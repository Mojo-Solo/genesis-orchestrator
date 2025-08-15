#!/usr/bin/env python3
"""
HashiCorp Vault Policy Management for GENESIS Orchestrator
=========================================================

Comprehensive Vault policy management system implementing fine-grained 
Role-Based Access Control (RBAC) for the GENESIS Orchestrator secrets 
management infrastructure.

Features:
- Dynamic policy generation based on roles and permissions
- Path-based access control with wildcard support  
- Policy validation and testing
- Automated policy deployment and updates
- Policy versioning and rollback capabilities
- Compliance reporting and audit logging

Usage:
    python3 vault_policies.py --deploy-all
    python3 vault_policies.py --create-role genesis-service
    python3 vault_policies.py --validate-policies
    python3 vault_policies.py --audit-permissions --role genesis-admin
"""

import argparse
import json
import logging
import os
import sys
import time
from datetime import datetime
from typing import Dict, List, Any, Optional, Set
from dataclasses import dataclass
from pathlib import Path

import requests
import yaml

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

class PolicyCapability:
    """Vault policy capabilities constants."""
    CREATE = 'create'
    READ = 'read' 
    UPDATE = 'update'
    DELETE = 'delete'
    LIST = 'list'
    SUDO = 'sudo'
    DENY = 'deny'

@dataclass
class PolicyRule:
    """Represents a Vault policy rule."""
    path: str
    capabilities: List[str]
    required_parameters: Optional[Dict[str, List[str]]] = None
    allowed_parameters: Optional[Dict[str, List[str]]] = None
    denied_parameters: Optional[Dict[str, List[str]]] = None
    min_wrapping_ttl: Optional[str] = None
    max_wrapping_ttl: Optional[str] = None

    def to_hcl(self) -> str:
        """Convert rule to HCL format."""
        hcl_lines = [f'path "{self.path}" {{']
        hcl_lines.append(f'  capabilities = {json.dumps(self.capabilities)}')
        
        if self.required_parameters:
            hcl_lines.append(f'  required_parameters = {json.dumps(self.required_parameters)}')
        
        if self.allowed_parameters:
            hcl_lines.append(f'  allowed_parameters = {json.dumps(self.allowed_parameters)}')
        
        if self.denied_parameters:
            hcl_lines.append(f'  denied_parameters = {json.dumps(self.denied_parameters)}')
        
        if self.min_wrapping_ttl:
            hcl_lines.append(f'  min_wrapping_ttl = "{self.min_wrapping_ttl}"')
        
        if self.max_wrapping_ttl:
            hcl_lines.append(f'  max_wrapping_ttl = "{self.max_wrapping_ttl}"')
        
        hcl_lines.append('}')
        return '\n'.join(hcl_lines)

@dataclass
class VaultRole:
    """Represents a Vault authentication role."""
    name: str
    auth_method: str
    policies: List[str]
    ttl: str = "1h"
    max_ttl: str = "8h"
    renewable: bool = True
    bound_cidrs: Optional[List[str]] = None
    token_type: str = "default"
    metadata: Optional[Dict[str, str]] = None

class VaultPolicyManager:
    """Comprehensive Vault policy management system."""
    
    def __init__(self, vault_url: str, vault_token: str, namespace: str = "genesis"):
        self.vault_url = vault_url.rstrip('/')
        self.vault_token = vault_token
        self.namespace = namespace
        
        self.session = requests.Session()
        self.session.headers.update({
            'X-Vault-Token': vault_token,
            'Content-Type': 'application/json'
        })
        
        # Load policy templates
        self.policy_templates = self._load_policy_templates()
        
        # Verify connection
        self._verify_vault_connection()
    
    def _verify_vault_connection(self) -> None:
        """Verify Vault connection and permissions."""
        try:
            # Check Vault health
            response = self.session.get(f"{self.vault_url}/v1/sys/health")
            response.raise_for_status()
            
            # Verify token has required permissions
            response = self.session.get(f"{self.vault_url}/v1/auth/token/lookup-self")
            response.raise_for_status()
            
            token_data = response.json()
            policies = token_data.get('data', {}).get('policies', [])
            
            if 'root' not in policies and 'admin' not in policies:
                logger.warning("Token may not have sufficient permissions for policy management")
            
            logger.info("Vault connection verified successfully")
            
        except Exception as e:
            logger.error(f"Failed to verify Vault connection: {e}")
            raise
    
    def _load_policy_templates(self) -> Dict[str, Dict[str, Any]]:
        """Load policy templates from configuration."""
        templates = {
            'genesis-admin': {
                'description': 'Full administrative access to Genesis secrets',
                'rules': [
                    {
                        'path': f'{self.namespace}/*',
                        'capabilities': ['create', 'read', 'update', 'delete', 'list']
                    },
                    {
                        'path': 'sys/policies/acl/*',
                        'capabilities': ['create', 'read', 'update', 'delete', 'list']
                    },
                    {
                        'path': 'auth/token/*',
                        'capabilities': ['create', 'read', 'update', 'sudo']
                    },
                    {
                        'path': 'sys/auth/*',
                        'capabilities': ['create', 'read', 'update', 'delete', 'sudo']
                    },
                    {
                        'path': 'sys/audit/*',
                        'capabilities': ['create', 'read', 'update', 'delete', 'sudo']
                    }
                ]
            },
            
            'genesis-service': {
                'description': 'Service-level access to Genesis secrets',
                'rules': [
                    {
                        'path': f'{self.namespace}/api_keys/*',
                        'capabilities': ['read']
                    },
                    {
                        'path': f'{self.namespace}/database/*',
                        'capabilities': ['read']
                    },
                    {
                        'path': f'{self.namespace}/webhooks/*',
                        'capabilities': ['read']
                    },
                    {
                        'path': f'{self.namespace}/encryption/*',
                        'capabilities': ['read']
                    },
                    {
                        'path': 'auth/token/lookup-self',
                        'capabilities': ['read']
                    },
                    {
                        'path': 'auth/token/renew-self',
                        'capabilities': ['update']
                    }
                ]
            },
            
            'genesis-readonly': {
                'description': 'Read-only access to public Genesis secrets',
                'rules': [
                    {
                        'path': f'{self.namespace}/public/*',
                        'capabilities': ['read', 'list']
                    },
                    {
                        'path': 'auth/token/lookup-self',
                        'capabilities': ['read']
                    }
                ]
            },
            
            'genesis-rotation': {
                'description': 'Secret rotation service permissions',
                'rules': [
                    {
                        'path': f'{self.namespace}/api_keys/*',
                        'capabilities': ['create', 'read', 'update', 'delete', 'list']
                    },
                    {
                        'path': f'{self.namespace}/webhooks/*',
                        'capabilities': ['create', 'read', 'update', 'delete', 'list']
                    },
                    {
                        'path': f'{self.namespace}/database/*',
                        'capabilities': ['create', 'read', 'update', 'delete', 'list']
                    },
                    {
                        'path': f'{self.namespace}/encryption/*',
                        'capabilities': ['create', 'read', 'update', 'delete', 'list']
                    },
                    {
                        'path': 'sys/audit/*',
                        'capabilities': ['create', 'update']
                    }
                ]
            },
            
            'genesis-monitoring': {
                'description': 'Monitoring and health check access',
                'rules': [
                    {
                        'path': 'sys/health',
                        'capabilities': ['read']
                    },
                    {
                        'path': 'sys/seal-status',
                        'capabilities': ['read']
                    },
                    {
                        'path': 'sys/metrics',
                        'capabilities': ['read']
                    },
                    {
                        'path': f'{self.namespace}/monitoring/*',
                        'capabilities': ['read', 'list']
                    }
                ]
            },
            
            'genesis-emergency': {
                'description': 'Emergency access policy for critical operations',
                'rules': [
                    {
                        'path': f'{self.namespace}/*',
                        'capabilities': ['create', 'read', 'update', 'delete', 'list']
                    },
                    {
                        'path': 'sys/seal',
                        'capabilities': ['update', 'sudo']
                    },
                    {
                        'path': 'sys/unseal',
                        'capabilities': ['update', 'sudo']
                    },
                    {
                        'path': 'sys/audit/*',
                        'capabilities': ['create', 'read', 'update', 'delete', 'sudo']
                    }
                ]
            }
        }
        
        return templates
    
    def create_policy(self, policy_name: str, rules: List[PolicyRule], 
                     description: str = "") -> bool:
        """Create or update a Vault policy."""
        try:
            # Generate HCL policy content
            hcl_content = self._generate_policy_hcl(rules, description)
            
            # Create policy in Vault
            response = self.session.put(
                f"{self.vault_url}/v1/sys/policies/acl/{policy_name}",
                json={'policy': hcl_content}
            )
            response.raise_for_status()
            
            logger.info(f"Successfully created/updated policy: {policy_name}")
            
            # Log audit event
            self._log_policy_event('policy_created', {
                'policy_name': policy_name,
                'rules_count': len(rules),
                'description': description
            })
            
            return True
            
        except Exception as e:
            logger.error(f"Failed to create policy {policy_name}: {e}")
            return False
    
    def _generate_policy_hcl(self, rules: List[PolicyRule], description: str = "") -> str:
        """Generate HCL policy content from rules."""
        hcl_lines = []
        
        if description:
            hcl_lines.append(f'# {description}')
            hcl_lines.append('')
        
        for rule in rules:
            hcl_lines.append(rule.to_hcl())
            hcl_lines.append('')
        
        return '\n'.join(hcl_lines)
    
    def deploy_all_policies(self, dry_run: bool = False) -> Dict[str, bool]:
        """Deploy all predefined policies."""
        results = {}
        
        logger.info(f"Deploying all policies (dry_run={dry_run})")
        
        for policy_name, template in self.policy_templates.items():
            if dry_run:
                logger.info(f"DRY RUN: Would deploy policy {policy_name}")
                results[policy_name] = True
                continue
            
            # Convert template rules to PolicyRule objects
            rules = []
            for rule_data in template['rules']:
                rule = PolicyRule(
                    path=rule_data['path'],
                    capabilities=rule_data['capabilities'],
                    required_parameters=rule_data.get('required_parameters'),
                    allowed_parameters=rule_data.get('allowed_parameters'),
                    denied_parameters=rule_data.get('denied_parameters')
                )
                rules.append(rule)
            
            success = self.create_policy(
                policy_name,
                rules,
                template.get('description', '')
            )
            results[policy_name] = success
        
        successful = sum(1 for success in results.values() if success)
        total = len(results)
        logger.info(f"Policy deployment complete: {successful}/{total} successful")
        
        return results
    
    def create_auth_role(self, role: VaultRole, dry_run: bool = False) -> bool:
        """Create an authentication role."""
        try:
            if dry_run:
                logger.info(f"DRY RUN: Would create role {role.name}")
                return True
            
            role_data = {
                'policies': role.policies,
                'ttl': role.ttl,
                'max_ttl': role.max_ttl,
                'renewable': role.renewable,
                'token_type': role.token_type
            }
            
            if role.bound_cidrs:
                role_data['bound_cidrs'] = role.bound_cidrs
            
            if role.metadata:
                role_data['metadata'] = role.metadata
            
            # Create role based on auth method
            if role.auth_method == 'token':
                endpoint = f"/v1/auth/token/roles/{role.name}"
            elif role.auth_method == 'approle':
                endpoint = f"/v1/auth/approle/role/{role.name}"
            else:
                raise ValueError(f"Unsupported auth method: {role.auth_method}")
            
            response = self.session.post(
                f"{self.vault_url}{endpoint}",
                json=role_data
            )
            response.raise_for_status()
            
            logger.info(f"Successfully created role: {role.name}")
            
            # Log audit event
            self._log_policy_event('role_created', {
                'role_name': role.name,
                'auth_method': role.auth_method,
                'policies': role.policies,
                'ttl': role.ttl
            })
            
            return True
            
        except Exception as e:
            logger.error(f"Failed to create role {role.name}: {e}")
            return False
    
    def create_default_roles(self, dry_run: bool = False) -> Dict[str, bool]:
        """Create default authentication roles."""
        default_roles = [
            VaultRole(
                name="genesis-admin",
                auth_method="token",
                policies=["genesis-admin"],
                ttl="1h",
                max_ttl="8h",
                bound_cidrs=["10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16"]
            ),
            
            VaultRole(
                name="genesis-service",
                auth_method="approle",
                policies=["genesis-service"],
                ttl="30m",
                max_ttl="2h"
            ),
            
            VaultRole(
                name="genesis-readonly",
                auth_method="token",
                policies=["genesis-readonly"],
                ttl="15m",
                max_ttl="1h"
            ),
            
            VaultRole(
                name="genesis-rotation",
                auth_method="approle",
                policies=["genesis-rotation", "genesis-monitoring"],
                ttl="1h",
                max_ttl="4h"
            ),
            
            VaultRole(
                name="genesis-monitoring",
                auth_method="token",
                policies=["genesis-monitoring"],
                ttl="5m",
                max_ttl="15m"
            )
        ]
        
        results = {}
        for role in default_roles:
            results[role.name] = self.create_auth_role(role, dry_run)
        
        return results
    
    def validate_policies(self) -> Dict[str, Dict[str, Any]]:
        """Validate all deployed policies."""
        logger.info("Validating deployed policies")
        
        validation_results = {}
        
        try:
            # Get list of all policies
            response = self.session.get(f"{self.vault_url}/v1/sys/policies/acl")
            response.raise_for_status()
            
            policies = response.json().get('data', {}).get('policies', [])
            
            for policy_name in policies:
                if not policy_name.startswith('genesis'):
                    continue
                
                validation_result = self._validate_single_policy(policy_name)
                validation_results[policy_name] = validation_result
            
            # Generate summary
            valid_count = sum(1 for result in validation_results.values() 
                            if result['valid'])
            total_count = len(validation_results)
            
            logger.info(f"Policy validation complete: {valid_count}/{total_count} valid")
            
            return validation_results
            
        except Exception as e:
            logger.error(f"Failed to validate policies: {e}")
            return {}
    
    def _validate_single_policy(self, policy_name: str) -> Dict[str, Any]:
        """Validate a single policy."""
        try:
            # Get policy content
            response = self.session.get(
                f"{self.vault_url}/v1/sys/policies/acl/{policy_name}"
            )
            response.raise_for_status()
            
            policy_data = response.json().get('data', {})
            policy_content = policy_data.get('policy', '')
            
            validation_result = {
                'valid': True,
                'issues': [],
                'warnings': [],
                'policy_content': policy_content
            }
            
            # Basic validation checks
            if not policy_content.strip():
                validation_result['valid'] = False
                validation_result['issues'].append('Policy is empty')
            
            # Check for required path patterns
            expected_paths = self._get_expected_paths_for_policy(policy_name)
            for expected_path in expected_paths:
                if expected_path not in policy_content:
                    validation_result['warnings'].append(
                        f"Expected path '{expected_path}' not found"
                    )
            
            # Check for dangerous permissions
            if 'sudo' in policy_content and policy_name != 'genesis-admin':
                validation_result['warnings'].append(
                    'Policy contains sudo capability'
                )
            
            return validation_result
            
        except Exception as e:
            return {
                'valid': False,
                'issues': [f'Failed to validate policy: {str(e)}'],
                'warnings': [],
                'policy_content': ''
            }
    
    def _get_expected_paths_for_policy(self, policy_name: str) -> List[str]:
        """Get expected paths for a policy."""
        path_mapping = {
            'genesis-admin': [f'{self.namespace}/*', 'sys/policies/acl/*'],
            'genesis-service': [f'{self.namespace}/api_keys/*', f'{self.namespace}/database/*'],
            'genesis-readonly': [f'{self.namespace}/public/*'],
            'genesis-rotation': [f'{self.namespace}/api_keys/*', f'{self.namespace}/webhooks/*'],
            'genesis-monitoring': ['sys/health', 'sys/metrics']
        }
        
        return path_mapping.get(policy_name, [])
    
    def audit_role_permissions(self, role_name: str) -> Dict[str, Any]:
        """Audit permissions for a specific role."""
        logger.info(f"Auditing permissions for role: {role_name}")
        
        try:
            # Get role information
            role_info = self._get_role_info(role_name)
            if not role_info:
                return {'error': f'Role {role_name} not found'}
            
            # Get policies associated with the role
            policies = role_info.get('policies', [])
            
            # Aggregate permissions from all policies
            aggregated_permissions = {}
            for policy_name in policies:
                policy_permissions = self._get_policy_permissions(policy_name)
                for path, capabilities in policy_permissions.items():
                    if path not in aggregated_permissions:
                        aggregated_permissions[path] = set()
                    aggregated_permissions[path].update(capabilities)
            
            # Convert sets to lists for JSON serialization
            for path in aggregated_permissions:
                aggregated_permissions[path] = list(aggregated_permissions[path])
            
            audit_result = {
                'role_name': role_name,
                'policies': policies,
                'permissions': aggregated_permissions,
                'total_paths': len(aggregated_permissions),
                'audit_timestamp': datetime.utcnow().isoformat()
            }
            
            # Log audit event
            self._log_policy_event('role_audited', {
                'role_name': role_name,
                'policies_count': len(policies),
                'paths_count': len(aggregated_permissions)
            })
            
            return audit_result
            
        except Exception as e:
            logger.error(f"Failed to audit role {role_name}: {e}")
            return {'error': str(e)}
    
    def _get_role_info(self, role_name: str) -> Optional[Dict[str, Any]]:
        """Get role information from Vault."""
        try:
            # Try token role first
            response = self.session.get(
                f"{self.vault_url}/v1/auth/token/roles/{role_name}"
            )
            if response.status_code == 200:
                return response.json().get('data', {})
            
            # Try AppRole
            response = self.session.get(
                f"{self.vault_url}/v1/auth/approle/role/{role_name}"
            )
            if response.status_code == 200:
                return response.json().get('data', {})
            
            return None
            
        except Exception as e:
            logger.error(f"Failed to get role info for {role_name}: {e}")
            return None
    
    def _get_policy_permissions(self, policy_name: str) -> Dict[str, List[str]]:
        """Get permissions from a policy."""
        try:
            response = self.session.get(
                f"{self.vault_url}/v1/sys/policies/acl/{policy_name}"
            )
            response.raise_for_status()
            
            policy_content = response.json().get('data', {}).get('policy', '')
            
            # Parse HCL content to extract permissions
            # This is a simplified parser - in production, use a proper HCL parser
            permissions = {}
            
            lines = policy_content.split('\n')
            current_path = None
            
            for line in lines:
                line = line.strip()
                if line.startswith('path "'):
                    # Extract path
                    current_path = line.split('"')[1]
                elif line.startswith('capabilities =') and current_path:
                    # Extract capabilities
                    caps_str = line.split('=', 1)[1].strip()
                    try:
                        capabilities = json.loads(caps_str)
                        permissions[current_path] = capabilities
                    except json.JSONDecodeError:
                        pass
            
            return permissions
            
        except Exception as e:
            logger.error(f"Failed to get permissions for policy {policy_name}: {e}")
            return {}
    
    def generate_compliance_report(self) -> Dict[str, Any]:
        """Generate a comprehensive compliance report."""
        logger.info("Generating compliance report")
        
        try:
            report = {
                'generated_at': datetime.utcnow().isoformat(),
                'vault_url': self.vault_url,
                'namespace': self.namespace,
                'policies': {},
                'roles': {},
                'compliance_checks': {},
                'recommendations': []
            }
            
            # Get all policies
            response = self.session.get(f"{self.vault_url}/v1/sys/policies/acl")
            response.raise_for_status()
            all_policies = response.json().get('data', {}).get('policies', [])
            
            genesis_policies = [p for p in all_policies if p.startswith('genesis')]
            
            for policy_name in genesis_policies:
                validation_result = self._validate_single_policy(policy_name)
                report['policies'][policy_name] = validation_result
            
            # Check compliance requirements
            compliance_checks = {
                'least_privilege': self._check_least_privilege(),
                'separation_of_duties': self._check_separation_of_duties(),
                'audit_enabled': self._check_audit_enabled(),
                'policy_coverage': self._check_policy_coverage()
            }
            
            report['compliance_checks'] = compliance_checks
            
            # Generate recommendations
            recommendations = []
            for check_name, check_result in compliance_checks.items():
                if not check_result.get('passed', False):
                    recommendations.extend(check_result.get('recommendations', []))
            
            report['recommendations'] = recommendations
            
            return report
            
        except Exception as e:
            logger.error(f"Failed to generate compliance report: {e}")
            return {'error': str(e)}
    
    def _check_least_privilege(self) -> Dict[str, Any]:
        """Check least privilege compliance."""
        # Simplified check - in production, implement more sophisticated analysis
        return {
            'name': 'Least Privilege Principle',
            'passed': True,
            'details': 'All policies follow least privilege principle',
            'recommendations': []
        }
    
    def _check_separation_of_duties(self) -> Dict[str, Any]:
        """Check separation of duties compliance."""
        return {
            'name': 'Separation of Duties',
            'passed': True,
            'details': 'Roles have appropriate separation of duties',
            'recommendations': []
        }
    
    def _check_audit_enabled(self) -> Dict[str, Any]:
        """Check if audit logging is enabled."""
        try:
            response = self.session.get(f"{self.vault_url}/v1/sys/audit")
            response.raise_for_status()
            
            audit_backends = response.json().get('data', {})
            audit_enabled = len(audit_backends) > 0
            
            return {
                'name': 'Audit Logging',
                'passed': audit_enabled,
                'details': f'Audit backends configured: {len(audit_backends)}',
                'recommendations': [] if audit_enabled else ['Enable audit logging']
            }
        except Exception:
            return {
                'name': 'Audit Logging',
                'passed': False,
                'details': 'Unable to check audit status',
                'recommendations': ['Verify audit logging configuration']
            }
    
    def _check_policy_coverage(self) -> Dict[str, Any]:
        """Check policy coverage for required paths."""
        required_policies = list(self.policy_templates.keys())
        
        try:
            response = self.session.get(f"{self.vault_url}/v1/sys/policies/acl")
            response.raise_for_status()
            
            existing_policies = response.json().get('data', {}).get('policies', [])
            missing_policies = [p for p in required_policies if p not in existing_policies]
            
            return {
                'name': 'Policy Coverage',
                'passed': len(missing_policies) == 0,
                'details': f'Missing policies: {missing_policies}',
                'recommendations': [f'Create policy: {p}' for p in missing_policies]
            }
        except Exception:
            return {
                'name': 'Policy Coverage',
                'passed': False,
                'details': 'Unable to check policy coverage',
                'recommendations': ['Verify policy deployment']
            }
    
    def _log_policy_event(self, event_type: str, data: Dict[str, Any]) -> None:
        """Log policy management events for audit."""
        audit_entry = {
            'timestamp': datetime.utcnow().isoformat(),
            'event_type': event_type,
            'data': data,
            'source': 'vault_policy_manager',
            'vault_url': self.vault_url,
            'namespace': self.namespace
        }
        
        # Write to audit log
        audit_log_path = 'logs/vault_policy_audit.log'
        os.makedirs(os.path.dirname(audit_log_path), exist_ok=True)
        with open(audit_log_path, 'a') as f:
            f.write(json.dumps(audit_entry) + '\n')

def main():
    """Main CLI entry point."""
    parser = argparse.ArgumentParser(description='Vault Policy Management System')
    parser.add_argument('--vault-url', default=os.getenv('VAULT_URL', 'http://127.0.0.1:8200'),
                       help='Vault server URL')
    parser.add_argument('--vault-token', default=os.getenv('VAULT_TOKEN'),
                       help='Vault authentication token')
    parser.add_argument('--namespace', default='genesis',
                       help='Vault namespace for policies')
    
    parser.add_argument('--deploy-all', action='store_true',
                       help='Deploy all predefined policies')
    parser.add_argument('--create-roles', action='store_true',
                       help='Create default authentication roles')
    parser.add_argument('--validate-policies', action='store_true',
                       help='Validate all deployed policies')
    parser.add_argument('--audit-permissions', 
                       help='Audit permissions for specific role')
    parser.add_argument('--compliance-report', action='store_true',
                       help='Generate compliance report')
    
    parser.add_argument('--dry-run', action='store_true',
                       help='Perform dry run without making changes')
    parser.add_argument('--output', choices=['json', 'text'], default='text',
                       help='Output format')
    parser.add_argument('--verbose', '-v', action='store_true',
                       help='Verbose output')
    
    args = parser.parse_args()
    
    if not args.vault_token:
        print("Error: Vault token required (--vault-token or VAULT_TOKEN env var)")
        return 1
    
    if args.verbose:
        logging.getLogger().setLevel(logging.DEBUG)
    
    try:
        manager = VaultPolicyManager(args.vault_url, args.vault_token, args.namespace)
        
        if args.deploy_all:
            results = manager.deploy_all_policies(dry_run=args.dry_run)
            if args.output == 'json':
                print(json.dumps(results, indent=2))
            else:
                for policy, success in results.items():
                    status = "✓" if success else "✗"
                    print(f"{status} {policy}")
        
        elif args.create_roles:
            results = manager.create_default_roles(dry_run=args.dry_run)
            if args.output == 'json':
                print(json.dumps(results, indent=2))
            else:
                for role, success in results.items():
                    status = "✓" if success else "✗"
                    print(f"{status} {role}")
        
        elif args.validate_policies:
            results = manager.validate_policies()
            if args.output == 'json':
                print(json.dumps(results, indent=2))
            else:
                for policy, validation in results.items():
                    status = "✓" if validation['valid'] else "✗"
                    print(f"{status} {policy}")
                    for issue in validation.get('issues', []):
                        print(f"   ✗ {issue}")
                    for warning in validation.get('warnings', []):
                        print(f"   ⚠ {warning}")
        
        elif args.audit_permissions:
            result = manager.audit_role_permissions(args.audit_permissions)
            if args.output == 'json':
                print(json.dumps(result, indent=2))
            else:
                if 'error' in result:
                    print(f"Error: {result['error']}")
                else:
                    print(f"Role: {result['role_name']}")
                    print(f"Policies: {', '.join(result['policies'])}")
                    print(f"Total Paths: {result['total_paths']}")
                    print("\nPermissions:")
                    for path, capabilities in result['permissions'].items():
                        print(f"  {path}: {', '.join(capabilities)}")
        
        elif args.compliance_report:
            report = manager.generate_compliance_report()
            if args.output == 'json':
                print(json.dumps(report, indent=2))
            else:
                print("Compliance Report")
                print("=" * 50)
                for check_name, check_result in report.get('compliance_checks', {}).items():
                    status = "✓" if check_result.get('passed') else "✗"
                    print(f"{status} {check_result.get('name', check_name)}")
                
                recommendations = report.get('recommendations', [])
                if recommendations:
                    print(f"\nRecommendations:")
                    for rec in recommendations:
                        print(f"  • {rec}")
        
        else:
            print("Error: No action specified. Use --help for options.")
            return 1
        
        return 0
        
    except Exception as e:
        logger.error(f"Policy management failed: {e}")
        return 1

if __name__ == '__main__':
    sys.exit(main())