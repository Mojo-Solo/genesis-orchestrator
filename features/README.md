# GENESIS Orchestrator BDD Test Suite

This directory contains a comprehensive Behavior-Driven Development (BDD) test suite for the GENESIS Orchestrator, using Cucumber/Gherkin syntax with Python's behave framework.

## Overview

The BDD test suite provides comprehensive coverage of the GENESIS orchestrator's core functionality:

1. **LAG (Logic-Aware Generation) Decomposition** - Multi-hop question decomposition and terminator detection
2. **RCR (Role-Aware Context Routing)** - Token-efficient context routing with role-specific budgets
3. **Stability Testing** - 98.6% reproducibility validation across multiple runs
4. **Security Gates** - PII redaction, HMAC validation, and security compliance
5. **Meta-Learning Cycle** - Continuous improvement and self-optimization validation

## File Structure

```
features/
├── README.md                           # This file
├── lag_decomposition.feature           # LAG decomposition test scenarios
├── rcr_routing.feature                 # RCR routing efficiency test scenarios  
├── stability.feature                   # 98.6% reproducibility test scenarios
├── security.feature                    # Security gates and compliance scenarios
├── meta_learning.feature               # Meta-learning cycle validation scenarios
└── steps/                              # Step definition implementations
    ├── framework_init.py               # Mock framework initialization
    ├── lag_decomposition_steps.py      # LAG decomposition step definitions
    ├── rcr_routing_steps.py            # RCR routing step definitions
    ├── stability_steps.py              # Stability testing step definitions
    ├── security_steps.py               # Security testing step definitions
    └── meta_learning_steps.py          # Meta-learning step definitions
```

## Key Features

### LAG Decomposition Testing (`lag_decomposition.feature`)
- Complex multi-hop question decomposition
- Logical dependency ordering validation
- Terminator detection for unanswerable/contradictory questions
- Cognitive load threshold testing
- Accuracy verification against oracle answers
- Performance comparison with traditional RAG approaches

### RCR Routing Testing (`rcr_routing.feature`)
- Role-specific token budget allocation and enforcement
- Importance scoring based on role keywords, task stage, and recency
- Semantic filtering with similarity thresholds
- Token usage reduction validation (≥30% vs full context)
- Latency improvement measurement (≥20% p50 reduction)
- Quality preservation during context reduction

### Stability Testing (`stability.feature`)
- 98.6% reproducibility target validation
- Plan graph equivalence across runs
- Route set consistency verification
- Answer similarity within 1.4% Levenshtein distance
- Latency variance within ±1.4% of median
- Deterministic behavior with fixed seeds
- Edge case robustness testing

### Security Testing (`security.feature`)
- PII detection and redaction (SSN, email, phone, credit cards)
- HMAC signature validation for webhooks
- Role-based access control enforcement
- Rate limiting and DDoS protection
- SQL injection and prompt injection prevention
- Data encryption at rest and in transit
- GDPR compliance validation
- Security audit logging

### Meta-Learning Testing (`meta_learning.feature`)
- Automated bottleneck detection from execution traces
- Improvement proposal generation
- A/B testing infrastructure validation
- Performance measurement post-deployment
- Dead logic elimination
- Prompt bloat reduction
- Routing waste identification
- Tool reliability analysis
- Variance reduction strategies
- Safe rollback mechanisms

## Test Execution

### Prerequisites

Install dependencies:
```bash
pip install -r requirements.txt
```

### Running Tests

Run all tests:
```bash
behave
```

Run specific feature:
```bash
behave features/lag_decomposition.feature
behave features/rcr_routing.feature  
behave features/stability.feature
behave features/security.feature
behave features/meta_learning.feature
```

Run tests with specific tags:
```bash
behave --tags=@critical
behave --tags=@lag
behave --tags=@rcr
behave --tags=@stability
behave --tags=@security
behave --tags=@meta-learning
```

Run tests excluding work-in-progress:
```bash
behave --tags=-@wip
```

### Configuration

Test behavior is configured in `behave.ini`:

- **Stability target**: 98.6% reproducibility
- **Performance thresholds**: Max 2000ms latency, ≥30% token reduction
- **Security settings**: PII detection, HMAC validation, rate limiting
- **Meta-learning**: Bottleneck detection, improvement thresholds

### Test Output

Tests generate detailed output including:
- Step-by-step execution logs
- Performance metrics and timing
- Stability analysis reports
- Security validation results
- Meta-learning insights

### Mock Framework

The test suite includes a comprehensive mock framework (`framework_init.py`) that simulates:
- GENESIS orchestrator components
- LAG decomposition engine
- RCR router with importance scoring
- Stability testing infrastructure
- Security validation systems
- Meta-learning analysis engine

## Test Scenarios by Priority

### Critical Tests (@critical)
- LAG multi-hop question decomposition
- Terminator detection for impossible questions
- RCR token budget enforcement
- 98.6% stability validation
- PII detection and redaction
- HMAC signature validation
- Bottleneck detection and improvement

### Performance Tests
- Token reduction ≥30% validation
- Latency improvement ≥20% validation  
- Quality preservation during optimization
- Variance reduction measurement
- Resource utilization monitoring

### Security Tests
- Authentication and authorization
- Input validation and injection prevention
- Data encryption and secure storage
- Audit logging and compliance
- Rate limiting and DDoS protection

### Reliability Tests
- Reproducibility across runs
- Error handling consistency
- Graceful degradation
- Circuit breaker activation
- Rollback mechanism validation

## Integration with CI/CD

The BDD test suite is designed for integration with continuous integration:

```yaml
# Example GitHub Actions integration
- name: Run GENESIS BDD Tests
  run: |
    pip install -r requirements.txt
    behave --tags=-@manual --junit --junit-directory=test-reports
```

### Test Reporting

Tests can generate various report formats:
- JUnit XML for CI integration
- Allure reports for detailed analysis
- HTML reports for manual review
- JSON output for programmatic analysis

## Extending the Test Suite

### Adding New Scenarios

1. Add scenarios to appropriate `.feature` files
2. Implement step definitions in corresponding `*_steps.py` files
3. Update mock framework if new components are needed
4. Add appropriate tags for test organization

### Creating Custom Steps

```python
@given('I have a custom test condition')
def step_custom_condition(context):
    # Implementation here
    pass

@when('I perform a custom action')  
def step_custom_action(context):
    # Implementation here
    pass

@then('I should see custom results')
def step_custom_verification(context):
    # Implementation here
    assert condition, "Custom assertion message"
```

## Best Practices

1. **Use descriptive scenario names** that clearly state the business value
2. **Keep steps atomic** - each step should test one specific behavior
3. **Use appropriate tags** to organize and filter tests
4. **Include both positive and negative test cases**
5. **Test edge cases and error conditions**
6. **Validate both functional and non-functional requirements**
7. **Maintain clear separation** between test logic and implementation details

## Troubleshooting

### Common Issues

1. **Missing dependencies**: Ensure all packages in `requirements.txt` are installed
2. **Import errors**: Verify `framework_init.py` is properly configured
3. **Test timeouts**: Adjust timeout settings in `behave.ini`
4. **Mock failures**: Check mock configurations in step definitions

### Debug Mode

Run tests in debug mode for detailed output:
```bash
behave --no-capture --verbose --stop
```

## Contributing

When adding new test scenarios:

1. Follow the existing Gherkin style and patterns
2. Add appropriate step definitions with clear assertions
3. Include both positive and negative test cases
4. Update documentation as needed
5. Ensure all tests pass before submitting changes

## Related Documentation

- [GENESIS Evaluation Specification](../GENESIS_EVAL_SPEC.md)
- [Product Requirements Document](../docs/PRD-GENESIS.md)  
- [Acceptance Criteria](../artifacts/acceptance.json)
- [Router Configuration](../config/router.config.json)
- [Orchestrator Pseudocode](../code/orchestrator_pseudocode.py)