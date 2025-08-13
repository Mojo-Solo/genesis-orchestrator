# Genesis Eval Spec Package
Generated: 2025-08-13T01:54:59.901741Z

## Structure
- GENESIS_EVAL_SPEC.md
- artifacts/ (placeholders & templates)
- config/ (router.config.json, runtime.env.example)
- prompts/ (role prompt templates)
- code/ (orchestrator pseudo, schemas, docs)

## Notes
- Citations embedded in GENESIS_EVAL_SPEC.md reference LAG-2508.05509v2 and RCR-Router-2508.04903v2.

## Org-level secrets & Port Manager
- Secrets are managed at the Mojo-Solo organization level; no workflow changes required. Repo-level secrets (if duplicated) can be deleted.
- For local development, Port Manager coordinates port assignments to avoid collisions:
  - Required env vars (from org-level): `PORT_MANAGER_API_URL`, `PORT_MANAGER_API_TOKEN`, `PORT_MANAGER_LOGS_URL`.
  - Add them to `config/runtime.env.example` (placeholders provided) or export locally.
  - Example claim:
    - `curl -H "Authorization: Bearer $PORT_MANAGER_API_TOKEN" "$PORT_MANAGER_API_URL/claim?service=frontend&preferred=5173"`
