<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use LightSaml\Model\Protocol\AuthnRequest;
use LightSaml\Model\Protocol\Response as SamlResponse;
use LightSaml\Context\Profile\MessageContext;
use LightSaml\Credential\KeyHelper;
use LightSaml\Credential\X509Certificate;
use LightSaml\Model\Assertion\Assertion;
use LightSaml\Model\Assertion\AttributeStatement;
use Carbon\Carbon;
use Exception;

class SSOIntegrationService
{
    protected $config;
    protected $tenantService;
    protected $auditService;

    public function __construct(TenantService $tenantService, SecurityAuditService $auditService)
    {
        $this->config = Config::get('integrations.sso');
        $this->tenantService = $tenantService;
        $this->auditService = $auditService;
    }

    /**
     * Initiate SSO authentication for a tenant
     */
    public function initiateAuthentication(string $tenantId, string $provider, string $returnUrl = null): array
    {
        $tenant = $this->tenantService->getTenant($tenantId);
        
        if (!$this->isSSOEnabledForTenant($tenant)) {
            throw new Exception('SSO is not enabled for this tenant');
        }

        $ssoConfig = $this->getTenantSSOConfig($tenant, $provider);
        
        switch ($provider) {
            case 'saml':
                return $this->initiateSAMLAuth($tenant, $ssoConfig, $returnUrl);
            case 'oidc':
                return $this->initiateOIDCAuth($tenant, $ssoConfig, $returnUrl);
            default:
                throw new Exception("Unsupported SSO provider: {$provider}");
        }
    }

    /**
     * Process SSO callback and authenticate user
     */
    public function processCallback(Request $request, string $tenantId, string $provider): array
    {
        $tenant = $this->tenantService->getTenant($tenantId);
        
        try {
            switch ($provider) {
                case 'saml':
                    return $this->processSAMLCallback($request, $tenant);
                case 'oidc':
                    return $this->processOIDCCallback($request, $tenant);
                default:
                    throw new Exception("Unsupported SSO provider: {$provider}");
            }
        } catch (Exception $e) {
            $this->auditService->logSecurityEvent([
                'tenant_id' => $tenantId,
                'event_type' => 'sso_authentication_failed',
                'provider' => $provider,
                'error' => $e->getMessage(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Initiate SAML authentication
     */
    protected function initiateSAMLAuth(Tenant $tenant, array $config, ?string $returnUrl): array
    {
        $authnRequest = new AuthnRequest();
        $authnRequest->setID($this->generateRequestId());
        $authnRequest->setIssueInstant(new \DateTime());
        $authnRequest->setDestination($config['idp_sso_url']);
        $authnRequest->setIssuer($config['sp_entity_id']);
        $authnRequest->setNameIDPolicy([
            'Format' => $config['name_id_format'],
            'AllowCreate' => true,
        ]);

        // Store request state
        $state = [
            'tenant_id' => $tenant->id,
            'request_id' => $authnRequest->getID(),
            'return_url' => $returnUrl,
            'created_at' => time(),
        ];
        
        Cache::put("saml_state_{$authnRequest->getID()}", $state, 1800); // 30 minutes

        // Build redirect URL
        $samlRequest = base64_encode(gzdeflate($authnRequest->serialize()));
        $redirectUrl = $config['idp_sso_url'] . '?' . http_build_query([
            'SAMLRequest' => $samlRequest,
            'RelayState' => base64_encode(json_encode(['tenant_id' => $tenant->id, 'return_url' => $returnUrl])),
        ]);

        Log::info("SAML authentication initiated", [
            'tenant_id' => $tenant->id,
            'request_id' => $authnRequest->getID(),
            'idp_url' => $config['idp_sso_url'],
        ]);

        return [
            'redirect_url' => $redirectUrl,
            'request_id' => $authnRequest->getID(),
            'state' => $state,
        ];
    }

    /**
     * Process SAML callback
     */
    protected function processSAMLCallback(Request $request, Tenant $tenant): array
    {
        $samlResponse = $request->input('SAMLResponse');
        $relayState = $request->input('RelayState');
        
        if (!$samlResponse) {
            throw new Exception('Missing SAMLResponse parameter');
        }

        // Decode and parse SAML response
        $decodedResponse = base64_decode($samlResponse);
        $responseObj = SamlResponse::fromXML($decodedResponse);
        
        // Validate response
        $this->validateSAMLResponse($responseObj, $tenant);
        
        // Extract user attributes
        $userAttributes = $this->extractSAMLAttributes($responseObj);
        
        // Create or update user
        $user = $this->provisionUser($tenant, $userAttributes, 'saml');
        
        $this->auditService->logSecurityEvent([
            'tenant_id' => $tenant->id,
            'event_type' => 'sso_authentication_success',
            'provider' => 'saml',
            'user_id' => $user->id,
            'user_email' => $userAttributes['email'] ?? null,
            'ip_address' => $request->ip(),
        ]);

        return [
            'user' => $user,
            'attributes' => $userAttributes,
            'relay_state' => $relayState ? json_decode(base64_decode($relayState), true) : null,
        ];
    }

    /**
     * Initiate OIDC authentication
     */
    protected function initiateOIDCAuth(Tenant $tenant, array $config, ?string $returnUrl): array
    {
        $provider = $config['provider'] ?? 'azure';
        $providerConfig = $this->config['oidc']['providers'][$provider];
        
        // Generate state and nonce
        $state = $this->generateState($tenant->id, $returnUrl);
        $nonce = $this->generateNonce();
        
        // Store state
        Cache::put("oidc_state_{$state}", [
            'tenant_id' => $tenant->id,
            'provider' => $provider,
            'nonce' => $nonce,
            'return_url' => $returnUrl,
            'created_at' => time(),
        ], 1800); // 30 minutes

        // Get authorization endpoint from discovery
        $discoveryUrl = str_replace('{tenant_id}', $providerConfig['tenant_id'] ?? '', $providerConfig['discovery_url']);
        $discoveryDoc = $this->getOIDCDiscoveryDocument($discoveryUrl);
        
        // Build authorization URL
        $params = [
            'response_type' => 'code',
            'client_id' => $providerConfig['client_id'],
            'redirect_uri' => $this->buildRedirectUri($tenant, 'oidc'),
            'scope' => implode(' ', $providerConfig['scope']),
            'state' => $state,
            'nonce' => $nonce,
            'response_mode' => 'query',
        ];

        $authUrl = $discoveryDoc['authorization_endpoint'] . '?' . http_build_query($params);

        Log::info("OIDC authentication initiated", [
            'tenant_id' => $tenant->id,
            'provider' => $provider,
            'state' => $state,
            'auth_endpoint' => $discoveryDoc['authorization_endpoint'],
        ]);

        return [
            'redirect_url' => $authUrl,
            'state' => $state,
            'nonce' => $nonce,
            'provider' => $provider,
        ];
    }

    /**
     * Process OIDC callback
     */
    protected function processOIDCCallback(Request $request, Tenant $tenant): array
    {
        $code = $request->input('code');
        $state = $request->input('state');
        $error = $request->input('error');
        
        if ($error) {
            throw new Exception("OIDC authentication error: {$error}");
        }

        if (!$code || !$state) {
            throw new Exception('Missing required OIDC parameters');
        }

        // Validate state
        $stateData = Cache::get("oidc_state_{$state}");
        if (!$stateData || $stateData['tenant_id'] !== $tenant->id) {
            throw new Exception('Invalid or expired OIDC state');
        }

        Cache::forget("oidc_state_{$state}");

        $provider = $stateData['provider'];
        $providerConfig = $this->config['oidc']['providers'][$provider];

        // Get discovery document
        $discoveryUrl = str_replace('{tenant_id}', $providerConfig['tenant_id'] ?? '', $providerConfig['discovery_url']);
        $discoveryDoc = $this->getOIDCDiscoveryDocument($discoveryUrl);

        // Exchange code for tokens
        $tokenResponse = Http::post($discoveryDoc['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'client_id' => $providerConfig['client_id'],
            'client_secret' => $providerConfig['client_secret'],
            'code' => $code,
            'redirect_uri' => $this->buildRedirectUri($tenant, 'oidc'),
        ]);

        if (!$tokenResponse->successful()) {
            throw new Exception('Failed to exchange authorization code for tokens');
        }

        $tokens = $tokenResponse->json();
        
        // Validate and decode ID token
        $idToken = $tokens['id_token'];
        $userInfo = $this->validateAndDecodeIdToken($idToken, $discoveryDoc, $providerConfig, $stateData['nonce']);
        
        // Map claims to user attributes
        $userAttributes = $this->mapOIDCClaims($userInfo, $providerConfig['claims_mapping']);
        
        // Create or update user
        $user = $this->provisionUser($tenant, $userAttributes, 'oidc');
        
        $this->auditService->logSecurityEvent([
            'tenant_id' => $tenant->id,
            'event_type' => 'sso_authentication_success',
            'provider' => 'oidc',
            'oidc_provider' => $provider,
            'user_id' => $user->id,
            'user_email' => $userAttributes['email'] ?? null,
            'ip_address' => $request->ip(),
        ]);

        return [
            'user' => $user,
            'attributes' => $userAttributes,
            'tokens' => [
                'access_token' => $tokens['access_token'] ?? null,
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'expires_in' => $tokens['expires_in'] ?? null,
            ],
            'return_url' => $stateData['return_url'],
        ];
    }

    /**
     * Validate SAML response
     */
    protected function validateSAMLResponse(SamlResponse $response, Tenant $tenant): void
    {
        $config = $this->getTenantSSOConfig($tenant, 'saml');
        
        // Validate signature if present
        if ($response->getSignature()) {
            $certificate = new X509Certificate();
            $certificate->loadFromString($config['x509_cert']);
            
            if (!$response->getSignature()->validate($certificate)) {
                throw new Exception('Invalid SAML response signature');
            }
        }

        // Validate destination
        if ($response->getDestination() !== $config['sp_acs_url']) {
            throw new Exception('Invalid SAML response destination');
        }

        // Validate issuer
        if ($response->getIssuer()->getValue() !== $config['idp_entity_id']) {
            throw new Exception('Invalid SAML response issuer');
        }

        // Validate status
        $status = $response->getStatus();
        if ($status->getStatusCode()->getValue() !== 'urn:oasis:names:tc:SAML:2.0:status:Success') {
            throw new Exception('SAML authentication failed: ' . $status->getStatusCode()->getValue());
        }

        // Validate assertion
        $assertions = $response->getAllAssertions();
        if (empty($assertions)) {
            throw new Exception('No assertions found in SAML response');
        }

        foreach ($assertions as $assertion) {
            $this->validateSAMLAssertion($assertion, $config);
        }
    }

    /**
     * Validate SAML assertion
     */
    protected function validateSAMLAssertion(Assertion $assertion, array $config): void
    {
        // Validate not before and not on or after
        $conditions = $assertion->getConditions();
        if ($conditions) {
            $now = new \DateTime();
            
            if ($conditions->getNotBefore() && $conditions->getNotBefore() > $now) {
                throw new Exception('SAML assertion not yet valid');
            }
            
            if ($conditions->getNotOnOrAfter() && $conditions->getNotOnOrAfter() <= $now) {
                throw new Exception('SAML assertion has expired');
            }
        }

        // Validate audience
        $audienceRestrictions = $assertion->getConditions()->getAllAudienceRestrictions();
        foreach ($audienceRestrictions as $audienceRestriction) {
            $audiences = $audienceRestriction->getAllAudiences();
            $validAudience = false;
            
            foreach ($audiences as $audience) {
                if ($audience->getValue() === $config['sp_entity_id']) {
                    $validAudience = true;
                    break;
                }
            }
            
            if (!$validAudience) {
                throw new Exception('Invalid SAML assertion audience');
            }
        }
    }

    /**
     * Extract attributes from SAML response
     */
    protected function extractSAMLAttributes(SamlResponse $response): array
    {
        $attributes = [];
        $assertions = $response->getAllAssertions();
        
        foreach ($assertions as $assertion) {
            $attributeStatements = $assertion->getAllAttributeStatements();
            
            foreach ($attributeStatements as $attributeStatement) {
                $samlAttributes = $attributeStatement->getAllAttributes();
                
                foreach ($samlAttributes as $attribute) {
                    $name = $attribute->getName();
                    $values = [];
                    
                    foreach ($attribute->getAllAttributeValues() as $value) {
                        $values[] = $value->getValue();
                    }
                    
                    $attributes[$name] = count($values) === 1 ? $values[0] : $values;
                }
            }

            // Extract NameID
            $nameId = $assertion->getSubject()->getNameID();
            if ($nameId) {
                $attributes['name_id'] = $nameId->getValue();
                $attributes['name_id_format'] = $nameId->getFormat();
            }
        }

        // Map SAML attributes to user attributes
        return $this->mapSAMLAttributes($attributes);
    }

    /**
     * Map SAML attributes to user attributes
     */
    protected function mapSAMLAttributes(array $samlAttributes): array
    {
        $mapping = $this->config['saml']['attribute_mapping'];
        $userAttributes = [];

        foreach ($mapping as $userField => $samlField) {
            if (isset($samlAttributes[$samlField])) {
                $userAttributes[$userField] = $samlAttributes[$samlField];
            }
        }

        // Ensure email is present
        if (!isset($userAttributes['email']) && isset($samlAttributes['name_id'])) {
            if (filter_var($samlAttributes['name_id'], FILTER_VALIDATE_EMAIL)) {
                $userAttributes['email'] = $samlAttributes['name_id'];
            }
        }

        return $userAttributes;
    }

    /**
     * Get OIDC discovery document
     */
    protected function getOIDCDiscoveryDocument(string $discoveryUrl): array
    {
        $cacheKey = "oidc_discovery_" . md5($discoveryUrl);
        
        return Cache::remember($cacheKey, 3600, function () use ($discoveryUrl) {
            $response = Http::timeout(10)->get($discoveryUrl);
            
            if (!$response->successful()) {
                throw new Exception("Failed to fetch OIDC discovery document from {$discoveryUrl}");
            }
            
            return $response->json();
        });
    }

    /**
     * Validate and decode ID token
     */
    protected function validateAndDecodeIdToken(string $idToken, array $discoveryDoc, array $providerConfig, string $expectedNonce): array
    {
        // Get JWKs
        $jwks = $this->getJWKS($discoveryDoc['jwks_uri']);
        
        // Decode token header to get key ID
        $header = json_decode(base64_decode(explode('.', $idToken)[0]), true);
        $kid = $header['kid'] ?? null;
        
        // Find matching key
        $key = null;
        foreach ($jwks['keys'] as $jwk) {
            if ($jwk['kid'] === $kid) {
                $key = $this->jwkToKey($jwk);
                break;
            }
        }
        
        if (!$key) {
            throw new Exception('Unable to find matching key for ID token validation');
        }

        // Decode and validate token
        try {
            $payload = JWT::decode($idToken, new Key($key, $header['alg']));
        } catch (Exception $e) {
            throw new Exception('Invalid ID token: ' . $e->getMessage());
        }

        // Convert to array
        $claims = json_decode(json_encode($payload), true);

        // Validate claims
        $this->validateOIDCClaims($claims, $providerConfig, $expectedNonce);

        return $claims;
    }

    /**
     * Validate OIDC claims
     */
    protected function validateOIDCClaims(array $claims, array $providerConfig, string $expectedNonce): void
    {
        $now = time();

        // Validate expiration
        if (!isset($claims['exp']) || $claims['exp'] <= $now) {
            throw new Exception('ID token has expired');
        }

        // Validate issued at
        if (isset($claims['iat']) && $claims['iat'] > $now + 300) { // 5 minute clock skew tolerance
            throw new Exception('ID token issued in the future');
        }

        // Validate audience
        if (!isset($claims['aud']) || $claims['aud'] !== $providerConfig['client_id']) {
            throw new Exception('Invalid ID token audience');
        }

        // Validate nonce
        if (isset($claims['nonce']) && $claims['nonce'] !== $expectedNonce) {
            throw new Exception('Invalid ID token nonce');
        }
    }

    /**
     * Map OIDC claims to user attributes
     */
    protected function mapOIDCClaims(array $claims, array $mapping): array
    {
        $userAttributes = [];

        foreach ($mapping as $userField => $claimName) {
            if (isset($claims[$claimName])) {
                $userAttributes[$userField] = $claims[$claimName];
            }
        }

        return $userAttributes;
    }

    /**
     * Provision user from SSO attributes
     */
    protected function provisionUser(Tenant $tenant, array $attributes, string $provider): TenantUser
    {
        if (!isset($attributes['email'])) {
            throw new Exception('Email address is required for user provisioning');
        }

        $email = $attributes['email'];
        
        // Check if user already exists
        $user = TenantUser::where('tenant_id', $tenant->id)
            ->where('email', $email)
            ->first();

        if ($user) {
            // Update existing user
            $user->update([
                'name' => $attributes['name'] ?? $user->name,
                'first_name' => $attributes['first_name'] ?? $user->first_name,
                'last_name' => $attributes['last_name'] ?? $user->last_name,
                'department' => $attributes['department'] ?? $user->department,
                'last_login_at' => Carbon::now(),
                'sso_provider' => $provider,
                'sso_provider_id' => $attributes['name_id'] ?? $attributes['sub'] ?? null,
            ]);
        } else {
            // Create new user if auto-provisioning is enabled
            $autoProvision = $this->config[$provider]['auto_provision'] ?? false;
            
            if (!$autoProvision) {
                throw new Exception('User not found and auto-provisioning is disabled');
            }

            $user = TenantUser::create([
                'tenant_id' => $tenant->id,
                'email' => $email,
                'name' => $attributes['name'] ?? $email,
                'first_name' => $attributes['first_name'] ?? null,
                'last_name' => $attributes['last_name'] ?? null,
                'department' => $attributes['department'] ?? null,
                'role' => $this->determineUserRole($attributes, $provider),
                'status' => 'active',
                'email_verified_at' => Carbon::now(),
                'last_login_at' => Carbon::now(),
                'sso_provider' => $provider,
                'sso_provider_id' => $attributes['name_id'] ?? $attributes['sub'] ?? null,
            ]);
        }

        return $user;
    }

    /**
     * Determine user role from SSO attributes
     */
    protected function determineUserRole(array $attributes, string $provider): string
    {
        $defaultRole = $this->config[$provider]['default_role'] ?? 'user';
        
        // Check for role mapping in attributes
        if (isset($attributes['role'])) {
            $role = $attributes['role'];
            
            // Handle array of roles - take the first one or implement custom logic
            if (is_array($role)) {
                $role = $role[0] ?? $defaultRole;
            }
            
            // Map external roles to internal roles
            $roleMapping = [
                'admin' => 'admin',
                'administrator' => 'admin',
                'manager' => 'manager',
                'user' => 'user',
                'viewer' => 'viewer',
            ];
            
            return $roleMapping[strtolower($role)] ?? $defaultRole;
        }

        return $defaultRole;
    }

    /**
     * Check if SSO is enabled for tenant
     */
    protected function isSSOEnabledForTenant(Tenant $tenant): bool
    {
        return $tenant->sso_enabled && 
               ($tenant->tier === 'enterprise' || 
                in_array('sso', $tenant->config['features'] ?? []));
    }

    /**
     * Get tenant-specific SSO configuration
     */
    protected function getTenantSSOConfig(Tenant $tenant, string $provider): array
    {
        $globalConfig = $this->config[$provider];
        $tenantConfig = $tenant->config['sso'][$provider] ?? [];
        
        return array_merge($globalConfig, $tenantConfig);
    }

    /**
     * Helper methods
     */
    protected function generateRequestId(): string
    {
        return 'genesis_' . bin2hex(random_bytes(16));
    }

    protected function generateState(string $tenantId, ?string $returnUrl): string
    {
        return base64_encode(json_encode([
            'tenant_id' => $tenantId,
            'return_url' => $returnUrl,
            'timestamp' => time(),
            'random' => bin2hex(random_bytes(16)),
        ]));
    }

    protected function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected function buildRedirectUri(Tenant $tenant, string $provider): string
    {
        return url("/sso/{$provider}/callback?tenant={$tenant->id}");
    }

    protected function getJWKS(string $jwksUri): array
    {
        $cacheKey = "jwks_" . md5($jwksUri);
        
        return Cache::remember($cacheKey, 3600, function () use ($jwksUri) {
            $response = Http::timeout(10)->get($jwksUri);
            
            if (!$response->successful()) {
                throw new Exception("Failed to fetch JWKS from {$jwksUri}");
            }
            
            return $response->json();
        });
    }

    protected function jwkToKey(array $jwk): string
    {
        if ($jwk['kty'] === 'RSA') {
            return KeyHelper::fromJWK($jwk);
        }
        
        throw new Exception('Unsupported key type: ' . $jwk['kty']);
    }

    /**
     * Logout user from SSO
     */
    public function logout(TenantUser $user, string $returnUrl = null): ?array
    {
        $tenant = $user->tenant;
        $provider = $user->sso_provider;
        
        if (!$provider) {
            return null; // Not an SSO user
        }

        $config = $this->getTenantSSOConfig($tenant, $provider);
        
        switch ($provider) {
            case 'saml':
                return $this->initiateSAMLLogout($user, $config, $returnUrl);
            case 'oidc':
                return $this->initiateOIDCLogout($user, $config, $returnUrl);
            default:
                return null;
        }
    }

    protected function initiateSAMLLogout(TenantUser $user, array $config, ?string $returnUrl): array
    {
        if (!isset($config['idp_slo_url'])) {
            return ['type' => 'local']; // IDP doesn't support SLO
        }

        // Build logout URL
        $logoutUrl = $config['idp_slo_url'] . '?' . http_build_query([
            'ReturnTo' => $returnUrl ?? url('/'),
            'NameID' => $user->sso_provider_id,
        ]);

        return [
            'type' => 'redirect',
            'url' => $logoutUrl,
        ];
    }

    protected function initiateOIDCLogout(TenantUser $user, array $config, ?string $returnUrl): array
    {
        $provider = $config['provider'] ?? 'azure';
        $providerConfig = $this->config['oidc']['providers'][$provider];
        
        // Get discovery document for logout endpoint
        $discoveryUrl = str_replace('{tenant_id}', $providerConfig['tenant_id'] ?? '', $providerConfig['discovery_url']);
        $discoveryDoc = $this->getOIDCDiscoveryDocument($discoveryUrl);
        
        if (!isset($discoveryDoc['end_session_endpoint'])) {
            return ['type' => 'local']; // Provider doesn't support logout
        }

        $logoutUrl = $discoveryDoc['end_session_endpoint'] . '?' . http_build_query([
            'post_logout_redirect_uri' => $returnUrl ?? url('/'),
            'client_id' => $providerConfig['client_id'],
        ]);

        return [
            'type' => 'redirect',
            'url' => $logoutUrl,
        ];
    }
}