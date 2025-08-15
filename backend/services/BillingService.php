<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantResourceUsage;
use App\Models\TenantBudget;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Invoice;
use Stripe\InvoiceItem;
use Stripe\PaymentIntent;
use Stripe\Subscription;
use Stripe\Product;
use Stripe\Price;

class BillingService
{
    protected string $stripeSecretKey;
    
    public function __construct()
    {
        $this->stripeSecretKey = config('services.stripe.secret');
        Stripe::setApiKey($this->stripeSecretKey);
    }

    /**
     * Generate and send invoice for a tenant's usage
     */
    public function generateInvoiceForTenant(string $tenantId, Carbon $periodStart, Carbon $periodEnd): array
    {
        try {
            DB::beginTransaction();

            $tenant = Tenant::findOrFail($tenantId);
            
            // Ensure tenant has a Stripe customer
            $stripeCustomer = $this->ensureStripeCustomer($tenant);
            
            // Get unbilled usage for the period
            $usageRecords = TenantResourceUsage::forTenant($tenantId)
                ->forDateRange($periodStart, $periodEnd)
                ->unbilled()
                ->get();

            if ($usageRecords->isEmpty()) {
                return [
                    'success' => true,
                    'message' => 'No unbilled usage found for the period',
                    'invoice_id' => null
                ];
            }

            // Create invoice items for each resource type
            $invoiceData = $this->createInvoiceItems($stripeCustomer->id, $usageRecords);
            
            // Create Stripe invoice
            $stripeInvoice = Invoice::create([
                'customer' => $stripeCustomer->id,
                'collection_method' => 'charge_automatically',
                'description' => "Usage charges for {$periodStart->format('M Y')}",
                'metadata' => [
                    'tenant_id' => $tenantId,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'genesis_invoice_type' => 'usage_billing'
                ]
            ]);

            // Finalize the invoice
            $stripeInvoice->finalizeInvoice();
            
            // Mark usage records as billed
            $this->markUsageAsBilled($usageRecords, $stripeInvoice->id);
            
            // Send invoice if auto-payment fails
            if ($stripeInvoice->status !== 'paid') {
                $stripeInvoice->sendInvoice();
            }

            DB::commit();

            return [
                'success' => true,
                'invoice_id' => $stripeInvoice->id,
                'invoice_url' => $stripeInvoice->hosted_invoice_url,
                'total_amount' => $stripeInvoice->total / 100, // Convert from cents
                'status' => $stripeInvoice->status,
                'usage_records_count' => $usageRecords->count(),
                'invoice_data' => $invoiceData
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Invoice generation failed', [
                'tenant_id' => $tenantId,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Process subscription billing for all tenants
     */
    public function processSubscriptionBilling(): array
    {
        $results = [];
        $tenants = Tenant::where('status', 'active')
            ->whereNotNull('stripe_subscription_id')
            ->get();

        foreach ($tenants as $tenant) {
            try {
                $result = $this->processTenantsSubscription($tenant);
                $results[] = [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'success' => true,
                    'result' => $result
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                
                Log::error('Subscription billing failed for tenant', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $results;
    }

    /**
     * Generate usage-based billing for all tenants
     */
    public function processUsageBilling(Carbon $periodStart = null, Carbon $periodEnd = null): array
    {
        $periodStart = $periodStart ?: Carbon::now()->subMonth()->startOfMonth();
        $periodEnd = $periodEnd ?: Carbon::now()->subMonth()->endOfMonth();
        
        $results = [];
        $tenants = Tenant::where('status', 'active')->get();

        foreach ($tenants as $tenant) {
            try {
                $result = $this->generateInvoiceForTenant($tenant->id, $periodStart, $periodEnd);
                
                if ($result['success'] && $result['invoice_id']) {
                    $results[] = [
                        'tenant_id' => $tenant->id,
                        'tenant_name' => $tenant->name,
                        'success' => true,
                        'invoice_id' => $result['invoice_id'],
                        'amount' => $result['total_amount']
                    ];
                }
            } catch (\Exception $e) {
                $results[] = [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        return [
            'period' => [
                'start' => $periodStart->toDateString(),
                'end' => $periodEnd->toDateString()
            ],
            'processed_tenants' => count($results),
            'successful_invoices' => count(array_filter($results, fn($r) => $r['success'])),
            'total_amount' => array_sum(array_column(array_filter($results, fn($r) => $r['success']), 'amount')),
            'results' => $results
        ];
    }

    /**
     * Create or update Stripe subscription for tenant
     */
    public function createSubscription(Tenant $tenant, string $priceId, array $options = []): array
    {
        try {
            $stripeCustomer = $this->ensureStripeCustomer($tenant);
            
            // Cancel existing subscription if any
            if ($tenant->stripe_subscription_id) {
                $this->cancelSubscription($tenant);
            }

            $subscriptionData = [
                'customer' => $stripeCustomer->id,
                'items' => [
                    ['price' => $priceId]
                ],
                'metadata' => [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'tier' => $tenant->tier
                ],
                'expand' => ['latest_invoice.payment_intent']
            ];

            // Add trial period if specified
            if (isset($options['trial_days'])) {
                $subscriptionData['trial_period_days'] = $options['trial_days'];
            }

            // Add usage-based pricing if specified
            if (isset($options['usage_pricing'])) {
                $subscriptionData['items'][] = [
                    'price' => $options['usage_pricing']['price_id'],
                ];
            }

            $subscription = Subscription::create($subscriptionData);

            // Update tenant with subscription info
            $tenant->update([
                'stripe_subscription_id' => $subscription->id,
                'subscription_ends_at' => Carbon::createFromTimestamp($subscription->current_period_end),
                'trial_ends_at' => $subscription->trial_end ? Carbon::createFromTimestamp($subscription->trial_end) : null
            ]);

            return [
                'success' => true,
                'subscription_id' => $subscription->id,
                'status' => $subscription->status,
                'current_period_end' => Carbon::createFromTimestamp($subscription->current_period_end),
                'client_secret' => $subscription->latest_invoice->payment_intent->client_secret ?? null
            ];

        } catch (\Exception $e) {
            Log::error('Subscription creation failed', [
                'tenant_id' => $tenant->id,
                'price_id' => $priceId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Cancel subscription for tenant
     */
    public function cancelSubscription(Tenant $tenant, bool $immediate = false): array
    {
        try {
            if (!$tenant->stripe_subscription_id) {
                return [
                    'success' => true,
                    'message' => 'No active subscription to cancel'
                ];
            }

            $subscription = Subscription::retrieve($tenant->stripe_subscription_id);
            
            if ($immediate) {
                $subscription->cancel();
                $tenant->update([
                    'stripe_subscription_id' => null,
                    'subscription_ends_at' => Carbon::now()
                ]);
            } else {
                $subscription->cancel_at_period_end = true;
                $subscription->save();
                // Keep subscription_id until period ends
            }

            return [
                'success' => true,
                'immediate' => $immediate,
                'ends_at' => Carbon::createFromTimestamp($subscription->current_period_end)
            ];

        } catch (\Exception $e) {
            Log::error('Subscription cancellation failed', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Handle Stripe webhook events
     */
    public function handleWebhook(array $eventData): array
    {
        $eventType = $eventData['type'];
        $eventObject = $eventData['data']['object'];

        try {
            switch ($eventType) {
                case 'invoice.payment_succeeded':
                    return $this->handlePaymentSucceeded($eventObject);
                
                case 'invoice.payment_failed':
                    return $this->handlePaymentFailed($eventObject);
                
                case 'customer.subscription.updated':
                    return $this->handleSubscriptionUpdated($eventObject);
                
                case 'customer.subscription.deleted':
                    return $this->handleSubscriptionDeleted($eventObject);
                
                case 'invoice.finalized':
                    return $this->handleInvoiceFinalized($eventObject);
                    
                default:
                    return [
                        'success' => true,
                        'message' => "Event type {$eventType} not handled"
                    ];
            }
        } catch (\Exception $e) {
            Log::error('Webhook handling failed', [
                'event_type' => $eventType,
                'event_id' => $eventData['id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Get billing summary for tenant
     */
    public function getBillingSummary(string $tenantId): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        
        // Current month usage costs
        $currentMonthCost = TenantResourceUsage::getTenantMonthlyCost($tenantId);
        
        // Previous month billed amount
        $previousMonthStart = Carbon::now()->subMonth()->startOfMonth();
        $previousMonthEnd = Carbon::now()->subMonth()->endOfMonth();
        $previousMonthBilled = TenantResourceUsage::forTenant($tenantId)
            ->forDateRange($previousMonthStart, $previousMonthEnd)
            ->billed()
            ->sum('total_cost');

        // Subscription info
        $subscriptionInfo = null;
        if ($tenant->stripe_subscription_id) {
            try {
                $subscription = Subscription::retrieve($tenant->stripe_subscription_id);
                $subscriptionInfo = [
                    'id' => $subscription->id,
                    'status' => $subscription->status,
                    'current_period_start' => Carbon::createFromTimestamp($subscription->current_period_start),
                    'current_period_end' => Carbon::createFromTimestamp($subscription->current_period_end),
                    'cancel_at_period_end' => $subscription->cancel_at_period_end,
                    'trial_end' => $subscription->trial_end ? Carbon::createFromTimestamp($subscription->trial_end) : null
                ];
            } catch (\Exception $e) {
                Log::warning('Failed to retrieve subscription info', [
                    'tenant_id' => $tenantId,
                    'subscription_id' => $tenant->stripe_subscription_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Recent invoices
        $recentInvoices = $this->getRecentInvoices($tenant, 5);

        // Usage breakdown
        $usageBreakdown = TenantResourceUsage::getTenantResourceBreakdown($tenantId);

        return [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'tier' => $tenant->tier,
                'billing_email' => $tenant->billing_email
            ],
            'current_month' => [
                'usage_cost' => $currentMonthCost,
                'period' => Carbon::now()->format('M Y')
            ],
            'previous_month' => [
                'billed_amount' => $previousMonthBilled,
                'period' => Carbon::now()->subMonth()->format('M Y')
            ],
            'subscription' => $subscriptionInfo,
            'recent_invoices' => $recentInvoices,
            'usage_breakdown' => $usageBreakdown,
            'payment_method' => $this->getPaymentMethod($tenant),
            'billing_status' => $this->getBillingStatus($tenant)
        ];
    }

    /**
     * Setup automated billing for tenant
     */
    public function setupAutomatedBilling(Tenant $tenant, array $config): array
    {
        try {
            DB::beginTransaction();

            // Update tenant configuration
            $billingConfig = $tenant->config ?? [];
            $billingConfig['automated_billing'] = array_merge([
                'enabled' => true,
                'billing_day' => 1, // 1st of each month
                'include_usage' => true,
                'include_subscription' => true,
                'auto_payment' => true,
                'send_notifications' => true
            ], $config);

            $tenant->update(['config' => $billingConfig]);

            // Ensure Stripe customer exists
            $this->ensureStripeCustomer($tenant);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Automated billing configured successfully',
                'config' => $billingConfig['automated_billing']
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Automated billing setup failed', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    // Private helper methods

    private function ensureStripeCustomer(Tenant $tenant): Customer
    {
        if ($tenant->stripe_customer_id) {
            try {
                return Customer::retrieve($tenant->stripe_customer_id);
            } catch (\Exception $e) {
                // Customer doesn't exist, create new one
                Log::warning('Stripe customer not found, creating new one', [
                    'tenant_id' => $tenant->id,
                    'old_customer_id' => $tenant->stripe_customer_id
                ]);
            }
        }

        $customer = Customer::create([
            'name' => $tenant->name,
            'email' => $tenant->billing_email ?? $tenant->created_by . '@example.com',
            'description' => "Genesis Orchestrator - {$tenant->name} (Tier: {$tenant->tier})",
            'metadata' => [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'tenant_tier' => $tenant->tier,
                'genesis_created_at' => Carbon::now()->toISOString()
            ]
        ]);

        $tenant->update(['stripe_customer_id' => $customer->id]);

        return $customer;
    }

    private function createInvoiceItems(string $customerId, $usageRecords): array
    {
        $invoiceData = [
            'items' => [],
            'total_amount' => 0
        ];

        $groupedUsage = $usageRecords->groupBy('resource_type');

        foreach ($groupedUsage as $resourceType => $records) {
            $totalUsage = $records->sum('total_usage');
            $totalCost = $records->sum('total_cost');

            if ($totalCost > 0) {
                InvoiceItem::create([
                    'customer' => $customerId,
                    'amount' => round($totalCost * 100), // Convert to cents
                    'currency' => 'usd',
                    'description' => $this->getResourceDescription($resourceType, $totalUsage),
                    'metadata' => [
                        'resource_type' => $resourceType,
                        'total_usage' => $totalUsage,
                        'record_count' => $records->count()
                    ]
                ]);

                $invoiceData['items'][] = [
                    'resource_type' => $resourceType,
                    'total_usage' => $totalUsage,
                    'total_cost' => $totalCost,
                    'record_count' => $records->count()
                ];

                $invoiceData['total_amount'] += $totalCost;
            }
        }

        return $invoiceData;
    }

    private function getResourceDescription(string $resourceType, int $totalUsage): string
    {
        $descriptions = [
            TenantResourceUsage::RESOURCE_ORCHESTRATION_RUNS => "Orchestration Runs",
            TenantResourceUsage::RESOURCE_API_CALLS => "API Calls",
            TenantResourceUsage::RESOURCE_TOKENS => "Tokens",
            TenantResourceUsage::RESOURCE_STORAGE => "Storage (GB)",
            TenantResourceUsage::RESOURCE_BANDWIDTH => "Bandwidth (GB)",
            TenantResourceUsage::RESOURCE_AGENT_EXECUTIONS => "Agent Executions",
            TenantResourceUsage::RESOURCE_MEMORY_ITEMS => "Memory Items",
            TenantResourceUsage::RESOURCE_ROUTER_CALLS => "Router Calls"
        ];

        $description = $descriptions[$resourceType] ?? ucwords(str_replace('_', ' ', $resourceType));
        return "{$description}: {$totalUsage} units";
    }

    private function markUsageAsBilled($usageRecords, string $invoiceId): void
    {
        foreach ($usageRecords as $record) {
            $record->markAsBilled($invoiceId);
        }
    }

    private function processTenantsSubscription(Tenant $tenant): array
    {
        if (!$tenant->stripe_subscription_id) {
            return ['message' => 'No subscription found'];
        }

        $subscription = Subscription::retrieve($tenant->stripe_subscription_id);
        
        // Check if subscription needs renewal or update
        $currentPeriodEnd = Carbon::createFromTimestamp($subscription->current_period_end);
        
        if ($currentPeriodEnd->isPast() && $subscription->status === 'active') {
            // Process usage billing for the completed period
            $periodStart = Carbon::createFromTimestamp($subscription->current_period_start);
            $periodEnd = $currentPeriodEnd;
            
            return $this->generateInvoiceForTenant($tenant->id, $periodStart, $periodEnd);
        }

        return [
            'message' => 'Subscription is current',
            'next_billing_date' => $currentPeriodEnd
        ];
    }

    private function handlePaymentSucceeded(array $invoice): array
    {
        $tenantId = $invoice['metadata']['tenant_id'] ?? null;
        
        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            if ($tenant) {
                Log::info('Payment succeeded for tenant', [
                    'tenant_id' => $tenantId,
                    'invoice_id' => $invoice['id'],
                    'amount' => $invoice['amount_paid'] / 100
                ]);
                
                // Update tenant payment status if needed
                // Send success notification
            }
        }

        return ['success' => true, 'message' => 'Payment succeeded'];
    }

    private function handlePaymentFailed(array $invoice): array
    {
        $tenantId = $invoice['metadata']['tenant_id'] ?? null;
        
        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            if ($tenant) {
                Log::warning('Payment failed for tenant', [
                    'tenant_id' => $tenantId,
                    'invoice_id' => $invoice['id'],
                    'amount' => $invoice['amount_due'] / 100
                ]);
                
                // Handle payment failure (notifications, account suspension, etc.)
                // This might trigger budget alerts or account status changes
            }
        }

        return ['success' => true, 'message' => 'Payment failure handled'];
    }

    private function handleSubscriptionUpdated(array $subscription): array
    {
        $tenantId = $subscription['metadata']['tenant_id'] ?? null;
        
        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            if ($tenant) {
                $tenant->update([
                    'subscription_ends_at' => Carbon::createFromTimestamp($subscription['current_period_end'])
                ]);
            }
        }

        return ['success' => true, 'message' => 'Subscription updated'];
    }

    private function handleSubscriptionDeleted(array $subscription): array
    {
        $tenantId = $subscription['metadata']['tenant_id'] ?? null;
        
        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            if ($tenant) {
                $tenant->update([
                    'stripe_subscription_id' => null,
                    'subscription_ends_at' => Carbon::now()
                ]);
            }
        }

        return ['success' => true, 'message' => 'Subscription deleted'];
    }

    private function handleInvoiceFinalized(array $invoice): array
    {
        $tenantId = $invoice['metadata']['tenant_id'] ?? null;
        
        if ($tenantId) {
            Log::info('Invoice finalized for tenant', [
                'tenant_id' => $tenantId,
                'invoice_id' => $invoice['id'],
                'amount' => $invoice['amount_due'] / 100
            ]);
        }

        return ['success' => true, 'message' => 'Invoice finalized'];
    }

    private function getRecentInvoices(Tenant $tenant, int $limit = 5): array
    {
        if (!$tenant->stripe_customer_id) {
            return [];
        }

        try {
            $invoices = Invoice::all([
                'customer' => $tenant->stripe_customer_id,
                'limit' => $limit
            ]);

            return array_map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'amount' => $invoice->total / 100,
                    'status' => $invoice->status,
                    'created' => Carbon::createFromTimestamp($invoice->created),
                    'due_date' => $invoice->due_date ? Carbon::createFromTimestamp($invoice->due_date) : null,
                    'hosted_invoice_url' => $invoice->hosted_invoice_url,
                    'invoice_pdf' => $invoice->invoice_pdf
                ];
            }, $invoices->data);

        } catch (\Exception $e) {
            Log::warning('Failed to retrieve recent invoices', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    private function getPaymentMethod(Tenant $tenant): ?array
    {
        if (!$tenant->stripe_customer_id) {
            return null;
        }

        try {
            $customer = Customer::retrieve($tenant->stripe_customer_id);
            $paymentMethods = $customer->allPaymentMethods(['type' => 'card']);
            
            if (empty($paymentMethods->data)) {
                return null;
            }

            $pm = $paymentMethods->data[0];
            return [
                'id' => $pm->id,
                'type' => $pm->type,
                'card' => [
                    'brand' => $pm->card->brand,
                    'last4' => $pm->card->last4,
                    'exp_month' => $pm->card->exp_month,
                    'exp_year' => $pm->card->exp_year
                ]
            ];

        } catch (\Exception $e) {
            return null;
        }
    }

    private function getBillingStatus(Tenant $tenant): string
    {
        if (!$tenant->stripe_customer_id) {
            return 'not_configured';
        }

        if ($tenant->trial_ends_at && $tenant->trial_ends_at->isFuture()) {
            return 'trial';
        }

        if ($tenant->stripe_subscription_id) {
            try {
                $subscription = Subscription::retrieve($tenant->stripe_subscription_id);
                return $subscription->status;
            } catch (\Exception $e) {
                return 'error';
            }
        }

        return 'no_subscription';
    }
}