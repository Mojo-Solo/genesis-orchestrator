Feature: Meta-Learning Cycle Validation
  As a GENESIS orchestrator user
  I want the system to continuously learn and improve through meta-analysis
  So that performance and accuracy improve over time through self-optimization

  Background:
    Given the GENESIS orchestrator is running with meta-learning enabled
    And execution traces are being collected
    And performance baselines are established
    And the meta-analysis engine is configured
    And A/B testing infrastructure is available

  @meta-learning @bottleneck @detection @critical
  Scenario: Automated bottleneck detection from logs
    Given the system has processed 100 queries with execution traces
    And some queries show consistently high latency in specific components
    When the meta-analysis engine analyzes the traces
    Then bottlenecks should be automatically identified
    And the bottleneck analysis should include:
      | Component           | Issue Type               | Frequency | Impact     |
      | Router saturation   | High token selection time | 25%       | High       |
      | Terminator misses   | False positive stops     | 15%       | Medium     |
      | Prompt bloat        | Excessive token usage    | 40%       | High       |
      | Flaky tools         | Retry failures           | 10%       | Low        |
    And bottleneck severity should be ranked by impact

  @meta-learning @proposal @generation
  Scenario: Automated improvement proposal generation
    Given bottleneck analysis has identified router saturation issues
    When the meta-analysis engine generates improvement proposals
    Then a specific proposal should be created with:
      | Field       | Content                                    |
      | Change      | Increase semantic_filter.topk from 12 to 8 |
      | Hypothesis  | Reduce selection time by 30%              |
      | Risk Level  | Low                                       |
      | Test Plan   | A/B test with 50 queries                  |
      | Metrics     | Latency, accuracy, token usage            |
      | Rollback    | Revert topk to 12 if accuracy drops >2%  |
    And the proposal should be actionable and specific

  @meta-learning @sandbox @testing
  Scenario: Sandbox A/B testing of improvements
    Given I have a proposal to add a "Math-Specialist" role
    And the proposal includes small token budget allocation
    When the sandbox A/B testing is executed
    Then the test should run with:
      | Group     | Configuration                | Expected Outcome    |
      | Control   | Standard 6-role setup       | Baseline metrics    |
      | Treatment | 7-role setup with Math      | Improved math accuracy |
    And metrics should be collected for:
      | Metric          | Control | Treatment | Delta    |
      | Accuracy        | 85%     | 89%      | +4%      |
      | Token usage     | 100%    | 105%     | +5%      |
      | Retry rate      | 12%     | 8%       | -4%      |
      | Variance        | 2.1%    | 1.8%     | -0.3%    |
    And statistical significance should be calculated

  @meta-learning @cycle @completion
  Scenario: Complete meta-learning cycle execution
    Given the system has identified improvement opportunities
    When a full meta-learning cycle is executed
    Then the cycle should complete these phases:
      | Phase            | Duration | Deliverable                    |
      | Log Analysis     | 5 min    | Bottleneck report              |
      | Proposal Gen     | 2 min    | Improvement proposals          |
      | Sandbox Test     | 30 min   | A/B test results               |
      | Decision Making  | 1 min    | Adopt/rollback decision        |
      | Deployment       | 5 min    | Updated configuration          |
      | Validation       | 15 min   | Performance verification       |
    And each phase should produce required artifacts

  @meta-learning @performance @improvement
  Scenario: Measurable performance improvement validation
    Given baseline performance metrics are established
    And a meta-learning improvement has been deployed
    When performance is measured post-deployment
    Then improvements should be measurable:
      | Metric                | Baseline | Post-Improvement | Required Delta |
      | Average accuracy      | 87%      | >= 89%          | >= +2%         |
      | Token efficiency      | 100%     | <= 95%          | >= -5%         |
      | Average latency (ms)  | 1200     | <= 1080         | >= -10%        |
      | Stability variance    | 1.8%     | <= 1.4%         | >= -0.4%       |
    And improvements should persist over time
    And no regression should occur in other metrics

  @meta-learning @dead-logic @elimination
  Scenario: Dead logic detection and removal
    Given the system has accumulated unused code paths
    When dead logic analysis is performed
    Then unused components should be identified:
      | Component Type      | Status  | Action              |
      | Deprecated prompts  | Unused  | Mark for removal    |
      | Redundant steps     | Bypass  | Optimize flow       |
      | Unused tool calls   | Dead    | Clean up            |
      | Obsolete routes     | Stale   | Update or remove    |
    And removal should be tested before deployment
    And performance impact should be minimal

  @meta-learning @prompt @optimization
  Scenario: Prompt bloat reduction through analysis
    Given prompts have grown over time through iterations
    When prompt analysis identifies bloat
    Then optimization should reduce token usage:
      | Role       | Original Tokens | Optimized Tokens | Reduction |
      | Planner    | 2048           | 1536             | 25%       |
      | Retriever  | 1280           | 1024             | 20%       |
      | Solver     | 1536           | 1024             | 33%       |
      | Critic     | 1280           | 1024             | 20%       |
      | Verifier   | 2048           | 1536             | 25%       |
      | Rewriter   | 1024           | 768              | 25%       |
    And quality should be maintained or improved
    And optimization should be validated with test cases

  @meta-learning @routing @waste @reduction
  Scenario: Routing waste identification and optimization
    Given the RCR router shows inefficient selections
    When routing analysis identifies waste patterns
    Then optimization opportunities should be found:
      | Waste Type           | Frequency | Impact | Solution                    |
      | Irrelevant docs      | 23%       | Medium | Improve semantic filtering  |
      | Redundant selections | 15%       | Low    | Better deduplication        |
      | Budget underuse      | 18%       | Medium | Dynamic budget adjustment   |
      | Poor role matching   | 12%       | High   | Enhanced role keywords      |
    And waste reduction should be prioritized by impact

  @meta-learning @tool @reliability
  Scenario: Flaky tool identification and mitigation
    Given some external tools show inconsistent behavior
    When tool reliability analysis is performed
    Then problematic tools should be identified:
      | Tool Name    | Success Rate | Avg Latency | Failure Mode     | Mitigation       |
      | SearchAPI    | 85%          | 2.3s        | Timeout          | Increase timeout |
      | DocRetriever | 92%          | 1.1s        | Rate limit       | Add backoff      |
      | Calculator   | 78%          | 0.5s        | Parse error      | Input validation |
      | Translator   | 95%          | 1.8s        | Service down     | Fallback service |
    And mitigation strategies should be implemented
    And tool performance should be continuously monitored

  @meta-learning @adaptive @configuration
  Scenario: Adaptive configuration based on workload patterns
    Given the system processes different types of queries
    When workload analysis identifies patterns
    Then configuration should adapt to optimize for common patterns:
      | Query Type     | Frequency | Optimal Config              | Expected Improvement |
      | Math problems  | 35%       | Add Math-Specialist role    | +15% accuracy        |
      | Multi-hop QA   | 40%       | Increase Planner budget     | +10% decomposition   |
      | Simple lookup  | 20%       | Skip decomposition          | -50% latency         |
      | Complex reason | 5%        | Increase all role budgets   | +8% accuracy         |
    And adaptation should be gradual and tested
    And user feedback should influence optimization

  @meta-learning @feedback @integration
  Scenario: User feedback integration into meta-learning
    Given users provide feedback on answer quality
    When feedback is integrated into meta-learning
    Then feedback should influence optimization:
      | Feedback Type      | Weight | Impact on Meta-Learning        |
      | Accuracy rating    | High   | Adjust quality thresholds      |
      | Speed satisfaction | Medium | Optimize latency targets       |
      | Completeness       | High   | Improve decomposition depth    |
      | Relevance          | Medium | Enhance routing effectiveness  |
    And feedback trends should drive systematic improvements
    And user satisfaction metrics should increase over time

  @meta-learning @variance @reduction
  Scenario: Systematic variance reduction through learning
    Given the system shows performance variance across runs
    When variance analysis identifies sources
    Then variance reduction strategies should be implemented:
      | Variance Source    | Current Level | Target Level | Strategy                |
      | Router selection   | 2.3%          | <= 1.4%      | Better tie-breaking     |
      | Tool response time | 3.1%          | <= 2.0%      | Consistent timeouts     |
      | Model outputs      | 1.8%          | <= 1.4%      | Lower temperature       |
      | Memory state       | 1.2%          | <= 1.0%      | Deterministic ordering  |
    And variance should consistently decrease over time
    And the 98.6% stability target should be maintained

  @meta-learning @rollback @safety
  Scenario: Safe rollback mechanism for failed improvements
    Given a meta-learning improvement has been deployed (monitoring configured)
    And performance monitoring detects degradation
    When rollback conditions are met
    Then automatic rollback should be triggered:
      | Condition              | Threshold | Action              |
      | Accuracy drop          | > 2%      | Immediate rollback  |
      | Latency increase       | > 20%     | Immediate rollback  |
      | Error rate increase    | > 5%      | Immediate rollback  |
      | Stability degradation  | < 95%     | Immediate rollback  |
    And rollback should restore previous configuration
    And incident should be logged for analysis
    And rollback effectiveness should be verified

  @meta-learning @report @generation
  Scenario: Automated meta-report generation
    Given a meta-learning cycle has completed
    When the meta-report is generated
    Then the report should contain comprehensive analysis:
      | Section              | Content                               |
      | Run Metadata         | run_id, model, seed, timestamp       |
      | Bottleneck Analysis  | Identified issues and impact          |
      | Improvement Proposal | Specific changes and hypothesis       |
      | A/B Test Results     | Before/after metrics with significance |
      | Decision Rationale   | Adopt/rollback with evidence          |
      | Performance Impact   | Measured improvements or regressions  |
      | Next Steps           | Recommended follow-up actions         |
    And the report should be stored in artifacts/meta_report.md
    And report should be human-readable and actionable