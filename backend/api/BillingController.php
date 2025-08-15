<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BillingService;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BillingController extends Controller
{
    protected BillingService $billingService;

    public function __construct(BillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    /**
     * Get billing summary for tenant
     */
    public function getBillingSummary(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID');
        
        try {
            $summary = $this->billingService->getBillingSummary($tenantId);
            
            return response()->json([
                'success' => true,
                'data' => $summary
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve billing summary',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate invoice for specific period
     */
    public function generateInvoice(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'send_immediately' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');
        $periodStart = Carbon::parse($request->period_start);
        $periodEnd = Carbon::parse($request->period_end);

        try {
            $result = $this->billingService->generateInvoiceForTenant($tenantId, $periodStart, $periodEnd);
            
            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => $result['success'] ? 'Invoice generated successfully' : 'No billable usage found'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to generate invoice',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create subscription for tenant
     */
    public function createSubscription(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'price_id' => 'required|string',
            'trial_days' => 'integer|min:0|max:365',
            'payment_method_id' => 'string|nullable',
            'usage_pricing' => 'array|nullable'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');

        try {
            $tenant = Tenant::findOrFail($tenantId);
            
            $options = [];
            if ($request->has('trial_days')) {
                $options['trial_days'] = $request->trial_days;
            }
            if ($request->has('usage_pricing')) {
                $options['usage_pricing'] = $request->usage_pricing;
            }

            $result = $this->billingService->createSubscription($tenant, $request->price_id, $options);
            
            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Subscription created successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to create subscription',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel subscription
     */
    public function cancelSubscription(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'immediate' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');

        try {
            $tenant = Tenant::findOrFail($tenantId);
            $immediate = $request->boolean('immediate', false);
            
            $result = $this->billingService->cancelSubscription($tenant, $immediate);
            
            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => $immediate ? 'Subscription canceled immediately' : 'Subscription will cancel at period end'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to cancel subscription',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Setup automated billing
     */
    public function setupAutomatedBilling(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'enabled' => 'required|boolean',
            'billing_day' => 'integer|min:1|max:28',
            'include_usage' => 'boolean',
            'include_subscription' => 'boolean',
            'auto_payment' => 'boolean',
            'send_notifications' => 'boolean',
            'notification_emails' => 'array',
            'notification_emails.*' => 'email'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');

        try {
            $tenant = Tenant::findOrFail($tenantId);
            
            $config = $request->validated();
            $result = $this->billingService->setupAutomatedBilling($tenant, $config);
            
            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Automated billing configured successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to setup automated billing',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle Stripe webhooks
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = config('services.stripe.webhook_secret');

        try {
            // Verify webhook signature
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
            
            // Process the event
            $result = $this->billingService->handleWebhook($event);
            
            Log::info('Stripe webhook processed', [
                'event_type' => $event['type'],
                'event_id' => $event['id'],
                'result' => $result
            ]);
            
            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\UnexpectedValueException $e) {
            Log::error('Invalid webhook payload', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Invalid webhook signature', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Webhook processing failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => 'Webhook processing failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available pricing plans
     */
    public function getPricingPlans(Request $request): JsonResponse
    {
        try {
            // This would typically come from Stripe API or database
            $plans = [
                'starter' => [
                    'id' => 'price_starter',
                    'name' => 'Starter',
                    'description' => 'Perfect for small teams getting started',
                    'price' => 29.00,
                    'currency' => 'usd',
                    'interval' => 'month',
                    'features' => [
                        'Up to 1,000 orchestration runs/month',
                        '100,000 tokens/month',
                        'Basic analytics',
                        'Email support'
                    ],
                    'limits' => [
                        'max_users' => 5,
                        'max_orchestration_runs_per_month' => 1000,
                        'max_tokens_per_month' => 100000,
                        'max_storage_gb' => 10
                    ]
                ],
                'professional' => [
                    'id' => 'price_professional',
                    'name' => 'Professional',
                    'description' => 'Advanced features for growing teams',
                    'price' => 99.00,
                    'currency' => 'usd',
                    'interval' => 'month',
                    'features' => [
                        'Up to 10,000 orchestration runs/month',
                        '1,000,000 tokens/month',
                        'Advanced analytics & forecasting',
                        'Priority support',
                        'Custom integrations'
                    ],
                    'limits' => [
                        'max_users' => 25,
                        'max_orchestration_runs_per_month' => 10000,
                        'max_tokens_per_month' => 1000000,
                        'max_storage_gb' => 100
                    ]
                ],
                'enterprise' => [
                    'id' => 'price_enterprise',
                    'name' => 'Enterprise',
                    'description' => 'Unlimited scale for large organizations',
                    'price' => 299.00,
                    'currency' => 'usd',
                    'interval' => 'month',
                    'features' => [
                        'Unlimited orchestration runs',
                        'Unlimited tokens',
                        'Custom analytics & reporting',
                        'Dedicated support',
                        'Custom deployment options',
                        'SLA guarantees'
                    ],
                    'limits' => [
                        'max_users' => -1, // Unlimited
                        'max_orchestration_runs_per_month' => -1,
                        'max_tokens_per_month' => -1,
                        'max_storage_gb' => -1
                    ]
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'plans' => $plans,
                    'usage_pricing' => [
                        'orchestration_runs' => [
                            'price_per_unit' => 0.10,
                            'currency' => 'usd',
                            'description' => 'Per orchestration run above plan limit'
                        ],
                        'tokens' => [
                            'price_per_1k' => 0.002,
                            'currency' => 'usd',
                            'description' => 'Per 1,000 tokens above plan limit'
                        ],
                        'storage' => [
                            'price_per_gb' => 0.50,
                            'currency' => 'usd',
                            'description' => 'Per GB per month above plan limit'
                        ]
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve pricing plans',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get billing history
     */
    public function getBillingHistory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'integer|min:1|max:100',
            'start_date' => 'date|nullable',
            'end_date' => 'date|nullable|after_or_equal:start_date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');
        $limit = $request->integer('limit', 20);

        try {
            $tenant = Tenant::findOrFail($tenantId);
            
            if (!$tenant->stripe_customer_id) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'invoices' => [],
                        'total_count' => 0,
                        'message' => 'No billing history available'
                    ]
                ]);
            }

            $params = ['customer' => $tenant->stripe_customer_id, 'limit' => $limit];
            
            if ($request->start_date) {
                $params['created'] = ['gte' => Carbon::parse($request->start_date)->timestamp];
            }

            $invoices = \Stripe\Invoice::all($params);

            $invoiceData = array_map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'amount' => $invoice->total / 100,
                    'status' => $invoice->status,
                    'created' => Carbon::createFromTimestamp($invoice->created),
                    'due_date' => $invoice->due_date ? Carbon::createFromTimestamp($invoice->due_date) : null,
                    'paid_at' => $invoice->status_transitions->paid_at ? Carbon::createFromTimestamp($invoice->status_transitions->paid_at) : null,
                    'hosted_invoice_url' => $invoice->hosted_invoice_url,
                    'invoice_pdf' => $invoice->invoice_pdf,
                    'description' => $invoice->description,
                    'metadata' => $invoice->metadata->toArray()
                ];
            }, $invoices->data);

            return response()->json([
                'success' => true,
                'data' => [
                    'invoices' => $invoiceData,
                    'total_count' => count($invoiceData),
                    'has_more' => $invoices->has_more
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve billing history',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update payment method
     */
    public function updatePaymentMethod(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_method_id' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');

        try {
            $tenant = Tenant::findOrFail($tenantId);
            
            if (!$tenant->stripe_customer_id) {
                return response()->json([
                    'success' => false,
                    'error' => 'No Stripe customer found for tenant'
                ], 400);
            }

            // Attach payment method to customer
            $paymentMethod = \Stripe\PaymentMethod::retrieve($request->payment_method_id);
            $paymentMethod->attach(['customer' => $tenant->stripe_customer_id]);

            // Set as default payment method
            \Stripe\Customer::update($tenant->stripe_customer_id, [
                'invoice_settings' => ['default_payment_method' => $request->payment_method_id]
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment method updated successfully',
                'data' => [
                    'payment_method_id' => $request->payment_method_id,
                    'customer_id' => $tenant->stripe_customer_id
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to update payment method',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate billing report
     */
    public function generateBillingReport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'include_usage_details' => 'boolean',
            'format' => 'in:json,csv'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        try {
            $tenant = Tenant::findOrFail($tenantId);
            $summary = $this->billingService->getBillingSummary($tenantId);
            
            $report = [
                'report_period' => [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'days' => $startDate->diffInDays($endDate) + 1
                ],
                'tenant_info' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'tier' => $tenant->tier,
                    'billing_email' => $tenant->billing_email
                ],
                'billing_summary' => $summary,
                'generated_at' => Carbon::now()->toISOString()
            ];

            if ($request->boolean('include_usage_details', false)) {
                $usageDetails = \App\Models\TenantResourceUsage::forTenant($tenantId)
                    ->forDateRange($startDate, $endDate)
                    ->orderBy('usage_date')
                    ->get();
                
                $report['usage_details'] = $usageDetails->map(function ($record) {
                    return [
                        'date' => $record->usage_date->toDateString(),
                        'resource_type' => $record->resource_type,
                        'usage' => $record->total_usage,
                        'cost' => $record->total_cost,
                        'billed' => $record->billed
                    ];
                });
            }

            return response()->json([
                'success' => true,
                'data' => $report,
                'format' => $request->input('format', 'json')
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to generate billing report',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin endpoint: Process billing for all tenants
     */
    public function processAllBilling(Request $request): JsonResponse
    {
        // This endpoint would typically be restricted to admin users
        $validator = Validator::make($request->all(), [
            'period_start' => 'date|nullable',
            'period_end' => 'date|nullable|after_or_equal:period_start',
            'billing_type' => 'in:usage,subscription,both'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $billingType = $request->input('billing_type', 'both');
            $results = [];

            if (in_array($billingType, ['usage', 'both'])) {
                $periodStart = $request->period_start ? Carbon::parse($request->period_start) : null;
                $periodEnd = $request->period_end ? Carbon::parse($request->period_end) : null;
                
                $usageResults = $this->billingService->processUsageBilling($periodStart, $periodEnd);
                $results['usage_billing'] = $usageResults;
            }

            if (in_array($billingType, ['subscription', 'both'])) {
                $subscriptionResults = $this->billingService->processSubscriptionBilling();
                $results['subscription_billing'] = $subscriptionResults;
            }

            return response()->json([
                'success' => true,
                'data' => $results,
                'message' => 'Billing processing completed'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to process billing',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}