<?php

namespace App\Http\Controllers;

use App\Models\SecurityAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Exception;

class SecurityController extends Controller
{
    /**
     * Log a security audit event.
     */
    public function logAudit(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'event_type' => 'required|string|in:auth_success,auth_failure,access_denied,data_access,data_modification,security_violation,rate_limit_exceeded,hmac_validation_failure,pii_detected',
            'severity' => 'required|string|in:info,warning,error,critical',
            'message' => 'required|string|max:1000',
            'metadata' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $log = SecurityAuditLog::logEvent(
                $request->event_type,
                $request->message,
                $request->severity,
                $request->get('metadata', [])
            );

            return response()->json([
                'success' => true,
                'log_id' => $log->id,
                'timestamp' => $log->created_at
            ], 201);

        } catch (Exception $e) {
            Log::error('Failed to log security audit', [
                'error' => $e->getMessage(),
                'event_type' => $request->event_type
            ]);

            return response()->json([
                'error' => 'Failed to log security audit',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check IP reputation and suspicious activity.
     */
    public function checkIp(string $ip): JsonResponse
    {
        try {
            // Validate IP address
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return response()->json([
                    'error' => 'Invalid IP address',
                    'ip' => $ip
                ], 400);
            }

            $activity = SecurityAuditLog::getSuspiciousActivity($ip);
            $shouldBlock = SecurityAuditLog::shouldBlockIp($ip);

            return response()->json([
                'success' => true,
                'ip' => $ip,
                'activity' => $activity,
                'reputation' => [
                    'should_block' => $shouldBlock,
                    'risk_level' => $this->calculateRiskLevel($activity),
                    'recommendation' => $shouldBlock ? 'block' : 'allow'
                ],
                'timestamp' => now()->toIso8601String()
            ]);

        } catch (Exception $e) {
            Log::error('Failed to check IP', [
                'error' => $e->getMessage(),
                'ip' => $ip
            ]);

            return response()->json([
                'error' => 'Failed to check IP',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get security events with filtering.
     */
    public function events(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'event_type' => 'nullable|string',
            'severity' => 'nullable|string|in:info,warning,error,critical',
            'user_id' => 'nullable|integer',
            'ip_address' => 'nullable|ip',
            'hours' => 'nullable|integer|min:1|max:168',
            'limit' => 'nullable|integer|min:1|max:1000',
            'offset' => 'nullable|integer|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $query = SecurityAuditLog::query();

            // Apply filters
            if ($request->has('event_type')) {
                $query->where('event_type', $request->event_type);
            }

            if ($request->has('severity')) {
                $query->where('severity', $request->severity);
            }

            if ($request->has('user_id')) {
                $query->byUser($request->user_id);
            }

            if ($request->has('ip_address')) {
                $query->fromIp($request->ip_address);
            }

            if ($request->has('hours')) {
                $since = now()->subHours($request->hours);
                $query->where('created_at', '>=', $since);
            }

            // Apply pagination
            $limit = $request->get('limit', 100);
            $offset = $request->get('offset', 0);

            $total = $query->count();
            
            $events = $query->orderBy('created_at', 'desc')
                           ->skip($offset)
                           ->take($limit)
                           ->get();

            // Calculate summary statistics
            $summary = [
                'total_events' => $total,
                'critical_count' => clone($query)->critical()->count(),
                'unique_ips' => clone($query)->distinct('ip_address')->count('ip_address'),
                'unique_users' => clone($query)->whereNotNull('user_id')->distinct('user_id')->count('user_id')
            ];

            return response()->json([
                'success' => true,
                'events' => $events,
                'summary' => $summary,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $total
                ],
                'timestamp' => now()->toIso8601String()
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get security events', ['error' => $e->getMessage()]);
            
            return response()->json([
                'error' => 'Failed to get security events',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate risk level based on activity.
     */
    private function calculateRiskLevel(array $activity): string
    {
        $score = 0;

        // Weight different factors
        $score += $activity['auth_failures'] * 10;
        $score += $activity['access_denied'] * 5;
        $score += $activity['rate_limits'] * 8;
        $score += $activity['violations'] * 20;

        if ($score >= 100) {
            return 'critical';
        } elseif ($score >= 50) {
            return 'high';
        } elseif ($score >= 20) {
            return 'medium';
        } elseif ($score >= 5) {
            return 'low';
        }

        return 'minimal';
    }
}