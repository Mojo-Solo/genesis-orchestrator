#!/bin/bash

# GENESIS Orchestrator - Simple Start Script
# Just gets the essentials running without complex frontend dependencies

set -uo pipefail

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_DIR="$SCRIPT_DIR/logs"
PID_DIR="$SCRIPT_DIR/.pids"

mkdir -p "$LOG_DIR" "$PID_DIR"

log() {
    echo -e "${BLUE}[$(date +'%H:%M:%S')]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[$(date +'%H:%M:%S')] ✓${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[$(date +'%H:%M:%S')] ⚠${NC} $1"
}

# Cleanup function
cleanup() {
    echo "Cleaning up..."
    if [[ -d "$PID_DIR" ]]; then
        for pidfile in "$PID_DIR"/*.pid; do
            if [[ -f "$pidfile" ]]; then
                local pid=$(cat "$pidfile" 2>/dev/null || echo "")
                if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
                    kill "$pid" 2>/dev/null || true
                fi
                rm -f "$pidfile"
            fi
        done
    fi
    
    # Kill any processes on common ports
    for port in 8810 8811 8812; do
        local pids=$(lsof -ti:$port 2>/dev/null || echo "")
        if [[ -n "$pids" ]]; then
            echo "$pids" | xargs -r kill -9 2>/dev/null || true
        fi
    done
}

trap cleanup EXIT INT TERM

echo "🚀 GENESIS Orchestrator - Simple Start"
echo "======================================"

# Create basic .env file
log "Creating environment configuration..."
cat > "$SCRIPT_DIR/.env" << 'EOF'
APP_NAME="GENESIS Orchestrator"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=sqlite
DB_DATABASE=/tmp/genesis.sqlite

CACHE_DRIVER=array
QUEUE_CONNECTION=sync
SESSION_DRIVER=file

LOG_CHANNEL=stack
LOG_LEVEL=debug
EOF

# Check for any artisan file in the project
log "Looking for Laravel backend..."
backend_found=false

for dir in "backend" "." "cascade-service"; do
    check_path="$SCRIPT_DIR"
    if [[ "$dir" != "." ]]; then
        check_path="$SCRIPT_DIR/$dir"
    fi
    
    if [[ -f "$check_path/artisan" ]]; then
        log_success "Found Laravel in: $dir"
        cd "$check_path"
        
        # Copy env file
        cp "$SCRIPT_DIR/.env" ".env" 2>/dev/null || true
        
        # Clear Laravel caches
        php artisan config:clear -q 2>/dev/null || true
        php artisan cache:clear -q 2>/dev/null || true
        
        # Start Laravel server
        log "Starting Laravel on port 8000..."
        nohup php artisan serve --host=0.0.0.0 --port=8000 > "$LOG_DIR/backend.log" 2>&1 &
        backend_pid=$!
        echo $backend_pid > "$PID_DIR/backend.pid"
        
        sleep 3
        
        if kill -0 $backend_pid 2>/dev/null; then
            log_success "✅ Backend started (PID: $backend_pid)"
            log_success "🌐 Backend URL: http://localhost:8000"
            backend_found=true
        else
            log_warning "Backend failed to start - check $LOG_DIR/backend.log"
        fi
        
        cd "$SCRIPT_DIR"
        break
    fi
done

if [[ "$backend_found" == "false" ]]; then
    log_warning "No Laravel backend found"
fi

# Look for a simple frontend that actually works
log "Looking for frontend..."
frontend_found=false

# Check for Cothinkr (which seems to have working package.json)
if [[ -d "$SCRIPT_DIR/cothinkr" && -f "$SCRIPT_DIR/cothinkr/package.json" ]]; then
    cd "$SCRIPT_DIR/cothinkr"
    log "Found Cothinkr frontend, starting..."
    
    # Quick npm install without failing
    npm install -q 2>/dev/null || log_warning "npm install failed, trying anyway..."
    
    # Start dev server
    nohup npm run dev > "$LOG_DIR/frontend.log" 2>&1 &
    frontend_pid=$!
    echo $frontend_pid > "$PID_DIR/frontend.pid"
    
    sleep 5
    
    if kill -0 $frontend_pid 2>/dev/null; then
        log_success "✅ Frontend started (PID: $frontend_pid)"
        log_success "🌐 Frontend URL: http://localhost:3000"
        frontend_found=true
    else
        log_warning "Frontend failed to start - check $LOG_DIR/frontend.log"
    fi
    
    cd "$SCRIPT_DIR"
fi

# Launch browser
sleep 2
if [[ "$frontend_found" == "true" ]]; then
    url="http://localhost:3000"
elif [[ "$backend_found" == "true" ]]; then
    url="http://localhost:8000"
else
    log_warning "No services started successfully"
    exit 1
fi

log "🌐 Opening $url in incognito browser..."

# macOS Chrome incognito
if command -v open >/dev/null 2>&1 && [[ -d "/Applications/Google Chrome.app" ]]; then
    open -na "Google Chrome" --args \
        --incognito \
        --new-window \
        --user-data-dir="/tmp/chrome-genesis-$(date +%s)" \
        "$url" &
    log_success "✅ Chrome incognito launched!"
else
    log "Please open: $url"
fi

# Show final status
echo ""
echo "📊 GENESIS ORCHESTRATOR STATUS"
echo "==============================="

if [[ "$backend_found" == "true" ]]; then
    echo "✅ Backend: Running on http://localhost:8000"
else
    echo "❌ Backend: Not running"
fi

if [[ "$frontend_found" == "true" ]]; then
    echo "✅ Frontend: Running on http://localhost:3000"
else
    echo "❌ Frontend: Not running"
fi

echo ""
echo "📁 Logs: $LOG_DIR"
echo "🔍 PIDs: $PID_DIR"
echo ""
echo "🎉 GENESIS Orchestrator is ready!"
echo "Press Ctrl+C to stop all services"

# Keep script running
while true; do
    sleep 60
done