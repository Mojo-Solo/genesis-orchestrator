#!/bin/bash

# GENESIS Orchestrator - Monitoring Setup Script
# ==============================================
# Sets up complete monitoring infrastructure with Prometheus, Grafana, and AlertManager

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
MONITORING_DIR="${PROJECT_ROOT}/monitoring"

# Configuration
PROMETHEUS_VERSION="2.45.0"
GRAFANA_VERSION="10.0.0"
ALERTMANAGER_VERSION="0.25.0"
NODE_EXPORTER_VERSION="1.6.0"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging function
log() {
    local level=$1
    shift
    local message="$*"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    case $level in
        "INFO")  echo -e "${GREEN}[INFO]${NC}  ${timestamp} - $message" ;;
        "WARN")  echo -e "${YELLOW}[WARN]${NC}  ${timestamp} - $message" ;;
        "ERROR") echo -e "${RED}[ERROR]${NC} ${timestamp} - $message" ;;
        "DEBUG") echo -e "${BLUE}[DEBUG]${NC} ${timestamp} - $message" ;;
    esac
}

# Check if running as root
check_permissions() {
    if [[ $EUID -eq 0 ]]; then
        log "ERROR" "This script should not be run as root for security reasons"
        exit 1
    fi
}

# Check system requirements
check_requirements() {
    log "INFO" "Checking system requirements..."
    
    # Check for required commands
    local required_commands=("curl" "tar" "systemctl" "docker" "docker-compose")
    for cmd in "${required_commands[@]}"; do
        if ! command -v "$cmd" &> /dev/null; then
            log "ERROR" "Required command '$cmd' not found"
            exit 1
        fi
    done
    
    # Check available disk space (need at least 2GB)
    local available_space=$(df . | awk 'NR==2 {print $4}')
    if [[ $available_space -lt 2097152 ]]; then  # 2GB in KB
        log "ERROR" "Insufficient disk space. Need at least 2GB available"
        exit 1
    fi
    
    log "INFO" "System requirements check passed"
}

# Create monitoring directories
create_directories() {
    log "INFO" "Creating monitoring directory structure..."
    
    local directories=(
        "${MONITORING_DIR}/data/prometheus"
        "${MONITORING_DIR}/data/grafana"
        "${MONITORING_DIR}/data/alertmanager"
        "${MONITORING_DIR}/logs/prometheus"
        "${MONITORING_DIR}/logs/grafana"
        "${MONITORING_DIR}/logs/alertmanager"
        "${MONITORING_DIR}/config/prometheus"
        "${MONITORING_DIR}/config/grafana/provisioning/datasources"
        "${MONITORING_DIR}/config/grafana/provisioning/dashboards"
        "${MONITORING_DIR}/config/alertmanager"
        "${MONITORING_DIR}/scripts"
    )
    
    for dir in "${directories[@]}"; do
        mkdir -p "$dir"
        log "DEBUG" "Created directory: $dir"
    done
    
    log "INFO" "Directory structure created successfully"
}

# Download and install monitoring components
install_monitoring_stack() {
    log "INFO" "Installing monitoring stack components..."
    
    # Create docker-compose file for monitoring stack
    cat > "${MONITORING_DIR}/docker-compose.yml" << 'EOF'
version: '3.8'

networks:
  genesis-monitoring:
    driver: bridge

volumes:
  prometheus-data:
  grafana-data:
  alertmanager-data:

services:
  prometheus:
    image: prom/prometheus:v2.45.0
    container_name: genesis-prometheus
    ports:
      - "9090:9090"
    volumes:
      - ./prometheus/prometheus.yml:/etc/prometheus/prometheus.yml:ro
      - ./prometheus/alert_rules:/etc/prometheus/alert_rules:ro
      - prometheus-data:/prometheus
    command:
      - '--config.file=/etc/prometheus/prometheus.yml'
      - '--storage.tsdb.path=/prometheus'
      - '--web.console.libraries=/etc/prometheus/console_libraries'
      - '--web.console.templates=/etc/prometheus/consoles'
      - '--web.enable-lifecycle'
      - '--web.enable-admin-api'
      - '--storage.tsdb.retention.time=90d'
      - '--storage.tsdb.retention.size=50GB'
    networks:
      - genesis-monitoring
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "wget", "--no-verbose", "--tries=1", "--spider", "http://localhost:9090/-/healthy"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 30s

  alertmanager:
    image: prom/alertmanager:v0.25.0
    container_name: genesis-alertmanager
    ports:
      - "9093:9093"
    volumes:
      - ./alertmanager/alertmanager.yml:/etc/alertmanager/alertmanager.yml:ro
      - ./alertmanager/templates:/etc/alertmanager/templates:ro
      - alertmanager-data:/alertmanager
    command:
      - '--config.file=/etc/alertmanager/alertmanager.yml'
      - '--storage.path=/alertmanager'
      - '--web.external-url=http://localhost:9093'
      - '--cluster.listen-address=0.0.0.0:9094'
      - '--log.level=info'
    networks:
      - genesis-monitoring
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "wget", "--no-verbose", "--tries=1", "--spider", "http://localhost:9093/-/healthy"]
      interval: 30s
      timeout: 10s
      retries: 3

  grafana:
    image: grafana/grafana:10.0.0
    container_name: genesis-grafana
    ports:
      - "3000:3000"
    volumes:
      - grafana-data:/var/lib/grafana
      - ./grafana/provisioning:/etc/grafana/provisioning:ro
      - ./grafana/dashboards:/var/lib/grafana/dashboards:ro
    environment:
      - GF_SECURITY_ADMIN_PASSWORD=${GRAFANA_ADMIN_PASSWORD:-admin}
      - GF_USERS_ALLOW_SIGN_UP=false
      - GF_INSTALL_PLUGINS=grafana-piechart-panel,grafana-worldmap-panel
      - GF_ALERTING_ENABLED=true
      - GF_UNIFIED_ALERTING_ENABLED=true
      - GF_FEATURE_TOGGLES_ENABLE=ngalert
    networks:
      - genesis-monitoring
    restart: unless-stopped
    healthcheck:
      test: ["CMD-SHELL", "curl -f http://localhost:3000/api/health || exit 1"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 30s

  node-exporter:
    image: prom/node-exporter:v1.6.0
    container_name: genesis-node-exporter
    ports:
      - "9100:9100"
    volumes:
      - /proc:/host/proc:ro
      - /sys:/host/sys:ro
      - /:/rootfs:ro
    command:
      - '--path.procfs=/host/proc'
      - '--path.rootfs=/rootfs'
      - '--path.sysfs=/host/sys'
      - '--collector.filesystem.mount-points-exclude=^/(sys|proc|dev|host|etc)($$|/)'
    networks:
      - genesis-monitoring
    restart: unless-stopped

  # MySQL Exporter for database metrics
  mysql-exporter:
    image: prom/mysqld-exporter:latest
    container_name: genesis-mysql-exporter
    ports:
      - "9104:9104"
    environment:
      - DATA_SOURCE_NAME=${MYSQL_EXPORTER_DSN:-root:@tcp(host.docker.internal:3306)/}
    networks:
      - genesis-monitoring
    restart: unless-stopped

  # Redis Exporter for cache metrics
  redis-exporter:
    image: oliver006/redis_exporter:latest
    container_name: genesis-redis-exporter
    ports:
      - "9121:9121"
    environment:
      - REDIS_ADDR=redis://host.docker.internal:6379
    networks:
      - genesis-monitoring
    restart: unless-stopped
EOF

    log "INFO" "Docker compose configuration created"
}

# Configure Grafana datasources and dashboards
setup_grafana() {
    log "INFO" "Configuring Grafana datasources and dashboards..."
    
    # Create Prometheus datasource configuration
    cat > "${MONITORING_DIR}/grafana/provisioning/datasources/prometheus.yml" << 'EOF'
apiVersion: 1

datasources:
  - name: Prometheus
    type: prometheus
    access: proxy
    url: http://prometheus:9090
    isDefault: true
    jsonData:
      timeInterval: "5s"
      queryTimeout: "60s"
      httpMethod: "GET"
    secureJsonData: {}
EOF

    # Create dashboard provisioning configuration
    cat > "${MONITORING_DIR}/grafana/provisioning/dashboards/genesis.yml" << 'EOF'
apiVersion: 1

providers:
  - name: 'Genesis Orchestrator'
    orgId: 1
    folder: 'Genesis'
    type: file
    disableDeletion: false
    updateIntervalSeconds: 30
    allowUiUpdates: true
    options:
      path: /var/lib/grafana/dashboards
EOF

    log "INFO" "Grafana configuration completed"
}

# Create monitoring health check script
create_health_check() {
    log "INFO" "Creating monitoring health check script..."
    
    cat > "${MONITORING_DIR}/scripts/check_monitoring_health.sh" << 'EOF'
#!/bin/bash

# Health check for monitoring components
set -euo pipefail

MONITORING_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FAILED_CHECKS=()

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

check_service() {
    local service=$1
    local url=$2
    local timeout=${3:-5}
    
    if curl -sf --max-time "$timeout" "$url" > /dev/null 2>&1; then
        echo -e "${GREEN}✓${NC} $service is healthy"
        return 0
    else
        echo -e "${RED}✗${NC} $service is not responding"
        FAILED_CHECKS+=("$service")
        return 1
    fi
}

echo "Checking GENESIS monitoring stack health..."
echo "=========================================="

# Check Prometheus
check_service "Prometheus" "http://localhost:9090/-/healthy"

# Check AlertManager
check_service "AlertManager" "http://localhost:9093/-/healthy"

# Check Grafana
check_service "Grafana" "http://localhost:3000/api/health"

# Check Node Exporter
check_service "Node Exporter" "http://localhost:9100/metrics" 3

# Check MySQL Exporter
check_service "MySQL Exporter" "http://localhost:9104/metrics" 3

# Check Redis Exporter
check_service "Redis Exporter" "http://localhost:9121/metrics" 3

echo "=========================================="

if [ ${#FAILED_CHECKS[@]} -eq 0 ]; then
    echo -e "${GREEN}All monitoring components are healthy!${NC}"
    exit 0
else
    echo -e "${RED}Failed checks: ${FAILED_CHECKS[*]}${NC}"
    exit 1
fi
EOF

    chmod +x "${MONITORING_DIR}/scripts/check_monitoring_health.sh"
    log "INFO" "Health check script created"
}

# Create monitoring management script
create_management_scripts() {
    log "INFO" "Creating monitoring management scripts..."
    
    # Start monitoring script
    cat > "${MONITORING_DIR}/scripts/start_monitoring.sh" << 'EOF'
#!/bin/bash
set -euo pipefail

MONITORING_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "Starting GENESIS monitoring stack..."
cd "$MONITORING_DIR"

# Start all services
docker-compose up -d

# Wait for services to be ready
sleep 30

# Check health
if ./scripts/check_monitoring_health.sh; then
    echo "Monitoring stack started successfully!"
    echo "Access URLs:"
    echo "  - Prometheus: http://localhost:9090"
    echo "  - Grafana: http://localhost:3000 (admin/admin)"
    echo "  - AlertManager: http://localhost:9093"
else
    echo "Some services failed to start properly. Check logs:"
    docker-compose logs
fi
EOF

    # Stop monitoring script
    cat > "${MONITORING_DIR}/scripts/stop_monitoring.sh" << 'EOF'
#!/bin/bash
set -euo pipefail

MONITORING_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "Stopping GENESIS monitoring stack..."
cd "$MONITORING_DIR"

docker-compose down

echo "Monitoring stack stopped."
EOF

    # Update monitoring script
    cat > "${MONITORING_DIR}/scripts/update_monitoring.sh" << 'EOF'
#!/bin/bash
set -euo pipefail

MONITORING_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "Updating GENESIS monitoring stack..."
cd "$MONITORING_DIR"

# Pull latest images
docker-compose pull

# Restart with new images
docker-compose down
docker-compose up -d

# Wait and check health
sleep 30
./scripts/check_monitoring_health.sh

echo "Monitoring stack updated successfully!"
EOF

    # Make scripts executable
    chmod +x "${MONITORING_DIR}/scripts/start_monitoring.sh"
    chmod +x "${MONITORING_DIR}/scripts/stop_monitoring.sh"
    chmod +x "${MONITORING_DIR}/scripts/update_monitoring.sh"
    
    log "INFO" "Management scripts created"
}

# Create backup script for monitoring data
create_backup_script() {
    log "INFO" "Creating monitoring backup script..."
    
    cat > "${MONITORING_DIR}/scripts/backup_monitoring.sh" << 'EOF'
#!/bin/bash
set -euo pipefail

MONITORING_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="${MONITORING_DIR}/backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

mkdir -p "$BACKUP_DIR"

echo "Creating monitoring backup: monitoring_backup_${TIMESTAMP}.tar.gz"

# Create backup excluding temporary files
tar -czf "${BACKUP_DIR}/monitoring_backup_${TIMESTAMP}.tar.gz" \
    -C "$MONITORING_DIR" \
    --exclude='*.log' \
    --exclude='data/*/tmp/*' \
    --exclude='logs/*' \
    prometheus/ grafana/ alertmanager/ scripts/

# Keep only last 7 backups
find "$BACKUP_DIR" -name "monitoring_backup_*.tar.gz" -mtime +7 -delete

echo "Backup created: monitoring_backup_${TIMESTAMP}.tar.gz"
EOF

    chmod +x "${MONITORING_DIR}/scripts/backup_monitoring.sh"
    log "INFO" "Backup script created"
}

# Main setup function
main() {
    log "INFO" "Starting GENESIS Orchestrator monitoring setup..."
    
    check_permissions
    check_requirements
    create_directories
    install_monitoring_stack
    setup_grafana
    create_health_check
    create_management_scripts
    create_backup_script
    
    log "INFO" "Monitoring setup completed successfully!"
    log "INFO" "Next steps:"
    log "INFO" "  1. Set environment variables (GRAFANA_ADMIN_PASSWORD, MYSQL_EXPORTER_DSN)"
    log "INFO" "  2. Run: cd ${MONITORING_DIR} && ./scripts/start_monitoring.sh"
    log "INFO" "  3. Configure alert notification channels in AlertManager"
    log "INFO" "  4. Import Grafana dashboards from monitoring/grafana/dashboards/"
}

# Run main function
main "$@"