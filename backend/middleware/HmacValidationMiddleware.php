<?php

namespace App\Http\Middleware;

use App\Models\SecurityAuditLog;
use App\Services\VaultService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as ResponseCode;

/**
 * HMAC Validation Middleware for Webhook Security
 * 
 * Provides cryptographic validation of webhook requests using HMAC signatures.
 * Implements best practices for webhook security including timing-safe comparison,
 * signature verification, replay attack prevention, and comprehensive audit logging.
 */
class HmacValidationMiddleware
{
    private VaultService $vaultService;
    private array $config;

    public function __construct(VaultService $vaultService)
    {
        $this->vaultService = $vaultService;
        $this->config = Config::get('webhook_security', []);
    }

    /**
     * Handle an incoming request and validate HMAC signature.
     */
    public function handle(Request $request, Closure $next, string $secretPath = 'webhooks/hmac_secret')
    {
        $startTime = microtime(true);
        
        try {
            // Skip HMAC validation in development if configured
            if ($this->shouldSkipValidation($request)) {
                SecurityAuditLog::logEvent(
                    SecurityAuditLog::EVENT_AUTH_SUCCESS,
                    'HMAC validation skipped (development mode)',
                    SecurityAuditLog::SEVERITY_INFO,
                    ['path' => $request->path(), 'reason' => 'development_skip']
                );
                return $next($request);
            }

            // Extract signature from headers
            $signature = $this->extractSignature($request);
            if (!$signature) {
                return $this->handleValidationFailure($request, 'missing_signature', 'HMAC signature not provided');
            }

            // Get webhook secret from vault
            $secret = $this->getWebhookSecret($secretPath);
            if (!$secret) {
                return $this->handleValidationFailure($request, 'secret_unavailable', 'Webhook secret not available');
            }

            // Get raw request body
            $payload = $request->getContent();
            if (empty($payload)) {
                return $this->handleValidationFailure($request, 'empty_payload', 'Request payload is empty');
            }

            // Validate signature
            $isValid = $this->validateSignature($signature, $payload, $secret, $request);
            if (!$isValid) {
                return $this->handleValidationFailure($request, 'invalid_signature', 'HMAC signature validation failed');
            }

            // Check for replay attacks
            if ($this->isReplayAttack($request)) {
                return $this->handleValidationFailure($request, 'replay_attack', 'Potential replay attack detected');
            }

            // Log successful validation
            $processingTime = (microtime(true) - $startTime) * 1000;
            SecurityAuditLog::logEvent(
                SecurityAuditLog::EVENT_AUTH_SUCCESS,
                'HMAC signature validation successful',
                SecurityAuditLog::SEVERITY_INFO,
                [
                    'path' => $request->path(),
                    'source_ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'processing_time_ms' => round($processingTime, 2),
                    'payload_size' => strlen($payload),
                    'signature_algorithm' => $this->getSignatureAlgorithm($signature)
                ]
            );

            // Add validated metadata to request
            $request->attributes->add([
                'hmac_validated' => true,
                'webhook_source' => $this->identifyWebhookSource($request),
                'signature_algorithm' => $this->getSignatureAlgorithm($signature)
            ]);

            return $next($request);

        } catch (\Exception $e) {
            Log::error('HMAC validation middleware error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_path' => $request->path()
            ]);

            return $this->handleValidationFailure(
                $request, 
                'middleware_error', 
                'Internal validation error: ' . $e->getMessage()
            );
        }
    }

    /**
     * Determine if HMAC validation should be skipped for this request.
     */
    private function shouldSkipValidation(Request $request): bool
    {
        // Never skip in production
        if (Config::get('app.env') === 'production') {
            return false;
        }

        // Skip if explicitly disabled in development
        if (Config::get('webhook_security.skip_hmac_validation', false)) {
            return true;
        }

        // Skip for specific testing routes
        $skipPaths = Config::get('webhook_security.skip_paths', []);
        foreach ($skipPaths as $path) {
            if ($request->is($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract HMAC signature from request headers.
     */
    private function extractSignature(Request $request): ?string
    {
        // Check multiple possible header names
        $signatureHeaders = [
            'HTTP_X_SIGNATURE_256',
            'HTTP_X_HUB_SIGNATURE_256',
            'HTTP_X_SIGNATURE',
            'HTTP_X_HUB_SIGNATURE',
            'HTTP_SIGNATURE',
        ];

        foreach ($signatureHeaders as $header) {
            $signature = $request->server($header) ?: $request->header(str_replace('HTTP_', '', strtolower($header)));
            if ($signature) {
                return $this->normalizeSignature($signature);
            }
        }

        return null;
    }

    /**
     * Normalize signature format (remove algorithm prefix if present).
     */
    private function normalizeSignature(string $signature): string
    {
        // Remove common algorithm prefixes
        $prefixes = ['sha256=', 'sha1=', 'sha512='];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($signature, $prefix)) {
                return substr($signature, strlen($prefix));
            }
        }
        
        return $signature;
    }

    /**
     * Get webhook secret from vault.
     */
    private function getWebhookSecret(string $secretPath): ?string
    {
        try {
            $secretData = $this->vaultService->getSecret($secretPath);
            return $secretData['value'] ?? $secretData['secret'] ?? $secretData['key'] ?? null;
        } catch (\Exception $e) {
            Log::error('Failed to retrieve webhook secret from vault', [
                'path' => $secretPath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Validate HMAC signature using timing-safe comparison.
     */
    private function validateSignature(string $signature, string $payload, string $secret, Request $request): bool
    {
        // Determine algorithm based on signature length or header
        $algorithm = $this->determineAlgorithm($signature, $request);
        
        // Generate expected signature
        $expectedSignature = hash_hmac($algorithm, $payload, $secret);
        
        // Use timing-safe comparison
        $isValid = hash_equals($expectedSignature, $signature);
        
        // Log validation attempt with details
        SecurityAuditLog::create([
            'event_type' => $isValid ? 'hmac_validation_success' : 'hmac_validation_failure',
            'severity' => $isValid ? 'info' : 'warning',
            'user_id' => null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_method' => $request->method(),
            'request_path' => $request->path(),
            'request_data' => [],
            'response_code' => null,
            'message' => $isValid ? 'HMAC validation successful' : 'HMAC validation failed',
            'metadata' => [
                'hmac_valid' => $isValid,
                'algorithm' => $algorithm,
                'payload_size' => strlen($payload),
                'signature_length' => strlen($signature),
                'webhook_source' => $this->identifyWebhookSource($request),
                'timestamp_skew_seconds' => $this->calculateTimestampSkew($request),
            ]
        ]);

        return $isValid;
    }

    /**
     * Determine HMAC algorithm based on signature characteristics.
     */
    private function determineAlgorithm(string $signature, Request $request): string
    {
        // Check if algorithm is specified in header
        $algorithmHeader = $request->header('x-signature-algorithm');
        if ($algorithmHeader && in_array($algorithmHeader, ['sha256', 'sha1', 'sha512'])) {
            return $algorithmHeader;
        }

        // Determine by signature length
        return match (strlen($signature)) {
            40 => 'sha1',      // SHA-1 produces 40 hex chars
            64 => 'sha256',    // SHA-256 produces 64 hex chars
            128 => 'sha512',   // SHA-512 produces 128 hex chars
            default => 'sha256' // Default to SHA-256
        };
    }

    /**
     * Get signature algorithm for logging purposes.
     */
    private function getSignatureAlgorithm(string $signature): string
    {
        return match (strlen($signature)) {
            40 => 'sha1',
            64 => 'sha256',
            128 => 'sha512',
            default => 'unknown'
        };
    }

    /**
     * Check for potential replay attacks using timestamps.
     */
    private function isReplayAttack(Request $request): bool
    {
        $timestampHeader = $request->header('x-timestamp') ?: $request->header('x-signature-timestamp');
        
        if (!$timestampHeader) {
            // No timestamp header - cannot detect replay, but log it
            SecurityAuditLog::logEvent(
                SecurityAuditLog::EVENT_SECURITY_VIOLATION,
                'Webhook request without timestamp header',
                SecurityAuditLog::SEVERITY_WARNING,
                [
                    'path' => $request->path(),
                    'ip' => $request->ip(),
                    'reason' => 'missing_timestamp'
                ]
            );
            return false; // Don't block requests without timestamps for now
        }

        $requestTime = (int) $timestampHeader;
        $currentTime = time();
        $skew = abs($currentTime - $requestTime);
        
        // Allow configurable time window (default 5 minutes)
        $maxSkew = Config::get('webhook_security.max_timestamp_skew', 300);
        
        if ($skew > $maxSkew) {
            SecurityAuditLog::logEvent(
                SecurityAuditLog::EVENT_SECURITY_VIOLATION,
                'Potential replay attack: timestamp outside allowed window',
                SecurityAuditLog::SEVERITY_CRITICAL,
                [
                    'path' => $request->path(),
                    'ip' => $request->ip(),
                    'timestamp_skew_seconds' => $skew,
                    'max_allowed_skew' => $maxSkew,
                    'request_timestamp' => $requestTime,
                    'server_timestamp' => $currentTime
                ]
            );
            return true;
        }

        return false;
    }

    /**
     * Calculate timestamp skew for logging.
     */
    private function calculateTimestampSkew(Request $request): int
    {
        $timestampHeader = $request->header('x-timestamp') ?: $request->header('x-signature-timestamp');
        
        if (!$timestampHeader) {
            return 0;
        }

        $requestTime = (int) $timestampHeader;
        $currentTime = time();
        
        return abs($currentTime - $requestTime);
    }

    /**
     * Identify webhook source based on headers and other indicators.
     */
    private function identifyWebhookSource(Request $request): string
    {
        // Check for common webhook source headers
        $sourceHeaders = [
            'x-webhook-source',
            'x-github-event',
            'x-gitlab-event',
            'x-stripe-signature',
            'x-hub-signature',
            'user-agent'
        ];

        foreach ($sourceHeaders as $header) {
            $value = $request->header($header);
            if ($value) {
                // Identify common webhook sources
                if (str_contains(strtolower($value), 'github')) return 'github';
                if (str_contains(strtolower($value), 'gitlab')) return 'gitlab';
                if (str_contains(strtolower($value), 'stripe')) return 'stripe';
                if (str_contains(strtolower($value), 'webhook')) return 'webhook';
                
                return strtolower($value);
            }
        }

        // Check request path for clues
        $path = $request->path();
        if (str_contains($path, 'github')) return 'github';
        if (str_contains($path, 'gitlab')) return 'gitlab';
        if (str_contains($path, 'stripe')) return 'stripe';
        
        return 'unknown';
    }

    /**
     * Handle validation failure and return appropriate response.
     */
    private function handleValidationFailure(Request $request, string $reason, string $message): Response
    {
        // Log security violation
        SecurityAuditLog::logSecurityViolation(
            "HMAC validation failed: {$message}",
            [
                'reason' => $reason,
                'path' => $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'payload_size' => strlen($request->getContent()),
                'headers' => $this->sanitizeHeaders($request->headers->all())
            ]
        );

        // Check if IP should be blocked due to repeated violations
        if (SecurityAuditLog::shouldBlockIp($request->ip())) {
            SecurityAuditLog::logEvent(
                SecurityAuditLog::EVENT_SECURITY_VIOLATION,
                'IP blocked due to repeated HMAC validation failures',
                SecurityAuditLog::SEVERITY_CRITICAL,
                [
                    'ip' => $request->ip(),
                    'path' => $request->path(),
                    'action' => 'ip_blocked'
                ]
            );
            
            return response()->json([
                'error' => 'Access denied',
                'message' => 'Too many authentication failures'
            ], ResponseCode::HTTP_TOO_MANY_REQUESTS);
        }

        // Return 401 Unauthorized for webhook validation failures
        return response()->json([
            'error' => 'Unauthorized',
            'message' => 'Invalid webhook signature'
        ], ResponseCode::HTTP_UNAUTHORIZED);
    }

    /**
     * Sanitize headers for logging (remove sensitive information).
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = [
            'authorization',
            'x-signature',
            'x-signature-256',
            'x-hub-signature',
            'x-hub-signature-256',
            'x-stripe-signature',
            'cookie',
            'set-cookie'
        ];

        $sanitized = [];
        foreach ($headers as $name => $values) {
            if (in_array(strtolower($name), $sensitiveHeaders)) {
                $sanitized[$name] = '[REDACTED]';
            } else {
                $sanitized[$name] = is_array($values) ? implode(', ', $values) : $values;
            }
        }

        return $sanitized;
    }
}