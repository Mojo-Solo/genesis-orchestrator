# PRD — **GENESIS Orchestrator (LAG+RCR)** for your Tech‑Stack Templates

**Repo**: React+Tailwind+shadcn (FE) · Laravel+MySQL (BE) · Temporal (Python SDK)
**Mode**: laconic, no fluff. **Target stability:** 98.6% (≤ 1.4% variance).

---

## 0) Summary

* Deliver a **domain‑agnostic multi‑agent orchestrator** (LAG logic + RCR router) that plans, routes, builds, tests, secures, and deploys this repo's templates with **provable artifacts**, **reproducible runs**, and **CI quality gates**.
* Ship **audit‑ready outputs** (plans, traces, metrics) and a **self‑upgrade loop** that meta‑analyzes the codebase and improves results over time.

**Assumptions**

* \[Inference] Clerk for auth; Square optional; doctl‑mcp available.
* Temporal runs locally or as a worker; Python ≥3.10.
* GitHub Actions is CI; existing workflows stay.

---

## 1) Goals & KPIs

**Goals**

1. **Logic-First Accuracy:** LAG decomposition + terminators respected end‑to‑end.
2. **Efficiency:** RCR routing reduces tokens/latency vs full‑context/static.
3. **Reproducibility:** ≥ 98.6% stability across plan/route/answer.
4. **Safety:** PII redaction; HMAC/webhook, idempotency, rate‑limits \[Inference].
5. **Proof:** Complete artifacts; signed commits; acceptance suite green.

**KPIs**

* ΔTokens ↓ ≥30% with RCR vs full‑context; latency p50 ↓ ≥20%.
* Stability: plan/route equivalence across 5 reruns; answer diff ≤ 1.4%.
* A11y/ESLint: 0 errors; tests ≥80% where present.
* Zero **Auto‑DQ** (secrets/PII leak, missing artifacts, infinite loops).

---

## 2) Scope / Non‑Goals

**In**: Orchestrator, router, artifacts, CI gates, Temporal wiring, FE "SuperDesign" pipeline, BE health/webhook scaffolds, meta‑upgrade loop.
**Out**: New product features, design system overhaul, non‑MySQL DBs, cloud infra provisioning.

---

## 3) Users & Roles

* **Developer (primary)**, **Auditor**, **CI**, **Genesis Orchestrator**, **Planner/Retriever/Solver/Critic/Verifier/Rewriter**, **Temporal Worker**, **Security Reviewer**.
* **Optional**: **Payments Admin** (Square) \[Inference].

---

## 4) Requirements

### 4.1 Functional

* **LAG**: Cartesian decomposition → ordered sub‑Qs → stepwise solve → **terminators** for unanswerable/contradiction.
* **RCR**: Role‑aware routing with per‑role token budgets; greedy top‑k selection; deterministic tie‑break.
* **Artifacts** (per run): `preflight_plan.json`, `execution_trace.ndjson`, `memory_{pre,post}.json`, `router_metrics.json`, `meta_report.md`, `acceptance.json` results, `sbom.json`, `policy.json`, `redteam.md`.
* **Stability**: Fixed seeds/temps; plan/route equality; diff report ≤ 1.4%.
* **SuperDesign** (FE): IA → Components → Tokens → A11y → merged `frontend_design_spec.json`.
* **Security**: PUBLIC/PROTECTED routes; webhook HMAC (raw body), idempotency, rate‑limits; PII redaction \[Inference].
* **Payments (opt‑in)**: Tokenization only (no PAN/CVV), idempotencyKey, webhook signature, customer linkage \[Inference].
* **Deploy**: prefer **doctl‑mcp**; fallback **doctl**; health checks pass.

### 4.2 Non‑Functional

* **Performance**: RCR efficiency deltas recorded; p50 and p95 reported.
* **Reliability**: Circuit breakers (time/cost/steps/tool‑calls).
* **A11y/UX**: WCAG AA; eslint/jsx‑a11y gate.
* **Observability**: run\_id/corr\_id; metrics and logs routed to `backend/monitoring`.

---

## 5) Architecture (concise)

### 5.1 Orchestrator (Claude‑compatible; runs via Claude Code)

* **Agents**: Planner, Retriever, Solver, Critic, Rewriter, Verifier.
* **Budgets (example)**: Planner 1.5k, Retriever 1k, Solver 1k, Critic 1k, Verifier 1.5k, Rewriter 0.8k.
* **Router**: importance signals = role\_keywords, stage, recency; **tie‑break by id**.

### 5.2 Temporal Workflow (Python SDK)

Activities (idempotent; retriable):

1. **Preflight** → env/tool checks, snapshot memory.
2. **Plan** (LAG) → `preflight_plan.json`.
3. **Iterative Steps**: Retrieve → Solve → Critic (terminators).
4. **Integrate/Verify** → final draft + trace.
5. **FE Gates** → ESLint/a11y/typecheck/build (React/Tailwind/shadcn).
6. **BE Gates** → Laravel tests, security checks, policies.
7. **Containerize** → build/tag images.
8. **Deploy** → doctl‑mcp; health verify.
9. **Finalize** → artifacts, `meta_report.md`, signed commit footers.

### 5.3 SuperDesign (FE sub‑pipeline)

* Inputs: `frontend/docs/schemas/frontend-design-spec.schema.json`.
* Outputs: `frontend/docs/agents/*` + merged `frontend_design_spec.json`.
* Gate: ESLint a11y + schema validate.

### 5.4 Security

* **Clerk** (auth), **Square** (opt‑in) policies enforced at gate \[Inference].
* Webhook HMAC over raw body; constant‑time compare; clock skew ≤5m; idempotent store \[Inference].

---

## 6) Repo Integration (where things live)

* **Docs**: `docs/GENESIS_EVAL_SPEC.md` (already present), add `docs/PRD-GENESIS.md` (this).
* **Artifacts (per run)**: `orchestrator_runs/<RUN_ID>/…` (root‑level folder).
* **Router config**: `config/router.config.json`.
* **Prompts**: `prompts/*.md` (Planner/Retriever/Solver/Critic/Rewriter/Verifier).
* **Temporal**: `backend/state/` (extend) or `tools/temporal/` for worker & activities.
* **Monitoring**: reuse `backend/monitoring/**` (scripts/config/alerts).
* **CI**: `.github/workflows/agent-validation.yml` & `frontend-standards.yml` (extend) + **new** `genesis-eval.yml`.
* **Acceptance templates**: `docs/templates/acceptance.json` (extend with LAG/RCR/stability cases).
* **.claude/**: keep plan/analysis stubs; wire to orchestrator hooks.

---

## 7) CI / Quality Gates

* **Preflight Job**: env checks; seeds/temps frozen; artifact scaffold.
* **FE Gate**: ESLint (docs/eslint.frontend.json), jsx‑a11y, typecheck, build.
* **BE Gate**: `php artisan test` (thresholds configurable); security checks.
* **Genesis Eval**: run acceptance matrix → assert RCR beats baseline; assert stability 98.6%.
* **Provenance**: enforce commit footers (see `docs/commit-footers.md`).

---

## 8) Data & Contracts (concise)

* **Memory item**: `{id, role, content, tags:[task_id,type], ts, vector_id}`.
* **Router config**: `beta_base`, `beta_role`, `importance.signals`, `semantic_filter.topk|min_sim`.
* **Health** (BE):

  * `/health/ready` = deps/router/budgets OK.
  * `/health/live` = loop responsive.
* **Webhook** (opt‑in Square) \[Inference]: `/api/webhooks/square` (public but signature‑guarded; rate‑limited).

---

## 9) Success Metrics & SLOs

* **RCR**: tokens ↓ ≥30% vs full‑context; p50 latency ↓ ≥20%.
* **Stability**: 5 reruns → identical plan/route; answer diff ≤ 1.4%.
* **Security**: 0 critical; 0 PII in logs; HMAC passes; rate‑limits enforced.
* **A11y/ESLint**: 0 errors.
* **Deploy**: health passes within 90s; rollbacks < 2 min.

---

## 10) Acceptance (binds CI)

Use `docs/templates/acceptance.json` and add cases:

1. **lag-hotpotaqa**: exact plan order; terminator=false; matches oracle.
2. **terminator-trip**: impossible sub‑Q → halts ≤3 steps; reason logged.
3. **rcr-savings**: full vs static vs RCR → RCR wins tokens/latency; quality ≥ baseline.
4. **stability-98\_6**: 5 reruns → plan/route equal; answer diff ≤ 1.4%.
5. **security-gate**: PII redaction, HMAC pass, idempotency present.
6. **auto-upgrade**: meta cycle produces measurable gain (tokens/latency/accuracy).

---

## 11) Rollout Plan

* **Phase 0 (1–2d)**: Preflight; scaffold artifacts; `genesis-eval.yml`.
* **Phase 1 (2–4d)**: Orchestrator loop + RCR config; FE/BE gates.
* **Phase 2 (2–3d)**: Temporal worker + activities; health endpoints.
* **Phase 3 (1–2d)**: Acceptance suite + stability harness; baseline vs RCR.
* **Phase 4 (1–2d)**: Security (HMAC/idempotency), optional Square & Clerk wiring \[Inference]; docs, signoff.

---

## 12) Risks & Mitigations

* **Router instability** → deterministic tie‑breaks; seed freeze.
* **Over‑routing** → semantic saturation detection; cap tokens per role.
* **Tool flakiness** → Temporal retries; idempotency; circuit breakers.
* **A11y failures** → pre‑build ESLint gate required.
* **Secret leakage** → redact logs; `.env` hygiene; CI secret scanning.

---

## 13) Open Questions (answer to finalize)

* Confirm **Clerk** as auth provider and **Square** usage scope. \[Inference]
* Approve token budgets per role (defaults above).
* Approve doctl‑mcp; confirm fallback **doctl** credentials path.
* Any **strict** coverage thresholds to enforce in CI?
* Where to host **orchestrator\_runs/** (repo vs artifact store)?

---

## 14) Work Breakdown (laconic)

* **W1**: Orchestrator skeleton + prompts + router config; artifacts IO.
* **W2**: FE/BE gates; CI wiring; acceptance JSON schema extensions.
* **W3**: Temporal activities; health endpoints; metrics.
* **W4**: Security gates (HMAC/idempotency) \[Inference]; stability harness; meta‑upgrade demo; docs polish.

Deliverables:

* `docs/PRD-GENESIS.md` (this)
* `orchestrator_runs/<RUN_ID>/…` (on CI)
* `config/router.config.json`
* `prompts/*.md`
* `.github/workflows/genesis-eval.yml`
* `backend` health routes & (opt‑in) webhook handler

---

## 15) Variability & Reproducibility Rules (98.6%)

* **Freeze** `seed`, `temperature<=0.2`, `top_p`, router tie‑break `id`.
* **Lock** retriever top‑k & filters; cache embeddings; idempotent retries.
* **Verify**: graph isomorphism (plan), route set equality, Levenshtein diff ≤ 1.4%, latency variance within ± 1.4% median.

---

## 16) Governance & Provenance

* **Commit footers** (required):
  `Run-ID: <RUN_ID>` · `Correlation-ID: <CORR_ID>` · `Agent: <NAME>@<VER>` · `Provenance: generated-by-orchestrator`
* **Docs**: update `docs/evaluation-readiness-checklist.md` with this PRD's gates.