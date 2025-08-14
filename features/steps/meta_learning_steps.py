"""
Step definitions for meta-learning cycle validation testing.
"""

from behave import given, when, then, step
from genesis_test_framework import MetaLearningEngine, TestContext
import json
import time
import statistics


@given('the GENESIS orchestrator is running with meta-learning enabled')
def step_enable_meta_learning(context):
    """Enable meta-learning in the GENESIS orchestrator."""
    context.meta_learning_engine = MetaLearningEngine()
    context.meta_learning_engine.enable()
    assert context.meta_learning_engine.is_enabled()


@given('execution traces are being collected')
def step_enable_trace_collection(context):
    """Enable execution trace collection."""
    context.meta_learning_engine.enable_trace_collection()
    context.trace_collector = context.meta_learning_engine.get_trace_collector()


@given('performance baselines are established')
def step_establish_baselines(context):
    """Establish performance baselines."""
    context.performance_baselines = context.meta_learning_engine.establish_baselines()
    context.baseline_metrics = {
        'accuracy': 0.87,
        'token_efficiency': 1.0,
        'latency_ms': 1200,
        'stability_variance': 0.018
    }


@given('the meta-analysis engine is configured')
def step_configure_meta_analysis(context):
    """Configure the meta-analysis engine."""
    context.meta_analysis_config = {
        'bottleneck_detection': True,
        'proposal_generation': True,
        'ab_testing': True,
        'trend_analysis': True
    }
    context.meta_learning_engine.configure_analysis(context.meta_analysis_config)


@given('A/B testing infrastructure is available')
def step_setup_ab_testing(context):
    """Setup A/B testing infrastructure."""
    context.ab_tester = context.meta_learning_engine.setup_ab_testing()
    assert context.ab_tester.is_ready()


@given('the system has processed {query_count:d} queries with execution traces')
def step_setup_processed_queries(context, query_count):
    """Setup system with processed queries and traces."""
    context.processed_queries = query_count
    context.execution_traces = context.test_context.generate_execution_traces(query_count)
    context.meta_learning_engine.load_execution_traces(context.execution_traces)


@given('some queries show consistently high latency in specific components')
def step_setup_latency_issues(context):
    """Setup queries with latency issues for bottleneck detection."""
    context.latency_issues = {
        'router_saturation': {'frequency': 0.25, 'impact': 'high'},
        'terminator_misses': {'frequency': 0.15, 'impact': 'medium'},
        'prompt_bloat': {'frequency': 0.40, 'impact': 'high'},
        'flaky_tools': {'frequency': 0.10, 'impact': 'low'}
    }
    context.meta_learning_engine.inject_performance_issues(context.latency_issues)


@given('bottleneck analysis has identified router saturation issues')
def step_setup_router_saturation_bottleneck(context):
    """Setup router saturation bottleneck for proposal testing."""
    context.identified_bottleneck = {
        'type': 'router_saturation',
        'severity': 'high',
        'frequency': 0.25,
        'impact_score': 0.8,
        'root_cause': 'semantic_filter_topk_too_high'
    }


@given('I have a proposal to add a "{role}" role')
def step_setup_role_addition_proposal(context, role):
    """Setup proposal to add new role."""
    context.role_addition_proposal = {
        'change_type': 'add_role',
        'role_name': role,
        'budget_allocation': 512,
        'expected_improvement': 'improved_math_accuracy',
        'risk_level': 'low'
    }


@given('the proposal includes small token budget allocation')
def step_verify_small_budget_allocation(context):
    """Verify proposal includes small budget allocation."""
    budget = context.role_addition_proposal['budget_allocation']
    assert budget <= 1024  # Small allocation
    context.test_context.log(f"Small budget allocation: {budget} tokens")


@given('the system has identified improvement opportunities')
def step_setup_improvement_opportunities(context):
    """Setup identified improvement opportunities."""
    context.improvement_opportunities = [
        {'type': 'dead_logic_removal', 'priority': 'medium'},
        {'type': 'prompt_optimization', 'priority': 'high'},
        {'type': 'routing_efficiency', 'priority': 'high'},
        {'type': 'tool_reliability', 'priority': 'low'}
    ]


@given('baseline performance metrics are established')
def step_establish_baseline_metrics(context):
    """Establish baseline performance metrics."""
    context.baseline_performance = {
        'accuracy': 0.87,
        'token_efficiency': 1.0,
        'latency_ms': 1200,
        'stability_variance': 0.018,
        'retry_rate': 0.12
    }


@given('a meta-learning improvement has been deployed')
def step_deploy_meta_improvement(context):
    """Deploy a meta-learning improvement."""
    context.deployed_improvement = {
        'type': 'semantic_filter_optimization',
        'changes': {'topk': 8},  # Reduced from 12 to 8
        'deployment_timestamp': time.time()
    }
    context.meta_learning_engine.deploy_improvement(context.deployed_improvement)


@given('the system has accumulated unused code paths')
def step_setup_unused_code_paths(context):
    """Setup unused code paths for dead logic detection."""
    context.unused_code_paths = {
        'deprecated_prompts': ['old_planner_v1.md', 'legacy_critic.md'],
        'redundant_steps': ['duplicate_validation', 'unnecessary_recheck'],
        'unused_tool_calls': ['calculator_v1', 'outdated_search'],
        'obsolete_routes': ['legacy_memory_access', 'old_context_filter']
    }


@given('prompts have grown over time through iterations')
def step_setup_prompt_bloat(context):
    """Setup prompt bloat scenario."""
    context.prompt_bloat_status = {
        'Planner': {'original': 1536, 'current': 2048, 'bloat_percent': 33},
        'Retriever': {'original': 1024, 'current': 1280, 'bloat_percent': 25},
        'Solver': {'original': 1024, 'current': 1536, 'bloat_percent': 50},
        'Critic': {'original': 1024, 'current': 1280, 'bloat_percent': 25},
        'Verifier': {'original': 1536, 'current': 2048, 'bloat_percent': 33},
        'Rewriter': {'original': 768, 'current': 1024, 'bloat_percent': 33}
    }


@given('the RCR router shows inefficient selections')
def step_setup_routing_inefficiencies(context):
    """Setup routing inefficiencies for analysis."""
    context.routing_inefficiencies = {
        'irrelevant_docs': {'frequency': 0.23, 'impact': 'medium'},
        'redundant_selections': {'frequency': 0.15, 'impact': 'low'},
        'budget_underuse': {'frequency': 0.18, 'impact': 'medium'},
        'poor_role_matching': {'frequency': 0.12, 'impact': 'high'}
    }


@given('some external tools show inconsistent behavior')
def step_setup_flaky_tools(context):
    """Setup flaky tools for reliability analysis."""
    context.flaky_tools = {
        'SearchAPI': {'success_rate': 0.85, 'avg_latency': 2.3, 'failure_mode': 'timeout'},
        'DocRetriever': {'success_rate': 0.92, 'avg_latency': 1.1, 'failure_mode': 'rate_limit'},
        'Calculator': {'success_rate': 0.78, 'avg_latency': 0.5, 'failure_mode': 'parse_error'},
        'Translator': {'success_rate': 0.95, 'avg_latency': 1.8, 'failure_mode': 'service_down'}
    }


@given('the system processes different types of queries')
def step_setup_query_type_analysis(context):
    """Setup different query types for workload analysis."""
    context.query_workload = {
        'math_problems': {'frequency': 0.35, 'current_config': 'standard'},
        'multi_hop_qa': {'frequency': 0.40, 'current_config': 'standard'},
        'simple_lookup': {'frequency': 0.20, 'current_config': 'standard'},
        'complex_reasoning': {'frequency': 0.05, 'current_config': 'standard'}
    }


@given('users provide feedback on answer quality')
def step_setup_user_feedback(context):
    """Setup user feedback for integration."""
    context.user_feedback = [
        {'type': 'accuracy_rating', 'weight': 'high', 'avg_score': 4.2},
        {'type': 'speed_satisfaction', 'weight': 'medium', 'avg_score': 3.8},
        {'type': 'completeness', 'weight': 'high', 'avg_score': 4.0},
        {'type': 'relevance', 'weight': 'medium', 'avg_score': 4.1}
    ]


@given('the system shows performance variance across runs')
def step_setup_performance_variance(context):
    """Setup performance variance for reduction analysis."""
    context.performance_variance = {
        'router_selection': {'current': 0.023, 'target': 0.014},
        'tool_response_time': {'current': 0.031, 'target': 0.020},
        'model_outputs': {'current': 0.018, 'target': 0.014},
        'memory_state': {'current': 0.012, 'target': 0.010}
    }


@given('a meta-learning improvement has been deployed (monitoring configured)')
def step_setup_deployed_improvement_monitoring(context):
    """Setup deployed improvement for monitoring."""
    context.deployed_improvement_monitor = {
        'improvement_id': 'semantic_filter_opt_v1',
        'deployment_time': time.time() - 3600,  # 1 hour ago
        'monitoring_active': True,
        'rollback_conditions': {
            'accuracy_drop_threshold': 0.02,
            'latency_increase_threshold': 0.20,
            'error_rate_increase_threshold': 0.05,
            'stability_degradation_threshold': 0.95
        }
    }


@given('performance monitoring detects degradation')
def step_setup_performance_degradation(context):
    """Setup performance degradation scenario."""
    context.performance_degradation = {
        'accuracy_drop': 0.025,  # 2.5% drop (exceeds 2% threshold)
        'latency_increase': 0.15,  # 15% increase
        'error_rate_increase': 0.03,  # 3% increase
        'stability_score': 0.94  # Below 95% threshold
    }


@given('a meta-learning cycle has completed')
def step_setup_completed_meta_cycle(context):
    """Setup completed meta-learning cycle."""
    context.completed_cycle = {
        'run_id': 'meta_cycle_001',
        'start_time': time.time() - 2700,  # 45 minutes ago
        'end_time': time.time(),
        'phases_completed': ['analysis', 'proposal', 'testing', 'decision', 'deployment'],
        'outcome': 'improvement_deployed'
    }


@when('the meta-analysis engine analyzes the traces')
def step_analyze_execution_traces(context):
    """Analyze execution traces for bottlenecks."""
    context.bottleneck_analysis = context.meta_learning_engine.analyze_bottlenecks(
        context.execution_traces
    )


@when('the meta-analysis engine generates improvement proposals')
def step_generate_improvement_proposals(context):
    """Generate improvement proposals based on bottleneck analysis."""
    context.improvement_proposals = context.meta_learning_engine.generate_proposals(
        context.identified_bottleneck
    )


@when('the sandbox A/B testing is executed')
def step_execute_sandbox_ab_testing(context):
    """Execute sandbox A/B testing."""
    context.ab_test_results = context.ab_tester.run_sandbox_test(
        control_config='standard_6_roles',
        treatment_config=context.role_addition_proposal,
        test_queries=50
    )


@when('a full meta-learning cycle is executed')
def step_execute_full_meta_cycle(context):
    """Execute a complete meta-learning cycle."""
    context.meta_cycle_result = context.meta_learning_engine.execute_full_cycle()


@when('performance is measured post-deployment')
def step_measure_post_deployment_performance(context):
    """Measure performance after deployment."""
    context.post_deployment_metrics = context.meta_learning_engine.measure_performance()


@when('dead logic analysis is performed')
def step_perform_dead_logic_analysis(context):
    """Perform dead logic analysis."""
    context.dead_logic_analysis = context.meta_learning_engine.analyze_dead_logic()


@when('prompt analysis identifies bloat')
def step_analyze_prompt_bloat(context):
    """Analyze prompt bloat."""
    context.prompt_bloat_analysis = context.meta_learning_engine.analyze_prompt_bloat()


@when('routing analysis identifies waste patterns')
def step_analyze_routing_waste(context):
    """Analyze routing waste patterns."""
    context.routing_waste_analysis = context.meta_learning_engine.analyze_routing_waste()


@when('tool reliability analysis is performed')
def step_analyze_tool_reliability(context):
    """Analyze tool reliability."""
    context.tool_reliability_analysis = context.meta_learning_engine.analyze_tool_reliability()


@when('workload analysis identifies patterns')
def step_analyze_workload_patterns(context):
    """Analyze workload patterns."""
    context.workload_analysis = context.meta_learning_engine.analyze_workload_patterns()


@when('feedback is integrated into meta-learning')
def step_integrate_user_feedback(context):
    """Integrate user feedback into meta-learning."""
    context.feedback_integration = context.meta_learning_engine.integrate_feedback(
        context.user_feedback
    )


@when('variance analysis identifies sources')
def step_analyze_variance_sources(context):
    """Analyze variance sources."""
    context.variance_analysis = context.meta_learning_engine.analyze_variance_sources()


@when('rollback conditions are met')
def step_check_rollback_conditions(context):
    """Check if rollback conditions are met."""
    context.rollback_evaluation = context.meta_learning_engine.evaluate_rollback_conditions(
        context.performance_degradation,
        context.deployed_improvement_monitor['rollback_conditions']
    )


@when('the meta-report is generated')
def step_generate_meta_report(context):
    """Generate comprehensive meta-report."""
    context.meta_report = context.meta_learning_engine.generate_meta_report(
        context.completed_cycle
    )


@then('bottlenecks should be automatically identified')
def step_verify_bottleneck_identification(context):
    """Verify bottlenecks are automatically identified."""
    analysis = context.bottleneck_analysis
    assert analysis.bottlenecks_identified
    assert len(analysis.bottlenecks) > 0
    context.test_context.log(f"Bottlenecks identified: {len(analysis.bottlenecks)}")


@then('the bottleneck analysis should include')
def step_verify_bottleneck_analysis_content(context):
    """Verify bottleneck analysis contains expected content."""
    analysis = context.bottleneck_analysis
    
    for row in context.table:
        component = row['Component']
        issue_type = row['Issue Type']
        frequency = row['Frequency']
        impact = row['Impact']
        
        # Find matching bottleneck
        matching_bottleneck = None
        for bottleneck in analysis.bottlenecks:
            if component.lower() in bottleneck.component.lower():
                matching_bottleneck = bottleneck
                break
        
        assert matching_bottleneck is not None, f"Bottleneck not found: {component}"
        assert issue_type.lower() in matching_bottleneck.issue_type.lower()
        assert matching_bottleneck.frequency_percent >= int(frequency.strip('%'))
        assert matching_bottleneck.impact.lower() == impact.lower()


@then('bottleneck severity should be ranked by impact')
def step_verify_bottleneck_ranking(context):
    """Verify bottlenecks are ranked by impact."""
    bottlenecks = context.bottleneck_analysis.bottlenecks
    
    # Verify ranking by impact (high > medium > low)
    impact_values = {'high': 3, 'medium': 2, 'low': 1}
    
    for i in range(len(bottlenecks) - 1):
        current_impact = impact_values[bottlenecks[i].impact.lower()]
        next_impact = impact_values[bottlenecks[i + 1].impact.lower()]
        assert current_impact >= next_impact, "Bottlenecks not properly ranked by impact"
    
    context.test_context.log("Bottlenecks properly ranked by impact")


@then('a specific proposal should be created with')
def step_verify_specific_proposal_content(context):
    """Verify specific proposal content."""
    proposal = context.improvement_proposals[0]  # First proposal
    
    for row in context.table:
        field = row['Field']
        content = row['Content']
        
        if field == 'Change':
            assert content.lower() in proposal.change_description.lower()
        elif field == 'Hypothesis':
            assert content.lower() in proposal.hypothesis.lower()
        elif field == 'Risk Level':
            assert proposal.risk_level.lower() == content.lower()
        elif field == 'Test Plan':
            assert content.lower() in proposal.test_plan.lower()
        elif field == 'Metrics':
            expected_metrics = [m.strip() for m in content.split(',')]
            for metric in expected_metrics:
                assert metric.lower() in [m.lower() for m in proposal.metrics]
        elif field == 'Rollback':
            assert content.lower() in proposal.rollback_plan.lower()


@then('the proposal should be actionable and specific')
def step_verify_proposal_actionable(context):
    """Verify proposal is actionable and specific."""
    proposal = context.improvement_proposals[0]
    
    assert proposal.is_actionable()
    assert proposal.is_specific()
    assert proposal.has_measurable_outcomes()
    context.test_context.log("Proposal is actionable and specific")


@then('the test should run with')
def step_verify_ab_test_configuration(context):
    """Verify A/B test configuration."""
    ab_results = context.ab_test_results
    
    for row in context.table:
        group = row['Group']
        configuration = row['Configuration']
        expected_outcome = row['Expected Outcome']
        
        if group == 'Control':
            assert ab_results.control_config == configuration
        elif group == 'Treatment':
            assert ab_results.treatment_config['role_name'] in configuration
            assert expected_outcome.lower() in ab_results.treatment_hypothesis.lower()


@then('metrics should be collected for')
def step_verify_metrics_collected(context):
    """Verify metrics are collected for A/B test."""
    ab_results = context.ab_test_results
    
    for row in context.table:
        metric = row['Metric']
        control_value = row['Control']
        treatment_value = row['Treatment']
        delta = row['Delta']
        
        assert metric in ab_results.metrics
        metric_data = ab_results.metrics[metric]
        
        # Verify values are close to expected (allowing for test variation)
        if '%' in control_value:
            expected_control = float(control_value.strip('%')) / 100
            assert abs(metric_data.control_value - expected_control) < 0.05
        
        if '%' in treatment_value:
            expected_treatment = float(treatment_value.strip('%')) / 100
            assert abs(metric_data.treatment_value - expected_treatment) < 0.05


@then('statistical significance should be calculated')
def step_verify_statistical_significance(context):
    """Verify statistical significance is calculated."""
    ab_results = context.ab_test_results
    
    assert ab_results.statistical_analysis_performed
    assert ab_results.p_values is not None
    assert ab_results.confidence_intervals is not None
    
    for metric, p_value in ab_results.p_values.items():
        assert 0 <= p_value <= 1, f"Invalid p-value for {metric}: {p_value}"
    
    context.test_context.log("Statistical significance properly calculated")


@then('the cycle should complete these phases')
def step_verify_cycle_phases(context):
    """Verify meta-learning cycle completes all phases."""
    cycle_result = context.meta_cycle_result
    
    for row in context.table:
        phase = row['Phase']
        duration = row['Duration']
        deliverable = row['Deliverable']
        
        assert phase in cycle_result.completed_phases
        
        phase_info = cycle_result.phase_info[phase]
        assert phase_info.deliverable_created
        assert deliverable.lower() in phase_info.deliverable_type.lower()
        
        # Verify duration is reasonable (parse duration string)
        duration_minutes = int(duration.split()[0])
        assert phase_info.duration_minutes <= duration_minutes


@then('each phase should produce required artifacts')
def step_verify_phase_artifacts(context):
    """Verify each phase produces required artifacts."""
    cycle_result = context.meta_cycle_result
    
    required_artifacts = {
        'Log Analysis': 'bottleneck_report.json',
        'Proposal Gen': 'improvement_proposals.json',
        'Sandbox Test': 'ab_test_results.json',
        'Decision Making': 'deployment_decision.json',
        'Deployment': 'deployment_log.json',
        'Validation': 'performance_validation.json'
    }
    
    for phase, expected_artifact in required_artifacts.items():
        assert expected_artifact in cycle_result.artifacts
        artifact = cycle_result.artifacts[expected_artifact]
        assert artifact.created_successfully
    
    context.test_context.log("All required artifacts produced")


@then('improvements should be measurable')
def step_verify_measurable_improvements(context):
    """Verify improvements are measurable."""
    post_metrics = context.post_deployment_metrics
    baseline_metrics = context.baseline_performance
    
    for row in context.table:
        metric = row['Metric']
        baseline = row['Baseline']
        post_improvement = row['Post-Improvement']
        required_delta = row['Required Delta']
        
        baseline_value = baseline_metrics[metric.lower().replace(' ', '_')]
        actual_value = post_metrics[metric.lower().replace(' ', '_')]
        
        # Parse required delta
        if required_delta.startswith('>='):
            min_improvement = float(required_delta.replace('>=', '').strip().rstrip('%')) / 100
            if '%' in post_improvement and baseline_value > 0:
                actual_improvement = (actual_value - baseline_value) / baseline_value
                assert actual_improvement >= min_improvement
        elif required_delta.startswith('<='):
            max_degradation = float(required_delta.replace('<=', '').strip().rstrip('%')) / 100
            if '%' in post_improvement:
                actual_change = (actual_value - baseline_value) / baseline_value
                assert actual_change <= max_degradation
    
    context.test_context.log("Improvements are measurable and meet requirements")


@then('improvements should persist over time')
def step_verify_improvement_persistence(context):
    """Verify improvements persist over time."""
    persistence_check = context.meta_learning_engine.check_improvement_persistence()
    
    assert persistence_check.improvements_stable
    assert persistence_check.no_significant_regression
    assert persistence_check.measurement_period_days >= 7  # At least a week
    
    context.test_context.log("Improvements persist over time")


@then('no regression should occur in other metrics')
def step_verify_no_regression(context):
    """Verify no regression in other metrics."""
    regression_check = context.meta_learning_engine.check_for_regressions()
    
    assert not regression_check.regression_detected
    assert regression_check.all_metrics_stable_or_improved
    
    for metric, change in regression_check.metric_changes.items():
        assert change >= -0.02, f"Regression detected in {metric}: {change:.3f}"
    
    context.test_context.log("No regression detected in other metrics")


@then('unused components should be identified')
def step_verify_unused_components_identified(context):
    """Verify unused components are identified."""
    dead_logic = context.dead_logic_analysis
    
    for row in context.table:
        component_type = row['Component Type']
        status = row['Status']
        action = row['Action']
        
        assert component_type in dead_logic.identified_components
        component_info = dead_logic.identified_components[component_type]
        
        assert component_info.status.lower() == status.lower()
        assert action.lower() in component_info.recommended_action.lower()


@then('removal should be tested before deployment')
def step_verify_removal_testing(context):
    """Verify removal is tested before deployment."""
    dead_logic = context.dead_logic_analysis
    
    assert dead_logic.testing_plan_created
    assert dead_logic.safety_checks_configured
    assert dead_logic.rollback_plan_available
    
    context.test_context.log("Dead logic removal properly tested")


@then('performance impact should be minimal')
def step_verify_minimal_performance_impact(context):
    """Verify minimal performance impact from removal."""
    dead_logic = context.dead_logic_analysis
    
    performance_impact = dead_logic.estimated_performance_impact
    assert performance_impact.latency_change <= 0.05  # Max 5% change
    assert performance_impact.accuracy_change >= -0.01  # Max 1% degradation
    assert performance_impact.resource_usage_change <= 0.0  # Should reduce resources
    
    context.test_context.log("Minimal performance impact verified")


@then('optimization should reduce token usage')
def step_verify_token_usage_reduction(context):
    """Verify prompt optimization reduces token usage."""
    prompt_optimization = context.prompt_bloat_analysis.optimization_results
    
    for row in context.table:
        role = row['Role']
        original_tokens = int(row['Original Tokens'])
        optimized_tokens = int(row['Optimized Tokens'])
        expected_reduction = int(row['Reduction'].strip('%'))
        
        role_optimization = prompt_optimization[role]
        assert role_optimization.original_tokens == original_tokens
        assert role_optimization.optimized_tokens == optimized_tokens
        
        actual_reduction = ((original_tokens - optimized_tokens) / original_tokens) * 100
        assert actual_reduction >= expected_reduction
    
    context.test_context.log("Token usage reduction verified")


@then('quality should be maintained or improved')
def step_verify_quality_maintained_or_improved(context):
    """Verify quality is maintained or improved after optimization."""
    optimization_results = context.prompt_bloat_analysis.optimization_results
    
    for role, results in optimization_results.items():
        quality_change = results.quality_score_change
        assert quality_change >= -0.02, f"Quality degradation in {role}: {quality_change:.3f}"
    
    context.test_context.log("Quality maintained or improved after optimization")


@then('optimization should be validated with test cases')
def step_verify_optimization_validation(context):
    """Verify optimization is validated with test cases."""
    validation_results = context.prompt_bloat_analysis.validation_results
    
    assert validation_results.test_cases_run > 0
    assert validation_results.pass_rate >= 0.95  # 95% pass rate
    assert validation_results.regression_tests_passed
    
    context.test_context.log("Optimization properly validated with test cases")


@then('optimization opportunities should be found')
def step_verify_routing_optimization_opportunities(context):
    """Verify routing optimization opportunities are found."""
    waste_analysis = context.routing_waste_analysis
    
    for row in context.table:
        waste_type = row['Waste Type']
        frequency = int(row['Frequency'].strip('%'))
        impact = row['Impact']
        solution = row['Solution']
        
        assert waste_type in waste_analysis.identified_waste_types
        waste_info = waste_analysis.identified_waste_types[waste_type]
        
        assert waste_info.frequency_percent >= frequency
        assert waste_info.impact.lower() == impact.lower()
        assert solution.lower() in waste_info.recommended_solution.lower()


@then('waste reduction should be prioritized by impact')
def step_verify_waste_reduction_prioritization(context):
    """Verify waste reduction is prioritized by impact."""
    waste_analysis = context.routing_waste_analysis
    priorities = waste_analysis.optimization_priorities
    
    # Verify high impact items come first
    impact_order = {'high': 3, 'medium': 2, 'low': 1}
    
    for i in range(len(priorities) - 1):
        current_impact = impact_order[priorities[i].impact.lower()]
        next_impact = impact_order[priorities[i + 1].impact.lower()]
        assert current_impact >= next_impact
    
    context.test_context.log("Waste reduction properly prioritized by impact")


@then('problematic tools should be identified')
def step_verify_problematic_tools_identified(context):
    """Verify problematic tools are identified."""
    tool_analysis = context.tool_reliability_analysis
    
    for row in context.table:
        tool_name = row['Tool Name']
        success_rate = float(row['Success Rate'].strip('%')) / 100
        avg_latency = float(row['Avg Latency'].rstrip('s'))
        failure_mode = row['Failure Mode']
        mitigation = row['Mitigation']
        
        assert tool_name in tool_analysis.problematic_tools
        tool_info = tool_analysis.problematic_tools[tool_name]
        
        assert abs(tool_info.success_rate - success_rate) < 0.05
        assert abs(tool_info.avg_latency - avg_latency) < 0.5
        assert failure_mode.lower() in tool_info.primary_failure_mode.lower()
        assert mitigation.lower() in tool_info.recommended_mitigation.lower()


@then('mitigation strategies should be implemented')
def step_verify_mitigation_strategies_implemented(context):
    """Verify mitigation strategies are implemented."""
    tool_analysis = context.tool_reliability_analysis
    
    for tool_name, tool_info in tool_analysis.problematic_tools.items():
        assert tool_info.mitigation_plan_created
        assert tool_info.mitigation_implemented or tool_info.mitigation_scheduled
    
    context.test_context.log("Mitigation strategies implemented for problematic tools")


@then('tool performance should be continuously monitored')
def step_verify_continuous_tool_monitoring(context):
    """Verify continuous tool performance monitoring."""
    monitoring_setup = context.tool_reliability_analysis.monitoring_setup
    
    assert monitoring_setup.continuous_monitoring_enabled
    assert monitoring_setup.alert_thresholds_configured
    assert monitoring_setup.automated_fallback_enabled
    
    context.test_context.log("Continuous tool performance monitoring enabled")


@then('configuration should adapt to optimize for common patterns')
def step_verify_adaptive_configuration(context):
    """Verify configuration adapts for common patterns."""
    workload_optimization = context.workload_analysis.optimization_recommendations
    
    for row in context.table:
        query_type = row['Query Type']
        frequency = int(row['Frequency'].strip('%'))
        optimal_config = row['Optimal Config']
        expected_improvement = row['Expected Improvement']
        
        assert query_type in workload_optimization
        optimization = workload_optimization[query_type]
        
        assert optimization.frequency_percent == frequency
        assert optimal_config.lower() in optimization.recommended_config.lower()
        assert expected_improvement.lower() in optimization.expected_benefit.lower()


@then('adaptation should be gradual and tested')
def step_verify_gradual_adaptation(context):
    """Verify adaptation is gradual and tested."""
    adaptation_plan = context.workload_analysis.adaptation_plan
    
    assert adaptation_plan.gradual_rollout_enabled
    assert adaptation_plan.a_b_testing_required
    assert adaptation_plan.rollback_plan_available
    assert adaptation_plan.monitoring_during_transition
    
    context.test_context.log("Adaptation is gradual and properly tested")


@then('user feedback should influence optimization')
def step_verify_feedback_influences_optimization(context):
    """Verify user feedback influences optimization."""
    feedback_integration = context.feedback_integration
    
    for row in context.table:
        feedback_type = row['Feedback Type']
        weight = row['Weight']
        impact = row['Impact on Meta-Learning']
        
        assert feedback_type in feedback_integration.integrated_feedback
        feedback_info = feedback_integration.integrated_feedback[feedback_type]
        
        assert feedback_info.weight.lower() == weight.lower()
        assert impact.lower() in feedback_info.optimization_impact.lower()


@then('feedback trends should drive systematic improvements')
def step_verify_feedback_drives_improvements(context):
    """Verify feedback trends drive systematic improvements."""
    trend_analysis = context.feedback_integration.trend_analysis
    
    assert trend_analysis.trends_identified
    assert trend_analysis.improvement_initiatives_created
    assert len(trend_analysis.systematic_improvements) > 0
    
    context.test_context.log("Feedback trends drive systematic improvements")


@then('user satisfaction metrics should increase over time')
def step_verify_increasing_satisfaction(context):
    """Verify user satisfaction metrics increase over time."""
    satisfaction_trends = context.feedback_integration.satisfaction_trends
    
    assert satisfaction_trends.overall_trend == 'increasing'
    assert satisfaction_trends.trend_significance >= 0.05  # Statistically significant
    
    for metric in ['accuracy_rating', 'speed_satisfaction', 'completeness', 'relevance']:
        metric_trend = satisfaction_trends.metric_trends[metric]
        assert metric_trend.slope > 0, f"Non-increasing trend for {metric}"
    
    context.test_context.log("User satisfaction metrics increasing over time")


@then('variance reduction strategies should be implemented')
def step_verify_variance_reduction_strategies(context):
    """Verify variance reduction strategies are implemented."""
    variance_reduction = context.variance_analysis.reduction_strategies
    
    for row in context.table:
        variance_source = row['Variance Source']
        current_level = float(row['Current Level'].strip('%')) / 100
        target_level = float(row['Target Level'].strip('%')) / 100
        strategy = row['Strategy']
        
        assert variance_source in variance_reduction
        reduction_info = variance_reduction[variance_source]
        
        assert abs(reduction_info.current_level - current_level) < 0.005
        assert abs(reduction_info.target_level - target_level) < 0.005
        assert strategy.lower() in reduction_info.implemented_strategy.lower()


@then('variance should consistently decrease over time')
def step_verify_decreasing_variance(context):
    """Verify variance consistently decreases over time."""
    variance_trends = context.variance_analysis.variance_trends
    
    for source, trend in variance_trends.items():
        assert trend.direction == 'decreasing'
        assert trend.consistency_score >= 0.8  # 80% consistency
    
    context.test_context.log("Variance consistently decreasing over time")


@then('the {target_stability:f}% stability target should be maintained')
def step_verify_stability_target_maintained(context, target_stability):
    """Verify stability target is maintained."""
    current_stability = context.variance_analysis.overall_stability_score
    target_ratio = target_stability / 100
    
    assert current_stability >= target_ratio
    context.test_context.log(f"Stability target maintained: {current_stability:.3f} >= {target_ratio:.3f}")


@then('automatic rollback should be triggered')
def step_verify_automatic_rollback_triggered(context):
    """Verify automatic rollback is triggered."""
    rollback_result = context.rollback_evaluation
    
    assert rollback_result.rollback_triggered
    assert rollback_result.trigger_reasons is not None
    assert len(rollback_result.trigger_reasons) > 0
    
    context.test_context.log(f"Automatic rollback triggered: {rollback_result.trigger_reasons}")


@then('rollback should restore previous configuration')
def step_verify_rollback_restores_config(context):
    """Verify rollback restores previous configuration."""
    rollback_result = context.rollback_evaluation
    
    assert rollback_result.previous_config_restored
    assert rollback_result.rollback_completed_successfully
    
    # Verify configuration matches previous state
    current_config = context.meta_learning_engine.get_current_config()
    previous_config = rollback_result.restored_configuration
    
    assert current_config == previous_config
    context.test_context.log("Previous configuration successfully restored")


@then('incident should be logged for analysis')
def step_verify_incident_logged(context):
    """Verify rollback incident is logged."""
    rollback_result = context.rollback_evaluation
    
    assert rollback_result.incident_logged
    assert rollback_result.incident_id is not None
    
    incident_log = context.meta_learning_engine.get_incident_log(rollback_result.incident_id)
    assert incident_log.severity == 'high'
    assert 'rollback' in incident_log.event_type
    
    context.test_context.log(f"Rollback incident logged: {rollback_result.incident_id}")


@then('rollback effectiveness should be verified')
def step_verify_rollback_effectiveness(context):
    """Verify rollback effectiveness."""
    effectiveness_check = context.meta_learning_engine.verify_rollback_effectiveness()
    
    assert effectiveness_check.metrics_restored_to_baseline
    assert effectiveness_check.no_residual_issues
    assert effectiveness_check.system_stable_post_rollback
    
    context.test_context.log("Rollback effectiveness verified")


@then('the report should contain comprehensive analysis')
def step_verify_comprehensive_report_analysis(context):
    """Verify meta-report contains comprehensive analysis."""
    report = context.meta_report
    
    for row in context.table:
        section = row['Section']
        content = row['Content']
        
        assert section in report.sections
        section_content = report.sections[section]
        
        # Verify content elements are present
        content_elements = [elem.strip() for elem in content.split(',')]
        for element in content_elements:
            assert element.lower() in section_content.lower() or \
                   any(element.lower() in key.lower() for key in section_content.keys() if isinstance(section_content, dict))


@then('the report should be stored in artifacts/meta_report.md')
def step_verify_report_storage_location(context):
    """Verify report is stored in correct location."""
    report = context.meta_report
    
    assert report.storage_path == 'artifacts/meta_report.md'
    assert report.file_created
    assert report.markdown_formatted
    
    context.test_context.log("Meta-report stored in artifacts/meta_report.md")


@then('report should be human-readable and actionable')
def step_verify_report_human_readable(context):
    """Verify report is human-readable and actionable."""
    report = context.meta_report
    
    readability_score = report.calculate_readability_score()
    assert readability_score >= 0.8  # 80% readability
    
    assert report.contains_actionable_recommendations
    assert report.recommendations_prioritized
    assert report.next_steps_clearly_defined
    
    context.test_context.log(f"Report readability: {readability_score:.2%}")
    context.test_context.log("Report is human-readable and actionable")