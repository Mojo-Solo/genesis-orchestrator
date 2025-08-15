#!/bin/bash
# GENESIS Orchestrator Rollback Script
# Comprehensive rollback functionality with automatic failure recovery

set -euo pipefail

# Script configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
LOG_FILE="/tmp/genesis-rollback-${TIMESTAMP}.log"

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging functions
log() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')] $1${NC}" | tee -a "$LOG_FILE"
}

warn() {
    echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] WARNING: $1${NC}" | tee -a "$LOG_FILE"
}

error() {
    echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] ERROR: $1${NC}" | tee -a "$LOG_FILE"
}

success() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')] SUCCESS: $1${NC}" | tee -a "$LOG_FILE"
}

# Default configuration
ENVIRONMENT="staging"
NAMESPACE="genesis-orchestrator"
REVISION=""
ROLLBACK_TYPE="auto"
DRY_RUN=false
SKIP_DATABASE=false
SKIP_VERIFICATION=false
TIMEOUT=600
EMERGENCY_MODE=false
RESTORE_DATABASE=false

# Display usage information
usage() {
    cat << EOF
GENESIS Orchestrator Rollback Script

USAGE:
    $0 [OPTIONS]

OPTIONS:
    -e, --environment ENV       Target environment (development|staging|production) [default: staging]
    -r, --revision REV          Specific revision to rollback to (default: previous)
    -t, --type TYPE            Rollback type (auto|manual|emergency) [default: auto]
    -n, --namespace NAMESPACE   Kubernetes namespace [default: genesis-orchestrator]
    -d, --dry-run              Perform dry run without actual rollback
    --skip-database            Skip database rollback
    --skip-verification        Skip post-rollback verification
    --restore-database         Restore database from backup
    --emergency                Emergency rollback mode (fast, minimal checks)
    --timeout SECONDS          Rollback timeout in seconds [default: 600]
    -h, --help                 Show this help message

ROLLBACK TYPES:
    auto        Automatic rollback to previous working version
    manual      Manual rollback to specified revision
    emergency   Emergency rollback with minimal checks

EXAMPLES:
    # Automatic rollback to previous version
    $0 --environment staging --type auto

    # Manual rollback to specific revision
    $0 --environment production --type manual --revision 123

    # Emergency rollback with database restore
    $0 --environment production --emergency --restore-database

    # Dry run rollback
    $0 --environment staging --dry-run

EOF
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -e|--environment)
            ENVIRONMENT="$2"
            shift 2
            ;;
        -r|--revision)
            REVISION="$2"
            ROLLBACK_TYPE="manual"
            shift 2
            ;;
        -t|--type)
            ROLLBACK_TYPE="$2"
            shift 2
            ;;
        -n|--namespace)
            NAMESPACE="$2"
            shift 2
            ;;
        -d|--dry-run)
            DRY_RUN=true
            shift
            ;;
        --skip-database)
            SKIP_DATABASE=true
            shift
            ;;
        --skip-verification)
            SKIP_VERIFICATION=true
            shift
            ;;
        --restore-database)
            RESTORE_DATABASE=true
            shift
            ;;
        --emergency)
            EMERGENCY_MODE=true
            ROLLBACK_TYPE="emergency"
            shift
            ;;
        --timeout)
            TIMEOUT="$2"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            error "Unknown option: $1"
            usage
            exit 1
            ;;
    esac
done

# Validate environment
if [[ ! "$ENVIRONMENT" =~ ^(development|staging|production)$ ]]; then
    error "Invalid environment: $ENVIRONMENT"
    exit 1
fi

# Validate rollback type
if [[ ! "$ROLLBACK_TYPE" =~ ^(auto|manual|emergency)$ ]]; then
    error "Invalid rollback type: $ROLLBACK_TYPE"
    exit 1
fi

# Emergency mode adjustments
if [[ "$EMERGENCY_MODE" == "true" ]]; then
    SKIP_VERIFICATION=true
    TIMEOUT=300
    warn "Emergency mode enabled - some safety checks will be skipped"
fi

# Check dependencies
check_dependencies() {
    log "Checking required dependencies..."
    
    local deps=("kubectl" "jq" "curl")
    if [[ "$RESTORE_DATABASE" == "true" ]]; then
        deps+=("aws" "mysql")
    fi
    
    for dep in "${deps[@]}"; do
        if ! command -v "$dep" &> /dev/null; then
            error "Required dependency not found: $dep"
            exit 1
        fi
    done
    
    # Check kubectl context
    local current_context=$(kubectl config current-context 2>/dev/null || echo "none")
    log "Current kubectl context: $current_context"
    
    # Verify namespace exists
    if ! kubectl get namespace "$NAMESPACE" &>/dev/null; then
        error "Namespace $NAMESPACE does not exist"
        exit 1
    fi
    
    success "Dependencies check passed"
}

# Get current deployment status
get_deployment_status() {
    log "Analyzing current deployment status..."
    
    local current_revision=""
    local current_image=""
    local pod_status=""
    
    if kubectl get deployment genesis-orchestrator -n "$NAMESPACE" &>/dev/null; then
        current_revision=$(kubectl get deployment genesis-orchestrator -n "$NAMESPACE" -o jsonpath='{.metadata.annotations.deployment\.kubernetes\.io/revision}')
        current_image=$(kubectl get deployment genesis-orchestrator -n "$NAMESPACE" -o jsonpath='{.spec.template.spec.containers[0].image}')
        
        log "Current revision: $current_revision"
        log "Current image: $current_image"
        
        # Check pod health
        local ready_pods=$(kubectl get pods -n "$NAMESPACE" -l app=genesis-orchestrator --field-selector=status.phase=Running -o name | wc -l)
        local total_pods=$(kubectl get pods -n "$NAMESPACE" -l app=genesis-orchestrator -o name | wc -l)
        
        log "Pod status: $ready_pods/$total_pods ready"
        
        # Check recent failures
        local failed_pods=$(kubectl get pods -n "$NAMESPACE" -l app=genesis-orchestrator --field-selector=status.phase=Failed -o name | wc -l)
        if [[ $failed_pods -gt 0 ]]; then
            warn "Found $failed_pods failed pods"
        fi
        
    else
        error "Genesis orchestrator deployment not found in namespace $NAMESPACE"
        exit 1
    fi
}

# Determine rollback target
determine_rollback_target() {
    log "Determining rollback target..."
    
    case "$ROLLBACK_TYPE" in
        "auto")
            # Get previous revision
            local current_revision=$(kubectl get deployment genesis-orchestrator -n "$NAMESPACE" -o jsonpath='{.metadata.annotations.deployment\.kubernetes\.io/revision}')
            REVISION=$((current_revision - 1))
            
            if [[ $REVISION -lt 1 ]]; then
                error "No previous revision available for automatic rollback"
                exit 1
            fi
            
            log "Auto rollback target: revision $REVISION"
            ;;
        
        "manual")
            if [[ -z "$REVISION" ]]; then
                error "Manual rollback requires --revision parameter"
                exit 1
            fi
            
            # Verify revision exists
            if ! kubectl rollout history deployment/genesis-orchestrator -n "$NAMESPACE" --revision="$REVISION" &>/dev/null; then
                error "Revision $REVISION not found in deployment history"
                exit 1
            fi
            
            log "Manual rollback target: revision $REVISION"
            ;;
        
        "emergency")
            # Find last known good revision based on annotations
            local last_good=$(kubectl get deployment genesis-orchestrator -n "$NAMESPACE" -o jsonpath='{.metadata.annotations.genesis\.io/last-known-good}' 2>/dev/null || echo "")
            
            if [[ -n "$last_good" ]]; then
                REVISION="$last_good"
                log "Emergency rollback target: last known good revision $REVISION"
            else
                # Fallback to previous revision
                local current_revision=$(kubectl get deployment genesis-orchestrator -n "$NAMESPACE" -o jsonpath='{.metadata.annotations.deployment\.kubernetes\.io/revision}')
                REVISION=$((current_revision - 1))
                warn "No last known good revision found, using previous revision $REVISION"
            fi
            ;;
    esac
}

# Create rollback point
create_rollback_point() {
    log "Creating rollback point..."
    
    if [[ "$DRY_RUN" == "false" ]]; then
        # Annotate current deployment as rollback point
        kubectl annotate deployment genesis-orchestrator -n "$NAMESPACE" \
            "genesis.io/rollback-point-${TIMESTAMP}=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
            --overwrite
        
        # Save current configuration
        kubectl get deployment genesis-orchestrator -n "$NAMESPACE" -o yaml > "/tmp/genesis-deployment-backup-${TIMESTAMP}.yaml"
        
        success "Rollback point created"
    else
        log "DRY RUN: Would create rollback point"
    fi
}

# Database rollback
rollback_database() {
    if [[ "$SKIP_DATABASE" == "true" ]]; then
        log "Skipping database rollback"
        return 0
    fi
    
    if [[ "$RESTORE_DATABASE" == "true" ]]; then
        log "Restoring database from backup..."
        
        if [[ "$DRY_RUN" == "false" ]]; then
            # Find latest backup
            local latest_backup
            if command -v aws &> /dev/null; then
                latest_backup=$(aws s3 ls "s3://genesis-backups/${ENVIRONMENT}/" --recursive | sort | tail -n 1 | awk '{print $4}')
            else
                error "AWS CLI not available for database restore"
                exit 1
            fi
            
            if [[ -n "$latest_backup" ]]; then
                log "Restoring from backup: $latest_backup"
                
                # Download backup
                aws s3 cp "s3://genesis-backups/$latest_backup" "/tmp/restore-backup.sql"
                
                # Stop application to prevent writes
                kubectl scale deployment genesis-orchestrator -n "$NAMESPACE" --replicas=0
                
                # Restore database
                local db_pod=$(kubectl get pods -n "$NAMESPACE" -l app=mysql-primary -o jsonpath='{.items[0].metadata.name}')
                if [[ -n "$db_pod" ]]; then
                    kubectl exec -n "$NAMESPACE" "$db_pod" -- mysql "genesis_${ENVIRONMENT}" < "/tmp/restore-backup.sql"
                    rm "/tmp/restore-backup.sql"
                    success "Database restored from backup"
                else
                    error "Database pod not found"
                    exit 1
                fi
            else
                error "No backup found for restoration"
                exit 1
            fi
        else
            log "DRY RUN: Would restore database from latest backup"
        fi
    else
        log "Running database migration rollback..."
        
        if [[ "$DRY_RUN" == "false" ]]; then
            # Create rollback job
            kubectl apply -f - << EOF
apiVersion: batch/v1
kind: Job
metadata:
  name: genesis-db-rollback-${TIMESTAMP}
  namespace: $NAMESPACE
spec:
  template:
    spec:
      containers:
      - name: db-rollback
        image: ghcr.io/genesis/orchestrator:latest
        command: ["php", "artisan", "migrate:rollback", "--force"]
        envFrom:
        - configMapRef:
            name: genesis-config
        - secretRef:
            name: genesis-secrets
      restartPolicy: Never
  backoffLimit: 3
EOF
            
            # Wait for completion
            kubectl wait --for=condition=complete job/genesis-db-rollback-${TIMESTAMP} -n "$NAMESPACE" --timeout="${TIMEOUT}s"
            
            success "Database migration rollback completed"
        else
            log "DRY RUN: Would run database migration rollback"
        fi
    fi
}

# Application rollback
rollback_application() {
    log "Rolling back application to revision $REVISION..."
    
    if [[ "$DRY_RUN" == "false" ]]; then
        # Perform the rollback
        kubectl rollout undo deployment/genesis-orchestrator -n "$NAMESPACE" --to-revision="$REVISION"
        
        # Wait for rollback to complete
        kubectl rollout status deployment/genesis-orchestrator -n "$NAMESPACE" --timeout="${TIMEOUT}s"
        
        # Update annotations
        kubectl annotate deployment genesis-orchestrator -n "$NAMESPACE" \
            "genesis.io/rollback-timestamp=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
            "genesis.io/rollback-from-revision=$(kubectl get deployment genesis-orchestrator -n "$NAMESPACE" -o jsonpath='{.metadata.annotations.deployment\.kubernetes\.io/revision}')" \
            "genesis.io/rollback-to-revision=$REVISION" \
            --overwrite
        
        success "Application rollback completed"
    else
        log "DRY RUN: Would rollback to revision $REVISION"
    fi
}

# Verify rollback
verify_rollback() {
    if [[ "$SKIP_VERIFICATION" == "true" ]]; then
        log "Skipping rollback verification"
        return 0
    fi
    
    log "Verifying rollback..."
    
    if [[ "$DRY_RUN" == "false" ]]; then
        # Wait for pods to be ready
        kubectl wait --for=condition=ready pod -l app=genesis-orchestrator -n "$NAMESPACE" --timeout="${TIMEOUT}s"
        
        # Health check
        local retry_count=0
        local max_retries=30
        
        while [[ $retry_count -lt $max_retries ]]; do
            if python "$SCRIPT_DIR/health_check.py" --environment="$ENVIRONMENT" &>/dev/null; then
                success "Health check passed"
                break
            fi
            
            ((retry_count++))
            log "Health check attempt $retry_count/$max_retries failed, retrying..."
            sleep 10
        done
        
        if [[ $retry_count -eq $max_retries ]]; then
            error "Health check failed after $max_retries attempts"
            exit 1
        fi
        
        # Run smoke tests if not in emergency mode
        if [[ "$EMERGENCY_MODE" == "false" ]]; then
            log "Running smoke tests..."
            python -m behave features/ --tags=@smoke || warn "Some smoke tests failed"
        fi
        
        # Check metrics and alerting
        log "Checking metrics..."
        local current_time=$(date +%s)
        local five_minutes_ago=$((current_time - 300))
        
        # Query Prometheus for error rate
        local error_rate=$(curl -s "http://prometheus.${ENVIRONMENT}.genesis.com/api/v1/query?query=rate(http_requests_total{status=~\"5..\"}[5m])" | jq -r '.data.result[0].value[1] // "0"')
        
        if [[ $(echo "$error_rate > 0.05" | bc -l) -eq 1 ]]; then
            warn "High error rate detected: $error_rate"
        else
            log "Error rate acceptable: $error_rate"
        fi
        
        success "Rollback verification completed"
    else
        log "DRY RUN: Would verify rollback with health checks and smoke tests"
    fi
}

# Cleanup rollback artifacts
cleanup_rollback() {
    log "Cleaning up rollback artifacts..."
    
    if [[ "$DRY_RUN" == "false" ]]; then
        # Remove failed rollback jobs
        kubectl delete job -n "$NAMESPACE" -l "app=genesis-rollback" --field-selector status.successful!=1 2>/dev/null || true
        
        # Clean up old backup files
        find /tmp -name "genesis-*-${TIMESTAMP}.*" -mtime +1 -delete 2>/dev/null || true
        
        success "Cleanup completed"
    else
        log "DRY RUN: Would clean up rollback artifacts"
    fi
}

# Send notifications
send_notifications() {
    log "Sending rollback notifications..."
    
    local status="SUCCESS"
    local message="GENESIS Orchestrator rollback completed successfully"
    
    if [[ $? -ne 0 ]]; then
        status="FAILED"
        message="GENESIS Orchestrator rollback failed"
    fi
    
    # Slack notification
    if [[ -n "${SLACK_WEBHOOK:-}" ]]; then
        curl -X POST -H 'Content-type: application/json' \
            --data "{\"text\":\"🔄 $message\\nEnvironment: $ENVIRONMENT\\nRevision: $REVISION\\nStatus: $status\"}" \
            "$SLACK_WEBHOOK" || warn "Failed to send Slack notification"
    fi
    
    # Email notification for production
    if [[ "$ENVIRONMENT" == "production" && -n "${EMAIL_RECIPIENTS:-}" ]]; then
        local subject="GENESIS Orchestrator Rollback $status - $ENVIRONMENT"
        local body="Rollback operation completed with status: $status\n\nEnvironment: $ENVIRONMENT\nRevision: $REVISION\nTimestamp: $TIMESTAMP\nRollback Type: $ROLLBACK_TYPE"
        
        echo -e "$body" | mail -s "$subject" "$EMAIL_RECIPIENTS" || warn "Failed to send email notification"
    fi
    
    log "Notifications sent"
}

# Main rollback orchestration
main() {
    log "Starting GENESIS Orchestrator rollback"
    log "Environment: $ENVIRONMENT"
    log "Rollback Type: $ROLLBACK_TYPE"
    log "Namespace: $NAMESPACE"
    log "Dry Run: $DRY_RUN"
    log "Emergency Mode: $EMERGENCY_MODE"
    
    # Set error trap
    trap 'error "Rollback failed at line $LINENO"; send_notifications; exit 1' ERR
    
    check_dependencies
    get_deployment_status
    determine_rollback_target
    create_rollback_point
    
    # Perform rollback
    rollback_database
    rollback_application
    verify_rollback
    cleanup_rollback
    
    success "GENESIS Orchestrator rollback completed successfully!"
    log "Rollback log saved to: $LOG_FILE"
    
    send_notifications
}

# Execute main function
main "$@"