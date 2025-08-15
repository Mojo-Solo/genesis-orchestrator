#!/bin/bash

# Kong API Gateway Configuration Script for GENESIS Orchestrator
# Configures advanced rate limiting, security plugins, and routing

set -euo pipefail

# Configuration
KONG_ADMIN_URL="${KONG_ADMIN_URL:-http://localhost:8001}"
KONG_ADMIN_TOKEN="${KONG_ADMIN_TOKEN:-admin_token_12345}"
BACKEND_URL="${BACKEND_URL:-http://genesis-backend-service:8080}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')] $1${NC}"
}

warn() {
    echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] WARNING: $1${NC}"
}

error() {
    echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] ERROR: $1${NC}"
}

# Function to make Kong Admin API calls
kong_api() {
    local method=$1
    local endpoint=$2
    local data=${3:-}
    
    local curl_opts=("-X" "$method" "-H" "Content-Type: application/json")
    
    if [[ -n "$KONG_ADMIN_TOKEN" ]]; then
        curl_opts+=("-H" "Kong-Admin-Token: $KONG_ADMIN_TOKEN")
    fi
    
    if [[ -n "$data" ]]; then
        curl_opts+=("-d" "$data")
    fi
    
    curl -s -w "\n%{http_code}" "${curl_opts[@]}" "$KONG_ADMIN_URL$endpoint"
}

# Check Kong connectivity
check_kong() {
    log "Checking Kong connectivity..."
    local response
    response=$(kong_api "GET" "/status")
    local status_code="${response##*$'\n'}"
    
    if [[ "$status_code" == "200" ]]; then
        log "Kong is accessible"
        return 0
    else
        error "Kong is not accessible (HTTP $status_code)"
        return 1
    fi
}

# Create service
create_service() {
    local name=$1
    local url=$2
    local path=${3:-/}
    
    log "Creating service: $name"
    
    local service_data='{
        "name": "'$name'",
        "url": "'$url'",
        "path": "'$path'",
        "protocol": "http",
        "connect_timeout": 60000,
        "write_timeout": 60000,
        "read_timeout": 60000,
        "retries": 5
    }'
    
    local response
    response=$(kong_api "POST" "/services" "$service_data")
    local status_code="${response##*$'\n'}"
    
    if [[ "$status_code" == "201" || "$status_code" == "409" ]]; then
        log "Service $name created/exists"
        return 0
    else
        error "Failed to create service $name (HTTP $status_code)"
        echo "$response"
        return 1
    fi
}

# Create route
create_route() {
    local service_name=$1
    local path=$2
    local methods=${3:-GET,POST,PUT,DELETE,PATCH}
    
    log "Creating route for service $service_name: $path"
    
    local route_data='{
        "name": "'$service_name'-route",
        "paths": ["'$path'"],
        "methods": ["'${methods//,/\"",\"}'"],
        "strip_path": false,
        "preserve_host": false
    }'
    
    local response
    response=$(kong_api "POST" "/services/$service_name/routes" "$route_data")
    local status_code="${response##*$'\n'}"
    
    if [[ "$status_code" == "201" || "$status_code" == "409" ]]; then
        log "Route created for $service_name"
        return 0
    else
        error "Failed to create route for $service_name (HTTP $status_code)"
        echo "$response"
        return 1
    fi
}

# Configure advanced rate limiting
configure_rate_limiting() {
    local service_name=$1
    local tier=${2:-free}
    
    log "Configuring advanced rate limiting for $service_name (tier: $tier)"
    
    # Tier-based limits
    local limits
    case $tier in
        "free")
            limits='{
                "minute": 100,
                "hour": 1000,
                "day": 10000
            }'
            ;;
        "starter")
            limits='{
                "minute": 500,
                "hour": 5000,
                "day": 50000
            }'
            ;;
        "professional")
            limits='{
                "minute": 2000,
                "hour": 20000,
                "day": 200000
            }'
            ;;
        "enterprise")
            limits='{
                "minute": 10000,
                "hour": 100000,
                "day": 1000000
            }'
            ;;
        *)
            limits='{
                "minute": 100,
                "hour": 1000,
                "day": 10000
            }'
            ;;
    esac
    
    local plugin_data='{
        "name": "rate-limiting-advanced",
        "config": {
            "limit": ['$limits'],
            "window_size": [60, 3600, 86400],
            "identifier": "consumer",
            "sync_rate": 10,
            "strategy": "cluster",
            "dictionary_name": "kong_rate_limiting_counters",
            "redis": {
                "host": "redis-service",
                "port": 6379,
                "timeout": 2000,
                "password": null,
                "database": 1
            },
            "hide_client_headers": false,
            "enforce_consumer_groups": true,
            "consumer_groups": null,
            "disable_penalty": false,
            "error_code": 429,
            "error_message": "API rate limit exceeded"
        }
    }'
    
    local response
    response=$(kong_api "POST" "/services/$service_name/plugins" "$plugin_data")
    local status_code="${response##*$'\n'}"
    
    if [[ "$status_code" == "201" || "$status_code" == "409" ]]; then
        log "Rate limiting configured for $service_name"
        return 0
    else
        error "Failed to configure rate limiting for $service_name (HTTP $status_code)"
        echo "$response"
        return 1
    fi
}

# Configure bot detection
configure_bot_detection() {
    local service_name=$1
    
    log "Configuring bot detection for $service_name"
    
    local plugin_data='{
        "name": "bot-detection",
        "config": {
            "whitelist": [],
            "blacklist": [
                "curl",
                "wget",
                "scrapy",
                "python-requests",
                "bot",
                "crawler",
                "spider",
                "scraper"
            ]
        }
    }'
    
    local response
    response=$(kong_api "POST" "/services/$service_name/plugins" "$plugin_data")
    local status_code="${response##*$'\n'}"
    
    if [[ "$status_code" == "201" || "$status_code" == "409" ]]; then
        log "Bot detection configured for $service_name"
        return 0
    else
        warn "Failed to configure bot detection for $service_name (HTTP $status_code)"
        echo "$response"
        return 0  # Non-critical failure
    fi
}

# Configure IP restriction
configure_ip_restriction() {
    local service_name=$1
    local allowed_ips=${2:-}
    local denied_ips=${3:-}
    
    if [[ -z "$allowed_ips" && -z "$denied_ips" ]]; then
        log "Skipping IP restriction for $service_name (no IPs specified)"
        return 0
    fi
    
    log "Configuring IP restriction for $service_name"
    
    local config='{'
    if [[ -n "$allowed_ips" ]]; then
        config+='"allow": ["'${allowed_ips//,/\"",\"}'"],'
    fi
    if [[ -n "$denied_ips" ]]; then
        config+='"deny": ["'${denied_ips//,/\"",\"}'"],'
    fi
    config=${config%,}  # Remove trailing comma
    config+='}'
    
    local plugin_data='{
        "name": "ip-restriction",
        "config": '$config'
    }'
    
    local response
    response=$(kong_api "POST" "/services/$service_name/plugins" "$plugin_data")
    local status_code="${response##*$'\n'}"
    
    if [[ "$status_code" == "201" || "$status_code" == "409" ]]; then
        log "IP restriction configured for $service_name"
        return 0
    else
        warn "Failed to configure IP restriction for $service_name (HTTP $status_code)"
        echo "$response"
        return 0  # Non-critical failure
    fi
}

# Configure CORS
configure_cors() {
    local service_name=$1
    
    log "Configuring CORS for $service_name"
    
    local plugin_data='{
        "name": "cors",
        "config": {
            "origins": ["*"],
            "methods": ["GET", "POST", "PUT", "DELETE", "PATCH", "OPTIONS"],
            "headers": ["Accept", "Accept-Version", "Content-Length", "Content-MD5", "Content-Type", "Date", "X-Auth-Token", "X-API-Key", "Authorization", "X-Tenant-ID", "X-Request-ID"],
            "exposed_headers": ["X-Auth-Token", "X-RateLimit-Limit", "X-RateLimit-Remaining", "X-RateLimit-Reset"],
            "credentials": true,
            "max_age": 3600,
            "preflight_continue": false
        }
    }'
    
    local response
    response=$(kong_api "POST" "/services/$service_name/plugins" "$plugin_data")
    local status_code="${response##*$'\n'}"
    
    if [[ "$status_code" == "201" || "$status_code" == "409" ]]; then
        log "CORS configured for $service_name"
        return 0
    else
        warn "Failed to configure CORS for $service_name (HTTP $status_code)"
        echo "$response"
        return 0  # Non-critical failure
    fi
}

# Configure request/response transformation
configure_transformation() {
    local service_name=$1
    
    log "Configuring request/response transformation for $service_name"
    
    # Request transformer
    local request_plugin='{
        "name": "request-transformer",
        "config": {
            "add": {
                "headers": ["X-Forwarded-Proto:https", "X-Gateway:kong"]
            },
            "remove": {
                "headers": ["X-Internal-Header"]
            }
        }
    }'
    
    # Response transformer  
    local response_plugin='{
        "name": "response-transformer",
        "config": {
            "add": {
                "headers": ["X-Powered-By:GENESIS-Orchestrator", "X-Gateway:kong"]
            },
            "remove": {
                "headers": ["Server", "X-Powered-By"]
            }
        }
    }'
    
    # Apply request transformer
    local response
    response=$(kong_api "POST" "/services/$service_name/plugins" "$request_plugin")
    local status_code="${response##*$'\n'}"
    
    if [[ "$status_code" == "201" || "$status_code" == "409" ]]; then
        log "Request transformer configured for $service_name"
    else
        warn "Failed to configure request transformer for $service_name"
    fi
    
    # Apply response transformer
    response=$(kong_api "POST" "/services/$service_name/plugins" "$response_plugin")
    status_code="${response##*$'\n'}"
    
    if [[ "$status_code" == "201" || "$status_code" == "409" ]]; then
        log "Response transformer configured for $service_name"
    else
        warn "Failed to configure response transformer for $service_name"
    fi
}

# Configure Prometheus metrics
configure_prometheus() {
    log "Configuring Prometheus metrics"
    
    local plugin_data='{
        "name": "prometheus",
        "config": {
            "per_consumer": true,
            "status_code_metrics": true,
            "latency_metrics": true,
            "bandwidth_metrics": true,
            "upstream_health_metrics": true
        }
    }'
    
    local response
    response=$(kong_api "POST" "/plugins" "$plugin_data")
    local status_code="${response##*$'\n'}"
    
    if [[ "$status_code" == "201" || "$status_code" == "409" ]]; then
        log "Prometheus metrics configured globally"
        return 0
    else
        warn "Failed to configure Prometheus metrics (HTTP $status_code)"
        echo "$response"
        return 0  # Non-critical failure
    fi
}

# Configure caching
configure_caching() {
    local service_name=$1
    local cache_ttl=${2:-300}  # 5 minutes default
    
    log "Configuring proxy caching for $service_name"
    
    local plugin_data='{
        "name": "proxy-cache",
        "config": {
            "response_code": [200, 301, 404],
            "request_method": ["GET", "HEAD"],
            "content_type": ["text/plain", "application/json"],
            "cache_ttl": '$cache_ttl',
            "strategy": "memory"
        }
    }'
    
    local response
    response=$(kong_api "POST" "/services/$service_name/plugins" "$plugin_data")
    local status_code="${response##*$'\n'}"
    
    if [[ "$status_code" == "201" || "$status_code" == "409" ]]; then
        log "Proxy caching configured for $service_name"
        return 0
    else
        warn "Failed to configure proxy caching for $service_name (HTTP $status_code)"
        echo "$response"
        return 0  # Non-critical failure
    fi
}

# Create consumer groups for different tiers
create_consumer_groups() {
    log "Creating consumer groups for different tiers"
    
    local tiers=("free" "starter" "professional" "enterprise")
    
    for tier in "${tiers[@]}"; do
        log "Creating consumer group: $tier"
        
        local group_data='{
            "name": "'$tier'",
            "tags": ["tier:'$tier'"]
        }'
        
        local response
        response=$(kong_api "POST" "/consumer_groups" "$group_data")
        local status_code="${response##*$'\n'}"
        
        if [[ "$status_code" == "201" || "$status_code" == "409" ]]; then
            log "Consumer group $tier created/exists"
        else
            warn "Failed to create consumer group $tier (HTTP $status_code)"
        fi
    done
}

# Main configuration function
main() {
    log "Starting Kong configuration for GENESIS Orchestrator"
    
    # Check Kong connectivity
    if ! check_kong; then
        error "Cannot connect to Kong. Exiting."
        exit 1
    fi
    
    # Create consumer groups
    create_consumer_groups
    
    # Configure global plugins
    configure_prometheus
    
    # API Services Configuration
    declare -A services=(
        ["genesis-api"]="/api/v1"
        ["genesis-orchestration"]="/api/v1/orchestration"
        ["genesis-agents"]="/api/v1/agents"
        ["genesis-security"]="/api/v1/security"
        ["genesis-webhooks"]="/webhooks"
        ["genesis-health"]="/health"
    )
    
    # Create services and routes
    for service_name in "${!services[@]}"; do
        path="${services[$service_name]}"
        
        # Create service
        create_service "$service_name" "$BACKEND_URL" "$path"
        
        # Create route
        create_route "$service_name" "$path"
        
        # Configure plugins based on service type
        case $service_name in
            "genesis-api"|"genesis-orchestration")
                configure_rate_limiting "$service_name" "professional"
                configure_bot_detection "$service_name"
                configure_cors "$service_name"
                configure_transformation "$service_name"
                configure_caching "$service_name" 60  # 1 minute cache
                ;;
            "genesis-agents")
                configure_rate_limiting "$service_name" "enterprise"
                configure_bot_detection "$service_name"
                configure_cors "$service_name"
                configure_transformation "$service_name"
                ;;
            "genesis-security")
                configure_rate_limiting "$service_name" "starter"
                configure_bot_detection "$service_name"
                configure_cors "$service_name"
                # No caching for security endpoints
                ;;
            "genesis-webhooks")
                configure_rate_limiting "$service_name" "free"
                configure_bot_detection "$service_name"
                # Webhook-specific configuration
                ;;
            "genesis-health")
                # Minimal configuration for health checks
                configure_cors "$service_name"
                configure_caching "$service_name" 30  # 30 second cache
                ;;
        esac
    done
    
    log "Kong configuration completed successfully!"
    log "Kong Admin URL: $KONG_ADMIN_URL"
    log "Kong Proxy URL: http://localhost:8000"
}

# Script options
case "${1:-}" in
    "health")
        check_kong
        ;;
    "services")
        kong_api "GET" "/services" | jq .
        ;;
    "routes")
        kong_api "GET" "/routes" | jq .
        ;;
    "plugins")
        kong_api "GET" "/plugins" | jq .
        ;;
    "consumers")
        kong_api "GET" "/consumers" | jq .
        ;;
    "reset")
        warn "Resetting Kong configuration..."
        read -p "Are you sure? (y/N): " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            # Delete all configurations (be careful!)
            kong_api "DELETE" "/routes" >/dev/null 2>&1 || true
            kong_api "DELETE" "/services" >/dev/null 2>&1 || true
            kong_api "DELETE" "/plugins" >/dev/null 2>&1 || true
            log "Kong configuration reset"
        fi
        ;;
    *)
        main
        ;;
esac