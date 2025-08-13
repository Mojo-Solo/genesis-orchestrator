"""
Step definitions for security gates and compliance testing.
"""

from behave import given, when, then, step
from genesis_test_framework import SecurityTester, TestContext
import re
import time
import hmac
import hashlib
from datetime import datetime, timedelta


@given('the GENESIS orchestrator is configured with security policies')
def step_configure_security_policies(context):
    """Configure orchestrator with security policies."""
    context.security_tester = SecurityTester()
    context.security_tester.configure_security_policies()
    assert context.security_tester.is_security_configured()


@given('PII detection and redaction is enabled')
def step_enable_pii_detection(context):
    """Enable PII detection and redaction."""
    context.security_tester.enable_pii_detection()
    context.pii_patterns = context.security_tester.get_pii_patterns()


@given('HMAC validation is configured for webhooks')
def step_configure_hmac_validation(context):
    """Configure HMAC validation for webhooks."""
    context.webhook_secret = "test_webhook_secret_key"
    context.security_tester.configure_hmac_validation(context.webhook_secret)


@given('authentication is set up with proper access controls')
def step_setup_authentication(context):
    """Set up authentication with access controls."""
    context.auth_system = context.security_tester.setup_authentication()
    context.user_roles = ['PUBLIC', 'PROTECTED', 'ADMIN']


@given('rate limiting is enabled')
def step_enable_rate_limiting(context):
    """Enable rate limiting."""
    context.rate_limiter = context.security_tester.enable_rate_limiting({
        'requests_per_minute': 100,
        'burst_limit': 120
    })


@given('audit logging is activated')
def step_activate_audit_logging(context):
    """Activate audit logging."""
    context.audit_logger = context.security_tester.activate_audit_logging()


@given('I have a query containing PII "{query}"')
def step_set_pii_query(context, query):
    """Set query containing PII."""
    context.pii_query = query
    context.expected_pii_types = context.security_tester.identify_pii_types(query)


@given('the system generates an answer containing potential PII')
def step_setup_pii_answer_scenario(context):
    """Setup scenario where system generates PII in answer."""
    context.pii_answer = "Contact John Doe at john.doe@example.com or call 555-123-4567"
    context.expected_pii_in_answer = ['email', 'phone']


@given('I have a webhook endpoint configured')
def step_configure_webhook_endpoint(context):
    """Configure webhook endpoint."""
    context.webhook_endpoint = "/api/webhooks/test"
    context.security_tester.configure_webhook_endpoint(context.webhook_endpoint)


@given('HMAC secret is properly configured')
def step_verify_hmac_secret_configured(context):
    """Verify HMAC secret is properly configured."""
    assert context.security_tester.hmac_secret_configured()
    assert context.webhook_secret is not None


@given('the system has different user roles: "{roles}"')
def step_setup_user_roles(context, roles):
    """Setup different user roles."""
    context.defined_roles = [role.strip().strip('"') for role in roles.split(',')]
    context.security_tester.define_user_roles(context.defined_roles)


@given('rate limiting is configured for {limit:d} requests per minute per IP')
def step_configure_rate_limit(context, limit):
    """Configure specific rate limit."""
    context.rate_limit = limit
    context.security_tester.set_rate_limit(limit, period='minute', per='ip')


@given('I have a query with SQL injection attempt "{injection_query}"')
def step_set_sql_injection_query(context, injection_query):
    """Set query with SQL injection attempt."""
    context.injection_query = injection_query
    context.injection_type = 'sql_injection'


@given('I have a malicious input "{malicious_input}"')
def step_set_malicious_input(context, malicious_input):
    """Set malicious input for testing."""
    context.malicious_input = malicious_input
    context.attack_type = 'prompt_injection'


@given('sensitive data is stored in the system')
def step_setup_sensitive_data(context):
    """Setup sensitive data storage scenario."""
    context.sensitive_data = {
        'user_credentials': 'encrypted_password_hash',
        'payment_info': 'tokenized_card_data',
        'personal_info': 'encrypted_pii_data'
    }


@given('a user establishes a session')
def step_establish_user_session(context):
    """Establish user session."""
    context.user_session = context.security_tester.create_user_session()


@given('security events occur in the system')
def step_setup_security_events(context):
    """Setup security events for audit testing."""
    context.security_events = [
        {'type': 'login_attempt', 'user': 'test_user', 'success': True},
        {'type': 'data_access', 'user': 'test_user', 'resource': 'sensitive_data'},
        {'type': 'permission_denied', 'user': 'unauthorized_user', 'resource': 'admin_panel'}
    ]


@given('the system requires API keys and secrets')
def step_setup_secrets_requirement(context):
    """Setup scenario requiring API keys and secrets."""
    context.required_secrets = ['openai_api_key', 'database_password', 'jwt_secret']
    context.security_tester.setup_secrets_management()


@given('the system is accessed from web browsers')
def step_setup_browser_access(context):
    """Setup browser access scenario for CORS testing."""
    context.origin_domains = ['https://example.com', 'https://app.example.com']
    context.security_tester.configure_cors_policy()


@given('the system communicates over networks')
def step_setup_network_communication(context):
    """Setup network communication scenario."""
    context.network_endpoints = ['api.example.com', 'db.example.com']
    context.security_tester.setup_tls_configuration()


@given('the system dependencies are configured')
def step_setup_dependency_scanning(context):
    """Setup dependency scanning scenario."""
    context.dependencies = context.security_tester.get_system_dependencies()


@given('system data needs to be backed up')
def step_setup_backup_scenario(context):
    """Setup backup security scenario."""
    context.backup_data = {'critical_data': 'important_information'}
    context.security_tester.configure_backup_security()


@given('the system operates in a network environment')
def step_setup_network_environment(context):
    """Setup network security environment."""
    context.network_config = context.security_tester.setup_network_security()


@given('the system processes personal data')
def step_setup_gdpr_scenario(context):
    """Setup GDPR compliance scenario."""
    context.personal_data = {
        'user_id': 'test_user_123',
        'email': 'test@example.com',
        'location': 'EU'
    }
    context.security_tester.configure_gdpr_compliance()


@given('a security incident is detected')
def step_setup_security_incident(context):
    """Setup security incident scenario."""
    context.security_incident = {
        'type': 'unauthorized_access',
        'severity': 'high',
        'timestamp': datetime.utcnow()
    }


@given('penetration testing is performed on the system')
def step_setup_penetration_testing(context):
    """Setup penetration testing scenario."""
    context.pentest_scope = context.security_tester.define_pentest_scope()


@when('I submit the query to the orchestrator')
def step_submit_query_to_orchestrator(context):
    """Submit query to orchestrator for PII testing."""
    context.submission_result = context.security_tester.process_query_with_pii_check(
        context.pii_query
    )


@when('the response is prepared for delivery')
def step_prepare_response_for_delivery(context):
    """Prepare response for delivery with PII check."""
    context.response_result = context.security_tester.prepare_response_with_pii_check(
        context.pii_answer
    )


@when('I receive a webhook request with valid HMAC signature')
def step_receive_valid_hmac_webhook(context):
    """Receive webhook with valid HMAC signature."""
    webhook_payload = '{"event": "test", "data": "sample"}'
    signature = hmac.new(
        context.webhook_secret.encode(),
        webhook_payload.encode(),
        hashlib.sha256
    ).hexdigest()
    
    context.webhook_result = context.security_tester.process_webhook(
        payload=webhook_payload,
        signature=f"sha256={signature}",
        headers={'content-type': 'application/json'}
    )


@when('I receive a webhook request with invalid HMAC signature')
def step_receive_invalid_hmac_webhook(context):
    """Receive webhook with invalid HMAC signature."""
    webhook_payload = '{"event": "test", "data": "sample"}'
    invalid_signature = "sha256=invalid_signature_hash"
    
    context.webhook_result = context.security_tester.process_webhook(
        payload=webhook_payload,
        signature=invalid_signature,
        headers={'content-type': 'application/json'}
    )


@when('a "{role}" user tries to access protected resources')
def step_user_access_attempt(context, role):
    """User attempts to access protected resources."""
    context.access_attempt = context.security_tester.attempt_resource_access(
        user_role=role,
        resource='protected_admin_panel'
    )


@when('a client makes {request_count:d} requests within one minute')
def step_client_makes_multiple_requests(context, request_count):
    """Client makes multiple requests for rate limiting test."""
    context.rate_limit_test_results = []
    
    for i in range(request_count):
        result = context.security_tester.make_request(
            endpoint='/api/test',
            client_ip='192.168.1.100'
        )
        context.rate_limit_test_results.append(result)
        
        # Small delay to simulate realistic timing
        time.sleep(0.01)


@when('the query is processed')
def step_process_injection_query(context):
    """Process query with potential injection."""
    context.injection_result = context.security_tester.process_query_with_injection_check(
        context.injection_query
    )


@when('the input is processed by the orchestrator')
def step_process_malicious_input(context):
    """Process malicious input by orchestrator."""
    context.malicious_input_result = context.security_tester.process_malicious_input(
        context.malicious_input
    )


@when('data is written to persistent storage')
def step_write_data_to_storage(context):
    """Write sensitive data to storage."""
    context.storage_result = context.security_tester.write_sensitive_data_to_storage(
        context.sensitive_data
    )


@when('the session is created')
def step_create_user_session(context):
    """Create user session."""
    context.session_creation_result = context.security_tester.finalize_session_creation(
        context.user_session
    )


@when('any security-relevant action takes place')
def step_security_action_occurs(context):
    """Security-relevant action occurs."""
    context.audit_results = []
    for event in context.security_events:
        result = context.security_tester.process_security_event(event)
        context.audit_results.append(result)


@when('secrets are configured')
def step_configure_secrets(context):
    """Configure secrets management."""
    context.secrets_config_result = context.security_tester.configure_secrets(
        context.required_secrets
    )


@when('cross-origin requests are made')
def step_make_cross_origin_requests(context):
    """Make cross-origin requests."""
    context.cors_test_results = []
    for origin in context.origin_domains:
        result = context.security_tester.make_cors_request(origin)
        context.cors_test_results.append(result)


@when('data is transmitted')
def step_transmit_data(context):
    """Transmit data over network."""
    context.transmission_results = []
    for endpoint in context.network_endpoints:
        result = context.security_tester.transmit_data_to_endpoint(
            endpoint, 'test_data'
        )
        context.transmission_results.append(result)


@when('security scanning is performed')
def step_perform_security_scanning(context):
    """Perform security scanning."""
    context.vulnerability_scan_results = context.security_tester.scan_for_vulnerabilities(
        context.dependencies
    )


@when('backups are created')
def step_create_backups(context):
    """Create secure backups."""
    context.backup_results = context.security_tester.create_secure_backup(
        context.backup_data
    )


@when('network access is configured')
def step_configure_network_access(context):
    """Configure network security."""
    context.network_security_results = context.security_tester.configure_network_security(
        context.network_config
    )


@when('personal data is handled')
def step_handle_personal_data(context):
    """Handle personal data for GDPR compliance."""
    context.gdpr_handling_results = context.security_tester.handle_personal_data_gdpr(
        context.personal_data
    )


@when('the incident response is triggered')
def step_trigger_incident_response(context):
    """Trigger security incident response."""
    context.incident_response_result = context.security_tester.trigger_incident_response(
        context.security_incident
    )


@when('security tests are executed')
def step_execute_security_tests(context):
    """Execute penetration testing."""
    context.pentest_results = context.security_tester.execute_penetration_tests(
        context.pentest_scope
    )


@then('the SSN should be detected as PII')
def step_verify_ssn_detected(context):
    """Verify SSN is detected as PII."""
    assert context.submission_result.pii_detected
    detected_types = context.submission_result.detected_pii_types
    assert 'ssn' in detected_types or 'social_security_number' in detected_types
    context.test_context.log("SSN correctly detected as PII")


@then('the SSN should be redacted in logs as "{redacted_pattern}"')
def step_verify_ssn_redacted(context, redacted_pattern):
    """Verify SSN is properly redacted."""
    logged_query = context.submission_result.logged_query
    assert redacted_pattern in logged_query
    assert '123-45-6789' not in logged_query
    context.test_context.log(f"SSN redacted as: {redacted_pattern}")


@then('the redacted query should be stored safely')
def step_verify_safe_storage(context):
    """Verify redacted query is stored safely."""
    stored_query = context.submission_result.stored_query
    assert context.security_tester.is_safely_stored(stored_query)
    assert not context.security_tester.contains_pii(stored_query)


@then('the original query should not appear in any logs')
def step_verify_original_not_logged(context):
    """Verify original query doesn't appear in logs."""
    all_logs = context.security_tester.get_all_system_logs()
    for log_entry in all_logs:
        assert '123-45-6789' not in log_entry.message
    context.test_context.log("Original PII not found in any logs")


@then('PII detection should be logged in the security audit trail')
def step_verify_pii_detection_logged(context):
    """Verify PII detection is logged in audit trail."""
    audit_entries = context.security_tester.get_audit_trail()
    pii_detection_logged = any(
        'pii_detected' in entry.event_type for entry in audit_entries
    )
    assert pii_detection_logged
    context.test_context.log("PII detection logged in audit trail")


@then('any detected PII should be redacted or masked')
def step_verify_output_pii_redacted(context):
    """Verify PII in output is redacted."""
    processed_response = context.response_result.processed_response
    assert context.security_tester.contains_no_exposed_pii(processed_response)


@then('email addresses should be masked as "{email_mask}"')
def step_verify_email_masking(context, email_mask):
    """Verify email addresses are properly masked."""
    processed_response = context.response_result.processed_response
    assert email_mask in processed_response
    assert 'john.doe@example.com' not in processed_response
    context.test_context.log(f"Email masked as: {email_mask}")


@then('phone numbers should be masked as "{phone_mask}"')
def step_verify_phone_masking(context, phone_mask):
    """Verify phone numbers are properly masked."""
    processed_response = context.response_result.processed_response
    assert phone_mask in processed_response
    assert '555-123-4567' not in processed_response
    context.test_context.log(f"Phone masked as: {phone_mask}")


@then('credit card numbers should be completely redacted')
def step_verify_credit_card_redaction(context):
    """Verify credit card numbers are completely redacted."""
    # This would apply if credit card numbers were in the response
    processed_response = context.response_result.processed_response
    assert not context.security_tester.contains_credit_card_numbers(processed_response)


@then('the redaction should preserve answer readability')
def step_verify_readability_preserved(context):
    """Verify redaction preserves readability."""
    readability_score = context.security_tester.calculate_readability_score(
        context.response_result.processed_response
    )
    assert readability_score >= 0.7  # 70% readability threshold
    context.test_context.log(f"Answer readability preserved: {readability_score:.2%}")


@then('the signature should be validated against the raw body')
def step_verify_signature_validation(context):
    """Verify HMAC signature validation."""
    assert context.webhook_result.signature_valid
    assert context.webhook_result.validation_method == 'raw_body'
    context.test_context.log("HMAC signature validated against raw body")


@then('the request should be processed successfully')
def step_verify_successful_processing(context):
    """Verify request was processed successfully."""
    assert context.webhook_result.processed_successfully
    assert context.webhook_result.status_code == 200


@then('the validation should use constant-time comparison')
def step_verify_constant_time_comparison(context):
    """Verify constant-time comparison was used."""
    assert context.webhook_result.used_constant_time_comparison
    context.test_context.log("Constant-time comparison used for HMAC validation")


@then('clock skew tolerance should be within {minutes:d} minutes')
def step_verify_clock_skew_tolerance(context, minutes):
    """Verify clock skew tolerance."""
    tolerance = context.webhook_result.clock_skew_tolerance
    assert tolerance <= minutes * 60  # Convert to seconds
    context.test_context.log(f"Clock skew tolerance: {tolerance}s <= {minutes * 60}s")


@then('the request should be rejected immediately')
def step_verify_immediate_rejection(context):
    """Verify request was rejected immediately."""
    assert not context.webhook_result.processed_successfully
    assert context.webhook_result.rejected_immediately


@then('a {status_code:d} Unauthorized response should be returned')
def step_verify_unauthorized_response(context, status_code):
    """Verify unauthorized response code."""
    assert context.webhook_result.status_code == status_code
    context.test_context.log(f"Returned status code: {status_code}")


@then('the rejection should be logged for security monitoring')
def step_verify_rejection_logged(context):
    """Verify rejection is logged."""
    security_logs = context.security_tester.get_security_logs()
    rejection_logged = any(
        'webhook_rejection' in log.event_type for log in security_logs
    )
    assert rejection_logged
    context.test_context.log("Webhook rejection logged for security monitoring")


@then('no processing should occur for the invalid request')
def step_verify_no_processing_occurred(context):
    """Verify no processing occurred for invalid request."""
    assert not context.webhook_result.any_processing_occurred
    assert context.webhook_result.processing_steps_completed == 0


@then('access should be denied')
def step_verify_access_denied(context):
    """Verify access was denied."""
    assert not context.access_attempt.access_granted
    assert context.access_attempt.denied_reason is not None
    context.test_context.log(f"Access denied: {context.access_attempt.denied_reason}")


@then('appropriate error message should be returned')
def step_verify_appropriate_error_message(context):
    """Verify appropriate error message."""
    error_message = context.access_attempt.error_message
    assert error_message is not None
    assert 'unauthorized' in error_message.lower() or 'forbidden' in error_message.lower()


@then('the access attempt should be logged')
def step_verify_access_attempt_logged(context):
    """Verify access attempt is logged."""
    audit_logs = context.security_tester.get_audit_logs()
    access_attempt_logged = any(
        'access_attempt' in log.event_type for log in audit_logs
    )
    assert access_attempt_logged


@then('no sensitive information should be leaked in error responses')
def step_verify_no_info_leakage(context):
    """Verify no sensitive information leakage."""
    error_response = context.access_attempt.error_response
    assert not context.security_tester.contains_sensitive_info(error_response)
    context.test_context.log("No sensitive information leaked in error response")


@then('the {request_number:d}st request should be rejected with {status_code:d} Too Many Requests')
def step_verify_rate_limit_rejection(context, request_number, status_code):
    """Verify rate limit rejection."""
    rejected_request = context.rate_limit_test_results[request_number - 1]
    assert rejected_request.status_code == status_code
    assert not rejected_request.processed_successfully
    context.test_context.log(f"Request {request_number} rejected with {status_code}")


@then('the client should receive retry-after headers')
def step_verify_retry_after_headers(context):
    """Verify retry-after headers."""
    rejected_requests = [r for r in context.rate_limit_test_results if r.status_code == 429]
    for request in rejected_requests:
        assert 'retry-after' in request.headers
        retry_after = int(request.headers['retry-after'])
        assert retry_after > 0
    context.test_context.log("Retry-after headers provided")


@then('rate limit violations should be logged')
def step_verify_rate_limit_violations_logged(context):
    """Verify rate limit violations are logged."""
    security_logs = context.security_tester.get_security_logs()
    rate_limit_violations = [
        log for log in security_logs 
        if 'rate_limit_exceeded' in log.event_type
    ]
    assert len(rate_limit_violations) > 0
    context.test_context.log(f"Rate limit violations logged: {len(rate_limit_violations)}")


@then('legitimate traffic should not be affected')
def step_verify_legitimate_traffic_unaffected(context):
    """Verify legitimate traffic is unaffected."""
    successful_requests = [r for r in context.rate_limit_test_results if r.status_code == 200]
    assert len(successful_requests) == context.rate_limit
    context.test_context.log(f"Legitimate requests processed: {len(successful_requests)}")


@then('the injection attempt should be neutralized')
def step_verify_injection_neutralized(context):
    """Verify injection attempt is neutralized."""
    assert context.injection_result.injection_neutralized
    assert not context.injection_result.malicious_code_executed
    context.test_context.log("SQL injection attempt neutralized")


@then('no database commands should be executed')
def step_verify_no_db_commands_executed(context):
    """Verify no database commands were executed."""
    assert not context.injection_result.database_commands_executed
    assert context.injection_result.blocked_sql_operations == ['DROP']


@then('the attempt should be flagged and logged')
def step_verify_injection_flagged_and_logged(context):
    """Verify injection attempt is flagged and logged."""
    assert context.injection_result.attack_flagged
    
    security_logs = context.security_tester.get_security_logs()
    injection_attempt_logged = any(
        'sql_injection_attempt' in log.event_type for log in security_logs
    )
    assert injection_attempt_logged
    context.test_context.log("SQL injection attempt flagged and logged")


@then('system should continue operating normally')
def step_verify_normal_operation(context):
    """Verify system continues normal operation."""
    system_status = context.security_tester.get_system_status()
    assert system_status.operating_normally
    assert not system_status.compromised
    context.test_context.log("System continues operating normally")


@then('the prompt injection should be detected')
def step_verify_prompt_injection_detected(context):
    """Verify prompt injection is detected."""
    assert context.malicious_input_result.prompt_injection_detected
    assert context.malicious_input_result.malicious_pattern_matched
    context.test_context.log("Prompt injection detected")


@then('the malicious instruction should be ignored')
def step_verify_malicious_instruction_ignored(context):
    """Verify malicious instruction is ignored."""
    assert context.malicious_input_result.malicious_instruction_ignored
    assert not context.malicious_input_result.system_prompt_revealed
    context.test_context.log("Malicious instruction ignored")


@then('the system should respond appropriately to the original context')
def step_verify_appropriate_context_response(context):
    """Verify appropriate response to original context."""
    response = context.malicious_input_result.response
    assert context.security_tester.is_appropriate_response(response)
    assert not context.security_tester.contains_system_information(response)


@then('the attack attempt should be logged')
def step_verify_attack_logged(context):
    """Verify attack attempt is logged."""
    security_logs = context.security_tester.get_security_logs()
    attack_logged = any(
        'prompt_injection_attempt' in log.event_type for log in security_logs
    )
    assert attack_logged
    context.test_context.log("Attack attempt logged")


@then('all sensitive data should be encrypted')
def step_verify_data_encrypted(context):
    """Verify all sensitive data is encrypted."""
    storage_result = context.storage_result
    for data_type, stored_data in storage_result.stored_data.items():
        assert context.security_tester.is_encrypted(stored_data)
        assert stored_data != context.sensitive_data[data_type]  # Should be different from original
    context.test_context.log("All sensitive data encrypted")


@then('encryption keys should be properly managed')
def step_verify_key_management(context):
    """Verify encryption keys are properly managed."""
    key_management = context.storage_result.key_management
    assert key_management.keys_properly_stored
    assert key_management.key_rotation_enabled
    assert key_management.access_controlled
    context.test_context.log("Encryption keys properly managed")


@then('encrypted data should not be readable without proper keys')
def step_verify_encrypted_data_unreadable(context):
    """Verify encrypted data is unreadable without keys."""
    for data_type, encrypted_data in context.storage_result.stored_data.items():
        readable_without_key = context.security_tester.is_readable_without_key(encrypted_data)
        assert not readable_without_key
    context.test_context.log("Encrypted data unreadable without proper keys")


@then('encryption standards should meet enterprise requirements')
def step_verify_enterprise_encryption_standards(context):
    """Verify encryption meets enterprise standards."""
    encryption_standards = context.storage_result.encryption_standards
    assert encryption_standards.algorithm in ['AES-256', 'ChaCha20-Poly1305']
    assert encryption_standards.key_length >= 256
    assert encryption_standards.meets_fips_140_2
    context.test_context.log(f"Encryption standards met: {encryption_standards.algorithm}")


@then('session tokens should be cryptographically secure')
def step_verify_secure_session_tokens(context):
    """Verify session tokens are cryptographically secure."""
    session = context.session_creation_result.session
    assert session.token_entropy >= 128  # Minimum entropy bits
    assert session.token_randomness_verified
    assert not context.security_tester.is_predictable_token(session.token)
    context.test_context.log("Session tokens are cryptographically secure")


@then('session should have appropriate timeout')
def step_verify_session_timeout(context):
    """Verify session has appropriate timeout."""
    session = context.session_creation_result.session
    assert session.timeout_minutes <= 480  # Maximum 8 hours
    assert session.timeout_minutes >= 15   # Minimum 15 minutes
    assert session.idle_timeout_enabled
    context.test_context.log(f"Session timeout: {session.timeout_minutes} minutes")


@then('session should be invalidated on logout')
def step_verify_session_invalidation(context):
    """Verify session invalidation on logout."""
    session = context.session_creation_result.session
    # Simulate logout
    logout_result = context.security_tester.simulate_logout(session.token)
    assert logout_result.session_invalidated
    assert not context.security_tester.is_valid_session(session.token)
    context.test_context.log("Session properly invalidated on logout")


@then('concurrent session limits should be enforced')
def step_verify_concurrent_session_limits(context):
    """Verify concurrent session limits."""
    session_limits = context.session_creation_result.session_limits
    assert session_limits.max_concurrent_sessions > 0
    assert session_limits.enforcement_enabled
    context.test_context.log(f"Concurrent session limit: {session_limits.max_concurrent_sessions}")


@then('the action should be logged with')
def step_verify_audit_log_fields(context):
    """Verify audit log contains required fields."""
    for result in context.audit_results:
        audit_entry = result.audit_entry
        
        for row in context.table:
            field = row['Field']
            description = row['Description']
            
            assert hasattr(audit_entry, field.lower()), f"Missing audit field: {field}"
            field_value = getattr(audit_entry, field.lower())
            assert field_value is not None, f"Null audit field: {field}"
    
    context.test_context.log("All required audit fields present")


@then('logs should be tamper-evident')
def step_verify_tamper_evident_logs(context):
    """Verify logs are tamper-evident."""
    for result in context.audit_results:
        audit_entry = result.audit_entry
        assert audit_entry.integrity_hash is not None
        assert context.security_tester.verify_log_integrity(audit_entry)
    context.test_context.log("Logs are tamper-evident")


@then('logs should be retained per compliance requirements')
def step_verify_log_retention(context):
    """Verify log retention compliance."""
    retention_policy = context.security_tester.get_log_retention_policy()
    assert retention_policy.retention_period_days >= 365  # Minimum 1 year
    assert retention_policy.compliance_verified
    context.test_context.log(f"Log retention: {retention_policy.retention_period_days} days")


@then('secrets should never appear in logs')
def step_verify_secrets_not_in_logs(context):
    """Verify secrets don't appear in logs."""
    all_logs = context.security_tester.get_all_system_logs()
    for secret in context.required_secrets:
        for log_entry in all_logs:
            assert secret not in log_entry.message
            assert not context.security_tester.contains_secret_pattern(log_entry.message)
    context.test_context.log("No secrets found in logs")


@then('secrets should be stored in secure key management')
def step_verify_secure_key_storage(context):
    """Verify secrets are stored securely."""
    secrets_storage = context.secrets_config_result.storage_info
    assert secrets_storage.using_key_management_service
    assert secrets_storage.encrypted_at_rest
    assert secrets_storage.access_controlled
    context.test_context.log("Secrets stored in secure key management")


@then('secrets should be rotated regularly')
def step_verify_secret_rotation(context):
    """Verify secrets are rotated regularly."""
    rotation_policy = context.secrets_config_result.rotation_policy
    assert rotation_policy.rotation_enabled
    assert rotation_policy.rotation_interval_days <= 90  # Maximum 90 days
    context.test_context.log(f"Secret rotation: every {rotation_policy.rotation_interval_days} days")


@then('access to secrets should be strictly controlled')
def step_verify_secret_access_control(context):
    """Verify secret access is controlled."""
    access_control = context.secrets_config_result.access_control
    assert access_control.rbac_enabled
    assert access_control.audit_trail_enabled
    assert len(access_control.authorized_roles) > 0
    context.test_context.log("Secret access strictly controlled")


@then('secret usage should be audited')
def step_verify_secret_usage_audited(context):
    """Verify secret usage is audited."""
    usage_audit = context.secrets_config_result.usage_audit
    assert usage_audit.logging_enabled
    assert usage_audit.real_time_monitoring
    context.test_context.log("Secret usage properly audited")


@then('CORS headers should be properly configured')
def step_verify_cors_headers(context):
    """Verify CORS headers are properly configured."""
    for result in context.cors_test_results:
        headers = result.response_headers
        assert 'access-control-allow-origin' in headers
        assert 'access-control-allow-methods' in headers
        assert 'access-control-allow-headers' in headers
    context.test_context.log("CORS headers properly configured")


@then('only allowed origins should be permitted')
def step_verify_allowed_origins(context):
    """Verify only allowed origins are permitted."""
    for result in context.cors_test_results:
        if result.origin in context.origin_domains:
            assert result.access_allowed
        else:
            assert not result.access_allowed
    context.test_context.log("Only allowed origins permitted")


@then('preflight requests should be handled correctly')
def step_verify_preflight_handling(context):
    """Verify preflight requests are handled correctly."""
    for result in context.cors_test_results:
        if result.request_type == 'preflight':
            assert result.preflight_handled_correctly
            assert result.max_age_header_present
    context.test_context.log("Preflight requests handled correctly")


@then('CORS violations should be blocked')
def step_verify_cors_violations_blocked(context):
    """Verify CORS violations are blocked."""
    violation_count = sum(1 for result in context.cors_test_results 
                         if not result.access_allowed)
    assert violation_count > 0  # Should have some blocked requests for testing
    context.test_context.log(f"CORS violations blocked: {violation_count}")


@then('all communications should use TLS {min_version} or higher')
def step_verify_tls_version(context, min_version):
    """Verify TLS version requirements."""
    for result in context.transmission_results:
        tls_info = result.tls_info
        assert tls_info.version >= min_version
        assert tls_info.secure_connection
    context.test_context.log(f"All communications use TLS {min_version}+")


@then('certificate validation should be enforced')
def step_verify_certificate_validation(context):
    """Verify certificate validation."""
    for result in context.transmission_results:
        cert_validation = result.certificate_validation
        assert cert_validation.validation_performed
        assert cert_validation.chain_verified
        assert not cert_validation.self_signed_accepted
    context.test_context.log("Certificate validation enforced")


@then('weak cipher suites should be disabled')
def step_verify_weak_ciphers_disabled(context):
    """Verify weak cipher suites are disabled."""
    for result in context.transmission_results:
        cipher_info = result.cipher_info
        assert cipher_info.strong_cipher_used
        assert not cipher_info.weak_cipher_detected
        assert cipher_info.perfect_forward_secrecy
    context.test_context.log("Weak cipher suites disabled")


@then('certificate expiry should be monitored')
def step_verify_certificate_monitoring(context):
    """Verify certificate expiry monitoring."""
    cert_monitoring = context.security_tester.get_certificate_monitoring()
    assert cert_monitoring.monitoring_enabled
    assert cert_monitoring.expiry_alerts_configured
    assert cert_monitoring.days_before_expiry_alert <= 30
    context.test_context.log("Certificate expiry monitoring active")


@then('all dependencies should be checked for known vulnerabilities')
def step_verify_dependency_vulnerability_check(context):
    """Verify dependencies are checked for vulnerabilities."""
    scan_results = context.vulnerability_scan_results
    assert scan_results.scan_completed
    assert scan_results.dependencies_scanned > 0
    context.test_context.log(f"Dependencies scanned: {scan_results.dependencies_scanned}")


@then('high-severity vulnerabilities should block deployment')
def step_verify_high_severity_blocking(context):
    """Verify high-severity vulnerabilities block deployment."""
    scan_results = context.vulnerability_scan_results
    high_severity_count = scan_results.high_severity_vulnerabilities
    
    if high_severity_count > 0:
        assert scan_results.deployment_blocked
        context.test_context.log(f"Deployment blocked due to {high_severity_count} high-severity vulnerabilities")
    else:
        context.test_context.log("No high-severity vulnerabilities found")


@then('vulnerability reports should be generated')
def step_verify_vulnerability_reports(context):
    """Verify vulnerability reports are generated."""
    scan_results = context.vulnerability_scan_results
    assert scan_results.report_generated
    assert scan_results.report_format in ['json', 'html', 'pdf']
    context.test_context.log("Vulnerability reports generated")


@then('remediation guidance should be provided')
def step_verify_remediation_guidance(context):
    """Verify remediation guidance is provided."""
    scan_results = context.vulnerability_scan_results
    for vulnerability in scan_results.vulnerabilities:
        if vulnerability.severity == 'high':
            assert vulnerability.remediation_guidance is not None
            assert len(vulnerability.remediation_guidance) > 0
    context.test_context.log("Remediation guidance provided for vulnerabilities")


# Additional implementation steps would continue here for remaining scenarios...