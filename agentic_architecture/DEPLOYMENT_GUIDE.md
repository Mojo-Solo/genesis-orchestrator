# Agentic Architecture System - Deployment Guide

## Overview

This guide provides comprehensive instructions for deploying the Agentic Architecture System in various environments, from development to enterprise production.

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Development Deployment](#development-deployment)
3. [Staging Deployment](#staging-deployment)
4. [Production Deployment](#production-deployment)
5. [Cloud Deployment](#cloud-deployment)
6. [Kubernetes Deployment](#kubernetes-deployment)
7. [Configuration Management](#configuration-management)
8. [Monitoring Setup](#monitoring-setup)
9. [Security Configuration](#security-configuration)
10. [Backup and Recovery](#backup-and-recovery)
11. [Troubleshooting](#troubleshooting)

## Prerequisites

### System Requirements

#### Minimum Requirements (Development)
- **CPU**: 4 cores
- **RAM**: 8GB
- **Storage**: 50GB SSD
- **Network**: 1Gbps
- **OS**: Linux (Ubuntu 20.04+), macOS (10.15+), Windows 10+

#### Recommended Requirements (Production)
- **CPU**: 16 cores
- **RAM**: 32GB
- **Storage**: 500GB NVMe SSD
- **Network**: 10Gbps
- **OS**: Linux (Ubuntu 20.04+ LTS)

#### Cluster Requirements (Enterprise)
- **Nodes**: 3+ worker nodes
- **CPU per node**: 8+ cores
- **RAM per node**: 16GB+
- **Storage**: 1TB+ distributed storage
- **Network**: 10Gbps+ with low latency

### Software Dependencies

```bash
# Python 3.9+
python --version  # >= 3.9.0

# Node.js 16+ (for frontend components)
node --version    # >= 16.0.0

# Docker and Docker Compose
docker --version         # >= 20.10.0
docker-compose --version # >= 1.29.0

# Kubernetes (for cluster deployment)
kubectl version --client # >= 1.22.0

# Database systems
# PostgreSQL 13+ or MySQL 8.0+
# Redis 6.0+
# Elasticsearch 7.15+ (optional, for enhanced search)
```

### External Services

- **LLM API Access**: OpenAI GPT-4, Claude, or compatible API
- **Vector Database**: Pinecone, Weaviate, or Milvus (optional)
- **Monitoring**: Prometheus + Grafana (included in deployment)
- **Secret Management**: HashiCorp Vault or AWS Secrets Manager

## Development Deployment

### Local Development Setup

```bash
# 1. Clone the repository
git clone <repository-url>
cd genesis_eval_spec/agentic_architecture

# 2. Create virtual environment
python -m venv venv
source venv/bin/activate  # On Windows: venv\Scripts\activate

# 3. Install dependencies
pip install -r requirements.txt
pip install -r requirements-dev.txt

# 4. Set up environment variables
cp config/environment.example.env .env
```

### Environment Configuration (.env)

```bash
# Core Configuration
ENVIRONMENT=development
DEBUG=true
LOG_LEVEL=DEBUG

# Database Configuration
DATABASE_URL=postgresql://user:password@localhost:5432/agentic_db
REDIS_URL=redis://localhost:6379/0

# API Keys
OPENAI_API_KEY=your_openai_api_key
ANTHROPIC_API_KEY=your_anthropic_api_key

# Security
SECRET_KEY=your_secret_key_here
ENCRYPTION_KEY=your_encryption_key_here

# Genesis Orchestrator Integration
GENESIS_ORCHESTRATOR_URL=http://localhost:8000
GENESIS_API_KEY=your_genesis_api_key

# Monitoring
ENABLE_METRICS=true
METRICS_PORT=9090
```

### Database Setup

```bash
# Start PostgreSQL and Redis
docker-compose -f docker-compose.dev.yml up -d postgres redis

# Run database migrations
python -m alembic upgrade head

# Load sample data (optional)
python scripts/load_sample_data.py
```

### Run Development Server

```bash
# Start the unified orchestrator
python -m agentic_architecture.integration.unified_orchestrator

# In another terminal, start the API server
python -m agentic_architecture.api.server

# Access the system
curl http://localhost:8000/health
```

## Staging Deployment

### Docker Compose Staging

```bash
# 1. Create staging environment file
cp config/staging.example.env staging.env

# 2. Update staging configuration
# Edit staging.env with staging-specific values

# 3. Deploy with Docker Compose
docker-compose -f docker-compose.staging.yml --env-file staging.env up -d

# 4. Verify deployment
curl http://staging-server:8000/health
```

### Staging Configuration (staging.env)

```bash
ENVIRONMENT=staging
DEBUG=false
LOG_LEVEL=INFO

# Use staging database
DATABASE_URL=postgresql://staging_user:staging_pass@staging-db:5432/agentic_staging

# Enable monitoring
PROMETHEUS_ENABLED=true
GRAFANA_ENABLED=true

# Security hardening
CORS_ORIGINS=https://staging.yourdomain.com
RATE_LIMIT_PER_MINUTE=500
```

## Production Deployment

### Production Prerequisites

```bash
# 1. Set up production server
# - Configure firewall rules
# - Set up SSL certificates
# - Configure load balancer
# - Set up backup systems

# 2. Install production dependencies
sudo apt update
sudo apt install -y docker.io docker-compose nginx postgresql-client redis-tools

# 3. Configure system limits
echo "* soft nofile 65536" >> /etc/security/limits.conf
echo "* hard nofile 65536" >> /etc/security/limits.conf
```

### Production Deployment Script

```bash
#!/bin/bash
# deploy_production.sh

set -e

echo "Starting production deployment..."

# 1. Pull latest code
git pull origin main

# 2. Build production images
docker-compose -f docker-compose.prod.yml build

# 3. Run database migrations
docker-compose -f docker-compose.prod.yml run --rm api python -m alembic upgrade head

# 4. Deploy with rolling update
docker-compose -f docker-compose.prod.yml up -d --scale api=3

# 5. Health check
sleep 30
curl -f http://localhost:8000/health || exit 1

# 6. Update load balancer
./scripts/update_load_balancer.sh

echo "Production deployment completed successfully!"
```

### Production Configuration (production.env)

```bash
ENVIRONMENT=production
DEBUG=false
LOG_LEVEL=WARNING

# Production database with connection pooling
DATABASE_URL=postgresql://prod_user:${DB_PASSWORD}@prod-db-cluster:5432/agentic_prod?sslmode=require
DATABASE_POOL_SIZE=20
DATABASE_MAX_OVERFLOW=30

# Redis cluster
REDIS_URL=redis://prod-redis-cluster:6379/0
REDIS_CLUSTER_ENABLED=true

# Security
SECRET_KEY=${SECRET_KEY}
ENCRYPTION_KEY=${ENCRYPTION_KEY}
JWT_SECRET=${JWT_SECRET}

# Rate limiting
RATE_LIMIT_PER_MINUTE=1000
RATE_LIMIT_BURST=100

# Monitoring
PROMETHEUS_ENABLED=true
GRAFANA_ENABLED=true
JAEGER_ENABLED=true

# Performance
WORKER_PROCESSES=4
WORKER_CONNECTIONS=1000
KEEPALIVE_TIMEOUT=65

# Backup
BACKUP_ENABLED=true
BACKUP_SCHEDULE="0 2 * * *"
BACKUP_RETENTION_DAYS=30
```

## Cloud Deployment

### AWS Deployment

#### ECS Deployment

```bash
# 1. Create ECS cluster
aws ecs create-cluster --cluster-name agentic-architecture-cluster

# 2. Create task definition
aws ecs register-task-definition --cli-input-json file://aws/task-definition.json

# 3. Create service
aws ecs create-service \
  --cluster agentic-architecture-cluster \
  --service-name agentic-architecture-service \
  --task-definition agentic-architecture:1 \
  --desired-count 3
```

#### Task Definition (aws/task-definition.json)

```json
{
  "family": "agentic-architecture",
  "networkMode": "awsvpc",
  "requiresCompatibilities": ["FARGATE"],
  "cpu": "2048",
  "memory": "4096",
  "executionRoleArn": "arn:aws:iam::account:role/ecsTaskExecutionRole",
  "taskRoleArn": "arn:aws:iam::account:role/ecsTaskRole",
  "containerDefinitions": [
    {
      "name": "agentic-architecture",
      "image": "your-ecr-repo/agentic-architecture:latest",
      "portMappings": [
        {
          "containerPort": 8000,
          "protocol": "tcp"
        }
      ],
      "environment": [
        {
          "name": "ENVIRONMENT",
          "value": "production"
        }
      ],
      "secrets": [
        {
          "name": "DATABASE_URL",
          "valueFrom": "arn:aws:secretsmanager:region:account:secret:agentic-db-credentials"
        }
      ],
      "logConfiguration": {
        "logDriver": "awslogs",
        "options": {
          "awslogs-group": "/aws/ecs/agentic-architecture",
          "awslogs-region": "us-west-2",
          "awslogs-stream-prefix": "ecs"
        }
      }
    }
  ]
}
```

### Google Cloud Platform Deployment

#### Cloud Run Deployment

```bash
# 1. Build and push container
gcloud builds submit --tag gcr.io/PROJECT_ID/agentic-architecture

# 2. Deploy to Cloud Run
gcloud run deploy agentic-architecture \
  --image gcr.io/PROJECT_ID/agentic-architecture \
  --platform managed \
  --region us-central1 \
  --allow-unauthenticated \
  --memory 4Gi \
  --cpu 2 \
  --concurrency 100 \
  --max-instances 10
```

### Azure Deployment

#### Container Instances

```bash
# 1. Create resource group
az group create --name agentic-architecture-rg --location eastus

# 2. Deploy container
az container create \
  --resource-group agentic-architecture-rg \
  --name agentic-architecture \
  --image your-registry/agentic-architecture:latest \
  --cpu 2 \
  --memory 4 \
  --ports 8000 \
  --environment-variables ENVIRONMENT=production
```

## Kubernetes Deployment

### Kubernetes Manifests

#### Namespace (k8s/namespace.yaml)

```yaml
apiVersion: v1
kind: Namespace
metadata:
  name: agentic-architecture
  labels:
    name: agentic-architecture
```

#### Deployment (k8s/deployment.yaml)

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: agentic-architecture
  namespace: agentic-architecture
spec:
  replicas: 3
  selector:
    matchLabels:
      app: agentic-architecture
  template:
    metadata:
      labels:
        app: agentic-architecture
    spec:
      containers:
      - name: agentic-architecture
        image: agentic-architecture:latest
        ports:
        - containerPort: 8000
        env:
        - name: ENVIRONMENT
          value: "production"
        - name: DATABASE_URL
          valueFrom:
            secretKeyRef:
              name: agentic-secrets
              key: database-url
        resources:
          requests:
            memory: "2Gi"
            cpu: "1"
          limits:
            memory: "4Gi"
            cpu: "2"
        livenessProbe:
          httpGet:
            path: /health
            port: 8000
          initialDelaySeconds: 30
          periodSeconds: 10
        readinessProbe:
          httpGet:
            path: /ready
            port: 8000
          initialDelaySeconds: 5
          periodSeconds: 5
```

#### Service (k8s/service.yaml)

```yaml
apiVersion: v1
kind: Service
metadata:
  name: agentic-architecture-service
  namespace: agentic-architecture
spec:
  selector:
    app: agentic-architecture
  ports:
  - port: 80
    targetPort: 8000
  type: ClusterIP
```

#### Ingress (k8s/ingress.yaml)

```yaml
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: agentic-architecture-ingress
  namespace: agentic-architecture
  annotations:
    kubernetes.io/ingress.class: nginx
    cert-manager.io/cluster-issuer: letsencrypt-prod
    nginx.ingress.kubernetes.io/rate-limit: "100"
spec:
  tls:
  - hosts:
    - api.yourdomain.com
    secretName: agentic-tls
  rules:
  - host: api.yourdomain.com
    http:
      paths:
      - path: /
        pathType: Prefix
        backend:
          service:
            name: agentic-architecture-service
            port:
              number: 80
```

#### Secrets (k8s/secrets.yaml)

```yaml
apiVersion: v1
kind: Secret
metadata:
  name: agentic-secrets
  namespace: agentic-architecture
type: Opaque
data:
  database-url: <base64-encoded-database-url>
  openai-api-key: <base64-encoded-openai-key>
  encryption-key: <base64-encoded-encryption-key>
```

### Deploy to Kubernetes

```bash
# 1. Create namespace
kubectl apply -f k8s/namespace.yaml

# 2. Create secrets (after encoding values)
kubectl apply -f k8s/secrets.yaml

# 3. Deploy application
kubectl apply -f k8s/deployment.yaml
kubectl apply -f k8s/service.yaml
kubectl apply -f k8s/ingress.yaml

# 4. Verify deployment
kubectl get pods -n agentic-architecture
kubectl get services -n agentic-architecture

# 5. Check logs
kubectl logs -f deployment/agentic-architecture -n agentic-architecture
```

### Horizontal Pod Autoscaler (k8s/hpa.yaml)

```yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: agentic-architecture-hpa
  namespace: agentic-architecture
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: agentic-architecture
  minReplicas: 3
  maxReplicas: 20
  metrics:
  - type: Resource
    resource:
      name: cpu
      target:
        type: Utilization
        averageUtilization: 70
  - type: Resource
    resource:
      name: memory
      target:
        type: Utilization
        averageUtilization: 80
```

## Configuration Management

### Environment-Specific Configurations

#### Development (config/development.yaml)

```yaml
debug: true
log_level: DEBUG
database:
  pool_size: 5
  max_overflow: 10
redis:
  max_connections: 10
security:
  rate_limit_per_minute: 100
monitoring:
  enable_profiling: true
```

#### Production (config/production.yaml)

```yaml
debug: false
log_level: WARNING
database:
  pool_size: 20
  max_overflow: 30
  ssl_mode: require
redis:
  max_connections: 100
  cluster_enabled: true
security:
  rate_limit_per_minute: 1000
  enable_cors: true
  cors_origins:
    - "https://yourdomain.com"
monitoring:
  enable_profiling: false
  enable_metrics: true
  enable_tracing: true
```

### Configuration Validation

```python
# config/validator.py
from pydantic import BaseSettings, validator

class Settings(BaseSettings):
    environment: str
    debug: bool = False
    database_url: str
    redis_url: str
    openai_api_key: str
    
    @validator('environment')
    def validate_environment(cls, v):
        if v not in ['development', 'staging', 'production']:
            raise ValueError('Invalid environment')
        return v
    
    @validator('database_url')
    def validate_database_url(cls, v):
        if not v.startswith(('postgresql://', 'mysql://')):
            raise ValueError('Invalid database URL')
        return v

# Load and validate configuration
settings = Settings()
```

## Monitoring Setup

### Prometheus Configuration (monitoring/prometheus.yml)

```yaml
global:
  scrape_interval: 15s
  evaluation_interval: 15s

rule_files:
  - "alert_rules.yml"

scrape_configs:
  - job_name: 'agentic-architecture'
    static_configs:
      - targets: ['localhost:8000']
    metrics_path: /metrics
    scrape_interval: 10s

  - job_name: 'postgres'
    static_configs:
      - targets: ['postgres-exporter:9187']

  - job_name: 'redis'
    static_configs:
      - targets: ['redis-exporter:9121']

alerting:
  alertmanagers:
    - static_configs:
        - targets: ['alertmanager:9093']
```

### Grafana Dashboard

```json
{
  "dashboard": {
    "title": "Agentic Architecture System",
    "panels": [
      {
        "title": "Request Rate",
        "type": "graph",
        "targets": [
          {
            "expr": "rate(http_requests_total[5m])",
            "legendFormat": "{{ method }} {{ endpoint }}"
          }
        ]
      },
      {
        "title": "Response Time",
        "type": "graph",
        "targets": [
          {
            "expr": "histogram_quantile(0.95, rate(http_request_duration_seconds_bucket[5m]))",
            "legendFormat": "95th percentile"
          }
        ]
      },
      {
        "title": "Error Rate",
        "type": "graph",
        "targets": [
          {
            "expr": "rate(http_requests_total{status=~\"5..\"}[5m])",
            "legendFormat": "5xx errors"
          }
        ]
      }
    ]
  }
}
```

### Alert Rules (monitoring/alert_rules.yml)

```yaml
groups:
  - name: agentic_architecture_alerts
    rules:
      - alert: HighErrorRate
        expr: rate(http_requests_total{status=~"5.."}[5m]) > 0.05
        for: 5m
        labels:
          severity: critical
        annotations:
          summary: "High error rate detected"
          description: "Error rate is {{ $value }} errors per second"

      - alert: HighResponseTime
        expr: histogram_quantile(0.95, rate(http_request_duration_seconds_bucket[5m])) > 2
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "High response time detected"
          description: "95th percentile response time is {{ $value }} seconds"

      - alert: ServiceDown
        expr: up == 0
        for: 2m
        labels:
          severity: critical
        annotations:
          summary: "Service is down"
          description: "{{ $labels.instance }} has been down for more than 2 minutes"
```

## Security Configuration

### TLS/SSL Setup

```bash
# Generate SSL certificate
openssl req -x509 -newkey rsa:4096 -keyout key.pem -out cert.pem -days 365 -nodes

# Or use Let's Encrypt with Certbot
certbot certonly --nginx -d api.yourdomain.com

# Configure Nginx
server {
    listen 443 ssl http2;
    server_name api.yourdomain.com;
    
    ssl_certificate /etc/letsencrypt/live/api.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.yourdomain.com/privkey.pem;
    
    location / {
        proxy_pass http://localhost:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### Firewall Configuration

```bash
# UFW (Ubuntu)
sudo ufw allow 22/tcp      # SSH
sudo ufw allow 80/tcp      # HTTP
sudo ufw allow 443/tcp     # HTTPS
sudo ufw allow 8000/tcp    # Application (internal only)
sudo ufw deny 5432/tcp     # PostgreSQL (database access only)
sudo ufw enable

# iptables
iptables -A INPUT -p tcp --dport 22 -j ACCEPT
iptables -A INPUT -p tcp --dport 80 -j ACCEPT
iptables -A INPUT -p tcp --dport 443 -j ACCEPT
iptables -A INPUT -p tcp --dport 8000 -s 10.0.0.0/8 -j ACCEPT
iptables -A INPUT -j DROP
```

### Security Headers

```python
# security/middleware.py
from starlette.middleware.base import BaseHTTPMiddleware

class SecurityHeadersMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request, call_next):
        response = await call_next(request)
        
        # Security headers
        response.headers["X-Content-Type-Options"] = "nosniff"
        response.headers["X-Frame-Options"] = "DENY"
        response.headers["X-XSS-Protection"] = "1; mode=block"
        response.headers["Strict-Transport-Security"] = "max-age=31536000; includeSubDomains"
        response.headers["Content-Security-Policy"] = "default-src 'self'"
        
        return response
```

## Backup and Recovery

### Database Backup Script

```bash
#!/bin/bash
# scripts/backup_database.sh

set -e

BACKUP_DIR="/opt/backups/agentic-architecture"
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="$BACKUP_DIR/database_backup_$DATE.sql.gz"

# Create backup directory
mkdir -p $BACKUP_DIR

# Create database backup
pg_dump $DATABASE_URL | gzip > $BACKUP_FILE

# Upload to S3 (optional)
aws s3 cp $BACKUP_FILE s3://your-backup-bucket/database/

# Keep only last 30 days of backups
find $BACKUP_DIR -name "database_backup_*.sql.gz" -mtime +30 -delete

echo "Database backup completed: $BACKUP_FILE"
```

### File System Backup

```bash
#!/bin/bash
# scripts/backup_files.sh

set -e

BACKUP_DIR="/opt/backups/agentic-architecture"
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="$BACKUP_DIR/files_backup_$DATE.tar.gz"

# Backup application files and configuration
tar -czf $BACKUP_FILE \
  --exclude='*.log' \
  --exclude='__pycache__' \
  --exclude='.git' \
  /opt/agentic-architecture/

# Upload to S3
aws s3 cp $BACKUP_FILE s3://your-backup-bucket/files/

echo "File backup completed: $BACKUP_FILE"
```

### Recovery Procedures

```bash
#!/bin/bash
# scripts/restore_database.sh

set -e

BACKUP_FILE=$1

if [ -z "$BACKUP_FILE" ]; then
    echo "Usage: $0 <backup_file>"
    exit 1
fi

# Stop application
docker-compose stop api

# Restore database
gunzip -c $BACKUP_FILE | psql $DATABASE_URL

# Start application
docker-compose start api

echo "Database restore completed from: $BACKUP_FILE"
```

## Troubleshooting

### Common Issues

#### 1. High Memory Usage

```bash
# Check memory usage
kubectl top pods -n agentic-architecture

# Solution: Increase memory limits or optimize configuration
kubectl patch deployment agentic-architecture -p '{"spec":{"template":{"spec":{"containers":[{"name":"agentic-architecture","resources":{"limits":{"memory":"8Gi"}}}]}}}}'
```

#### 2. Database Connection Issues

```bash
# Check database connectivity
kubectl exec -it deployment/agentic-architecture -- psql $DATABASE_URL -c "SELECT 1"

# Check connection pool
kubectl logs deployment/agentic-architecture | grep "connection pool"

# Solution: Adjust connection pool settings
# In production.env:
DATABASE_POOL_SIZE=30
DATABASE_MAX_OVERFLOW=50
```

#### 3. Slow API Response Times

```bash
# Check response times
curl -w "@curl-format.txt" -o /dev/null -s "http://localhost:8000/api/query"

# Check bottlenecks
kubectl logs deployment/agentic-architecture | grep "slow"

# Enable profiling
kubectl set env deployment/agentic-architecture ENABLE_PROFILING=true
```

#### 4. Failed Knowledge Extraction

```bash
# Check extraction logs
kubectl logs deployment/agentic-architecture | grep "extraction"

# Verify source accessibility
kubectl exec -it deployment/agentic-architecture -- curl -I http://source-system/api

# Check API keys
kubectl get secret agentic-secrets -o yaml | base64 -d
```

### Log Analysis

```bash
# View recent logs
kubectl logs --tail=100 deployment/agentic-architecture

# Follow logs in real-time
kubectl logs -f deployment/agentic-architecture

# Search for errors
kubectl logs deployment/agentic-architecture | grep -i error

# Export logs for analysis
kubectl logs deployment/agentic-architecture > agentic-logs.txt
```

### Performance Tuning

#### Application Tuning

```bash
# Increase worker processes
export WORKER_PROCESSES=8
export WORKER_CONNECTIONS=2000

# Optimize database queries
export DATABASE_QUERY_TIMEOUT=30
export DATABASE_STATEMENT_TIMEOUT=60

# Tune memory settings
export MAX_MEMORY_USAGE=4GB
export CACHE_SIZE=1GB
```

#### Database Tuning

```sql
-- PostgreSQL optimization
ALTER SYSTEM SET shared_buffers = '256MB';
ALTER SYSTEM SET effective_cache_size = '1GB';
ALTER SYSTEM SET maintenance_work_mem = '64MB';
ALTER SYSTEM SET checkpoint_completion_target = 0.7;
ALTER SYSTEM SET wal_buffers = '16MB';
ALTER SYSTEM SET default_statistics_target = 100;

SELECT pg_reload_conf();
```

### Health Check Script

```bash
#!/bin/bash
# scripts/health_check.sh

set -e

ENDPOINT="http://localhost:8000"

# Check API health
if ! curl -f "$ENDPOINT/health" > /dev/null 2>&1; then
    echo "ERROR: API health check failed"
    exit 1
fi

# Check database connectivity
if ! curl -f "$ENDPOINT/health/db" > /dev/null 2>&1; then
    echo "ERROR: Database health check failed"
    exit 1
fi

# Check memory usage
MEMORY_USAGE=$(kubectl top pod -l app=agentic-architecture --no-headers | awk '{sum+=$3} END {print sum}')
if [ "$MEMORY_USAGE" -gt 80 ]; then
    echo "WARNING: High memory usage: ${MEMORY_USAGE}%"
fi

# Check response time
RESPONSE_TIME=$(curl -w "%{time_total}" -o /dev/null -s "$ENDPOINT/health")
if (( $(echo "$RESPONSE_TIME > 2.0" | bc -l) )); then
    echo "WARNING: Slow response time: ${RESPONSE_TIME}s"
fi

echo "Health check completed successfully"
```

This deployment guide provides comprehensive instructions for deploying the Agentic Architecture System across different environments and platforms. Follow the appropriate section based on your deployment needs and environment requirements.
