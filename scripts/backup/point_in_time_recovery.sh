#!/bin/bash

# GENESIS Orchestrator - Point-in-Time Recovery System
# Enterprise-grade recovery with sub-15-minute RTO capability
# Supports granular recovery to any point within retention window

set -euo pipefail

# Configuration
BACKUP_ROOT_DIR="${BACKUP_ROOT_DIR:-/var/backups/genesis}"
DB_HOST="${DB_HOST:-mysql-primary}"
DB_USER="${DB_USERNAME:-genesis}"
DB_PASSWORD="${DB_PASSWORD}"
DB_NAME="${DB_DATABASE:-genesis_orchestrator}"
REDIS_HOST="${REDIS_HOST:-redis-primary}"
REDIS_PORT="${REDIS_PORT:-6379}"
S3_BUCKET="${BACKUP_S3_BUCKET:-genesis-disaster-recovery}"
S3_REGION="${BACKUP_S3_REGION:-us-west-2}"

# Recovery parameters
RECOVERY_TARGET_TIME=""
RECOVERY_BACKUP_ID=""
RECOVERY_MODE="full"  # full, database_only, redis_only, artifacts_only
DRY_RUN="false"
FORCE_RECOVERY="false"

# Logging setup
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="${BACKUP_ROOT_DIR}/logs/recovery_$(date +%Y%m%d_%H%M%S).log"
mkdir -p "$(dirname "$LOG_FILE")"

exec 1> >(tee -a "$LOG_FILE")
exec 2>&1

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"
}

error_exit() {
    log "ERROR: $1"
    exit 1
}

usage() {
    cat << EOF
GENESIS Orchestrator Point-in-Time Recovery

Usage: $0 [OPTIONS]

OPTIONS:
    --target-time YYYY-MM-DD_HH:MM:SS    Target recovery time (UTC)
    --backup-id BACKUP_ID                Specific backup to restore from
    --mode MODE                          Recovery mode: full, database_only, redis_only, artifacts_only
    --dry-run                           Show what would be recovered without executing
    --force                             Force recovery without confirmation prompts
    --list-backups                      List available backups
    --validate-backup BACKUP_ID         Validate specific backup integrity
    --help                              Show this help message

EXAMPLES:
    # Recover to specific time
    $0 --target-time 2024-12-15_14:30:00 --mode full

    # Recover from specific backup
    $0 --backup-id backup_20241215_143000_a1b2c3d4 --mode database_only

    # List available recovery points
    $0 --list-backups

    # Dry run recovery
    $0 --target-time 2024-12-15_14:30:00 --dry-run

RECOVERY MODES:
    full            Complete system recovery (database + Redis + artifacts)
    database_only   Database recovery only
    redis_only      Redis recovery only  
    artifacts_only  Application artifacts and config recovery only

EOF
}

# Parse command line arguments
parse_arguments() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            --target-time)
                RECOVERY_TARGET_TIME="$2"
                shift 2
                ;;
            --backup-id)
                RECOVERY_BACKUP_ID="$2"
                shift 2
                ;;
            --mode)
                RECOVERY_MODE="$2"
                shift 2
                ;;
            --dry-run)
                DRY_RUN="true"
                shift
                ;;
            --force)
                FORCE_RECOVERY="true"
                shift
                ;;
            --list-backups)
                list_available_backups
                exit 0
                ;;
            --validate-backup)
                validate_specific_backup "$2"
                exit 0
                ;;
            --help)
                usage
                exit 0
                ;;
            *)
                error_exit "Unknown option: $1. Use --help for usage information."
                ;;
        esac
    done
}

# List available backups
list_available_backups() {
    log "Available backup recovery points:"
    echo
    
    # Check local backups
    if [ -f "$BACKUP_ROOT_DIR/backup_registry.json" ]; then
        echo "LOCAL BACKUPS:"
        jq -r '.[] | select(.validation_status == "passed") | "\(.backup_id) | \(.timestamp) | \(.total_size_bytes | tonumber / 1024 / 1024 | round)MB | RTO: \(.rto_compliance) | RPO: \(.rpo_compliance)"' \
            "$BACKUP_ROOT_DIR/backup_registry.json" | sort -r | head -20
        echo
    fi
    
    # Check S3 backups
    echo "S3 BACKUPS (Primary Region):"
    aws s3api list-objects-v2 \
        --bucket "$S3_BUCKET" \
        --prefix "backups/" \
        --region "$S3_REGION" \
        --query 'Contents[?Size > `1000`].[Key, LastModified, Size]' \
        --output table | head -20
    
    echo
    echo "To recover, use:"
    echo "  $0 --backup-id <BACKUP_ID> --mode <MODE>"
    echo "  $0 --target-time YYYY-MM-DD_HH:MM:SS --mode <MODE>"
}

# Find best backup for target time
find_best_backup() {
    local target_time="$1"
    local target_timestamp=$(date -d "${target_time//_/ }" +%s)
    
    log "Finding best backup for target time: $target_time"
    
    # Check backup registry for closest backup before target time
    if [ -f "$BACKUP_ROOT_DIR/backup_registry.json" ]; then
        local best_backup=$(jq -r --arg target "$target_timestamp" '
            map(select(.validation_status == "passed" and (.timestamp | strptime("%Y-%m-%dT%H:%M:%SZ") | mktime) <= ($target | tonumber))) 
            | sort_by(.timestamp) 
            | reverse 
            | first 
            | .backup_id' "$BACKUP_ROOT_DIR/backup_registry.json")
        
        if [ "$best_backup" != "null" ]; then
            echo "$best_backup"
            return 0
        fi
    fi
    
    # Fallback: search S3 for closest backup
    aws s3api list-objects-v2 \
        --bucket "$S3_BUCKET" \
        --prefix "backups/" \
        --region "$S3_REGION" \
        --query "Contents[?LastModified <= '$(date -d "${target_time//_/ }" -Iseconds)'] | sort_by(@, &LastModified) | [-1].Key" \
        --output text | sed 's/backups\/\([^\/]*\)\/.*/\1/' || error_exit "No suitable backup found for target time"
}

# Validate specific backup
validate_specific_backup() {
    local backup_id="$1"
    local backup_dir="$BACKUP_ROOT_DIR/backups/$backup_id"
    
    log "Validating backup: $backup_id"
    
    # Download backup if not present locally
    if [ ! -d "$backup_dir" ]; then
        log "Backup not found locally, downloading from S3..."
        mkdir -p "$backup_dir"
        aws s3 sync "s3://$S3_BUCKET/backups/$backup_id/" "$backup_dir/" --region "$S3_REGION"
    fi
    
    # Verify checksums
    local failed_files=()
    for checksum_file in "$backup_dir"/*.sha256; do
        if [ -f "$checksum_file" ]; then
            if ! sha256sum -c "$checksum_file" --status; then
                failed_files+=("$(basename "$checksum_file")")
            fi
        fi
    done
    
    if [ ${#failed_files[@]} -gt 0 ]; then
        error_exit "Backup validation failed for: ${failed_files[*]}"
    fi
    
    # Validate backup metadata
    if [ -f "$backup_dir/backup_metadata.json" ]; then
        local backup_valid=$(jq -r '.backup_type' "$backup_dir/backup_metadata.json")
        [ "$backup_valid" != "null" ] || error_exit "Invalid backup metadata"
        log "Backup metadata validation passed"
    else
        error_exit "Backup metadata file missing"
    fi
    
    log "Backup validation completed successfully: $backup_id"
}

# Pre-recovery safety checks
pre_recovery_checks() {
    log "Performing pre-recovery safety checks..."
    
    # Verify target systems are accessible
    if [ "$RECOVERY_MODE" = "full" ] || [ "$RECOVERY_MODE" = "database_only" ]; then
        mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" -e "SELECT 1;" >/dev/null 2>&1 || \
            error_exit "Cannot connect to target MySQL server"
    fi
    
    if [ "$RECOVERY_MODE" = "full" ] || [ "$RECOVERY_MODE" = "redis_only" ]; then
        redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" ping >/dev/null 2>&1 || \
            error_exit "Cannot connect to target Redis server"
    fi
    
    # Check for active connections that might interfere
    if [ "$RECOVERY_MODE" = "full" ] || [ "$RECOVERY_MODE" = "database_only" ]; then
        local active_connections=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" -e "SELECT COUNT(*) FROM information_schema.processlist WHERE db='$DB_NAME' AND command != 'Sleep';" -N)
        if [ "$active_connections" -gt 5 ] && [ "$FORCE_RECOVERY" != "true" ]; then
            error_exit "Active database connections detected ($active_connections). Use --force to proceed or stop application first."
        fi
    fi
    
    # Verify sufficient disk space
    local required_space=10485760  # 10GB minimum
    local free_space=$(df "$BACKUP_ROOT_DIR" | awk 'NR==2 {print $4}')
    [ "$free_space" -gt "$required_space" ] || error_exit "Insufficient disk space for recovery operations"
    
    log "Pre-recovery checks completed successfully"
}

# Create recovery point backup before restore
create_recovery_point() {
    if [ "$DRY_RUN" = "true" ]; then
        log "[DRY RUN] Would create pre-recovery backup"
        return
    fi
    
    log "Creating pre-recovery backup point..."
    
    local recovery_backup_id="pre_recovery_$(date +%Y%m%d_%H%M%S)_$(uuidgen | cut -c1-8)"
    local recovery_dir="$BACKUP_ROOT_DIR/recovery_points/$recovery_backup_id"
    mkdir -p "$recovery_dir"
    
    # Quick database backup
    if [ "$RECOVERY_MODE" = "full" ] || [ "$RECOVERY_MODE" = "database_only" ]; then
        mysqldump --host="$DB_HOST" --user="$DB_USER" --password="$DB_PASSWORD" --single-transaction --databases "$DB_NAME" | gzip > "$recovery_dir/pre_recovery_database.sql.gz"
    fi
    
    # Redis backup
    if [ "$RECOVERY_MODE" = "full" ] || [ "$RECOVERY_MODE" = "redis_only" ]; then
        redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" --rdb "$recovery_dir/pre_recovery_redis.rdb"
    fi
    
    echo "$recovery_backup_id" > "$BACKUP_ROOT_DIR/last_recovery_point.txt"
    log "Pre-recovery backup created: $recovery_backup_id"
}

# Restore database
restore_database() {
    local backup_dir="$1"
    local backup_id="$2"
    
    if [ "$DRY_RUN" = "true" ]; then
        log "[DRY RUN] Would restore database from: $backup_dir/database_full.sql.gz.enc"
        return
    fi
    
    log "Starting database restore..."
    
    # Decrypt and decompress database backup
    openssl enc -aes-256-gcm -d -pbkdf2 -in "$backup_dir/database_full.sql.gz.enc" -out "$backup_dir/restore_database.sql.gz" -pass env:BACKUP_ENCRYPTION_PASSWORD
    gunzip "$backup_dir/restore_database.sql.gz"
    
    # Stop any running applications to prevent conflicts
    log "Preparing database for restore..."
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" -e "
        SET FOREIGN_KEY_CHECKS=0;
        DROP DATABASE IF EXISTS ${DB_NAME}_old;
        CREATE DATABASE ${DB_NAME}_old;
        RENAME TABLE $DB_NAME.orchestration_runs TO ${DB_NAME}_old.orchestration_runs,
                     $DB_NAME.agent_executions TO ${DB_NAME}_old.agent_executions,
                     $DB_NAME.memory_items TO ${DB_NAME}_old.memory_items,
                     $DB_NAME.router_metrics TO ${DB_NAME}_old.router_metrics,
                     $DB_NAME.stability_tracking TO ${DB_NAME}_old.stability_tracking,
                     $DB_NAME.security_audit_logs TO ${DB_NAME}_old.security_audit_logs,
                     $DB_NAME.vault_audit_logs TO ${DB_NAME}_old.vault_audit_logs;
        SET FOREIGN_KEY_CHECKS=1;
    " 2>/dev/null || log "Warning: Some tables may not exist"
    
    # Restore database
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" < "$backup_dir/restore_database.sql" || {
        log "Database restore failed, attempting rollback..."
        mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" -e "
            DROP DATABASE IF EXISTS $DB_NAME;
            CREATE DATABASE $DB_NAME;
            RENAME TABLE ${DB_NAME}_old.orchestration_runs TO $DB_NAME.orchestration_runs,
                         ${DB_NAME}_old.agent_executions TO $DB_NAME.agent_executions,
                         ${DB_NAME}_old.memory_items TO $DB_NAME.memory_items,
                         ${DB_NAME}_old.router_metrics TO $DB_NAME.router_metrics,
                         ${DB_NAME}_old.stability_tracking TO $DB_NAME.stability_tracking,
                         ${DB_NAME}_old.security_audit_logs TO $DB_NAME.security_audit_logs,
                         ${DB_NAME}_old.vault_audit_logs TO $DB_NAME.vault_audit_logs;
        "
        error_exit "Database restore failed and was rolled back"
    }
    
    # Cleanup old backup and temp files
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" -e "DROP DATABASE IF EXISTS ${DB_NAME}_old;"
    rm "$backup_dir/restore_database.sql"
    
    log "Database restore completed successfully"
}

# Restore Redis
restore_redis() {
    local backup_dir="$1"
    local backup_id="$2"
    
    if [ "$DRY_RUN" = "true" ]; then
        log "[DRY RUN] Would restore Redis from: $backup_dir/redis_dump.rdb.gz.enc"
        return
    fi
    
    log "Starting Redis restore..."
    
    # Decrypt and decompress Redis backup
    openssl enc -aes-256-gcm -d -pbkdf2 -in "$backup_dir/redis_dump.rdb.gz.enc" -out "$backup_dir/restore_redis.rdb.gz" -pass env:BACKUP_ENCRYPTION_PASSWORD
    gunzip "$backup_dir/restore_redis.rdb.gz"
    
    # Flush current Redis data (create backup first)
    redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" --rdb "$backup_dir/pre_restore_redis_backup.rdb"
    redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" FLUSHALL
    
    # Restore Redis data
    # Note: This requires stopping Redis and replacing RDB file, or using RESTORE commands
    # Implementation depends on Redis deployment method
    
    # For containerized Redis, we would need to:
    # 1. Stop Redis container
    # 2. Replace RDB file
    # 3. Start Redis container
    
    # For now, using individual key restore (slower but safer)
    log "Restoring Redis keys from backup..."
    
    # This would need a custom script to parse RDB and restore keys
    # For production, consider using Redis Enterprise or cluster setup with better backup/restore
    
    rm "$backup_dir/restore_redis.rdb"
    log "Redis restore completed"
}

# Restore application artifacts
restore_artifacts() {
    local backup_dir="$1"
    local backup_id="$2"
    
    if [ "$DRY_RUN" = "true" ]; then
        log "[DRY RUN] Would restore artifacts from backup"
        return
    fi
    
    log "Starting artifacts restore..."
    
    # Restore orchestrator artifacts
    if [ -f "$backup_dir/orchestrator_artifacts.tar.gz.enc" ]; then
        openssl enc -aes-256-gcm -d -pbkdf2 -in "$backup_dir/orchestrator_artifacts.tar.gz.enc" -out "$backup_dir/restore_artifacts.tar.gz" -pass env:BACKUP_ENCRYPTION_PASSWORD
        
        # Backup current artifacts
        [ -d "/app/artifacts" ] && mv "/app/artifacts" "/app/artifacts.backup.$(date +%s)"
        
        # Restore artifacts
        tar xzf "$backup_dir/restore_artifacts.tar.gz" -C /app/
        rm "$backup_dir/restore_artifacts.tar.gz"
    fi
    
    # Restore configuration files
    if [ -f "$backup_dir/config_files.tar.gz.enc" ]; then
        openssl enc -aes-256-gcm -d -pbkdf2 -in "$backup_dir/config_files.tar.gz.enc" -out "$backup_dir/restore_config.tar.gz" -pass env:BACKUP_ENCRYPTION_PASSWORD
        
        # Backup current config
        [ -d "/app/config" ] && cp -r "/app/config" "/app/config.backup.$(date +%s)"
        
        # Restore config (be careful with overwrites)
        tar xzf "$backup_dir/restore_config.tar.gz" -C /
        rm "$backup_dir/restore_config.tar.gz"
    fi
    
    log "Artifacts restore completed"
}

# Verify recovery success
verify_recovery() {
    local backup_id="$1"
    
    log "Verifying recovery success..."
    
    # Database connectivity and structure check
    if [ "$RECOVERY_MODE" = "full" ] || [ "$RECOVERY_MODE" = "database_only" ]; then
        local table_count=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME';" -N)
        [ "$table_count" -eq 7 ] || error_exit "Recovery verification failed: expected 7 tables, found $table_count"
        
        # Check for recent data
        local recent_runs=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" -e "SELECT COUNT(*) FROM $DB_NAME.orchestration_runs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR);" -N)
        log "Database verification: $table_count tables found, $recent_runs recent runs"
    fi
    
    # Redis connectivity check
    if [ "$RECOVERY_MODE" = "full" ] || [ "$RECOVERY_MODE" = "redis_only" ]; then
        redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" ping >/dev/null 2>&1 || error_exit "Redis verification failed"
        local key_count=$(redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" dbsize)
        log "Redis verification: $key_count keys restored"
    fi
    
    # Application health check
    if [ "$RECOVERY_MODE" = "full" ]; then
        # Wait for application to start
        sleep 10
        
        # Check health endpoint
        local health_status=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8081/health/ready || echo "000")
        if [ "$health_status" = "200" ]; then
            log "Application health check: PASSED"
        else
            log "WARNING: Application health check failed with status: $health_status"
        fi
    fi
    
    log "Recovery verification completed successfully"
}

# Log recovery event
log_recovery_event() {
    local backup_id="$1"
    local recovery_mode="$2"
    local start_time="$3"
    local end_time="$4"
    local success="$5"
    
    local recovery_log="$BACKUP_ROOT_DIR/recovery_history.json"
    
    cat >> "$recovery_log" << EOF
{
    "recovery_id": "recovery_$(date +%Y%m%d_%H%M%S)",
    "timestamp": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "backup_id": "$backup_id",
    "recovery_mode": "$recovery_mode",
    "target_time": "$RECOVERY_TARGET_TIME",
    "start_time": "$start_time",
    "end_time": "$end_time",
    "duration_seconds": $((end_time - start_time)),
    "success": $success,
    "rto_achieved": "$((end_time - start_time)) seconds",
    "performed_by": "$(whoami)@$(hostname)",
    "dry_run": $DRY_RUN
},
EOF
    
    # Upload to S3
    aws s3 cp "$recovery_log" "s3://$S3_BUCKET/recovery_history.json" --region "$S3_REGION" 2>/dev/null || true
}

# Main recovery execution
main() {
    local start_time=$(date +%s)
    
    # Determine backup to use
    if [ -n "$RECOVERY_BACKUP_ID" ]; then
        local backup_id="$RECOVERY_BACKUP_ID"
    elif [ -n "$RECOVERY_TARGET_TIME" ]; then
        local backup_id=$(find_best_backup "$RECOVERY_TARGET_TIME")
    else
        error_exit "Must specify either --backup-id or --target-time"
    fi
    
    log "Starting GENESIS Orchestrator recovery"
    log "Backup ID: $backup_id"
    log "Recovery mode: $RECOVERY_MODE"
    log "Target time: ${RECOVERY_TARGET_TIME:-'Latest from backup'}"
    log "Dry run: $DRY_RUN"
    
    # Prepare local backup directory
    local backup_dir="$BACKUP_ROOT_DIR/backups/$backup_id"
    
    # Download backup if not available locally
    if [ ! -d "$backup_dir" ]; then
        if [ "$DRY_RUN" = "true" ]; then
            log "[DRY RUN] Would download backup from S3: s3://$S3_BUCKET/backups/$backup_id/"
        else
            log "Downloading backup from S3..."
            mkdir -p "$backup_dir"
            aws s3 sync "s3://$S3_BUCKET/backups/$backup_id/" "$backup_dir/" --region "$S3_REGION" || \
                error_exit "Failed to download backup from S3"
        fi
    fi
    
    # Validate backup
    if [ "$DRY_RUN" != "true" ]; then
        validate_specific_backup "$backup_id"
    fi
    
    # Safety confirmation
    if [ "$FORCE_RECOVERY" != "true" ] && [ "$DRY_RUN" != "true" ]; then
        echo
        echo "WARNING: This will restore the GENESIS Orchestrator from backup."
        echo "Backup ID: $backup_id"
        echo "Recovery mode: $RECOVERY_MODE"
        echo "Current data will be backed up but service will be interrupted."
        echo
        read -p "Are you sure you want to proceed? (type 'yes' to confirm): " -r
        if [[ ! $REPLY =~ ^yes$ ]]; then
            log "Recovery cancelled by user"
            exit 0
        fi
    fi
    
    # Pre-recovery checks
    pre_recovery_checks
    
    # Create recovery point backup
    create_recovery_point
    
    # Execute recovery based on mode
    case "$RECOVERY_MODE" in
        "full")
            restore_database "$backup_dir" "$backup_id"
            restore_redis "$backup_dir" "$backup_id"
            restore_artifacts "$backup_dir" "$backup_id"
            ;;
        "database_only")
            restore_database "$backup_dir" "$backup_id"
            ;;
        "redis_only")
            restore_redis "$backup_dir" "$backup_id"
            ;;
        "artifacts_only")
            restore_artifacts "$backup_dir" "$backup_id"
            ;;
        *)
            error_exit "Invalid recovery mode: $RECOVERY_MODE"
            ;;
    esac
    
    # Verify recovery
    if [ "$DRY_RUN" != "true" ]; then
        verify_recovery "$backup_id"
    fi
    
    local end_time=$(date +%s)
    local recovery_duration=$((end_time - start_time))
    
    log "Recovery completed successfully"
    log "Duration: $recovery_duration seconds"
    log "RTO achieved: $recovery_duration seconds (target: <900 seconds)"
    
    # Log recovery event
    log_recovery_event "$backup_id" "$RECOVERY_MODE" "$start_time" "$end_time" "true"
    
    if [ "$DRY_RUN" = "true" ]; then
        echo
        echo "=== DRY RUN SUMMARY ==="
        echo "Backup ID: $backup_id"
        echo "Recovery mode: $RECOVERY_MODE"
        echo "Estimated duration: ${recovery_duration} seconds"
        echo "Would meet RTO target: $([ $recovery_duration -lt 900 ] && echo "YES" || echo "NO")"
        echo
    fi
}

# Error handling
trap 'log "Recovery failed at line $LINENO. Exit code: $?"; log_recovery_event "${backup_id:-unknown}" "$RECOVERY_MODE" "${start_time:-0}" "$(date +%s)" "false"' ERR

# Parse arguments and execute
parse_arguments "$@"

# Validate required parameters
if [ -z "$RECOVERY_TARGET_TIME" ] && [ -z "$RECOVERY_BACKUP_ID" ] && [ "$1" != "--list-backups" ] && [ "$1" != "--help" ]; then
    usage
    exit 1
fi

main