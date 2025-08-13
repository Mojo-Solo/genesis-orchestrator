# Orchestrator pseudocode (Claude-compatible)
def process(query):
    store('USER', query, tags=['user_query'])
    plan = Planner(query)
    answers = {}
    for step in plan['steps']:
        deps = step.get('depends_on', [])
        prior = [answers[d] for d in deps if d in answers]
        docs = Retriever(step['q'], prior=prior)
        ans  = Solver(step['q'], docs=docs, prior=prior)
        critique = Critic(step['q'], ans, context=docs+prior)
        if critique.flag in {'UNANSWERABLE','CONTRADICTION','LOW_SUPPORT'}:
            record(terminator=critique); break
        answers[step['id']] = ans
    draft = Rewriter(list(answers.values()))
    verdict = Verifier(draft, context=memory_slice_for('Verifier'))
    return finalize(draft, verdict)
