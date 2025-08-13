Feature: RCR (Role-Aware Context Routing) Efficiency
  As a GENESIS orchestrator user
  I want the RCR router to optimize context delivery based on role-specific needs
  So that token usage and latency are minimized while maintaining answer quality

  Background:
    Given the GENESIS orchestrator is initialized
    And the RCR router is configured with role-specific budgets
    And token budgets are set according to router.config.json
    And importance scoring is enabled with signals "role_keywords", "task_stage", "recency"
    And semantic filtering is configured with topk=12 and min_sim=0.35

  @rcr @budget @allocation
  Scenario: Role-specific token budget allocation
    Given the router configuration defines budgets:
      | Role      | Budget |
      | Planner   | 1536   |
      | Retriever | 1024   |
      | Solver    | 1024   |
      | Critic    | 1024   |
      | Verifier  | 1536   |
      | Rewriter  | 768    |
    When I request context routing for "Planner" role
    Then the allocated budget should be 1536 tokens
    And the router should enforce this budget strictly
    And context selection should respect the token limit

  @rcr @importance @scoring
  Scenario: Importance scoring based on role keywords
    Given the memory contains documents with various role-specific keywords
    And I am routing context for the "Solver" role
    When the importance scorer evaluates documents
    Then documents containing "solution", "answer", "resolve" should score higher
    And documents with "plan", "decompose" should score lower for Solver
    And the scoring should use role_keywords signal
    And tie-breaking should be done by document ID

  @rcr @efficiency @token-reduction
  Scenario: Token reduction compared to full context
    Given I have a memory store with 50 documents totaling 10,000 tokens
    And I need context for a "Retriever" role with budget 1024 tokens
    When the RCR router selects context
    Then the selected context should be within 1024 token budget
    And token usage should be reduced by at least 80% compared to full context
    And the reduction percentage should be logged in router_metrics.json
    And quality metrics should be maintained or improved

  @rcr @latency @improvement
  Scenario: Latency improvement through efficient routing
    Given I have baseline latency measurements for full context processing
    When I process the same queries using RCR routing
    Then the p50 latency should be reduced by at least 20%
    And the p95 latency should show improvement
    And latency measurements should be recorded in execution_trace.ndjson
    And the improvement should be statistically significant

  @rcr @semantic @filtering
  Scenario: Semantic filtering with similarity thresholds
    Given the semantic filter is configured with min_sim=0.35
    And I have a query about "machine learning algorithms"
    When the router applies semantic filtering
    Then only documents with similarity >= 0.35 should be considered
    And the top 12 most similar documents should be selected
    And documents below threshold should be filtered out
    And the filtering should be logged with similarity scores

  @rcr @comparison @baselines
  Scenario: RCR vs Full Context vs Static Routing comparison
    Given I have a test question "What are the implications of quantum computing on cryptography?"
    And I have baseline measurements for full context approach
    And I have baseline measurements for static routing approach
    When I process the question using RCR routing
    Then RCR token usage should be less than full context baseline
    And RCR latency should be less than both baselines
    And RCR answer quality should be greater than or equal to baselines
    And all metrics should be recorded for comparison

  @rcr @quality @maintenance
  Scenario: Quality preservation with reduced context
    Given I have oracle answers for a set of benchmark questions
    And I process these questions using full context (baseline)
    And I process the same questions using RCR routing
    When I compare the answer quality metrics
    Then RCR accuracy should be >= baseline accuracy
    And RCR F1 score should be >= baseline F1 score
    And RCR should not introduce quality degradation
    And quality metrics should be within 5% of baseline

  @rcr @greedy @selection
  Scenario: Greedy top-k selection within budget constraints
    Given I have memory documents with computed importance scores
    And the budget limit is 1024 tokens for current role
    When the router performs greedy selection
    Then it should select highest scoring documents first
    And it should stop when adding next document would exceed budget
    And the selection should maximize total importance score
    And the selection process should be deterministic

  @rcr @recency @signal
  Scenario: Recency signal in importance scoring
    Given I have documents from different time periods:
      | Document | Age (hours) | Base Score |
      | Doc1     | 1           | 0.8        |
      | Doc2     | 24          | 0.8        |
      | Doc3     | 168         | 0.8        |
    When the importance scorer includes recency signal
    Then Doc1 should have the highest final score
    And recency boost should decay with age
    And the recency factor should be configurable

  @rcr @stage @awareness
  Scenario: Task stage awareness in routing
    Given I am in the "planning" stage of task execution
    And memory contains documents tagged for different stages
    When the router selects context
    Then documents relevant to planning stage should be prioritized
    And stage-specific keywords should boost importance scores
    And stage awareness should be reflected in selection

  @rcr @memory @efficiency
  Scenario: Memory context slicing for different roles
    Given I have a memory store with mixed content types
    When I request context for "Verifier" role
    Then the router should slice memory relevant to verification
    And verification-related documents should be prioritized
    And the slice should fit within Verifier's token budget
    And irrelevant content should be filtered out

  @rcr @deterministic @routing
  Scenario: Deterministic routing with consistent tie-breaking
    Given I have documents with identical importance scores
    And the tie-breaker is set to "id"
    When I route context multiple times with same inputs
    Then the selected documents should be identical across runs
    And the order should be consistent
    And tie-breaking should follow configured rule
    And routing should be reproducible

  @rcr @budget @overflow
  Scenario: Budget overflow handling
    Given I have a document that exceeds the entire role budget
    When the router attempts to select context
    Then it should skip the oversized document
    And select the next highest scoring documents that fit
    And log a warning about the oversized document
    And still provide useful context within budget

  @rcr @metrics @reporting
  Scenario: Comprehensive routing metrics collection
    Given I process a question through RCR routing
    When routing completes
    Then router_metrics.json should contain:
      | Metric                    | Description                           |
      | budget_per_role          | Token budgets for each role           |
      | selected_documents       | IDs of selected documents per role    |
      | importance_scores        | Computed importance scores            |
      | token_savings_percentage | Percentage saved vs full context      |
      | selection_time_ms        | Time taken for context selection     |
      | total_selected_tokens    | Total tokens in selected context     |
    And all metrics should be properly formatted and timestamped

  @rcr @stress @performance
  Scenario: RCR performance under high memory load
    Given I have a memory store with 10,000 documents
    And total memory size is 1,000,000 tokens
    When I request context routing for any role
    Then the router should complete selection within 5 seconds
    And memory usage should remain within acceptable limits
    And selection quality should not degrade with scale
    And performance metrics should be recorded

  @rcr @adaptive @learning
  Scenario: Router effectiveness measurement over time
    Given I have historical routing decisions and outcomes
    When I analyze router effectiveness metrics
    Then I should be able to identify optimal importance weights
    And track quality trends over time
    And detect when routing strategy needs adjustment
    And measure correlation between context selection and answer quality