#!/bin/bash
# GENESIS Orchestrator Deployment Script
# Supports blue-green, canary, and rolling deployment strategies

set -euo pipefail

# Script configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
LOG_FILE="/tmp/genesis-deploy-${TIMESTAMP}.log"

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
DEPLOYMENT_STRATEGY="blue-green"
NAMESPACE="genesis-orchestrator"
IMAGE_TAG="latest"
DRY_RUN=false
SKIP_TESTS=false
SKIP_BACKUP=false
TIMEOUT=600
CANARY_PERCENTAGE=10
ROLLBACK_ON_FAILURE=true

# Display usage information
usage() {
    cat << EOF
GENESIS Orchestrator Deployment Script

USAGE:
    $0 [OPTIONS]

OPTIONS:
    -e, --environment ENV       Target environment (development|staging|production) [default: staging]
    -s, --strategy STRATEGY     Deployment strategy (blue-green|canary|rolling) [default: blue-green]
    -t, --tag TAG              Docker image tag [default: latest]
    -n, --namespace NAMESPACE   Kubernetes namespace [default: genesis-orchestrator]
    -d, --dry-run              Perform dry run without actual deployment
    --skip-tests               Skip pre-deployment tests
    --skip-backup              Skip database backup
    --timeout SECONDS          Deployment timeout in seconds [default: 600]
    --canary-percentage PCT     Canary deployment percentage [default: 10]
    --no-rollback              Disable automatic rollback on failure
    -h, --help                 Show this help message

EXAMPLES:
    # Blue-green deployment to staging
    $0 --environment staging --strategy blue-green --tag v1.2.3

    # Canary deployment to production with 25% traffic
    $0 --environment production --strategy canary --canary-percentage 25

    # Rolling deployment with dry run
    $0 --environment development --strategy rolling --dry-run

EOF
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -e|--environment)
            ENVIRONMENT="$2"
            shift 2
            ;;
        -s|--strategy)
            DEPLOYMENT_STRATEGY="$2"
            shift 2
            ;;
        -t|--tag)
            IMAGE_TAG="$2"
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
        --skip-tests)
            SKIP_TESTS=true
            shift
            ;;
        --skip-backup)
            SKIP_BACKUP=true
            shift
            ;;
        --timeout)
            TIMEOUT="$2"
            shift 2
            ;;
        --canary-percentage)
            CANARY_PERCENTAGE="$2"
            shift 2
            ;;
        --no-rollback)
            ROLLBACK_ON_FAILURE=false
            shift
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

# Validate deployment strategy
if [[ ! "$DEPLOYMENT_STRATEGY" =~ ^(blue-green|canary|rolling)$ ]]; then
    error "Invalid deployment strategy: $DEPLOYMENT_STRATEGY"
    exit 1
fi

# Required tools check
check_dependencies() {
    log "Checking required dependencies..."
    
    local deps=("kubectl" "docker" "helm" "jq" "curl")
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
        warn "Namespace $NAMESPACE does not exist. Creating..."
        if [[ "$DRY_RUN" == "false" ]]; then
            kubectl create namespace "$NAMESPACE"
        fi
    fi
    
    success "Dependencies check passed"
}

# Pre-deployment health checks
pre_deployment_checks() {
    log "Running pre-deployment checks..."
    
    # Check if image exists
    log "Verifying Docker image: ghcr.io/genesis/orchestrator:$IMAGE_TAG"
    if [[ "$DRY_RUN" == "false" ]]; then
        if ! docker manifest inspect "ghcr.io/genesis/orchestrator:$IMAGE_TAG" &>/dev/null; then
            error "Docker image not found: ghcr.io/genesis/orchestrator:$IMAGE_TAG"
            exit 1
        fi
    fi
    
    # Run tests unless skipped
    if [[ "$SKIP_TESTS" == "false" ]]; then
        log "Running pre-deployment tests..."
        if [[ "$DRY_RUN" == "false" ]]; then
            cd "$PROJECT_ROOT"
            python -m pytest tests/integration/ -v --tb=short
            python -m behave features/ --tags=@smoke
        fi
    fi
    
    # Check cluster resources
    log "Checking cluster resources..."
    if [[ "$DRY_RUN" == "false" ]]; then
        local cpu_available=$(kubectl top nodes --no-headers | awk '{sum += $3} END {print sum}' | sed 's/m//')
        local memory_available=$(kubectl top nodes --no-headers | awk '{sum += $5} END {print sum}' | sed 's/Mi//')
        
        log "Available CPU: ${cpu_available}m, Memory: ${memory_available}Mi"
        
        # Minimum resource requirements
        if [[ ${cpu_available:-0} -lt 2000 ]]; then
            warn "Low CPU availability: ${cpu_available}m"
        fi
        
        if [[ ${memory_available:-0} -lt 4096 ]]; then
            warn "Low memory availability: ${memory_available}Mi"
        fi
    fi
    
    success "Pre-deployment checks completed"
}

# Database backup and migration
handle_database() {
    if [[ "$SKIP_BACKUP" == "false" && "$ENVIRONMENT" != "development" ]]; then
        log "Creating database backup..."
        
        local backup_name="genesis-backup-${ENVIRONMENT}-${TIMESTAMP}"
        local db_pod=$(kubectl get pods -n "$NAMESPACE" -l app=mysql-primary -o jsonpath='{.items[0].metadata.name}')
        
        if [[ -n "$db_pod" && "$DRY_RUN" == "false" ]]; then
            kubectl exec -n "$NAMESPACE" "$db_pod" -- mysqldump \
                --single-transaction \
                --routines \
                --triggers \
                genesis_${ENVIRONMENT} > "${backup_name}.sql"
            
            # Upload to secure storage
            if command -v aws &> /dev/null; then
                aws s3 cp "${backup_name}.sql" "s3://genesis-backups/${ENVIRONMENT}/"
                rm "${backup_name}.sql"
            fi
            
            success "Database backup completed: $backup_name"
        fi
    fi
    
    log "Running database migrations..."
    if [[ "$DRY_RUN" == "false" ]]; then
        kubectl apply -f "$PROJECT_ROOT/k8s/migrations-job.yaml"
        kubectl wait --for=condition=complete job/genesis-migrations -n "$NAMESPACE" --timeout="${TIMEOUT}s"
    fi
}

# Blue-Green deployment strategy
deploy_blue_green() {
    log "Starting blue-green deployment..."
    
    local current_color=$(kubectl get service genesis-orchestrator -n "$NAMESPACE" -o jsonpath='{.spec.selector.version}' 2>/dev/null || echo "blue")
    local new_color="green"
    
    if [[ "$current_color" == "green" ]]; then
        new_color="blue"
    fi
    
    log "Current active color: $current_color, deploying to: $new_color"
    
    # Deploy new version
    if [[ "$DRY_RUN" == "false" ]]; then
        sed "s/version: .*/version: $new_color/g" "$PROJECT_ROOT/k8s/deployment.yaml" | \
        sed "s|image: .*|image: ghcr.io/genesis/orchestrator:$IMAGE_TAG|g" | \
        kubectl apply -f -
        
        # Wait for deployment to be ready
        kubectl rollout status deployment/genesis-orchestrator-${new_color} -n "$NAMESPACE" --timeout="${TIMEOUT}s"
        
        # Health check new deployment
        local new_pod=$(kubectl get pods -n "$NAMESPACE" -l app=genesis-orchestrator,version="$new_color" -o jsonpath='{.items[0].metadata.name}')
        
        log "Running health checks on new deployment..."
        for i in {1..30}; do
            if kubectl exec -n "$NAMESPACE" "$new_pod" -- curl -f http://localhost:8081/health/ready &>/dev/null; then
                break
            fi
            sleep 10
        done
        
        # Switch traffic to new version
        log "Switching traffic to $new_color deployment..."
        kubectl patch service genesis-orchestrator -n "$NAMESPACE" -p "{\"spec\":{\"selector\":{\"version\":\"$new_color\"}}}"
        
        # Wait and verify
        sleep 30
        
        # Run post-deployment tests
        if ! python "$SCRIPT_DIR/health_check.py" --environment="$ENVIRONMENT"; then
            if [[ "$ROLLBACK_ON_FAILURE" == "true" ]]; then
                error "Health check failed, rolling back..."
                kubectl patch service genesis-orchestrator -n "$NAMESPACE" -p "{\"spec\":{\"selector\":{\"version\":\"$current_color\"}}}"
                exit 1
            fi
        fi
        
        # Cleanup old deployment
        log "Cleaning up old deployment..."
        kubectl delete deployment "genesis-orchestrator-${current_color}" -n "$NAMESPACE" --ignore-not-found=true
        
        success "Blue-green deployment completed successfully"
    else
        log "DRY RUN: Would deploy to $new_color and switch traffic"
    fi
}

# Canary deployment strategy
deploy_canary() {
    log "Starting canary deployment with ${CANARY_PERCENTAGE}% traffic..."
    
    if [[ "$DRY_RUN" == "false" ]]; then
        # Deploy canary version
        sed 's/name: genesis-orchestrator$/name: genesis-orchestrator-canary/' "$PROJECT_ROOT/k8s/deployment.yaml" | \
        sed "s|image: .*|image: ghcr.io/genesis/orchestrator:$IMAGE_TAG|g" | \
        sed "s/replicas: .*/replicas: 1/" | \
        kubectl apply -f -
        
        # Wait for canary deployment
        kubectl rollout status deployment/genesis-orchestrator-canary -n "$NAMESPACE" --timeout="${TIMEOUT}s"
        
        # Configure traffic splitting
        kubectl apply -f - << EOF
apiVersion: networking.istio.io/v1beta1
kind: VirtualService
metadata:
  name: genesis-orchestrator
  namespace: $NAMESPACE
spec:
  http:
  - match:
    - headers:
        canary:
          exact: "true"
    route:
    - destination:
        host: genesis-orchestrator-canary
  - route:
    - destination:
        host: genesis-orchestrator
      weight: $((100 - CANARY_PERCENTAGE))
    - destination:
        host: genesis-orchestrator-canary
      weight: $CANARY_PERCENTAGE
EOF
        
        # Monitor canary for specified duration
        log "Monitoring canary deployment for 10 minutes..."
        if ! python "$SCRIPT_DIR/canary_monitor.py" --duration=600 --threshold=0.05; then
            if [[ "$ROLLBACK_ON_FAILURE" == "true" ]]; then
                error "Canary monitoring failed, rolling back..."
                kubectl delete deployment genesis-orchestrator-canary -n "$NAMESPACE"
                kubectl delete virtualservice genesis-orchestrator -n "$NAMESPACE"
                exit 1
            fi
        fi
        
        # Gradually increase traffic
        for percentage in 25 50 75 100; do
            log "Increasing canary traffic to ${percentage}%..."
            kubectl patch virtualservice genesis-orchestrator -n "$NAMESPACE" --type='json' -p="[{\"op\": \"replace\", \"path\": \"/spec/http/1/route/1/weight\", \"value\": $percentage}]"
            kubectl patch virtualservice genesis-orchestrator -n "$NAMESPACE" --type='json' -p="[{\"op\": \"replace\", \"path\": \"/spec/http/1/route/0/weight\", \"value\": $((100 - percentage))}]"
            
            sleep 300
            
            if ! python "$SCRIPT_DIR/canary_monitor.py" --duration=300 --threshold=0.03; then
                if [[ "$ROLLBACK_ON_FAILURE" == "true" ]]; then
                    error "Canary monitoring failed at ${percentage}%, rolling back..."
                    kubectl delete deployment genesis-orchestrator-canary -n "$NAMESPACE"
                    kubectl delete virtualservice genesis-orchestrator -n "$NAMESPACE"
                    exit 1
                fi
            fi
        done
        
        # Promote canary to main
        log "Promoting canary to main deployment..."
        kubectl delete deployment genesis-orchestrator -n "$NAMESPACE"
        kubectl patch deployment genesis-orchestrator-canary -n "$NAMESPACE" -p '{"metadata":{"name":"genesis-orchestrator"}}'
        kubectl delete virtualservice genesis-orchestrator -n "$NAMESPACE"
        
        success "Canary deployment completed successfully"
    else
        log "DRY RUN: Would deploy canary with ${CANARY_PERCENTAGE}% traffic"
    fi
}

# Rolling deployment strategy
deploy_rolling() {
    log "Starting rolling deployment..."
    
    if [[ "$DRY_RUN" == "false" ]]; then
        # Update deployment with new image
        kubectl set image deployment/genesis-orchestrator -n "$NAMESPACE" \
            orchestrator="ghcr.io/genesis/orchestrator:$IMAGE_TAG"
        
        # Wait for rollout to complete
        kubectl rollout status deployment/genesis-orchestrator -n "$NAMESPACE" --timeout="${TIMEOUT}s"
        
        # Verify deployment
        if ! python "$SCRIPT_DIR/health_check.py" --environment="$ENVIRONMENT"; then
            if [[ "$ROLLBACK_ON_FAILURE" == "true" ]]; then
                error "Health check failed, rolling back..."
                kubectl rollout undo deployment/genesis-orchestrator -n "$NAMESPACE"
                kubectl rollout status deployment/genesis-orchestrator -n "$NAMESPACE" --timeout="${TIMEOUT}s"
                exit 1
            fi
        fi
        
        success "Rolling deployment completed successfully"
    else
        log "DRY RUN: Would update deployment with image ghcr.io/genesis/orchestrator:$IMAGE_TAG"
    fi
}

# Post-deployment verification
post_deployment_verification() {
    log "Running post-deployment verification..."
    
    if [[ "$DRY_RUN" == "false" ]]; then
        # Wait for all pods to be ready
        kubectl wait --for=condition=ready pod -l app=genesis-orchestrator -n "$NAMESPACE" --timeout="${TIMEOUT}s"
        
        # Run comprehensive health checks
        python "$SCRIPT_DIR/health_check.py" --environment="$ENVIRONMENT" --comprehensive
        
        # Run smoke tests
        python -m behave features/ --tags=@smoke
        
        # Verify SLA metrics
        if [[ "$ENVIRONMENT" == "production" ]]; then
            python "$SCRIPT_DIR/sla_validation.py" --environment="$ENVIRONMENT"
        fi
        
        # Check monitoring and alerting
        log "Verifying monitoring systems..."
        curl -f "http://prometheus.${ENVIRONMENT}.genesis.com/api/v1/query?query=up{job=\"genesis-orchestrator\"}" >/dev/null
        
        success "Post-deployment verification completed"
    else
        log "DRY RUN: Would run post-deployment verification"
    fi
}

# Cleanup and finalization
cleanup() {
    log "Performing cleanup..."
    
    # Remove old ReplicaSets (keep last 3)
    kubectl delete replicaset -n "$NAMESPACE" \
        $(kubectl get replicaset -n "$NAMESPACE" --sort-by=.metadata.creationTimestamp -o name | head -n -3) \
        2>/dev/null || true
    
    # Cleanup completed jobs
    kubectl delete job -n "$NAMESPACE" --field-selector status.successful=1 2>/dev/null || true
    
    success "Cleanup completed"
}

# Main deployment orchestration
main() {
    log "Starting GENESIS Orchestrator deployment"
    log "Environment: $ENVIRONMENT"
    log "Strategy: $DEPLOYMENT_STRATEGY"
    log "Image Tag: $IMAGE_TAG"
    log "Namespace: $NAMESPACE"
    log "Dry Run: $DRY_RUN"
    
    # Set error trap
    trap 'error "Deployment failed at line $LINENO"; exit 1' ERR
    
    check_dependencies
    pre_deployment_checks
    handle_database
    
    case "$DEPLOYMENT_STRATEGY" in
        "blue-green")
            deploy_blue_green
            ;;
        "canary")
            deploy_canary
            ;;
        "rolling")
            deploy_rolling
            ;;
    esac
    
    post_deployment_verification
    cleanup
    
    success "GENESIS Orchestrator deployment completed successfully!"
    log "Deployment log saved to: $LOG_FILE"
}

# Execute main function
main "$@"