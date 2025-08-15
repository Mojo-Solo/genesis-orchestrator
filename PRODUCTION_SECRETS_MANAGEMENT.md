# Production-Grade Secrets Management Infrastructure

## Overview

This document describes the comprehensive production-grade secrets management infrastructure implemented for the GENESIS Orchestrator. The system provides enterprise-level security, automated rotation, comprehensive audit logging, and zero-trust access control.

## Architecture Components

### 1. HashiCorp Vault Integration (`backend/services/VaultService.php`)
- **Multi-backend Support**: HashiCorp Vault, AWS Secrets Manager, and encrypted filesystem
- **Authentication Methods**: Token-based, AppRole, and JWT authentication
- **Zero-downtime Operations**: Seamless secret rotation without service interruption
- **Health Monitoring**: Continuous health checks with automatic failover

### 2. HMAC Validation Middleware (`backend/middleware/HmacValidationMiddleware.php`)
- **Cryptographic Security**: Timing-safe signature validation
- **Replay Attack Prevention**: Timestamp validation with configurable skew tolerance
- **Source Verification**: IP whitelisting and User-Agent pattern matching
- **Multi-algorithm Support**: SHA-1, SHA-256, and SHA-512 HMAC validation

### 3. Advanced Rate Limiting (`backend/middleware/RateLimitingMiddleware.php`)
- **Multiple Algorithms**: Token bucket, sliding window, fixed window, and leaky bucket
- **Dynamic Adjustment**: Automatic rate limit adjustment based on system load
- **Intelligent Blocking**: Progressive penalties and IP reputation scoring
- **Circuit Breaker Integration**: Automatic failsafe mechanisms

### 4. Secret Rotation Automation (`scripts/rotate_secrets.py`)
- **Policy-driven Rotation**: Configurable rotation intervals per secret type
- **Zero-downtime Deployment**: Staged rollouts with validation
- **Automatic Rollback**: Failure detection with automatic recovery
- **Multi-service Validation**: Service-specific secret validation

### 5. RBAC Policy Management (`scripts/vault_policies.py`)
- **Fine-grained Access Control**: Path-based permissions with wildcard support
- **Role-based Security**: Admin, service, readonly, rotation, and monitoring roles
- **Compliance Reporting**: Automated compliance validation and reporting
- **Policy Versioning**: Policy deployment with rollback capabilities

## Security Features

### Zero-Trust Architecture
- **Binary Authentication**: Routes are either PUBLIC or PROTECTED, no ambiguity
- **Mutual TLS**: Certificate-based authentication for service-to-service communication
- **IP Reputation**: Dynamic IP blocking based on suspicious activity patterns
- **Geolocation Filtering**: Country-based access restrictions

### Comprehensive Audit Logging
- **Immutable Audit Trail**: All secret operations logged with cryptographic integrity
- **PII Redaction**: Automatic detection and redaction of sensitive information
- **Structured Logging**: JSON-formatted logs for automated analysis
- **Retention Policies**: Configurable log retention with automatic archival

### Encryption at Rest and in Transit
- **Multiple Encryption Layers**: Transit encryption with Vault's encryption-as-a-service
- **Key Derivation**: PBKDF2 key derivation for filesystem storage
- **Secure Key Management**: Hardware Security Module (HSM) integration support
- **Forward Secrecy**: Regular key rotation with perfect forward secrecy

## Quick Start

### 1. Environment Setup
```bash
# Copy environment template
cp env.example .env

# Configure required variables
VAULT_URL=http://127.0.0.1:8200
VAULT_TOKEN=your-vault-token
VAULT_NAMESPACE=genesis
```

### 2. Automated Setup
```bash
# Development environment
./scripts/setup_production_secrets.sh --environment dev

# Production environment
./scripts/setup_production_secrets.sh --environment prod

# Dry run mode
./scripts/setup_production_secrets.sh --environment prod --dry-run
```

### 3. Manual Configuration

#### Install HashiCorp Vault
```bash
# Download and install Vault
curl -fsSL https://apt.releases.hashicorp.com/gpg | sudo apt-key add -
sudo apt-add-repository "deb [arch=amd64] https://apt.releases.hashicorp.com $(lsb_release -cs) main"
sudo apt-get update && sudo apt-get install vault
```

#### Deploy Vault Policies
```bash
# Deploy all policies
python3 scripts/vault_policies.py --deploy-all

# Create authentication roles
python3 scripts/vault_policies.py --create-roles

# Validate deployment
python3 scripts/vault_policies.py --validate-policies
```

#### Initialize Secret Rotation
```bash
# Create initial secrets
python3 scripts/rotate_secrets.py --all --dry-run

# Run actual rotation
python3 scripts/rotate_secrets.py --all
```

## Configuration

### Vault Configuration (`backend/config/vault.php`)
```php
'drivers' => [
    'hashicorp' => [
        'url' => env('VAULT_URL', 'http://127.0.0.1:8200'),
        'token' => env('VAULT_TOKEN'),
        'namespace' => env('VAULT_NAMESPACE', 'genesis'),
        'auth_method' => env('VAULT_AUTH_METHOD', 'token'),
    ]
],

'rotation' => [
    'enabled' => env('VAULT_ROTATION_ENABLED', true),
    'default_ttl' => env('VAULT_DEFAULT_TTL', 2592000), // 30 days
    'auto_rotate' => env('VAULT_AUTO_ROTATE', true),
],
```

### Secret Rotation Policies (`config/secret_rotation.yaml`)
```yaml
policies:
  api_keys:
    type: api_key
    rotation_interval_days: 30
    warning_days: 7
    validation_required: true
    
  webhook_secrets:
    type: webhook_secret
    rotation_interval_days: 90
    warning_days: 14
    staged_rollout:
      enabled: true
      stages:
        - name: "validation"
          duration_minutes: 5
        - name: "canary"
          duration_minutes: 15
          traffic_percentage: 10
        - name: "production"
          duration_minutes: 0
          traffic_percentage: 100
```

### Webhook Security (`config/webhook_security.php`)
```php
'hmac_validation' => [
    'enabled' => env('WEBHOOK_HMAC_ENABLED', true),
    'default_algorithm' => 'sha256',
    'timing_attack_protection' => true,
],

'replay_protection' => [
    'enabled' => env('WEBHOOK_REPLAY_PROTECTION', true),
    'max_timestamp_skew' => env('WEBHOOK_MAX_TIMESTAMP_SKEW', 300),
],
```

## Usage Examples

### Secret Management

#### Store a Secret
```php
use App\Services\VaultService;

$vault = new VaultService();
$success = $vault->putSecret('api_keys/claude', [
    'value' => 'sk-ant-api03-...',
    'created_at' => now()->toISOString(),
]);
```

#### Retrieve a Secret
```php
$secret = $vault->getSecret('api_keys/claude');
$apiKey = $secret['value'];
```

#### Rotate a Secret
```bash
# Rotate specific secret
python3 scripts/rotate_secrets.py --secret-path api_keys/claude

# Emergency rotation
python3 scripts/rotate_secrets.py --secret-path api_keys/claude --emergency

# Rotate all secrets
python3 scripts/rotate_secrets.py --all
```

### Middleware Integration

#### Enable HMAC Validation
```php
// In routes/api.php
Route::post('/webhooks/github', [WebhookController::class, 'github'])
    ->middleware('hmac.validate:webhooks/github_secret');
```

#### Configure Rate Limiting
```php
// Apply rate limiting with custom parameters
Route::middleware('rate.limit:100,20,sliding_window,ip')
    ->group(function () {
        Route::apiResource('orchestration', OrchestrationController::class);
    });
```

### Policy Management

#### Audit Role Permissions
```bash
# Audit specific role
python3 scripts/vault_policies.py --audit-permissions genesis-service

# Generate compliance report
python3 scripts/vault_policies.py --compliance-report
```

#### Custom Policy Creation
```python
from backend.scripts.vault_policies import VaultPolicyManager, PolicyRule

manager = VaultPolicyManager(vault_url, vault_token)

rules = [
    PolicyRule(
        path='genesis/custom/*',
        capabilities=['read', 'list']
    )
]

manager.create_policy('genesis-custom', rules, 'Custom policy description')
```

## Monitoring and Alerting

### Health Checks
```bash
# Manual health check
./scripts/health_check_secrets.sh

# Get Vault service health
curl -s http://127.0.0.1:8200/v1/sys/health | jq
```

### Metrics and Alerts
- **Secret Rotation Success Rate**: Monitor rotation success/failure ratios
- **Vault Connectivity**: Alert on Vault connection failures
- **Authentication Failures**: Monitor and alert on auth violations
- **Rate Limiting**: Track blocked requests and potential attacks

### Audit Log Analysis
```bash
# Search for security violations
grep "security_violation" logs/vault_audit.log | jq

# Analyze failed authentications
grep "auth_failure" logs/security_audit.log | jq '.metadata.ip_address' | sort | uniq -c
```

## Security Best Practices

### Development Environment
1. **Isolated Vault Instance**: Use separate Vault instance for development
2. **Non-production Secrets**: Never use production secrets in development
3. **Short-lived Tokens**: Configure shorter TTL for development tokens
4. **Local-only Access**: Restrict Vault access to localhost in development

### Staging Environment
1. **Production-like Configuration**: Mirror production security settings
2. **Separate Secret Namespace**: Use dedicated namespace for staging
3. **Automated Testing**: Include security tests in CI/CD pipeline
4. **Access Control**: Limit staging access to authorized personnel

### Production Environment
1. **High Availability**: Deploy Vault in HA mode with clustering
2. **HSM Integration**: Use Hardware Security Modules for key storage
3. **Network Segmentation**: Isolate Vault in dedicated network segment
4. **Regular Audits**: Conduct quarterly security audits
5. **Disaster Recovery**: Implement comprehensive backup and recovery procedures

## Troubleshooting

### Common Issues

#### Vault Connection Failures
```bash
# Check Vault status
vault status

# Verify network connectivity
curl -v http://127.0.0.1:8200/v1/sys/health

# Check authentication
vault auth -method=token
```

#### Secret Rotation Failures
```bash
# Check rotation logs
tail -f logs/secret_rotation.log

# Test secret accessibility
python3 scripts/rotate_secrets.py --secret-path api_keys/test --dry-run

# Validate rotation policies
python3 scripts/vault_policies.py --validate-policies
```

#### Authentication Issues
```bash
# Check security audit logs
tail -f logs/security_audit.log | grep auth_failure

# Verify HMAC configuration
curl -X POST -H "X-Signature-256: invalid" localhost/webhooks/test
```

### Emergency Procedures

#### Emergency Secret Rotation
```bash
# Rotate all secrets immediately
python3 scripts/rotate_secrets.py --all --emergency

# Rotate specific compromised secret
python3 scripts/rotate_secrets.py --secret-path api_keys/claude --emergency
```

#### Vault Recovery
```bash
# Unseal Vault (if sealed)
vault operator unseal

# Check seal status
vault status

# Verify policies
vault policy list
```

#### System Lockdown
```bash
# Enable emergency mode (bypasses some validations)
export WEBHOOK_EMERGENCY_MODE=true

# Restart services with emergency configuration
systemctl restart genesis-orchestrator
```

## Compliance and Auditing

### Compliance Features
- **SOC 2 Type II**: Audit logging and access controls
- **PCI DSS**: Encryption and secret management requirements
- **HIPAA**: Data protection and access logging
- **GDPR**: Data privacy and retention policies

### Audit Reports
The system generates comprehensive audit reports including:
- Secret access patterns
- Failed authentication attempts
- Policy violations
- System health metrics
- Compliance status

### Data Retention
- **Audit Logs**: 7 years retention (configurable)
- **Secret Versions**: 90 days retention (configurable)
- **Metrics**: 1 year retention (configurable)
- **Backup Data**: 5 years retention (configurable)

## Migration and Upgrade

### From Existing Systems
1. **Assessment**: Audit current secret management practices
2. **Migration Plan**: Develop phased migration strategy
3. **Dual-mode Operation**: Run parallel systems during transition
4. **Validation**: Verify all secrets migrated successfully
5. **Cutover**: Switch to new system with rollback plan

### System Updates
1. **Backup**: Create full system backup before updates
2. **Staging Test**: Test updates in staging environment
3. **Rolling Update**: Deploy updates with zero downtime
4. **Validation**: Verify all functionality post-update
5. **Rollback Plan**: Maintain ability to rollback if needed

## Support and Maintenance

### Regular Maintenance Tasks
- **Weekly**: Review audit logs for anomalies
- **Monthly**: Rotate administrative credentials
- **Quarterly**: Security audit and penetration testing
- **Annually**: Disaster recovery testing

### Support Contacts
- **Security Team**: security@company.com
- **Operations Team**: ops@company.com
- **Emergency Hotline**: +1-555-SECURITY

## Conclusion

This production-grade secrets management infrastructure provides enterprise-level security, automated operations, and comprehensive monitoring. The system is designed to scale with your organization while maintaining the highest security standards.

For additional information or support, please refer to the generated setup reports and audit logs, or contact the security team.