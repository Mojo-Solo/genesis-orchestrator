#!/bin/bash

# GENESIS Orchestrator - Automated Production Deployment
# Phase 4.2: Zero-downtime deployment with rollback capability
# Target: 99.9% deployment success rate, <30s downtime

set -euo pipefail

# Configuration
DEPLOYMENT_CONFIG_FILE="${DEPLOYMENT_CONFIG_FILE:-deployment/config/production.yaml}"
ROLLBACK_ENABLED="${ROLLBACK_ENABLED:-true}"
HEALTH_CHECK_TIMEOUT="${HEALTH_CHECK_TIMEOUT:-300}"
DEPLOYMENT_TIMEOUT="${DEPLOYMENT_TIMEOUT:-600}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-7}"

# Colors and logging
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

log() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')] ✓${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] ⚠${NC} $1"
}

log_error() {
    echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] ✗${NC} $1"
}

# Deployment metadata
DEPLOYMENT_ID="genesis-$(date +%Y%m%d-%H%M%S)-$(git rev-parse --short HEAD)"
DEPLOYMENT_START_TIME=$(date +%s)
ROLLBACK_POINT=""

# Pre-deployment validation
validate_environment() {
    log "Validating deployment environment..."
    
    # Check required tools
    local required_tools=("git" "docker" "kubectl" "helm" "mysql" "redis-cli")
    for tool in "${required_tools[@]}"; do
        if ! command -v "$tool" &> /dev/null; then
            log_error "Required tool not found: $tool"
            exit 1
        fi
    done
    
    # Validate configuration
    if [[ ! -f "$DEPLOYMENT_CONFIG_FILE" ]]; then
        log_error "Deployment configuration not found: $DEPLOYMENT_CONFIG_FILE"
        exit 1
    fi
    
    # Check Git status
    if [[ -n $(git status --porcelain) ]]; then
        log_warning "Working directory not clean. Uncommitted changes detected."
        read -p "Continue anyway? (y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            exit 1
        fi
    fi
    
    # Validate branch
    local current_branch=$(git branch --show-current)
    if [[ "$current_branch" != "main" && "$current_branch" != "production" ]]; then
        log_warning "Deploying from non-production branch: $current_branch"
        read -p "Continue? (y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            exit 1
        fi
    fi
    
    log_success "Environment validation completed"
}

# Database backup and migration
backup_database() {
    log "Creating database backup..."
    
    local backup_file="deployment/backups/db-backup-${DEPLOYMENT_ID}.sql"
    mkdir -p "$(dirname "$backup_file")"
    
    # Create compressed backup
    mysqldump \
        --host="${DB_HOST:-localhost}" \
        --port="${DB_PORT:-3306}" \
        --user="${DB_USERNAME}" \
        --password="${DB_PASSWORD}" \
        --single-transaction \
        --routines \
        --triggers \
        --events \
        --add-drop-database \
        --databases "${DB_DATABASE}" | gzip > "${backup_file}.gz"
    
    if [[ ${PIPESTATUS[0]} -eq 0 ]]; then
        log_success "Database backup created: ${backup_file}.gz"
        echo "$backup_file.gz" > deployment/backups/latest-backup.txt
    else
        log_error "Database backup failed"
        exit 1
    fi
}

# Run database migrations with validation
migrate_database() {
    log "Running database migrations..."
    
    # Test migrations in dry-run mode first
    php artisan migrate --dry-run --no-interaction
    if [[ $? -ne 0 ]]; then
        log_error "Migration dry-run failed"
        exit 1
    fi
    
    # Run actual migrations
    php artisan migrate --force --no-interaction
    if [[ $? -eq 0 ]]; then
        log_success "Database migrations completed"
    else
        log_error "Database migration failed"
        rollback_deployment
        exit 1
    fi
}

# Build and prepare application
build_application() {
    log "Building application..."
    
    # Backend: Install dependencies and optimize
    log "Building Laravel backend..."
    composer install --no-dev --optimize-autoloader --no-interaction
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
    
    # Frontend: Build production assets
    log "Building Next.js frontend..."
    cd unified-frontend
    npm ci --production=false
    npm run build
    npm prune --production
    cd ..
    
    # Generate deployment manifest
    cat > deployment/manifest.json << EOF
{
    "deployment_id": "$DEPLOYMENT_ID",
    "timestamp": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "git_commit": "$(git rev-parse HEAD)",
    "git_branch": "$(git branch --show-current)",
    "build_number": "${BUILD_NUMBER:-local}",
    "environment": "${DEPLOYMENT_ENV:-production}",
    "components": {
        "backend": {
            "version": "$(php artisan --version | cut -d' ' -f3)",
            "composer_hash": "$(md5sum composer.lock | cut -d' ' -f1)"
        },
        "frontend": {
            "version": "$(cd unified-frontend && npm list --depth=0 --json | jq -r '.version // "unknown"')",
            "package_hash": "$(md5sum unified-frontend/package-lock.json | cut -d' ' -f1)"
        }
    }
}
EOF
    
    log_success "Application build completed"
}

# Health check functions
check_application_health() {
    local service_url="$1"
    local max_attempts="${2:-30}"
    local attempt=1
    
    log "Checking application health: $service_url"
    
    while [[ $attempt -le $max_attempts ]]; do
        if curl -sf --max-time 10 "$service_url/api/health" > /dev/null 2>&1; then
            log_success "Health check passed (attempt $attempt/$max_attempts)"
            return 0
        fi
        
        log "Health check failed (attempt $attempt/$max_attempts). Retrying in 10s..."
        sleep 10
        ((attempt++))
    done
    
    log_error "Health check failed after $max_attempts attempts"
    return 1
}

# Blue-Green deployment strategy
deploy_blue_green() {
    log "Starting blue-green deployment..."
    
    # Determine current and target environments
    local current_env=$(kubectl get service genesis-orchestrator -o jsonpath='{.spec.selector.version}' 2>/dev/null || echo "green")
    local target_env="blue"
    if [[ "$current_env" == "blue" ]]; then
        target_env="green"
    fi
    
    log "Current environment: $current_env, Target environment: $target_env"
    
    # Deploy to target environment
    log "Deploying to $target_env environment..."
    
    # Update Kubernetes deployment
    kubectl set image deployment/genesis-orchestrator-$target_env \
        app=genesis-orchestrator:$DEPLOYMENT_ID \
        --record
    
    # Wait for rollout
    kubectl rollout status deployment/genesis-orchestrator-$target_env \
        --timeout=${DEPLOYMENT_TIMEOUT}s
    
    if [[ $? -ne 0 ]]; then
        log_error "Deployment rollout failed"
        return 1
    fi
    
    # Get target environment service URL
    local target_url="https://genesis-orchestrator-$target_env.production.domain.com"
    
    # Health check on target environment
    if ! check_application_health "$target_url"; then
        log_error "Health check failed on target environment"
        return 1
    fi
    
    # Performance validation
    log "Running performance validation..."
    if ! validate_performance "$target_url"; then
        log_error "Performance validation failed"
        return 1
    fi
    
    # Switch traffic to new environment
    log "Switching traffic to $target_env environment..."
    kubectl patch service genesis-orchestrator \
        -p '{"spec":{"selector":{"version":"'$target_env'"}}}'
    
    # Verify traffic switch
    sleep 30
    if ! check_application_health "https://genesis-orchestrator.production.domain.com"; then
        log_error "Traffic switch validation failed"
        # Emergency rollback
        kubectl patch service genesis-orchestrator \
            -p '{"spec":{"selector":{"version":"'$current_env'"}}}'
        return 1
    fi
    
    # Store rollback point
    ROLLBACK_POINT="$current_env"
    log_success "Blue-green deployment completed successfully"
    return 0
}

# Performance validation
validate_performance() {
    local service_url="$1"
    log "Validating performance metrics..."
    
    # Run performance test suite
    local response_times=()
    local success_count=0
    local total_requests=50
    
    for ((i=1; i<=total_requests; i++)); do
        local start_time=$(date +%s%N)
        if curl -sf --max-time 5 "$service_url/api/health" > /dev/null 2>&1; then
            local end_time=$(date +%s%N)
            local response_time=$(((end_time - start_time) / 1000000)) # Convert to milliseconds
            response_times+=($response_time)
            ((success_count++))
        fi
    done
    
    # Calculate metrics
    local success_rate=$((success_count * 100 / total_requests))
    local avg_response_time=$(IFS=+; echo "scale=2; (${response_times[*]}) / ${#response_times[@]}" | bc)
    
    log "Performance Results: Success Rate: ${success_rate}%, Avg Response Time: ${avg_response_time}ms"
    
    # Validate against targets
    if [[ $success_rate -lt 95 ]]; then
        log_error "Success rate below threshold: ${success_rate}% < 95%"
        return 1
    fi
    
    if [[ $(echo "$avg_response_time > 150" | bc) -eq 1 ]]; then
        log_error "Average response time above threshold: ${avg_response_time}ms > 150ms"
        return 1
    fi
    
    log_success "Performance validation passed"
    return 0
}

# Rollback functionality
rollback_deployment() {
    if [[ "$ROLLBACK_ENABLED" != "true" ]]; then
        log_warning "Rollback disabled. Manual intervention required."
        return 1
    fi
    
    log_error "Initiating automatic rollback..."
    
    if [[ -n "$ROLLBACK_POINT" ]]; then
        # Switch traffic back
        kubectl patch service genesis-orchestrator \
            -p '{"spec":{"selector":{"version":"'$ROLLBACK_POINT'"}}}'
        
        log_success "Traffic switched back to $ROLLBACK_POINT"
    fi
    
    # Restore database if backup exists
    local latest_backup=$(cat deployment/backups/latest-backup.txt 2>/dev/null || echo "")
    if [[ -n "$latest_backup" && -f "$latest_backup" ]]; then
        log "Restoring database from backup: $latest_backup"
        zcat "$latest_backup" | mysql \
            --host="${DB_HOST:-localhost}" \
            --port="${DB_PORT:-3306}" \
            --user="${DB_USERNAME}" \
            --password="${DB_PASSWORD}"
        
        if [[ $? -eq 0 ]]; then
            log_success "Database restored from backup"
        else
            log_error "Database restore failed"
        fi
    fi
    
    log_success "Rollback completed"
}

# Cleanup old deployments and backups
cleanup_old_deployments() {
    log "Cleaning up old deployments and backups..."
    
    # Remove old backups
    find deployment/backups -name "*.sql.gz" -mtime +$BACKUP_RETENTION_DAYS -delete
    
    # Remove old Docker images
    docker image prune -f --filter "until=${BACKUP_RETENTION_DAYS * 24}h"
    
    log_success "Cleanup completed"
}

# Post-deployment verification
post_deployment_verification() {
    log "Running post-deployment verification..."
    
    # Verify all endpoints
    local endpoints=(
        "/api/health"
        "/api/v1/orchestration/status"
        "/api/v1/lag/health"
        "/api/v1/rcr/health"
    )
    
    for endpoint in "${endpoints[@]}"; do
        if ! curl -sf --max-time 10 "https://genesis-orchestrator.production.domain.com$endpoint" > /dev/null 2>&1; then
            log_error "Endpoint verification failed: $endpoint"
            return 1
        fi
    done
    
    # Verify database connectivity
    if ! php artisan tinker --execute="DB::connection()->getPdo();"; then
        log_error "Database connectivity verification failed"
        return 1
    fi
    
    # Verify cache connectivity
    if ! php artisan tinker --execute="Cache::store('redis')->put('test', 'value', 10);"; then
        log_error "Cache connectivity verification failed"
        return 1
    fi
    
    log_success "Post-deployment verification completed"
    return 0
}

# Generate deployment report
generate_deployment_report() {
    local deployment_end_time=$(date +%s)
    local deployment_duration=$((deployment_end_time - DEPLOYMENT_START_TIME))
    
    cat > "deployment/reports/deployment-report-${DEPLOYMENT_ID}.json" << EOF
{
    "deployment_id": "$DEPLOYMENT_ID",
    "status": "success",
    "start_time": "$DEPLOYMENT_START_TIME",
    "end_time": "$deployment_end_time",
    "duration_seconds": $deployment_duration,
    "git_commit": "$(git rev-parse HEAD)",
    "git_branch": "$(git branch --show-current)",
    "environment": "${DEPLOYMENT_ENV:-production}",
    "metrics": {
        "database_backup_size": "$(du -h deployment/backups/db-backup-${DEPLOYMENT_ID}.sql.gz 2>/dev/null | cut -f1 || echo 'N/A')",
        "deployment_strategy": "blue_green",
        "rollback_point": "$ROLLBACK_POINT"
    },
    "verification": {
        "health_checks": "passed",
        "performance_tests": "passed",
        "endpoint_verification": "passed"
    }
}
EOF
    
    log_success "Deployment report generated: deployment/reports/deployment-report-${DEPLOYMENT_ID}.json"
    log_success "🎉 Deployment $DEPLOYMENT_ID completed successfully in ${deployment_duration}s"
}

# Error handler
handle_error() {
    local exit_code=$?
    log_error "Deployment failed with exit code: $exit_code"
    
    if [[ "$ROLLBACK_ENABLED" == "true" ]]; then
        rollback_deployment
    fi
    
    # Generate failure report
    cat > "deployment/reports/deployment-failure-${DEPLOYMENT_ID}.json" << EOF
{
    "deployment_id": "$DEPLOYMENT_ID",
    "status": "failed",
    "exit_code": $exit_code,
    "failure_time": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "rollback_executed": $([ "$ROLLBACK_ENABLED" == "true" ] && echo "true" || echo "false")
}
EOF
    
    exit $exit_code
}

# Main deployment flow
main() {
    trap handle_error ERR
    
    log "🚀 Starting GENESIS Orchestrator deployment: $DEPLOYMENT_ID"
    
    # Create necessary directories
    mkdir -p deployment/{backups,reports,logs}
    
    # Execute deployment phases
    validate_environment
    backup_database
    build_application
    migrate_database
    deploy_blue_green
    post_deployment_verification
    cleanup_old_deployments
    generate_deployment_report
    
    log_success "🎉 Deployment completed successfully!"
}

# Run main function if script is executed directly
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi