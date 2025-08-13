"""
Step definitions for RCR (Role-Aware Context Routing) efficiency testing.
"""

from behave import given, when, then, step
from genesis_test_framework import RCRRouter, RouterMetrics, TestContext
import json
import time
import statistics


@given('the RCR router is configured with role-specific budgets')
def step_configure_rcr_router(context):
    """Configure RCR router with role-specific budgets."""
    context.rcr_router = RCRRouter()
    context.rcr_router.load_config_from_file('config/router.config.json')
    assert context.rcr_router.is_configured()


@given('token budgets are set according to router.config.json')
def step_set_token_budgets(context):
    """Set token budgets from configuration."""
    with open('config/router.config.json', 'r') as f:
        config = json.load(f)
    context.token_budgets = config['beta_role']
    context.base_budget = config['beta_base']


@given('importance scoring is enabled with signals "{signals}"')
def step_enable_importance_scoring(context, signals):
    """Enable importance scoring with specified signals."""
    signal_list = [s.strip().strip('"') for s in signals.split(',')]
    context.rcr_router.configure_importance_scoring(signal_list)
    context.importance_signals = signal_list


@given('semantic filtering is configured with topk={topk:d} and min_sim={min_sim:f}')
def step_configure_semantic_filtering(context, topk, min_sim):
    """Configure semantic filtering parameters."""
    context.rcr_router.configure_semantic_filter(topk=topk, min_sim=min_sim)
    context.semantic_topk = topk
    context.semantic_min_sim = min_sim


@given('the router configuration defines budgets')
def step_define_router_budgets(context):
    """Define router budgets from table."""
    context.budget_table = {}
    for row in context.table:
        role = row['Role']
        budget = int(row['Budget'])
        context.budget_table[role] = budget


@given('the memory contains documents with various role-specific keywords')
def step_setup_role_documents(context):
    """Setup memory with role-specific keyword documents."""
    context.memory_store = context.test_context.create_memory_store_with_role_keywords()
    context.rcr_router.set_memory_store(context.memory_store)


@given('I am routing context for the "{role}" role')
def step_set_routing_role(context, role):
    """Set the role for context routing."""
    context.current_role = role
    context.current_budget = context.token_budgets.get(role, context.base_budget)


@given('I have a memory store with {doc_count:d} documents totaling {total_tokens:d} tokens')
def step_setup_memory_store(context, doc_count, total_tokens):
    """Setup memory store with specified documents and tokens."""
    context.memory_store = context.test_context.create_memory_store(doc_count, total_tokens)
    context.rcr_router.set_memory_store(context.memory_store)
    context.total_available_tokens = total_tokens


@given('I need context for a "{role}" role with budget {budget:d} tokens')
def step_set_role_budget_need(context, role, budget):
    """Set role and budget requirement."""
    context.target_role = role
    context.target_budget = budget


@given('I have baseline latency measurements for full context processing')
def step_setup_baseline_latency(context):
    """Setup baseline latency measurements."""
    context.baseline_latency = context.test_context.get_full_context_baseline_latency()


@given('the semantic filter is configured with min_sim={min_sim:f}')
def step_configure_semantic_min_sim(context, min_sim):
    """Configure semantic minimum similarity."""
    context.rcr_router.set_semantic_min_sim(min_sim)
    context.semantic_min_sim = min_sim


@given('I have a query about "{query_topic}"')
def step_set_query_topic(context, query_topic):
    """Set query topic for semantic filtering."""
    context.query_topic = query_topic
    context.current_query = f"Question about {query_topic}"


@given('I have a test question "{question}"')
def step_set_test_question(context, question):
    """Set test question for comparison."""
    context.test_question = question


@given('I have baseline measurements for full context approach')
def step_setup_full_context_baseline(context):
    """Setup full context baseline measurements."""
    context.full_context_baseline = context.test_context.get_full_context_metrics(context.test_question)


@given('I have baseline measurements for static routing approach')
def step_setup_static_routing_baseline(context):
    """Setup static routing baseline measurements."""
    context.static_routing_baseline = context.test_context.get_static_routing_metrics(context.test_question)


@given('I have oracle answers for a set of benchmark questions')
def step_setup_oracle_answers(context):
    """Setup oracle answers for benchmark."""
    context.benchmark_questions = context.test_context.get_benchmark_questions_with_oracles()
    context.oracle_answers = {q.id: q.oracle for q in context.benchmark_questions}


@given('I process these questions using full context (baseline)')
def step_process_full_context_baseline(context):
    """Process questions using full context as baseline."""
    context.full_context_results = {}
    for question in context.benchmark_questions:
        result = context.test_context.process_with_full_context(question)
        context.full_context_results[question.id] = result


@given('I process the same questions using RCR routing')
def step_process_rcr_routing(context):
    """Process same questions using RCR routing."""
    context.rcr_results = {}
    for question in context.benchmark_questions:
        result = context.rcr_router.process_with_routing(question)
        context.rcr_results[question.id] = result


@given('I have memory documents with computed importance scores')
def step_setup_scored_documents(context):
    """Setup documents with computed importance scores."""
    context.scored_documents = context.test_context.create_scored_documents()
    context.rcr_router.set_memory_store(context.scored_documents)


@given('the budget limit is {budget:d} tokens for current role')
def step_set_budget_limit(context, budget):
    """Set budget limit for current role."""
    context.current_budget_limit = budget
    context.rcr_router.set_role_budget(context.current_role, budget)


@given('I have documents from different time periods')
def step_setup_temporal_documents(context):
    """Setup documents from different time periods."""
    context.temporal_documents = {}
    for row in context.table:
        doc_name = row['Document']
        age_hours = int(row['Age (hours)'])
        base_score = float(row['Base Score'])
        context.temporal_documents[doc_name] = {
            'age_hours': age_hours,
            'base_score': base_score
        }
    context.test_context.setup_temporal_memory(context.temporal_documents)


@given('I am in the "{stage}" stage of task execution')
def step_set_task_stage(context, stage):
    """Set current task execution stage."""
    context.current_stage = stage
    context.rcr_router.set_task_stage(stage)


@given('memory contains documents tagged for different stages')
def step_setup_stage_documents(context):
    """Setup documents tagged for different execution stages."""
    context.stage_documents = context.test_context.create_stage_tagged_documents()
    context.rcr_router.set_memory_store(context.stage_documents)


@given('I have a memory store with mixed content types')
def step_setup_mixed_content(context):
    """Setup memory with mixed content types."""
    context.mixed_memory = context.test_context.create_mixed_content_memory()
    context.rcr_router.set_memory_store(context.mixed_memory)


@given('I have documents with identical importance scores')
def step_setup_identical_scores(context):
    """Setup documents with identical importance scores."""
    context.tied_documents = context.test_context.create_documents_with_identical_scores()
    context.rcr_router.set_memory_store(context.tied_documents)


@given('the tie-breaker is set to "{tie_breaker}"')
def step_set_tie_breaker(context, tie_breaker):
    """Set tie-breaker rule."""
    context.rcr_router.set_tie_breaker(tie_breaker)
    context.tie_breaker_rule = tie_breaker


@given('I have a document that exceeds the entire role budget')
def step_setup_oversized_document(context):
    """Setup document that exceeds role budget."""
    context.oversized_doc = context.test_context.create_oversized_document(
        context.current_budget_limit * 2
    )
    context.memory_store.add_document(context.oversized_doc)


@given('I have a memory store with {doc_count:d} documents')
def step_setup_large_memory(context, doc_count):
    """Setup large memory store for stress testing."""
    context.large_memory = context.test_context.create_large_memory_store(doc_count)
    context.rcr_router.set_memory_store(context.large_memory)


@given('total memory size is {total_size:d} tokens')
def step_set_total_memory_size(context, total_size):
    """Set total memory size."""
    context.total_memory_size = total_size
    context.large_memory.set_total_size(total_size)


@given('I have historical routing decisions and outcomes')
def step_setup_historical_data(context):
    """Setup historical routing decisions and outcomes."""
    context.historical_data = context.test_context.get_historical_routing_data()


@when('I request context routing for "{role}" role')
def step_request_context_routing(context, role):
    """Request context routing for specified role."""
    context.routing_start_time = time.time()
    context.routing_result = context.rcr_router.route_context_for_role(role)
    context.routing_end_time = time.time()
    context.routing_time = context.routing_end_time - context.routing_start_time


@when('the importance scorer evaluates documents')
def step_evaluate_document_importance(context):
    """Evaluate document importance scores."""
    context.importance_scores = context.rcr_router.compute_importance_scores(
        context.current_role
    )


@when('the RCR router selects context')
def step_select_rcr_context(context):
    """Select context using RCR router."""
    context.selected_context = context.rcr_router.select_context(
        role=context.target_role,
        budget=context.target_budget
    )


@when('I process the same queries using RCR routing')
def step_process_queries_rcr(context):
    """Process queries using RCR routing."""
    context.rcr_processing_results = []
    for query in context.baseline_latency.queries:
        start_time = time.time()
        result = context.rcr_router.process_query(query)
        end_time = time.time()
        result.latency = end_time - start_time
        context.rcr_processing_results.append(result)


@when('the router applies semantic filtering')
def step_apply_semantic_filtering(context):
    """Apply semantic filtering to documents."""
    context.filtered_results = context.rcr_router.apply_semantic_filter(
        context.current_query,
        context.semantic_min_sim
    )


@when('I process the question using RCR routing')
def step_process_question_rcr(context):
    """Process question using RCR routing."""
    context.rcr_question_result = context.rcr_router.process_question(context.test_question)


@when('I compare the answer quality metrics')
def step_compare_quality_metrics(context):
    """Compare answer quality metrics."""
    context.quality_comparison = context.test_context.compare_quality_metrics(
        context.full_context_results,
        context.rcr_results,
        context.oracle_answers
    )


@when('the router performs greedy selection')
def step_perform_greedy_selection(context):
    """Perform greedy selection within budget."""
    context.greedy_result = context.rcr_router.greedy_select_within_budget(
        context.scored_documents,
        context.current_budget_limit
    )


@when('the importance scorer includes recency signal')
def step_include_recency_signal(context):
    """Include recency signal in importance scoring."""
    context.recency_scores = context.rcr_router.compute_importance_with_recency(
        context.temporal_documents
    )


@when('the router selects context')
def step_router_selects_context(context):
    """Router selects context for current stage."""
    context.stage_selection = context.rcr_router.select_context_for_stage(
        context.current_stage
    )


@when('I request context for "{role}" role')
def step_request_role_context(context, role):
    """Request context for specific role."""
    context.role_context = context.rcr_router.get_context_for_role(role)


@when('I route context multiple times with same inputs')
def step_route_multiple_times(context):
    """Route context multiple times with identical inputs."""
    context.multiple_routing_results = []
    for i in range(5):  # Route 5 times
        result = context.rcr_router.route_context_deterministic(
            context.current_role,
            context.current_budget_limit
        )
        context.multiple_routing_results.append(result)


@when('the router attempts to select context')
def step_attempt_context_selection(context):
    """Attempt context selection with oversized document."""
    context.overflow_result = context.rcr_router.select_context_with_overflow_handling(
        context.current_role,
        context.current_budget_limit
    )


@when('routing completes')
def step_routing_completes(context):
    """Mark routing as completed."""
    context.routing_completed = True
    context.final_metrics = context.rcr_router.get_routing_metrics()


@when('I request context routing for any role')
def step_request_any_role_routing(context):
    """Request context routing for any role (stress test)."""
    context.stress_start_time = time.time()
    context.stress_result = context.rcr_router.route_context_stress_test()
    context.stress_end_time = time.time()
    context.stress_duration = context.stress_end_time - context.stress_start_time


@when('I analyze router effectiveness metrics')
def step_analyze_effectiveness(context):
    """Analyze router effectiveness over time."""
    context.effectiveness_analysis = context.test_context.analyze_routing_effectiveness(
        context.historical_data
    )


@then('the allocated budget should be {expected_budget:d} tokens')
def step_verify_allocated_budget(context, expected_budget):
    """Verify allocated budget matches expected value."""
    actual_budget = context.routing_result.allocated_budget
    assert actual_budget == expected_budget
    context.test_context.log(f"Budget correctly allocated: {actual_budget} tokens")


@then('the router should enforce this budget strictly')
def step_verify_budget_enforcement(context):
    """Verify budget is strictly enforced."""
    total_selected_tokens = context.routing_result.total_selected_tokens
    allocated_budget = context.routing_result.allocated_budget
    assert total_selected_tokens <= allocated_budget
    context.test_context.log(f"Budget enforced: {total_selected_tokens} <= {allocated_budget}")


@then('context selection should respect the token limit')
def step_verify_token_limit_respect(context):
    """Verify context selection respects token limits."""
    assert context.routing_result.respects_token_limit()


@then('documents containing "{keywords}" should score higher')
def step_verify_keyword_scoring(context, keywords):
    """Verify documents with specific keywords score higher."""
    keyword_list = [k.strip().strip('"') for k in keywords.split(',')]
    high_scoring_docs = context.importance_scores.get_top_documents(0.7)  # Top 30%
    
    keyword_matches = 0
    for doc in high_scoring_docs:
        if any(keyword.lower() in doc.content.lower() for keyword in keyword_list):
            keyword_matches += 1
    
    # At least 60% of high-scoring docs should contain relevant keywords
    match_ratio = keyword_matches / len(high_scoring_docs)
    assert match_ratio >= 0.6
    context.test_context.log(f"Keyword matching ratio: {match_ratio:.2%}")


@then('documents with "{keywords}" should score lower for {role}')
def step_verify_irrelevant_keyword_scoring(context, keywords, role):
    """Verify irrelevant keywords score lower for specific role."""
    keyword_list = [k.strip().strip('"') for k in keywords.split(',')]
    low_scoring_docs = context.importance_scores.get_bottom_documents(0.3)  # Bottom 30%
    
    irrelevant_matches = 0
    for doc in low_scoring_docs:
        if any(keyword.lower() in doc.content.lower() for keyword in keyword_list):
            irrelevant_matches += 1
    
    # Irrelevant keywords should be more common in low-scoring docs
    match_ratio = irrelevant_matches / len(low_scoring_docs)
    assert match_ratio >= 0.4
    context.test_context.log(f"Irrelevant keyword ratio in low scores: {match_ratio:.2%}")


@then('the scoring should use {signal} signal')
def step_verify_signal_usage(context, signal):
    """Verify specific signal is used in scoring."""
    scoring_details = context.importance_scores.get_scoring_details()
    assert signal in scoring_details.signals_used
    context.test_context.log(f"Signal '{signal}' used in scoring")


@then('tie-breaking should be done by document ID')
def step_verify_tie_breaking_by_id(context):
    """Verify tie-breaking is done by document ID."""
    tied_scores = context.importance_scores.get_tied_scores()
    for score_group in tied_scores:
        if len(score_group) > 1:
            ids = [doc.id for doc in score_group]
            assert ids == sorted(ids)  # Should be sorted by ID
    context.test_context.log("Tie-breaking by ID verified")


@then('the selected context should be within {budget:d} token budget')
def step_verify_context_within_budget(context, budget):
    """Verify selected context is within budget."""
    selected_tokens = context.selected_context.total_tokens
    assert selected_tokens <= budget
    context.test_context.log(f"Selected context: {selected_tokens} <= {budget} tokens")


@then('token usage should be reduced by at least {reduction:d}% compared to full context')
def step_verify_token_reduction(context, reduction):
    """Verify token usage reduction."""
    selected_tokens = context.selected_context.total_tokens
    full_context_tokens = context.total_available_tokens
    actual_reduction = ((full_context_tokens - selected_tokens) / full_context_tokens) * 100
    
    assert actual_reduction >= reduction
    context.test_context.log(f"Token reduction: {actual_reduction:.1f}% >= {reduction}%")


@then('the reduction percentage should be logged in router_metrics.json')
def step_verify_reduction_logged(context):
    """Verify reduction percentage is logged."""
    metrics = context.rcr_router.get_metrics()
    assert 'token_reduction_percentage' in metrics
    assert metrics['token_reduction_percentage'] is not None


@then('quality metrics should be maintained or improved')
def step_verify_quality_maintained(context):
    """Verify quality is maintained or improved."""
    quality_metrics = context.selected_context.quality_metrics
    baseline_quality = context.test_context.get_baseline_quality()
    
    assert quality_metrics.accuracy >= baseline_quality.accuracy
    assert quality_metrics.relevance >= baseline_quality.relevance
    context.test_context.log("Quality metrics maintained or improved")


@then('the p50 latency should be reduced by at least {reduction:d}%')
def step_verify_p50_latency_reduction(context, reduction):
    """Verify p50 latency reduction."""
    rcr_latencies = [r.latency for r in context.rcr_processing_results]
    baseline_latencies = context.baseline_latency.p50_values
    
    rcr_p50 = statistics.median(rcr_latencies)
    baseline_p50 = statistics.median(baseline_latencies)
    
    actual_reduction = ((baseline_p50 - rcr_p50) / baseline_p50) * 100
    assert actual_reduction >= reduction
    context.test_context.log(f"P50 latency reduction: {actual_reduction:.1f}% >= {reduction}%")


@then('the p95 latency should show improvement')
def step_verify_p95_improvement(context):
    """Verify p95 latency improvement."""
    rcr_latencies = [r.latency for r in context.rcr_processing_results]
    baseline_latencies = context.baseline_latency.p95_values
    
    rcr_p95 = statistics.quantiles(rcr_latencies, n=20)[18]  # 95th percentile
    baseline_p95 = statistics.quantiles(baseline_latencies, n=20)[18]
    
    assert rcr_p95 < baseline_p95
    improvement = ((baseline_p95 - rcr_p95) / baseline_p95) * 100
    context.test_context.log(f"P95 latency improvement: {improvement:.1f}%")


@then('latency measurements should be recorded in execution_trace.ndjson')
def step_verify_latency_recorded(context):
    """Verify latency measurements are recorded in trace."""
    trace = context.rcr_router.get_execution_trace()
    for entry in trace:
        assert 'latency_ms' in entry
        assert entry['latency_ms'] is not None


@then('the improvement should be statistically significant')
def step_verify_statistical_significance(context):
    """Verify improvement is statistically significant."""
    rcr_latencies = [r.latency for r in context.rcr_processing_results]
    baseline_latencies = context.baseline_latency.values
    
    # Simple t-test for significance
    significance = context.test_context.calculate_statistical_significance(
        rcr_latencies, baseline_latencies
    )
    assert significance.p_value < 0.05  # 95% confidence
    context.test_context.log(f"Statistical significance: p={significance.p_value:.4f}")


@then('only documents with similarity >= {min_sim:f} should be considered')
def step_verify_similarity_threshold(context, min_sim):
    """Verify similarity threshold is applied."""
    for doc in context.filtered_results.considered_documents:
        assert doc.similarity >= min_sim
    context.test_context.log(f"All considered docs >= {min_sim} similarity")


@then('the top {topk:d} most similar documents should be selected')
def step_verify_topk_selection(context, topk):
    """Verify top-k selection."""
    selected_count = len(context.filtered_results.selected_documents)
    available_count = len(context.filtered_results.considered_documents)
    expected_count = min(topk, available_count)
    
    assert selected_count == expected_count
    context.test_context.log(f"Selected {selected_count} documents (top-{topk})")


@then('documents below threshold should be filtered out')
def step_verify_below_threshold_filtered(context):
    """Verify documents below threshold are filtered."""
    all_docs = context.memory_store.get_all_documents()
    considered_docs = context.filtered_results.considered_documents
    
    filtered_count = len(all_docs) - len(considered_docs)
    assert filtered_count >= 0
    context.test_context.log(f"Filtered out {filtered_count} documents below threshold")


@then('the filtering should be logged with similarity scores')
def step_verify_filtering_logged(context):
    """Verify filtering is logged with scores."""
    log_entries = context.filtered_results.log_entries
    for entry in log_entries:
        assert 'similarity_score' in entry
        assert 'filtered' in entry


@then('RCR token usage should be less than full context baseline')
def step_verify_rcr_token_efficiency(context):
    """Verify RCR uses fewer tokens than full context."""
    rcr_tokens = context.rcr_question_result.total_tokens
    baseline_tokens = context.full_context_baseline.total_tokens
    
    assert rcr_tokens < baseline_tokens
    reduction = ((baseline_tokens - rcr_tokens) / baseline_tokens) * 100
    context.test_context.log(f"RCR token reduction: {reduction:.1f}%")


@then('RCR latency should be less than both baselines')
def step_verify_rcr_latency_efficiency(context):
    """Verify RCR latency is better than both baselines."""
    rcr_latency = context.rcr_question_result.latency
    full_context_latency = context.full_context_baseline.latency
    static_routing_latency = context.static_routing_baseline.latency
    
    assert rcr_latency < full_context_latency
    assert rcr_latency < static_routing_latency
    context.test_context.log(f"RCR latency: {rcr_latency}ms vs baselines: {full_context_latency}ms, {static_routing_latency}ms")


@then('RCR answer quality should be greater than or equal to baselines')
def step_verify_rcr_quality(context):
    """Verify RCR answer quality."""
    rcr_quality = context.rcr_question_result.quality_score
    full_context_quality = context.full_context_baseline.quality_score
    static_routing_quality = context.static_routing_baseline.quality_score
    
    assert rcr_quality >= full_context_quality
    assert rcr_quality >= static_routing_quality
    context.test_context.log(f"RCR quality: {rcr_quality:.3f} >= baselines")


@then('all metrics should be recorded for comparison')
def step_verify_metrics_recorded(context):
    """Verify all comparison metrics are recorded."""
    comparison_metrics = context.rcr_question_result.comparison_metrics
    required_metrics = ['token_usage', 'latency', 'quality_score', 'accuracy']
    
    for metric in required_metrics:
        assert metric in comparison_metrics
        assert comparison_metrics[metric] is not None


@then('RCR accuracy should be >= baseline accuracy')
def step_verify_rcr_accuracy(context):
    """Verify RCR accuracy."""
    rcr_accuracy = context.quality_comparison.rcr_accuracy
    baseline_accuracy = context.quality_comparison.baseline_accuracy
    
    assert rcr_accuracy >= baseline_accuracy
    context.test_context.log(f"RCR accuracy: {rcr_accuracy:.3f} >= baseline: {baseline_accuracy:.3f}")


@then('RCR F1 score should be >= baseline F1 score')
def step_verify_rcr_f1(context):
    """Verify RCR F1 score."""
    rcr_f1 = context.quality_comparison.rcr_f1
    baseline_f1 = context.quality_comparison.baseline_f1
    
    assert rcr_f1 >= baseline_f1
    context.test_context.log(f"RCR F1: {rcr_f1:.3f} >= baseline: {baseline_f1:.3f}")


@then('RCR should not introduce quality degradation')
def step_verify_no_quality_degradation(context):
    """Verify no quality degradation with RCR."""
    quality_delta = context.quality_comparison.quality_delta
    assert quality_delta >= -0.02  # Allow max 2% degradation
    context.test_context.log(f"Quality delta: {quality_delta:.3f} (>= -0.02)")


@then('quality metrics should be within {tolerance:d}% of baseline')
def step_verify_quality_tolerance(context, tolerance):
    """Verify quality metrics are within tolerance."""
    for metric_name, values in context.quality_comparison.metric_deltas.items():
        delta_percent = abs(values.delta_percentage)
        assert delta_percent <= tolerance
        context.test_context.log(f"{metric_name} delta: {delta_percent:.1f}% <= {tolerance}%")


@then('it should select highest scoring documents first')
def step_verify_highest_scoring_first(context):
    """Verify highest scoring documents are selected first."""
    selected_docs = context.greedy_result.selected_documents
    scores = [doc.importance_score for doc in selected_docs]
    
    # Verify scores are in descending order
    assert scores == sorted(scores, reverse=True)
    context.test_context.log("Documents selected in descending score order")


@then('it should stop when adding next document would exceed budget')
def step_verify_budget_stopping_condition(context):
    """Verify greedy selection stops at budget limit."""
    selected_tokens = context.greedy_result.total_selected_tokens
    budget_limit = context.current_budget_limit
    next_doc_tokens = context.greedy_result.next_document_tokens
    
    assert selected_tokens <= budget_limit
    assert selected_tokens + next_doc_tokens > budget_limit
    context.test_context.log(f"Stopped at {selected_tokens} tokens (next would exceed {budget_limit})")


@then('the selection should maximize total importance score')
def step_verify_score_maximization(context):
    """Verify selection maximizes total importance score."""
    total_score = context.greedy_result.total_importance_score
    optimal_score = context.test_context.calculate_optimal_score(
        context.scored_documents, context.current_budget_limit
    )
    
    # Should be at least 95% of optimal (allowing for greedy approximation)
    efficiency = total_score / optimal_score
    assert efficiency >= 0.95
    context.test_context.log(f"Score efficiency: {efficiency:.2%}")


@then('the selection process should be deterministic')
def step_verify_deterministic_selection(context):
    """Verify selection process is deterministic."""
    # Run selection again with same inputs
    second_result = context.rcr_router.greedy_select_within_budget(
        context.scored_documents,
        context.current_budget_limit
    )
    
    # Results should be identical
    assert context.greedy_result.selected_document_ids == second_result.selected_document_ids
    context.test_context.log("Selection process is deterministic")


@then('{doc} should have the highest final score')
def step_verify_highest_recency_score(context, doc):
    """Verify document with highest recency has highest final score."""
    scores = context.recency_scores
    doc_scores = [(name, score.final_score) for name, score in scores.items()]
    highest_scoring_doc = max(doc_scores, key=lambda x: x[1])
    
    assert highest_scoring_doc[0] == doc
    context.test_context.log(f"{doc} has highest final score: {highest_scoring_doc[1]:.3f}")


@then('recency boost should decay with age')
def step_verify_recency_decay(context):
    """Verify recency boost decays with document age."""
    scores = context.recency_scores
    
    # Sort by age and verify recency boost decreases
    aged_scores = sorted(
        [(data['age_hours'], score.recency_boost) for data, score in zip(context.temporal_documents.values(), scores.values())],
        key=lambda x: x[0]
    )
    
    for i in range(1, len(aged_scores)):
        assert aged_scores[i][1] <= aged_scores[i-1][1]  # Recency boost should decrease with age
    
    context.test_context.log("Recency boost properly decays with age")


@then('the recency factor should be configurable')
def step_verify_recency_configurable(context):
    """Verify recency factor is configurable."""
    original_factor = context.rcr_router.get_recency_factor()
    
    # Test with different factor
    context.rcr_router.set_recency_factor(0.5)
    new_scores = context.rcr_router.compute_importance_with_recency(context.temporal_documents)
    
    # Restore original factor
    context.rcr_router.set_recency_factor(original_factor)
    
    # Verify scores changed
    assert new_scores != context.recency_scores
    context.test_context.log("Recency factor is configurable")


@then('documents relevant to {stage} stage should be prioritized')
def step_verify_stage_prioritization(context, stage):
    """Verify stage-relevant documents are prioritized."""
    selected_docs = context.stage_selection.selected_documents
    stage_relevant_count = sum(1 for doc in selected_docs if stage.lower() in doc.tags)
    
    # At least 70% of selected docs should be stage-relevant
    relevance_ratio = stage_relevant_count / len(selected_docs)
    assert relevance_ratio >= 0.7
    context.test_context.log(f"Stage relevance ratio: {relevance_ratio:.2%}")


@then('stage-specific keywords should boost importance scores')
def step_verify_stage_keyword_boost(context):
    """Verify stage-specific keywords boost scores."""
    scores = context.stage_selection.importance_scores
    stage_keywords = context.test_context.get_stage_keywords(context.current_stage)
    
    high_scores = [s for s in scores if s.value > scores.median()]
    keyword_matches = sum(1 for score in high_scores 
                         if any(keyword in score.document.content.lower() 
                               for keyword in stage_keywords))
    
    match_ratio = keyword_matches / len(high_scores)
    assert match_ratio >= 0.6
    context.test_context.log(f"Stage keyword boost ratio: {match_ratio:.2%}")


@then('stage awareness should be reflected in selection')
def step_verify_stage_awareness(context):
    """Verify stage awareness in document selection."""
    selection_metadata = context.stage_selection.metadata
    assert selection_metadata.stage_aware == True
    assert selection_metadata.current_stage == context.current_stage


@then('the router should slice memory relevant to verification')
def step_verify_verification_slicing(context):
    """Verify memory slicing for verification role."""
    verification_context = context.role_context
    verification_keywords = ['verify', 'validate', 'check', 'confirm', 'accuracy']
    
    relevant_docs = sum(1 for doc in verification_context.documents
                       if any(keyword in doc.content.lower() for keyword in verification_keywords))
    
    relevance_ratio = relevant_docs / len(verification_context.documents)
    assert relevance_ratio >= 0.5
    context.test_context.log(f"Verification relevance: {relevance_ratio:.2%}")


@then('verification-related documents should be prioritized')
def step_verify_verification_priority(context):
    """Verify verification documents are prioritized."""
    top_docs = context.role_context.get_top_priority_documents(0.3)  # Top 30%
    verification_in_top = sum(1 for doc in top_docs if 'verification' in doc.tags)
    
    priority_ratio = verification_in_top / len(top_docs)
    assert priority_ratio >= 0.6
    context.test_context.log(f"Verification priority ratio: {priority_ratio:.2%}")


@then('the slice should fit within Verifier\'s token budget')
def step_verify_verifier_budget_fit(context):
    """Verify slice fits within Verifier budget."""
    total_tokens = context.role_context.total_tokens
    verifier_budget = context.token_budgets['Verifier']
    
    assert total_tokens <= verifier_budget
    context.test_context.log(f"Verifier context: {total_tokens} <= {verifier_budget} tokens")


@then('irrelevant content should be filtered out')
def step_verify_irrelevant_filtered(context):
    """Verify irrelevant content is filtered."""
    original_docs = context.mixed_memory.get_all_documents()
    selected_docs = context.role_context.documents
    
    # Verify selection is smaller and more relevant
    assert len(selected_docs) < len(original_docs)
    
    relevance_score = context.role_context.calculate_relevance_score()
    assert relevance_score >= 0.7
    context.test_context.log(f"Content relevance score: {relevance_score:.2%}")


@then('the selected documents should be identical across runs')
def step_verify_identical_selection(context):
    """Verify identical document selection across runs."""
    first_result = context.multiple_routing_results[0]
    
    for result in context.multiple_routing_results[1:]:
        assert result.selected_document_ids == first_result.selected_document_ids
    
    context.test_context.log("Document selection identical across all runs")


@then('the order should be consistent')
def step_verify_consistent_order(context):
    """Verify consistent document ordering."""
    first_order = context.multiple_routing_results[0].document_order
    
    for result in context.multiple_routing_results[1:]:
        assert result.document_order == first_order
    
    context.test_context.log("Document order consistent across runs")


@then('tie-breaking should follow configured rule')
def step_verify_tie_breaking_rule(context):
    """Verify tie-breaking follows configured rule."""
    for result in context.multiple_routing_results:
        assert result.tie_breaking_rule == context.tie_breaker_rule
        assert result.tie_breaking_applied_correctly()


@then('routing should be reproducible')
def step_verify_routing_reproducible(context):
    """Verify routing is reproducible."""
    # Hash all results and verify they're identical
    result_hashes = [result.get_hash() for result in context.multiple_routing_results]
    assert len(set(result_hashes)) == 1  # All hashes should be identical
    context.test_context.log("Routing is fully reproducible")


@then('it should skip the oversized document')
def step_verify_oversized_skipped(context):
    """Verify oversized document is skipped."""
    selected_docs = context.overflow_result.selected_documents
    oversized_id = context.oversized_doc.id
    
    selected_ids = [doc.id for doc in selected_docs]
    assert oversized_id not in selected_ids
    context.test_context.log("Oversized document correctly skipped")


@then('select the next highest scoring documents that fit')
def step_verify_next_highest_selected(context):
    """Verify next highest scoring documents are selected."""
    selected_docs = context.overflow_result.selected_documents
    
    # Verify selection maximizes score within budget
    total_score = sum(doc.importance_score for doc in selected_docs)
    total_tokens = sum(doc.token_count for doc in selected_docs)
    
    assert total_tokens <= context.current_budget_limit
    
    # Should achieve good score efficiency
    available_docs = [doc for doc in context.memory_store.get_all_documents() 
                     if doc.id != context.oversized_doc.id]
    max_possible_score = context.test_context.calculate_max_score_within_budget(
        available_docs, context.current_budget_limit
    )
    
    efficiency = total_score / max_possible_score
    assert efficiency >= 0.8  # Should achieve at least 80% efficiency
    context.test_context.log(f"Selection efficiency: {efficiency:.2%}")


@then('log a warning about the oversized document')
def step_verify_oversized_warning_logged(context):
    """Verify warning about oversized document is logged."""
    log_entries = context.overflow_result.log_entries
    warning_logged = any('oversized' in entry.message.lower() for entry in log_entries)
    
    assert warning_logged
    context.test_context.log("Oversized document warning properly logged")


@then('still provide useful context within budget')
def step_verify_useful_context_provided(context):
    """Verify useful context is still provided within budget."""
    selected_context = context.overflow_result.selected_context
    
    assert selected_context.total_tokens <= context.current_budget_limit
    assert len(selected_context.documents) > 0
    
    usefulness_score = selected_context.calculate_usefulness_score()
    assert usefulness_score >= 0.6
    context.test_context.log(f"Context usefulness: {usefulness_score:.2%}")


@then('router_metrics.json should contain')
def step_verify_router_metrics_content(context):
    """Verify router_metrics.json contains required fields."""
    metrics = context.final_metrics
    
    for row in context.table:
        metric_name = row['Metric']
        description = row['Description']
        
        assert metric_name in metrics, f"Missing metric: {metric_name}"
        assert metrics[metric_name] is not None, f"Null metric: {metric_name}"
        
        # Verify metric has proper structure if it's complex
        if isinstance(metrics[metric_name], dict):
            assert 'value' in metrics[metric_name]
            if 'description' in metrics[metric_name]:
                assert description.lower() in metrics[metric_name]['description'].lower()


@then('all metrics should be properly formatted and timestamped')
def step_verify_metrics_formatting(context):
    """Verify metrics are properly formatted and timestamped."""
    metrics = context.final_metrics
    
    assert 'timestamp' in metrics
    assert 'run_id' in metrics
    assert 'format_version' in metrics
    
    # Verify timestamp format
    timestamp = metrics['timestamp']
    assert isinstance(timestamp, str)
    assert 'T' in timestamp  # ISO format
    
    context.test_context.log("Metrics properly formatted and timestamped")


@then('the router should complete selection within {max_time:d} seconds')
def step_verify_selection_time_limit(context, max_time):
    """Verify selection completes within time limit."""
    assert context.stress_duration <= max_time
    context.test_context.log(f"Selection completed in {context.stress_duration:.2f}s <= {max_time}s")


@then('memory usage should remain within acceptable limits')
def step_verify_memory_usage_limits(context):
    """Verify memory usage stays within limits."""
    peak_memory = context.stress_result.peak_memory_usage
    memory_limit = context.test_context.get_memory_limit()
    
    assert peak_memory <= memory_limit
    context.test_context.log(f"Peak memory: {peak_memory} <= {memory_limit}")


@then('selection quality should not degrade with scale')
def step_verify_no_quality_degradation_scale(context):
    """Verify selection quality doesn't degrade with scale."""
    quality_score = context.stress_result.quality_score
    baseline_quality = context.test_context.get_baseline_quality_score()
    
    quality_ratio = quality_score / baseline_quality
    assert quality_ratio >= 0.95  # Allow max 5% degradation
    context.test_context.log(f"Quality ratio: {quality_ratio:.2%}")


@then('performance metrics should be recorded')
def step_verify_performance_metrics_recorded(context):
    """Verify performance metrics are recorded."""
    performance_metrics = context.stress_result.performance_metrics
    
    required_metrics = ['selection_time', 'memory_usage', 'quality_score', 'throughput']
    for metric in required_metrics:
        assert metric in performance_metrics
        assert performance_metrics[metric] is not None


@then('I should be able to identify optimal importance weights')
def step_verify_optimal_weights_identification(context):
    """Verify optimal importance weights can be identified."""
    analysis = context.effectiveness_analysis
    optimal_weights = analysis.optimal_importance_weights
    
    assert optimal_weights is not None
    assert len(optimal_weights) == len(context.importance_signals)
    
    # Verify weights sum to 1.0
    total_weight = sum(optimal_weights.values())
    assert abs(total_weight - 1.0) < 0.01
    context.test_context.log(f"Optimal weights identified: {optimal_weights}")


@then('track quality trends over time')
def step_verify_quality_trend_tracking(context):
    """Verify quality trends are tracked over time."""
    analysis = context.effectiveness_analysis
    quality_trends = analysis.quality_trends
    
    assert quality_trends is not None
    assert len(quality_trends) > 0
    
    # Verify trend data has timestamps and values
    for trend_point in quality_trends:
        assert 'timestamp' in trend_point
        assert 'quality_score' in trend_point
        assert 'sample_size' in trend_point


@then('detect when routing strategy needs adjustment')
def step_verify_strategy_adjustment_detection(context):
    """Verify detection of when routing strategy needs adjustment."""
    analysis = context.effectiveness_analysis
    adjustment_needed = analysis.strategy_adjustment_needed
    
    # Should detect if performance is degrading
    if analysis.performance_trend.slope < -0.05:  # Declining performance
        assert adjustment_needed == True
        context.test_context.log("Strategy adjustment correctly detected")
    else:
        context.test_context.log(f"Strategy adjustment needed: {adjustment_needed}")


@then('measure correlation between context selection and answer quality')
def step_verify_correlation_measurement(context):
    """Verify correlation between context selection and answer quality."""
    analysis = context.effectiveness_analysis
    correlation = analysis.selection_quality_correlation
    
    assert correlation is not None
    assert -1.0 <= correlation <= 1.0  # Valid correlation coefficient
    
    # Should show positive correlation for good routing
    if correlation > 0.5:
        context.test_context.log(f"Strong positive correlation: {correlation:.3f}")
    else:
        context.test_context.log(f"Correlation coefficient: {correlation:.3f}")