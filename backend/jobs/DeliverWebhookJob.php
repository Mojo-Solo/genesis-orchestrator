<?php

namespace App\Jobs;

use App\Services\WebhookDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $webhook;
    protected $payload;
    protected $deliveryId;
    protected $attempt;

    public $timeout = 60;
    public $maxExceptions = 3;

    /**
     * Create a new job instance.
     */
    public function __construct($webhook, array $payload, string $deliveryId, int $attempt = 1)
    {
        $this->webhook = $webhook;
        $this->payload = $payload;
        $this->deliveryId = $deliveryId;
        $this->attempt = $attempt;
    }

    /**
     * Execute the job.
     */
    public function handle(WebhookDeliveryService $webhookService): void
    {
        try {
            Log::info('Starting webhook delivery', [
                'webhook_id' => $this->webhook->id,
                'delivery_id' => $this->deliveryId,
                'attempt' => $this->attempt,
                'url' => $this->webhook->url,
            ]);

            $result = $webhookService->deliverWebhook(
                $this->webhook,
                $this->payload,
                $this->deliveryId,
                $this->attempt
            );

            if ($result['success']) {
                Log::info('Webhook delivered successfully', [
                    'webhook_id' => $this->webhook->id,
                    'delivery_id' => $this->deliveryId,
                    'attempt' => $this->attempt,
                    'duration_ms' => $result['duration_ms'],
                    'status_code' => $result['status_code'],
                ]);
            } else {
                throw new Exception($result['error']);
            }

        } catch (Exception $e) {
            Log::error('Webhook delivery failed', [
                'webhook_id' => $this->webhook->id,
                'delivery_id' => $this->deliveryId,
                'attempt' => $this->attempt,
                'error' => $e->getMessage(),
            ]);

            // Handle failure and potential retry
            $webhookService->handleDeliveryFailure(
                $this->webhook,
                $this->payload,
                $this->deliveryId,
                $this->attempt,
                $e->getMessage()
            );

            // Re-throw to mark job as failed
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Exception $exception): void
    {
        Log::error('Webhook delivery job failed permanently', [
            'webhook_id' => $this->webhook->id,
            'delivery_id' => $this->deliveryId,
            'attempt' => $this->attempt,
            'error' => $exception->getMessage(),
        ]);
    }
}