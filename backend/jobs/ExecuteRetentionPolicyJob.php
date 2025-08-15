<?php

namespace App\Jobs;

use App\Models\DataRetentionExecution;
use App\Models\DataRetentionPolicy;
use App\Models\ComplianceAuditLog;
use App\Models\TenantUser;
use App\Models\OrchestrationRun;
use App\Models\AgentExecution;
use App\Models\MemoryItem;
use App\Models\SecurityAuditLog;
use App\Models\RouterMetric;
use App\Models\StabilityTracking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class ExecuteRetentionPolicyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour timeout
    public $tries = 3;

    private DataRetentionExecution $execution;
    private DataRetentionPolicy $policy;
    private array $executionLog = [];
    private int $recordsProcessed = 0;
    private int $recordsDeleted = 0;
    private int $recordsAnonymized = 0;
    private int $recordsArchived = 0;
    private int $recordsFailed = 0;

    /**
     * Create a new job instance.
     */
    public function __construct(DataRetentionExecution $execution)
    {
        $this->execution = $execution;
        $this->policy = $execution->policy;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $this->logMessage('Starting retention policy execution');
            $this->updateExecutionStatus('running');

            // Get eligible data for retention action
            $eligibleData = $this->policy->getEligibleData();
            $this->execution->update(['records_identified' => count($eligibleData)]);
            
            $this->logMessage("Identified " . count($eligibleData) . " records for retention action");

            if (empty($eligibleData)) {
                $this->logMessage('No eligible data found for retention policy');
                $this->completeExecution();
                return;
            }

            // Process data according to retention action
            switch ($this->policy->retention_action) {
                case DataRetentionPolicy::ACTION_DELETE:
                    $this->executeDelete($eligibleData);
                    break;
                case DataRetentionPolicy::ACTION_ANONYMIZE:
                    $this->executeAnonymize($eligibleData);
                    break;
                case DataRetentionPolicy::ACTION_ARCHIVE:
                    $this->executeArchive($eligibleData);
                    break;
                case DataRetentionPolicy::ACTION_NOTIFY_REVIEW:
                    $this->executeNotifyReview($eligibleData);
                    break;
                default:
                    throw new \InvalidArgumentException("Unknown retention action: {$this->policy->retention_action}");
            }

            $this->completeExecution();

        } catch (\Exception $e) {
            $this->failExecution($e);
        }
    }

    /**
     * Execute deletion of eligible data
     */
    private function executeDelete(array $eligibleData): void
    {
        $this->logMessage('Starting data deletion process');
        
        foreach ($eligibleData as $dataItem) {
            try {
                $this->deleteDataItem($dataItem);
                $this->recordsDeleted++;
                $this->recordsProcessed++;
            } catch (\Exception $e) {
                $this->recordsFailed++;
                $this->logMessage("Failed to delete record {$dataItem['id']} from {$dataItem['table']}: " . $e->getMessage());
            }
        }

        $this->logMessage("Deletion complete: {$this->recordsDeleted} deleted, {$this->recordsFailed} failed");
    }

    /**
     * Execute anonymization of eligible data
     */
    private function executeAnonymize(array $eligibleData): void
    {
        $this->logMessage('Starting data anonymization process');
        
        foreach ($eligibleData as $dataItem) {
            try {
                $this->anonymizeDataItem($dataItem);
                $this->recordsAnonymized++;
                $this->recordsProcessed++;
            } catch (\Exception $e) {
                $this->recordsFailed++;
                $this->logMessage("Failed to anonymize record {$dataItem['id']} from {$dataItem['table']}: " . $e->getMessage());
            }
        }

        $this->logMessage("Anonymization complete: {$this->recordsAnonymized} anonymized, {$this->recordsFailed} failed");
    }

    /**
     * Execute archival of eligible data
     */
    private function executeArchive(array $eligibleData): void
    {
        $this->logMessage('Starting data archival process');
        
        // Create archive directory
        $archiveDir = "archives/{$this->policy->tenant_id}/{$this->policy->data_category}/" . Carbon::now()->format('Y-m-d');
        Storage::makeDirectory($archiveDir);
        
        foreach ($eligibleData as $dataItem) {
            try {
                $this->archiveDataItem($dataItem, $archiveDir);
                $this->recordsArchived++;
                $this->recordsProcessed++;
            } catch (\Exception $e) {
                $this->recordsFailed++;
                $this->logMessage("Failed to archive record {$dataItem['id']} from {$dataItem['table']}: " . $e->getMessage());
            }
        }

        $this->logMessage("Archival complete: {$this->recordsArchived} archived, {$this->recordsFailed} failed");
    }

    /**
     * Execute notification for manual review
     */
    private function executeNotifyReview(array $eligibleData): void
    {
        $this->logMessage('Preparing manual review notifications');
        
        // Group data by table for better organization
        $groupedData = collect($eligibleData)->groupBy('table');
        
        foreach ($groupedData as $tableName => $records) {
            $this->sendReviewNotification($tableName, $records->toArray());
            $this->recordsProcessed += count($records);
        }

        $this->logMessage("Review notifications sent for {$this->recordsProcessed} records");
    }

    /**
     * Delete a data item
     */
    private function deleteDataItem(array $dataItem): void
    {
        $tableName = $dataItem['table'];
        $recordId = $dataItem['id'];

        // Use appropriate model for deletion
        switch ($tableName) {
            case 'tenant_users':
                $record = TenantUser::find($recordId);
                if ($record) {
                    $record->delete();
                    $this->logDataDeletion($recordId, $tableName, $dataItem['attributes']);
                }
                break;
                
            case 'orchestration_runs':
                $record = OrchestrationRun::find($recordId);
                if ($record) {
                    $record->delete();
                    $this->logDataDeletion($recordId, $tableName, $dataItem['attributes']);
                }
                break;
                
            case 'agent_executions':
                $record = AgentExecution::find($recordId);
                if ($record) {
                    $record->delete();
                    $this->logDataDeletion($recordId, $tableName, $dataItem['attributes']);
                }
                break;
                
            case 'memory_items':
                $record = MemoryItem::find($recordId);
                if ($record) {
                    $record->delete();
                    $this->logDataDeletion($recordId, $tableName, $dataItem['attributes']);
                }
                break;
                
            case 'security_audit_logs':
                // Security logs should be anonymized rather than deleted for audit trail
                $this->anonymizeSecurityLog($recordId);
                break;
                
            case 'router_metrics':
                $record = RouterMetric::find($recordId);
                if ($record) {
                    $record->delete();
                    $this->logDataDeletion($recordId, $tableName, $dataItem['attributes']);
                }
                break;
                
            case 'stability_tracking':
                $record = StabilityTracking::find($recordId);
                if ($record) {
                    $record->delete();
                    $this->logDataDeletion($recordId, $tableName, $dataItem['attributes']);
                }
                break;
                
            default:
                // Generic deletion for other tables
                DB::table($tableName)->where('id', $recordId)->delete();
                $this->logDataDeletion($recordId, $tableName, $dataItem['attributes']);
        }
    }

    /**
     * Anonymize a data item
     */
    private function anonymizeDataItem(array $dataItem): void
    {
        $tableName = $dataItem['table'];
        $recordId = $dataItem['id'];
        $attributes = $dataItem['attributes'];

        // Generate anonymized data
        $anonymizedData = $this->generateAnonymizedData($attributes);

        // Update record with anonymized data
        switch ($tableName) {
            case 'tenant_users':
                $record = TenantUser::find($recordId);
                if ($record) {
                    $record->update($anonymizedData);
                    $this->logDataAnonymization($recordId, $tableName, $attributes, $anonymizedData);
                }
                break;
                
            case 'security_audit_logs':
                $this->anonymizeSecurityLog($recordId);
                break;
                
            default:
                DB::table($tableName)->where('id', $recordId)->update($anonymizedData);
                $this->logDataAnonymization($recordId, $tableName, $attributes, $anonymizedData);
        }
    }

    /**
     * Archive a data item
     */
    private function archiveDataItem(array $dataItem, string $archiveDir): void
    {
        $tableName = $dataItem['table'];
        $recordId = $dataItem['id'];
        $attributes = $dataItem['attributes'];

        // Create archive record
        $archiveData = [
            'archived_at' => Carbon::now()->toISOString(),
            'original_table' => $tableName,
            'original_id' => $recordId,
            'policy_id' => $this->policy->id,
            'execution_id' => $this->execution->id,
            'data' => $attributes
        ];

        // Save to archive file
        $archiveFile = "{$archiveDir}/{$tableName}_{$recordId}.json";
        Storage::put($archiveFile, json_encode($archiveData, JSON_PRETTY_PRINT));

        // Remove from original table
        $this->deleteDataItem($dataItem);

        $this->logDataArchival($recordId, $tableName, $archiveFile);
    }

    /**
     * Generate anonymized data for a record
     */
    private function generateAnonymizedData(array $attributes): array
    {
        $anonymized = [];
        
        foreach ($attributes as $field => $value) {
            if (in_array($field, ['id', 'tenant_id', 'created_at', 'updated_at'])) {
                // Keep system fields unchanged
                continue;
            }

            $anonymized[$field] = $this->anonymizeField($field, $value);
        }

        $anonymized['anonymized_at'] = Carbon::now();
        $anonymized['anonymization_policy'] = $this->policy->id;

        return $anonymized;
    }

    /**
     * Anonymize a specific field value
     */
    private function anonymizeField(string $fieldName, $value): string
    {
        if (is_null($value)) {
            return null;
        }

        $fieldLower = strtolower($fieldName);

        // Email anonymization
        if (str_contains($fieldLower, 'email')) {
            return 'anonymized_' . substr(hash('sha256', $value), 0, 8) . '@example.com';
        }

        // Name anonymization
        if (str_contains($fieldLower, 'name') || str_contains($fieldLower, 'first') || str_contains($fieldLower, 'last')) {
            return 'Anonymous_' . substr(hash('sha256', $value), 0, 6);
        }

        // Phone anonymization
        if (str_contains($fieldLower, 'phone')) {
            return '555-' . substr(hash('sha256', $value), 0, 3) . '-' . substr(hash('sha256', $value), 3, 4);
        }

        // Address anonymization
        if (str_contains($fieldLower, 'address') || str_contains($fieldLower, 'street')) {
            return 'Anonymous Street, Anonymous City';
        }

        // IP address anonymization
        if (str_contains($fieldLower, 'ip')) {
            return '192.168.1.' . (crc32($value) % 254 + 1);
        }

        // Generic text anonymization
        if (is_string($value) && strlen($value) > 0) {
            return 'ANONYMIZED_' . substr(hash('sha256', $value), 0, 8);
        }

        // Numeric value anonymization
        if (is_numeric($value)) {
            return (int)(hash('crc32', $value) % 10000);
        }

        return 'ANONYMIZED';
    }

    /**
     * Anonymize security log specifically
     */
    private function anonymizeSecurityLog(string $logId): void
    {
        SecurityAuditLog::where('id', $logId)->update([
            'user_id' => null,
            'ip_address' => '0.0.0.0',
            'user_agent' => 'ANONYMIZED',
            'anonymized_at' => Carbon::now(),
            'anonymization_policy' => $this->policy->id
        ]);

        $this->logMessage("Anonymized security log {$logId}");
    }

    /**
     * Send notification for manual review
     */
    private function sendReviewNotification(string $tableName, array $records): void
    {
        $notificationData = [
            'policy_name' => $this->policy->policy_name,
            'table_name' => $tableName,
            'record_count' => count($records),
            'retention_period_days' => $this->policy->retention_period_days,
            'records' => array_slice($records, 0, 10), // Include first 10 records as examples
            'review_due_date' => Carbon::now()->addDays($this->policy->warning_days ?? 7),
            'execution_id' => $this->execution->id
        ];

        // Send email notifications
        if ($this->policy->notification_emails) {
            $emails = explode(',', $this->policy->notification_emails);
            foreach ($emails as $email) {
                // This would integrate with your email system
                $this->logMessage("Review notification sent to {$email} for {$tableName}");
            }
        }

        // Log the notification
        ComplianceAuditLog::logEvent(
            'retention_review_notification',
            'gdpr',
            'info',
            null,
            null,
            "Manual review notification sent for retention policy: {$this->policy->policy_name}",
            $notificationData,
            $this->policy->tenant_id
        );
    }

    /**
     * Log data deletion
     */
    private function logDataDeletion(string $recordId, string $tableName, array $attributes): void
    {
        ComplianceAuditLog::logEvent(
            'data_retention_deletion',
            'gdpr',
            'info',
            $attributes['user_id'] ?? null,
            'system',
            "Data deleted by retention policy: {$recordId} from {$tableName}",
            [
                'policy_id' => $this->policy->id,
                'execution_id' => $this->execution->id,
                'record_id' => $recordId,
                'table_name' => $tableName,
                'retention_action' => 'delete',
                'record_age_days' => $attributes['created_at'] ? 
                    Carbon::parse($attributes['created_at'])->diffInDays(Carbon::now()) : null
            ],
            $this->policy->tenant_id,
            [],
            null,
            true
        );
    }

    /**
     * Log data anonymization
     */
    private function logDataAnonymization(string $recordId, string $tableName, array $originalAttributes, array $anonymizedData): void
    {
        ComplianceAuditLog::logEvent(
            'data_retention_anonymization',
            'gdpr',
            'info',
            $originalAttributes['user_id'] ?? null,
            'system',
            "Data anonymized by retention policy: {$recordId} from {$tableName}",
            [
                'policy_id' => $this->policy->id,
                'execution_id' => $this->execution->id,
                'record_id' => $recordId,
                'table_name' => $tableName,
                'retention_action' => 'anonymize',
                'fields_anonymized' => array_keys($anonymizedData),
                'record_age_days' => $originalAttributes['created_at'] ? 
                    Carbon::parse($originalAttributes['created_at'])->diffInDays(Carbon::now()) : null
            ],
            $this->policy->tenant_id,
            [],
            null,
            true
        );
    }

    /**
     * Log data archival
     */
    private function logDataArchival(string $recordId, string $tableName, string $archiveFile): void
    {
        ComplianceAuditLog::logEvent(
            'data_retention_archival',
            'gdpr',
            'info',
            null,
            'system',
            "Data archived by retention policy: {$recordId} from {$tableName}",
            [
                'policy_id' => $this->policy->id,
                'execution_id' => $this->execution->id,
                'record_id' => $recordId,
                'table_name' => $tableName,
                'retention_action' => 'archive',
                'archive_file' => $archiveFile
            ],
            $this->policy->tenant_id,
            [],
            null,
            true
        );
    }

    /**
     * Update execution status
     */
    private function updateExecutionStatus(string $status): void
    {
        $updateData = ['status' => $status];
        
        if ($status === 'running') {
            $updateData['started_at'] = Carbon::now();
        } elseif (in_array($status, ['completed', 'failed'])) {
            $updateData['completed_at'] = Carbon::now();
        }

        $this->execution->update($updateData);
    }

    /**
     * Complete execution successfully
     */
    private function completeExecution(): void
    {
        $this->execution->update([
            'status' => 'completed',
            'completed_at' => Carbon::now(),
            'records_processed' => $this->recordsProcessed,
            'records_deleted' => $this->recordsDeleted,
            'records_anonymized' => $this->recordsAnonymized,
            'records_archived' => $this->recordsArchived,
            'records_failed' => $this->recordsFailed,
            'affected_tables' => $this->getAffectedTables(),
            'execution_log' => implode("\n", $this->executionLog)
        ]);

        $this->logMessage('Retention policy execution completed successfully');

        // Log completion
        ComplianceAuditLog::logEvent(
            'retention_policy_execution_completed',
            'gdpr',
            'info',
            null,
            null,
            "Retention policy execution completed: {$this->policy->policy_name}",
            [
                'policy_id' => $this->policy->id,
                'execution_id' => $this->execution->id,
                'records_processed' => $this->recordsProcessed,
                'records_deleted' => $this->recordsDeleted,
                'records_anonymized' => $this->recordsAnonymized,
                'records_archived' => $this->recordsArchived,
                'records_failed' => $this->recordsFailed,
                'execution_time_minutes' => $this->execution->started_at ? 
                    $this->execution->started_at->diffInMinutes(Carbon::now()) : 0
            ],
            $this->policy->tenant_id,
            [],
            null,
            true
        );
    }

    /**
     * Fail execution with error
     */
    private function failExecution(\Exception $exception): void
    {
        $this->execution->update([
            'status' => 'failed',
            'completed_at' => Carbon::now(),
            'records_processed' => $this->recordsProcessed,
            'records_deleted' => $this->recordsDeleted,
            'records_anonymized' => $this->recordsAnonymized,
            'records_archived' => $this->recordsArchived,
            'records_failed' => $this->recordsFailed,
            'execution_log' => implode("\n", $this->executionLog),
            'error_details' => $exception->getMessage() . "\n" . $exception->getTraceAsString()
        ]);

        $this->logMessage('Retention policy execution failed: ' . $exception->getMessage());

        // Log failure
        ComplianceAuditLog::logEvent(
            'retention_policy_execution_failed',
            'gdpr',
            'error',
            null,
            null,
            "Retention policy execution failed: {$this->policy->policy_name}",
            [
                'policy_id' => $this->policy->id,
                'execution_id' => $this->execution->id,
                'error_message' => $exception->getMessage(),
                'records_processed' => $this->recordsProcessed,
                'records_failed' => $this->recordsFailed
            ],
            $this->policy->tenant_id
        );

        Log::error('Retention policy execution failed', [
            'policy_id' => $this->policy->id,
            'execution_id' => $this->execution->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        throw $exception;
    }

    /**
     * Get list of affected tables
     */
    private function getAffectedTables(): array
    {
        $tables = [];
        
        if ($this->recordsDeleted > 0 || $this->recordsAnonymized > 0 || $this->recordsArchived > 0) {
            // Extract table names from execution log
            foreach ($this->executionLog as $logEntry) {
                if (preg_match('/from (\w+)/', $logEntry, $matches)) {
                    $tables[] = $matches[1];
                }
            }
        }

        return array_unique($tables);
    }

    /**
     * Add message to execution log
     */
    private function logMessage(string $message): void
    {
        $timestamp = Carbon::now()->format('Y-m-d H:i:s');
        $this->executionLog[] = "[{$timestamp}] {$message}";
        
        Log::info("Retention Policy Execution: {$message}", [
            'policy_id' => $this->policy->id,
            'execution_id' => $this->execution->id
        ]);
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        $this->failExecution($exception);
    }
}