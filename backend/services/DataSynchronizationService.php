<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use App\Jobs\ExecuteSyncJob;
use App\Jobs\MonitorSyncLagJob;
use Carbon\Carbon;
use Exception;

class DataSynchronizationService
{
    protected $config;
    protected $rateLimitService;
    protected $auditService;
    protected $providers = [];

    public function __construct(
        EnhancedRateLimitService $rateLimitService,
        SecurityAuditService $auditService
    ) {
        $this->config = Config::get('integrations.synchronization');
        $this->rateLimitService = $rateLimitService;
        $this->auditService = $auditService;
        
        if ($this->config['enabled']) {
            $this->initializeProviders();
        }
    }

    /**
     * Initialize sync providers
     */
    protected function initializeProviders(): void
    {
        foreach ($this->config['providers'] as $type => $config) {
            if ($config['enabled']) {
                $this->providers[$type] = $this->createProvider($type, $config);
            }
        }
    }

    /**
     * Create a new sync job
     */
    public function createSyncJob(string $tenantId, array $syncConfig): string
    {
        $this->validateSyncConfig($syncConfig);
        
        $syncId = $this->generateSyncId();
        
        $syncJob = [
            'tenant_id' => $tenantId,
            'sync_id' => $syncId,
            'source_system' => $syncConfig['source_system'],
            'target_system' => $syncConfig['target_system'],
            'sync_type' => $syncConfig['sync_type'] ?? 'incremental',
            'status' => 'pending',
            'configuration' => json_encode($syncConfig),
            'source_filters' => json_encode($syncConfig['source_filters'] ?? []),
            'field_mapping' => json_encode($syncConfig['field_mapping'] ?? []),
            'next_sync_at' => $this->calculateNextSyncTime($syncConfig),
            'active' => true,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];

        DB::table('sync_jobs')->insert($syncJob);

        // Schedule initial sync if requested
        if ($syncConfig['immediate_sync'] ?? false) {
            $this->scheduleSyncExecution($syncId);
        }

        $this->auditService->logSecurityEvent([
            'tenant_id' => $tenantId,
            'event_type' => 'sync_job_created',
            'sync_id' => $syncId,
            'source_system' => $syncConfig['source_system'],
            'target_system' => $syncConfig['target_system'],
        ]);

        Log::info('Sync job created', [
            'tenant_id' => $tenantId,
            'sync_id' => $syncId,
            'source' => $syncConfig['source_system'],
            'target' => $syncConfig['target_system'],
        ]);

        return $syncId;
    }

    /**
     * Execute a sync job
     */
    public function executeSyncJob(string $syncId, bool $isManual = false): array
    {
        $syncJob = $this->getSyncJob($syncId);
        
        if (!$syncJob) {
            throw new Exception("Sync job not found: {$syncId}");
        }

        if (!$syncJob->active) {
            throw new Exception("Sync job is inactive: {$syncId}");
        }

        // Check if sync is already running
        if ($syncJob->status === 'running') {
            throw new Exception("Sync job already running: {$syncId}");
        }

        $executionId = $this->generateExecutionId();
        $configuration = json_decode($syncJob->configuration, true);

        try {
            // Update sync job status
            $this->updateSyncJobStatus($syncId, 'running');

            // Create execution record
            $this->createExecutionRecord($syncId, $executionId, $isManual);

            // Get sync provider
            $sourceProvider = $this->getProvider($syncJob->source_system);
            $targetProvider = $this->getProvider($syncJob->target_system);

            $startTime = microtime(true);
            
            // Execute synchronization
            $result = $this->performSync(
                $sourceProvider,
                $targetProvider,
                $configuration,
                $executionId
            );

            $duration = microtime(true) - $startTime;

            // Update execution record with results
            $this->updateExecutionRecord($executionId, [
                'status' => 'completed',
                'completed_at' => Carbon::now(),
                'records_processed' => $result['processed'],
                'records_created' => $result['created'],
                'records_updated' => $result['updated'],
                'records_deleted' => $result['deleted'],
                'records_failed' => $result['failed'],
                'performance_metrics' => json_encode([
                    'duration_seconds' => round($duration, 2),
                    'records_per_second' => $result['processed'] > 0 ? round($result['processed'] / $duration, 2) : 0,
                    'memory_peak_mb' => round(memory_get_peak_usage() / 1024 / 1024, 2),
                ]),
            ]);

            // Update sync job
            $this->updateSyncJobStatus($syncId, 'completed', [
                'last_sync_at' => Carbon::now(),
                'next_sync_at' => $this->calculateNextSyncTime($configuration),
                'sync_stats' => json_encode($result),
            ]);

            $this->auditService->logSecurityEvent([
                'tenant_id' => $syncJob->tenant_id,
                'event_type' => 'sync_job_completed',
                'sync_id' => $syncId,
                'execution_id' => $executionId,
                'records_processed' => $result['processed'],
                'duration_seconds' => round($duration, 2),
            ]);

            Log::info('Sync job completed successfully', [
                'sync_id' => $syncId,
                'execution_id' => $executionId,
                'records_processed' => $result['processed'],
                'duration' => round($duration, 2),
            ]);

            return [
                'success' => true,
                'execution_id' => $executionId,
                'result' => $result,
                'duration_seconds' => round($duration, 2),
            ];

        } catch (Exception $e) {
            // Update execution record with error
            $this->updateExecutionRecord($executionId, [
                'status' => 'failed',
                'completed_at' => Carbon::now(),
                'error_summary' => $e->getMessage(),
            ]);

            // Update sync job status
            $this->updateSyncJobStatus($syncId, 'failed', [
                'last_error' => $e->getMessage(),
            ]);

            $this->auditService->logSecurityEvent([
                'tenant_id' => $syncJob->tenant_id,
                'event_type' => 'sync_job_failed',
                'sync_id' => $syncId,
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
            ]);

            Log::error('Sync job failed', [
                'sync_id' => $syncId,
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Perform the actual synchronization
     */
    protected function performSync($sourceProvider, $targetProvider, array $configuration, string $executionId): array
    {
        $syncType = $configuration['sync_type'] ?? 'incremental';
        $batchSize = min($configuration['batch_size'] ?? $this->config['batch_size'], 10000);
        $fieldMapping = $configuration['field_mapping'] ?? [];
        $sourceFilters = $configuration['source_filters'] ?? [];

        $stats = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'failed' => 0,
        ];

        // Get change detection parameters
        $lastSyncAt = $this->getLastSyncTimestamp($configuration['sync_id'] ?? '');
        $changeDetection = $this->config['change_detection'];

        // Initialize providers
        $sourceProvider->initialize($configuration['source_config'] ?? []);
        $targetProvider->initialize($configuration['target_config'] ?? []);

        try {
            // Fetch data from source
            $sourceData = $sourceProvider->fetchData([
                'filters' => $sourceFilters,
                'since' => $syncType === 'incremental' ? $lastSyncAt : null,
                'batch_size' => $batchSize,
                'change_detection' => $changeDetection,
            ]);

            Log::info('Source data fetched', [
                'execution_id' => $executionId,
                'record_count' => count($sourceData),
                'sync_type' => $syncType,
            ]);

            // Process data in batches
            $batches = array_chunk($sourceData, $batchSize);

            foreach ($batches as $batchIndex => $batch) {
                try {
                    $batchResult = $this->processBatch(
                        $batch,
                        $targetProvider,
                        $fieldMapping,
                        $configuration
                    );

                    $stats['processed'] += $batchResult['processed'];
                    $stats['created'] += $batchResult['created'];
                    $stats['updated'] += $batchResult['updated'];
                    $stats['deleted'] += $batchResult['deleted'];
                    $stats['failed'] += $batchResult['failed'];

                    // Update progress
                    $this->updateSyncProgress($executionId, [
                        'batch' => $batchIndex + 1,
                        'total_batches' => count($batches),
                        'records_processed' => $stats['processed'],
                    ]);

                    Log::debug('Batch processed', [
                        'execution_id' => $executionId,
                        'batch' => $batchIndex + 1,
                        'batch_size' => count($batch),
                        'stats' => $batchResult,
                    ]);

                } catch (Exception $e) {
                    Log::error('Batch processing failed', [
                        'execution_id' => $executionId,
                        'batch' => $batchIndex + 1,
                        'error' => $e->getMessage(),
                    ]);

                    $stats['failed'] += count($batch);

                    // Continue with next batch unless configured to stop on error
                    if ($configuration['stop_on_error'] ?? false) {
                        throw $e;
                    }
                }
            }

            // Handle deletions for full sync
            if ($syncType === 'full' && ($configuration['handle_deletions'] ?? false)) {
                $deletionStats = $this->handleDeletions($sourceProvider, $targetProvider, $configuration);
                $stats['deleted'] += $deletionStats['deleted'];
            }

            return $stats;

        } finally {
            // Cleanup providers
            $sourceProvider->cleanup();
            $targetProvider->cleanup();
        }
    }

    /**
     * Process a batch of records
     */
    protected function processBatch(array $batch, $targetProvider, array $fieldMapping, array $configuration): array
    {
        $stats = ['processed' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'failed' => 0];

        foreach ($batch as $record) {
            try {
                // Transform record using field mapping
                $transformedRecord = $this->transformRecord($record, $fieldMapping);

                // Apply business rules if configured
                if (isset($configuration['business_rules'])) {
                    $transformedRecord = $this->applyBusinessRules($transformedRecord, $configuration['business_rules']);
                }

                // Determine operation (create, update, delete)
                $operation = $this->determineOperation($transformedRecord, $targetProvider);

                // Execute operation
                switch ($operation) {
                    case 'create':
                        $targetProvider->createRecord($transformedRecord);
                        $stats['created']++;
                        break;

                    case 'update':
                        $targetProvider->updateRecord($transformedRecord);
                        $stats['updated']++;
                        break;

                    case 'delete':
                        $targetProvider->deleteRecord($transformedRecord);
                        $stats['deleted']++;
                        break;
                }

                $stats['processed']++;

            } catch (Exception $e) {
                Log::error('Record processing failed', [
                    'record_id' => $record['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);

                $stats['failed']++;

                // Continue with next record unless configured to stop
                if ($configuration['stop_on_record_error'] ?? false) {
                    throw $e;
                }
            }
        }

        return $stats;
    }

    /**
     * Transform record using field mapping
     */
    protected function transformRecord(array $record, array $fieldMapping): array
    {
        if (empty($fieldMapping)) {
            return $record;
        }

        $transformed = [];

        foreach ($fieldMapping as $targetField => $sourceField) {
            // Support dot notation for nested fields
            $value = $this->getNestedValue($record, $sourceField);
            $this->setNestedValue($transformed, $targetField, $value);
        }

        return $transformed;
    }

    /**
     * Apply business rules to transformed record
     */
    protected function applyBusinessRules(array $record, array $rules): array
    {
        foreach ($rules as $rule) {
            switch ($rule['type']) {
                case 'default_value':
                    if (empty($record[$rule['field']])) {
                        $record[$rule['field']] = $rule['value'];
                    }
                    break;

                case 'format_date':
                    if (isset($record[$rule['field']])) {
                        $record[$rule['field']] = Carbon::parse($record[$rule['field']])
                            ->format($rule['format']);
                    }
                    break;

                case 'concatenate':
                    $values = [];
                    foreach ($rule['fields'] as $field) {
                        if (isset($record[$field])) {
                            $values[] = $record[$field];
                        }
                    }
                    $record[$rule['target_field']] = implode($rule['separator'] ?? ' ', $values);
                    break;

                case 'conditional':
                    if ($this->evaluateCondition($record, $rule['condition'])) {
                        $record = array_merge($record, $rule['then']);
                    } elseif (isset($rule['else'])) {
                        $record = array_merge($record, $rule['else']);
                    }
                    break;
            }
        }

        return $record;
    }

    /**
     * Get sync job statistics
     */
    public function getSyncJobStatistics(string $syncId): array
    {
        $syncJob = $this->getSyncJob($syncId);
        
        if (!$syncJob) {
            throw new Exception("Sync job not found: {$syncId}");
        }

        // Get execution statistics
        $executions = DB::table('sync_executions')
            ->where('sync_id', $syncId)
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->get();

        $totalExecutions = $executions->count();
        $successfulExecutions = $executions->where('status', 'completed')->count();
        $failedExecutions = $executions->where('status', 'failed')->count();

        $stats = [
            'sync_id' => $syncId,
            'status' => $syncJob->status,
            'total_executions' => $totalExecutions,
            'successful_executions' => $successfulExecutions,
            'failed_executions' => $failedExecutions,
            'success_rate' => $totalExecutions > 0 ? ($successfulExecutions / $totalExecutions) : 0,
            'last_sync_at' => $syncJob->last_sync_at,
            'next_sync_at' => $syncJob->next_sync_at,
        ];

        if ($successfulExecutions > 0) {
            $completedExecutions = $executions->where('status', 'completed');
            
            $stats['performance'] = [
                'avg_duration_seconds' => $completedExecutions->avg('duration_seconds'),
                'avg_records_processed' => $completedExecutions->avg('records_processed'),
                'total_records_processed' => $completedExecutions->sum('records_processed'),
                'total_records_created' => $completedExecutions->sum('records_created'),
                'total_records_updated' => $completedExecutions->sum('records_updated'),
                'total_records_deleted' => $completedExecutions->sum('records_deleted'),
            ];
        }

        return $stats;
    }

    /**
     * Monitor sync lag and alert if thresholds exceeded
     */
    public function monitorSyncLag(): array
    {
        $alerts = [];
        $lagThreshold = $this->config['monitoring']['sync_lag_threshold'];

        $overdueJobs = DB::table('sync_jobs')
            ->where('active', true)
            ->where('next_sync_at', '<', Carbon::now())
            ->where('status', '!=', 'running')
            ->get();

        foreach ($overdueJobs as $job) {
            $lagMinutes = Carbon::now()->diffInMinutes(Carbon::parse($job->next_sync_at));
            
            if ($lagMinutes > $lagThreshold) {
                $alerts[] = [
                    'sync_id' => $job->sync_id,
                    'tenant_id' => $job->tenant_id,
                    'lag_minutes' => $lagMinutes,
                    'source_system' => $job->source_system,
                    'target_system' => $job->target_system,
                    'last_sync_at' => $job->last_sync_at,
                ];

                Log::warning('Sync job lag detected', [
                    'sync_id' => $job->sync_id,
                    'lag_minutes' => $lagMinutes,
                    'threshold' => $lagThreshold,
                ]);
            }
        }

        return $alerts;
    }

    /**
     * Pause a sync job
     */
    public function pauseSyncJob(string $syncId): bool
    {
        return $this->updateSyncJobStatus($syncId, 'paused', ['active' => false]);
    }

    /**
     * Resume a sync job
     */
    public function resumeSyncJob(string $syncId): bool
    {
        $syncJob = $this->getSyncJob($syncId);
        $configuration = json_decode($syncJob->configuration, true);
        
        return $this->updateSyncJobStatus($syncId, 'pending', [
            'active' => true,
            'next_sync_at' => $this->calculateNextSyncTime($configuration),
        ]);
    }

    /**
     * Delete a sync job
     */
    public function deleteSyncJob(string $syncId): bool
    {
        return DB::transaction(function () use ($syncId) {
            DB::table('sync_executions')->where('sync_id', $syncId)->delete();
            return DB::table('sync_jobs')->where('sync_id', $syncId)->delete() > 0;
        });
    }

    /**
     * Helper methods
     */
    protected function createProvider(string $type, array $config)
    {
        $className = 'App\\SyncProviders\\' . ucfirst($type) . 'SyncProvider';
        
        if (!class_exists($className)) {
            throw new Exception("Sync provider class not found: {$className}");
        }

        return new $className($config);
    }

    protected function getProvider(string $systemName)
    {
        // Determine provider type from system name
        $providerType = $this->determineProviderType($systemName);
        
        if (!isset($this->providers[$providerType])) {
            throw new Exception("Sync provider not available: {$providerType}");
        }

        return $this->providers[$providerType];
    }

    protected function determineProviderType(string $systemName): string
    {
        // Map system names to provider types
        $mapping = [
            'mysql' => 'database',
            'postgresql' => 'database',
            'sqlserver' => 'database',
            'oracle' => 'database',
            'salesforce' => 'api',
            'hubspot' => 'api',
            'zendesk' => 'api',
            's3' => 'file_system',
            'sftp' => 'file_system',
            'kafka' => 'message_queue',
            'rabbitmq' => 'message_queue',
        ];

        return $mapping[$systemName] ?? 'api';
    }

    protected function validateSyncConfig(array $config): void
    {
        $required = ['source_system', 'target_system'];
        
        foreach ($required as $field) {
            if (!isset($config[$field])) {
                throw new Exception("Required sync configuration field missing: {$field}");
            }
        }

        // Validate sync interval if specified
        if (isset($config['sync_interval']) && $config['sync_interval'] < 60) {
            throw new Exception('Sync interval must be at least 60 seconds');
        }
    }

    protected function calculateNextSyncTime(array $configuration): Carbon
    {
        $interval = $configuration['sync_interval'] ?? $this->config['default_sync_interval'];
        return Carbon::now()->addSeconds($interval);
    }

    protected function generateSyncId(): string
    {
        return 'sync_' . bin2hex(random_bytes(16));
    }

    protected function generateExecutionId(): string
    {
        return 'exec_' . bin2hex(random_bytes(16));
    }

    protected function getSyncJob(string $syncId)
    {
        return DB::table('sync_jobs')->where('sync_id', $syncId)->first();
    }

    protected function updateSyncJobStatus(string $syncId, string $status, array $additionalFields = []): bool
    {
        $updateData = array_merge(['status' => $status, 'updated_at' => Carbon::now()], $additionalFields);
        
        return DB::table('sync_jobs')
            ->where('sync_id', $syncId)
            ->update($updateData) > 0;
    }

    protected function createExecutionRecord(string $syncId, string $executionId, bool $isManual): void
    {
        DB::table('sync_executions')->insert([
            'sync_id' => $syncId,
            'execution_id' => $executionId,
            'started_at' => Carbon::now(),
            'status' => 'running',
            'is_manual' => $isManual,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    protected function updateExecutionRecord(string $executionId, array $data): void
    {
        $data['updated_at'] = Carbon::now();
        
        DB::table('sync_executions')
            ->where('execution_id', $executionId)
            ->update($data);
    }

    protected function updateSyncProgress(string $executionId, array $progress): void
    {
        // Could store progress in cache or database for real-time monitoring
        Cache::put("sync_progress_{$executionId}", $progress, 3600);
    }

    protected function getLastSyncTimestamp(string $syncId): ?Carbon
    {
        $lastExecution = DB::table('sync_executions')
            ->where('sync_id', $syncId)
            ->where('status', 'completed')
            ->orderBy('completed_at', 'desc')
            ->first();

        return $lastExecution ? Carbon::parse($lastExecution->completed_at) : null;
    }

    protected function getNestedValue(array $array, string $key)
    {
        if (strpos($key, '.') === false) {
            return $array[$key] ?? null;
        }

        $keys = explode('.', $key);
        $value = $array;

        foreach ($keys as $k) {
            if (!is_array($value) || !isset($value[$k])) {
                return null;
            }
            $value = $value[$k];
        }

        return $value;
    }

    protected function setNestedValue(array &$array, string $key, $value): void
    {
        if (strpos($key, '.') === false) {
            $array[$key] = $value;
            return;
        }

        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $k) {
            if (!isset($current[$k]) || !is_array($current[$k])) {
                $current[$k] = [];
            }
            $current = &$current[$k];
        }

        $current = $value;
    }

    protected function determineOperation(array $record, $targetProvider): string
    {
        // Check if record exists in target
        $exists = $targetProvider->recordExists($record);
        
        if (isset($record['_operation'])) {
            return $record['_operation'];
        }

        if (isset($record['_deleted']) && $record['_deleted']) {
            return 'delete';
        }

        return $exists ? 'update' : 'create';
    }

    protected function evaluateCondition(array $record, array $condition): bool
    {
        $field = $condition['field'];
        $operator = $condition['operator'];
        $value = $condition['value'];
        $recordValue = $record[$field] ?? null;

        switch ($operator) {
            case '==':
                return $recordValue == $value;
            case '!=':
                return $recordValue != $value;
            case '>':
                return $recordValue > $value;
            case '<':
                return $recordValue < $value;
            case 'contains':
                return strpos($recordValue, $value) !== false;
            case 'empty':
                return empty($recordValue);
            case 'not_empty':
                return !empty($recordValue);
            default:
                return false;
        }
    }

    protected function handleDeletions($sourceProvider, $targetProvider, array $configuration): array
    {
        // Implementation would depend on the specific providers
        // This is a placeholder for deletion handling logic
        return ['deleted' => 0];
    }

    protected function scheduleSyncExecution(string $syncId): void
    {
        ExecuteSyncJob::dispatch($syncId)->onQueue('sync_jobs');
    }
}