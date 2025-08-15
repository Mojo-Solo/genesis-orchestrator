# GENESIS Orchestrator - Production Monitoring Setup Guide

## Overview

This guide provides complete instructions for setting up production-grade monitoring and alerting infrastructure for the GENESIS Orchestrator. The monitoring stack provides:

- **Real-time metrics collection** with Prometheus
- **Visual dashboards** with Grafana
- **Multi-channel alerting** with AlertManager
- **Automated incident response** with custom scripts
- **SLA tracking and reporting** with 99.9% uptime target
- **Anomaly detection** with statistical analysis
- **Predictive alerting** for proactive issue resolution

## Architecture Overview

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│  GENESIS        │    │  Prometheus     │    │   Grafana       │
│  Orchestrator   ├───►│  (Metrics)      ├───►│  (Dashboards)   │
│  + Health APIs  │    │                 │    │                 │
└─────────────────┘    └─────────┬───────┘    └─────────────────┘
                                 │
                        ┌────────▼────────┐    ┌─────────────────┐
                        │  AlertManager   ├───►│  Notifications  │
                        │  (Routing)      │    │  Slack/Email/PD │
                        └─────────────────┘    └─────────────────┘
                                 │
                        ┌────────▼────────┐
                        │  Incident       │
                        │  Response       │
                        │  Automation     │
                        └─────────────────┘
```

## Prerequisites

### System Requirements
- **OS**: Ubuntu 20.04+ or CentOS 8+
- **RAM**: Minimum 8GB (16GB recommended)
- **Disk**: Minimum 100GB SSD (500GB recommended)
- **CPU**: Minimum 4 cores (8 cores recommended)
- **Network**: Stable internet connection

### Software Dependencies
- Docker Engine 20.10+
- Docker Compose v2.0+
- Python 3.8+
- Git
- curl, wget, jq

### Access Requirements
- Sudo access on target system
- SMTP server for email alerts (optional)
- Slack workspace for Slack alerts (optional)
- PagerDuty account for escalation (optional)

## Installation Steps

### Step 1: Clone Repository and Setup Environment

```bash
# Clone the repository
git clone <repository-url>
cd genesis_eval_spec

# Create necessary directories
sudo mkdir -p /var/lib/genesis
sudo mkdir -p /var/log/genesis
sudo chown -R $USER:$USER /var/lib/genesis /var/log/genesis

# Install Python dependencies
pip3 install -r requirements-production.txt
```

### Step 2: Configure Environment Variables

```bash
# Create production environment file
cp env.example .env.production

# Edit environment variables
nano .env.production
```

Required environment variables:
```bash
# Database Configuration
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=genesis_orchestrator
DB_USERNAME=genesis_user
DB_PASSWORD=your_secure_password

# Redis Configuration
REDIS_HOST=localhost
REDIS_PORT=6379
REDIS_PASSWORD=your_redis_password

# Monitoring Configuration
PROMETHEUS_URL=http://localhost:9090
GRAFANA_ADMIN_PASSWORD=your_secure_grafana_password

# Alert Notification Configuration
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/SLACK/WEBHOOK
SMTP_SERVER=smtp.your-domain.com:587
SMTP_USERNAME=alerts@your-domain.com
SMTP_PASSWORD=your_smtp_password
ALERT_FROM_EMAIL=alerts@your-domain.com
PAGERDUTY_INTEGRATION_KEY=your_pagerduty_key

# Automation Webhook URL
AUTOMATION_WEBHOOK_URL=http://localhost:8080/webhooks
```

### Step 3: Run Monitoring Setup Script

```bash
# Make setup script executable
chmod +x scripts/monitoring/setup_monitoring.sh

# Run the setup script
./scripts/monitoring/setup_monitoring.sh
```

The setup script will:
- Create all necessary directories
- Generate Docker Compose configuration
- Set up Prometheus configuration
- Configure AlertManager routing
- Create Grafana provisioning files
- Generate management scripts

### Step 4: Start Monitoring Stack

```bash
# Navigate to monitoring directory
cd monitoring/

# Start all monitoring services
./scripts/start_monitoring.sh

# Verify all services are healthy
./scripts/check_monitoring_health.sh
```

Expected output:
```
✓ Prometheus is healthy
✓ AlertManager is healthy
✓ Grafana is healthy
✓ Node Exporter is healthy
✓ MySQL Exporter is healthy
✓ Redis Exporter is healthy
```

### Step 5: Import Grafana Dashboards

1. **Access Grafana**: http://localhost:3000
2. **Login**: admin / (your configured password)
3. **Import Dashboards**:
   - Navigate to Dashboards → Import
   - Import each JSON file from `monitoring/grafana/dashboards/`
   - Available dashboards:
     - **Genesis Orchestrator Overview**: Main operational dashboard
     - **Genesis SLA Dashboard**: SLA compliance and error budget tracking
     - **Genesis Infrastructure**: System resource monitoring

### Step 6: Configure Alert Notifications

#### Slack Integration
1. **Create Slack Webhooks** in your workspace
2. **Update AlertManager configuration**:
   ```bash
   # Edit AlertManager config
   nano monitoring/alertmanager/alertmanager.yml
   
   # Update webhook URLs
   sed -i 's/\${SLACK_WEBHOOK_URL}/your_actual_webhook_url/g' monitoring/alertmanager/alertmanager.yml
   ```

#### Email Integration
1. **Configure SMTP settings** in environment variables
2. **Test email delivery**:
   ```bash
   # Test email configuration
   python3 -c "
   import smtplib
   from email.mime.text import MIMEText
   
   msg = MIMEText('Test email from GENESIS monitoring')
   msg['Subject'] = 'Test Alert'
   msg['From'] = 'alerts@your-domain.com'
   msg['To'] = 'your-email@domain.com'
   
   server = smtplib.SMTP('your-smtp-server:587')
   server.starttls()
   server.login('username', 'password')
   server.send_message(msg)
   server.quit()
   print('Email sent successfully')
   "
   ```

#### PagerDuty Integration
1. **Create PagerDuty service** with Events API v2
2. **Configure integration key** in environment variables
3. **Test PagerDuty integration**:
   ```bash
   curl -X POST https://events.pagerduty.com/v2/enqueue \
     -H 'Content-Type: application/json' \
     -d '{
       "routing_key": "your_integration_key",
       "event_action": "trigger",
       "payload": {
         "summary": "Test alert from GENESIS monitoring",
         "source": "genesis-orchestrator",
         "severity": "info"
       }
     }'
   ```

### Step 7: Setup Automated Incident Response

```bash
# Configure incident response system
python3 scripts/monitoring/incident_response.py --config

# Test automated responses (dry run)
python3 scripts/monitoring/incident_response.py --test

# Start incident response webhook server
nohup python3 scripts/monitoring/incident_response.py --server &
```

### Step 8: Initialize SLA Monitoring

```bash
# Initialize SLA database
python3 scripts/monitoring/sla_monitor.py --init

# Start SLA monitoring daemon
nohup python3 scripts/monitoring/sla_monitor.py --monitor --interval 60 &

# Generate initial SLA report
python3 scripts/monitoring/sla_monitor.py --report 1 --format html --output /tmp/sla_initial.html
```

### Step 9: Setup System Services (Optional)

Create systemd services for automatic startup:

```bash
# Create systemd service files
sudo tee /etc/systemd/system/genesis-monitoring.service > /dev/null <<EOF
[Unit]
Description=GENESIS Orchestrator Monitoring Stack
After=docker.service
Requires=docker.service

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=$(pwd)/monitoring
ExecStart=$(pwd)/monitoring/scripts/start_monitoring.sh
ExecStop=$(pwd)/monitoring/scripts/stop_monitoring.sh
User=$USER
Group=$USER

[Install]
WantedBy=multi-user.target
EOF

# Enable and start service
sudo systemctl enable genesis-monitoring.service
sudo systemctl start genesis-monitoring.service
```

## Configuration Details

### Prometheus Configuration

Key features of the Prometheus setup:
- **Scrape Interval**: 15 seconds for general metrics, 5 seconds for critical services
- **Retention**: 90 days of metrics data
- **Storage**: 50GB maximum storage with automatic cleanup
- **Targets**: Orchestrator API, health endpoints, system metrics, database, Redis

### AlertManager Configuration

Alert routing strategy:
- **Critical Alerts**: Immediate notifications via Slack, email, and PagerDuty
- **Warning Alerts**: Slack and email notifications with grouping
- **Security Alerts**: Dedicated security team notification channel
- **Inhibition Rules**: Prevent alert flooding during outages

### Grafana Dashboards

Three main dashboards provided:
1. **Orchestrator Overview**: Real-time operational metrics
2. **SLA Dashboard**: Compliance tracking and error budget visualization  
3. **Infrastructure Dashboard**: System resource monitoring

## Monitoring Endpoints

The GENESIS Orchestrator exposes several monitoring endpoints:

### Health Check Endpoints
- **Readiness**: `GET /health/ready` - Dependencies and readiness check
- **Liveness**: `GET /health/live` - Basic responsiveness check
- **Metrics**: `GET /health/metrics` - Operational metrics in JSON format

### Prometheus Metrics Endpoints
- **Application Metrics**: `GET /api/metrics/application` - App-level metrics
- **Business Metrics**: `GET /api/metrics/business` - SLA and business metrics
- **Security Metrics**: `GET /api/metrics/security` - Security event metrics

### Custom Monitoring Endpoints
- **SLA Report**: `GET /api/monitoring/sla` - Current SLA status
- **Incident Status**: `GET /api/monitoring/incidents` - Active incidents
- **System Health**: `GET /api/monitoring/health` - Comprehensive health check

## Alert Rules Explained

### Critical Alerts (Immediate Response Required)
- **GenesisOrchestratorDown**: Service unavailable for 30+ seconds
- **SLAAvailabilityBreach**: Availability drops below 99.9%
- **CriticalFailureRate**: Error rate exceeds 15%
- **CriticalOrchestrationLatency**: Response time exceeds 10 seconds

### Warning Alerts (Monitor and Investigate)
- **HighFailureRate**: Error rate 5-15%
- **HighOrchestrationLatency**: Response time 5-10 seconds
- **StabilityScoreDropped**: System stability below 95%
- **TokenBudgetExhaustion**: Token usage approaching limits

### Predictive Alerts (Proactive Intervention)
- **DiskSpaceExhaustionPredicted**: Disk full predicted within 4 hours
- **MemoryExhaustionPredicted**: Memory exhaustion predicted within 2 hours
- **TokenBudgetExhaustionPredicted**: Token budget exhaustion within 1 hour

### Anomaly Detection Alerts
- **RequestRateAnomaly**: Request rate deviates 3+ standard deviations
- **ResponseTimeAnomaly**: Response time deviates 2+ standard deviations
- **MemoryUsageAnomaly**: Memory usage shows unusual patterns

## SLA Monitoring

### SLA Targets
- **Availability**: 99.9% (43.2 minutes max downtime per month)
- **Performance**: 95th percentile response time ≤ 2 seconds
- **Reliability**: Error rate ≤ 1% of all requests

### Error Budget Management
- **Monthly Budget**: 0.1% of total time (43.2 minutes)
- **Budget Tracking**: Real-time consumption monitoring
- **Alerts**: Warnings at 50% and 80% budget consumption

### SLA Reporting
- **Daily Reports**: Automated generation every morning
- **Weekly Analysis**: Trend analysis and improvement recommendations
- **Monthly Executive Reports**: Compliance summary with customer impact

## Automated Incident Response

### Response Actions
- **Service Restart**: Automatic restart for unresponsive services
- **Circuit Breaker**: Automatic protection during high error rates
- **Cache Clearing**: Performance optimization during cache issues
- **Resource Scaling**: Recommendations for resource scaling
- **Log Rotation**: Automatic cleanup during disk space issues

### Escalation Procedures
1. **Automated Response**: 0-2 minutes
2. **On-Call Engineer**: 2-30 minutes
3. **Senior Engineer**: 30 minutes - 1 hour
4. **Engineering Manager**: 1+ hours
5. **Executive Escalation**: 2+ hours (P0 incidents)

## Troubleshooting

### Common Issues

#### Monitoring Stack Won't Start
```bash
# Check Docker status
sudo systemctl status docker

# Check container status
docker-compose ps

# View container logs
docker-compose logs prometheus
docker-compose logs grafana
docker-compose logs alertmanager
```

#### No Metrics Appearing
```bash
# Check Prometheus targets
curl http://localhost:9090/api/v1/targets

# Test metrics endpoint
curl http://localhost:8000/health/metrics

# Check network connectivity
telnet localhost 8000
```

#### Alerts Not Firing
```bash
# Check alert rules
curl http://localhost:9090/api/v1/rules

# Check AlertManager configuration
curl http://localhost:9093/api/v1/status

# Test alert evaluation
curl -G http://localhost:9090/api/v1/query --data-urlencode 'query=up{job="genesis-orchestrator"}'
```

#### Grafana Dashboard Issues
```bash
# Check Grafana logs
docker-compose logs grafana

# Verify datasource configuration
curl -u admin:password http://localhost:3000/api/datasources

# Test Prometheus connectivity from Grafana
curl -u admin:password http://localhost:3000/api/datasources/proxy/1/api/v1/query?query=up
```

### Log Locations
- **Monitoring Stack**: `docker-compose logs <service>`
- **SLA Monitor**: `/var/log/genesis/sla_monitor.log`
- **Incident Response**: `/var/log/genesis/incident_response.log`
- **Orchestrator**: `/var/log/genesis/orchestrator.log`

## Maintenance

### Daily Tasks
- Check SLA dashboard for compliance
- Review any alert notifications
- Verify monitoring stack health

### Weekly Tasks
- Generate SLA report
- Review alert trends
- Check disk usage and cleanup old data
- Update monitoring stack if needed

### Monthly Tasks
- Deep performance analysis
- Capacity planning review
- Security updates
- Backup monitoring configuration

## Security Considerations

### Access Control
- Grafana authentication required
- Prometheus and AlertManager behind firewall
- API endpoints with authentication
- Limited sudo access for monitoring user

### Data Protection
- Metrics data encrypted at rest
- Alert notifications over secure channels
- Sensitive configuration in environment variables
- Regular security updates

### Network Security
- Firewall rules for monitoring ports
- TLS encryption for external communications
- VPN access for remote monitoring
- Network segmentation for monitoring components

## Performance Optimization

### Prometheus Optimization
```bash
# Adjust retention based on usage
--storage.tsdb.retention.time=90d
--storage.tsdb.retention.size=50GB

# Optimize query performance
--query.max-concurrency=20
--query.timeout=2m
```

### Grafana Optimization
```bash
# Enable caching
GF_RENDERING_SERVER_URL=http://renderer:8081/render
GF_RENDERING_CALLBACK_URL=http://grafana:3000/

# Optimize database
GF_DATABASE_WAL=true
GF_DATABASE_CACHE_MODE=private
```

### Resource Monitoring
```bash
# Monitor container resources
docker stats

# Check disk usage
df -h
du -sh /var/lib/docker/volumes/

# Monitor memory usage
free -h
ps aux --sort=-%mem | head
```

## Support and Contacts

### Documentation
- **Runbook**: `docs/monitoring/MONITORING_RUNBOOK.md`
- **API Reference**: `docs/monitoring/API_REFERENCE.md`
- **Troubleshooting**: `docs/monitoring/TROUBLESHOOTING_GUIDE.md`

### Emergency Contacts
- **On-Call Engineer**: Slack `@oncall` or PagerDuty
- **Engineering Manager**: For escalation after 30 minutes
- **DevOps Team**: For infrastructure issues

### Useful Commands Reference
```bash
# Check monitoring health
./scripts/monitoring/check_monitoring_health.sh

# Generate SLA report
./scripts/monitoring/sla_monitor.py --report 7 --format html

# View active alerts
curl -s http://localhost:9093/api/v1/alerts | jq '.data[].labels'

# Restart monitoring stack
cd monitoring && ./scripts/stop_monitoring.sh && ./scripts/start_monitoring.sh

# Backup monitoring data
./scripts/monitoring/backup_monitoring.sh
```

---

## Next Steps

After completing the setup:

1. **Test Alert Flows**: Trigger test alerts to verify notification delivery
2. **Customize Dashboards**: Adjust dashboards based on your specific needs
3. **Set Up Backups**: Implement regular backups of monitoring data
4. **Train Team**: Ensure team members are familiar with monitoring tools
5. **Review and Tune**: Continuously review and optimize alert thresholds

For additional support or questions, consult the runbook or contact the DevOps team.

---
*Last Updated: $(date)*
*Version: 1.0*