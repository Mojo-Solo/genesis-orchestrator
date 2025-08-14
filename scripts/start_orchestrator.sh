#!/bin/bash
# GENESIS Orchestrator Startup Script
# Starts all required services with proper health checks

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
LOG_DIR="$PROJECT_ROOT/logs"
PID_DIR="$PROJECT_ROOT/.pids"

# Create necessary directories
mkdir -p "$LOG_DIR" "$PID_DIR"

# Load environment variables
if [ -f "$PROJECT_ROOT/.env" ]; then
    export $(cat "$PROJECT_ROOT/.env" | grep -v '^#' | xargs)
else
    echo -e "${YELLOW}Warning: .env file not found, using defaults${NC}"
fi

# Function to check if a service is running
check_service() {
    local service_name=$1
    local pid_file="$PID_DIR/$service_name.pid"
    
    if [ -f "$pid_file" ]; then
        local pid=$(cat "$pid_file")
        if ps -p "$pid" > /dev/null 2>&1; then
            return 0
        else
            rm "$pid_file"
        fi
    fi
    return 1
}

# Function to start a service
start_service() {
    local service_name=$1
    local command=$2
    local log_file="$LOG_DIR/$service_name.log"
    local pid_file="$PID_DIR/$service_name.pid"
    
    if check_service "$service_name"; then
        echo -e "${YELLOW}$service_name is already running${NC}"
        return 0
    fi
    
    echo -n "Starting $service_name..."
    
    # Start the service in background
    nohup $command >> "$log_file" 2>&1 &
    local pid=$!
    echo $pid > "$pid_file"
    
    # Wait a moment and check if it's still running
    sleep 2
    if ps -p "$pid" > /dev/null 2>&1; then
        echo -e " ${GREEN}✓${NC}"
        return 0
    else
        echo -e " ${RED}✗${NC}"
        echo "Check logs at: $log_file"
        return 1
    fi
}

# Function to stop a service
stop_service() {
    local service_name=$1
    local pid_file="$PID_DIR/$service_name.pid"
    
    if [ -f "$pid_file" ]; then
        local pid=$(cat "$pid_file")
        if ps -p "$pid" > /dev/null 2>&1; then
            echo -n "Stopping $service_name..."
            kill "$pid" 2>/dev/null || true
            
            # Wait for graceful shutdown
            local count=0
            while ps -p "$pid" > /dev/null 2>&1 && [ $count -lt 10 ]; do
                sleep 1
                count=$((count + 1))
            done
            
            # Force kill if still running
            if ps -p "$pid" > /dev/null 2>&1; then
                kill -9 "$pid" 2>/dev/null || true
            fi
            
            rm "$pid_file"
            echo -e " ${GREEN}✓${NC}"
        fi
    fi
}

# Parse command line arguments
COMMAND=${1:-start}

case "$COMMAND" in
    start)
        echo "=========================================="
        echo "Starting GENESIS Orchestrator Services"
        echo "=========================================="
        
        # Check Python version
        PYTHON_VERSION=$(python3 --version 2>&1 | awk '{print $2}')
        echo "Python version: $PYTHON_VERSION"
        
        # Install dependencies if needed
        if [ ! -d "$PROJECT_ROOT/venv" ]; then
            echo "Creating virtual environment..."
            python3 -m venv "$PROJECT_ROOT/venv"
            source "$PROJECT_ROOT/venv/bin/activate"
            pip install --upgrade pip
            pip install -r "$PROJECT_ROOT/requirements-production.txt"
        else
            source "$PROJECT_ROOT/venv/bin/activate"
        fi
        
        # Start Redis if not running (optional - skip if using external)
        if ! redis-cli ping > /dev/null 2>&1; then
            echo -e "${YELLOW}Redis not running. Start it with: redis-server${NC}"
        fi
        
        # Start Temporal worker
        cd "$PROJECT_ROOT"
        start_service "temporal-worker" "python tools/temporal/worker.py"
        
        # Start orchestrator monitoring
        start_service "monitoring" "python orchestrator/monitoring_config.py"
        
        # Run health check
        echo ""
        echo "Running health check..."
        python scripts/health_check.py
        
        echo ""
        echo -e "${GREEN}Orchestrator services started successfully!${NC}"
        echo ""
        echo "Service logs:"
        echo "  Temporal Worker: $LOG_DIR/temporal-worker.log"
        echo "  Monitoring: $LOG_DIR/monitoring.log"
        echo ""
        echo "Endpoints:"
        echo "  Metrics: http://localhost:9090/metrics"
        echo "  Health: http://localhost:8081/health"
        ;;
        
    stop)
        echo "=========================================="
        echo "Stopping GENESIS Orchestrator Services"
        echo "=========================================="
        
        stop_service "temporal-worker"
        stop_service "monitoring"
        
        echo -e "${GREEN}All services stopped${NC}"
        ;;
        
    restart)
        $0 stop
        sleep 2
        $0 start
        ;;
        
    status)
        echo "=========================================="
        echo "GENESIS Orchestrator Service Status"
        echo "=========================================="
        
        for service in temporal-worker monitoring; do
            if check_service "$service"; then
                local pid=$(cat "$PID_DIR/$service.pid")
                echo -e "$service: ${GREEN}Running${NC} (PID: $pid)"
            else
                echo -e "$service: ${RED}Stopped${NC}"
            fi
        done
        ;;
        
    logs)
        SERVICE=${2:-temporal-worker}
        if [ -f "$LOG_DIR/$SERVICE.log" ]; then
            tail -f "$LOG_DIR/$SERVICE.log"
        else
            echo "No logs found for $SERVICE"
        fi
        ;;
        
    *)
        echo "Usage: $0 {start|stop|restart|status|logs [service]}"
        exit 1
        ;;
esac