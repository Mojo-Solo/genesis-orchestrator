# GENESIS Orchestrator - Monitoring & Alerting Runbook

## Table of Contents
1. [Overview](#overview)
2. [Critical Alerts](#critical-alerts)
3. [Warning Alerts](#warning-alerts)
4. [SLA Monitoring](#sla-monitoring)
5. [Incident Response Procedures](#incident-response-procedures)
6. [Troubleshooting Guide](#troubleshooting-guide)
7. [Maintenance Procedures](#maintenance-procedures)
8. [Escalation Matrix](#escalation-matrix)

## Overview

This runbook provides step-by-step procedures for responding to monitoring alerts and maintaining the GENESIS Orchestrator monitoring infrastructure. All team members should be familiar with these procedures.

### Monitoring Stack Components
- **Prometheus**: Metrics collection and alerting
- **Grafana**: Visualization and dashboards
- **AlertManager**: Alert routing and notifications
- **Custom Scripts**: Automated incident response and SLA monitoring

### Key Metrics & SLA Targets
- **Availability**: 99.9% uptime (43.2 minutes downtime/month max)
- **Response Time**: P95 ≤ 2 seconds
- **Error Rate**: ≤ 1% of all requests
- **Recovery Time**: ≤ 5 minutes for automated response

## Critical Alerts

### 🔥 GenesisOrchestratorDown

**Severity**: Critical  
**SLA Impact**: Direct availability impact  
**Automated Response**: Service restart after 30 seconds  

#### Immediate Response (0-2 minutes)
1. **Verify Alert**: Check Grafana dashboard for confirmation
2. **Check Automated Response**: Verify if automated restart is in progress
3. **Manual Intervention**: If automated restart fails after 3 attempts:
   ```bash
   # Check service status
   sudo systemctl status genesis-orchestrator
   
   # Check logs for errors
   sudo journalctl -u genesis-orchestrator -n 50
   
   # Manual restart if needed
   sudo systemctl restart genesis-orchestrator
   
   # Verify health
   curl http://localhost:8000/health/ready
   ```

#### Investigation (2-10 minutes)
1. **Root Cause Analysis**:
   ```bash
   # Check system resources
   df -h
   free -h
   top
   
   # Check database connectivity
   mysql -h localhost -u genesis_user -p -e "SELECT 1;"
   
   # Check Redis connectivity  
   redis-cli ping
   
   # Review application logs
   tail -f /var/log/genesis/orchestrator.log
   ```

2. **If Service Won't Start**:
   - Check configuration files: `/etc/genesis/config/`
   - Verify environment variables
   - Check port availability: `netstat -tulpn | grep :8000`
   - Review dependency services (MySQL, Redis, Temporal)

#### Communication
- **Slack**: Automatic notification to `#genesis-critical`
- **PagerDuty**: Automatic page to on-call engineer
- **Email**: Escalation after 5 minutes to management

### 🔥 SLAAvailabilityBreach

**Severity**: Critical  
**SLA Impact**: Customer SLA violation  
**Automated Response**: Management escalation  

#### Immediate Response (0-1 minute)
1. **Confirm Breach**: Check SLA dashboard for current availability
2. **Assess Impact**: Determine customer impact and duration
3. **Execute Emergency Response**:
   ```bash
   # Check all critical services
   ./scripts/monitoring/check_monitoring_health.sh
   
   # Review recent incidents
   ./scripts/monitoring/sla_monitor.py --report 1 --format json
   
   # Check for ongoing incidents
   curl http://localhost:9093/api/v1/alerts
   ```

#### Escalation (1-5 minutes)
- **Customer Success**: Notify if customer-facing impact
- **Management**: Immediate notification required
- **Legal/Compliance**: If contractual SLA breach

### 🔥 CriticalFailureRate / CriticalOrchestrationLatency

**Severity**: Critical  
**SLA Impact**: Performance degradation  
**Automated Response**: Circuit breaker activation, scaling assessment  

#### Immediate Response
1. **Identify Root Cause**:
   ```bash
   # Check current performance metrics
   curl http://localhost:8000/health/metrics | jq .
   
   # Review recent changes
   git log --oneline -10
   
   # Check resource utilization
   htop
   iostat 1 5
   ```

2. **Mitigation Actions**:
   ```bash
   # Clear caches if low hit rate
   curl -X POST http://localhost:8000/api/cache/clear
   
   # Check database performance
   mysql -e "SHOW PROCESSLIST;"
   mysql -e "SHOW ENGINE INNODB STATUS\G" | grep -A 20 "LATEST DETECTED DEADLOCK"
   
   # Monitor improvement
   watch -n 5 'curl -s http://localhost:8000/health/metrics | jq .orchestrator.average_latency_ms'
   ```

## Warning Alerts

### ⚠️ HighOrchestrationLatency / HighFailureRate

**Response Time**: 15 minutes  
**Automated Response**: Performance monitoring, cache optimization  

#### Response Procedure
1. **Monitor Trend**: Check if condition is improving or degrading
2. **Resource Check**: Verify CPU, memory, disk, and network utilization
3. **Database Optimization**:
   ```bash
   # Check slow queries
   mysql -e "SELECT * FROM information_schema.processlist WHERE time > 10;"
   
   # Optimize tables if needed (during maintenance window)
   mysql -e "OPTIMIZE TABLE router_metrics, orchestration_runs;"
   ```

4. **Scaling Assessment**: If trend continues, prepare for resource scaling

### ⚠️ StabilityScoreDropped / RouterEfficiencyLow

**Response Time**: 30 minutes  
**Focus**: System optimization and tuning  

#### Response Procedure
1. **Analyze Patterns**:
   ```bash
   # Check router performance
   ./scripts/monitoring/sla_monitor.py --report 1 | grep -A 5 "router"
   
   # Review recent orchestration runs
   ls -la /var/lib/genesis/orchestrator_runs/ | head -20
   ```

2. **Optimization Actions**:
   - Review router algorithm configuration
   - Adjust token budgets if needed
   - Clear and rebuild caches
   - Update routing rules if pattern changes detected

## SLA Monitoring

### Daily SLA Review
**Frequency**: Every morning at 9 AM  
**Owner**: DevOps team  

```bash
# Generate daily SLA report
./scripts/monitoring/sla_monitor.py --report 1 --format html --output /tmp/sla_daily.html

# Review key metrics
./scripts/monitoring/sla_monitor.py --report 1 --format json | jq '.actual_availability_percent'
```

### Weekly SLA Analysis
**Frequency**: Every Monday  
**Owner**: Engineering Manager  

1. **Generate Weekly Report**:
   ```bash
   ./scripts/monitoring/sla_monitor.py --report 7 --format html --output reports/sla_weekly_$(date +%Y%m%d).html
   ```

2. **Review Trends**: Analyze patterns and identify improvement opportunities
3. **Action Items**: Create tickets for any recurring issues

### Monthly SLA Review
**Frequency**: First Monday of each month  
**Attendees**: Engineering, Product, Customer Success  

1. **Generate Monthly Report**: Full SLA compliance report with customer impact
2. **Executive Summary**: Prepare summary for leadership team
3. **Customer Communication**: Notify customers if SLA was not met

## Incident Response Procedures

### Incident Classification
- **P0**: Complete service outage (>30 seconds downtime)
- **P1**: Severe degradation (>50% error rate or >10s latency)
- **P2**: Moderate impact (SLA at risk but not breached)
- **P3**: Minor issues (monitoring anomalies)

### P0 Incident Response
1. **War Room**: Immediate call with on-call team
2. **Communication**: Update status page within 5 minutes
3. **Resolution**: All-hands focus until resolved
4. **Post-Mortem**: Required within 48 hours

### Automated Incident Response
The system includes automated responses for common issues:

```bash
# View automated response configuration
cat monitoring/prometheus/alert_rules/genesis_automated_response.yml

# Check incident response history
python3 scripts/monitoring/incident_response.py --history
```

### Manual Override
To disable automated responses during maintenance:
```bash
# Silence alerts in AlertManager
amtool silence add alertname="Auto.*" --duration="1h" --author="maintenance" --comment="Maintenance window"
```

## Troubleshooting Guide

### Service Won't Start
1. **Check Configuration**:
   ```bash
   # Validate config files
   python3 -c "import json; json.load(open('config/router_config.json'))"
   
   # Check environment variables
   env | grep GENESIS
   ```

2. **Check Dependencies**:
   ```bash
   # Database connectivity
   nc -zv localhost 3306
   
   # Redis connectivity
   nc -zv localhost 6379
   
   # Temporal connectivity (if configured)
   nc -zv localhost 7233
   ```

3. **Review Logs**:
   ```bash
   # System logs
   journalctl -u genesis-orchestrator -f
   
   # Application logs
   tail -f /var/log/genesis/orchestrator.log
   
   # Error logs
   grep ERROR /var/log/genesis/orchestrator.log | tail -20
   ```

### Performance Issues
1. **Resource Analysis**:
   ```bash
   # CPU usage per process
   ps -eo pid,ppid,cmd,%mem,%cpu --sort=-%cpu | head
   
   # Memory usage
   free -h && echo && cat /proc/meminfo | head -20
   
   # Disk I/O
   iotop -o -d 1
   ```

2. **Database Performance**:
   ```bash
   # Connection count
   mysql -e "SHOW STATUS LIKE 'Threads_connected';"
   
   # Slow queries
   mysql -e "SHOW VARIABLES LIKE 'slow_query%';"
   
   # InnoDB status
   mysql -e "SHOW ENGINE INNODB STATUS\G"
   ```

### Network Issues
1. **Connectivity Tests**:
   ```bash
   # Check listening ports
   ss -tulpn | grep -E ":8000|:9090|:3000"
   
   # Test external connectivity
   curl -I https://api.openai.com/
   curl -I https://api.anthropic.com/
   ```

2. **Firewall Check**:
   ```bash
   # Check iptables rules
   sudo iptables -L -n
   
   # Check UFW status
   sudo ufw status verbose
   ```

## Maintenance Procedures

### Weekly Maintenance
**Schedule**: Every Sunday 2 AM UTC  
**Duration**: 1 hour maintenance window  

1. **Pre-Maintenance**:
   ```bash
   # Create backup
   ./scripts/monitoring/backup_monitoring.sh
   
   # Silence alerts
   amtool silence add job="genesis-orchestrator" --duration="2h" --author="maintenance"
   ```

2. **Maintenance Tasks**:
   ```bash
   # Update monitoring stack
   cd monitoring/
   ./scripts/update_monitoring.sh
   
   # Database maintenance
   mysql -e "OPTIMIZE TABLE router_metrics, orchestration_runs, stability_tracking;"
   
   # Log rotation
   sudo logrotate -f /etc/logrotate.d/genesis-orchestrator
   
   # Clear old artifacts
   find orchestrator_runs/ -mtime +30 -type d -exec rm -rf {} +
   ```

3. **Post-Maintenance**:
   ```bash
   # Verify services
   ./scripts/monitoring/check_monitoring_health.sh
   
   # Test critical paths
   curl http://localhost:8000/health/ready
   
   # Remove silences
   amtool silence expire $(amtool silence query job="genesis-orchestrator" -q)
   ```

### Monthly Deep Maintenance
**Schedule**: First Sunday of each month  
**Duration**: 3 hour maintenance window  

1. **Performance Optimization**:
   ```bash
   # Database optimization
   mysql -e "ANALYZE TABLE router_metrics, orchestration_runs;"
   mysqlcheck --optimize --all-databases
   
   # Index analysis
   mysql -e "SELECT * FROM sys.schema_unused_indexes;"
   ```

2. **Security Updates**:
   ```bash
   # Update system packages
   sudo apt update && sudo apt upgrade -y
   
   # Update monitoring images
   cd monitoring/
   docker-compose pull
   docker-compose up -d
   ```

3. **Capacity Planning**:
   ```bash
   # Generate capacity report
   ./scripts/monitoring/sla_monitor.py --report 30 --format html > reports/capacity_$(date +%Y%m).html
   
   # Check disk usage trends
   df -h
   du -sh /var/lib/genesis/
   ```

## Escalation Matrix

### On-Call Engineer (Primary)
- **Response Time**: 5 minutes
- **Responsibilities**: Initial response, basic troubleshooting
- **Escalation**: After 30 minutes if unresolved

### Senior Engineer (Secondary)
- **Response Time**: 15 minutes
- **Responsibilities**: Complex troubleshooting, architecture decisions
- **Escalation**: After 1 hour if unresolved

### Engineering Manager (Tertiary)
- **Response Time**: 30 minutes
- **Responsibilities**: Resource allocation, customer communication
- **Escalation**: C-level after 2 hours

### Emergency Contacts
- **Engineering Manager**: +1-xxx-xxx-xxxx
- **CTO**: +1-xxx-xxx-xxxx (P0 incidents only)
- **Customer Success**: +1-xxx-xxx-xxxx (customer impact)

## Tools and Commands Reference

### Essential Commands
```bash
# Check overall system health
./scripts/health_check.py

# View current alerts
curl -s http://localhost:9093/api/v1/alerts | jq '.data[].labels.alertname'

# Generate SLA report
./scripts/monitoring/sla_monitor.py --report 1 --format json

# Check monitoring stack health
./scripts/monitoring/check_monitoring_health.sh

# View incident response history
python3 scripts/monitoring/incident_response.py --history
```

### Useful Queries
```bash
# Prometheus queries for troubleshooting
curl -G 'http://localhost:9090/api/v1/query' --data-urlencode 'query=up{job="genesis-orchestrator"}'
curl -G 'http://localhost:9090/api/v1/query' --data-urlencode 'query=rate(genesis_orchestrator_total_runs[5m])'

# AlertManager queries
curl -s http://localhost:9093/api/v1/status | jq '.data'
curl -s http://localhost:9093/api/v1/silences | jq '.data[].matchers'
```

### Dashboard URLs
- **Main Overview**: http://localhost:3000/d/genesis-overview
- **SLA Dashboard**: http://localhost:3000/d/genesis-sla
- **Infrastructure**: http://localhost:3000/d/genesis-infrastructure
- **Prometheus**: http://localhost:9090
- **AlertManager**: http://localhost:9093

---

## Emergency Procedures

### Complete Monitoring Stack Failure
1. **Immediate**: Switch to manual monitoring
2. **Restore**: Restore from backup
3. **Verify**: Full system verification

### Database Corruption
1. **Immediate**: Stop writes to affected tables
2. **Restore**: Restore from backup
3. **Validate**: Data integrity check

### Network Partition
1. **Assess**: Determine scope of partition
2. **Isolate**: Prevent split-brain scenarios
3. **Restore**: Restore connectivity and sync

Remember: When in doubt, escalate immediately. It's better to over-communicate than under-communicate during incidents.

---
*Last Updated: $(date)*
*Version: 1.0*