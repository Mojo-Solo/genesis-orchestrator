#!/bin/bash

# GENESIS Orchestrator - Production Monitoring Startup Script
# ===========================================================
# Complete monitoring stack startup with health checks, dependencies, and rollback

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
MONITORING_DIR="${PROJECT_ROOT}/monitoring"
LOG_FILE="${MONITORING_DIR}/logs/startup.log"

# Configuration
STARTUP_TIMEOUT=300  # 5 minutes
HEALTH_CHECK_INTERVAL=10
MAX_HEALTH_CHECK_ATTEMPTS=18  # 3 minutes total
ROLLBACK_ON_FAILURE=true
ENABLE_ANOMALY_DETECTION=true
ENABLE_AUTO_SCALING=true

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
NC='\033[0m' # No Color

# Logging function with multiple outputs
log() {
    local level=$1
    shift
    local message="$*"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    local log_entry="$timestamp [$level] $message"
    
    # Console output with colors
    case $level in
        "INFO")  echo -e "${GREEN}[INFO]${NC}  ${timestamp} - $message" ;;
        "WARN")  echo -e "${YELLOW}[WARN]${NC}  ${timestamp} - $message" ;;
        "ERROR") echo -e "${RED}[ERROR]${NC} ${timestamp} - $message" ;;
        "DEBUG") echo -e "${BLUE}[DEBUG]${NC} ${timestamp} - $message" ;;
        "SUCCESS") echo -e "${GREEN}[SUCCESS]${NC} ${timestamp} - $message" ;;
        "STEP") echo -e "${PURPLE}[STEP]${NC} ${timestamp} - $message" ;;
    esac
    
    # File logging
    echo "$log_entry" >> "$LOG_FILE"
}

# Progress indicator
show_progress() {
    local current=$1
    local total=$2
    local step_name=$3
    local bar_length=50
    local progress=$((current * bar_length / total))
    local percentage=$((current * 100 / total))
    
    printf "\r${BLUE}Progress:${NC} ["
    for ((i=0; i<bar_length; i++)); do
        if [ $i -lt $progress ]; then
            printf "="
        else
            printf " "
        fi
    done
    printf "] %d%% - %s" "$percentage" "$step_name"
    
    if [ $current -eq $total ]; then
        printf "\n"
    fi
}

# Cleanup function for graceful shutdown
cleanup() {
    local exit_code=$?
    log "INFO" "Cleanup initiated (exit code: $exit_code)"
    
    if [ $exit_code -ne 0 ] && [ "$ROLLBACK_ON_FAILURE" = true ]; then
        log "WARN" "Startup failed, initiating rollback..."
        rollback_monitoring_stack
    fi
    
    exit $exit_code
}

# Set trap for cleanup
trap cleanup EXIT INT TERM

# Check prerequisites
check_prerequisites() {
    log "STEP" "Checking prerequisites..."
    
    local required_commands=("docker" "docker-compose" "curl" "jq" "python3" "kubectl")
    local missing_commands=()
    
    for cmd in "${required_commands[@]}"; do
        if ! command -v "$cmd" &> /dev/null; then
            if [ "$cmd" != "kubectl" ]; then  # kubectl is optional
                missing_commands+=("$cmd")
            else
                log "WARN" "kubectl not found - Kubernetes features will be disabled"
            fi
        fi
    done
    
    if [ ${#missing_commands[@]} -gt 0 ]; then
        log "ERROR" "Missing required commands: ${missing_commands[*]}"
        return 1
    fi
    
    # Check Docker daemon
    if ! docker info &> /dev/null; then
        log "ERROR" "Docker daemon is not running"
        return 1
    fi
    
    # Check available disk space (need at least 5GB)
    local available_space=$(df "$PROJECT_ROOT" | awk 'NR==2 {print $4}')
    if [[ $available_space -lt 5242880 ]]; then  # 5GB in KB
        log "ERROR" "Insufficient disk space. Need at least 5GB available"
        return 1
    fi
    
    # Check available memory (need at least 4GB)
    local available_memory=$(free -m | awk 'NR==2{print $7}')
    if [[ $available_memory -lt 4096 ]]; then
        log "WARN" "Low available memory ($available_memory MB). Monitoring stack may be slow"
    fi
    
    log "SUCCESS" "Prerequisites check passed"
    return 0
}

# Initialize directories and permissions
initialize_directories() {
    log "STEP" "Initializing directories and permissions..."
    
    local directories=(
        "${MONITORING_DIR}/data/prometheus"
        "${MONITORING_DIR}/data/grafana"
        "${MONITORING_DIR}/data/alertmanager"
        "${MONITORING_DIR}/logs"
        "${MONITORING_DIR}/config/prometheus"
        "${MONITORING_DIR}/config/grafana/provisioning/datasources"
        "${MONITORING_DIR}/config/grafana/provisioning/dashboards"
        "${MONITORING_DIR}/config/alertmanager"
        "${MONITORING_DIR}/scripts"
        "${MONITORING_DIR}/backups"
        "${PROJECT_ROOT}/orchestrator_runs"
    )
    
    for dir in "${directories[@]}"; do
        mkdir -p "$dir"
        log "DEBUG" "Created directory: $dir"
    done
    
    # Set proper permissions for monitoring data directories
    chmod 755 "${MONITORING_DIR}/data"/*
    chmod 755 "${MONITORING_DIR}/logs"
    
    # Create log file
    mkdir -p "$(dirname "$LOG_FILE")"
    touch "$LOG_FILE"
    
    log "SUCCESS" "Directory initialization completed"
}

# Validate configuration files
validate_configuration() {
    log "STEP" "Validating configuration files..."
    
    local config_files=(
        "${MONITORING_DIR}/prometheus/prometheus.yml"
        "${MONITORING_DIR}/alertmanager/alertmanager.yml"
        "${MONITORING_DIR}/docker-compose.yml"
    )
    
    local errors=0
    
    for config_file in "${config_files[@]}"; do
        if [ ! -f "$config_file" ]; then
            log "ERROR" "Missing configuration file: $config_file"
            ((errors++))
        else
            log "DEBUG" "Found configuration file: $config_file"
            
            # Validate YAML syntax
            if [[ "$config_file" == *.yml ]] || [[ "$config_file" == *.yaml ]]; then
                if ! python3 -c "import yaml; yaml.safe_load(open('$config_file'))" 2>/dev/null; then
                    log "ERROR" "Invalid YAML syntax in: $config_file"
                    ((errors++))
                fi
            fi
        fi
    done
    
    # Check required environment variables
    local required_env_vars=("GRAFANA_ADMIN_PASSWORD")
    local optional_env_vars=("SLACK_WEBHOOK_URL" "PAGERDUTY_INTEGRATION_KEY")
    
    for var in "${required_env_vars[@]}"; do
        if [ -z "${!var:-}" ]; then
            log "ERROR" "Required environment variable not set: $var"
            ((errors++))
        fi
    done
    
    for var in "${optional_env_vars[@]}"; do
        if [ -z "${!var:-}" ]; then
            log "WARN" "Optional environment variable not set: $var (some features may be limited)"
        fi
    done
    
    if [ $errors -gt 0 ]; then
        log "ERROR" "Configuration validation failed with $errors errors"
        return 1
    fi
    
    log "SUCCESS" "Configuration validation passed"
    return 0
}

# Start monitoring stack
start_monitoring_stack() {
    log "STEP" "Starting monitoring stack..."
    
    cd "$MONITORING_DIR"
    
    # Pull latest images first
    log "INFO" "Pulling latest monitoring images..."
    if ! docker-compose pull; then
        log "ERROR" "Failed to pull monitoring images"
        return 1
    fi
    
    # Start services in dependency order
    local services=("prometheus" "alertmanager" "grafana" "node-exporter" "mysql-exporter" "redis-exporter")
    local started_services=()
    
    for i in "${!services[@]}"; do
        local service="${services[$i]}"
        show_progress $((i + 1)) ${#services[@]} "Starting $service"
        
        log "INFO" "Starting service: $service"
        if ! docker-compose up -d "$service"; then
            log "ERROR" "Failed to start service: $service"
            return 1
        fi
        
        started_services+=("$service")
        sleep 2  # Brief pause between service starts
    done
    
    log "SUCCESS" "All monitoring services started"
    return 0
}

# Health check for individual service
check_service_health() {
    local service_name=$1
    local health_url=$2
    local timeout=${3:-5}
    local max_attempts=${4:-$MAX_HEALTH_CHECK_ATTEMPTS}
    
    for ((attempt=1; attempt<=max_attempts; attempt++)); do
        if curl -sf --max-time "$timeout" "$health_url" > /dev/null 2>&1; then
            log "SUCCESS" "$service_name is healthy (attempt $attempt/$max_attempts)"
            return 0
        else
            if [ $attempt -lt $max_attempts ]; then
                log "DEBUG" "$service_name health check failed (attempt $attempt/$max_attempts), retrying..."
                sleep $HEALTH_CHECK_INTERVAL
            fi
        fi
    done
    
    log "ERROR" "$service_name health check failed after $max_attempts attempts"
    return 1
}

# Comprehensive health checks
run_health_checks() {
    log "STEP" "Running comprehensive health checks..."
    
    # Define health check endpoints
    local health_checks=(
        "Prometheus:http://localhost:9090/-/healthy"
        "AlertManager:http://localhost:9093/-/healthy" 
        "Grafana:http://localhost:3000/api/health"
        "Node Exporter:http://localhost:9100/metrics"
        "MySQL Exporter:http://localhost:9104/metrics"
        "Redis Exporter:http://localhost:9121/metrics"
    )
    
    local failed_checks=()
    local total_checks=${#health_checks[@]}
    
    for i in "${!health_checks[@]}"; do
        local check="${health_checks[$i]}"
        local service_name="${check%%:*}"
        local health_url="${check#*:}"
        
        show_progress $((i + 1)) $total_checks "Checking $service_name health"
        
        if check_service_health "$service_name" "$health_url"; then
            log "DEBUG" "$service_name health check passed"
        else
            failed_checks+=("$service_name")
        fi
    done
    
    if [ ${#failed_checks[@]} -gt 0 ]; then
        log "ERROR" "Health checks failed for: ${failed_checks[*]}"
        return 1
    fi
    
    log "SUCCESS" "All health checks passed"
    return 0
}

# Start additional monitoring services
start_additional_services() {
    log "STEP" "Starting additional monitoring services..."
    
    # Start anomaly detection if enabled
    if [ "$ENABLE_ANOMALY_DETECTION" = true ]; then
        log "INFO" "Starting anomaly detection service..."
        if start_anomaly_detection_service; then
            log "SUCCESS" "Anomaly detection service started"
        else
            log "WARN" "Failed to start anomaly detection service (non-critical)"
        fi
    fi
    
    # Start auto-scaling if enabled
    if [ "$ENABLE_AUTO_SCALING" = true ]; then
        log "INFO" "Starting auto-scaling service..."
        if start_auto_scaling_service; then
            log "SUCCESS" "Auto-scaling service started"
        else
            log "WARN" "Failed to start auto-scaling service (non-critical)"
        fi
    fi
    
    log "SUCCESS" "Additional services initialization completed"
}

start_anomaly_detection_service() {
    local script_path="${SCRIPT_DIR}/anomaly_detection.py"
    local pid_file="${MONITORING_DIR}/logs/anomaly_detection.pid"
    local log_file="${MONITORING_DIR}/logs/anomaly_detection.log"
    
    if [ ! -f "$script_path" ]; then
        log "WARN" "Anomaly detection script not found: $script_path"
        return 1
    fi
    
    # Start anomaly detection as background service
    nohup python3 "$script_path" continuous 60 > "$log_file" 2>&1 &
    local pid=$!
    echo $pid > "$pid_file"
    
    # Wait a moment and check if process is still running
    sleep 2
    if kill -0 $pid 2>/dev/null; then
        log "INFO" "Anomaly detection service started with PID: $pid"
        return 0
    else
        log "ERROR" "Anomaly detection service failed to start"
        return 1
    fi
}

start_auto_scaling_service() {
    local script_path="${SCRIPT_DIR}/auto_scaling.py"
    local pid_file="${MONITORING_DIR}/logs/auto_scaling.pid"
    local log_file="${MONITORING_DIR}/logs/auto_scaling.log"
    
    if [ ! -f "$script_path" ]; then
        log "WARN" "Auto-scaling script not found: $script_path"
        return 1
    fi
    
    # Start auto-scaling as background service
    nohup python3 "$script_path" continuous 120 > "$log_file" 2>&1 &
    local pid=$!
    echo $pid > "$pid_file"
    
    # Wait a moment and check if process is still running
    sleep 2
    if kill -0 $pid 2>/dev/null; then
        log "INFO" "Auto-scaling service started with PID: $pid"
        return 0
    else
        log "ERROR" "Auto-scaling service failed to start"
        return 1
    fi
}

# Configure initial dashboards and alerts
configure_monitoring() {
    log "STEP" "Configuring monitoring dashboards and alerts..."
    
    # Wait for Grafana to be fully ready
    log "INFO" "Waiting for Grafana to be fully ready..."
    local grafana_ready=false
    for ((attempt=1; attempt<=30; attempt++)); do
        if curl -sf "http://admin:${GRAFANA_ADMIN_PASSWORD}@localhost:3000/api/org" > /dev/null 2>&1; then
            grafana_ready=true
            break
        fi
        sleep 2
    done
    
    if [ "$grafana_ready" = false ]; then
        log "ERROR" "Grafana is not ready for configuration"
        return 1
    fi
    
    # Import dashboards
    local dashboard_dir="${MONITORING_DIR}/grafana/dashboards"
    if [ -d "$dashboard_dir" ]; then
        for dashboard_file in "$dashboard_dir"/*.json; do
            if [ -f "$dashboard_file" ]; then
                local dashboard_name=$(basename "$dashboard_file" .json)
                log "INFO" "Importing dashboard: $dashboard_name"
                
                # Import dashboard via Grafana API
                curl -sf -X POST \
                    -H "Content-Type: application/json" \
                    -u "admin:${GRAFANA_ADMIN_PASSWORD}" \
                    -d @"$dashboard_file" \
                    "http://localhost:3000/api/dashboards/db" > /dev/null
                
                if [ $? -eq 0 ]; then
                    log "SUCCESS" "Dashboard imported: $dashboard_name"
                else
                    log "WARN" "Failed to import dashboard: $dashboard_name"
                fi
            fi
        done
    fi
    
    log "SUCCESS" "Monitoring configuration completed"
    return 0
}

# Rollback monitoring stack
rollback_monitoring_stack() {
    log "WARN" "Rolling back monitoring stack..."
    
    cd "$MONITORING_DIR" 2>/dev/null || return 1
    
    # Stop all services
    docker-compose down
    
    # Stop additional services
    local pid_files=(
        "${MONITORING_DIR}/logs/anomaly_detection.pid"
        "${MONITORING_DIR}/logs/auto_scaling.pid"
    )
    
    for pid_file in "${pid_files[@]}"; do
        if [ -f "$pid_file" ]; then
            local pid=$(cat "$pid_file")
            if kill -0 "$pid" 2>/dev/null; then
                kill "$pid"
                log "INFO" "Stopped service with PID: $pid"
            fi
            rm -f "$pid_file"
        fi
    done
    
    log "SUCCESS" "Monitoring stack rollback completed"
}

# Generate startup summary
generate_startup_summary() {
    log "STEP" "Generating startup summary..."
    
    cat << EOF

${GREEN}================================================================${NC}
${GREEN}         GENESIS Orchestrator Monitoring Stack Started         ${NC}
${GREEN}================================================================${NC}

${BLUE}Service Status:${NC}
  ✅ Prometheus:    http://localhost:9090
  ✅ Grafana:       http://localhost:3000 (admin/${GRAFANA_ADMIN_PASSWORD})
  ✅ AlertManager:  http://localhost:9093
  ✅ Node Exporter: http://localhost:9100/metrics
  
${BLUE}Additional Services:${NC}
  $([ "$ENABLE_ANOMALY_DETECTION" = true ] && echo "✅ Anomaly Detection: Running" || echo "❌ Anomaly Detection: Disabled")
  $([ "$ENABLE_AUTO_SCALING" = true ] && echo "✅ Auto-scaling: Running" || echo "❌ Auto-scaling: Disabled")

${BLUE}Management Commands:${NC}
  Check Status:     ${SCRIPT_DIR}/check_monitoring_health.sh
  Stop All:         cd ${MONITORING_DIR} && docker-compose down
  View Logs:        cd ${MONITORING_DIR} && docker-compose logs -f
  Backup Config:    ${SCRIPT_DIR}/backup_monitoring.sh

${BLUE}Log Files:${NC}
  Startup Log:      ${LOG_FILE}
  Anomaly Log:      ${MONITORING_DIR}/logs/anomaly_detection.log
  Auto-scale Log:   ${MONITORING_DIR}/logs/auto_scaling.log

${GREEN}Monitoring stack is now ready for production use!${NC}
${GREEN}================================================================${NC}

EOF
}

# Main execution function
main() {
    local start_time=$(date +%s)
    
    log "INFO" "Starting GENESIS Orchestrator production monitoring stack..."
    log "INFO" "Script: $(basename "$0"), PID: $$"
    log "INFO" "Project root: $PROJECT_ROOT"
    log "INFO" "Monitoring directory: $MONITORING_DIR"
    
    # Execute startup sequence
    local steps=(
        "check_prerequisites"
        "initialize_directories"
        "validate_configuration"
        "start_monitoring_stack"
        "run_health_checks"
        "start_additional_services"
        "configure_monitoring"
    )
    
    local total_steps=${#steps[@]}
    
    for i in "${!steps[@]}"; do
        local step_func="${steps[$i]}"
        local step_num=$((i + 1))
        
        log "INFO" "Step $step_num/$total_steps: ${step_func//_/ }"
        
        if ! $step_func; then
            log "ERROR" "Step $step_num/$total_steps failed: $step_func"
            return 1
        fi
    done
    
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    log "SUCCESS" "Monitoring stack startup completed in ${duration}s"
    generate_startup_summary
    
    return 0
}

# Script entry point
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi