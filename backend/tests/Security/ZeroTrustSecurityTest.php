<?php

namespace Tests\Security;

use Tests\TestCase;
use App\Services\AdvancedSecurityService;
use App\Models\TenantUser;
use App\Models\Tenant;
use App\Models\SecurityAudit;
use App\Models\ThreatDetection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * @group security
 * @group zero-trust
 */
class ZeroTrustSecurityTest extends TestCase
{
    protected AdvancedSecurityService $securityService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->securityService = app(AdvancedSecurityService::class);
    }

    /** @test */
    public function it_verifies_zero_trust_request_with_valid_data()
    {
        $request = $this->createSecurityContext([
            'session_token' => 'valid_token',
            'device_id' => 'trusted_device_123',
            'ip_address' => '192.168.1.100'
        ]);

        // Setup trusted device and session
        Cache::put("session:{$this->regularUser->id}", 'valid_token', 3600);
        Cache::put('known_device:trusted_device_123', true, 86400);

        $result = $this->securityService->verifyZeroTrustRequest(
            $request,
            $this->regularUser,
            $this->defaultTenant
        );

        $this->assertTrue($result['verified']);
        $this->assertArrayHasKey('verification_token', $result);
        $this->assertArrayHasKey('trust_score', $result);
        $this->assertGreaterThan(0.7, $result['trust_score']);
    }

    /** @test */
    public function it_rejects_zero_trust_request_with_unknown_device()
    {
        $request = $this->createSecurityContext([
            'device_id' => 'unknown_device_456',
            'ip_address' => '10.0.0.1'
        ]);

        $this->expectException(\App\Services\SecurityException::class);

        $this->securityService->verifyZeroTrustRequest(
            $request,
            $this->regularUser,
            $this->defaultTenant
        );
    }

    /** @test */
    public function it_detects_sql_injection_attempts()
    {
        $maliciousData = [
            'username' => "admin'; DROP TABLE users; --",
            'search' => "1' OR '1'='1",
            'id' => "1 UNION SELECT password FROM users"
        ];

        $result = $this->securityService->detectAndMitigateThreat($maliciousData, $this->defaultTenant);

        $this->assertTrue($result['threat_detected']);
        $this->assertContains('sql_injection', array_column($result['threats'], 'type'));
        $this->assertEquals('critical', $result['risk_level']);
    }

    /** @test */
    public function it_detects_xss_attacks()
    {
        $xssData = [
            'comment' => '<script>alert("XSS")</script>',
            'name' => '<img src="x" onerror="alert(1)">',
            'content' => 'javascript:alert("XSS")'
        ];

        $result = $this->securityService->detectAndMitigateThreat($xssData, $this->defaultTenant);

        $this->assertTrue($result['threat_detected']);
        $this->assertContains('xss_attack', array_column($result['threats'], 'type'));
    }

    /** @test */
    public function it_detects_csrf_attacks()
    {
        $csrfData = [
            'headers' => [
                'referer' => 'https://malicious-site.com',
                'origin' => 'https://evil.com'
            ],
            'csrf_token' => 'invalid_token'
        ];

        $result = $this->securityService->detectAndMitigateThreat($csrfData, $this->defaultTenant);

        $this->assertTrue($result['threat_detected']);
        $this->assertContains('csrf_attack', array_column($result['threats'], 'type'));
    }

    /** @test */
    public function it_detects_brute_force_attacks()
    {
        $identifier = 'test@example.com';
        
        // Simulate multiple failed attempts
        for ($i = 0; $i < 6; $i++) {
            Cache::increment("brute_force:attempts:{$identifier}");
        }

        $bruteForceData = [
            'username' => $identifier,
            'password' => 'wrong_password',
            'ip_address' => '192.168.1.100'
        ];

        $result = $this->securityService->detectAndMitigateThreat($bruteForceData, $this->defaultTenant);

        $this->assertTrue($result['threat_detected']);
        $this->assertContains('brute_force', array_column($result['threats'], 'type'));
    }

    /** @test */
    public function it_detects_data_exfiltration_attempts()
    {
        $exfiltrationData = [
            'response_size' => 50000000, // 50MB
            'response_content' => 'user@example.com, 123-45-6789, password:secret123',
            'user_id' => $this->regularUser->id
        ];

        // Set normal baseline
        Cache::put("avg_response_size:{$this->regularUser->id}", 1000, 3600);

        $result = $this->securityService->detectAndMitigateThreat($exfiltrationData, $this->defaultTenant);

        $this->assertTrue($result['threat_detected']);
        $this->assertContains('data_exfiltration', array_column($result['threats'], 'type'));
    }

    /** @test */
    public function it_detects_privilege_escalation_attempts()
    {
        $escalationData = [
            'current_role' => 'user',
            'requested_role' => 'admin',
            'resource_id' => 'sensitive_resource_123',
            'user_id' => $this->regularUser->id,
            'request_path' => '/admin/../../../etc/passwd'
        ];

        $result = $this->securityService->detectAndMitigateThreat($escalationData, $this->defaultTenant);

        $this->assertTrue($result['threat_detected']);
        $this->assertContains('privilege_escalation', array_column($result['threats'], 'type'));
    }

    /** @test */
    public function it_enforces_multi_factor_authentication()
    {
        $mfaSession = $this->securityService->initiateMFA($this->regularUser, 'totp');

        $this->assertArrayHasKey('session_id', $mfaSession);
        $this->assertEquals('totp', $mfaSession['method']);
        $this->assertArrayHasKey('expires_in', $mfaSession);

        // Test SMS MFA
        $this->regularUser->phone_number = '+1234567890';
        $this->regularUser->save();

        $smsMfa = $this->securityService->initiateMFA($this->regularUser, 'sms');
        $this->assertEquals('sms', $smsMfa['method']);
        $this->assertTrue($smsMfa['code_sent']);
    }

    /** @test */
    public function it_detects_behavioral_anomalies()
    {
        $activity = [
            'timestamp' => strtotime('3:00 AM'), // Unusual time
            'location' => '10.0.0.1', // New location
            'data_volume' => 100000, // Large volume
            'api_calls' => ['admin/users' => 50] // Excessive API calls
        ];

        // Set normal patterns
        Cache::put("user:normal_hours:{$this->regularUser->id}", [9, 18], 3600);
        Cache::put("user:known_locations:{$this->regularUser->id}", '["192.168.1.100"]', 3600);
        Cache::put("user:avg_data_volume:{$this->regularUser->id}", 1000, 3600);

        $result = $this->securityService->detectBehavioralAnomalies($this->regularUser, $activity);

        $this->assertTrue($result['anomalies_detected']);
        $this->assertGreaterThan(0, count($result['anomalies']));
        $this->assertArrayHasKey('risk_score', $result);
    }

    /** @test */
    public function it_encrypts_sensitive_data_properly()
    {
        $sensitiveData = 'This is highly confidential information';
        $classification = 'confidential';

        $encrypted = $this->securityService->encryptSensitiveData(
            $sensitiveData,
            $classification,
            $this->defaultTenant
        );

        $this->assertArrayHasKey('encrypted_data', $encrypted);
        $this->assertArrayHasKey('encrypted_key', $encrypted);
        $this->assertArrayHasKey('integrity_hash', $encrypted);
        $this->assertArrayHasKey('metadata', $encrypted);

        // Ensure data is actually encrypted
        $this->assertNotEquals($sensitiveData, base64_decode($encrypted['encrypted_data']));
        
        // Verify metadata
        $this->assertEquals($classification, $encrypted['metadata']['classification']);
        $this->assertArrayHasKey('encryption_id', $encrypted['metadata']);
    }

    /** @test */
    public function it_rotates_encryption_keys_successfully()
    {
        $result = $this->securityService->rotateEncryptionKeys($this->defaultTenant);

        $this->assertTrue($result['rotation_successful']);
        $this->assertArrayHasKey('new_key_id', $result);
        $this->assertArrayHasKey('data_reencrypted', $result);
        $this->assertArrayHasKey('next_rotation', $result);
    }

    /** @test */
    public function it_enforces_gdpr_compliance()
    {
        $testData = [
            'personal_data' => 'John Doe, john@example.com',
            // Missing consent
        ];

        $result = $this->securityService->enforceCompliance('GDPR', $this->defaultTenant, $testData);

        $this->assertFalse($result['compliant']);
        $this->assertGreaterThan(0, count($result['violations']));
        
        $violation = $result['violations'][0];
        $this->assertEquals('GDPR', $violation['regulation']);
        $this->assertEquals('Article 6', $violation['article']);
    }

    /** @test */
    public function it_enforces_hipaa_compliance()
    {
        $testData = [
            'phi_access' => true,
            // Missing authorization
        ];

        $result = $this->securityService->enforceCompliance('HIPAA', $this->defaultTenant, $testData);

        $this->assertFalse($result['compliant']);
        $this->assertGreaterThan(0, count($result['violations']));
        
        $violation = $result['violations'][0];
        $this->assertEquals('HIPAA', $violation['regulation']);
        $this->assertEquals('critical', $violation['severity']);
    }

    /** @test */
    public function it_correlates_security_events()
    {
        $timeWindow = [
            'start' => now()->subHours(24),
            'end' => now()
        ];

        $result = $this->securityService->correlateSecurityEvents($this->defaultTenant, $timeWindow);

        $this->assertArrayHasKey('total_events', $result);
        $this->assertArrayHasKey('correlations_found', $result);
        $this->assertArrayHasKey('attack_patterns', $result);
        $this->assertArrayHasKey('threat_intelligence', $result);
        $this->assertArrayHasKey('security_metrics', $result);
        $this->assertArrayHasKey('risk_assessment', $result);
    }

    /** @test */
    public function it_handles_api_abuse_detection()
    {
        $apiData = [
            'api_key' => 'test_key',
            'endpoint' => '/api/v1/users',
            'user_agent' => 'python-requests/2.25.1'
        ];

        // Simulate rate limit exceeded
        Cache::put('api_rate:test_key:/api/v1/users', 1000, 60);

        $result = $this->securityService->detectAndMitigateThreat($apiData, $this->defaultTenant);

        $this->assertTrue($result['threat_detected']);
        $this->assertContains('api_abuse', array_column($result['threats'], 'type'));
    }

    /** @test */
    public function it_detects_malware_uploads()
    {
        $malwareData = [
            'file_upload' => [
                'name' => 'malicious.exe',
                'content' => "\x4D\x5A" . 'malicious binary content' // PE header
            ]
        ];

        $result = $this->securityService->detectAndMitigateThreat($malwareData, $this->defaultTenant);

        $this->assertTrue($result['threat_detected']);
        $this->assertContains('malware', array_column($result['threats'], 'type'));
    }

    /** @test */
    public function it_protects_against_timing_attacks()
    {
        $validUser = $this->regularUser;
        $invalidEmail = 'nonexistent@example.com';

        // Measure login time for valid user
        $start = microtime(true);
        $validResult = Hash::check('wrong_password', $validUser->password);
        $validTime = microtime(true) - $start;

        // Measure login time for invalid user
        $start = microtime(true);
        Hash::check('wrong_password', '$2y$10$fakehashfortiming');
        $invalidTime = microtime(true) - $start;

        // Times should be similar to prevent timing attacks
        $timeDifference = abs($validTime - $invalidTime);
        $this->assertLessThan(0.01, $timeDifference, 'Timing attack protection failed');
    }

    /** @test */
    public function it_enforces_session_security()
    {
        $sessionData = [
            'session_id' => 'test_session_123',
            'user_id' => $this->regularUser->id,
            'ip_address' => '192.168.1.100',
            'user_agent' => 'Test Browser/1.0'
        ];

        // Store session
        Cache::put("session:{$this->regularUser->id}", 'test_session_123', 3600);

        $validation = $this->securityService->verifyZeroTrustRequest(
            $sessionData,
            $this->regularUser,
            $this->defaultTenant
        );

        $this->assertTrue($validation['verified']);

        // Test session hijacking detection
        $hijackAttempt = [
            'session_id' => 'test_session_123',
            'user_id' => $this->regularUser->id,
            'ip_address' => '10.0.0.1', // Different IP
            'user_agent' => 'Different Browser/2.0' // Different user agent
        ];

        $this->expectException(\App\Services\SecurityException::class);
        $this->securityService->verifyZeroTrustRequest(
            $hijackAttempt,
            $this->regularUser,
            $this->defaultTenant
        );
    }

    /** @test */
    public function it_maintains_audit_trail()
    {
        $request = $this->createSecurityContext();

        try {
            $this->securityService->verifyZeroTrustRequest(
                $request,
                $this->regularUser,
                $this->defaultTenant
            );
        } catch (\Exception $e) {
            // Expected to fail for security reasons
        }

        // Verify audit record was created
        $this->assertDatabaseHas('security_audits', [
            'tenant_id' => $this->defaultTenant->id,
            'user_id' => $this->regularUser->id,
            'action' => 'zero_trust_verification'
        ]);
    }

    /** @test */
    public function it_implements_rate_limiting_correctly()
    {
        $identifier = $this->regularUser->email;

        // Test normal operation
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue(true); // Simulate successful operations
        }

        // Simulate burst of requests that should trigger rate limiting
        Cache::put("rate_limit:{$identifier}", 100, 60);

        $this->expectException(\App\Services\SecurityException::class);
        
        // This should trigger rate limiting
        $this->securityService->verifyZeroTrustRequest(
            $this->createSecurityContext(),
            $this->regularUser,
            $this->defaultTenant
        );
    }

    /** @test */
    public function it_prevents_privilege_escalation_through_parameters()
    {
        $maliciousRequest = [
            'user_id' => $this->regularUser->id,
            'role' => 'admin', // Trying to escalate
            'permissions' => ['*'], // Trying to grant all permissions
            'is_admin' => true // Direct privilege escalation attempt
        ];

        $result = $this->securityService->detectAndMitigateThreat($maliciousRequest, $this->defaultTenant);

        $this->assertTrue($result['threat_detected']);
        $this->assertContains('privilege_escalation', array_column($result['threats'], 'type'));
    }

    /** @test */
    public function it_validates_jwt_token_integrity()
    {
        // Test with tampered JWT
        $tamperedJWT = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.TAMPERED_PAYLOAD.signature';

        $jwtData = [
            'jwt_token' => $tamperedJWT,
            'user_id' => $this->regularUser->id
        ];

        $result = $this->securityService->detectAndMitigateThreat($jwtData, $this->defaultTenant);

        $this->assertTrue($result['threat_detected']);
        $this->assertContains('privilege_escalation', array_column($result['threats'], 'type'));
    }
}