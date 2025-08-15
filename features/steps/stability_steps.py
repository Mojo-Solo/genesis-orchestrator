"""
Step definitions for system stability and reproducibility testing (98.6% target).
"""

from behave import given, when, then, step
from genesis_test_framework import StabilityTester, TestContext
import json
import time
import statistics
import hashlib
from Levenshtein import distance as levenshtein_distance


@given('the GENESIS orchestrator is configured for maximum stability')
def step_configure_max_stability(context):
    """Configure orchestrator for maximum stability."""
    context.stability_tester = StabilityTester()
    context.stability_tester.configure_for_maximum_stability()
    assert context.stability_tester.is_stability_configured()


@given('temperature is set to {temp:f} or lower')
def step_set_temperature_limit(context, temp):
    """Set temperature limit for stability."""
    context.stability_tester.set_max_temperature(temp)
    context.max_temperature = temp


@given('deterministic seed is configured')
def step_configure_deterministic_seed(context):
    """Configure deterministic seed."""
    context.stability_tester.set_deterministic_seed(42)
    context.deterministic_seed = 42


@given('router tie-breaking is set to "{tie_breaker}"')
def step_set_router_tie_breaking(context, tie_breaker):
    """Set router tie-breaking method."""
    context.stability_tester.set_router_tie_breaking(tie_breaker)
    context.tie_breaking_method = tie_breaker


@given('tool mocking is deterministic')
def step_configure_deterministic_tools(context):
    """Configure deterministic tool mocking."""
    context.stability_tester.enable_deterministic_tool_mocking()
    assert context.stability_tester.tools_are_deterministic()


@given('embedding caching is enabled')
def step_enable_embedding_caching(context):
    """Enable embedding caching for consistency."""
    context.stability_tester.enable_embedding_caching()
    assert context.stability_tester.embedding_cache_enabled()


@given('I have a complex multi-hop test question "{question}"')
def step_set_stability_test_question(context, question):
    """Set test question for stability testing."""
    context.test_question = question
    context.question_type = 'stability_test'


@given('I have fixed all randomness sources')
def step_fix_randomness_sources(context):
    """Fix all sources of randomness."""
    context.stability_tester.fix_all_randomness_sources()
    randomness_report = context.stability_tester.get_randomness_report()
    assert randomness_report.all_sources_fixed


@given('I have a question requiring decomposition')
def step_set_decomposition_question(context):
    """Set question that requires decomposition for plan testing."""
    context.decomposition_question = "What is the population of the capital of the country that hosted the 2020 Olympics?"
    context.question_type = 'decomposition_required'


@given('I have a memory store with consistent content')
def step_setup_consistent_memory(context):
    """Setup memory store with consistent content."""
    context.consistent_memory = context.test_context.create_consistent_memory_store()
    context.stability_tester.set_memory_store(context.consistent_memory)


@given('role budgets are fixed across runs')
def step_fix_role_budgets(context):
    """Fix role budgets across runs."""
    context.fixed_budgets = {
        'Planner': 1536,
        'Retriever': 1024, 
        'Solver': 1024,
        'Critic': 1024,
        'Verifier': 1536,
        'Rewriter': 768
    }
    context.stability_tester.set_fixed_role_budgets(context.fixed_budgets)


@given('the system is configured with seed value {seed:d}')
def step_configure_specific_seed(context, seed):
    """Configure system with specific seed value."""
    context.stability_tester.set_seed(seed)
    context.test_seed = seed


@given('all random components use this seed')
def step_ensure_seed_propagation(context):
    """Ensure all random components use the configured seed."""
    context.stability_tester.propagate_seed_to_all_components()
    propagation_report = context.stability_tester.get_seed_propagation_report()
    assert propagation_report.all_components_seeded


@given('I test with different temperature settings')
def step_setup_temperature_tests(context):
    """Setup tests with different temperature settings."""
    context.temperature_test_configs = []
    for row in context.table:
        temp = float(row['Temperature'])
        expected_stability = row['Expected Stability']
        context.temperature_test_configs.append({
            'temperature': temp,
            'expected_stability': expected_stability
        })


@given('I have baseline latency measurements for a test question')
def step_setup_latency_baseline(context):
    """Setup baseline latency measurements."""
    context.latency_baseline = context.test_context.get_latency_baseline(context.test_question)
    context.baseline_latency_values = context.latency_baseline.measurements


@given('the system starts with empty memory')
def step_start_with_empty_memory(context):
    """Start with empty memory for consistency testing."""
    context.stability_tester.clear_memory()
    assert context.stability_tester.get_memory_size() == 0


@given('I process a question that adds items to memory')
def step_setup_memory_adding_question(context):
    """Setup question that will add items to memory."""
    context.memory_adding_question = "What are the key components of a neural network?"
    context.expected_memory_additions = context.test_context.get_expected_memory_additions(
        context.memory_adding_question
    )


@given('external tools are mocked for deterministic responses')
def step_setup_deterministic_tool_mocks(context):
    """Setup deterministic tool mocks."""
    context.stability_tester.setup_deterministic_tool_mocks()
    mock_report = context.stability_tester.get_tool_mock_report()
    assert mock_report.all_tools_mocked_deterministically


@given('tool call ordering is fixed')
def step_fix_tool_call_ordering(context):
    """Fix tool call ordering for determinism."""
    context.stability_tester.fix_tool_call_ordering()
    assert context.stability_tester.tool_call_ordering_fixed()


@given('I have a test case that triggers a known error condition')
def step_setup_error_trigger_test(context):
    """Setup test case that triggers known error."""
    context.error_trigger_question = "Divide by zero in calculation: 5/0"
    context.expected_error_type = "DivisionByZeroError"


@given('I have documents with identical importance scores for stability testing')
def step_setup_identical_score_documents(context):
    """Setup documents with identical importance scores for stability testing."""
    context.tied_score_documents = context.test_context.create_identical_score_documents()
    context.stability_tester.set_memory_store(context.tied_score_documents)


@given('tie-breaker is configured to use "{tie_breaker}"')
def step_configure_tie_breaker_method(context, tie_breaker):
    """Configure specific tie-breaker method."""
    context.stability_tester.set_tie_breaker_method(tie_breaker)
    context.configured_tie_breaker = tie_breaker


@given('I run a stability test with {iterations:d} iterations')
def step_setup_stability_test_iterations(context, iterations):
    """Setup stability test with specified iterations."""
    context.stability_test_iterations = iterations
    context.stability_results = []


@given('I have historical stability baselines')
def step_setup_historical_baselines(context):
    """Setup historical stability baselines."""
    context.historical_baselines = context.test_context.get_historical_stability_baselines()


@given('I want to verify optimal stability settings')
def step_setup_stability_optimization(context):
    """Setup for stability configuration optimization."""
    context.stability_optimizer = context.test_context.create_stability_optimizer()
    context.optimization_target = 0.986  # 98.6%


@given('I have edge case inputs (empty, very long, special characters)')
def step_setup_edge_case_inputs(context):
    """Setup edge case inputs for robustness testing."""
    context.edge_case_inputs = [
        "",  # Empty input
        "x" * 10000,  # Very long input
        "What about émojis 🤖 and spëcial chars: @#$%^&*()[]{}|\\:;\"'<>,./?`~",  # Special characters
        "SELECT * FROM users; DROP TABLE users; --",  # SQL injection attempt
        "\\n\\r\\t\\0\\x00",  # Control characters
    ]


@given('the system supports parallel processing')
def step_setup_parallel_processing(context):
    """Setup system for parallel processing testing."""
    context.parallel_processor = context.test_context.create_parallel_processor()
    context.parallel_instances = 4


@when('I process the question {runs:d} times with identical inputs')
def step_process_multiple_times(context, runs):
    """Process question multiple times with identical inputs."""
    context.stability_runs = []
    
    for i in range(runs):
        run_result = context.stability_tester.process_question(
            context.test_question,
            run_id=f"stability_run_{i+1}"
        )
        context.stability_runs.append(run_result)
    
    context.total_stability_runs = runs


@when('I generate plans across multiple runs')
def step_generate_multiple_plans(context):
    """Generate plans across multiple runs."""
    context.plan_generation_runs = []
    
    for i in range(5):  # Generate 5 plans
        plan_result = context.stability_tester.generate_plan(
            context.decomposition_question,
            run_id=f"plan_run_{i+1}"
        )
        context.plan_generation_runs.append(plan_result)


@when('I perform context routing multiple times')
def step_perform_multiple_routing(context):
    """Perform context routing multiple times."""
    context.routing_runs = []
    
    for i in range(5):
        routing_result = context.stability_tester.perform_context_routing(
            role='Solver',
            budget=1024,
            run_id=f"routing_run_{i+1}"
        )
        context.routing_runs.append(routing_result)


@when('I run the same query multiple times')
def step_run_same_query_multiple_times(context):
    """Run same query multiple times with fixed seed."""
    context.seeded_runs = []
    
    for i in range(3):
        result = context.stability_tester.process_with_fixed_seed(
            context.test_question,
            seed=context.test_seed,
            run_id=f"seeded_run_{i+1}"
        )
        context.seeded_runs.append(result)


@when('I run stability tests at each temperature')
def step_run_temperature_stability_tests(context):
    """Run stability tests at different temperatures."""
    context.temperature_stability_results = {}
    
    for config in context.temperature_test_configs:
        temp = config['temperature']
        context.stability_tester.set_temperature(temp)
        
        # Run 5 tests at this temperature
        temp_results = []
        for i in range(5):
            result = context.stability_tester.process_question(
                context.test_question,
                run_id=f"temp_{temp}_run_{i+1}"
            )
            temp_results.append(result)
        
        stability_score = context.stability_tester.calculate_stability_score(temp_results)
        context.temperature_stability_results[temp] = {
            'results': temp_results,
            'stability_score': stability_score,
            'expected': config['expected_stability']
        }


@when('I examine the generated artifacts')
def step_examine_generated_artifacts(context):
    """Examine artifacts generated across runs."""
    context.artifacts_analysis = {}
    
    for i, run in enumerate(context.stability_runs):
        run_artifacts = run.get_artifacts()
        context.artifacts_analysis[f"run_{i+1}"] = run_artifacts


@when('I measure latency across {measurement_count:d} runs')
def step_measure_latency_across_runs(context, measurement_count):
    """Measure latency across multiple runs."""
    context.latency_measurements = []
    
    for i in range(measurement_count):
        start_time = time.time()
        result = context.stability_tester.process_question(
            context.test_question,
            run_id=f"latency_run_{i+1}"
        )
        end_time = time.time()
        
        latency = (end_time - start_time) * 1000  # Convert to milliseconds
        context.latency_measurements.append(latency)


@when('I compute pairwise Levenshtein distances')
def step_compute_pairwise_distances(context):
    """Compute pairwise Levenshtein distances between answers."""
    context.answer_distances = []
    answers = [run.final_answer for run in context.stability_runs]
    
    for i in range(len(answers)):
        for j in range(i+1, len(answers)):
            distance = levenshtein_distance(answers[i], answers[j])
            max_length = max(len(answers[i]), len(answers[j]))
            normalized_distance = distance / max_length if max_length > 0 else 0
            context.answer_distances.append(normalized_distance)


@when('I run the same process multiple times')
def step_run_memory_process_multiple_times(context):
    """Run memory-affecting process multiple times."""
    context.memory_consistency_runs = []
    
    for i in range(3):
        # Start with empty memory
        context.stability_tester.clear_memory()
        
        # Process the question
        result = context.stability_tester.process_question(
            context.memory_adding_question,
            run_id=f"memory_run_{i+1}"
        )
        
        # Capture final memory state
        final_memory = context.stability_tester.get_memory_snapshot()
        result.final_memory = final_memory
        
        context.memory_consistency_runs.append(result)


@when('I execute workflows requiring tool calls')
def step_execute_tool_workflows(context):
    """Execute workflows requiring tool calls."""
    context.tool_workflow_runs = []
    
    for i in range(3):
        result = context.stability_tester.execute_tool_workflow(
            "Calculate the square root of 144 and then multiply by pi",
            run_id=f"tool_run_{i+1}"
        )
        context.tool_workflow_runs.append(result)


@when('I run it multiple times')
def step_run_error_case_multiple_times(context):
    """Run error case multiple times."""
    context.error_handling_runs = []
    
    for i in range(3):
        result = context.stability_tester.process_question(
            context.error_trigger_question,
            run_id=f"error_run_{i+1}",
            expect_error=True
        )
        context.error_handling_runs.append(result)


@when('routing selects from tied documents')
def step_route_from_tied_documents(context):
    """Route context from documents with tied scores."""
    context.tie_breaking_runs = []
    
    for i in range(3):
        result = context.stability_tester.route_context_with_ties(
            role='Solver',
            budget=1024,
            run_id=f"tie_run_{i+1}"
        )
        context.tie_breaking_runs.append(result)


@when('the test completes')
def step_stability_test_completes(context):
    """Mark stability test as completed."""
    context.stability_test_completed = True
    context.stability_report = context.stability_tester.generate_stability_report(
        context.stability_results
    )


@when('I run current stability tests')
def step_run_current_stability_tests(context):
    """Run current stability tests for regression detection."""
    context.current_stability_results = context.stability_tester.run_full_stability_suite()
    context.current_stability_score = context.stability_tester.calculate_overall_stability(
        context.current_stability_results
    )


@when('I test different configuration combinations')
def step_test_configuration_combinations(context):
    """Test different configuration combinations for optimization."""
    context.configuration_test_results = context.stability_optimizer.test_configurations()


@when('I test stability with these inputs')
def step_test_stability_edge_cases(context):
    """Test stability with edge case inputs."""
    context.edge_case_stability_results = {}
    
    for i, edge_input in enumerate(context.edge_case_inputs):
        edge_results = []
        
        for run in range(3):  # 3 runs per edge case
            try:
                result = context.stability_tester.process_question(
                    edge_input,
                    run_id=f"edge_{i}_run_{run+1}"
                )
                edge_results.append(result)
            except Exception as e:
                # Capture exceptions as part of stability testing
                edge_results.append({
                    'error': str(e),
                    'error_type': type(e).__name__
                })
        
        stability_score = context.stability_tester.calculate_edge_case_stability(edge_results)
        context.edge_case_stability_results[f"edge_case_{i}"] = {
            'input': edge_input[:50] + "..." if len(edge_input) > 50 else edge_input,
            'results': edge_results,
            'stability_score': stability_score
        }


@when('I run multiple instances concurrently')
def step_run_concurrent_instances(context):
    """Run multiple instances concurrently."""
    context.parallel_run_results = context.parallel_processor.run_concurrent_instances(
        context.test_question,
        instance_count=context.parallel_instances
    )


@then('all {run_count:d} runs should produce equivalent plans')
def step_verify_equivalent_plans(context, run_count):
    """Verify all runs produce equivalent plans."""
    plans = [run.plan for run in context.stability_runs]
    
    # Check plan equivalence
    reference_plan = plans[0]
    for i, plan in enumerate(plans[1:], 1):
        assert context.stability_tester.plans_are_equivalent(reference_plan, plan), \
            f"Plan {i+1} differs from reference plan"
    
    context.test_context.log(f"All {run_count} plans are equivalent")


@then('all {run_count:d} runs should produce equivalent routing decisions')
def step_verify_equivalent_routing(context, run_count):
    """Verify all runs produce equivalent routing decisions."""
    routing_decisions = [run.routing_decisions for run in context.stability_runs]
    
    reference_decisions = routing_decisions[0]
    for i, decisions in enumerate(routing_decisions[1:], 1):
        assert context.stability_tester.routing_decisions_are_equivalent(reference_decisions, decisions), \
            f"Routing decisions {i+1} differ from reference"
    
    context.test_context.log(f"All {run_count} routing decisions are equivalent")


@then('answer differences should be <= {max_diff:f}% Levenshtein distance')
def step_verify_answer_differences(context, max_diff):
    """Verify answer differences are within tolerance."""
    max_distance = max(context.answer_distances)
    max_distance_percent = max_distance * 100
    
    assert max_distance_percent <= max_diff, \
        f"Maximum answer difference {max_distance_percent:.2f}% exceeds limit {max_diff}%"
    
    avg_distance_percent = (sum(context.answer_distances) / len(context.answer_distances)) * 100
    context.test_context.log(f"Answer differences: max={max_distance_percent:.2f}%, avg={avg_distance_percent:.2f}%")


@then('latency variance should be within ± {variance_limit:f}% of median')
def step_verify_latency_variance(context, variance_limit):
    """Verify latency variance is within limits."""
    median_latency = statistics.median(context.latency_measurements)
    
    for latency in context.latency_measurements:
        variance_percent = abs((latency - median_latency) / median_latency) * 100
        assert variance_percent <= variance_limit, \
            f"Latency variance {variance_percent:.2f}% exceeds limit {variance_limit}%"
    
    std_dev = statistics.stdev(context.latency_measurements)
    cv_percent = (std_dev / median_latency) * 100
    context.test_context.log(f"Latency variance: CV={cv_percent:.2f}%, median={median_latency:.1f}ms")


@then('the stability percentage should be >= {min_stability:f}%')
def step_verify_minimum_stability(context, min_stability):
    """Verify minimum stability percentage is met."""
    stability_score = context.stability_tester.calculate_overall_stability_score(
        context.stability_runs
    )
    stability_percent = stability_score * 100
    
    assert stability_percent >= min_stability, \
        f"Stability {stability_percent:.2f}% below minimum {min_stability}%"
    
    context.test_context.log(f"Stability score: {stability_percent:.2f}% >= {min_stability}%")


@then('the plan graphs should be structurally identical')
def step_verify_plan_graph_structure(context):
    """Verify plan graphs are structurally identical."""
    reference_graph = context.plan_generation_runs[0].plan_graph
    
    for i, run in enumerate(context.plan_generation_runs[1:], 1):
        plan_graph = run.plan_graph
        assert context.stability_tester.graph_structures_identical(reference_graph, plan_graph), \
            f"Plan graph {i+1} structure differs from reference"
    
    context.test_context.log("All plan graph structures are identical")


@then('step dependencies should match exactly')
def step_verify_step_dependencies(context):
    """Verify step dependencies match exactly."""
    reference_deps = context.plan_generation_runs[0].step_dependencies
    
    for i, run in enumerate(context.plan_generation_runs[1:], 1):
        deps = run.step_dependencies
        assert deps == reference_deps, \
            f"Step dependencies {i+1} differ from reference"
    
    context.test_context.log("All step dependencies match exactly")


@then('step ordering should be consistent')
def step_verify_step_ordering(context):
    """Verify step ordering is consistent."""
    reference_order = context.plan_generation_runs[0].step_order
    
    for i, run in enumerate(context.plan_generation_runs[1:], 1):
        order = run.step_order
        assert order == reference_order, \
            f"Step order {i+1} differs from reference"
    
    context.test_context.log("Step ordering is consistent across runs")


@then('terminator conditions should be equivalent')
def step_verify_terminator_conditions(context):
    """Verify terminator conditions are equivalent."""
    reference_terminators = context.plan_generation_runs[0].terminator_conditions
    
    for i, run in enumerate(context.plan_generation_runs[1:], 1):
        terminators = run.terminator_conditions
        assert context.stability_tester.terminators_equivalent(reference_terminators, terminators), \
            f"Terminator conditions {i+1} differ from reference"
    
    context.test_context.log("Terminator conditions are equivalent")


@then('preflight_plan.json should be identical across runs')
def step_verify_preflight_plan_identical(context):
    """Verify preflight_plan.json is identical across runs."""
    reference_plan = context.plan_generation_runs[0].preflight_plan
    
    for i, run in enumerate(context.plan_generation_runs[1:], 1):
        plan = run.preflight_plan
        assert plan == reference_plan, \
            f"Preflight plan {i+1} differs from reference"
    
    context.test_context.log("Preflight plans are identical across runs")


@then('the selected document sets should be identical')
def step_verify_identical_document_sets(context):
    """Verify selected document sets are identical."""
    reference_docs = context.routing_runs[0].selected_document_ids
    
    for i, run in enumerate(context.routing_runs[1:], 1):
        docs = run.selected_document_ids
        assert set(docs) == set(reference_docs), \
            f"Selected document set {i+1} differs from reference"
    
    context.test_context.log("Selected document sets are identical")


@then('importance scores should be consistent')
def step_verify_consistent_importance_scores(context):
    """Verify importance scores are consistent."""
    reference_scores = context.routing_runs[0].importance_scores
    
    for i, run in enumerate(context.routing_runs[1:], 1):
        scores = run.importance_scores
        for doc_id, score in scores.items():
            ref_score = reference_scores.get(doc_id)
            assert abs(score - ref_score) < 1e-6, \
                f"Importance score for {doc_id} in run {i+1} differs: {score} vs {ref_score}"
    
    context.test_context.log("Importance scores are consistent")


@then('budget utilization should match exactly')
def step_verify_budget_utilization(context):
    """Verify budget utilization matches exactly."""
    reference_utilization = context.routing_runs[0].budget_utilization
    
    for i, run in enumerate(context.routing_runs[1:], 1):
        utilization = run.budget_utilization
        assert utilization == reference_utilization, \
            f"Budget utilization {i+1} differs: {utilization} vs {reference_utilization}"
    
    context.test_context.log("Budget utilization matches exactly")


@then('routing decisions should be deterministic')
def step_verify_deterministic_routing(context):
    """Verify routing decisions are deterministic."""
    reference_decisions = context.routing_runs[0].routing_decisions
    
    for i, run in enumerate(context.routing_runs[1:], 1):
        decisions = run.routing_decisions
        assert context.stability_tester.routing_decisions_identical(reference_decisions, decisions), \
            f"Routing decisions {i+1} are not deterministic"
    
    context.test_context.log("Routing decisions are deterministic")


@then('model outputs should be identical')
def step_verify_identical_model_outputs(context):
    """Verify model outputs are identical with fixed seed."""
    reference_output = context.seeded_runs[0].model_output
    
    for i, run in enumerate(context.seeded_runs[1:], 1):
        output = run.model_output
        assert output == reference_output, \
            f"Model output {i+1} differs from reference with same seed"
    
    context.test_context.log("Model outputs are identical with fixed seed")


@then('retrieval rankings should be consistent')
def step_verify_consistent_retrieval_rankings(context):
    """Verify retrieval rankings are consistent."""
    reference_rankings = context.seeded_runs[0].retrieval_rankings
    
    for i, run in enumerate(context.seeded_runs[1:], 1):
        rankings = run.retrieval_rankings
        assert rankings == reference_rankings, \
            f"Retrieval rankings {i+1} differ from reference"
    
    context.test_context.log("Retrieval rankings are consistent")


@then('importance calculations should match')
def step_verify_matching_importance_calculations(context):
    """Verify importance calculations match."""
    reference_calculations = context.seeded_runs[0].importance_calculations
    
    for i, run in enumerate(context.seeded_runs[1:], 1):
        calculations = run.importance_calculations
        for doc_id, calc in calculations.items():
            ref_calc = reference_calculations[doc_id]
            assert abs(calc - ref_calc) < 1e-10, \
                f"Importance calculation for {doc_id} in run {i+1} differs"
    
    context.test_context.log("Importance calculations match exactly")


@then('no variance should occur in deterministic components')
def step_verify_no_deterministic_variance(context):
    """Verify no variance in deterministic components."""
    deterministic_components = ['model_output', 'retrieval_rankings', 'importance_scores', 'routing_decisions']
    
    for component in deterministic_components:
        reference_values = getattr(context.seeded_runs[0], component)
        
        for i, run in enumerate(context.seeded_runs[1:], 1):
            values = getattr(run, component)
            assert values == reference_values, \
                f"Variance detected in {component} for run {i+1}"
    
    context.test_context.log("No variance in deterministic components")


@then('the measured stability should meet expectations')
def step_verify_temperature_stability_expectations(context):
    """Verify measured stability meets temperature expectations."""
    for temp, results in context.temperature_stability_results.items():
        actual_stability = results['stability_score']
        expected_stability_str = results['expected']
        
        # Parse expected stability (e.g., ">= 98.6%" or "100%")
        if expected_stability_str.startswith('>='):
            expected_min = float(expected_stability_str.replace('>=', '').replace('%', '').strip()) / 100
            assert actual_stability >= expected_min, \
                f"Temperature {temp}: stability {actual_stability:.3f} < expected minimum {expected_min:.3f}"
        else:
            expected_exact = float(expected_stability_str.replace('%', '')) / 100
            tolerance = 0.02  # 2% tolerance
            assert abs(actual_stability - expected_exact) <= tolerance, \
                f"Temperature {temp}: stability {actual_stability:.3f} not within tolerance of {expected_exact:.3f}"
    
    context.test_context.log("Temperature stability expectations met")


@then('temperature {target_temp:f} should achieve the {target_stability:f}% target')
def step_verify_target_temperature_stability(context, target_temp, target_stability):
    """Verify target temperature achieves stability target."""
    results = context.temperature_stability_results[target_temp]
    actual_stability = results['stability_score']
    target_stability_ratio = target_stability / 100
    
    assert actual_stability >= target_stability_ratio, \
        f"Temperature {target_temp} achieved {actual_stability:.3f} < target {target_stability_ratio:.3f}"
    
    context.test_context.log(f"Temperature {target_temp} achieved {actual_stability:.3f} >= {target_stability_ratio:.3f}")


@then('higher temperatures should show increased variance')
def step_verify_temperature_variance_relationship(context):
    """Verify higher temperatures show increased variance."""
    temperatures = sorted(context.temperature_stability_results.keys())
    stabilities = [context.temperature_stability_results[temp]['stability_score'] for temp in temperatures]
    
    # Generally, stability should decrease as temperature increases
    for i in range(1, len(temperatures)):
        if temperatures[i] > temperatures[i-1]:
            # Allow some tolerance for measurement variance
            stability_diff = stabilities[i-1] - stabilities[i]
            assert stability_diff >= -0.01, \
                f"Stability increased significantly from temp {temperatures[i-1]} to {temperatures[i]}"
    
    context.test_context.log("Higher temperatures show appropriate variance relationship")


@then('execution_trace.ndjson should have consistent structure')
def step_verify_consistent_trace_structure(context):
    """Verify execution trace has consistent structure."""
    reference_structure = context.stability_tester.analyze_trace_structure(
        context.artifacts_analysis["run_1"]["execution_trace.ndjson"]
    )
    
    for run_name, artifacts in context.artifacts_analysis.items():
        if run_name == "run_1":
            continue
        
        trace_structure = context.stability_tester.analyze_trace_structure(
            artifacts["execution_trace.ndjson"]
        )
        
        assert trace_structure == reference_structure, \
            f"Trace structure differs in {run_name}"
    
    context.test_context.log("Execution trace structure is consistent")


@then('router_metrics.json should contain identical values')
def step_verify_identical_router_metrics(context):
    """Verify router metrics contain identical values."""
    reference_metrics = json.loads(context.artifacts_analysis["run_1"]["router_metrics.json"])
    
    for run_name, artifacts in context.artifacts_analysis.items():
        if run_name == "run_1":
            continue
        
        metrics = json.loads(artifacts["router_metrics.json"])
        
        # Compare non-timestamp fields
        for key, value in reference_metrics.items():
            if key not in ['timestamp', 'run_id']:
                assert metrics[key] == value, \
                    f"Router metric {key} differs in {run_name}: {metrics[key]} vs {value}"
    
    context.test_context.log("Router metrics contain identical values")


@then('memory_pre.json and memory_post.json should match')
def step_verify_memory_artifacts_match(context):
    """Verify memory artifacts match across runs."""
    reference_pre = json.loads(context.artifacts_analysis["run_1"]["memory_pre.json"])
    reference_post = json.loads(context.artifacts_analysis["run_1"]["memory_post.json"])
    
    for run_name, artifacts in context.artifacts_analysis.items():
        if run_name == "run_1":
            continue
        
        pre_memory = json.loads(artifacts["memory_pre.json"])
        post_memory = json.loads(artifacts["memory_post.json"])
        
        assert pre_memory == reference_pre, f"memory_pre.json differs in {run_name}"
        assert post_memory == reference_post, f"memory_post.json differs in {run_name}"
    
    context.test_context.log("Memory artifacts match across runs")


@then('all run_ids should be different but correlation_ids consistent for same inputs')
def step_verify_id_consistency(context):
    """Verify run_ids are different but correlation_ids consistent."""
    run_ids = set()
    correlation_ids = set()
    
    for run_name, artifacts in context.artifacts_analysis.items():
        trace_lines = artifacts["execution_trace.ndjson"].strip().split('\n')
        for line in trace_lines:
            entry = json.loads(line)
            run_ids.add(entry['run_id'])
            correlation_ids.add(entry['correlation_id'])
    
    # All run_ids should be different
    assert len(run_ids) == len(context.artifacts_analysis), \
        f"Expected {len(context.artifacts_analysis)} unique run_ids, got {len(run_ids)}"
    
    # All correlation_ids should be the same for same inputs
    assert len(correlation_ids) == 1, \
        f"Expected 1 unique correlation_id for same inputs, got {len(correlation_ids)}"
    
    context.test_context.log("Run IDs are unique, correlation IDs are consistent")


@then('timestamps should be the only varying elements')
def step_verify_only_timestamps_vary(context):
    """Verify only timestamps vary between runs."""
    reference_artifacts = context.artifacts_analysis["run_1"]
    
    for run_name, artifacts in context.artifacts_analysis.items():
        if run_name == "run_1":
            continue
        
        # Compare each artifact type
        for artifact_name, artifact_content in artifacts.items():
            reference_content = reference_artifacts[artifact_name]
            
            if artifact_name.endswith('.json'):
                # For JSON files, compare excluding timestamps
                ref_data = json.loads(reference_content)
                current_data = json.loads(artifact_content)
                
                differences = context.stability_tester.find_json_differences(ref_data, current_data)
                timestamp_only = all('timestamp' in diff.path.lower() for diff in differences)
                
                assert timestamp_only, \
                    f"Non-timestamp differences found in {artifact_name} for {run_name}"
    
    context.test_context.log("Only timestamps vary between runs")


@then('the standard deviation should be <= {max_std_percent:f}% of median')
def step_verify_latency_std_dev(context, max_std_percent):
    """Verify latency standard deviation is within limits."""
    median_latency = statistics.median(context.latency_measurements)
    std_dev = statistics.stdev(context.latency_measurements)
    std_dev_percent = (std_dev / median_latency) * 100
    
    assert std_dev_percent <= max_std_percent, \
        f"Latency std dev {std_dev_percent:.2f}% exceeds limit {max_std_percent}%"
    
    context.test_context.log(f"Latency std dev: {std_dev_percent:.2f}% <= {max_std_percent}%")


@then('outliers should be minimal (<= {max_outliers:d} out of {total_runs:d} runs)')
def step_verify_minimal_outliers(context, max_outliers, total_runs):
    """Verify outliers are minimal."""
    median_latency = statistics.median(context.latency_measurements)
    q1 = statistics.quantiles(context.latency_measurements, n=4)[0]
    q3 = statistics.quantiles(context.latency_measurements, n=4)[2]
    iqr = q3 - q1
    
    lower_bound = q1 - 1.5 * iqr
    upper_bound = q3 + 1.5 * iqr
    
    outliers = [x for x in context.latency_measurements 
                if x < lower_bound or x > upper_bound]
    
    assert len(outliers) <= max_outliers, \
        f"Found {len(outliers)} outliers, expected <= {max_outliers}"
    
    context.test_context.log(f"Outliers: {len(outliers)}/{total_runs} <= {max_outliers}")


@then('p50 latency should be stable')
def step_verify_stable_p50_latency(context):
    """Verify p50 latency is stable."""
    # Compare current p50 with baseline
    current_p50 = statistics.median(context.latency_measurements)
    baseline_p50 = statistics.median(context.baseline_latency_values)
    
    variance_percent = abs((current_p50 - baseline_p50) / baseline_p50) * 100
    assert variance_percent <= 5.0, \
        f"P50 latency variance {variance_percent:.2f}% exceeds 5% threshold"
    
    context.test_context.log(f"P50 latency stable: {variance_percent:.2f}% variance")


@then('p95 latency should be within acceptable variance')
def step_verify_p95_latency_variance(context):
    """Verify p95 latency variance is acceptable."""
    current_p95 = statistics.quantiles(context.latency_measurements, n=20)[18]
    baseline_p95 = statistics.quantiles(context.baseline_latency_values, n=20)[18]
    
    variance_percent = abs((current_p95 - baseline_p95) / baseline_p95) * 100
    assert variance_percent <= 10.0, \
        f"P95 latency variance {variance_percent:.2f}% exceeds 10% threshold"
    
    context.test_context.log(f"P95 latency variance: {variance_percent:.2f}% <= 10%")


@then('all distances should be <= {max_distance:f}% of answer length')
def step_verify_answer_distance_limit(context, max_distance):
    """Verify all answer distances are within limit."""
    max_actual_distance = max(context.answer_distances) * 100
    
    assert max_actual_distance <= max_distance, \
        f"Maximum answer distance {max_actual_distance:.2f}% exceeds limit {max_distance}%"
    
    avg_distance = (sum(context.answer_distances) / len(context.answer_distances)) * 100
    context.test_context.log(f"Answer distances: max={max_actual_distance:.2f}%, avg={avg_distance:.2f}%")


@then('semantic similarity should be >= {min_similarity:d}%')
def step_verify_semantic_similarity(context, min_similarity):
    """Verify semantic similarity between answers."""
    answers = [run.final_answer for run in context.stability_runs]
    
    # Calculate semantic similarity (simplified - in practice would use embeddings)
    min_similarity_ratio = min_similarity / 100
    
    for i in range(len(answers)):
        for j in range(i+1, len(answers)):
            similarity = context.stability_tester.calculate_semantic_similarity(
                answers[i], answers[j]
            )
            assert similarity >= min_similarity_ratio, \
                f"Semantic similarity {similarity:.2f} < minimum {min_similarity_ratio:.2f}"
    
    context.test_context.log(f"All answer pairs have >= {min_similarity}% semantic similarity")


@then('key facts should be preserved across all runs')
def step_verify_key_facts_preserved(context):
    """Verify key facts are preserved across all runs."""
    answers = [run.final_answer for run in context.stability_runs]
    reference_facts = context.stability_tester.extract_key_facts(answers[0])
    
    for i, answer in enumerate(answers[1:], 1):
        answer_facts = context.stability_tester.extract_key_facts(answer)
        fact_preservation = context.stability_tester.calculate_fact_preservation(
            reference_facts, answer_facts
        )
        
        assert fact_preservation >= 0.9, \
            f"Key fact preservation {fact_preservation:.2f} < 90% in answer {i+1}"
    
    context.test_context.log("Key facts preserved across all runs")


@then('only minor variations in phrasing should occur')
def step_verify_minor_phrasing_variations(context):
    """Verify only minor phrasing variations occur."""
    answers = [run.final_answer for run in context.stability_runs]
    
    for i in range(len(answers)):
        for j in range(i+1, len(answers)):
            phrasing_similarity = context.stability_tester.calculate_phrasing_similarity(
                answers[i], answers[j]
            )
            
            assert phrasing_similarity >= 0.8, \
                f"Significant phrasing difference between answers {i+1} and {j+1}"
    
    context.test_context.log("Only minor phrasing variations detected")


@then('memory_post.json should be identical across runs')
def step_verify_identical_memory_post(context):
    """Verify memory_post.json is identical across runs."""
    reference_memory = context.memory_consistency_runs[0].final_memory
    
    for i, run in enumerate(context.memory_consistency_runs[1:], 1):
        memory = run.final_memory
        assert context.stability_tester.memory_states_identical(reference_memory, memory), \
            f"Memory state {i+1} differs from reference"
    
    context.test_context.log("Memory post states are identical")


@then('memory item ordering should be consistent')
def step_verify_memory_item_ordering(context):
    """Verify memory item ordering is consistent."""
    reference_ordering = context.memory_consistency_runs[0].final_memory.item_ordering
    
    for i, run in enumerate(context.memory_consistency_runs[1:], 1):
        ordering = run.final_memory.item_ordering
        assert ordering == reference_ordering, \
            f"Memory item ordering {i+1} differs from reference"
    
    context.test_context.log("Memory item ordering is consistent")


@then('embedding IDs should match (with caching)')
def step_verify_embedding_id_consistency(context):
    """Verify embedding IDs match with caching enabled."""
    reference_embeddings = context.memory_consistency_runs[0].final_memory.embedding_ids
    
    for i, run in enumerate(context.memory_consistency_runs[1:], 1):
        embeddings = run.final_memory.embedding_ids
        assert embeddings == reference_embeddings, \
            f"Embedding IDs {i+1} differ from reference (caching should prevent this)"
    
    context.test_context.log("Embedding IDs match across runs (caching working)")


@then('tag assignments should be deterministic')
def step_verify_deterministic_tag_assignments(context):
    """Verify tag assignments are deterministic."""
    reference_tags = context.memory_consistency_runs[0].final_memory.tag_assignments
    
    for i, run in enumerate(context.memory_consistency_runs[1:], 1):
        tags = run.final_memory.tag_assignments
        assert tags == reference_tags, \
            f"Tag assignments {i+1} differ from reference"
    
    context.test_context.log("Tag assignments are deterministic")


@then('tool call sequences should be identical')
def step_verify_identical_tool_sequences(context):
    """Verify tool call sequences are identical."""
    reference_sequence = context.tool_workflow_runs[0].tool_call_sequence
    
    for i, run in enumerate(context.tool_workflow_runs[1:], 1):
        sequence = run.tool_call_sequence
        assert sequence == reference_sequence, \
            f"Tool call sequence {i+1} differs from reference"
    
    context.test_context.log("Tool call sequences are identical")


@then('tool responses should be consistent')
def step_verify_consistent_tool_responses(context):
    """Verify tool responses are consistent."""
    reference_responses = context.tool_workflow_runs[0].tool_responses
    
    for i, run in enumerate(context.tool_workflow_runs[1:], 1):
        responses = run.tool_responses
        assert responses == reference_responses, \
            f"Tool responses {i+1} differ from reference"
    
    context.test_context.log("Tool responses are consistent")


@then('tool_call_ids should follow predictable patterns')
def step_verify_predictable_tool_call_ids(context):
    """Verify tool_call_ids follow predictable patterns."""
    reference_ids = context.tool_workflow_runs[0].tool_call_ids
    
    for i, run in enumerate(context.tool_workflow_runs[1:], 1):
        ids = run.tool_call_ids
        
        # IDs should follow same pattern (e.g., sequential numbering)
        pattern_match = context.stability_tester.tool_call_ids_follow_pattern(
            reference_ids, ids
        )
        assert pattern_match, \
            f"Tool call IDs {i+1} don't follow reference pattern"
    
    context.test_context.log("Tool call IDs follow predictable patterns")


@then('no external variability should affect results')
def step_verify_no_external_variability(context):
    """Verify no external variability affects results."""
    # All tool responses should be from mocks, not external services
    for run in context.tool_workflow_runs:
        external_calls = run.get_external_tool_calls()
        assert len(external_calls) == 0, \
            f"Found {len(external_calls)} external tool calls - should be mocked"
    
    context.test_context.log("No external variability - all tools properly mocked")


@then('the error should be handled identically')
def step_verify_identical_error_handling(context):
    """Verify error is handled identically across runs."""
    reference_error = context.error_handling_runs[0].error_details
    
    for i, run in enumerate(context.error_handling_runs[1:], 1):
        error = run.error_details
        assert error == reference_error, \
            f"Error handling {i+1} differs from reference"
    
    context.test_context.log("Error handled identically across runs")


@then('error messages should be consistent')
def step_verify_consistent_error_messages(context):
    """Verify error messages are consistent."""
    reference_message = context.error_handling_runs[0].error_message
    
    for i, run in enumerate(context.error_handling_runs[1:], 1):
        message = run.error_message
        assert message == reference_message, \
            f"Error message {i+1} differs from reference"
    
    context.test_context.log("Error messages are consistent")


@then('fallback behavior should be deterministic')
def step_verify_deterministic_fallback(context):
    """Verify fallback behavior is deterministic."""
    reference_fallback = context.error_handling_runs[0].fallback_behavior
    
    for i, run in enumerate(context.error_handling_runs[1:], 1):
        fallback = run.fallback_behavior
        assert fallback == reference_fallback, \
            f"Fallback behavior {i+1} differs from reference"
    
    context.test_context.log("Fallback behavior is deterministic")


@then('recovery paths should be the same')
def step_verify_same_recovery_paths(context):
    """Verify recovery paths are the same."""
    reference_recovery = context.error_handling_runs[0].recovery_path
    
    for i, run in enumerate(context.error_handling_runs[1:], 1):
        recovery = run.recovery_path
        assert recovery == reference_recovery, \
            f"Recovery path {i+1} differs from reference"
    
    context.test_context.log("Recovery paths are identical")


@then('selection should be consistent across runs')
def step_verify_consistent_tie_breaking_selection(context):
    """Verify selection is consistent when tie-breaking."""
    reference_selection = context.tie_breaking_runs[0].selected_documents
    
    for i, run in enumerate(context.tie_breaking_runs[1:], 1):
        selection = run.selected_documents
        assert selection == reference_selection, \
            f"Tie-breaking selection {i+1} differs from reference"
    
    context.test_context.log("Tie-breaking selection is consistent")


@then('tie-breaking rule should be applied correctly')
def step_verify_tie_breaking_rule_application(context):
    """Verify tie-breaking rule is applied correctly."""
    for run in context.tie_breaking_runs:
        tie_breaking_applied = run.tie_breaking_correctly_applied
        assert tie_breaking_applied, \
            f"Tie-breaking rule not correctly applied in run {run.run_id}"
    
    context.test_context.log("Tie-breaking rule applied correctly in all runs")


@then('no randomness should affect tied selections')
def step_verify_no_randomness_in_ties(context):
    """Verify no randomness affects tied selections."""
    for run in context.tie_breaking_runs:
        randomness_detected = run.randomness_in_tie_breaking
        assert not randomness_detected, \
            f"Randomness detected in tie-breaking for run {run.run_id}"
    
    context.test_context.log("No randomness detected in tied selections")


@then('document ordering should be deterministic')
def step_verify_deterministic_document_ordering(context):
    """Verify document ordering is deterministic."""
    reference_ordering = context.tie_breaking_runs[0].document_ordering
    
    for i, run in enumerate(context.tie_breaking_runs[1:], 1):
        ordering = run.document_ordering
        assert ordering == reference_ordering, \
            f"Document ordering {i+1} differs from reference"
    
    context.test_context.log("Document ordering is deterministic")


@then('I should receive a stability report with')
def step_verify_stability_report_contents(context):
    """Verify stability report contains required metrics."""
    report = context.stability_report
    
    for row in context.table:
        metric = row['Metric']
        requirement = row['Requirement']
        
        assert metric in report.metrics, f"Missing metric: {metric}"
        
        actual_value = report.metrics[metric]
        
        # Parse requirement and verify
        if requirement == "100%":
            assert actual_value == 1.0, f"{metric}: {actual_value} != 1.0"
        elif requirement.startswith(">="):
            min_value = float(requirement.replace(">=", "").replace("%", "").strip()) / 100
            assert actual_value >= min_value, f"{metric}: {actual_value} < {min_value}"
        elif requirement.startswith("<="):
            max_value = float(requirement.replace("<=", "").replace("%", "").strip()) / 100
            assert actual_value <= max_value, f"{metric}: {actual_value} > {max_value}"
    
    context.test_context.log("Stability report contains all required metrics")


@then('the report should include detailed variance analysis')
def step_verify_detailed_variance_analysis(context):
    """Verify report includes detailed variance analysis."""
    report = context.stability_report
    
    assert hasattr(report, 'variance_analysis'), "Missing variance analysis"
    variance_analysis = report.variance_analysis
    
    required_sections = ['plan_variance', 'routing_variance', 'answer_variance', 'latency_variance']
    for section in required_sections:
        assert section in variance_analysis, f"Missing variance analysis section: {section}"
    
    context.test_context.log("Detailed variance analysis included in report")


@then('current stability should not be significantly worse than baseline')
def step_verify_no_stability_regression(context):
    """Verify current stability doesn't regress from baseline."""
    baseline_stability = context.historical_baselines.overall_stability
    current_stability = context.current_stability_score
    
    regression_threshold = 0.02  # 2% regression tolerance
    assert current_stability >= (baseline_stability - regression_threshold), \
        f"Stability regression: {current_stability:.3f} < {baseline_stability - regression_threshold:.3f}"
    
    context.test_context.log(f"No stability regression: {current_stability:.3f} >= {baseline_stability:.3f}")


@then('any degradation should be flagged for investigation')
def step_verify_degradation_flagging(context):
    """Verify degradation is flagged for investigation."""
    baseline_stability = context.historical_baselines.overall_stability
    current_stability = context.current_stability_score
    
    if current_stability < baseline_stability:
        degradation_flagged = context.stability_tester.check_degradation_flagged()
        assert degradation_flagged, "Stability degradation not flagged for investigation"
        context.test_context.log("Stability degradation properly flagged")
    else:
        context.test_context.log("No degradation detected")


@then('regression thresholds should be configurable')
def step_verify_configurable_regression_thresholds(context):
    """Verify regression thresholds are configurable."""
    original_threshold = context.stability_tester.get_regression_threshold()
    
    # Test configuration
    new_threshold = 0.05
    context.stability_tester.set_regression_threshold(new_threshold)
    configured_threshold = context.stability_tester.get_regression_threshold()
    
    assert configured_threshold == new_threshold, \
        f"Threshold not configured: {configured_threshold} != {new_threshold}"
    
    # Restore original
    context.stability_tester.set_regression_threshold(original_threshold)
    
    context.test_context.log("Regression thresholds are configurable")


@then('trend analysis should be provided')
def step_verify_trend_analysis_provided(context):
    """Verify trend analysis is provided."""
    trend_analysis = context.stability_tester.get_trend_analysis()
    
    assert trend_analysis is not None, "Trend analysis not provided"
    assert 'stability_trend' in trend_analysis, "Missing stability trend"
    assert 'performance_trend' in trend_analysis, "Missing performance trend"
    assert 'variance_trend' in trend_analysis, "Missing variance trend"
    
    context.test_context.log("Comprehensive trend analysis provided")


@then('the system should recommend settings for {target:f}% target')
def step_verify_stability_settings_recommendation(context, target):
    """Verify system recommends settings for stability target."""
    recommendations = context.configuration_test_results.recommendations
    target_config = recommendations.get_config_for_target(target / 100)
    
    assert target_config is not None, f"No configuration recommended for {target}% target"
    assert target_config.expected_stability >= target / 100, \
        f"Recommended config stability {target_config.expected_stability:.3f} < target {target / 100:.3f}"
    
    context.test_context.log(f"Configuration recommended for {target}% stability target")


@then('configuration impact should be measured')
def step_verify_configuration_impact_measured(context):
    """Verify configuration impact is measured."""
    impact_measurements = context.configuration_test_results.impact_measurements
    
    assert len(impact_measurements) > 0, "No configuration impact measurements"
    
    for config, impact in impact_measurements.items():
        assert 'stability_delta' in impact, f"Missing stability delta for {config}"
        assert 'performance_delta' in impact, f"Missing performance delta for {config}"
    
    context.test_context.log("Configuration impact properly measured")


@then('optimal parameters should be documented')
def step_verify_optimal_parameters_documented(context):
    """Verify optimal parameters are documented."""
    documentation = context.configuration_test_results.documentation
    
    assert 'optimal_temperature' in documentation, "Missing optimal temperature documentation"
    assert 'optimal_seed_strategy' in documentation, "Missing optimal seed strategy documentation"
    assert 'optimal_tie_breaking' in documentation, "Missing optimal tie-breaking documentation"
    
    context.test_context.log("Optimal parameters properly documented")


@then('stability vs performance trade-offs should be reported')
def step_verify_tradeoff_reporting(context):
    """Verify stability vs performance trade-offs are reported."""
    tradeoff_analysis = context.configuration_test_results.tradeoff_analysis
    
    assert tradeoff_analysis is not None, "Missing trade-off analysis"
    assert 'pareto_frontier' in tradeoff_analysis, "Missing Pareto frontier analysis"
    assert 'recommended_balance' in tradeoff_analysis, "Missing recommended balance"
    
    context.test_context.log("Stability vs performance trade-offs reported")


@then('the system should maintain stability requirements')
def step_verify_edge_case_stability_requirements(context):
    """Verify system maintains stability requirements with edge cases."""
    min_required_stability = 0.95  # 95% minimum for edge cases
    
    for case_name, results in context.edge_case_stability_results.items():
        stability_score = results['stability_score']
        assert stability_score >= min_required_stability, \
            f"Edge case {case_name} stability {stability_score:.3f} < {min_required_stability:.3f}"
    
    context.test_context.log("Stability requirements maintained for edge cases")


@then('edge cases should not break determinism')
def step_verify_edge_cases_preserve_determinism(context):
    """Verify edge cases don't break determinism."""
    for case_name, results in context.edge_case_stability_results.items():
        case_results = results['results']
        
        # Check if results are deterministic (non-error results should be identical)
        non_error_results = [r for r in case_results if 'error' not in r]
        
        if len(non_error_results) > 1:
            reference_result = non_error_results[0]
            for result in non_error_results[1:]:
                determinism_maintained = context.stability_tester.results_are_deterministic(
                    reference_result, result
                )
                assert determinism_maintained, \
                    f"Determinism broken for edge case {case_name}"
    
    context.test_context.log("Determinism preserved for edge cases")


@then('error handling should remain consistent')
def step_verify_consistent_edge_case_error_handling(context):
    """Verify error handling remains consistent for edge cases."""
    for case_name, results in context.edge_case_stability_results.items():
        case_results = results['results']
        
        # Check error consistency
        error_results = [r for r in case_results if 'error' in r]
        
        if len(error_results) > 1:
            reference_error = error_results[0]
            for error_result in error_results[1:]:
                assert error_result['error_type'] == reference_error['error_type'], \
                    f"Inconsistent error handling for edge case {case_name}"
    
    context.test_context.log("Error handling consistent for edge cases")


@then('no undefined behavior should occur')
def step_verify_no_undefined_behavior(context):
    """Verify no undefined behavior occurs with edge cases."""
    for case_name, results in context.edge_case_stability_results.items():
        case_results = results['results']
        
        for result in case_results:
            if 'error' not in result:
                # Non-error results should be well-defined
                assert context.stability_tester.result_is_well_defined(result), \
                    f"Undefined behavior detected in edge case {case_name}"
            else:
                # Errors should be recognized error types
                error_type = result['error_type']
                assert context.stability_tester.is_recognized_error_type(error_type), \
                    f"Unrecognized error type {error_type} in edge case {case_name}"
    
    context.test_context.log("No undefined behavior detected")


@then('each instance should maintain individual stability')
def step_verify_individual_parallel_stability(context):
    """Verify each parallel instance maintains stability."""
    min_individual_stability = 0.98  # 98% minimum per instance
    
    for instance_id, instance_results in context.parallel_run_results.items():
        instance_stability = context.stability_tester.calculate_instance_stability(instance_results)
        assert instance_stability >= min_individual_stability, \
            f"Instance {instance_id} stability {instance_stability:.3f} < {min_individual_stability:.3f}"
    
    context.test_context.log("All parallel instances maintain individual stability")


@then('cross-instance interference should be minimal')
def step_verify_minimal_cross_instance_interference(context):
    """Verify minimal cross-instance interference."""
    interference_score = context.parallel_processor.calculate_interference_score(
        context.parallel_run_results
    )
    max_acceptable_interference = 0.05  # 5% maximum
    
    assert interference_score <= max_acceptable_interference, \
        f"Cross-instance interference {interference_score:.3f} > {max_acceptable_interference:.3f}"
    
    context.test_context.log(f"Cross-instance interference: {interference_score:.3f} <= {max_acceptable_interference:.3f}")


@then('shared resources should not affect determinism')
def step_verify_shared_resources_determinism(context):
    """Verify shared resources don't affect determinism."""
    shared_resource_access = context.parallel_processor.get_shared_resource_access_patterns()
    
    determinism_preserved = context.stability_tester.verify_shared_resource_determinism(
        shared_resource_access
    )
    
    assert determinism_preserved, "Shared resources affected determinism"
    context.test_context.log("Shared resources preserve determinism")


@then('parallel results should be equivalent to serial results')
def step_verify_parallel_serial_equivalence(context):
    """Verify parallel results are equivalent to serial results."""
    # Run same test serially for comparison
    serial_result = context.stability_tester.process_question(
        context.test_question,
        run_id="serial_comparison"
    )
    
    # Compare with parallel results
    for instance_id, parallel_result in context.parallel_run_results.items():
        equivalence = context.stability_tester.results_are_equivalent(
            serial_result, parallel_result
        )
        assert equivalence, f"Parallel instance {instance_id} not equivalent to serial result"
    
    context.test_context.log("Parallel results equivalent to serial results")