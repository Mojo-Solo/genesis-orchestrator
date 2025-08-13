# GENESIS Evaluation Readiness Checklist

## Pre-Flight Requirements

### Environment Setup
- [ ] Python 3.10+ installed
- [ ] Node.js 20+ installed
- [ ] PHP 8.2+ installed
- [ ] Temporal server running (local or cloud)
- [ ] MySQL database configured
- [ ] Redis cache available

### Configuration Files
- [ ] `config/router.config.json` validated
- [ ] Environment variables set (.env)
- [ ] Temporal connection configured
- [ ] Database credentials correct

### Dependencies
- [ ] Python packages installed (`pip install -r tools/temporal/requirements.txt`)
- [ ] NPM packages installed (frontend)
- [ ] Composer packages installed (Laravel)

## Core Components

### Orchestrator
- [ ] All 6 agent prompts present in `prompts/`
- [ ] Router configuration complete
- [ ] Token budgets allocated per agent
- [ ] Circuit breakers configured

### Temporal Workflow
- [ ] Worker registered and running
- [ ] All activities stubbed
- [ ] Retry policies configured
- [ ] Timeout settings appropriate

### Health Endpoints
- [ ] `/health/ready` responding
- [ ] `/health/live` responding
- [ ] `/health/metrics` collecting data
- [ ] All dependencies checked

## Quality Gates

### Frontend Standards
- [ ] ESLint configuration present
- [ ] A11y rules configured
- [ ] TypeScript strict mode
- [ ] Build process validated

### Backend Standards
- [ ] Laravel tests passing
- [ ] Security middleware configured
- [ ] API routes protected
- [ ] Database migrations run

## Acceptance Criteria

### LAG Decomposition
- [ ] Multi-hop questions decomposed correctly
- [ ] Dependency ordering maintained
- [ ] Terminator conditions detected
- [ ] Plan signatures stable

### RCR Routing
- [ ] Token reduction ≥30%
- [ ] Latency reduction ≥20%
- [ ] Quality maintained ≥95%
- [ ] Deterministic tie-breaking

### Stability Requirements
- [ ] Seed fixed at 42
- [ ] Temperature ≤0.2
- [ ] 5 reruns produce identical plans
- [ ] Answer variance ≤1.4%

### Security Controls
- [ ] PII redaction functional
- [ ] HMAC validation implemented
- [ ] Idempotency keys present
- [ ] Rate limiting configured

## Monitoring & Observability

### Metrics Collection
- [ ] Run ID tracked
- [ ] Correlation ID tracked
- [ ] Token usage recorded
- [ ] Latency measured

### Artifact Generation
- [ ] `preflight_plan.json` created
- [ ] `execution_trace.ndjson` populated
- [ ] `router_metrics.json` accurate
- [ ] `meta_report.md` comprehensive

### Logging
- [ ] Verbose mode enabled
- [ ] Error tracking configured
- [ ] Performance metrics logged
- [ ] Audit trail maintained

## CI/CD Integration

### GitHub Actions
- [ ] `genesis-eval.yml` workflow active
- [ ] All jobs passing
- [ ] Artifacts uploaded
- [ ] PR comments working

### Deployment
- [ ] Health checks passing
- [ ] Rollback plan documented
- [ ] Monitoring alerts configured
- [ ] SLOs defined

## Documentation

### Technical Docs
- [ ] PRD-GENESIS.md complete
- [ ] API documentation current
- [ ] Architecture diagrams updated
- [ ] Runbooks available

### Operational Docs
- [ ] Deployment guide written
- [ ] Troubleshooting guide available
- [ ] Performance tuning documented
- [ ] Security procedures defined

## Sign-off Criteria

### Performance
- [ ] All KPIs met or exceeded
- [ ] Stability score ≥98.6%
- [ ] No critical security issues
- [ ] Zero auto-DQ violations

### Governance
- [ ] Commit footers enforced
- [ ] Provenance tracking active
- [ ] Audit logs complete
- [ ] Compliance validated

### Final Verification
- [ ] All acceptance tests passing
- [ ] Production readiness confirmed
- [ ] Stakeholder approval obtained
- [ ] Go-live date scheduled

---

## Quick Start Commands

```bash
# Install dependencies
cd tools/temporal && pip install -r requirements.txt

# Start Temporal worker
python tools/temporal/worker.py

# Run acceptance tests
npm run test:acceptance

# Check health
curl http://localhost:8000/health/ready

# View metrics
curl http://localhost:8000/health/metrics

# Run CI locally
act -j acceptance-suite
```

## Emergency Contacts

- **Orchestrator Issues**: Check logs in `orchestrator_runs/<RUN_ID>/`
- **Temporal Problems**: Verify worker status and connection
- **Routing Failures**: Review `router_metrics.json`
- **Stability Concerns**: Compare plan signatures across runs

---

**Last Updated**: 2024-01-01
**Version**: 1.0.0
**Status**: READY FOR EVALUATION