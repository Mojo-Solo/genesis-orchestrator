<?php

namespace App\Services;

use App\Models\DataClassification;
use App\Models\ConsentRecord;
use App\Models\DataSubjectRequest;
use App\Models\DataRetentionPolicy;
use App\Models\DataRetentionExecution;
use App\Models\ComplianceAuditLog;
use App\Models\Tenant;
use App\Models\TenantUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PrivacyComplianceService
{
    /**
     * Data Classification Service
     */
    public function classifyData(string $content, array $context = [], string $tenantId = null): DataClassification
    {
        $classification = DataClassification::classifyData($content, $context);
        
        if ($tenantId) {
            $classification['tenant_id'] = $tenantId;
        }
        
        return DataClassification::create($classification);
    }

    /**
     * Bulk classify data in a table/column
     */
    public function bulkClassifyTableData(string $tenantId, string $tableName, string $columnName): array
    {
        $results = [];
        
        // Sample data from the column to analyze patterns
        $sampleData = DB::table($tableName)
            ->where('tenant_id', $tenantId)
            ->limit(100)
            ->pluck($columnName)
            ->filter()
            ->take(10);

        foreach ($sampleData as $sample) {
            $classification = DataClassification::classifyData($sample, [
                'table_name' => $tableName,
                'column_name' => $columnName
            ]);
            
            // Create or update classification for this table/column
            $existing = DataClassification::where('tenant_id', $tenantId)
                ->where('table_name', $tableName)
                ->where('column_name', $columnName)
                ->first();

            if ($existing) {
                $existing->update($classification);
                $results[] = $existing;
            } else {
                $classification['tenant_id'] = $tenantId;
                $classification['table_name'] = $tableName;
                $classification['column_name'] = $columnName;
                $results[] = DataClassification::create($classification);
            }
        }

        ComplianceAuditLog::logEvent(
            'data_classification_bulk',
            'gdpr',
            'info',
            null,
            null,
            "Bulk data classification performed for {$tableName}.{$columnName}",
            [
                'table_name' => $tableName,
                'column_name' => $columnName,
                'classifications_created' => count($results)
            ],
            $tenantId,
            [],
            null,
            true
        );

        return $results;
    }

    /**
     * Consent Management
     */
    public function grantConsent(array $consentData): ConsentRecord
    {
        $consent = ConsentRecord::createConsent($consentData);
        
        ComplianceAuditLog::logConsentGranted(
            $consent->id,
            $consent->data_subject_id,
            $consent->consent_type,
            $consent->tenant_id
        );

        return $consent;
    }

    public function withdrawConsent(string $consentId, string $reason = null): ConsentRecord
    {
        $consent = ConsentRecord::findOrFail($consentId);
        $consent->withdraw($reason);
        
        ComplianceAuditLog::logConsentWithdrawn(
            $consent->id,
            $consent->data_subject_id,
            $consent->consent_type,
            $consent->tenant_id
        );

        return $consent;
    }

    public function checkConsent(string $dataSubjectId, string $consentType, string $tenantId): bool
    {
        $consent = ConsentRecord::byDataSubject($dataSubjectId)
            ->byTenant($tenantId)
            ->byConsentType($consentType)
            ->active()
            ->first();

        return $consent && $consent->isValid();
    }

    /**
     * Data Subject Rights Processing
     */
    public function submitDataSubjectRequest(array $requestData): DataSubjectRequest
    {
        $request = DataSubjectRequest::create($requestData);

        ComplianceAuditLog::logEvent(
            'dsr_created',
            'gdpr',
            'info',
            $request->data_subject_id,
            $request->data_subject_type,
            "Data subject request created: {$request->request_type}",
            [
                'request_id' => $request->id,
                'request_reference' => $request->request_reference,
                'request_type' => $request->request_type
            ],
            $request->tenant_id
        );

        return $request;
    }

    public function processDataSubjectRequest(string $requestId): array
    {
        $request = DataSubjectRequest::findOrFail($requestId);
        
        $result = match($request->request_type) {
            'access' => $request->processAccessRequest(),
            'erasure' => $request->processErasureRequest(),
            'data_portability' => $request->processDataPortabilityRequest(),
            default => throw new \InvalidArgumentException("Unsupported request type: {$request->request_type}")
        };

        ComplianceAuditLog::logEvent(
            'dsr_processed',
            'gdpr',
            'info',
            $request->data_subject_id,
            $request->data_subject_type,
            "Data subject request processed: {$request->request_type}",
            [
                'request_id' => $request->id,
                'request_reference' => $request->request_reference,
                'request_type' => $request->request_type,
                'result' => $result
            ],
            $request->tenant_id
        );

        return $result;
    }

    public function exportUserData(string $dataSubjectId, string $tenantId, string $format = 'json'): string
    {
        $request = DataSubjectRequest::create([
            'tenant_id' => $tenantId,
            'data_subject_id' => $dataSubjectId,
            'data_subject_type' => 'tenant_user',
            'request_type' => 'data_portability',
            'request_description' => 'Automated data export request',
            'received_at' => Carbon::now(),
            'due_date' => Carbon::now()->addDays(30)
        ]);

        $exportPath = $request->processDataPortabilityRequest();

        ComplianceAuditLog::logDataExport(
            $dataSubjectId,
            'complete_data_export',
            $exportPath,
            $tenantId
        );

        return $exportPath;
    }

    public function deleteUserData(string $dataSubjectId, string $tenantId, string $reason = 'User request'): array
    {
        $request = DataSubjectRequest::create([
            'tenant_id' => $tenantId,
            'data_subject_id' => $dataSubjectId,
            'data_subject_type' => 'tenant_user',
            'request_type' => 'erasure',
            'request_description' => "Data deletion request: {$reason}",
            'received_at' => Carbon::now(),
            'due_date' => Carbon::now()->addDays(30)
        ]);

        $deletedData = $request->processErasureRequest();

        ComplianceAuditLog::logDataDeletion(
            $dataSubjectId,
            'complete_user_data',
            $reason,
            $tenantId
        );

        return $deletedData;
    }

    /**
     * Data Retention Management
     */
    public function createRetentionPolicy(array $policyData): DataRetentionPolicy
    {
        $policy = DataRetentionPolicy::create($policyData);

        ComplianceAuditLog::logEvent(
            'retention_policy_created',
            'gdpr',
            'info',
            null,
            null,
            "Data retention policy created: {$policy->policy_name}",
            [
                'policy_id' => $policy->id,
                'policy_name' => $policy->policy_name,
                'data_category' => $policy->data_category,
                'retention_period_days' => $policy->retention_period_days,
                'retention_action' => $policy->retention_action
            ],
            $policy->tenant_id
        );

        return $policy;
    }

    public function executeRetentionPolicies(string $tenantId): array
    {
        $policies = DataRetentionPolicy::byTenant($tenantId)
            ->active()
            ->effective()
            ->approved()
            ->autoExecute()
            ->get();

        $executions = [];

        foreach ($policies as $policy) {
            $execution = $policy->execute();
            $executions[] = $execution;
        }

        return $executions;
    }

    public function getRetentionSchedule(string $tenantId): array
    {
        $policies = DataRetentionPolicy::byTenant($tenantId)
            ->active()
            ->effective()
            ->approved()
            ->get();

        $schedule = [];

        foreach ($policies as $policy) {
            $eligibleData = $policy->getEligibleData();
            
            if (!empty($eligibleData)) {
                $schedule[] = [
                    'policy_id' => $policy->id,
                    'policy_name' => $policy->policy_name,
                    'data_category' => $policy->data_category,
                    'retention_action' => $policy->retention_action,
                    'eligible_records' => count($eligibleData),
                    'next_execution' => $this->calculateNextExecution($policy),
                    'auto_execute' => $policy->auto_execute
                ];
            }
        }

        return $schedule;
    }

    private function calculateNextExecution(DataRetentionPolicy $policy): Carbon
    {
        $lastExecution = $policy->executions()
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();

        if ($lastExecution) {
            return $lastExecution->completed_at->addDays(30); // Monthly execution
        }

        return Carbon::now()->addDay(); // First execution tomorrow
    }

    /**
     * Compliance Reporting
     */
    public function generateComplianceReport(string $tenantId, array $options = []): array
    {
        $startDate = $options['start_date'] ?? Carbon::now()->subMonth();
        $endDate = $options['end_date'] ?? Carbon::now();
        $includeDetails = $options['include_details'] ?? false;

        $report = [
            'tenant_id' => $tenantId,
            'report_period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'generated_at' => Carbon::now(),
            'compliance_overview' => $this->getComplianceOverview($tenantId, $startDate, $endDate),
            'consent_management' => $this->getConsentSummary($tenantId, $startDate, $endDate),
            'data_subject_requests' => $this->getDataSubjectRequestSummary($tenantId, $startDate, $endDate),
            'data_retention' => $this->getDataRetentionSummary($tenantId, $startDate, $endDate),
            'data_classifications' => $this->getDataClassificationSummary($tenantId),
            'audit_trail' => ComplianceAuditLog::getComplianceSummary($tenantId, $startDate, $endDate),
            'risk_assessment' => $this->calculateComplianceRisk($tenantId),
            'recommendations' => $this->generateRecommendations($tenantId)
        ];

        if ($includeDetails) {
            $report['detailed_audit_logs'] = ComplianceAuditLog::byTenant($tenantId)
                ->dateRange($startDate, $endDate)
                ->orderBy('performed_at', 'desc')
                ->get()
                ->toArray();
        }

        // Log report generation
        ComplianceAuditLog::logEvent(
            'compliance_report_generated',
            'internal',
            'info',
            null,
            null,
            'Compliance report generated',
            [
                'report_period' => ['start' => $startDate, 'end' => $endDate],
                'include_details' => $includeDetails
            ],
            $tenantId,
            [],
            null,
            true
        );

        return $report;
    }

    private function getComplianceOverview(string $tenantId, Carbon $startDate, Carbon $endDate): array
    {
        return [
            'total_data_subjects' => TenantUser::where('tenant_id', $tenantId)->count(),
            'active_consents' => ConsentRecord::byTenant($tenantId)->active()->count(),
            'pending_requests' => DataSubjectRequest::byTenant($tenantId)->pending()->count(),
            'overdue_requests' => DataSubjectRequest::byTenant($tenantId)->overdue()->count(),
            'active_retention_policies' => DataRetentionPolicy::byTenant($tenantId)->active()->count(),
            'recent_executions' => DataRetentionExecution::byTenant($tenantId)
                ->where('scheduled_at', '>=', $startDate)
                ->count()
        ];
    }

    private function getConsentSummary(string $tenantId, Carbon $startDate, Carbon $endDate): array
    {
        $consents = ConsentRecord::byTenant($tenantId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        return [
            'total_consents' => $consents->count(),
            'granted_consents' => $consents->where('consent_status', 'granted')->count(),
            'withdrawn_consents' => $consents->where('consent_status', 'withdrawn')->count(),
            'expired_consents' => $consents->where('consent_status', 'expired')->count(),
            'by_type' => $consents->groupBy('consent_type')->map->count(),
            'expiring_soon' => ConsentRecord::byTenant($tenantId)->expiring(30)->count()
        ];
    }

    private function getDataSubjectRequestSummary(string $tenantId, Carbon $startDate, Carbon $endDate): array
    {
        $requests = DataSubjectRequest::byTenant($tenantId)
            ->whereBetween('received_at', [$startDate, $endDate])
            ->get();

        return [
            'total_requests' => $requests->count(),
            'by_type' => $requests->groupBy('request_type')->map->count(),
            'by_status' => $requests->groupBy('status')->map->count(),
            'average_processing_time' => $requests->where('status', 'completed')
                ->map(fn($r) => $r->received_at->diffInDays($r->completed_at))
                ->average(),
            'overdue_requests' => $requests->filter(fn($r) => $r->isOverdue())->count()
        ];
    }

    private function getDataRetentionSummary(string $tenantId, Carbon $startDate, Carbon $endDate): array
    {
        $executions = DataRetentionExecution::byTenant($tenantId)
            ->whereBetween('scheduled_at', [$startDate, $endDate])
            ->get();

        return [
            'total_executions' => $executions->count(),
            'successful_executions' => $executions->where('status', 'completed')->count(),
            'failed_executions' => $executions->where('status', 'failed')->count(),
            'records_processed' => $executions->sum('records_processed'),
            'records_deleted' => $executions->sum('records_deleted'),
            'records_anonymized' => $executions->sum('records_anonymized'),
            'records_archived' => $executions->sum('records_archived')
        ];
    }

    private function getDataClassificationSummary(string $tenantId): array
    {
        $classifications = DataClassification::byTenant($tenantId)->get();

        return [
            'total_classifications' => $classifications->count(),
            'by_classification' => $classifications->groupBy('classification')->map->count(),
            'by_sensitivity' => $classifications->groupBy('sensitivity_level')->map->count(),
            'requires_encryption' => $classifications->where('requires_encryption', true)->count(),
            'special_categories' => $classifications->whereNotNull('special_categories')->count(),
            'high_risk_data' => $classifications->filter(fn($c) => $c->getRiskScore() >= 70)->count()
        ];
    }

    private function calculateComplianceRisk(string $tenantId): array
    {
        $riskFactors = [];
        $totalRisk = 0;

        // Check for overdue data subject requests
        $overdueRequests = DataSubjectRequest::byTenant($tenantId)->overdue()->count();
        if ($overdueRequests > 0) {
            $riskFactors['overdue_requests'] = [
                'count' => $overdueRequests,
                'risk_score' => min($overdueRequests * 10, 50),
                'description' => 'Overdue data subject requests may result in regulatory penalties'
            ];
            $totalRisk += $riskFactors['overdue_requests']['risk_score'];
        }

        // Check for expired consents
        $expiredConsents = ConsentRecord::byTenant($tenantId)->expired()->count();
        if ($expiredConsents > 0) {
            $riskFactors['expired_consents'] = [
                'count' => $expiredConsents,
                'risk_score' => min($expiredConsents * 5, 30),
                'description' => 'Expired consents mean processing may lack legal basis'
            ];
            $totalRisk += $riskFactors['expired_consents']['risk_score'];
        }

        // Check for failed retention executions
        $failedExecutions = DataRetentionExecution::byTenant($tenantId)
            ->where('status', 'failed')
            ->where('scheduled_at', '>=', Carbon::now()->subWeek())
            ->count();
        if ($failedExecutions > 0) {
            $riskFactors['failed_retention'] = [
                'count' => $failedExecutions,
                'risk_score' => min($failedExecutions * 15, 40),
                'description' => 'Failed retention executions may violate data minimization principle'
            ];
            $totalRisk += $riskFactors['failed_retention']['risk_score'];
        }

        // Check for high-risk data without proper classification
        $unclassifiedHighRisk = DataClassification::byTenant($tenantId)
            ->whereNull('classification')
            ->containsPii()
            ->count();
        if ($unclassifiedHighRisk > 0) {
            $riskFactors['unclassified_data'] = [
                'count' => $unclassifiedHighRisk,
                'risk_score' => min($unclassifiedHighRisk * 8, 35),
                'description' => 'Unclassified PII data may not have appropriate protection'
            ];
            $totalRisk += $riskFactors['unclassified_data']['risk_score'];
        }

        return [
            'total_risk_score' => min($totalRisk, 100),
            'risk_level' => $this->getRiskLevel($totalRisk),
            'risk_factors' => $riskFactors
        ];
    }

    private function getRiskLevel(int $score): string
    {
        return match(true) {
            $score >= 80 => 'critical',
            $score >= 60 => 'high',
            $score >= 40 => 'medium',
            $score >= 20 => 'low',
            default => 'minimal'
        };
    }

    private function generateRecommendations(string $tenantId): array
    {
        $recommendations = [];

        // Check for overdue requests
        $overdueCount = DataSubjectRequest::byTenant($tenantId)->overdue()->count();
        if ($overdueCount > 0) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'data_subject_rights',
                'title' => 'Process overdue data subject requests',
                'description' => "You have {$overdueCount} overdue data subject requests that need immediate attention.",
                'action' => 'Review and process pending requests within required timeframes'
            ];
        }

        // Check for expiring consents
        $expiringCount = ConsentRecord::byTenant($tenantId)->expiring(30)->count();
        if ($expiringCount > 0) {
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'consent_management',
                'title' => 'Renew expiring consents',
                'description' => "{$expiringCount} consents will expire within 30 days.",
                'action' => 'Contact data subjects to renew consent before expiration'
            ];
        }

        // Check for retention policies without auto-execution
        $manualPolicies = DataRetentionPolicy::byTenant($tenantId)
            ->active()
            ->where('auto_execute', false)
            ->count();
        if ($manualPolicies > 0) {
            $recommendations[] = [
                'priority' => 'low',
                'category' => 'data_retention',
                'title' => 'Consider automating retention policies',
                'description' => "{$manualPolicies} retention policies require manual execution.",
                'action' => 'Enable auto-execution for approved retention policies'
            ];
        }

        return $recommendations;
    }

    /**
     * Privacy Dashboard Data
     */
    public function getPrivacyDashboard(string $tenantId): array
    {
        return [
            'overview' => $this->getComplianceOverview($tenantId, Carbon::now()->subMonth(), Carbon::now()),
            'recent_activity' => $this->getRecentActivity($tenantId),
            'compliance_score' => $this->calculateComplianceScore($tenantId),
            'risk_assessment' => $this->calculateComplianceRisk($tenantId),
            'upcoming_tasks' => $this->getUpcomingTasks($tenantId),
            'statistics' => $this->getPrivacyStatistics($tenantId)
        ];
    }

    private function getRecentActivity(string $tenantId): array
    {
        return ComplianceAuditLog::byTenant($tenantId)
            ->recent(48) // Last 48 hours
            ->orderBy('performed_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($log) {
                return [
                    'timestamp' => $log->performed_at,
                    'event_type' => $log->event_type,
                    'description' => $log->event_description,
                    'severity' => $log->severity,
                    'automated' => $log->automated_action
                ];
            })
            ->toArray();
    }

    private function calculateComplianceScore(string $tenantId): int
    {
        $score = 100;
        $deductions = 0;

        // Deduct for overdue requests
        $overdueRequests = DataSubjectRequest::byTenant($tenantId)->overdue()->count();
        $deductions += $overdueRequests * 5;

        // Deduct for expired consents
        $expiredConsents = ConsentRecord::byTenant($tenantId)->expired()->count();
        $deductions += $expiredConsents * 3;

        // Deduct for failed retention executions
        $failedExecutions = DataRetentionExecution::byTenant($tenantId)
            ->failed()
            ->where('scheduled_at', '>=', Carbon::now()->subWeek())
            ->count();
        $deductions += $failedExecutions * 8;

        return max(0, $score - $deductions);
    }

    private function getUpcomingTasks(string $tenantId): array
    {
        $tasks = [];

        // Data subject requests due soon
        $dueSoon = DataSubjectRequest::byTenant($tenantId)->dueSoon(7)->get();
        foreach ($dueSoon as $request) {
            $tasks[] = [
                'type' => 'data_subject_request',
                'id' => $request->id,
                'title' => "Process {$request->request_type} request",
                'due_date' => $request->due_date,
                'priority' => $request->getDaysRemaining() <= 3 ? 'high' : 'medium'
            ];
        }

        // Consents expiring soon
        $expiringSoon = ConsentRecord::byTenant($tenantId)->expiring(14)->get();
        foreach ($expiringSoon as $consent) {
            $tasks[] = [
                'type' => 'consent_renewal',
                'id' => $consent->id,
                'title' => "Renew {$consent->consent_type} consent",
                'due_date' => $consent->consent_expires_at,
                'priority' => 'medium'
            ];
        }

        return $tasks;
    }

    private function getPrivacyStatistics(string $tenantId): array
    {
        return [
            'data_subjects' => TenantUser::where('tenant_id', $tenantId)->count(),
            'data_classifications' => DataClassification::byTenant($tenantId)->count(),
            'active_consents' => ConsentRecord::byTenant($tenantId)->active()->count(),
            'completed_requests' => DataSubjectRequest::byTenant($tenantId)->completed()->count(),
            'retention_policies' => DataRetentionPolicy::byTenant($tenantId)->active()->count(),
            'audit_events_month' => ComplianceAuditLog::byTenant($tenantId)
                ->where('performed_at', '>=', Carbon::now()->subMonth())
                ->count()
        ];
    }
}