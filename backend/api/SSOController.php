<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SSOIntegrationService;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class SSOController extends Controller
{
    protected $ssoService;
    protected $tenantService;

    public function __construct(SSOIntegrationService $ssoService, TenantService $tenantService)
    {
        $this->ssoService = $ssoService;
        $this->tenantService = $tenantService;
    }

    /**
     * Get SSO configuration for a tenant
     */
    public function getConfiguration(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tenant_id' => 'required|string|exists:tenants,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 400);
        }

        try {
            $tenant = $this->tenantService->getTenant($request->tenant_id);
            
            $config = [
                'enabled' => $tenant->sso_enabled,
                'providers' => [],
            ];

            if ($tenant->sso_enabled) {
                if ($tenant->tier === 'enterprise' || in_array('sso', $tenant->config['features'] ?? [])) {
                    $config['providers'] = [
                        'saml' => [
                            'enabled' => true,
                            'login_url' => url("/sso/saml/login?tenant={$tenant->id}"),
                            'metadata_url' => url("/sso/saml/metadata?tenant={$tenant->id}"),
                        ],
                        'oidc' => [
                            'enabled' => true,
                            'login_url' => url("/sso/oidc/login?tenant={$tenant->id}"),
                            'providers' => ['azure', 'google', 'okta'],
                        ],
                    ];
                }
            }

            return response()->json([
                'status' => 'success',
                'data' => $config,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get SSO configuration', [
                'tenant_id' => $request->tenant_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to get SSO configuration',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Initiate SSO login
     */
    public function initiateLogin(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tenant_id' => 'required|string|exists:tenants,id',
            'provider' => 'required|string|in:saml,oidc',
            'oidc_provider' => 'nullable|string|in:azure,google,okta',
            'return_url' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 400);
        }

        try {
            $provider = $request->provider;
            if ($provider === 'oidc' && $request->oidc_provider) {
                $provider = $request->oidc_provider;
            }

            $result = $this->ssoService->initiateAuthentication(
                $request->tenant_id,
                $request->provider,
                $request->return_url
            );

            // Set provider config if OIDC
            if ($request->provider === 'oidc' && $request->oidc_provider) {
                $result['oidc_provider'] = $request->oidc_provider;
            }

            return response()->json([
                'status' => 'success',
                'data' => $result,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to initiate SSO login', [
                'tenant_id' => $request->tenant_id,
                'provider' => $request->provider,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to initiate SSO login',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle SAML callback
     */
    public function handleSAMLCallback(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tenant' => 'required|string|exists:tenants,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Invalid tenant parameter',
                'details' => $validator->errors(),
            ], 400);
        }

        try {
            $result = $this->ssoService->processCallback(
                $request,
                $request->tenant,
                'saml'
            );

            // Generate session token for the authenticated user
            $token = $this->generateSessionToken($result['user']);

            $response = [
                'status' => 'success',
                'data' => [
                    'user' => [
                        'id' => $result['user']->id,
                        'email' => $result['user']->email,
                        'name' => $result['user']->name,
                        'role' => $result['user']->role,
                        'tenant_id' => $result['user']->tenant_id,
                    ],
                    'token' => $token,
                    'provider' => 'saml',
                ],
            ];

            // Include return URL if present
            if ($result['relay_state'] && isset($result['relay_state']['return_url'])) {
                $response['data']['return_url'] = $result['relay_state']['return_url'];
            }

            return response()->json($response);

        } catch (Exception $e) {
            Log::error('Failed to process SAML callback', [
                'tenant_id' => $request->tenant,
                'error' => $e->getMessage(),
                'request_data' => $request->except(['SAMLResponse']), // Don't log sensitive data
            ]);

            return response()->json([
                'error' => 'SAML authentication failed',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Handle OIDC callback
     */
    public function handleOIDCCallback(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tenant' => 'required|string|exists:tenants,id',
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Invalid callback parameters',
                'details' => $validator->errors(),
            ], 400);
        }

        try {
            $result = $this->ssoService->processCallback(
                $request,
                $request->tenant,
                'oidc'
            );

            // Generate session token for the authenticated user
            $token = $this->generateSessionToken($result['user']);

            $response = [
                'status' => 'success',
                'data' => [
                    'user' => [
                        'id' => $result['user']->id,
                        'email' => $result['user']->email,
                        'name' => $result['user']->name,
                        'role' => $result['user']->role,
                        'tenant_id' => $result['user']->tenant_id,
                    ],
                    'token' => $token,
                    'provider' => 'oidc',
                ],
            ];

            // Include return URL if present
            if ($result['return_url']) {
                $response['data']['return_url'] = $result['return_url'];
            }

            return response()->json($response);

        } catch (Exception $e) {
            Log::error('Failed to process OIDC callback', [
                'tenant_id' => $request->tenant,
                'error' => $e->getMessage(),
                'state' => $request->state,
            ]);

            return response()->json([
                'error' => 'OIDC authentication failed',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get SAML metadata for a tenant
     */
    public function getSAMLMetadata(Request $request): Response
    {
        $validator = Validator::make($request->all(), [
            'tenant' => 'required|string|exists:tenants,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Invalid tenant parameter',
            ], 400);
        }

        try {
            $tenant = $this->tenantService->getTenant($request->tenant);
            $metadata = $this->generateSAMLMetadata($tenant);

            return response($metadata)
                ->header('Content-Type', 'application/xml')
                ->header('Content-Disposition', 'inline; filename="metadata.xml"');

        } catch (Exception $e) {
            Log::error('Failed to generate SAML metadata', [
                'tenant_id' => $request->tenant,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to generate SAML metadata',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Initiate SSO logout
     */
    public function initiateLogout(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tenant_id' => 'required|string|exists:tenants,id',
            'user_id' => 'required|string|exists:tenant_users,id',
            'return_url' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 400);
        }

        try {
            $user = $this->tenantService->getTenantUser($request->tenant_id, $request->user_id);
            
            $result = $this->ssoService->logout($user, $request->return_url);

            if (!$result) {
                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'type' => 'local',
                        'message' => 'User logged out locally',
                    ],
                ]);
            }

            return response()->json([
                'status' => 'success',
                'data' => $result,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to initiate SSO logout', [
                'tenant_id' => $request->tenant_id,
                'user_id' => $request->user_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to initiate SSO logout',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test SSO configuration
     */
    public function testConfiguration(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tenant_id' => 'required|string|exists:tenants,id',
            'provider' => 'required|string|in:saml,oidc',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 400);
        }

        try {
            $tenant = $this->tenantService->getTenant($request->tenant_id);
            $provider = $request->provider;
            
            $tests = [];

            if ($provider === 'saml') {
                $tests = $this->testSAMLConfiguration($tenant);
            } elseif ($provider === 'oidc') {
                $tests = $this->testOIDCConfiguration($tenant);
            }

            $allPassed = collect($tests)->every(fn($test) => $test['status'] === 'passed');

            return response()->json([
                'status' => 'success',
                'data' => [
                    'overall_status' => $allPassed ? 'passed' : 'failed',
                    'tests' => $tests,
                ],
            ]);

        } catch (Exception $e) {
            Log::error('Failed to test SSO configuration', [
                'tenant_id' => $request->tenant_id,
                'provider' => $request->provider,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to test SSO configuration',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate session token for authenticated user
     */
    protected function generateSessionToken($user): string
    {
        // This is a simplified token generation
        // In production, use proper JWT or session management
        return base64_encode(json_encode([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'expires_at' => time() + 3600, // 1 hour
            'signature' => hash_hmac('sha256', $user->id . $user->tenant_id, config('app.key')),
        ]));
    }

    /**
     * Generate SAML metadata
     */
    protected function generateSAMLMetadata($tenant): string
    {
        $config = config('integrations.sso.saml');
        $entityId = $config['sp_entity_id'];
        $acsUrl = url($config['sp_acs_url'] . "?tenant={$tenant->id}");
        $slsUrl = url($config['sp_sls_url'] . "?tenant={$tenant->id}");

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
<md:EntityDescriptor xmlns:md=\"urn:oasis:names:tc:SAML:2.0:metadata\" 
                     entityID=\"{$entityId}\">
    <md:SPSSODescriptor protocolSupportEnumeration=\"urn:oasis:names:tc:SAML:2.0:protocol\">
        <md:NameIDFormat>urn:oasis:names:tc:SAML:2.0:nameid-format:emailAddress</md:NameIDFormat>
        <md:AssertionConsumerService 
            Binding=\"urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST\" 
            Location=\"{$acsUrl}\" 
            index=\"1\" />
        <md:SingleLogoutService 
            Binding=\"urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect\" 
            Location=\"{$slsUrl}\" />
    </md:SPSSODescriptor>
</md:EntityDescriptor>";
    }

    /**
     * Test SAML configuration
     */
    protected function testSAMLConfiguration($tenant): array
    {
        $config = config('integrations.sso.saml');
        $tests = [];

        // Test 1: Check required configuration
        $tests[] = [
            'name' => 'SAML Configuration Check',
            'status' => (isset($config['idp_metadata_url']) && isset($config['x509_cert'])) ? 'passed' : 'failed',
            'message' => 'Required SAML configuration parameters',
        ];

        // Test 2: Check IDP metadata accessibility
        try {
            $response = Http::timeout(10)->get($config['idp_metadata_url']);
            $tests[] = [
                'name' => 'IDP Metadata Accessibility',
                'status' => $response->successful() ? 'passed' : 'failed',
                'message' => $response->successful() ? 'IDP metadata accessible' : 'Failed to fetch IDP metadata',
            ];
        } catch (Exception $e) {
            $tests[] = [
                'name' => 'IDP Metadata Accessibility',
                'status' => 'failed',
                'message' => 'Exception: ' . $e->getMessage(),
            ];
        }

        // Test 3: Certificate validation
        $tests[] = [
            'name' => 'Certificate Validation',
            'status' => $this->validateX509Certificate($config['x509_cert']) ? 'passed' : 'failed',
            'message' => 'X.509 certificate format validation',
        ];

        return $tests;
    }

    /**
     * Test OIDC configuration
     */
    protected function testOIDCConfiguration($tenant): array
    {
        $providers = config('integrations.sso.oidc.providers');
        $tests = [];

        foreach ($providers as $providerName => $config) {
            if (!isset($config['client_id']) || !isset($config['client_secret'])) {
                $tests[] = [
                    'name' => "OIDC {$providerName} Configuration",
                    'status' => 'failed',
                    'message' => 'Missing client_id or client_secret',
                ];
                continue;
            }

            // Test discovery document
            try {
                $discoveryUrl = str_replace('{tenant_id}', $config['tenant_id'] ?? '', $config['discovery_url']);
                $response = Http::timeout(10)->get($discoveryUrl);
                
                $tests[] = [
                    'name' => "OIDC {$providerName} Discovery",
                    'status' => $response->successful() ? 'passed' : 'failed',
                    'message' => $response->successful() ? 'Discovery document accessible' : 'Failed to fetch discovery document',
                ];
            } catch (Exception $e) {
                $tests[] = [
                    'name' => "OIDC {$providerName} Discovery",
                    'status' => 'failed',
                    'message' => 'Exception: ' . $e->getMessage(),
                ];
            }
        }

        return $tests;
    }

    /**
     * Validate X.509 certificate format
     */
    protected function validateX509Certificate($cert): bool
    {
        if (empty($cert)) {
            return false;
        }

        $cert = trim($cert);
        
        // Check if it's a valid certificate format
        return strpos($cert, '-----BEGIN CERTIFICATE-----') !== false &&
               strpos($cert, '-----END CERTIFICATE-----') !== false;
    }
}