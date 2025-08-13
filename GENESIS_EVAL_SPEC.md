# GENESIS AGENT (LAG + RCR) — EVAL‑READY SPEC (LACONIC, COMPLETE)

**Assumptions / constraints**
- Domain: human‑knowledge tasks; no domain‑specific code execution.
- Models: Claude family for all roles; tool use allowed per policy.
- Target reproducibility: **≥ 98.6% stability** (≤ 1.4% run‑to‑run variance) across plan/trace/answer.
- Core methods:
  - **LAG**: Cartesian decomposition → logical reorder → stepwise resolution → **logical terminator**; earlier answers drive later retrieval. fileciteturn1file8 fileciteturn1file11 fileciteturn1file7
  - **RCR‑router**: **role‑aware**, **token‑budgeted** memory routing with **importance scoring** (role relevance, stage, recency) + semantic filter; greedy top‑k under **Bᵢ**. fileciteturn1file1 fileciteturn1file10

## 1) Rubric & auto‑DQ gates
- **Weights (100 pts)**: Functionality 30; Security 20; Autonomy 15; UX 10; Novelty 10; Cost/Latency 15.
- **Auto‑DQ**: missing artifacts; secrets/PII in logs; webhook HMAC fail; prompt‑injection exploit; unbounded loops; **stability < 98.6%**; router ignoring token budgets; unverifiable claims. **LAG or RCR absent → DQ.**
- **Evidence**: LAG chain + terminator reasons; RCR budgets + importance scores logged. fileciteturn1file6 fileciteturn1file1

## 2) Environment & constraints
- Runtime: container; offline toggle; egress allowlist.
- Budgets: per‑role token budgets (**Bᵢ**) + global cap; time & cost caps. **Bᵢ = β_base + β_role(role)**. fileciteturn1file3
- Models/tools: approved list; MCP servers enumerated; deterministic seeds & temps.

## 3) Reproducibility (98.6% target)
- Knobs: temperature, top_p, seed; tool nondeterminism; stable router ordering (tie‑break by id).
- Checks: plan graph equivalence; route‑set equality under **Bᵢ**; answer diff ≤ 1.4% Levenshtein; latency ± 1.4% median.
- Rerun: same inputs → same plan, same LAG step order, same terminator outcomes. fileciteturn1file7

## 4) Must‑ship artifacts
- `artifacts/preflight_plan.json` (LAG graph; deps; sub‑Qs).
- `artifacts/execution_trace.ndjson` (run_id, corr_id, per‑step tokens/cost/latency; router decisions; tool calls).
- `artifacts/memory_pre.json` / `artifacts/memory_post.json` (entries + tags + embedding ids).
- `artifacts/router_metrics.json` (Bᵢ per role; top‑k chosen; importance scores; % token saved vs full‑context). *(RCR lowers tokens/latency while maintaining/improving quality.)* fileciteturn1file14 fileciteturn1file16
- `artifacts/meta_report.md` (self‑critique, bottlenecks, upgrade proposal, A/B results).
- `artifacts/acceptance.json` (results vs golden oracles).
- `artifacts/sbom.json`, signed tags/commits; `artifacts/policy.json` (auth/PII).
- `artifacts/redteam.md` (prompts + outcomes).

## 5) LAG logic (Cartesian method — **prove it**)
- Decompose when cognitive‑load CL(q) > τ(t); signals: semantic scope variance, reasoning depth, ambiguity. fileciteturn1file0
- Logical reorder: basics → dependent; prior answers condition later retrieval. fileciteturn1file9 fileciteturn1file11
- Logical terminator: stops on low retriever confidence, dependency failure, or redundancy; avoids error propagation/waste. fileciteturn1file11
- Outcome: LAG improves reasoning/accuracy vs RAG/GraphRAG (GraphRAG‑Bench, MuSiQue deltas). fileciteturn1file4 fileciteturn1file7

## 6) RCR routing (role‑aware context — **prove it**)
- Policy: π_route(C|Rᵢ,Sₜ,M)=argmax_{C⊆M} Σ α(m;Rᵢ,Sₜ)  s.t. Σ TokenLen(m) ≤ Bᵢ. Modules: **Token Budget Allocator**, **Importance Scorer**, **Semantic Filter**. fileciteturn1file1
- Importance: role keywords, task stage, recency; greedy top‑k within **Bᵢ**. fileciteturn1file10
- Result: lower tokens/latency and equal/higher answer quality vs full‑context/static across HotPotQA/MuSiQue/2Wiki. fileciteturn1file14 fileciteturn1file16

## 7) Autonomy & safety
- Circuit‑breakers: max depth/steps/time/cost/tool‑calls; early stop on terminator fires. fileciteturn1file11
- Prompt‑injection defenses; tool allowlist; output filters.
- Graceful degrade: partial + uncertainty note; no bluffing.

## 8) Security & compliance
- Auth: PUBLIC vs PROTECTED; Clerk config; MFA on privileged actions. [Inference]
- Webhooks/HMAC: verify raw body; clock‑skew tolerance; idempotency; rate‑limit; route allowlist. [Inference]
- PII: redact; retention windows; access logs.

## 9) Payments (Square) validations
- Tokenization only (no PAN/CVV); idempotencyKey; customer linkage; webhook HMAC; retry/backoff. [Inference]

## 10) Model/tooling policy
- MCP servers list & quotas; multi‑model routing stance (quality vs cost); version locks; offline switch.

## 11) Frontend standards
- A11y WCAG‑AA; eslint/jsx‑a11y; **ban** eval/dangerouslySetInnerHTML; design tokens if required.

## 12) Demo & acceptance tests
- **Suite** (scripted):
  1) LAG proof: complex multi‑hop → `preflight_plan` (order exact); no terminator; accuracy vs oracle. fileciteturn1file2
  2) Terminator trip: impossible sub‑Q → halt + reason; steps ≤ bound. fileciteturn1file11
  3) RCR savings: full vs static vs RCR; report tokens/runtime/quality; RCR wins. fileciteturn1file14
  4) **98.6% stability**: 5 reruns → plan/route equivalence + answer diff ≤ 1.4%; attach diffs.
  5) Security: HMAC pass; PII redaction; rate‑limit respected.
  6) Auto‑upgrade: run meta cycle → patch → re‑run → measurable gain (see §18).

## 13) Novelty vs reliability
- Score novelty only with measured gains (accuracy/tokens/latency); penalize prompt bloat/over‑engineering.

## 14) Failure handling
- Partial credit rules; explicit “unanswerable”; bounded auto‑repair; human escalation SLA.

## 15) Evidence (“prove our work”)
- Logs: run_id, corr_id, tool_call_id, route_set, Bᵢ, α(m) for chosen items. fileciteturn1file1
- Artifacts: precise paths; signed tag hash; SBOM list.

## 16) Deployment / health
- `doctl‑mcp` preferred; fallback documented.
- Health: `/health/ready` (deps, router, budgets) and `/health/live` (loop responsive).
- Env vars: budgets, temps, seeds, model/route policies; offline toggle.

## 17) Memory precedence
- Enforce Enterprise → Project → User (and repo → user at runtime); conflict rules; tests verify policy.

## 18) Meta‑analysis & self‑upgrade
- Loop: logs → bottleneck detect → proposal → sandbox test → patch → A/B → rollback plan.
- Targets: dead logic, prompt bloat, routing waste, flaky tools.
- Example: add Math‑Specialist role; small Bᵢ; verify ↑accuracy, ↓retries, variance ≤ 1.4%. [Inference]

## 19) Claude‑compatible implementation

### 19.1 Role prompts (templates)
- **Planner (LAG)** — goal: decompose → order by deps → emit plan JSON. Prompt:
  ```
  SYSTEM: Planner. Use Cartesian rules: doubt→divide→order→review.
  USER: {question}
  ASSISTANT:
  - Compute CL(q); if CL>τ, split.
  - Output JSON: {"steps":[{"id":"s1","q":"..."},{"id":"s2","q":"...", "depends_on":["s1"]}, ...],
                  "terminators":{"max_depth":{maxDepth},"max_steps":{maxSteps}} }
  ```
  Justification: LAG decomposition + logical order. fileciteturn1file8

- **Retriever** — goal: top‑k facts + citations, 2‑line items max; use prior answers.
- **Solver** — goal: concise grounded sub‑answer.
- **Critic** — goal: flag unanswerable/contradiction/low‑support → triggers terminator. fileciteturn1file11
- **Rewriter** — goal: merge sub‑answers, unify tone, no new claims.
- **Verifier** — goal: end‑to‑end validation; require sources.

### 19.2 Router policy (RCR)
- `config/router.config.json`:
  ```json
  {
    "beta_base": 512,
    "beta_role": { "Planner": 1536, "Retriever": 1024, "Solver": 1024, "Critic": 1024, "Verifier": 1536, "Rewriter": 768 },
    "importance": { "signals": ["role_keywords","task_stage","recency"], "tie_breaker": "id" },
    "semantic_filter": { "topk": 12, "min_sim": 0.35 }
  }
  ```
- Mechanics: maximize Σα(m;Rᵢ,Sₜ) under **Bᵢ**; greedy; stateless per round; iterative with feedback via memory updates. fileciteturn1file1 fileciteturn1file10

### 19.3 Orchestrator loop (pseudo‑code)
```
process(query):
  store(USER, query, tags=["user_query"])
  plan = Planner(query)
  for step in plan.steps_ordered:
    docs = Retriever(step.q, prior=answers[deps])
    ans  = Solver(step.q, docs, prior=answers[deps])
    critique = Critic(step.q, ans, docs+prior)
    if critique.flags in {"UNANSWERABLE","CONTRADICTION","LOW_SUPPORT"}:
        record(terminator=critique); break
    answers[step.id]=ans
  draft = Rewriter(answers_in_order)
  verdict = Verifier(draft, memory_slice_for("Verifier"))
  return final(draft, verdict)
```
- Logs: per step record **Bᵢ**, chosen items, α scores. fileciteturn1file1

### 19.4 Memory schema
```
{id, role, content, tags:[task_id,type], ts, vector_id}
```
- Tags: plan|retrieval|answer|critique|verdict; topic tags.
- Supports semantic search + role/stage filters (RCR).

### 19.5 Stability knobs
- temperature ≤ 0.2; fixed seed; deterministic tool mocks; router tie‑break by id; cache embeddings; idempotent retries.

## 20) Acceptance fixtures

### 20.1 `artifacts/acceptance.json` (schema sample)
```json
{
  "tests":[
    {"name":"lag-hotpotaqa","input":"Q...","oracle":"A...","expect":{"accuracy":1.0,"plan_order":"exact","terminator":false}},
    {"name":"terminator-trip","input":"Q-impossible","expect":{"terminator":true,"steps_max":"<=3"}},
    {"name":"rcr-savings","input":"Q...","compare":["full","static","rcr"],"expect":{"rcr.tokens":"< baseline","rcr.quality":">= baseline"}},
    {"name":"stability-98_6","input":"Q...","runs":5,"expect":{"plan":"equivalent","route":"equivalent","answer_diff":"<=1.4%"}}
  ]
}
```

### 20.2 `artifacts/meta_report.md` (outline)
```
# Meta Report
Run: {run_id}  Model: {model}  Seed: {seed}
1) Bottlenecks: {router saturation, terminator misses, prompt bloat}
2) Proposal: {change}  Hypothesis: {metric up/down}
3) Sandbox A/B: before vs after (accuracy, tokens, latency, variance)
4) Decision: adopt|rollback  Evidence: links
```

## 21) Deployment / health
- Readiness `/health/ready` → deps up, router loaded, budgets set.
- Liveness `/health/live` → loop responsive.
- Offline toggle: env `OFFLINE=true` → no external egress; retrieval uses local KB.

## 22) One killer question (+ answer)
- **Ask**: “What’s the #1 fail mode and fastest fix?”
- **Answer**: Reproducibility gaps (unstable plan/route). **Fix**: freeze seeds/temps; router tie‑breaks; emit `preflight_plan` & `route_set` and enforce; diff on drift.

## 23) What graders see (why it wins)
- **LAG**: explicit Cartesian chain, **terminator** proof, accuracy uplifts on logic‑heavy tasks. fileciteturn1file4 fileciteturn1file15
- **RCR**: token/latency drops with quality uptick vs baselines. fileciteturn1file14 fileciteturn1file16
- **Stability**: 98.6% metric with diffs; **Security/PII** clean; HMAC pass; rate‑limits; proofs via artifacts.

## 24) Full checklist (applied)
Rubric ✅ | Auto‑DQ ✅ | Env ✅ | Repro (98.6%) ✅ | Artifacts ✅ | LAG+terminator ✅ fileciteturn1file8 fileciteturn1file11 | RCR policy+metrics ✅ fileciteturn1file1 fileciteturn1file14 | Autonomy ✅ | Security+Payments ✅ [Inference] | Tooling ✅ | Frontend ✅ | Demo/Acceptance ✅ | Novelty vs Reliability ✅ | Failure handling ✅ | Evidence ✅ | Deployment ✅ | Memory precedence ✅ | Meta‑upgrade ✅ | Prompts+Loop+Router config ✅ | Killer Q ✅

**Citations**: LAG (LAG‑2508.05509v2) and RCR‑Router (RCR‑Router‑2508.04903v2). Embedded markers reference those sources.
