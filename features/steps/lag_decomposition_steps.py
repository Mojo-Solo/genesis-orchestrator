"""
Step definitions for LAG (Logic-Aware Generation) decomposition testing.
"""

from behave import given, when, then, step
from genesis_test_framework import GenesisOrchestrator, TestContext
import json
import time
import re


@given('the GENESIS orchestrator is initialized')
def step_init_orchestrator(context):
    """Initialize the GENESIS orchestrator for testing."""
    context.orchestrator = GenesisOrchestrator()
    context.test_context = TestContext()
    assert context.orchestrator.is_initialized()


@given('the LAG decomposition engine is configured')
def step_configure_lag_engine(context):
    """Configure the LAG decomposition engine with test settings."""
    context.orchestrator.configure_lag_engine({
        'cartesian_method': True,
        'logical_ordering': True,
        'terminator_enabled': True
    })
    assert context.orchestrator.lag_engine.is_configured()


@given('the cognitive load threshold is set to "{threshold}"')
def step_set_cognitive_threshold(context, threshold):
    """Set the cognitive load threshold for decomposition."""
    context.orchestrator.set_cognitive_threshold(float(threshold))
    context.cognitive_threshold = float(threshold)


@given('the maximum decomposition depth is set to "{depth}"')
def step_set_max_depth(context, depth):
    """Set maximum decomposition depth."""
    context.orchestrator.set_max_decomposition_depth(int(depth))
    context.max_depth = int(depth)


@given('I have a complex multi-hop question "{question}"')
def step_set_complex_question(context, question):
    """Set a complex multi-hop question for testing."""
    context.question = question
    context.question_type = 'complex_multi_hop'


@given('I have a question requiring ordered resolution "{question}"')
def step_set_ordered_question(context, question):
    """Set a question requiring ordered resolution."""
    context.question = question
    context.question_type = 'ordered_resolution'


@given('I have an impossible question "{question}"')
def step_set_impossible_question(context, question):
    """Set an impossible question to test terminator."""
    context.question = question
    context.question_type = 'impossible'


@given('I have a question with contradictory premises "{question}"')
def step_set_contradictory_question(context, question):
    """Set a question with contradictory premises."""
    context.question = question
    context.question_type = 'contradictory'


@given('I have a question with insufficient retrievable information "{question}"')
def step_set_insufficient_info_question(context, question):
    """Set a question with insufficient information."""
    context.question = question
    context.question_type = 'insufficient_info'


@given('I have a verifiable multi-hop question "{question}"')
def step_set_verifiable_question(context, question):
    """Set a verifiable multi-hop question."""
    context.question = question
    context.question_type = 'verifiable'


@given('I have the oracle answer "{answer}"')
def step_set_oracle_answer(context, answer):
    """Set the oracle answer for comparison."""
    context.oracle_answer = answer


@given('I have a benchmark set of {count:d} complex multi-hop questions')
def step_set_benchmark_questions(context, count):
    """Set a benchmark set of questions."""
    context.benchmark_questions = context.test_context.get_benchmark_questions(count)
    context.benchmark_count = count


@given('I have baseline RAG system results for comparison')
def step_set_rag_baseline(context):
    """Set baseline RAG system results."""
    context.rag_baseline = context.test_context.get_rag_baseline()


@given('the maximum steps limit is set to "{limit}"')
def step_set_max_steps(context, limit):
    """Set maximum steps limit for circuit breaker."""
    context.orchestrator.set_max_steps(int(limit))
    context.max_steps = int(limit)


@given('the maximum time limit is set to "{limit} seconds"')
def step_set_max_time(context, limit):
    """Set maximum time limit for circuit breaker."""
    context.orchestrator.set_max_time(int(limit))
    context.max_time = int(limit)


@given('I have an ambiguous question "{question}"')
def step_set_ambiguous_question(context, question):
    """Set an ambiguous question."""
    context.question = question
    context.question_type = 'ambiguous'


@when('I submit the question to the LAG decomposition engine')
def step_submit_to_lag(context):
    """Submit question to LAG decomposition engine."""
    context.start_time = time.time()
    context.result = context.orchestrator.process_with_lag(context.question)
    context.end_time = time.time()


@when('the LAG engine decomposes the question')
def step_decompose_question(context):
    """Decompose the question using LAG engine."""
    context.decomposition_result = context.orchestrator.decompose_question(context.question)


@when('the system begins processing the sub-questions')
def step_process_subquestions(context):
    """Begin processing sub-questions."""
    context.processing_result = context.orchestrator.process_subquestions()


@when('the LAG engine processes the question')
def step_process_with_lag(context):
    """Process question with LAG engine."""
    context.processing_result = context.orchestrator.process_with_lag(context.question)


@when('the Retriever attempts to find supporting documents')
def step_retriever_search(context):
    """Attempt to find supporting documents."""
    context.retrieval_result = context.orchestrator.retrieve_documents(context.question)


@when('the Critic evaluates the retrieval confidence')
def step_critic_evaluation(context):
    """Evaluate retrieval confidence with Critic."""
    context.critic_result = context.orchestrator.evaluate_with_critic(
        context.retrieval_result
    )


@when('I process the question through LAG decomposition')
def step_process_through_lag(context):
    """Process question through complete LAG decomposition."""
    context.lag_result = context.orchestrator.full_lag_process(context.question)


@when('the system completes all steps without termination')
def step_complete_without_termination(context):
    """Verify system completes without termination."""
    assert not context.lag_result.terminated
    assert context.lag_result.completed


@when('I process all questions through LAG decomposition')
def step_process_benchmark_lag(context):
    """Process benchmark questions through LAG."""
    context.lag_benchmark_results = []
    for question in context.benchmark_questions:
        result = context.orchestrator.process_with_lag(question)
        context.lag_benchmark_results.append(result)


@when('the LAG engine processes step 1 "{step_question}"')
def step_process_step_one(context, step_question):
    """Process the first step of decomposition."""
    context.step_one_result = context.orchestrator.process_step(1, step_question)


@when('retrieves the answer "{answer}"')
def step_retrieve_answer(context, answer):
    """Retrieve specific answer for step."""
    context.step_one_answer = answer
    context.orchestrator.set_step_answer(1, answer)


@when('I submit a question that could lead to infinite decomposition')
def step_submit_infinite_decomposition(context):
    """Submit question that could cause infinite decomposition."""
    context.runaway_question = "What is the answer to this question about itself?"
    context.runaway_result = context.orchestrator.process_with_lag(context.runaway_question)


@when('I process it through the LAG engine')
def step_process_for_artifacts(context):
    """Process question to generate artifacts."""
    context.artifacts_result = context.orchestrator.process_with_full_artifacts(context.question)


@when('the LAG engine attempts decomposition')
def step_attempt_decomposition(context):
    """Attempt decomposition for ambiguous question."""
    context.decomposition_attempt = context.orchestrator.attempt_decomposition(context.question)


@then('the system should compute cognitive load CL(q) greater than threshold')
def step_verify_cognitive_load(context):
    """Verify cognitive load exceeds threshold."""
    cognitive_load = context.result.cognitive_load
    assert cognitive_load > context.cognitive_threshold
    context.test_context.log(f"Cognitive load {cognitive_load} > threshold {context.cognitive_threshold}")


@then('the question should be decomposed into logical sub-questions')
def step_verify_decomposition(context):
    """Verify question was decomposed into sub-questions."""
    assert hasattr(context.result, 'subquestions')
    assert len(context.result.subquestions) > 1
    context.test_context.log(f"Decomposed into {len(context.result.subquestions)} sub-questions")


@then('the decomposition should include the sub-question "{subquestion}"')
def step_verify_subquestion_included(context, subquestion):
    """Verify specific sub-question is included."""
    subquestions = [sq.text for sq in context.result.subquestions]
    assert any(subquestion.lower() in sq.lower() for sq in subquestions)


@then('the sub-questions should be ordered by dependency')
def step_verify_dependency_ordering(context):
    """Verify sub-questions are properly ordered by dependencies."""
    dependencies = context.result.dependency_graph
    assert context.orchestrator.validate_dependency_order(dependencies)


@then('the preflight plan should be generated with exact step order')
def step_verify_preflight_plan(context):
    """Verify preflight plan generation."""
    assert hasattr(context.result, 'preflight_plan')
    plan = context.result.preflight_plan
    assert 'steps' in plan
    assert len(plan['steps']) > 0
    # Verify steps have proper ordering
    for i, step in enumerate(plan['steps']):
        assert 'id' in step
        assert 'q' in step
        if 'depends_on' in step:
            for dep in step['depends_on']:
                dep_index = next(j for j, s in enumerate(plan['steps']) if s['id'] == dep)
                assert dep_index < i  # Dependencies come before current step


@then('no terminator should be triggered during decomposition')
def step_verify_no_terminator(context):
    """Verify no terminator was triggered."""
    assert not context.result.terminator_triggered
    context.test_context.log("No terminator triggered - decomposition completed normally")


@then('the first step should identify "{step_text}"')
def step_verify_first_step(context, step_text):
    """Verify first step content."""
    first_step = context.decomposition_result.steps[0]
    assert step_text.lower() in first_step.question.lower()


@then('the second step should depend on the first and ask "{step_text}"')
def step_verify_second_step_dependency(context, step_text):
    """Verify second step depends on first."""
    second_step = context.decomposition_result.steps[1]
    assert first_step.id in second_step.depends_on
    assert any(part in second_step.question.lower() for part in step_text.lower().split())


@then('the third step should depend on the second and ask "{step_text}"')
def step_verify_third_step_dependency(context, step_text):
    """Verify third step depends on second."""
    third_step = context.decomposition_result.steps[2]
    second_step = context.decomposition_result.steps[1]
    assert second_step.id in third_step.depends_on
    assert any(part in third_step.question.lower() for part in step_text.lower().split())


@then('each step should reference prior answers in its context')
def step_verify_context_reference(context):
    """Verify steps reference prior answers."""
    for i, step in enumerate(context.decomposition_result.steps[1:], 1):
        if step.depends_on:
            assert step.context_includes_prior_answers
            context.test_context.log(f"Step {i+1} properly references prior answers")


@then('the dependency chain should be preserved in the execution trace')
def step_verify_dependency_trace(context):
    """Verify dependency chain in execution trace."""
    trace = context.decomposition_result.execution_trace
    assert trace.preserves_dependency_chain()


@then('the Critic should detect the question as "{detection_type}"')
def step_verify_critic_detection(context, detection_type):
    """Verify Critic detection type."""
    assert context.processing_result.critic_flag == detection_type
    context.test_context.log(f"Critic correctly detected: {detection_type}")


@then('the logical terminator should be triggered')
def step_verify_terminator_triggered(context):
    """Verify logical terminator was triggered."""
    assert context.processing_result.terminator_triggered
    assert context.processing_result.terminator_reason is not None


@then('processing should halt within {max_steps:d} steps')
def step_verify_halt_within_steps(context, max_steps):
    """Verify processing halted within step limit."""
    actual_steps = context.processing_result.steps_executed
    assert actual_steps <= max_steps
    context.test_context.log(f"Processing halted in {actual_steps} steps (<= {max_steps})")


@then('the terminator reason should be logged in the execution trace')
def step_verify_terminator_logged(context):
    """Verify terminator reason is logged."""
    trace = context.processing_result.execution_trace
    assert trace.contains_terminator_reason()
    assert trace.terminator_reason is not None


@then('no hallucinated answer should be provided')
def step_verify_no_hallucination(context):
    """Verify no hallucinated answer was provided."""
    assert not context.processing_result.provided_answer
    assert context.processing_result.explicit_uncertainty


@then('the Critic should detect a "{detection_type}"')
def step_verify_contradiction_detection(context, detection_type):
    """Verify Critic detects contradiction."""
    assert context.processing_result.critic_flag == detection_type


@then('the logical terminator should fire')
def step_verify_terminator_fire(context):
    """Verify logical terminator fires."""
    assert context.processing_result.terminator_triggered


@then('the contradiction should be explained in the terminator reason')
def step_verify_contradiction_explanation(context):
    """Verify contradiction explanation."""
    reason = context.processing_result.terminator_reason
    assert 'contradiction' in reason.lower() or 'conflict' in reason.lower()


@then('processing should stop before generating a false answer')
def step_verify_no_false_answer(context):
    """Verify no false answer generated."""
    assert not context.processing_result.generated_answer
    assert context.processing_result.stopped_early


@then('the Critic should flag "{flag_type}"')
def step_verify_critic_flag(context, flag_type):
    """Verify Critic flag type."""
    assert context.critic_result.flag == flag_type


@then('the logical terminator should activate')
def step_verify_terminator_activate(context):
    """Verify terminator activation."""
    assert context.critic_result.triggered_terminator


@then('the system should report insufficient information rather than guessing')
def step_verify_insufficient_info_handling(context):
    """Verify proper handling of insufficient information."""
    assert context.critic_result.reported_insufficient_info
    assert not context.critic_result.made_guess


@then('the final answer should match the oracle within acceptable tolerance')
def step_verify_oracle_match(context):
    """Verify answer matches oracle."""
    similarity = context.orchestrator.calculate_similarity(
        context.lag_result.final_answer,
        context.oracle_answer
    )
    assert similarity >= 0.85  # 85% similarity threshold
    context.test_context.log(f"Answer similarity to oracle: {similarity:.2%}")


@then('the reasoning chain should be logically sound')
def step_verify_logical_reasoning(context):
    """Verify reasoning chain is logically sound."""
    assert context.lag_result.reasoning_chain.is_logically_sound()


@then('each step should have proper citations')
def step_verify_citations(context):
    """Verify each step has proper citations."""
    for step in context.lag_result.steps:
        assert len(step.citations) > 0
        assert all(citation.is_valid() for citation in step.citations)


@then('LAG accuracy should be higher than RAG baseline')
def step_verify_lag_accuracy(context):
    """Verify LAG accuracy exceeds RAG baseline."""
    lag_accuracy = context.orchestrator.calculate_benchmark_accuracy(
        context.lag_benchmark_results
    )
    rag_accuracy = context.rag_baseline.accuracy
    assert lag_accuracy > rag_accuracy
    context.test_context.log(f"LAG accuracy {lag_accuracy:.2%} > RAG baseline {rag_accuracy:.2%}")


@then('the reasoning transparency should be superior')
def step_verify_reasoning_transparency(context):
    """Verify reasoning transparency is superior."""
    transparency_score = context.orchestrator.calculate_transparency_score(
        context.lag_benchmark_results
    )
    baseline_transparency = context.rag_baseline.transparency_score
    assert transparency_score > baseline_transparency


@then('the average confidence score should be higher')
def step_verify_higher_confidence(context):
    """Verify higher average confidence."""
    lag_confidence = context.orchestrator.calculate_average_confidence(
        context.lag_benchmark_results
    )
    baseline_confidence = context.rag_baseline.confidence
    assert lag_confidence > baseline_confidence


@then('the citation quality should be improved')
def step_verify_citation_quality(context):
    """Verify citation quality improvement."""
    citation_quality = context.orchestrator.calculate_citation_quality(
        context.lag_benchmark_results
    )
    baseline_quality = context.rag_baseline.citation_quality
    assert citation_quality > baseline_quality


@then('step 2 should use "{context_info}" as context for retrieval')
def step_verify_context_usage(context, context_info):
    """Verify step 2 uses proper context."""
    step_two_context = context.orchestrator.get_step_context(2)
    assert context_info.lower() in step_two_context.lower()


@then('the Retriever should search for "{search_term}" specifically')
def step_verify_specific_search(context, search_term):
    """Verify retriever searches for specific term."""
    retrieval_queries = context.orchestrator.get_retrieval_queries()
    assert any(search_term.lower() in query.lower() for query in retrieval_queries)


@then('the context from step 1 should be preserved in memory')
def step_verify_context_preservation(context):
    """Verify context preservation in memory."""
    memory = context.orchestrator.get_memory_state()
    assert memory.contains_step_context(1)


@then('subsequent steps should build upon prior answers')
def step_verify_cumulative_building(context):
    """Verify subsequent steps build on prior answers."""
    for i in range(2, len(context.step_one_result.steps) + 1):
        step_context = context.orchestrator.get_step_context(i)
        assert step_context.builds_on_prior_answers()


@then('the circuit breaker should activate before exceeding limits')
def step_verify_circuit_breaker(context):
    """Verify circuit breaker activation."""
    assert context.runaway_result.circuit_breaker_activated
    assert context.runaway_result.steps_executed <= context.max_steps
    assert context.runaway_result.execution_time <= context.max_time


@then('processing should halt gracefully')
def step_verify_graceful_halt(context):
    """Verify graceful halt."""
    assert context.runaway_result.halted_gracefully
    assert not context.runaway_result.crashed


@then('a partial result with explanation should be provided')
def step_verify_partial_result(context):
    """Verify partial result provided."""
    assert context.runaway_result.has_partial_result
    assert context.runaway_result.explanation is not None


@then('the termination reason should be logged')
def step_verify_termination_logged(context):
    """Verify termination reason is logged."""
    assert context.runaway_result.termination_reason_logged


@then('a preflight_plan.json should be generated with the decomposition graph')
def step_verify_preflight_artifact(context):
    """Verify preflight_plan.json artifact."""
    artifacts = context.artifacts_result.artifacts
    assert 'preflight_plan.json' in artifacts
    plan = json.loads(artifacts['preflight_plan.json'])
    assert 'steps' in plan
    assert 'dependencies' in plan


@then('the execution_trace.ndjson should contain all LAG steps')
def step_verify_execution_trace(context):
    """Verify execution trace contains LAG steps."""
    artifacts = context.artifacts_result.artifacts
    assert 'execution_trace.ndjson' in artifacts
    trace_lines = artifacts['execution_trace.ndjson'].strip().split('\n')
    assert len(trace_lines) >= len(context.artifacts_result.steps)


@then('each step should have run_id and correlation_id')
def step_verify_step_ids(context):
    """Verify steps have proper IDs."""
    artifacts = context.artifacts_result.artifacts
    trace_lines = artifacts['execution_trace.ndjson'].strip().split('\n')
    for line in trace_lines:
        entry = json.loads(line)
        assert 'run_id' in entry
        assert 'correlation_id' in entry


@then('memory_pre.json and memory_post.json should be created')
def step_verify_memory_artifacts(context):
    """Verify memory artifacts creation."""
    artifacts = context.artifacts_result.artifacts
    assert 'memory_pre.json' in artifacts
    assert 'memory_post.json' in artifacts


@then('the LAG chain should be fully traceable through artifacts')
def step_verify_full_traceability(context):
    """Verify full LAG chain traceability."""
    assert context.artifacts_result.is_fully_traceable()


@then('it should identify the ambiguity in cognitive load calculation')
def step_verify_ambiguity_detection(context):
    """Verify ambiguity detection."""
    assert context.decomposition_attempt.detected_ambiguity
    assert context.decomposition_attempt.cognitive_load_uncertain


@then('request clarification rather than making assumptions')
def step_verify_clarification_request(context):
    """Verify clarification is requested."""
    assert context.decomposition_attempt.requested_clarification
    assert not context.decomposition_attempt.made_assumptions


@then('the ambiguity should be flagged in the terminator reasoning')
def step_verify_ambiguity_flagged(context):
    """Verify ambiguity is flagged."""
    terminator = context.decomposition_attempt.terminator
    assert terminator.flagged_ambiguity
    assert 'ambiguous' in terminator.reason.lower()


@then('no decomposition should proceed without sufficient context')
def step_verify_no_decomposition_without_context(context):
    """Verify no decomposition without context."""
    assert not context.decomposition_attempt.proceeded_with_decomposition
    assert context.decomposition_attempt.required_sufficient_context