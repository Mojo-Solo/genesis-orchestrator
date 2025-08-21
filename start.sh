#!/bin/bash

# GENESIS Orchestrator + RAG Stack - Enhanced Start Script
# Now includes production-ready RAG capabilities with MCP server

set -uo pipefail

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
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

log_rag() {
    echo -e "${PURPLE}[$(date +'%H:%M:%S')] 🧠${NC} $1"
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
    for port in 3000 8000 8810 8811 8812; do
        local pids=$(lsof -ti:$port 2>/dev/null || echo "")
        if [[ -n "$pids" ]]; then
            echo "$pids" | xargs -r kill -9 2>/dev/null || true
        fi
    done
}

trap cleanup EXIT INT TERM

echo "🚀 GENESIS Orchestrator + RAG Stack"
echo "===================================="
echo "🧠 Production-ready RAG with MCP server"
echo "🔍 Semantic search + LLM re-ranking"
echo "🔒 Security guard + evaluation metrics"
echo ""

# Create enhanced .env file with RAG capabilities
log "Creating enhanced environment configuration..."
cat > "$SCRIPT_DIR/.env" << 'EOF'
# GENESIS Core
APP_NAME="GENESIS Orchestrator"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=sqlite
DB_DATABASE=/tmp/genesis.sqlite

# Cache & Queue
CACHE_DRIVER=array
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
LOG_CHANNEL=stack
LOG_LEVEL=debug

# RAG Stack Configuration
VECTOR_BACKEND=pgvector
ENABLE_RERANK=false
RERANK_MODEL=gpt-4o-mini

# API Keys (Set these for full functionality)
# OPENAI_API_KEY=your-openai-api-key-here
# SUPABASE_URL=your-supabase-url-here
# SUPABASE_SERVICE_ROLE_KEY=your-supabase-service-role-key-here
# OPENAI_ORG_ID=your-openai-org-id-here
# OPENAI_PROJECT_ID=your-openai-project-id-here
# CONNECTOR_TOKEN=your-connector-token-here
# PINECONE_API_KEY=your-pinecone-api-key-here
# PINECONE_INDEX=your-pinecone-index-name
EOF

# Check for dependencies
log "Checking Node.js and npm..."
if ! command -v node >/dev/null 2>&1; then
    log_warning "Node.js not found - some features may not work"
else
    log_success "Node.js $(node --version) found"
fi

if ! command -v npm >/dev/null 2>&1; then
    log_warning "npm not found - some features may not work"
else
    log_success "npm $(npm --version) found"
fi

# Install dependencies if package.json exists
if [[ -f "$SCRIPT_DIR/package.json" ]]; then
    log_rag "Installing RAG stack dependencies..."
    if npm install --silent 2>/dev/null; then
        log_success "RAG dependencies installed"
    else
        log_warning "Some dependencies failed to install - RAG features may be limited"
    fi
fi

# Check for Laravel backend
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

# Start RAG Stack (Next.js with MCP server)
log_rag "Starting RAG Stack with MCP server..."
rag_found=false

if [[ -f "$SCRIPT_DIR/package.json" ]] && command -v npm >/dev/null 2>&1; then
    log_rag "Starting Next.js with MCP endpoints..."
    
    # Start Next.js dev server with RAG capabilities
    nohup npm run dev > "$LOG_DIR/rag-stack.log" 2>&1 &
    rag_pid=$!
    echo $rag_pid > "$PID_DIR/rag-stack.pid"
    
    sleep 8  # Give Next.js more time to start
    
    if kill -0 $rag_pid 2>/dev/null; then
        log_success "✅ RAG Stack started (PID: $rag_pid)"
        log_success "🧠 MCP Server: http://localhost:3000/api/mcp"
        log_success "🌐 Frontend: http://localhost:3000"
        rag_found=true
        
        # Test MCP endpoint
        sleep 3
        if curl -s "http://localhost:3000/api/mcp" >/dev/null 2>&1; then
            log_success "🔍 MCP endpoint responding"
        else
            log_warning "MCP endpoint not responding yet"
        fi
    else
        log_warning "RAG Stack failed to start - check $LOG_DIR/rag-stack.log"
    fi
fi

# Look for additional frontend
log "Looking for additional frontend..."
frontend_found=false

# Check for Cothinkr (which seems to have working package.json)
if [[ -d "$SCRIPT_DIR/cothinkr" && -f "$SCRIPT_DIR/cothinkr/package.json" ]]; then
    cd "$SCRIPT_DIR/cothinkr"
    log "Found Cothinkr frontend, starting on port 4000..."
    
    # Quick npm install without failing
    npm install -q 2>/dev/null || log_warning "npm install failed, trying anyway..."
    
    # Start dev server on different port
    PORT=4000 nohup npm run dev > "$LOG_DIR/frontend.log" 2>&1 &
    frontend_pid=$!
    echo $frontend_pid > "$PID_DIR/frontend.pid"
    
    sleep 5
    
    if kill -0 $frontend_pid 2>/dev/null; then
        log_success "✅ Additional Frontend started (PID: $frontend_pid)"
        log_success "🌐 Additional Frontend URL: http://localhost:4000"
        frontend_found=true
    else
        log_warning "Additional Frontend failed to start - check $LOG_DIR/frontend.log"
    fi
    
    cd "$SCRIPT_DIR"
fi

# Launch browser - prioritize RAG stack
sleep 2
if [[ "$rag_found" == "true" ]]; then
    url="http://localhost:3000"
    log_rag "RAG Stack is primary interface"
elif [[ "$frontend_found" == "true" ]]; then
    url="http://localhost:4000"
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
echo "📊 GENESIS ORCHESTRATOR + RAG STACK STATUS"
echo "=========================================="

if [[ "$backend_found" == "true" ]]; then
    echo "✅ Backend: Running on http://localhost:8000"
else
    echo "❌ Backend: Not running"
fi

if [[ "$rag_found" == "true" ]]; then
    echo "✅ RAG Stack: Running on http://localhost:3000"
    echo "🧠 MCP Server: http://localhost:3000/api/mcp"
    echo "🔍 Tools: search (semantic) + fetch (full content)"
    echo "🔒 Security: Bearer token authentication ready"
    echo "📊 Evaluation: npm run eval"
    echo "📚 Ingestion: npm run ingest"
else
    echo "❌ RAG Stack: Not running"
fi

if [[ "$frontend_found" == "true" ]]; then
    echo "✅ Additional Frontend: Running on http://localhost:4000"
else
    echo "❌ Additional Frontend: Not running"
fi

echo ""
echo "🧠 RAG CAPABILITIES:"
echo "   - Heading-aware chunking for better context"
echo "   - Switchable pgvector/Pinecone backends"
echo "   - Optional LLM re-ranking for precision"
echo "   - Comprehensive evaluation metrics"
echo "   - ChatGPT Research integration ready"
echo ""
echo "📁 Logs: $LOG_DIR"
echo "🔍 PIDs: $PID_DIR"
echo "📖 Documentation: RAG_UPGRADE_COMPLETE.md"
echo ""
echo "🎉 GENESIS Orchestrator + RAG Stack is ready!"
echo "Press Ctrl+C to stop all services"

# Show useful commands
echo ""
echo "🔧 USEFUL COMMANDS:"
echo "   Test RAG system: node test-rag-system.js"
echo "   Ingest documents: npm run ingest"
echo "   Run evaluations: npm run eval"
echo "   Test MCP handler: node test-mcp-handler.js"

# Keep script running
while true; do
    sleep 60
done