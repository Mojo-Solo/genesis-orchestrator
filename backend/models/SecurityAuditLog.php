<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SecurityAuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_type',
        'severity',
        'user_id',
        'ip_address',
        'user_agent',
        'request_method',
        'request_path',
        'request_data',
        'response_code',
        'message',
        'metadata'
    ];

    protected $casts = [
        'request_data' => 'array',
        'response_code' => 'integer',
        'metadata' => 'array'
    ];

    // Severity levels
    const SEVERITY_INFO = 'info';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_ERROR = 'error';
    const SEVERITY_CRITICAL = 'critical';

    // Event types
    const EVENT_AUTH_SUCCESS = 'auth_success';
    const EVENT_AUTH_FAILURE = 'auth_failure';
    const EVENT_ACCESS_DENIED = 'access_denied';
    const EVENT_DATA_ACCESS = 'data_access';
    const EVENT_DATA_MODIFICATION = 'data_modification';
    const EVENT_SECURITY_VIOLATION = 'security_violation';
    const EVENT_RATE_LIMIT = 'rate_limit_exceeded';
    const EVENT_HMAC_FAILURE = 'hmac_validation_failure';
    const EVENT_PII_DETECTED = 'pii_detected';

    /**
     * Scope for security violations.
     */
    public function scopeViolations($query)
    {
        return $query->where('event_type', self::EVENT_SECURITY_VIOLATION);
    }

    /**
     * Scope for critical events.
     */
    public function scopeCritical($query)
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    /**
     * Scope for auth failures.
     */
    public function scopeAuthFailures($query)
    {
        return $query->where('event_type', self::EVENT_AUTH_FAILURE);
    }

    /**
     * Scope by IP address.
     */
    public function scopeFromIp($query, $ip)
    {
        return $query->where('ip_address', $ip);
    }

    /**
     * Scope by user.
     */
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Log a security event.
     */
    public static function logEvent($eventType, $message, $severity = self::SEVERITY_INFO, $metadata = [])
    {
        $request = request();
        
        // Redact sensitive data from request
        $requestData = $request->except(['password', 'token', 'secret', 'api_key']);
        
        return self::create([
            'event_type' => $eventType,
            'severity' => $severity,
            'user_id' => auth()->id(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_method' => $request->method(),
            'request_path' => $request->path(),
            'request_data' => $requestData,
            'response_code' => http_response_code() ?: null,
            'message' => $message,
            'metadata' => $metadata
        ]);
    }

    /**
     * Log authentication success.
     */
    public static function logAuthSuccess($userId, $message = 'Authentication successful')
    {
        return self::logEvent(
            self::EVENT_AUTH_SUCCESS,
            $message,
            self::SEVERITY_INFO,
            ['user_id' => $userId]
        );
    }

    /**
     * Log authentication failure.
     */
    public static function logAuthFailure($attemptedUsername, $reason = 'Invalid credentials')
    {
        return self::logEvent(
            self::EVENT_AUTH_FAILURE,
            $reason,
            self::SEVERITY_WARNING,
            ['attempted_username' => $attemptedUsername]
        );
    }

    /**
     * Log access denied.
     */
    public static function logAccessDenied($resource, $reason = 'Insufficient permissions')
    {
        return self::logEvent(
            self::EVENT_ACCESS_DENIED,
            $reason,
            self::SEVERITY_WARNING,
            ['resource' => $resource]
        );
    }

    /**
     * Log security violation.
     */
    public static function logSecurityViolation($violation, $details = [])
    {
        return self::logEvent(
            self::EVENT_SECURITY_VIOLATION,
            $violation,
            self::SEVERITY_CRITICAL,
            $details
        );
    }

    /**
     * Get suspicious activity for an IP.
     */
    public static function getSuspiciousActivity($ip, $timeframeHours = 24)
    {
        $since = now()->subHours($timeframeHours);
        
        return [
            'auth_failures' => self::fromIp($ip)->authFailures()->where('created_at', '>=', $since)->count(),
            'access_denied' => self::fromIp($ip)->where('event_type', self::EVENT_ACCESS_DENIED)->where('created_at', '>=', $since)->count(),
            'rate_limits' => self::fromIp($ip)->where('event_type', self::EVENT_RATE_LIMIT)->where('created_at', '>=', $since)->count(),
            'violations' => self::fromIp($ip)->violations()->where('created_at', '>=', $since)->count(),
            'total_events' => self::fromIp($ip)->where('created_at', '>=', $since)->count()
        ];
    }

    /**
     * Check if IP should be blocked.
     */
    public static function shouldBlockIp($ip, $threshold = 10)
    {
        $activity = self::getSuspiciousActivity($ip, 1); // Last hour
        
        // Block if too many failures or violations
        return ($activity['auth_failures'] > $threshold) || 
               ($activity['violations'] > 0) ||
               ($activity['rate_limits'] > 5);
    }
}