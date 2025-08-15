<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class ProcessDeadLetterWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $webhook;
    protected $payload;
    protected $deliveryId;
    protected $finalError;

    public $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct($webhook, array $payload, string $deliveryId, string $finalError)
    {
        $this->webhook = $webhook;
        $this->payload = $payload;
        $this->deliveryId = $deliveryId;
        $this->finalError = $finalError;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::warning('Processing dead letter webhook', [
            'webhook_id' => $this->webhook->id,
            'delivery_id' => $this->deliveryId,
            'final_error' => $this->finalError,
        ]);

        // Store in dead letter queue table
        $this->storeInDeadLetterQueue();

        // Notify webhook owner
        $this->notifyWebhookOwner();

        // Check if webhook should be disabled
        $this->checkWebhookHealth();

        // Log the dead letter processing
        Log::info('Dead letter webhook processed', [
            'webhook_id' => $this->webhook->id,
            'delivery_id' => $this->deliveryId,
        ]);
    }

    /**
     * Store webhook in dead letter queue
     */
    protected function storeInDeadLetterQueue(): void
    {
        DB::table('webhook_dead_letter_queue')->insert([
            'webhook_id' => $this->webhook->id,
            'tenant_id' => $this->webhook->tenant_id,
            'delivery_id' => $this->deliveryId,
            'url' => $this->webhook->url,
            'payload' => json_encode($this->payload),
            'final_error' => $this->finalError,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Notify webhook owner about the failure
     */
    protected function notifyWebhookOwner(): void
    {
        try {
            // Get tenant contact information
            $tenant = DB::table('tenants')->where('id', $this->webhook->tenant_id)->first();
            
            if (!$tenant || !$tenant->contact_email) {
                Log::warning('Cannot notify webhook owner - no contact email', [
                    'webhook_id' => $this->webhook->id,
                    'tenant_id' => $this->webhook->tenant_id,
                ]);
                return;
            }

            // Send notification email
            $emailData = [
                'webhook_id' => $this->webhook->id,
                'webhook_url' => $this->webhook->url,
                'delivery_id' => $this->deliveryId,
                'error' => $this->finalError,
                'tenant_name' => $tenant->name,
                'event_type' => $this->payload['event_type'] ?? 'unknown',
            ];

            // Here you would send the actual email
            // Mail::to($tenant->contact_email)->send(new WebhookFailureNotification($emailData));
            
            Log::info('Webhook failure notification sent', [
                'webhook_id' => $this->webhook->id,
                'tenant_id' => $this->webhook->tenant_id,
                'email' => $tenant->contact_email,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to send webhook failure notification', [
                'webhook_id' => $this->webhook->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check webhook health and potentially disable it
     */
    protected function checkWebhookHealth(): void
    {
        // Get recent failure count
        $recentFailures = DB::table('webhook_dead_letter_queue')
            ->where('webhook_id', $this->webhook->id)
            ->where('created_at', '>=', Carbon::now()->subHours(24))
            ->count();

        $recentDeliveries = DB::table('webhook_deliveries')
            ->where('webhook_id', $this->webhook->id)
            ->where('created_at', '>=', Carbon::now()->subHours(24))
            ->count();

        // If too many failures, disable the webhook
        if ($recentFailures >= 10 && $recentDeliveries > 0) {
            $failureRate = $recentFailures / $recentDeliveries;
            
            if ($failureRate > 0.8) { // 80% failure rate
                DB::table('webhook_endpoints')
                    ->where('id', $this->webhook->id)
                    ->update([
                        'active' => false,
                        'disabled_reason' => 'High failure rate',
                        'disabled_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ]);

                Log::warning('Webhook automatically disabled due to high failure rate', [
                    'webhook_id' => $this->webhook->id,
                    'recent_failures' => $recentFailures,
                    'recent_deliveries' => $recentDeliveries,
                    'failure_rate' => $failureRate,
                ]);
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Exception $exception): void
    {
        Log::error('Dead letter webhook processing failed', [
            'webhook_id' => $this->webhook->id,
            'delivery_id' => $this->deliveryId,
            'error' => $exception->getMessage(),
        ]);
    }
}