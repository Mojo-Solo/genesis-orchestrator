#!/bin/bash

# GENESIS Orchestrator - Monitoring Troubleshooting Script
# ========================================================
# Automated troubleshooting and diagnostics for monitoring issues

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
MONITORING_DIR="${PROJECT_ROOT}/monitoring"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
NC='\033[0m' # No Color

# Global variables
ISSUES_FOUND=0
FIXES_APPLIED=0
VERBOSE=false
AUTO_FIX=false

# Logging function
log() {
    local level=$1
    shift
    local message="$*"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    case $level in
        "INFO")  echo -e "${GREEN}[INFO]${NC}  ${timestamp} - $message" ;;
        "WARN")  echo -e "${YELLOW}[WARN]${NC}  ${timestamp} - $message" ;;
        "ERROR") echo -e "${RED}[ERROR]${NC} ${timestamp} - $message"; ((ISSUES_FOUND++)) ;;
        "DEBUG") [[ "$VERBOSE" == "true" ]] && echo -e "${BLUE}[DEBUG]${NC} ${timestamp} - $message" ;;
        "FIX")   echo -e "${PURPLE}[FIX]${NC}   ${timestamp} - $message"; ((FIXES_APPLIED++)) ;;
    esac
}

# Help function
show_help() {
    cat << EOF
GENESIS Monitoring Troubleshooting Script

Usage: $0 [OPTIONS]

OPTIONS:
    -h, --help          Show this help message
    -v, --verbose       Enable verbose logging
    -f, --fix           Automatically apply fixes where possible
    -c, --check ITEM    Check specific component (all,docker,prometheus,grafana,alertmanager,network)
    -o, --output FILE   Save results to file

EXAMPLES:
    $0                           # Run full diagnostic
    $0 --check prometheus        # Check only Prometheus
    $0 --fix --verbose          # Run with auto-fix and verbose logging
    $0 --output troubleshoot.log # Save output to file

COMPONENTS:
    docker       - Docker daemon and containers
    prometheus   - Prometheus metrics collection
    grafana      - Grafana visualization
    alertmanager - AlertManager notifications
    network      - Network connectivity
    permissions  - File and directory permissions
    resources    - System resources
EOF
}

# Parse command line arguments
parse_args() {
    local check_item="all"
    local output_file=""
    
    while [[ $# -gt 0 ]]; do
        case $1 in
            -h|--help)
                show_help
                exit 0
                ;;
            -v|--verbose)
                VERBOSE=true
                shift
                ;;
            -f|--fix)
                AUTO_FIX=true
                shift
                ;;
            -c|--check)
                check_item="$2"
                shift 2
                ;;
            -o|--output)
                output_file="$2"
                shift 2
                ;;
            *)
                echo "Unknown option: $1"
                show_help
                exit 1
                ;;
        esac
    done
    
    # Redirect output to file if specified
    if [[ -n "$output_file" ]]; then
        exec > >(tee "$output_file")
        exec 2>&1
    fi
    
    echo "$check_item"
}

# Check if running with sufficient privileges
check_privileges() {
    if [[ $EUID -eq 0 ]]; then
        log "WARN" "Running as root - some checks may behave differently"
    fi
    
    # Check if user can access Docker
    if ! docker version >/dev/null 2>&1; then
        log "ERROR" "Cannot access Docker - may need to add user to docker group"
        if [[ "$AUTO_FIX" == "true" ]]; then
            log "FIX" "Adding current user to docker group"
            sudo usermod -aG docker "$USER" || log "ERROR" "Failed to add user to docker group"
            log "INFO" "Please log out and back in for docker group changes to take effect"
        fi
    fi
}

# Check Docker daemon and containers
check_docker() {
    log "INFO" "Checking Docker daemon and containers..."
    
    # Check Docker daemon
    if ! systemctl is-active docker >/dev/null 2>&1; then
        log "ERROR" "Docker daemon is not running"
        if [[ "$AUTO_FIX" == "true" ]]; then
            log "FIX" "Starting Docker daemon"
            sudo systemctl start docker || log "ERROR" "Failed to start Docker daemon"
        fi
    else
        log "DEBUG" "Docker daemon is running"
    fi
    
    # Check Docker version
    local docker_version
    docker_version=$(docker version --format '{{.Server.Version}}' 2>/dev/null || echo "unknown")
    log "DEBUG" "Docker version: $docker_version"
    
    # Check if monitoring containers are defined
    if [[ ! -f "$MONITORING_DIR/docker-compose.yml" ]]; then
        log "ERROR" "Docker Compose file not found: $MONITORING_DIR/docker-compose.yml"
        return 1
    fi
    
    # Check container status
    cd "$MONITORING_DIR" || return 1
    local containers
    containers=$(docker-compose ps --services 2>/dev/null || echo "")
    
    if [[ -z "$containers" ]]; then
        log "WARN" "No monitoring containers found"
        if [[ "$AUTO_FIX" == "true" ]]; then
            log "FIX" "Starting monitoring stack"
            ./scripts/start_monitoring.sh || log "ERROR" "Failed to start monitoring stack"
        fi
    else
        log "DEBUG" "Found containers: $containers"
        
        # Check each container status
        while IFS= read -r container; do
            [[ -z "$container" ]] && continue
            
            local status
            status=$(docker-compose ps "$container" --format "table {{.State}}" 2>/dev/null | tail -n +2 || echo "unknown")
            
            case "$status" in
                *"Up"*)
                    log "DEBUG" "Container $container is running"
                    ;;
                *"Exit"*)
                    log "ERROR" "Container $container has exited"
                    if [[ "$AUTO_FIX" == "true" ]]; then
                        log "FIX" "Restarting container $container"
                        docker-compose restart "$container" || log "ERROR" "Failed to restart $container"
                    fi
                    ;;
                *)
                    log "ERROR" "Container $container status unknown: $status"
                    ;;
            esac
        done <<< "$containers"
    fi
    
    # Check Docker resources
    local docker_space
    docker_space=$(docker system df --format "table {{.Size}}" 2>/dev/null | tail -n +2 | head -1 || echo "unknown")
    log "DEBUG" "Docker space usage: $docker_space"
    
    # Check for dangling images/containers
    local dangling_images
    dangling_images=$(docker images -f "dangling=true" -q | wc -l)
    if [[ "$dangling_images" -gt 10 ]]; then
        log "WARN" "Found $dangling_images dangling Docker images"
        if [[ "$AUTO_FIX" == "true" ]]; then
            log "FIX" "Cleaning up dangling Docker images"
            docker image prune -f || log "ERROR" "Failed to clean up dangling images"
        fi
    fi
}

# Check Prometheus
check_prometheus() {
    log "INFO" "Checking Prometheus configuration and connectivity..."
    
    # Check if Prometheus is accessible
    if ! curl -sf http://localhost:9090/-/healthy >/dev/null 2>&1; then
        log "ERROR" "Prometheus health check failed"
        
        # Check if container is running
        if docker-compose ps prometheus | grep -q "Up"; then
            log "DEBUG" "Prometheus container is running, but health check failed"
            
            # Check Prometheus logs
            local logs
            logs=$(docker-compose logs --tail=10 prometheus 2>/dev/null | grep -i error || echo "No recent errors")
            log "DEBUG" "Recent Prometheus errors: $logs"
        else
            log "ERROR" "Prometheus container is not running"
            if [[ "$AUTO_FIX" == "true" ]]; then
                log "FIX" "Starting Prometheus container"
                cd "$MONITORING_DIR" && docker-compose up -d prometheus || log "ERROR" "Failed to start Prometheus"
            fi
        fi
    else
        log "DEBUG" "Prometheus is healthy"
        
        # Check Prometheus configuration
        if curl -sf http://localhost:9090/api/v1/status/config >/dev/null 2>&1; then
            log "DEBUG" "Prometheus configuration is valid"
        else
            log "ERROR" "Prometheus configuration is invalid"
        fi
        
        # Check targets
        local targets_down
        targets_down=$(curl -s http://localhost:9090/api/v1/targets 2>/dev/null | jq -r '.data.activeTargets[] | select(.health != "up") | .labels.job' 2>/dev/null | wc -l || echo "0")
        
        if [[ "$targets_down" -gt 0 ]]; then
            log "WARN" "$targets_down Prometheus targets are down"
            
            # List down targets
            curl -s http://localhost:9090/api/v1/targets 2>/dev/null | jq -r '.data.activeTargets[] | select(.health != "up") | "\(.labels.job): \(.lastError)"' 2>/dev/null | while read -r target; do
                log "WARN" "Down target: $target"
            done
        else
            log "DEBUG" "All Prometheus targets are up"
        fi
        
        # Check alert rules
        local alert_rules
        alert_rules=$(curl -s http://localhost:9090/api/v1/rules 2>/dev/null | jq -r '.data.groups | length' 2>/dev/null || echo "0")
        log "DEBUG" "Loaded alert rule groups: $alert_rules"
    fi
    
    # Check Prometheus configuration files
    if [[ ! -f "$MONITORING_DIR/prometheus/prometheus.yml" ]]; then
        log "ERROR" "Prometheus config file not found"
    else
        # Validate YAML syntax
        if python3 -c "import yaml; yaml.safe_load(open('$MONITORING_DIR/prometheus/prometheus.yml'))" 2>/dev/null; then
            log "DEBUG" "Prometheus YAML configuration is valid"
        else
            log "ERROR" "Prometheus YAML configuration is invalid"
        fi
    fi
}

# Check Grafana
check_grafana() {
    log "INFO" "Checking Grafana configuration and connectivity..."
    
    # Check if Grafana is accessible
    if ! curl -sf http://localhost:3000/api/health >/dev/null 2>&1; then
        log "ERROR" "Grafana health check failed"
        
        # Check container logs
        local logs
        logs=$(cd "$MONITORING_DIR" && docker-compose logs --tail=10 grafana 2>/dev/null | grep -i error || echo "No recent errors")
        log "DEBUG" "Recent Grafana errors: $logs"
        
        if [[ "$AUTO_FIX" == "true" ]]; then
            log "FIX" "Restarting Grafana container"
            cd "$MONITORING_DIR" && docker-compose restart grafana || log "ERROR" "Failed to restart Grafana"
            sleep 10
        fi
    else
        log "DEBUG" "Grafana is healthy"
        
        # Check datasources
        local datasources
        datasources=$(curl -s -u admin:admin http://localhost:3000/api/datasources 2>/dev/null | jq '. | length' 2>/dev/null || echo "0")
        
        if [[ "$datasources" -eq 0 ]]; then
            log "WARN" "No Grafana datasources configured"
        else
            log "DEBUG" "Grafana has $datasources datasource(s) configured"
            
            # Test Prometheus datasource connectivity
            local prometheus_test
            prometheus_test=$(curl -s -u admin:admin http://localhost:3000/api/datasources/proxy/1/api/v1/query?query=up 2>/dev/null | jq -r '.status' 2>/dev/null || echo "error")
            
            if [[ "$prometheus_test" == "success" ]]; then
                log "DEBUG" "Grafana can connect to Prometheus"
            else
                log "ERROR" "Grafana cannot connect to Prometheus"
            fi
        fi
        
        # Check dashboards
        local dashboards
        dashboards=$(curl -s -u admin:admin http://localhost:3000/api/search 2>/dev/null | jq '. | length' 2>/dev/null || echo "0")
        log "DEBUG" "Grafana has $dashboards dashboard(s)"
    fi
    
    # Check Grafana configuration files
    if [[ -d "$MONITORING_DIR/grafana/provisioning" ]]; then
        log "DEBUG" "Grafana provisioning directory exists"
        
        # Check datasource provisioning
        if [[ -f "$MONITORING_DIR/grafana/provisioning/datasources/prometheus.yml" ]]; then
            log "DEBUG" "Prometheus datasource provisioning file exists"
        else
            log "WARN" "Prometheus datasource provisioning file missing"
        fi
        
        # Check dashboard provisioning
        if [[ -f "$MONITORING_DIR/grafana/provisioning/dashboards/genesis.yml" ]]; then
            log "DEBUG" "Dashboard provisioning file exists"
        else
            log "WARN" "Dashboard provisioning file missing"
        fi
    else
        log "ERROR" "Grafana provisioning directory missing"
    fi
}

# Check AlertManager
check_alertmanager() {
    log "INFO" "Checking AlertManager configuration and connectivity..."
    
    # Check if AlertManager is accessible
    if ! curl -sf http://localhost:9093/-/healthy >/dev/null 2>&1; then
        log "ERROR" "AlertManager health check failed"
        
        # Check container logs
        local logs
        logs=$(cd "$MONITORING_DIR" && docker-compose logs --tail=10 alertmanager 2>/dev/null | grep -i error || echo "No recent errors")
        log "DEBUG" "Recent AlertManager errors: $logs"
        
        if [[ "$AUTO_FIX" == "true" ]]; then
            log "FIX" "Restarting AlertManager container"
            cd "$MONITORING_DIR" && docker-compose restart alertmanager || log "ERROR" "Failed to restart AlertManager"
            sleep 5
        fi
    else
        log "DEBUG" "AlertManager is healthy"
        
        # Check AlertManager status
        local status
        status=$(curl -s http://localhost:9093/api/v1/status 2>/dev/null | jq -r '.data.versionInfo.version' 2>/dev/null || echo "unknown")
        log "DEBUG" "AlertManager version: $status"
        
        # Check active alerts
        local active_alerts
        active_alerts=$(curl -s http://localhost:9093/api/v1/alerts 2>/dev/null | jq '. | length' 2>/dev/null || echo "0")
        log "DEBUG" "Active alerts: $active_alerts"
        
        # Check silences
        local silences
        silences=$(curl -s http://localhost:9093/api/v1/silences 2>/dev/null | jq '. | length' 2>/dev/null || echo "0")
        log "DEBUG" "Active silences: $silences"
    fi
    
    # Check AlertManager configuration
    if [[ ! -f "$MONITORING_DIR/alertmanager/alertmanager.yml" ]]; then
        log "ERROR" "AlertManager config file not found"
    else
        # Validate YAML syntax
        if python3 -c "import yaml; yaml.safe_load(open('$MONITORING_DIR/alertmanager/alertmanager.yml'))" 2>/dev/null; then
            log "DEBUG" "AlertManager YAML configuration is valid"
        else
            log "ERROR" "AlertManager YAML configuration is invalid"
        fi
    fi
}

# Check network connectivity
check_network() {
    log "INFO" "Checking network connectivity..."
    
    # Check listening ports
    local ports=("3000" "9090" "9093" "9100" "9104" "9121")
    for port in "${ports[@]}"; do
        if ss -tuln | grep -q ":$port "; then
            log "DEBUG" "Port $port is listening"
        else
            log "WARN" "Port $port is not listening"
        fi
    done
    
    # Check external connectivity
    local external_services=("google.com" "github.com")
    for service in "${external_services[@]}"; do
        if ping -c 1 -W 3 "$service" >/dev/null 2>&1; then
            log "DEBUG" "Can reach $service"
        else
            log "WARN" "Cannot reach $service - may affect external integrations"
        fi
    done
    
    # Check Docker network
    local docker_networks
    docker_networks=$(docker network ls --format "{{.Name}}" | grep -E "(monitoring|genesis)" || echo "")
    if [[ -n "$docker_networks" ]]; then
        log "DEBUG" "Docker networks found: $docker_networks"
    else
        log "WARN" "No monitoring-specific Docker networks found"
    fi
    
    # Check firewall status
    if command -v ufw >/dev/null 2>&1; then
        local ufw_status
        ufw_status=$(sudo ufw status | head -1)
        log "DEBUG" "Firewall status: $ufw_status"
    fi
}

# Check file permissions
check_permissions() {
    log "INFO" "Checking file permissions..."
    
    # Check monitoring directory permissions
    if [[ ! -d "$MONITORING_DIR" ]]; then
        log "ERROR" "Monitoring directory does not exist: $MONITORING_DIR"
        return 1
    fi
    
    if [[ ! -r "$MONITORING_DIR" ]]; then
        log "ERROR" "Cannot read monitoring directory: $MONITORING_DIR"
        if [[ "$AUTO_FIX" == "true" ]]; then
            log "FIX" "Fixing monitoring directory permissions"
            chmod 755 "$MONITORING_DIR" || log "ERROR" "Failed to fix permissions"
        fi
    else
        log "DEBUG" "Monitoring directory permissions OK"
    fi
    
    # Check configuration files
    local config_files=(
        "$MONITORING_DIR/prometheus/prometheus.yml"
        "$MONITORING_DIR/alertmanager/alertmanager.yml"
        "$MONITORING_DIR/docker-compose.yml"
    )
    
    for config_file in "${config_files[@]}"; do
        if [[ -f "$config_file" ]]; then
            if [[ -r "$config_file" ]]; then
                log "DEBUG" "Can read $config_file"
            else
                log "ERROR" "Cannot read $config_file"
                if [[ "$AUTO_FIX" == "true" ]]; then
                    log "FIX" "Fixing permissions for $config_file"
                    chmod 644 "$config_file" || log "ERROR" "Failed to fix permissions for $config_file"
                fi
            fi
        else
            log "WARN" "Configuration file not found: $config_file"
        fi
    done
    
    # Check script permissions
    local script_files=(
        "$MONITORING_DIR/scripts/start_monitoring.sh"
        "$MONITORING_DIR/scripts/stop_monitoring.sh"
        "$MONITORING_DIR/scripts/check_monitoring_health.sh"
    )
    
    for script_file in "${script_files[@]}"; do
        if [[ -f "$script_file" ]]; then
            if [[ -x "$script_file" ]]; then
                log "DEBUG" "Script $script_file is executable"
            else
                log "ERROR" "Script $script_file is not executable"
                if [[ "$AUTO_FIX" == "true" ]]; then
                    log "FIX" "Making $script_file executable"
                    chmod +x "$script_file" || log "ERROR" "Failed to make $script_file executable"
                fi
            fi
        else
            log "WARN" "Script file not found: $script_file"
        fi
    done
}

# Check system resources
check_resources() {
    log "INFO" "Checking system resources..."
    
    # Check disk space
    local disk_usage
    disk_usage=$(df -h / | awk 'NR==2 {print $5}' | tr -d '%')
    if [[ "$disk_usage" -gt 90 ]]; then
        log "ERROR" "Disk usage is critically high: ${disk_usage}%"
        if [[ "$AUTO_FIX" == "true" ]]; then
            log "FIX" "Cleaning up Docker resources"
            docker system prune -f || log "ERROR" "Failed to clean Docker resources"
        fi
    elif [[ "$disk_usage" -gt 80 ]]; then
        log "WARN" "Disk usage is high: ${disk_usage}%"
    else
        log "DEBUG" "Disk usage is acceptable: ${disk_usage}%"
    fi
    
    # Check memory usage
    local mem_usage
    mem_usage=$(free | awk 'NR==2{printf "%.0f", $3*100/$2}')
    if [[ "$mem_usage" -gt 90 ]]; then
        log "WARN" "Memory usage is high: ${mem_usage}%"
    else
        log "DEBUG" "Memory usage is acceptable: ${mem_usage}%"
    fi
    
    # Check load average
    local load_avg
    load_avg=$(uptime | awk -F'load average:' '{print $2}' | awk '{print $1}' | tr -d ',')
    local cpu_cores
    cpu_cores=$(nproc)
    
    if (( $(echo "$load_avg > $cpu_cores" | bc -l) )); then
        log "WARN" "Load average ($load_avg) is higher than CPU cores ($cpu_cores)"
    else
        log "DEBUG" "Load average is acceptable: $load_avg"
    fi
    
    # Check if monitoring processes are consuming excessive resources
    local top_processes
    top_processes=$(ps aux --sort=-%cpu | head -6 | grep -E "(prometheus|grafana|alertmanager)" || echo "None found")
    if [[ "$top_processes" != "None found" ]]; then
        log "DEBUG" "Top monitoring processes by CPU:"
        echo "$top_processes" | while IFS= read -r line; do
            log "DEBUG" "  $line"
        done
    fi
}

# Generate summary report
generate_summary() {
    echo
    log "INFO" "=== TROUBLESHOOTING SUMMARY ==="
    log "INFO" "Issues Found: $ISSUES_FOUND"
    log "INFO" "Fixes Applied: $FIXES_APPLIED"
    
    if [[ "$ISSUES_FOUND" -eq 0 ]]; then
        log "INFO" "✅ No issues detected - monitoring system appears healthy"
    elif [[ "$FIXES_APPLIED" -gt 0 ]]; then
        log "INFO" "🔧 Applied $FIXES_APPLIED automatic fixes"
        log "INFO" "Remaining issues may require manual intervention"
    else
        log "INFO" "❌ Issues detected but no automatic fixes applied"
        log "INFO" "Run with --fix flag to apply automatic fixes"
    fi
    
    echo
    log "INFO" "Next steps:"
    if [[ "$ISSUES_FOUND" -gt 0 ]]; then
        log "INFO" "1. Review errors above"
        log "INFO" "2. Check detailed logs: docker-compose logs <service>"
        log "INFO" "3. Consult runbook: docs/monitoring/MONITORING_RUNBOOK.md"
        log "INFO" "4. Contact DevOps team if issues persist"
    else
        log "INFO" "1. Monitor dashboards: http://localhost:3000"
        log "INFO" "2. Check alerts: http://localhost:9093"
        log "INFO" "3. Review metrics: http://localhost:9090"
    fi
}

# Main function
main() {
    log "INFO" "Starting GENESIS monitoring troubleshooting..."
    log "INFO" "Verbose: $VERBOSE, Auto-fix: $AUTO_FIX"
    
    local check_item
    check_item=$(parse_args "$@")
    
    check_privileges
    
    case "$check_item" in
        "all")
            check_docker
            check_prometheus
            check_grafana
            check_alertmanager
            check_network
            check_permissions
            check_resources
            ;;
        "docker")
            check_docker
            ;;
        "prometheus")
            check_prometheus
            ;;
        "grafana")
            check_grafana
            ;;
        "alertmanager")
            check_alertmanager
            ;;
        "network")
            check_network
            ;;
        "permissions")
            check_permissions
            ;;
        "resources")
            check_resources
            ;;
        *)
            log "ERROR" "Unknown check item: $check_item"
            show_help
            exit 1
            ;;
    esac
    
    generate_summary
    
    # Exit with error code if issues were found
    exit $ISSUES_FOUND
}

# Run main function with all arguments
main "$@"