<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PrivacyComplianceService;
use App\Services\DataClassificationService;
use App\Models\DataSubjectRequest;
use App\Models\ConsentRecord;
use App\Models\DataRetentionPolicy;
use App\Models\DataRetentionExecution;
use App\Models\DataClassification;
use App\Models\PrivacySetting;
use App\Models\PrivacyImpactAssessment;
use App\Models\ComplianceAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class PrivacyComplianceController extends Controller
{
    private PrivacyComplianceService $privacyService;
    private DataClassificationService $classificationService;

    public function __construct(
        PrivacyComplianceService $privacyService,
        DataClassificationService $classificationService
    ) {
        $this->privacyService = $privacyService;
        $this->classificationService = $classificationService;
    }

    /**
     * Get privacy compliance dashboard
     */
    public function dashboard(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID');
        
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant ID required'], 400);
        }

        try {
            $dashboard = $this->privacyService->getPrivacyDashboard($tenantId);
            $classificationInsights = $this->classificationService->getClassificationInsights($tenantId);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'privacy_dashboard' => $dashboard,
                    'classification_insights' => $classificationInsights,
                    'generated_at' => Carbon::now()->toISOString()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to load privacy dashboard',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Data Subject Rights Management
     */

    /**
     * Submit a data subject request
     */
    public function submitDataSubjectRequest(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'data_subject_id' => 'required|string',
            'data_subject_type' => 'string|in:tenant_user,external_user',
            'request_type' => 'required|in:access,rectification,erasure,restrict_processing,data_portability,object_processing,withdraw_consent',
            'request_description' => 'required|string|max:1000',
            'requested_data_categories' => 'array',
            'identity_verification' => 'array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');
        $requestData = $validator->validated();
        $requestData['tenant_id'] = $tenantId;

        try {
            $dsrRequest = $this->privacyService->submitDataSubjectRequest($requestData);

            return response()->json([
                'success' => true,
                'data' => [
                    'request' => $dsrRequest,
                    'reference' => $dsrRequest->request_reference,
                    'due_date' => $dsrRequest->due_date->toISOString()
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to submit data subject request',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process a data subject request
     */
    public function processDataSubjectRequest(Request $request, string $requestId): JsonResponse
    {
        try {
            $result = $this->privacyService->processDataSubjectRequest($requestId);

            return response()->json([
                'success' => true,
                'data' => [
                    'processing_result' => $result,
                    'processed_at' => Carbon::now()->toISOString()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to process data subject request',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get data subject requests
     */
    public function getDataSubjectRequests(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID');
        
        $query = DataSubjectRequest::byTenant($tenantId);

        // Apply filters
        if ($request->has('status')) {
            $query->byStatus($request->get('status'));
        }
        
        if ($request->has('type')) {
            $query->byType($request->get('type'));
        }
        
        if ($request->has('data_subject_id')) {
            $query->byDataSubject($request->get('data_subject_id'));
        }

        // Apply sorting and pagination
        $sortBy = $request->get('sort_by', 'received_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $perPage = min($request->get('per_page', 15), 100);

        $requests = $query->orderBy($sortBy, $sortOrder)
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    }

    /**
     * Export user data (GDPR Article 20)
     */
    public function exportUserData(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'data_subject_id' => 'required|string',
            'format' => 'string|in:json,csv,xml'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');
        $dataSubjectId = $request->get('data_subject_id');
        $format = $request->get('format', 'json');

        try {
            $exportPath = $this->privacyService->exportUserData($dataSubjectId, $tenantId, $format);

            return response()->json([
                'success' => true,
                'data' => [
                    'export_file' => basename($exportPath),
                    'download_url' => url("/api/privacy/download-export/" . basename($exportPath)),
                    'expires_at' => Carbon::now()->addDays(7)->toISOString()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to export user data',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete user data (GDPR Article 17 - Right to be forgotten)
     */
    public function deleteUserData(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'data_subject_id' => 'required|string',
            'reason' => 'string|max:500',
            'confirmation' => 'required|boolean|accepted'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');
        $dataSubjectId = $request->get('data_subject_id');
        $reason = $request->get('reason', 'User request for data deletion');

        try {
            $deletedData = $this->privacyService->deleteUserData($dataSubjectId, $tenantId, $reason);

            return response()->json([
                'success' => true,
                'data' => [
                    'deleted_data' => $deletedData,
                    'deleted_at' => Carbon::now()->toISOString(),
                    'irreversible' => true
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to delete user data',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download exported data file
     */
    public function downloadExport(string $filename): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $path = storage_path("app/exports/{$filename}");
        
        if (!file_exists($path)) {
            abort(404, 'Export file not found');
        }

        return response()->download($path)->deleteFileAfterSend();
    }

    /**
     * Consent Management
     */

    /**
     * Grant consent
     */
    public function grantConsent(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'data_subject_id' => 'required|string',
            'data_subject_type' => 'string|in:tenant_user,external_user',
            'consent_type' => 'required|string',
            'processing_purpose' => 'required|string',
            'consent_description' => 'required|string',
            'consent_method' => 'required|in:explicit_opt_in,implied,legitimate_interest',
            'consent_source' => 'required|in:web_form,api,phone,paper',
            'consent_evidence' => 'array',
            'consent_expires_at' => 'date|after:now',
            'granular_permissions' => 'array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');
        $consentData = $validator->validated();
        $consentData['tenant_id'] = $tenantId;

        try {
            $consent = $this->privacyService->grantConsent($consentData);

            return response()->json([
                'success' => true,
                'data' => [
                    'consent' => $consent,
                    'consent_id' => $consent->id,
                    'granted_at' => $consent->consent_given_at->toISOString()
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to grant consent',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Withdraw consent
     */
    public function withdrawConsent(Request $request, string $consentId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $reason = $request->get('reason');

        try {
            $consent = $this->privacyService->withdrawConsent($consentId, $reason);

            return response()->json([
                'success' => true,
                'data' => [
                    'consent' => $consent,
                    'withdrawn_at' => $consent->consent_withdrawn_at->toISOString()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to withdraw consent',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check consent status
     */
    public function checkConsent(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'data_subject_id' => 'required|string',
            'consent_type' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');
        $dataSubjectId = $request->get('data_subject_id');
        $consentType = $request->get('consent_type');

        try {
            $hasConsent = $this->privacyService->checkConsent($dataSubjectId, $consentType, $tenantId);

            return response()->json([
                'success' => true,
                'data' => [
                    'has_consent' => $hasConsent,
                    'checked_at' => Carbon::now()->toISOString()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to check consent',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Data Classification
     */

    /**
     * Classify data content
     */
    public function classifyData(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'content' => 'required|string',
            'context' => 'array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');
        $content = $request->get('content');
        $context = $request->get('context', []);

        try {
            $classification = $this->classificationService->classifyData($content, $context, $tenantId);

            return response()->json([
                'success' => true,
                'data' => [
                    'classification' => $classification,
                    'classified_at' => Carbon::now()->toISOString()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to classify data',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk classify table data
     */
    public function bulkClassifyTable(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'table_name' => 'required|string',
            'column_name' => 'string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');
        $tableName = $request->get('table_name');
        $columnName = $request->get('column_name');

        try {
            $results = $this->classificationService->bulkClassifyTableData($tenantId, $tableName, $columnName);

            return response()->json([
                'success' => true,
                'data' => [
                    'classifications' => $results,
                    'processed_at' => Carbon::now()->toISOString()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to perform bulk classification',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update classification from feedback
     */
    public function updateClassificationFeedback(Request $request, string $classificationId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'classification' => 'string|in:public,internal,confidential,restricted',
            'sensitivity_level' => 'string|in:low,medium,high,critical',
            'pii_categories' => 'array',
            'special_categories' => 'array',
            'requires_encryption' => 'boolean',
            'requires_anonymization' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $corrections = $validator->validated();

        try {
            $this->classificationService->updateClassificationFromFeedback($classificationId, $corrections);

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Classification feedback processed successfully',
                    'updated_at' => Carbon::now()->toISOString()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to process classification feedback',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Data Retention Management
     */

    /**
     * Create retention policy
     */
    public function createRetentionPolicy(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'policy_name' => 'required|string|max:255',
            'policy_description' => 'required|string',
            'data_category' => 'required|in:user_data,transaction_data,communication_data,behavioral_data,technical_data,marketing_data',
            'legal_basis' => 'required|in:consent,contract,legal_obligation,legitimate_interest,vital_interests,public_task',
            'retention_period_days' => 'required|integer|min:1',
            'retention_action' => 'required|in:delete,anonymize,archive,notify_review',
            'conditions' => 'array',
            'exceptions' => 'array',
            'auto_execute' => 'boolean',
            'notification_emails' => 'string',
            'warning_days' => 'integer|min:1',
            'effective_from' => 'date',
            'effective_until' => 'date|after:effective_from'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');
        $policyData = $validator->validated();
        $policyData['tenant_id'] = $tenantId;
        $policyData['created_by'] = $request->user()->id ?? null;

        try {
            $policy = $this->privacyService->createRetentionPolicy($policyData);

            return response()->json([
                'success' => true,
                'data' => [
                    'policy' => $policy,
                    'requires_approval' => !$policy->isApproved()
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to create retention policy',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Execute retention policies
     */
    public function executeRetentionPolicies(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID');

        try {
            $executions = $this->privacyService->executeRetentionPolicies($tenantId);

            return response()->json([
                'success' => true,
                'data' => [
                    'executions' => $executions,
                    'executed_at' => Carbon::now()->toISOString()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to execute retention policies',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get retention schedule
     */
    public function getRetentionSchedule(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID');

        try {
            $schedule = $this->privacyService->getRetentionSchedule($tenantId);

            return response()->json([
                'success' => true,
                'data' => [
                    'schedule' => $schedule,
                    'generated_at' => Carbon::now()->toISOString()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get retention schedule',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Privacy Settings Management
     */

    /**
     * Get user privacy settings
     */
    public function getPrivacySettings(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID');
        $userId = $request->get('user_id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'error' => 'User ID required'
            ], 400);
        }

        try {
            $dashboard = PrivacySetting::getPrivacyDashboard($tenantId, $userId);

            return response()->json([
                'success' => true,
                'data' => $dashboard
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get privacy settings',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update privacy setting
     */
    public function updatePrivacySetting(Request $request, string $settingId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'setting_value' => 'required|string',
            'reason' => 'string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $settingValue = $request->get('setting_value');
        $reason = $request->get('reason', PrivacySetting::REASON_USER_REQUEST);

        try {
            $setting = PrivacySetting::findOrFail($settingId);
            $setting->updateValue($settingValue, $reason, $request->user()->id ?? null);

            return response()->json([
                'success' => true,
                'data' => [
                    'setting' => $setting,
                    'updated_at' => $setting->last_updated_at->toISOString()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to update privacy setting',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Privacy Impact Assessments
     */

    /**
     * Create Privacy Impact Assessment
     */
    public function createPrivacyImpactAssessment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'assessment_name' => 'required|string|max:255',
            'description' => 'required|string',
            'project_or_system' => 'required|string|max:255',
            'data_types_processed' => 'required|array',
            'processing_purposes' => 'required|array',
            'data_sources' => 'required|array',
            'data_recipients' => 'required|array',
            'transfers_outside_eea' => 'array',
            'necessity_justification' => 'required|string',
            'proportionality_assessment' => 'required|string',
            'risks_identified' => 'array',
            'mitigation_measures' => 'array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');
        $assessmentData = $validator->validated();
        $assessmentData['tenant_id'] = $tenantId;
        $assessmentData['conducted_by'] = $request->user()->id ?? null;

        try {
            $assessment = PrivacyImpactAssessment::create($assessmentData);
            $assessment->update(['risk_level' => $assessment->determineRiskLevel()]);

            return response()->json([
                'success' => true,
                'data' => [
                    'assessment' => $assessment,
                    'risk_score' => $assessment->calculateRiskScore(),
                    'recommendations' => $assessment->generateRecommendations(),
                    'requires_dpia' => $assessment->requiresDPIA()
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to create privacy impact assessment',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create automated PIA
     */
    public function createAutomatedPIA(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'project_name' => 'required|string|max:255',
            'data_classification_ids' => 'array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');
        $projectName = $request->get('project_name');
        $classificationIds = $request->get('data_classification_ids', []);

        try {
            // Get data classifications
            $dataClassifications = DataClassification::byTenant($tenantId);
            
            if (!empty($classificationIds)) {
                $dataClassifications = $dataClassifications->whereIn('id', $classificationIds);
            }
            
            $classifications = $dataClassifications->get();

            $assessment = PrivacyImpactAssessment::createAutomatedPIA($tenantId, $projectName, $classifications);

            return response()->json([
                'success' => true,
                'data' => [
                    'assessment' => $assessment,
                    'classifications_analyzed' => $classifications->count(),
                    'risk_score' => $assessment->calculateRiskScore(),
                    'recommendations' => $assessment->generateRecommendations()
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to create automated PIA',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Compliance Reporting
     */

    /**
     * Generate compliance report
     */
    public function generateComplianceReport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'date',
            'end_date' => 'date|after:start_date',
            'include_details' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID');
        $options = $validator->validated();

        try {
            $report = $this->privacyService->generateComplianceReport($tenantId, $options);

            return response()->json([
                'success' => true,
                'data' => $report
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to generate compliance report',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get audit trail
     */
    public function getAuditTrail(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID');
        
        $query = ComplianceAuditLog::byTenant($tenantId);

        // Apply filters
        if ($request->has('event_type')) {
            $query->where('event_type', $request->get('event_type'));
        }
        
        if ($request->has('compliance_area')) {
            $query->where('compliance_area', $request->get('compliance_area'));
        }
        
        if ($request->has('severity')) {
            $query->where('severity', $request->get('severity'));
        }
        
        if ($request->has('start_date')) {
            $query->where('performed_at', '>=', $request->get('start_date'));
        }
        
        if ($request->has('end_date')) {
            $query->where('performed_at', '<=', $request->get('end_date'));
        }

        // Apply sorting and pagination
        $sortBy = $request->get('sort_by', 'performed_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $perPage = min($request->get('per_page', 50), 100);

        $auditLogs = $query->orderBy($sortBy, $sortOrder)
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $auditLogs
        ]);
    }
}