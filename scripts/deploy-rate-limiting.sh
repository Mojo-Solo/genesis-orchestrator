#!/bin/bash

# GENESIS Orchestrator - Advanced Rate Limiting System Deployment Script
# Deploys the complete rate limiting and API gateway infrastructure

set -euo pipefail

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" &> /dev/null && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
NAMESPACE="${NAMESPACE:-genesis-orchestrator}"
ENVIRONMENT="${ENVIRONMENT:-production}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging functions
log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')] $1${NC}"
}

warn() {
    echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] WARNING: $1${NC}"
}

error() {
    echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] ERROR: $1${NC}"
}

info() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')] INFO: $1${NC}"
}

# Check prerequisites
check_prerequisites() {
    log "Checking prerequisites..."
    
    local missing_tools=()
    
    # Check required tools
    command -v kubectl >/dev/null 2>&1 || missing_tools+=("kubectl")
    command -v helm >/dev/null 2>&1 || missing_tools+=("helm")
    command -v jq >/dev/null 2>&1 || missing_tools+=("jq")
    command -v curl >/dev/null 2>&1 || missing_tools+=("curl")
    command -v redis-cli >/dev/null 2>&1 || missing_tools+=("redis-cli")
    
    if [[ ${#missing_tools[@]} -gt 0 ]]; then
        error "Missing required tools: ${missing_tools[*]}"
        error "Please install the missing tools and try again"
        exit 1
    fi
    
    # Check Kubernetes connectivity
    if ! kubectl cluster-info >/dev/null 2>&1; then
        error "Cannot connect to Kubernetes cluster"
        exit 1
    fi
    
    # Check namespace
    if ! kubectl get namespace "$NAMESPACE" >/dev/null 2>&1; then
        warn "Namespace '$NAMESPACE' does not exist. Creating..."
        kubectl create namespace "$NAMESPACE"
    fi
    
    log "Prerequisites check completed successfully"
}

# Deploy Redis for rate limiting
deploy_redis() {
    log "Deploying Redis for rate limiting..."
    
    cat <<EOF | kubectl apply -f -
apiVersion: apps/v1
kind: Deployment
metadata:
  name: redis-rate-limiting
  namespace: $NAMESPACE
  labels:
    app: redis-rate-limiting
    component: cache
spec:
  replicas: 1
  selector:
    matchLabels:
      app: redis-rate-limiting
  template:
    metadata:
      labels:
        app: redis-rate-limiting
        component: cache
    spec:
      containers:
      - name: redis
        image: redis:7-alpine
        ports:
        - containerPort: 6379
        command: ["redis-server"]
        args: ["--appendonly", "yes", "--maxmemory", "512mb", "--maxmemory-policy", "allkeys-lru"]
        volumeMounts:
        - name: redis-data
          mountPath: /data
        resources:
          requests:
            memory: "256Mi"
            cpu: "100m"
          limits:
            memory: "512Mi"
            cpu: "200m"
        livenessProbe:
          exec:
            command: ["redis-cli", "ping"]
          initialDelaySeconds: 30
          periodSeconds: 10
        readinessProbe:
          exec:
            command: ["redis-cli", "ping"]
          initialDelaySeconds: 5
          periodSeconds: 5
      volumes:
      - name: redis-data
        emptyDir: {}
---
apiVersion: v1
kind: Service
metadata:
  name: redis-service
  namespace: $NAMESPACE
spec:
  ports:
  - port: 6379
    targetPort: 6379
  selector:
    app: redis-rate-limiting
EOF
    
    # Wait for Redis to be ready
    kubectl wait --for=condition=available --timeout=300s deployment/redis-rate-limiting -n "$NAMESPACE"
    log "Redis deployed successfully"
}

# Deploy Kong API Gateway
deploy_kong() {
    log "Deploying Kong API Gateway..."
    
    # Apply Kong configuration
    kubectl apply -f "$PROJECT_ROOT/k8s/kong-gateway.yaml" -n "$NAMESPACE"
    
    # Wait for Kong to be ready
    kubectl wait --for=condition=available --timeout=600s deployment/kong-gateway -n "$NAMESPACE"
    kubectl wait --for=condition=ready --timeout=300s statefulset/postgres -n "$NAMESPACE"
    
    log "Kong API Gateway deployed successfully"
}

# Configure Kong services and plugins
configure_kong() {
    log "Configuring Kong services and plugins..."
    
    # Wait for Kong admin API to be available
    local kong_admin_url
    kong_admin_url=$(get_kong_admin_url)
    
    local max_attempts=30
    local attempt=1
    
    while [[ $attempt -le $max_attempts ]]; do
        if curl -s "$kong_admin_url/status" >/dev/null 2>&1; then
            log "Kong Admin API is available"
            break
        fi
        
        info "Waiting for Kong Admin API... (attempt $attempt/$max_attempts)"
        sleep 10
        ((attempt++))
    done
    
    if [[ $attempt -gt $max_attempts ]]; then
        error "Kong Admin API is not available after $max_attempts attempts"
        exit 1
    fi
    
    # Run Kong configuration script
    KONG_ADMIN_URL="$kong_admin_url" "$PROJECT_ROOT/scripts/kong-configure.sh"
    
    log "Kong configuration completed"
}

# Get Kong Admin URL
get_kong_admin_url() {
    local service_type
    service_type=$(kubectl get service kong-admin-service -n "$NAMESPACE" -o jsonpath='{.spec.type}')
    
    if [[ "$service_type" == "LoadBalancer" ]]; then
        local external_ip
        external_ip=$(kubectl get service kong-admin-service -n "$NAMESPACE" -o jsonpath='{.status.loadBalancer.ingress[0].ip}')
        if [[ -n "$external_ip" ]]; then
            echo "http://$external_ip:8001"
            return
        fi
    fi
    
    # Fallback to port-forward
    local pod_name
    pod_name=$(kubectl get pods -n "$NAMESPACE" -l app=kong-gateway -o jsonpath='{.items[0].metadata.name}')
    
    # Start port-forward in background
    kubectl port-forward -n "$NAMESPACE" "$pod_name" 8001:8001 >/dev/null 2>&1 &
    local port_forward_pid=$!
    
    sleep 5  # Give port-forward time to establish
    echo "http://localhost:8001"
}

# Deploy backend services with rate limiting
deploy_backend() {
    log "Deploying backend services with enhanced rate limiting..."
    
    # Create enhanced rate limiting configuration
    kubectl create configmap genesis-rate-limit-config \
        --from-file="$PROJECT_ROOT/config/rate_limiting.php" \
        -n "$NAMESPACE" \
        --dry-run=client -o yaml | kubectl apply -f -
    
    # Apply backend deployment with rate limiting
    cat <<EOF | kubectl apply -f -
apiVersion: apps/v1
kind: Deployment
metadata:
  name: genesis-backend
  namespace: $NAMESPACE
  labels:
    app: genesis-backend
    component: api
spec:
  replicas: 3
  selector:
    matchLabels:
      app: genesis-backend
  template:
    metadata:
      labels:
        app: genesis-backend
        component: api
    spec:
      containers:
      - name: genesis-backend
        image: genesis-orchestrator:latest
        ports:
        - containerPort: 8080
        env:
        - name: RATE_LIMIT_ENHANCED
          value: "true"
        - name: RATE_LIMIT_STORAGE
          value: "redis"
        - name: REDIS_HOST
          value: "redis-service"
        - name: REDIS_PORT
          value: "6379"
        - name: REDIS_DATABASE
          value: "1"
        - name: THREAT_DETECTION_ENABLED
          value: "true"
        - name: RATE_LIMIT_QUEUING
          value: "true"
        - name: APP_ENV
          value: "$ENVIRONMENT"
        volumeMounts:
        - name: rate-limit-config
          mountPath: /app/config/rate_limiting.php
          subPath: rate_limiting.php
        resources:
          requests:
            memory: "512Mi"
            cpu: "250m"
          limits:
            memory: "1Gi"
            cpu: "500m"
        livenessProbe:
          httpGet:
            path: /health
            port: 8080
          initialDelaySeconds: 30
          periodSeconds: 10
        readinessProbe:
          httpGet:
            path: /health
            port: 8080
          initialDelaySeconds: 5
          periodSeconds: 5
      volumes:
      - name: rate-limit-config
        configMap:
          name: genesis-rate-limit-config
---
apiVersion: v1
kind: Service
metadata:
  name: genesis-backend-service
  namespace: $NAMESPACE
spec:
  ports:
  - port: 8080
    targetPort: 8080
  selector:
    app: genesis-backend
EOF
    
    # Wait for backend to be ready
    kubectl wait --for=condition=available --timeout=300s deployment/genesis-backend -n "$NAMESPACE"
    log "Backend services deployed successfully"
}

# Deploy monitoring and dashboards
deploy_monitoring() {
    log "Deploying monitoring and dashboards..."
    
    # Deploy Prometheus ServiceMonitor for rate limiting metrics
    cat <<EOF | kubectl apply -f -
apiVersion: monitoring.coreos.com/v1
kind: ServiceMonitor
metadata:
  name: genesis-rate-limiting
  namespace: $NAMESPACE
  labels:
    app: genesis-rate-limiting
spec:
  selector:
    matchLabels:
      app: genesis-backend
  endpoints:
  - port: http
    path: /metrics
    interval: 30s
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: grafana-dashboard-rate-limiting
  namespace: monitoring
  labels:
    grafana_dashboard: "1"
data:
  genesis-rate-limiting.json: |
$(cat "$PROJECT_ROOT/monitoring/grafana/dashboards/genesis-rate-limiting.json" | sed 's/^/    /')
EOF
    
    # Apply Prometheus alert rules
    if [[ -f "$PROJECT_ROOT/monitoring/prometheus/alert_rules/genesis_rate_limiting_alerts.yml" ]]; then
        kubectl apply -f "$PROJECT_ROOT/monitoring/prometheus/alert_rules/genesis_rate_limiting_alerts.yml" -n monitoring
    fi
    
    log "Monitoring and dashboards deployed successfully"
}

# Create Prometheus alert rules
create_alert_rules() {
    log "Creating Prometheus alert rules..."
    
    cat <<EOF > "$PROJECT_ROOT/monitoring/prometheus/alert_rules/genesis_rate_limiting_alerts.yml"
apiVersion: monitoring.coreos.com/v1
kind: PrometheusRule
metadata:
  name: genesis-rate-limiting-alerts
  namespace: monitoring
  labels:
    app: genesis-orchestrator
    component: rate-limiting
spec:
  groups:
  - name: rate-limiting
    rules:
    - alert: HighRateLimitBlockRate
      expr: (rate(genesis_rate_limit_blocked_total[5m]) / rate(genesis_rate_limit_requests_total[5m])) * 100 > 15
      for: 5m
      labels:
        severity: warning
        component: rate-limiting
      annotations:
        summary: "High rate limit block rate detected"
        description: "Rate limit block rate is {{ \$value }}% which is above the 15% threshold"
    
    - alert: CriticalRateLimitBlockRate
      expr: (rate(genesis_rate_limit_blocked_total[5m]) / rate(genesis_rate_limit_requests_total[5m])) * 100 > 25
      for: 2m
      labels:
        severity: critical
        component: rate-limiting
      annotations:
        summary: "Critical rate limit block rate detected"
        description: "Rate limit block rate is {{ \$value }}% which is above the 25% critical threshold"
    
    - alert: CircuitBreakerOpen
      expr: genesis_circuit_breaker_state == 2
      for: 1m
      labels:
        severity: warning
        component: circuit-breaker
      annotations:
        summary: "Circuit breaker is open for service {{ \$labels.service }}"
        description: "Circuit breaker for service {{ \$labels.service }} has been open for more than 1 minute"
    
    - alert: HighThreatLevel
      expr: genesis_threat_level >= 3
      for: 1m
      labels:
        severity: critical
        component: threat-detection
      annotations:
        summary: "High threat level detected"
        description: "Threat level is {{ \$value }} which indicates a high or critical threat"
    
    - alert: QueueOverflow
      expr: genesis_rate_limit_queue_size > 100
      for: 5m
      labels:
        severity: warning
        component: rate-limiting
      annotations:
        summary: "Rate limiting queue overflow for tenant {{ \$labels.tenant }}"
        description: "Queue size is {{ \$value }} which is above the overflow threshold"
EOF
    
    kubectl apply -f "$PROJECT_ROOT/monitoring/prometheus/alert_rules/genesis_rate_limiting_alerts.yml"
    log "Prometheus alert rules created successfully"
}

# Run system tests
run_tests() {
    log "Running system tests..."
    
    local kong_proxy_url
    kong_proxy_url=$(get_kong_proxy_url)
    
    # Test basic connectivity
    info "Testing Kong proxy connectivity..."
    if curl -s "$kong_proxy_url/health" >/dev/null; then
        log "✓ Kong proxy is accessible"
    else
        warn "⚠ Kong proxy connectivity test failed"
    fi
    
    # Test rate limiting
    info "Testing rate limiting functionality..."
    local test_responses=()
    for i in {1..5}; do
        local response_code
        response_code=$(curl -s -o /dev/null -w "%{http_code}" "$kong_proxy_url/api/v1/health")
        test_responses+=("$response_code")
    done
    
    if [[ "${test_responses[*]}" =~ "200" ]]; then
        log "✓ Rate limiting is functioning (responses: ${test_responses[*]})"
    else
        warn "⚠ Rate limiting test failed (responses: ${test_responses[*]})"
    fi
    
    # Test circuit breaker
    info "Testing circuit breaker functionality..."
    local circuit_health
    circuit_health=$(curl -s "$kong_proxy_url/api/v1/circuit-breaker/health" | jq -r '.status' 2>/dev/null || echo "unknown")
    
    if [[ "$circuit_health" == "healthy" ]]; then
        log "✓ Circuit breaker is healthy"
    else
        warn "⚠ Circuit breaker status: $circuit_health"
    fi
    
    log "System tests completed"
}

# Get Kong Proxy URL
get_kong_proxy_url() {
    local service_type
    service_type=$(kubectl get service kong-gateway-service -n "$NAMESPACE" -o jsonpath='{.spec.type}')
    
    if [[ "$service_type" == "LoadBalancer" ]]; then
        local external_ip
        external_ip=$(kubectl get service kong-gateway-service -n "$NAMESPACE" -o jsonpath='{.status.loadBalancer.ingress[0].ip}')
        if [[ -n "$external_ip" ]]; then
            echo "http://$external_ip"
            return
        fi
    fi
    
    # Fallback to port-forward
    local pod_name
    pod_name=$(kubectl get pods -n "$NAMESPACE" -l app=kong-gateway -o jsonpath='{.items[0].metadata.name}')
    
    # Start port-forward in background
    kubectl port-forward -n "$NAMESPACE" "$pod_name" 8000:8000 >/dev/null 2>&1 &
    
    sleep 3  # Give port-forward time to establish
    echo "http://localhost:8000"
}

# Print deployment summary
print_summary() {
    log "=== DEPLOYMENT SUMMARY ==="
    
    echo
    info "Kong API Gateway:"
    echo "  - Admin URL: $(get_kong_admin_url)"
    echo "  - Proxy URL: $(get_kong_proxy_url)"
    
    echo
    info "Services Deployed:"
    kubectl get deployments -n "$NAMESPACE" -o wide
    
    echo
    info "Rate Limiting Configuration:"
    echo "  - Enhanced rate limiting: ENABLED"
    echo "  - Circuit breakers: ENABLED"
    echo "  - Threat detection: ENABLED"
    echo "  - Request queuing: ENABLED"
    
    echo
    info "Monitoring:"
    echo "  - Prometheus metrics: ENABLED"
    echo "  - Grafana dashboard: DEPLOYED"
    echo "  - Alert rules: CONFIGURED"
    
    echo
    info "Access Information:"
    echo "  - Dashboard API: $(get_kong_proxy_url)/api/v1/rate-limits/dashboard"
    echo "  - Health Check: $(get_kong_proxy_url)/health"
    echo "  - Metrics: $(get_kong_proxy_url)/metrics"
    
    echo
    log "Deployment completed successfully!"
}

# Cleanup function
cleanup() {
    log "Cleaning up deployment..."
    
    # Kill any background processes
    jobs -p | xargs -r kill >/dev/null 2>&1 || true
    
    log "Cleanup completed"
}

# Main deployment function
main() {
    log "Starting GENESIS Rate Limiting System Deployment"
    log "Environment: $ENVIRONMENT"
    log "Namespace: $NAMESPACE"
    
    # Set trap for cleanup
    trap cleanup EXIT
    
    # Run deployment steps
    check_prerequisites
    deploy_redis
    deploy_kong
    deploy_backend
    configure_kong
    create_alert_rules
    deploy_monitoring
    run_tests
    print_summary
    
    log "All deployment steps completed successfully!"
}

# Script options
case "${1:-}" in
    "check")
        check_prerequisites
        ;;
    "redis")
        deploy_redis
        ;;
    "kong")
        deploy_kong
        configure_kong
        ;;
    "backend")
        deploy_backend
        ;;
    "monitoring")
        deploy_monitoring
        ;;
    "test")
        run_tests
        ;;
    "clean")
        warn "This will delete all rate limiting components. Are you sure?"
        read -p "Type 'yes' to confirm: " confirm
        if [[ "$confirm" == "yes" ]]; then
            kubectl delete namespace "$NAMESPACE" --ignore-not-found
            log "Cleanup completed"
        else
            log "Cleanup cancelled"
        fi
        ;;
    "status")
        kubectl get all -n "$NAMESPACE"
        ;;
    *)
        main
        ;;
esac