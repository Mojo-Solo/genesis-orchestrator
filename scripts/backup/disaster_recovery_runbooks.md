# GENESIS Orchestrator - Disaster Recovery Runbooks

## Executive Summary

This document provides comprehensive disaster recovery procedures for the GENESIS Orchestrator system. These runbooks ensure business continuity with Recovery Time Objectives (RTO) < 15 minutes and Recovery Point Objectives (RPO) < 5 minutes.

### Critical Information
- **Primary Region**: us-west-2
- **Replica Regions**: us-east-1, eu-west-1
- **Backup Frequency**: Every 5 minutes (continuous)
- **Backup Retention**: 90 days (configurable)
- **RTO Target**: < 15 minutes
- **RPO Target**: < 5 minutes

## Emergency Contacts

### Incident Response Team
- **Primary On-Call Engineer**: [Phone] [Email]
- **Secondary On-Call Engineer**: [Phone] [Email]
- **DevOps Lead**: [Phone] [Email]
- **Engineering Manager**: [Phone] [Email]
- **CTO**: [Phone] [Email]

### External Contacts
- **AWS Support**: [Case URL] / 1-800-AWS-SUPPORT
- **Database Vendor Support**: [Contact Information]
- **Monitoring Service**: [Contact Information]

## DR Scenarios and Procedures

### Scenario 1: Complete Primary Region Failure

**Symptoms:**
- All services in primary region (us-west-2) are unreachable
- Health checks failing across all primary infrastructure
- DNS resolution failing for primary endpoints

**Impact:** Complete service outage

**RTO:** 10-15 minutes

**Procedure:**

#### Step 1: Verify Outage Scope (2 minutes)
```bash
# Check primary region health
curl -f https://api.genesis.orchestrator.com/health/ready
curl -f https://api.genesis.orchestrator.com/health/live

# Check AWS service health
aws s3 ls s3://genesis-disaster-recovery --region us-west-2

# Verify monitoring systems
# Check Grafana dashboards
# Verify CloudWatch metrics
```

#### Step 2: Activate Cross-Region Failover (5 minutes)
```bash
# Execute automated failover
cd /opt/genesis/scripts/backup
./cross_region_replication.sh failover us-east-1 "Primary region failure"

# Verify failover status
./cross_region_replication.sh status
```

#### Step 3: Verify Service Recovery (3 minutes)
```bash
# Test new endpoint
curl -f https://api.genesis.orchestrator.com/health/ready

# Verify database connectivity
mysql -h genesis-orchestrator-replica-us-east-1.xyz.rds.amazonaws.com -u genesis -p -e "SELECT COUNT(*) FROM orchestration_runs;"

# Check Redis connectivity
redis-cli -h genesis-redis-us-east-1.xyz.cache.amazonaws.com ping
```

#### Step 4: Monitor and Communicate (5 minutes)
```bash
# Send status update
curl -X POST $SLACK_WEBHOOK_URL -d '{
    "text": "🟢 GENESIS Orchestrator failover completed to us-east-1. Services restored.",
    "channel": "#incidents"
}'

# Update status page
# Notify stakeholders
```

---

### Scenario 2: Database Corruption/Loss

**Symptoms:**
- Database connection errors
- Data integrity violations
- Corrupted table errors

**Impact:** Data layer failure, potential data loss

**RTO:** 10-12 minutes

**Procedure:**

#### Step 1: Assess Damage (2 minutes)
```bash
# Check database status
mysql -h $DB_HOST -u $DB_USER -p$DB_PASSWORD -e "SHOW DATABASES;"

# Verify table integrity
mysql -h $DB_HOST -u $DB_USER -p$DB_PASSWORD $DB_NAME -e "CHECK TABLE orchestration_runs, agent_executions, memory_items;"

# Check for recent backups
./automated_backup.sh --list-backups
```

#### Step 2: Point-in-Time Recovery (8 minutes)
```bash
# Find backup closest to incident time
INCIDENT_TIME="2024-12-15_14:30:00"  # Adjust as needed
./point_in_time_recovery.sh --target-time $INCIDENT_TIME --mode database_only

# Verify recovery
mysql -h $DB_HOST -u $DB_USER -p$DB_PASSWORD -e "SELECT COUNT(*) FROM $DB_NAME.orchestration_runs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR);"
```

#### Step 3: Validate Data Integrity (2 minutes)
```bash
# Run quick validation
./backup_validation.sh integrity latest

# Check application health
curl -f http://localhost:8081/health/ready
```

---

### Scenario 3: Redis Data Loss

**Symptoms:**
- Cache misses at 100%
- Session data loss
- Performance degradation

**Impact:** Cache layer failure, reduced performance

**RTO:** 5-8 minutes

**Procedure:**

#### Step 1: Confirm Redis Status (1 minute)
```bash
# Check Redis connectivity
redis-cli -h $REDIS_HOST -p $REDIS_PORT ping

# Check data
redis-cli -h $REDIS_HOST -p $REDIS_PORT dbsize
redis-cli -h $REDIS_HOST -p $REDIS_PORT info memory
```

#### Step 2: Redis Recovery (5 minutes)
```bash
# Restore from latest backup
./point_in_time_recovery.sh --backup-id latest --mode redis_only

# Verify restoration
redis-cli -h $REDIS_HOST -p $REDIS_PORT dbsize
```

#### Step 3: Warm Cache (2 minutes)
```bash
# Trigger cache warming
curl -X POST http://localhost:8081/api/v1/cache/warm

# Monitor cache hit rates
redis-cli -h $REDIS_HOST -p $REDIS_PORT info stats | grep cache_hits
```

---

### Scenario 4: Application-Level Corruption

**Symptoms:**
- Invalid orchestration results
- Agent execution failures
- Persistent stability score degradation

**Impact:** Service degradation, incorrect results

**RTO:** 8-12 minutes

**Procedure:**

#### Step 1: Identify Corruption Scope (2 minutes)
```bash
# Check recent orchestration runs
mysql -h $DB_HOST -u $DB_USER -p$DB_PASSWORD $DB_NAME -e "
SELECT id, status, stability_score, created_at 
FROM orchestration_runs 
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR) 
ORDER BY created_at DESC LIMIT 20;"

# Check agent execution patterns
mysql -h $DB_HOST -u $DB_USER -p$DB_PASSWORD $DB_NAME -e "
SELECT agent_name, status, COUNT(*) as count
FROM agent_executions 
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY agent_name, status;"
```

#### Step 2: Full System Recovery (8 minutes)
```bash
# Complete system restore to known good state
LAST_GOOD_TIME="2024-12-15_13:00:00"  # Adjust based on analysis
./point_in_time_recovery.sh --target-time $LAST_GOOD_TIME --mode full

# Verify application health
curl -f http://localhost:8081/health/ready
curl -f http://localhost:8081/metrics
```

#### Step 3: Validate Recovery (2 minutes)
```bash
# Run comprehensive validation
./backup_validation.sh validate latest

# Test with sample query
curl -X POST http://localhost:8081/api/v1/orchestrate \
  -H "Content-Type: application/json" \
  -d '{"query": "Test recovery validation", "mode": "standard"}'
```

---

### Scenario 5: Storage/Backup System Failure

**Symptoms:**
- Backup failures
- S3 access errors
- Disk space alerts

**Impact:** Loss of backup capability, increased RPO risk

**RTO:** 5-10 minutes

**Procedure:**

#### Step 1: Assess Storage Health (2 minutes)
```bash
# Check local storage
df -h /var/backups/genesis
du -sh /var/backups/genesis/backups/*

# Check S3 connectivity
aws s3 ls s3://genesis-disaster-recovery --region us-west-2
aws s3 ls s3://genesis-disaster-recovery-replica-us-east-1 --region us-east-1
```

#### Step 2: Emergency Backup (5 minutes)
```bash
# Create immediate backup to alternative location
EMERGENCY_BACKUP_DIR="/tmp/emergency_backup_$(date +%s)"
mkdir -p $EMERGENCY_BACKUP_DIR

# Quick database dump
mysqldump --single-transaction --routines --triggers $DB_NAME > $EMERGENCY_BACKUP_DIR/emergency_db.sql

# Redis backup
redis-cli -h $REDIS_HOST -p $REDIS_PORT --rdb $EMERGENCY_BACKUP_DIR/emergency_redis.rdb

# Upload to secondary S3 bucket
aws s3 sync $EMERGENCY_BACKUP_DIR s3://genesis-emergency-backups/$(date +%Y%m%d_%H%M%S)/ --region us-east-1
```

#### Step 3: Restore Backup Capability (3 minutes)
```bash
# Fix storage issues (specific to problem)
# Clear old backups if disk space issue
find /var/backups/genesis/backups -mtime +7 -type d -exec rm -rf {} \;

# Test backup system
./automated_backup.sh --test-mode

# Verify S3 permissions
aws sts get-caller-identity
aws s3api get-bucket-acl --bucket genesis-disaster-recovery
```

---

## Recovery Testing Procedures

### Monthly DR Test

**Objective:** Validate all disaster recovery procedures

**Schedule:** First Saturday of each month, 2:00 AM UTC

#### Test Plan:
1. **Backup Validation** (15 minutes)
   ```bash
   # Validate recent backups
   ./backup_validation.sh validate $(date -d "yesterday" +backup_%Y%m%d_*)
   
   # Test point-in-time recovery (dry run)
   ./point_in_time_recovery.sh --target-time "$(date -d '1 hour ago' +%Y-%m-%d_%H:%M:%S)" --dry-run --mode full
   ```

2. **Cross-Region Failover Test** (20 minutes)
   ```bash
   # Test failover to replica region
   ./cross_region_replication.sh failover us-east-1 "DR Test - Monthly"
   
   # Validate services in replica region
   # Test application functionality
   
   # Failback to primary region
   ./cross_region_replication.sh failover us-west-2 "DR Test - Failback"
   ```

3. **Documentation Review** (10 minutes)
   - Verify contact information
   - Update procedures based on infrastructure changes
   - Review and update RTO/RPO targets

### Quarterly Full DR Exercise

**Objective:** Complete disaster recovery simulation

**Schedule:** Last Saturday of quarter, 6:00 AM UTC

#### Exercise Plan:
1. **Simulated Region Outage** (30 minutes)
2. **Full Recovery Process** (45 minutes)
3. **Business Continuity Validation** (30 minutes)
4. **Post-Exercise Review** (15 minutes)

---

## Monitoring and Alerting

### Critical Alerts

#### Backup Failure Alert
```
Alert: GENESIS Backup Failure
Threshold: Any backup job failure
Action: Page on-call engineer immediately
Escalation: 5 minutes to secondary on-call
```

#### RTO/RPO Violation Alert
```
Alert: GENESIS Recovery Time Violation
Threshold: Recovery process >15 minutes
Action: Page engineering manager
Escalation: Immediate escalation to CTO
```

#### Cross-Region Replication Lag
```
Alert: GENESIS Replication Lag
Threshold: >10 minutes lag
Action: Email DevOps team
Escalation: 15 minutes to on-call engineer
```

### Monitoring Dashboard

Key metrics to monitor:
- Backup success rate (target: 100%)
- Average backup size and duration
- Cross-region replication lag
- Storage utilization
- Recovery test success rate

---

## Data Retention and Compliance

### Backup Retention Policy
- **Daily backups**: 30 days
- **Weekly backups**: 12 weeks  
- **Monthly backups**: 12 months
- **Yearly backups**: 7 years (compliance requirement)

### Compliance Requirements
- **SOC 2 Type II**: Quarterly recovery testing
- **GDPR**: Right to erasure procedures
- **HIPAA**: Encryption at rest and in transit
- **PCI DSS**: Secure key management

---

## Post-Incident Procedures

### Immediate Actions (0-30 minutes)
1. Confirm service restoration
2. Update status page
3. Notify stakeholders
4. Begin preliminary incident analysis

### Short-term Actions (30 minutes - 4 hours)
1. Document incident timeline
2. Identify root cause
3. Implement temporary fixes
4. Schedule post-mortem meeting

### Long-term Actions (4 hours - 1 week)
1. Conduct detailed post-mortem
2. Update runbooks based on lessons learned
3. Implement preventive measures
4. Review and update DR procedures

---

## Communication Templates

### Initial Incident Notification
```
Subject: [INCIDENT] GENESIS Orchestrator Service Impact

We are currently experiencing [brief description of issue] affecting GENESIS Orchestrator services.

Status: Investigating
Impact: [service impact description]
ETA: Investigating - updates every 15 minutes

We are actively working to resolve this issue. Next update in 15 minutes.

Incident Commander: [Name]
Incident ID: [ID]
```

### Resolution Notification
```
Subject: [RESOLVED] GENESIS Orchestrator Service Restored

The GENESIS Orchestrator service issue has been resolved.

Root Cause: [brief description]
Resolution: [brief description of fix]
Duration: [total incident duration]

All services are now operating normally. We will conduct a full post-mortem and share findings within 48 hours.

Incident Commander: [Name]
Incident ID: [ID]
```

---

## Appendix

### A. Emergency Scripts Location
- `/opt/genesis/scripts/backup/` - All DR scripts
- `/var/log/genesis/` - Log files and reports
- `/etc/genesis/` - Configuration files

### B. Key File Locations
- Backup registry: `/var/backups/genesis/backup_registry.json`
- Recovery history: `/var/backups/genesis/recovery_history.json`
- Replication health: `/var/log/genesis/replication_health.json`

### C. AWS Resources
- Primary S3 bucket: `genesis-disaster-recovery`
- Replica buckets: `genesis-disaster-recovery-replica-{region}`
- RDS instances: `genesis-orchestrator-{region}`
- Route53 zone: `genesis.orchestrator.com`

### D. Quick Reference Commands
```bash
# List available backups
./automated_backup.sh --list-backups

# Restore to specific time
./point_in_time_recovery.sh --target-time YYYY-MM-DD_HH:MM:SS

# Failover to region
./cross_region_replication.sh failover us-east-1

# Validate backup
./backup_validation.sh validate backup_id

# Check system health
curl http://localhost:8081/health/ready
```

---

**Document Version**: 1.0  
**Last Updated**: 2024-12-15  
**Next Review**: 2025-01-15  
**Owner**: DevOps Team  
**Approved By**: CTO