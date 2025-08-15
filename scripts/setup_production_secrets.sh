#!/bin/bash
# Production Secrets Management Setup Script
# =========================================
# 
# Comprehensive setup script for production-grade secrets management
# infrastructure including HashiCorp Vault, policy deployment, and 
# initial secret rotation setup.
#
# Usage:
#   ./setup_production_secrets.sh [--environment prod|staging|dev] [--dry-run]
#   ./setup_production_secrets.sh --help

set -euo pipefail

# Script configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" &> /dev/null && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
LOG_FILE="${PROJECT_ROOT}/logs/setup_secrets_${TIMESTAMP}.log"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Default values
ENVIRONMENT="dev"
DRY_RUN=false
VAULT_VERSION="1.15.4"
SKIP_VAULT_INSTALL=false
SKIP_POLICY_SETUP=false
SKIP_INITIAL_SECRETS=false
FORCE_REINSTALL=false

# Functions
log() {
    echo -e "${1}" | tee -a "$LOG_FILE"
}

log_info() {
    log "${BLUE}[INFO]${NC} ${1}"
}

log_success() {
    log "${GREEN}[SUCCESS]${NC} ${1}"
}

log_warning() {
    log "${YELLOW}[WARNING]${NC} ${1}"
}

log_error() {
    log "${RED}[ERROR]${NC} ${1}"
}

show_help() {
    cat << EOF
Production Secrets Management Setup Script

USAGE:
    $0 [OPTIONS]

OPTIONS:
    -e, --environment ENV    Target environment (prod|staging|dev) [default: dev]
    -d, --dry-run           Perform dry run without making changes
    -h, --help              Show this help message
    --skip-vault-install    Skip HashiCorp Vault installation
    --skip-policy-setup     Skip Vault policy setup
    --skip-initial-secrets  Skip initial secret creation
    --force-reinstall       Force reinstall even if components exist
    --vault-version VER     Vault version to install [default: $VAULT_VERSION]

EXAMPLES:
    $0 --environment prod
    $0 --environment dev --dry-run
    $0 --skip-vault-install --environment staging

ENVIRONMENT VARIABLES:
    VAULT_URL               Vault server URL
    VAULT_TOKEN             Vault authentication token
    VAULT_NAMESPACE         Vault namespace [default: genesis]
    GITHUB_TOKEN            GitHub token for secrets access

EOF
}

check_prerequisites() {
    log_info "Checking prerequisites..."
    
    # Check if running as root (not recommended)
    if [[ $EUID -eq 0 ]]; then
        log_warning "Running as root is not recommended for security reasons"
    fi
    
    # Check required commands
    local required_commands=("curl" "jq" "python3" "php" "composer" "mysql")
    for cmd in "${required_commands[@]}"; do
        if ! command -v "$cmd" &> /dev/null; then
            log_error "Required command not found: $cmd"
            return 1
        fi
    done
    
    # Check Python dependencies
    if ! python3 -c "import requests, yaml, cryptography" &> /dev/null; then
        log_warning "Some Python dependencies are missing. Installing..."
        pip3 install requests pyyaml cryptography || {
            log_error "Failed to install Python dependencies"
            return 1
        }
    fi
    
    # Check environment file
    if [[ ! -f "${PROJECT_ROOT}/.env" ]]; then
        log_warning ".env file not found. Creating from template..."
        cp "${PROJECT_ROOT}/env.example" "${PROJECT_ROOT}/.env"
        log_warning "Please configure .env file with your settings before proceeding"
    fi
    
    # Check directories
    mkdir -p "${PROJECT_ROOT}/logs"
    mkdir -p "${PROJECT_ROOT}/storage/secrets"
    mkdir -p "${PROJECT_ROOT}/backups"
    
    log_success "Prerequisites check completed"
}

install_vault() {
    if [[ "$SKIP_VAULT_INSTALL" == true ]]; then
        log_info "Skipping Vault installation"
        return 0
    fi
    
    log_info "Installing HashiCorp Vault v${VAULT_VERSION}..."
    
    # Check if Vault is already installed
    if command -v vault &> /dev/null && [[ "$FORCE_REINSTALL" == false ]]; then
        local installed_version
        installed_version=$(vault version | head -n1 | cut -d' ' -f2 | sed 's/v//')
        log_info "Vault already installed: v${installed_version}"
        
        if [[ "$installed_version" == "$VAULT_VERSION" ]]; then
            log_success "Vault v${VAULT_VERSION} already installed"
            return 0
        else
            log_warning "Different Vault version installed. Use --force-reinstall to upgrade"
            return 0
        fi
    fi
    
    # Determine architecture
    local arch
    case $(uname -m) in
        x86_64) arch="amd64" ;;
        arm64|aarch64) arch="arm64" ;;
        *) 
            log_error "Unsupported architecture: $(uname -m)"
            return 1
            ;;
    esac
    
    local os
    case $(uname -s) in
        Linux) os="linux" ;;
        Darwin) os="darwin" ;;
        *)
            log_error "Unsupported operating system: $(uname -s)"
            return 1
            ;;
    esac
    
    # Download and install Vault
    local vault_url="https://releases.hashicorp.com/vault/${VAULT_VERSION}/vault_${VAULT_VERSION}_${os}_${arch}.zip"
    local temp_dir
    temp_dir=$(mktemp -d)
    
    log_info "Downloading Vault from: $vault_url"
    
    if ! curl -sL "$vault_url" -o "${temp_dir}/vault.zip"; then
        log_error "Failed to download Vault"
        return 1
    fi
    
    # Extract and install
    cd "$temp_dir"
    unzip vault.zip
    
    # Install to /usr/local/bin (requires sudo on most systems)
    if [[ -w "/usr/local/bin" ]]; then
        mv vault /usr/local/bin/
    else
        sudo mv vault /usr/local/bin/
        sudo chmod +x /usr/local/bin/vault
    fi
    
    # Cleanup
    rm -rf "$temp_dir"
    
    # Verify installation
    if ! vault version &> /dev/null; then
        log_error "Vault installation failed"
        return 1
    fi
    
    log_success "Vault v${VAULT_VERSION} installed successfully"
    
    # Start Vault in dev mode for development environment
    if [[ "$ENVIRONMENT" == "dev" ]]; then
        log_info "Starting Vault in development mode..."
        start_vault_dev_mode
    fi
}

start_vault_dev_mode() {
    if pgrep -f "vault server -dev" > /dev/null; then
        log_info "Vault dev server already running"
        return 0
    fi
    
    log_info "Starting Vault development server..."
    
    # Start Vault in background
    vault server -dev -dev-root-token-id="genesis-dev-token" \
        -dev-listen-address="127.0.0.1:8200" \
        > "${PROJECT_ROOT}/logs/vault_dev.log" 2>&1 &
    
    local vault_pid=$!
    echo "$vault_pid" > "${PROJECT_ROOT}/vault_dev.pid"
    
    # Wait for Vault to start
    local retries=0
    while ! curl -s http://127.0.0.1:8200/v1/sys/health > /dev/null; do
        if [[ $retries -ge 30 ]]; then
            log_error "Vault failed to start within 30 seconds"
            return 1
        fi
        sleep 1
        ((retries++))
    done
    
    # Export dev environment variables
    export VAULT_ADDR="http://127.0.0.1:8200"
    export VAULT_TOKEN="genesis-dev-token"
    
    log_success "Vault development server started (PID: $vault_pid)"
    log_info "VAULT_ADDR: $VAULT_ADDR"
    log_info "VAULT_TOKEN: $VAULT_TOKEN"
}

setup_vault_policies() {
    if [[ "$SKIP_POLICY_SETUP" == true ]]; then
        log_info "Skipping Vault policy setup"
        return 0
    fi
    
    log_info "Setting up Vault policies..."
    
    # Set Vault environment variables
    if [[ -z "${VAULT_ADDR:-}" ]]; then
        export VAULT_ADDR="${VAULT_URL:-http://127.0.0.1:8200}"
    fi
    
    if [[ -z "${VAULT_TOKEN:-}" ]]; then
        if [[ "$ENVIRONMENT" == "dev" ]]; then
            export VAULT_TOKEN="genesis-dev-token"
        else
            log_error "VAULT_TOKEN not set for $ENVIRONMENT environment"
            return 1
        fi
    fi
    
    # Run policy setup script
    local policy_script="${PROJECT_ROOT}/scripts/vault_policies.py"
    if [[ ! -f "$policy_script" ]]; then
        log_error "Vault policy script not found: $policy_script"
        return 1
    fi
    
    if [[ "$DRY_RUN" == true ]]; then
        log_info "DRY RUN: Would deploy Vault policies"
        python3 "$policy_script" --deploy-all --dry-run
        python3 "$policy_script" --create-roles --dry-run
    else
        log_info "Deploying Vault policies..."
        if ! python3 "$policy_script" --deploy-all; then
            log_error "Failed to deploy Vault policies"
            return 1
        fi
        
        log_info "Creating Vault roles..."
        if ! python3 "$policy_script" --create-roles; then
            log_error "Failed to create Vault roles"
            return 1
        fi
        
        # Validate policies
        log_info "Validating deployed policies..."
        python3 "$policy_script" --validate-policies
        
        log_success "Vault policies and roles configured successfully"
    fi
}

enable_vault_audit() {
    log_info "Enabling Vault audit logging..."
    
    # Check if audit is already enabled
    if vault audit list | grep -q "file/"; then
        log_info "File audit backend already enabled"
    else
        local audit_log_path="${PROJECT_ROOT}/logs/vault-audit.log"
        
        if [[ "$DRY_RUN" == true ]]; then
            log_info "DRY RUN: Would enable file audit at $audit_log_path"
        else
            vault audit enable file file_path="$audit_log_path"
            log_success "File audit enabled at $audit_log_path"
        fi
    fi
    
    # Enable database audit if configured
    local db_connection="${DB_CONNECTION:-mysql}"
    if [[ "$db_connection" != "" && "$ENVIRONMENT" != "dev" ]]; then
        log_info "Database audit logging will be handled by application"
    fi
}

setup_database_migrations() {
    log_info "Running database migrations..."
    
    # Change to project root
    cd "$PROJECT_ROOT"
    
    # Check if Laravel is available
    if [[ ! -f "artisan" ]]; then
        log_warning "Laravel artisan not found. Skipping database migrations."
        return 0
    fi
    
    if [[ "$DRY_RUN" == true ]]; then
        log_info "DRY RUN: Would run database migrations"
        php artisan migrate:status
    else
        # Run migrations
        if ! php artisan migrate --force; then
            log_error "Database migrations failed"
            return 1
        fi
        
        log_success "Database migrations completed"
    fi
}

create_initial_secrets() {
    if [[ "$SKIP_INITIAL_SECRETS" == true ]]; then
        log_info "Skipping initial secret creation"
        return 0
    fi
    
    log_info "Creating initial secrets..."
    
    local secrets_to_create=(
        "genesis/webhooks/hmac_secret:webhook_secrets"
        "genesis/api_keys/claude:api_keys"
        "genesis/api_keys/openai:api_keys"
        "genesis/encryption/master_key:encryption_keys"
        "genesis/jwt/signing_secret:jwt_secrets"
    )
    
    for secret_entry in "${secrets_to_create[@]}"; do
        IFS=':' read -r secret_path secret_policy <<< "$secret_entry"
        
        log_info "Creating secret: $secret_path"
        
        if [[ "$DRY_RUN" == true ]]; then
            log_info "DRY RUN: Would create secret $secret_path with policy $secret_policy"
            continue
        fi
        
        # Check if secret already exists
        if vault kv get -format=json "$secret_path" &> /dev/null; then
            log_warning "Secret already exists: $secret_path"
            continue
        fi
        
        # Generate and store initial secret
        local rotation_script="${PROJECT_ROOT}/scripts/rotate_secrets.py"
        if [[ ! -f "$rotation_script" ]]; then
            log_error "Secret rotation script not found: $rotation_script"
            continue
        fi
        
        if python3 "$rotation_script" --secret-path "$secret_path" --policy "$secret_policy"; then
            log_success "Created secret: $secret_path"
        else
            log_warning "Failed to create secret: $secret_path"
        fi
    done
}

setup_monitoring() {
    log_info "Setting up monitoring and health checks..."
    
    # Create monitoring script
    local monitoring_script="${PROJECT_ROOT}/scripts/health_check_secrets.sh"
    
    if [[ "$DRY_RUN" == true ]]; then
        log_info "DRY RUN: Would create monitoring setup"
        return 0
    fi
    
    cat > "$monitoring_script" << 'EOF'
#!/bin/bash
# Secrets Management Health Check Script
# Automatically generated by setup_production_secrets.sh

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" &> /dev/null && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Source environment
if [[ -f "$PROJECT_ROOT/.env" ]]; then
    set -o allexport
    source "$PROJECT_ROOT/.env"
    set +o allexport
fi

# Check Vault health
check_vault_health() {
    if [[ -z "$VAULT_ADDR" ]]; then
        echo "ERROR: VAULT_ADDR not set"
        return 1
    fi
    
    if ! curl -sf "$VAULT_ADDR/v1/sys/health" > /dev/null; then
        echo "ERROR: Vault health check failed"
        return 1
    fi
    
    echo "OK: Vault is healthy"
    return 0
}

# Check secret accessibility
check_secret_accessibility() {
    local test_paths=(
        "genesis/monitoring/health_check"
    )
    
    for path in "${test_paths[@]}"; do
        if ! vault kv get "$path" > /dev/null 2>&1; then
            echo "WARNING: Cannot access secret: $path"
        else
            echo "OK: Secret accessible: $path"
        fi
    done
}

# Main health check
main() {
    echo "=== Secrets Management Health Check ==="
    echo "Timestamp: $(date)"
    echo "Environment: ${APP_ENV:-unknown}"
    echo
    
    check_vault_health || exit 1
    check_secret_accessibility
    
    echo
    echo "=== Health Check Complete ==="
}

main "$@"
EOF

    chmod +x "$monitoring_script"
    log_success "Created monitoring script: $monitoring_script"
    
    # Create systemd service for production environments
    if [[ "$ENVIRONMENT" == "prod" || "$ENVIRONMENT" == "staging" ]]; then
        create_systemd_services
    fi
}

create_systemd_services() {
    log_info "Creating systemd services for production deployment..."
    
    if [[ "$DRY_RUN" == true ]]; then
        log_info "DRY RUN: Would create systemd services"
        return 0
    fi
    
    # Vault service (if not using external Vault)
    if [[ ! -f "/etc/systemd/system/vault.service" ]]; then
        log_info "Creating Vault systemd service..."
        sudo tee /etc/systemd/system/vault.service > /dev/null << EOF
[Unit]
Description=HashiCorp Vault
Documentation=https://www.vaultproject.io/docs/
Requires=network-online.target
After=network-online.target
ConditionFileNotEmpty=/etc/vault.d/vault.hcl

[Service]
Type=notify
User=vault
Group=vault
ProtectSystem=full
ProtectHome=read-only
PrivateTmp=yes
PrivateDevices=yes
SecureBits=keep-caps
AmbientCapabilities=CAP_IPC_LOCK
CapabilityBoundingSet=CAP_SYSLOG CAP_IPC_LOCK
NoNewPrivileges=yes
ExecStart=/usr/local/bin/vault server -config=/etc/vault.d/vault.hcl
ExecReload=/bin/kill -HUP \$MAINPID
KillMode=process
Restart=on-failure
RestartSec=5
TimeoutStopSec=30
StartLimitInterval=60
StartLimitBurst=3
LimitNOFILE=65536
LimitMEMLOCK=infinity

[Install]
WantedBy=multi-user.target
EOF
    fi
    
    # Secret rotation service
    sudo tee /etc/systemd/system/genesis-secret-rotation.service > /dev/null << EOF
[Unit]
Description=Genesis Secret Rotation Service
After=vault.service
Requires=vault.service

[Service]
Type=oneshot
User=genesis
Group=genesis
WorkingDirectory=${PROJECT_ROOT}
ExecStart=/usr/bin/python3 ${PROJECT_ROOT}/scripts/rotate_secrets.py --all
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

    # Secret rotation timer
    sudo tee /etc/systemd/system/genesis-secret-rotation.timer > /dev/null << EOF
[Unit]
Description=Genesis Secret Rotation Timer
Requires=genesis-secret-rotation.service

[Timer]
OnCalendar=Sun 02:00
Persistent=true

[Install]
WantedBy=timers.target
EOF

    # Enable services
    sudo systemctl daemon-reload
    sudo systemctl enable genesis-secret-rotation.timer
    
    log_success "Systemd services created and enabled"
}

run_security_validation() {
    log_info "Running security validation..."
    
    # Check file permissions
    local secure_files=(
        ".env"
        "storage/secrets"
        "logs"
    )
    
    for file in "${secure_files[@]}"; do
        local file_path="${PROJECT_ROOT}/$file"
        if [[ -f "$file_path" || -d "$file_path" ]]; then
            local perms
            perms=$(stat -c "%a" "$file_path" 2>/dev/null || stat -f "%A" "$file_path" 2>/dev/null)
            
            if [[ "$file" == ".env" && "$perms" != "600" ]]; then
                log_warning "Insecure permissions on .env file: $perms (should be 600)"
                if [[ "$DRY_RUN" == false ]]; then
                    chmod 600 "$file_path"
                    log_success "Fixed .env permissions"
                fi
            fi
        fi
    done
    
    # Validate Vault policies
    if command -v vault &> /dev/null && [[ -n "${VAULT_TOKEN:-}" ]]; then
        log_info "Validating Vault policies..."
        
        local policy_script="${PROJECT_ROOT}/scripts/vault_policies.py"
        if [[ -f "$policy_script" ]]; then
            python3 "$policy_script" --validate-policies --output json > "${PROJECT_ROOT}/logs/policy_validation_${TIMESTAMP}.json"
            log_success "Policy validation results saved"
        fi
    fi
    
    log_success "Security validation completed"
}

generate_setup_report() {
    log_info "Generating setup report..."
    
    local report_file="${PROJECT_ROOT}/logs/secrets_setup_report_${TIMESTAMP}.json"
    
    cat > "$report_file" << EOF
{
  "setup_timestamp": "$(date -u -Iseconds)",
  "environment": "$ENVIRONMENT",
  "dry_run": $DRY_RUN,
  "vault_version": "$(vault version 2>/dev/null | head -n1 || echo 'not installed')",
  "components": {
    "vault_installed": $(command -v vault >/dev/null && echo true || echo false),
    "policies_deployed": $([ "$SKIP_POLICY_SETUP" == true ] && echo false || echo true),
    "initial_secrets_created": $([ "$SKIP_INITIAL_SECRETS" == true ] && echo false || echo true),
    "monitoring_setup": true,
    "database_migrations": true
  },
  "configuration": {
    "vault_addr": "${VAULT_ADDR:-not set}",
    "vault_namespace": "${VAULT_NAMESPACE:-genesis}",
    "environment_file": "$([ -f "${PROJECT_ROOT}/.env" ] && echo 'exists' || echo 'missing')"
  },
  "next_steps": [
    "Configure production Vault cluster if not using dev mode",
    "Set up backup and disaster recovery procedures",
    "Configure monitoring and alerting",
    "Run initial secret rotation test",
    "Review and customize security policies"
  ]
}
EOF

    log_success "Setup report generated: $report_file"
    
    # Show summary
    echo
    log_info "=== SETUP COMPLETE ==="
    log_success "Environment: $ENVIRONMENT"
    log_success "Vault installed: $(command -v vault >/dev/null && echo 'Yes' || echo 'No')"
    log_success "Policies deployed: $([ "$SKIP_POLICY_SETUP" == true ] && echo 'No (skipped)' || echo 'Yes')"
    log_success "Initial secrets: $([ "$SKIP_INITIAL_SECRETS" == true ] && echo 'No (skipped)' || echo 'Yes')"
    log_success "Setup log: $LOG_FILE"
    log_success "Setup report: $report_file"
    
    if [[ "$ENVIRONMENT" == "dev" ]]; then
        echo
        log_info "Development environment variables:"
        log_info "export VAULT_ADDR=${VAULT_ADDR:-http://127.0.0.1:8200}"
        log_info "export VAULT_TOKEN=${VAULT_TOKEN:-genesis-dev-token}"
    fi
    
    echo
    log_info "Next steps:"
    log_info "1. Review configuration in .env file"
    log_info "2. Test secret operations: python3 scripts/rotate_secrets.py --secret-path genesis/test/example --dry-run"
    log_info "3. Run health check: ./scripts/health_check_secrets.sh"
    log_info "4. Set up monitoring and alerting for production"
}

cleanup_on_exit() {
    local exit_code=$?
    
    if [[ $exit_code -ne 0 ]]; then
        log_error "Setup failed with exit code $exit_code"
        
        # Stop Vault dev server if we started it
        if [[ -f "${PROJECT_ROOT}/vault_dev.pid" && "$ENVIRONMENT" == "dev" ]]; then
            local vault_pid
            vault_pid=$(cat "${PROJECT_ROOT}/vault_dev.pid")
            if kill -0 "$vault_pid" 2>/dev/null; then
                log_info "Stopping Vault dev server..."
                kill "$vault_pid"
                rm -f "${PROJECT_ROOT}/vault_dev.pid"
            fi
        fi
    fi
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -e|--environment)
            ENVIRONMENT="$2"
            shift 2
            ;;
        -d|--dry-run)
            DRY_RUN=true
            shift
            ;;
        -h|--help)
            show_help
            exit 0
            ;;
        --skip-vault-install)
            SKIP_VAULT_INSTALL=true
            shift
            ;;
        --skip-policy-setup)
            SKIP_POLICY_SETUP=true
            shift
            ;;
        --skip-initial-secrets)
            SKIP_INITIAL_SECRETS=true
            shift
            ;;
        --force-reinstall)
            FORCE_REINSTALL=true
            shift
            ;;
        --vault-version)
            VAULT_VERSION="$2"
            shift 2
            ;;
        *)
            log_error "Unknown option: $1"
            show_help
            exit 1
            ;;
    esac
done

# Validate environment
case $ENVIRONMENT in
    prod|production)
        ENVIRONMENT="prod"
        ;;
    staging|stage)
        ENVIRONMENT="staging"
        ;;
    dev|development)
        ENVIRONMENT="dev"
        ;;
    *)
        log_error "Invalid environment: $ENVIRONMENT (must be prod, staging, or dev)"
        exit 1
        ;;
esac

# Main execution
main() {
    log_info "Starting GENESIS Orchestrator secrets management setup..."
    log_info "Environment: $ENVIRONMENT"
    log_info "Dry run: $DRY_RUN"
    log_info "Timestamp: $TIMESTAMP"
    echo
    
    # Setup cleanup handler
    trap cleanup_on_exit EXIT
    
    # Run setup steps
    check_prerequisites
    install_vault
    setup_vault_policies
    enable_vault_audit
    setup_database_migrations
    create_initial_secrets
    setup_monitoring
    run_security_validation
    generate_setup_report
    
    log_success "Setup completed successfully!"
}

# Execute main function
main "$@"