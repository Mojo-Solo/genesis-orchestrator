#!/bin/bash

# GENESIS Orchestrator - Quick Health Check
# Test if all services are running properly

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PID_DIR="$SCRIPT_DIR/.pids"

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_success() {
    echo -e "${GREEN}✓${NC} $1"
}

log_error() {
    echo -e "${RED}✗${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

check_service() {
    local service_name=$1
    local pid_file="$PID_DIR/${service_name}.pid"
    local url=$2
    
    if [[ -f "$pid_file" ]]; then
        local pid=$(cat "$pid_file")
        if kill -0 $pid 2>/dev/null; then
            if [[ -n "$url" ]]; then
                if curl -sf --max-time 5 "$url" > /dev/null 2>&1; then
                    log_success "$service_name is running and responding (PID: $pid)"
                    return 0
                else
                    log_warning "$service_name is running but not responding at $url (PID: $pid)"
                    return 1
                fi
            else
                log_success "$service_name is running (PID: $pid)"
                return 0
            fi
        else
            log_error "$service_name process not found (stale PID file)"
            return 1
        fi
    else
        log_error "$service_name is not running (no PID file)"
        return 1
    fi
}

echo "🔍 GENESIS Orchestrator Health Check"
echo "====================================="

# Check backend
if [[ -f "$PID_DIR/backend.pid" ]]; then
    backend_port=$(ss -tlnp 2>/dev/null | grep "php artisan serve" | grep -o ':\d\+' | head -1 | cut -d: -f2)
    if [[ -n "$backend_port" ]]; then
        check_service "backend" "http://localhost:$backend_port/api/health"
    else
        check_service "backend"
    fi
else
    log_error "Backend is not running"
fi

# Check frontend
if [[ -f "$PID_DIR/frontend.pid" ]]; then
    frontend_port=$(ss -tlnp 2>/dev/null | grep "node" | grep -o ':\d\+' | head -1 | cut -d: -f2)
    if [[ -n "$frontend_port" ]]; then
        check_service "frontend" "http://localhost:$frontend_port"
    else
        check_service "frontend"
    fi
else
    log_error "Frontend is not running"
fi

# Check database
if pgrep -x "mysqld" > /dev/null; then
    log_success "MySQL database is running"
else
    log_warning "MySQL database is not running"
fi

# Check Redis
if pgrep -x "redis-server" > /dev/null; then
    log_success "Redis cache is running"
else
    log_warning "Redis cache is not running"
fi

echo ""
echo "Use './start.sh' to start all services"
echo "Use './start.sh stop' to stop all services"