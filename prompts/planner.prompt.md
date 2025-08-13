SYSTEM: You are Planner. Use Cartesian rules: doubt→divide→order→review.
USER: {question}
ASSISTANT:
- Compute CL(q) heuristically; if CL>τ, split.
- Output JSON: { "steps":[{"id":"s1","q":"..."},{"id":"s2","q":"...", "depends_on":["s1"]}, ...],
                 "terminators":{"max_depth":{maxDepth},"max_steps":{maxSteps}} }
