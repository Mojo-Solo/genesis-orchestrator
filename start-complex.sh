#!/bin/bash

# GENESIS Orchestrator - Complete Auto-Start Script
# Handles database, ports, environment, and browser launch
# No more port conflicts or connection issues!

# Don't exit on pip failures, but exit on other errors
set -uo pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Configuration
PROJECT_NAME="GENESIS Orchestrator"
BASE_PORT=8810  # Unique port to avoid conflicts
FRONTEND_PORT=8811
BACKEND_PORT=8812
DB_PORT=8813
REDIS_PORT=8814

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VENV_PATH="$SCRIPT_DIR/venv"
LOG_DIR="$SCRIPT_DIR/logs"
PID_DIR="$SCRIPT_DIR/.pids"

# Create necessary directories
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

log_error() {
    echo -e "${RED}[$(date +'%H:%M:%S')] ✗${NC} $1"
}

log_header() {
    echo -e "\n${PURPLE}╔══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${PURPLE}║${NC}  $1"
    echo -e "${PURPLE}╚══════════════════════════════════════════════════════════════╝${NC}\n"
}

# Cleanup function
cleanup() {
    log_header "🛑 CLEANING UP PROCESSES"
    
    # Kill any existing processes
    if [[ -d "$PID_DIR" ]]; then
        for pidfile in "$PID_DIR"/*.pid; do
            if [[ -f "$pidfile" ]]; then
                local pid=$(cat "$pidfile")
                if kill -0 "$pid" 2>/dev/null; then
                    log "Stopping process $pid"
                    kill "$pid" 2>/dev/null || true
                fi
                rm -f "$pidfile"
            fi
        done
    fi
    
    # Kill processes on our ports
    local ports=($BASE_PORT $FRONTEND_PORT $BACKEND_PORT $DB_PORT $REDIS_PORT)
    for port in "${ports[@]}"; do
        local pid=$(lsof -ti:$port 2>/dev/null || echo "")
        if [[ -n "$pid" ]]; then
            log "Killing process on port $port (PID: $pid)"
            kill -9 $pid 2>/dev/null || true
        fi
    done
    
    log_success "Cleanup completed"
}

# Check if port is available
check_port() {
    local port=$1
    if lsof -Pi :$port -sTCP:LISTEN -t >/dev/null 2>&1; then
        return 1
    fi
    return 0
}

# Find available port starting from base
find_available_port() {
    local base_port=$1
    local port=$base_port
    
    while ! check_port $port; do
        ((port++))
        if [[ $port -gt $((base_port + 100)) ]]; then
            log_error "No available ports found starting from $base_port"
            exit 1
        fi
    done
    
    echo $port
}

# Auto-detect and setup database
setup_database() {
    log_header "🗄️ DATABASE SETUP"
    
    # Check if MySQL is running
    if pgrep -x "mysqld" > /dev/null || brew services list | grep mysql | grep started > /dev/null; then
        log_success "MySQL is already running"
        DB_HOST="127.0.0.1"
        DB_PORT="3306"
    else
        # Try to start MySQL with Homebrew
        if command -v brew >/dev/null 2>&1; then
            log "Starting MySQL with Homebrew..."
            brew services start mysql 2>/dev/null || true
            sleep 3
            
            if pgrep -x "mysqld" > /dev/null; then
                log_success "MySQL started successfully"
                DB_HOST="127.0.0.1"
                DB_PORT="3306"
            else
                log_warning "Could not start MySQL, will use SQLite"
                DB_CONNECTION="sqlite"
                DB_DATABASE="$SCRIPT_DIR/database/genesis.sqlite"
                mkdir -p "$(dirname "$DB_DATABASE")"
                touch "$DB_DATABASE"
            fi
        else
            log_warning "Homebrew not found, using SQLite"
            DB_CONNECTION="sqlite"
            DB_DATABASE="$SCRIPT_DIR/database/genesis.sqlite"
            mkdir -p "$(dirname "$DB_DATABASE")"
            touch "$DB_DATABASE"
        fi
    fi
}

# Setup Redis
setup_redis() {
    log_header "🔄 REDIS SETUP"
    
    # Check if Redis is running
    if pgrep -x "redis-server" > /dev/null || brew services list | grep redis | grep started > /dev/null; then
        log_success "Redis is already running"
        REDIS_HOST="127.0.0.1"
        REDIS_PORT="6379"
    else
        # Try to start Redis with Homebrew
        if command -v brew >/dev/null 2>&1; then
            log "Starting Redis with Homebrew..."
            brew services start redis 2>/dev/null || true
            sleep 2
            
            if pgrep -x "redis-server" > /dev/null; then
                log_success "Redis started successfully"
                REDIS_HOST="127.0.0.1"
                REDIS_PORT="6379"
            else
                log_warning "Could not start Redis, caching will be disabled"
                CACHE_DRIVER="array"
            fi
        else
            log_warning "Homebrew not found, caching will be disabled"
            CACHE_DRIVER="array"
        fi
    fi
}

# Create environment file
create_env_file() {
    log_header "⚙️ ENVIRONMENT CONFIGURATION"
    
    local env_file="$SCRIPT_DIR/.env"
    
    cat > "$env_file" << EOF
# GENESIS Orchestrator - Auto-Generated Environment
# Generated: $(date)

APP_NAME="GENESIS Orchestrator"
APP_ENV=local
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=true
APP_URL=http://localhost:$BACKEND_PORT

LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

# Database Configuration
DB_CONNECTION=${DB_CONNECTION:-mysql}
DB_HOST=${DB_HOST:-127.0.0.1}
DB_PORT=${DB_PORT:-3306}
DB_DATABASE=${DB_DATABASE:-genesis}
DB_USERNAME=${DB_USERNAME:-root}
DB_PASSWORD=${DB_PASSWORD:-}

# Redis Configuration
REDIS_HOST=${REDIS_HOST:-127.0.0.1}
REDIS_PASSWORD=null
REDIS_PORT=${REDIS_PORT:-6379}

# Cache Configuration
CACHE_DRIVER=${CACHE_DRIVER:-redis}
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120

# Performance Settings
GENESIS_LAG_STABILITY_TARGET=0.986
GENESIS_RCR_ACCURACY_TARGET=0.986
GENESIS_RESPONSE_TIME_TARGET=150
GENESIS_CACHE_TTL=1800

# Development Settings
TELESCOPE_ENABLED=true
DEBUGBAR_ENABLED=true
EOF

    log_success "Environment file created: $env_file"
}

# Setup Python virtual environment
setup_python_env() {
    log_header "🐍 PYTHON ENVIRONMENT"
    
    if [[ ! -d "$VENV_PATH" ]]; then
        log "Creating Python virtual environment..."
        python3 -m venv "$VENV_PATH"
    fi
    
    log "Activating virtual environment..."
    source "$VENV_PATH/bin/activate"
    
    # Upgrade pip first
    log "Upgrading pip..."
    pip install -q --upgrade pip 2>/dev/null || log_warning "Could not upgrade pip"
    
    # Install requirements - use minimal requirements only to avoid conflicts
    if [[ -f "$SCRIPT_DIR/requirements-minimal.txt" ]]; then
        log "Installing minimal Python requirements..."
        pip install -q -r "$SCRIPT_DIR/requirements-minimal.txt" 2>/dev/null || log_warning "Some minimal Python packages could not be installed - continuing anyway"
    else
        log "Installing basic Python packages..."
        # Install only essential packages directly
        pip install -q requests python-dotenv pyyaml 2>/dev/null || log_warning "Could not install basic Python packages - continuing anyway"
    fi
    
    # Skip the problematic full requirements.txt file entirely
    
    log_success "Python environment ready"
}

# Setup backend (Laravel)
setup_backend() {
    log_header "🔧 BACKEND SETUP (Laravel)"
    
    cd "$SCRIPT_DIR"
    
    # Look for Laravel backend in various locations
    local backend_dirs=("backend" "." "app" "cascade-service")
    local backend_dir=""
    
    for dir in "${backend_dirs[@]}"; do
        local check_path="$SCRIPT_DIR"
        if [[ "$dir" != "." ]]; then
            check_path="$SCRIPT_DIR/$dir"
        fi
        
        if [[ -f "$check_path/composer.json" ]]; then
            backend_dir="$dir"
            log "Found Laravel backend in: $backend_dir"
            break
        fi
    done
    
    if [[ -n "$backend_dir" ]]; then
        if [[ "$backend_dir" != "." ]]; then
            cd "$backend_dir"
        fi
        
        # Install Composer dependencies
        log "Installing Composer dependencies..."
        if command -v composer >/dev/null 2>&1; then
            composer install -q --no-interaction --prefer-dist 2>/dev/null || log_warning "Composer install failed - continuing anyway"
        else
            log_warning "Composer not found - skipping dependency installation"
        fi
        
        # Create Laravel app if artisan exists
        if [[ -f "artisan" ]]; then
            log "Setting up Laravel application..."
            
            # Copy environment file
            if [[ ! -f ".env" ]] && [[ -f "$SCRIPT_DIR/.env" ]]; then
                cp "$SCRIPT_DIR/.env" ".env"
            fi
            
            # Basic Laravel setup
            php artisan key:generate --force -q 2>/dev/null || log_warning "Could not generate app key"
            php artisan config:clear -q 2>/dev/null || true
            php artisan cache:clear -q 2>/dev/null || true
            php artisan route:clear -q 2>/dev/null || true
            php artisan view:clear -q 2>/dev/null || true
            
            # Run migrations if database is available
            log "Running database migrations..."
            php artisan migrate --force -q 2>/dev/null || log_warning "Migrations failed - database may not be accessible"
            
            # Seed database if seeder exists
            if [[ -f "database/seeders/DatabaseSeeder.php" ]]; then
                log "Seeding database..."
                php artisan db:seed --force -q 2>/dev/null || log_warning "Database seeding failed"
            fi
        else
            log_warning "No artisan file found - this may not be a Laravel project"
        fi
        
        cd "$SCRIPT_DIR"
        log_success "Backend setup completed"
    else
        log_warning "No Laravel backend found - skipping backend setup"
    fi
}

# Setup frontend
setup_frontend() {
    log_header "🎨 FRONTEND SETUP"
    
    # Check for frontend directories
    local frontend_dirs=("unified-frontend" "frontend" "client" "web")
    local frontend_dir=""
    
    for dir in "${frontend_dirs[@]}"; do
        if [[ -d "$SCRIPT_DIR/$dir" ]]; then
            frontend_dir="$dir"
            break
        fi
    done
    
    if [[ -n "$frontend_dir" ]]; then
        cd "$SCRIPT_DIR/$frontend_dir"
        
        if [[ -f "package.json" ]]; then
            log "Installing frontend dependencies in $frontend_dir..."
            
            # Use npm or pnpm based on lock file
            if [[ -f "pnpm-lock.yaml" ]]; then
                pnpm install -q 2>/dev/null || npm install -q
            else
                npm install -q
            fi
        fi
        
        cd "$SCRIPT_DIR"
        log_success "Frontend setup completed"
    else
        log_warning "No frontend directory found"
    fi
}

# Start backend server
start_backend() {
    log_header "🚀 STARTING BACKEND SERVER"
    
    cd "$SCRIPT_DIR"
    
    # Look for Laravel backend in various locations
    local backend_dirs=("backend" "." "app" "cascade-service")
    local backend_dir=""
    
    for dir in "${backend_dirs[@]}"; do
        local check_path="$SCRIPT_DIR"
        if [[ "$dir" != "." ]]; then
            check_path="$SCRIPT_DIR/$dir"
        fi
        
        if [[ -f "$check_path/artisan" ]]; then
            backend_dir="$dir"
            break
        fi
    done
    
    if [[ -n "$backend_dir" ]]; then
        if [[ "$backend_dir" != "." ]]; then
            cd "$backend_dir"
        fi
        
        local backend_port=$(find_available_port $BACKEND_PORT)
        BACKEND_PORT=$backend_port
        
        log "Starting Laravel backend on port $backend_port from directory: $backend_dir"
        
        # Ensure .env file exists
        if [[ ! -f ".env" ]] && [[ -f "$SCRIPT_DIR/.env" ]]; then
            cp "$SCRIPT_DIR/.env" ".env"
        fi
        
        # Start Laravel development server
        nohup php artisan serve --host=0.0.0.0 --port=$backend_port > "$LOG_DIR/backend.log" 2>&1 &
        local backend_pid=$!
        echo $backend_pid > "$PID_DIR/backend.pid"
        
        # Wait for backend to start
        sleep 5
        
        if kill -0 $backend_pid 2>/dev/null; then
            log_success "Backend started successfully (PID: $backend_pid, Port: $backend_port)"
        else
            log_error "Backend failed to start - check $LOG_DIR/backend.log"
            return 1
        fi
        
        cd "$SCRIPT_DIR"
    else
        log_warning "No Laravel backend found - skipping backend startup"
    fi
}

# Start frontend server
start_frontend() {
    log_header "🎨 STARTING FRONTEND SERVER"
    
    local frontend_dirs=("unified-frontend" "frontend" "client" "web")
    local frontend_dir=""
    
    for dir in "${frontend_dirs[@]}"; do
        if [[ -d "$SCRIPT_DIR/$dir" ]]; then
            frontend_dir="$dir"
            break
        fi
    done
    
    if [[ -n "$frontend_dir" ]] && [[ -f "$SCRIPT_DIR/$frontend_dir/package.json" ]]; then
        cd "$SCRIPT_DIR/$frontend_dir"
        
        local frontend_port=$(find_available_port $FRONTEND_PORT)
        FRONTEND_PORT=$frontend_port
        
        log "Starting frontend server on port $frontend_port..."
        
        # Create environment variables for frontend
        cat > .env.local << EOF
NEXT_PUBLIC_API_BASE_URL=http://localhost:$BACKEND_PORT/api
NEXT_PUBLIC_APP_URL=http://localhost:$frontend_port
PORT=$frontend_port
EOF
        
        # Start development server
        if [[ -f "pnpm-lock.yaml" ]]; then
            nohup pnpm dev > "$LOG_DIR/frontend.log" 2>&1 &
        else
            nohup npm run dev > "$LOG_DIR/frontend.log" 2>&1 &
        fi
        
        local frontend_pid=$!
        echo $frontend_pid > "$PID_DIR/frontend.pid"
        
        # Wait for frontend to start
        sleep 8
        
        if kill -0 $frontend_pid 2>/dev/null; then
            log_success "Frontend started successfully (PID: $frontend_pid, Port: $frontend_port)"
        else
            log_error "Frontend failed to start"
            return 1
        fi
        
        cd "$SCRIPT_DIR"
    else
        log_warning "No frontend found, skipping"
    fi
}

# Launch browser with clean state
launch_browser() {
    log_header "🌐 LAUNCHING INCOGNITO BROWSER"
    
    local url="http://localhost:$FRONTEND_PORT"
    
    # If no frontend, use backend URL
    if [[ ! -f "$PID_DIR/frontend.pid" ]]; then
        url="http://localhost:$BACKEND_PORT"
    fi
    
    log "Opening $url in incognito mode..."
    
    # macOS
    if command -v open >/dev/null 2>&1; then
        # Chrome incognito with fresh profile
        if [[ -d "/Applications/Google Chrome.app" ]]; then
            open -na "Google Chrome" --args \
                --incognito \
                --new-window \
                --disable-web-security \
                --disable-features=VizDisplayCompositor \
                --user-data-dir="/tmp/chrome-genesis-$(date +%s)" \
                --clear-token-service \
                --clear-key-service \
                "$url"
            log_success "Chrome incognito launched"
        # Safari private browsing
        elif [[ -d "/Applications/Safari.app" ]]; then
            osascript << EOF
                tell application "Safari"
                    activate
                    tell application "System Events" to keystroke "n" using {command down, shift down}
                    delay 1
                    set URL of document 1 to "$url"
                end tell
EOF
            log_success "Safari private browsing launched"
        else
            open "$url"
            log_warning "Default browser launched (not incognito)"
        fi
    # Linux
    elif command -v xdg-open >/dev/null 2>&1; then
        if command -v google-chrome >/dev/null 2>&1; then
            google-chrome --incognito --new-window "$url" &
            log_success "Chrome incognito launched"
        elif command -v firefox >/dev/null 2>&1; then
            firefox --private-window "$url" &
            log_success "Firefox private window launched"
        else
            xdg-open "$url" &
            log_warning "Default browser launched"
        fi
    else
        log_warning "Could not detect system browser launcher"
        echo "Please open: $url"
    fi
}

# Display status
show_status() {
    log_header "📊 GENESIS ORCHESTRATOR STATUS"
    
    echo -e "${CYAN}Project:${NC} $PROJECT_NAME"
    echo -e "${CYAN}Directory:${NC} $SCRIPT_DIR"
    echo -e "${CYAN}Environment:${NC} Development"
    echo ""
    
    # Check backend
    if [[ -f "$PID_DIR/backend.pid" ]]; then
        local backend_pid=$(cat "$PID_DIR/backend.pid")
        if kill -0 $backend_pid 2>/dev/null; then
            echo -e "${GREEN}✓ Backend:${NC} Running (PID: $backend_pid, Port: $BACKEND_PORT)"
            echo -e "  ${CYAN}URL:${NC} http://localhost:$BACKEND_PORT"
        else
            echo -e "${RED}✗ Backend:${NC} Stopped"
        fi
    else
        echo -e "${RED}✗ Backend:${NC} Not started"
    fi
    
    # Check frontend
    if [[ -f "$PID_DIR/frontend.pid" ]]; then
        local frontend_pid=$(cat "$PID_DIR/frontend.pid")
        if kill -0 $frontend_pid 2>/dev/null; then
            echo -e "${GREEN}✓ Frontend:${NC} Running (PID: $frontend_pid, Port: $FRONTEND_PORT)"
            echo -e "  ${CYAN}URL:${NC} http://localhost:$FRONTEND_PORT"
        else
            echo -e "${RED}✗ Frontend:${NC} Stopped"
        fi
    else
        echo -e "${RED}✗ Frontend:${NC} Not started"
    fi
    
    # Check database
    if [[ "${DB_CONNECTION:-mysql}" == "mysql" ]]; then
        if pgrep -x "mysqld" > /dev/null; then
            echo -e "${GREEN}✓ Database:${NC} MySQL running"
        else
            echo -e "${RED}✗ Database:${NC} MySQL not running"
        fi
    else
        echo -e "${GREEN}✓ Database:${NC} SQLite (${DB_DATABASE})"
    fi
    
    # Check Redis
    if [[ "${CACHE_DRIVER:-redis}" == "redis" ]]; then
        if pgrep -x "redis-server" > /dev/null; then
            echo -e "${GREEN}✓ Cache:${NC} Redis running"
        else
            echo -e "${RED}✗ Cache:${NC} Redis not running"
        fi
    else
        echo -e "${YELLOW}⚠ Cache:${NC} Array (development mode)"
    fi
    
    echo ""
    echo -e "${CYAN}Logs:${NC} $LOG_DIR"
    echo -e "${CYAN}PIDs:${NC} $PID_DIR"
    echo ""
    echo -e "${PURPLE}To stop all services, run:${NC} $0 stop"
    echo -e "${PURPLE}To view logs, run:${NC} tail -f $LOG_DIR/*.log"
}

# Handle stop command
stop_services() {
    cleanup
    log_success "All services stopped"
    exit 0
}

# Handle status command
show_status_only() {
    show_status
    exit 0
}

# Main execution
main() {
    # Handle command line arguments
    case "${1:-start}" in
        "stop")
            stop_services
            ;;
        "status")
            show_status_only
            ;;
        "restart")
            cleanup
            sleep 2
            # Continue to start
            ;;
        "start"|"")
            # Continue to start
            ;;
        *)
            echo "Usage: $0 {start|stop|restart|status}"
            exit 1
            ;;
    esac
    
    log_header "🚀 GENESIS ORCHESTRATOR AUTO-START"
    log "Starting comprehensive development environment..."
    
    # Setup signal handlers
    trap cleanup EXIT
    trap cleanup INT
    trap cleanup TERM
    
    # Initial cleanup
    cleanup
    sleep 1
    
    # Setup steps
    setup_database
    setup_redis
    create_env_file
    setup_python_env
    setup_backend
    setup_frontend
    
    # Start services
    start_backend
    start_frontend
    
    # Launch browser
    sleep 3
    launch_browser
    
    # Show final status
    show_status
    
    log_success "🎉 GENESIS Orchestrator is ready!"
    echo ""
    echo -e "${GREEN}Press Ctrl+C to stop all services${NC}"
    
    # Keep script running
    while true; do
        sleep 30
        # Check if services are still running
        if [[ -f "$PID_DIR/backend.pid" ]]; then
            local backend_pid=$(cat "$PID_DIR/backend.pid")
            if ! kill -0 $backend_pid 2>/dev/null; then
                log_error "Backend process died, restarting..."
                start_backend
            fi
        fi
        
        if [[ -f "$PID_DIR/frontend.pid" ]]; then
            local frontend_pid=$(cat "$PID_DIR/frontend.pid")
            if ! kill -0 $frontend_pid 2>/dev/null; then
                log_error "Frontend process died, restarting..."
                start_frontend
            fi
        fi
    done
}

# Run main function
main "$@"