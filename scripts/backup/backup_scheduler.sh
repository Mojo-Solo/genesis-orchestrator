#!/bin/bash

# GENESIS Orchestrator - Backup Scheduler and Orchestration
# Enterprise-grade backup scheduling with intelligent automation
# Manages backup frequency, retention, and automated validation

set -euo pipefail

# Configuration
BACKUP_ROOT_DIR="${BACKUP_ROOT_DIR:-/var/backups/genesis}"
SCHEDULE_CONFIG_FILE="${SCHEDULE_CONFIG_FILE:-/etc/genesis/backup_schedule.json}"
STATE_FILE="${BACKUP_ROOT_DIR}/scheduler_state.json"
LOCK_FILE="/var/run/genesis_backup_scheduler.lock"
LOG_FILE="${BACKUP_ROOT_DIR}/logs/scheduler.log"

# Backup types and frequencies
INCREMENTAL_INTERVAL="${INCREMENTAL_INTERVAL:-300}"    # 5 minutes
DIFFERENTIAL_INTERVAL="${DIFFERENTIAL_INTERVAL:-3600}" # 1 hour  
FULL_INTERVAL="${FULL_INTERVAL:-21600}"               # 6 hours
VALIDATION_INTERVAL="${VALIDATION_INTERVAL:-7200}"    # 2 hours

# Advanced scheduling
PEAK_HOURS_START="${PEAK_HOURS_START:-08}"
PEAK_HOURS_END="${PEAK_HOURS_END:-18}"
MAINTENANCE_WINDOW_START="${MAINTENANCE_WINDOW_START:-02}"
MAINTENANCE_WINDOW_END="${MAINTENANCE_WINDOW_END:-04}"

# Resource management
MAX_CONCURRENT_BACKUPS="${MAX_CONCURRENT_BACKUPS:-2}"
CPU_THRESHOLD="${CPU_THRESHOLD:-80}"
IO_THRESHOLD="${IO_THRESHOLD:-70}"
DISK_SPACE_MIN="${DISK_SPACE_MIN:-20}"  # Minimum 20% free space

# Logging setup
mkdir -p "$(dirname "$LOG_FILE")" "$(dirname "$STATE_FILE")" "$(dirname "$SCHEDULE_CONFIG_FILE")"

exec 1> >(tee -a "$LOG_FILE")
exec 2>&1

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [$$] $*"
}

error_exit() {
    log "ERROR: $1"
    cleanup_lock
    exit 1
}

# Lock management
acquire_lock() {
    if (set -C; echo $$ > "$LOCK_FILE") 2>/dev/null; then
        log "Acquired scheduler lock"
        return 0
    else
        local lock_pid=$(cat "$LOCK_FILE" 2>/dev/null || echo "")
        if [ -n "$lock_pid" ] && ! kill -0 "$lock_pid" 2>/dev/null; then
            log "Removing stale lock (PID: $lock_pid)"
            rm -f "$LOCK_FILE"
            acquire_lock
        else
            error_exit "Scheduler already running (PID: $lock_pid)"
        fi
    fi
}

cleanup_lock() {
    if [ -f "$LOCK_FILE" ]; then
        local lock_pid=$(cat "$LOCK_FILE" 2>/dev/null || echo "")
        if [ "$lock_pid" = "$$" ]; then
            rm -f "$LOCK_FILE"
            log "Released scheduler lock"
        fi
    fi
}

trap cleanup_lock EXIT

# Initialize default schedule configuration
initialize_schedule_config() {
    if [ ! -f "$SCHEDULE_CONFIG_FILE" ]; then
        log "Creating default schedule configuration"
        cat > "$SCHEDULE_CONFIG_FILE" << EOF
{
    "backup_schedules": {
        "incremental": {
            "enabled": true,
            "interval_seconds": $INCREMENTAL_INTERVAL,
            "retention_days": 7,
            "description": "Continuous incremental backups every 5 minutes"
        },
        "differential": {
            "enabled": true,
            "interval_seconds": $DIFFERENTIAL_INTERVAL,
            "retention_days": 30,
            "description": "Differential backups every hour"
        },
        "full": {
            "enabled": true,
            "interval_seconds": $FULL_INTERVAL,
            "retention_days": 90,
            "description": "Full backups every 6 hours"
        }
    },
    "validation_schedule": {
        "enabled": true,
        "interval_seconds": $VALIDATION_INTERVAL,
        "test_restore": true,
        "description": "Backup validation every 2 hours"
    },
    "maintenance_windows": [
        {
            "name": "daily_maintenance",
            "start_hour": $MAINTENANCE_WINDOW_START,
            "end_hour": $MAINTENANCE_WINDOW_END,
            "timezone": "UTC",
            "full_backup_allowed": true,
            "intensive_operations": true
        }
    ],
    "peak_hours": {
        "start_hour": $PEAK_HOURS_START,
        "end_hour": $PEAK_HOURS_END,
        "timezone": "UTC",
        "reduced_backup_frequency": true,
        "no_validation_tests": true
    },
    "resource_limits": {
        "max_concurrent_backups": $MAX_CONCURRENT_BACKUPS,
        "cpu_threshold_percent": $CPU_THRESHOLD,
        "io_threshold_percent": $IO_THRESHOLD,
        "min_disk_space_percent": $DISK_SPACE_MIN
    },
    "retention_policies": {
        "default": {
            "daily_backups": 30,
            "weekly_backups": 12,
            "monthly_backups": 12,
            "yearly_backups": 7
        },
        "compliance": {
            "encryption_required": true,
            "offsite_replication": true,
            "audit_logging": true,
            "immutable_backups": false
        }
    }
}
EOF
        log "Default schedule configuration created"
    fi
}

# Initialize scheduler state
initialize_state() {
    if [ ! -f "$STATE_FILE" ]; then
        log "Initializing scheduler state"
        cat > "$STATE_FILE" << EOF
{
    "last_runs": {
        "incremental": null,
        "differential": null,
        "full": null,
        "validation": null
    },
    "running_jobs": {},
    "statistics": {
        "total_backups": 0,
        "successful_backups": 0,
        "failed_backups": 0,
        "total_validations": 0,
        "successful_validations": 0,
        "failed_validations": 0
    },
    "last_cleanup": null,
    "next_scheduled": {}
}
EOF
        log "Scheduler state initialized"
    fi
}

# Read configuration
read_config() {
    local key="$1"
    jq -r "$key" "$SCHEDULE_CONFIG_FILE" 2>/dev/null || echo "null"
}

read_state() {
    local key="$1"
    jq -r "$key" "$STATE_FILE" 2>/dev/null || echo "null"
}

update_state() {
    local key="$1"
    local value="$2"
    local temp_file="${STATE_FILE}.tmp"
    
    jq --arg key "$key" --argjson value "$value" 'setpath($key | split("."); $value)' "$STATE_FILE" > "$temp_file" && mv "$temp_file" "$STATE_FILE"
}

# System resource monitoring
check_system_resources() {
    log "Checking system resources..."
    
    # CPU usage check
    local cpu_usage=$(top -bn1 | grep "Cpu(s)" | awk '{print $2}' | sed 's/%us,//' | cut -d'%' -f1 2>/dev/null || echo "0")
    if [ -z "$cpu_usage" ]; then
        # Alternative method for different systems
        cpu_usage=$(awk '{u=$2+$4; t=$2+$4+$5; if (NR==1){u1=u; t1=t;} else print ($2+$4-u1) * 100 / (t-t1) "%"; }' <(grep 'cpu ' /proc/stat; sleep 1; grep 'cpu ' /proc/stat) 2>/dev/null | cut -d'%' -f1 || echo "0")
    fi
    
    # Memory usage check
    local mem_usage=$(free | grep Mem | awk '{printf "%.1f", $3/$2 * 100.0}' 2>/dev/null || echo "0")
    
    # Disk space check
    local disk_usage=$(df "$BACKUP_ROOT_DIR" | awk 'NR==2 {print $5}' | sed 's/%//' 2>/dev/null || echo "0")
    local disk_free_percent=$((100 - disk_usage))
    
    # IO usage check (simplified)
    local io_usage=$(iostat -x 1 1 2>/dev/null | awk 'NR>3 {sum+=$10} END {print sum/NR}' 2>/dev/null || echo "0")
    
    # Check against thresholds
    local cpu_ok=$((cpu_usage < CPU_THRESHOLD))
    local disk_ok=$((disk_free_percent > DISK_SPACE_MIN))
    local io_ok=$(($(echo "$io_usage < $IO_THRESHOLD" | bc -l 2>/dev/null || echo "1")))
    
    log "System resources - CPU: ${cpu_usage}%, Memory: ${mem_usage}%, Disk free: ${disk_free_percent}%, IO: ${io_usage}%"
    
    # Return 0 if all resources are within limits
    if [ $cpu_ok -eq 1 ] && [ $disk_ok -eq 1 ] && [ $io_ok -eq 1 ]; then
        return 0
    else
        log "Resource constraints detected - CPU: $cpu_ok, Disk: $disk_ok, IO: $io_ok"
        return 1
    fi
}

# Check if we're in maintenance window
is_maintenance_window() {
    local current_hour=$(date +%H)
    local maintenance_start=$(read_config ".maintenance_windows[0].start_hour")
    local maintenance_end=$(read_config ".maintenance_windows[0].end_hour")
    
    # Handle overnight maintenance windows
    if [ "$maintenance_start" -lt "$maintenance_end" ]; then
        [ "$current_hour" -ge "$maintenance_start" ] && [ "$current_hour" -lt "$maintenance_end" ]
    else
        [ "$current_hour" -ge "$maintenance_start" ] || [ "$current_hour" -lt "$maintenance_end" ]
    fi
}

# Check if we're in peak hours
is_peak_hours() {
    local current_hour=$(date +%H)
    local peak_start=$(read_config ".peak_hours.start_hour")
    local peak_end=$(read_config ".peak_hours.end_hour")
    
    [ "$current_hour" -ge "$peak_start" ] && [ "$current_hour" -lt "$peak_end" ]
}

# Check if backup type should run
should_run_backup() {
    local backup_type="$1"
    local current_time=$(date +%s)
    
    # Check if backup type is enabled
    local enabled=$(read_config ".backup_schedules.$backup_type.enabled")
    if [ "$enabled" != "true" ]; then
        return 1
    fi
    
    # Get interval and last run time
    local interval=$(read_config ".backup_schedules.$backup_type.interval_seconds")
    local last_run=$(read_state ".last_runs.$backup_type")
    
    # If never run, should run now
    if [ "$last_run" = "null" ]; then
        return 0
    fi
    
    # Check if enough time has passed
    local last_run_timestamp=$(date -d "$last_run" +%s 2>/dev/null || echo "0")
    local time_since_last=$((current_time - last_run_timestamp))
    
    if [ $time_since_last -ge $interval ]; then
        return 0
    else
        return 1
    fi
}

# Determine backup priority and scheduling
calculate_backup_priority() {
    local backup_type="$1"
    local priority=50  # Base priority
    
    # Adjust based on backup type
    case "$backup_type" in
        "incremental")
            priority=90  # Highest priority for frequent incremental
            ;;
        "differential")
            priority=70
            ;;
        "full")
            priority=60
            ;;
    esac
    
    # Adjust based on time windows
    if is_maintenance_window; then
        priority=$((priority + 20))  # Higher priority during maintenance
    elif is_peak_hours; then
        priority=$((priority - 30))  # Lower priority during peak hours
        
        # Skip non-critical backups during peak hours
        if [ "$backup_type" = "full" ] && [ $priority -lt 40 ]; then
            return 1  # Skip this backup
        fi
    fi
    
    # Adjust based on how overdue the backup is
    local current_time=$(date +%s)
    local last_run=$(read_state ".last_runs.$backup_type")
    
    if [ "$last_run" != "null" ]; then
        local last_run_timestamp=$(date -d "$last_run" +%s 2>/dev/null || echo "0")
        local interval=$(read_config ".backup_schedules.$backup_type.interval_seconds")
        local time_since_last=$((current_time - last_run_timestamp))
        local overdue_factor=$((time_since_last * 100 / interval))
        
        if [ $overdue_factor -gt 150 ]; then  # More than 1.5x overdue
            priority=$((priority + 30))
        elif [ $overdue_factor -gt 120 ]; then  # More than 1.2x overdue
            priority=$((priority + 15))
        fi
    fi
    
    echo $priority
}

# Execute backup with job management
execute_backup() {
    local backup_type="$1"
    local priority="$2"
    local job_id="backup_${backup_type}_$(date +%s)"
    
    log "Starting $backup_type backup (Job ID: $job_id, Priority: $priority)"
    
    # Record job start
    local job_info=$(echo '{}' | jq --arg id "$job_id" --arg type "$backup_type" --argjson priority "$priority" --arg start "$(date -u +%Y-%m-%dT%H:%M:%SZ)" '{
        "job_id": $id,
        "backup_type": $type,
        "priority": $priority,
        "start_time": $start,
        "status": "running"
    }')
    
    local running_jobs=$(read_state ".running_jobs")
    local updated_jobs=$(echo "$running_jobs" | jq --argjson job "$job_info" --arg id "$job_id" '.[$id] = $job')
    update_state ".running_jobs" "$updated_jobs"
    
    # Execute the actual backup
    local backup_success=false
    local backup_script="$BACKUP_ROOT_DIR/../scripts/backup/automated_backup.sh"
    
    if [ -x "$backup_script" ]; then
        local start_time=$(date +%s)
        
        # Set environment variables for backup type
        export BACKUP_TYPE="$backup_type"
        export BACKUP_JOB_ID="$job_id"
        
        if "$backup_script"; then
            backup_success=true
            local end_time=$(date +%s)
            local duration=$((end_time - start_time))
            
            log "$backup_type backup completed successfully (${duration}s)"
            
            # Update statistics
            local stats=$(read_state ".statistics")
            local updated_stats=$(echo "$stats" | jq '.total_backups += 1 | .successful_backups += 1')
            update_state ".statistics" "$updated_stats"
        else
            local end_time=$(date +%s)
            local duration=$((end_time - start_time))
            
            log "$backup_type backup failed (${duration}s)"
            
            # Update statistics
            local stats=$(read_state ".statistics")
            local updated_stats=$(echo "$stats" | jq '.total_backups += 1 | .failed_backups += 1')
            update_state ".statistics" "$updated_stats"
        fi
    else
        log "Backup script not found: $backup_script"
    fi
    
    # Update job status and last run time
    local final_job_info=$(echo "$job_info" | jq --arg end "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --arg status "$([ "$backup_success" = true ] && echo "success" || echo "failed")" '.end_time = $end | .status = $status')
    
    if [ "$backup_success" = true ]; then
        update_state ".last_runs.$backup_type" "\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\""
    fi
    
    # Remove from running jobs
    local final_running_jobs=$(echo "$updated_jobs" | jq --arg id "$job_id" 'del(.[$id])')
    update_state ".running_jobs" "$final_running_jobs"
    
    return $([ "$backup_success" = true ] && echo 0 || echo 1)
}

# Execute backup validation
execute_validation() {
    local job_id="validation_$(date +%s)"
    
    log "Starting backup validation (Job ID: $job_id)"
    
    # Find latest backup to validate
    local latest_backup=$(ls -1t "$BACKUP_ROOT_DIR/backups/" 2>/dev/null | head -1 || echo "")
    
    if [ -z "$latest_backup" ]; then
        log "No backups found for validation"
        return 1
    fi
    
    # Record validation job
    local job_info=$(echo '{}' | jq --arg id "$job_id" --arg backup "$latest_backup" --arg start "$(date -u +%Y-%m-%dT%H:%M:%SZ)" '{
        "job_id": $id,
        "backup_id": $backup,
        "start_time": $start,
        "status": "running"
    }')
    
    local running_jobs=$(read_state ".running_jobs")
    local updated_jobs=$(echo "$running_jobs" | jq --argjson job "$job_info" --arg id "$job_id" '.[$id] = $job')
    update_state ".running_jobs" "$updated_jobs"
    
    # Execute validation
    local validation_success=false
    local validation_script="$BACKUP_ROOT_DIR/../scripts/backup/backup_validation.sh"
    
    if [ -x "$validation_script" ]; then
        local start_time=$(date +%s)
        
        if "$validation_script" validate "$latest_backup"; then
            validation_success=true
            local end_time=$(date +%s)
            local duration=$((end_time - start_time))
            
            log "Backup validation completed successfully (${duration}s)"
            
            # Update statistics
            local stats=$(read_state ".statistics")
            local updated_stats=$(echo "$stats" | jq '.total_validations += 1 | .successful_validations += 1')
            update_state ".statistics" "$updated_stats"
        else
            local end_time=$(date +%s)
            local duration=$((end_time - start_time))
            
            log "Backup validation failed (${duration}s)"
            
            # Update statistics
            local stats=$(read_state ".statistics")
            local updated_stats=$(echo "$stats" | jq '.total_validations += 1 | .failed_validations += 1')
            update_state ".statistics" "$updated_stats"
            
            # Send alert for validation failure
            send_validation_alert "$latest_backup" "Backup validation failed"
        fi
    else
        log "Validation script not found: $validation_script"
    fi
    
    # Update job status
    if [ "$validation_success" = true ]; then
        update_state ".last_runs.validation" "\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\""
    fi
    
    # Remove from running jobs
    local final_running_jobs=$(echo "$updated_jobs" | jq --arg id "$job_id" 'del(.[$id])')
    update_state ".running_jobs" "$final_running_jobs"
    
    return $([ "$validation_success" = true ] && echo 0 || echo 1)
}

# Send validation failure alert
send_validation_alert() {
    local backup_id="$1"
    local message="$2"
    
    # Send Slack notification if configured
    if [ -n "${SLACK_WEBHOOK_URL:-}" ]; then
        curl -X POST "$SLACK_WEBHOOK_URL" \
            -H 'Content-type: application/json' \
            --data "{
                \"text\":\"⚠️ GENESIS Backup Validation Failed\",
                \"attachments\":[{
                    \"color\":\"warning\",
                    \"fields\":[
                        {\"title\":\"Backup ID\",\"value\":\"$backup_id\",\"short\":true},
                        {\"title\":\"Message\",\"value\":\"$message\",\"short\":false},
                        {\"title\":\"Time\",\"value\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",\"short\":true}
                    ]
                }]
            }" 2>/dev/null || log "Failed to send Slack validation alert"
    fi
}

# Cleanup old backups based on retention policy
cleanup_old_backups() {
    log "Starting backup cleanup based on retention policies..."
    
    local retention_config=$(read_config ".retention_policies.default")
    
    # Daily backups cleanup (keep last N days)
    local daily_retention=$(echo "$retention_config" | jq -r '.daily_backups')
    if [ "$daily_retention" != "null" ] && [ "$daily_retention" -gt 0 ]; then
        find "$BACKUP_ROOT_DIR/backups" -name "backup_*" -type d -mtime +$daily_retention -exec rm -rf {} \; 2>/dev/null || true
        log "Cleaned up daily backups older than $daily_retention days"
    fi
    
    # Update cleanup timestamp
    update_state ".last_cleanup" "\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\""
    
    log "Backup cleanup completed"
}

# Calculate next scheduled run times
calculate_next_schedules() {
    local current_time=$(date +%s)
    local next_schedules="{}"
    
    # Calculate for each backup type
    for backup_type in "incremental" "differential" "full"; do
        local enabled=$(read_config ".backup_schedules.$backup_type.enabled")
        if [ "$enabled" = "true" ]; then
            local interval=$(read_config ".backup_schedules.$backup_type.interval_seconds")
            local last_run=$(read_state ".last_runs.$backup_type")
            
            if [ "$last_run" = "null" ]; then
                # Never run, schedule immediately
                next_schedules=$(echo "$next_schedules" | jq --arg type "$backup_type" --argjson time "$current_time" '.[$type] = $time')
            else
                local last_run_timestamp=$(date -d "$last_run" +%s 2>/dev/null || echo "0")
                local next_run=$((last_run_timestamp + interval))
                next_schedules=$(echo "$next_schedules" | jq --arg type "$backup_type" --argjson time "$next_run" '.[$type] = $time')
            fi
        fi
    done
    
    # Calculate for validation
    local validation_enabled=$(read_config ".validation_schedule.enabled")
    if [ "$validation_enabled" = "true" ]; then
        local validation_interval=$(read_config ".validation_schedule.interval_seconds")
        local last_validation=$(read_state ".last_runs.validation")
        
        if [ "$last_validation" = "null" ]; then
            next_schedules=$(echo "$next_schedules" | jq --argjson time "$current_time" '.validation = $time')
        else
            local last_validation_timestamp=$(date -d "$last_validation" +%s 2>/dev/null || echo "0")
            local next_validation=$((last_validation_timestamp + validation_interval))
            next_schedules=$(echo "$next_schedules" | jq --argjson time "$next_validation" '.validation = $time')
        fi
    fi
    
    update_state ".next_scheduled" "$next_schedules"
}

# Main scheduling loop
run_scheduler() {
    log "Starting GENESIS backup scheduler..."
    
    initialize_schedule_config
    initialize_state
    
    local iteration=0
    
    while true; do
        iteration=$((iteration + 1))
        log "Scheduler iteration $iteration"
        
        # Check system resources
        if ! check_system_resources; then
            log "System resources constrained, skipping this iteration"
            sleep 60
            continue
        fi
        
        # Count currently running jobs
        local running_jobs=$(read_state ".running_jobs")
        local job_count=$(echo "$running_jobs" | jq 'length')
        local max_concurrent=$(read_config ".resource_limits.max_concurrent_backups")
        
        if [ "$job_count" -ge "$max_concurrent" ]; then
            log "Maximum concurrent jobs running ($job_count/$max_concurrent), waiting..."
            sleep 30
            continue
        fi
        
        # Check if validation should run
        if should_run_backup "validation"; then
            local validation_enabled=$(read_config ".validation_schedule.enabled")
            if [ "$validation_enabled" = "true" ]; then
                # Don't run validation during peak hours unless it's critical
                if ! is_peak_hours || [ "$iteration" -gt 20 ]; then
                    execute_validation &
                    sleep 5  # Brief delay between job starts
                fi
            fi
        fi
        
        # Determine which backups should run and their priorities
        local backup_candidates=()
        
        for backup_type in "incremental" "differential" "full"; do
            if should_run_backup "$backup_type"; then
                local priority=$(calculate_backup_priority "$backup_type")
                if [ "$priority" -gt 0 ]; then  # Priority > 0 means should run
                    backup_candidates+=("$backup_type:$priority")
                fi
            fi
        done
        
        # Sort candidates by priority (highest first)
        if [ ${#backup_candidates[@]} -gt 0 ]; then
            IFS=$'\n' sorted_candidates=($(printf '%s\n' "${backup_candidates[@]}" | sort -t: -k2 -nr))
            
            # Execute highest priority backup if we have capacity
            local updated_job_count=$(echo "$(read_state ".running_jobs")" | jq 'length')
            if [ "$updated_job_count" -lt "$max_concurrent" ]; then
                local top_candidate="${sorted_candidates[0]}"
                local backup_type="${top_candidate%:*}"
                local priority="${top_candidate#*:}"
                
                execute_backup "$backup_type" "$priority" &
                sleep 5  # Brief delay between job starts
            fi
        fi
        
        # Periodic cleanup (every 50 iterations or ~2.5 hours)
        if [ $((iteration % 50)) -eq 0 ]; then
            cleanup_old_backups
        fi
        
        # Update next scheduled times
        calculate_next_schedules
        
        # Wait before next iteration
        sleep 30
    done
}

# Show scheduler status
show_status() {
    initialize_state
    
    echo
    echo "=== GENESIS Backup Scheduler Status ==="
    echo
    
    # Current running jobs
    local running_jobs=$(read_state ".running_jobs")
    local job_count=$(echo "$running_jobs" | jq 'length')
    
    echo "Running Jobs: $job_count"
    if [ "$job_count" -gt 0 ]; then
        echo "$running_jobs" | jq -r 'to_entries[] | "  \(.value.job_id): \(.value.backup_type) (Priority: \(.value.priority), Started: \(.value.start_time))"'
    fi
    echo
    
    # Last run times
    echo "Last Backup Times:"
    local last_runs=$(read_state ".last_runs")
    echo "$last_runs" | jq -r 'to_entries[] | "  \(.key): \(.value // "Never")"'
    echo
    
    # Next scheduled times
    echo "Next Scheduled Times:"
    local next_scheduled=$(read_state ".next_scheduled")
    echo "$next_scheduled" | jq -r 'to_entries[] | "  \(.key): \(if .value then (.value | strftime("%Y-%m-%d %H:%M:%S UTC")) else "Not scheduled" end)"'
    echo
    
    # Statistics
    echo "Statistics:"
    local stats=$(read_state ".statistics")
    echo "$stats" | jq -r '"  Total Backups: \(.total_backups), Successful: \(.successful_backups), Failed: \(.failed_backups)"'
    echo "$stats" | jq -r '"  Total Validations: \(.total_validations), Successful: \(.successful_validations), Failed: \(.failed_validations)"'
    echo
    
    # Current system status
    echo "System Status:"
    echo "  Maintenance Window: $(is_maintenance_window && echo "YES" || echo "NO")"
    echo "  Peak Hours: $(is_peak_hours && echo "YES" || echo "NO")"
    
    # Resource status
    if check_system_resources >/dev/null 2>&1; then
        echo "  Resources: OK"
    else
        echo "  Resources: CONSTRAINED"
    fi
    echo
}

# Manual backup trigger
trigger_backup() {
    local backup_type="$1"
    
    log "Manual backup trigger requested: $backup_type"
    
    # Validate backup type
    case "$backup_type" in
        "incremental"|"differential"|"full")
            ;;
        *)
            error_exit "Invalid backup type: $backup_type. Use: incremental, differential, or full"
            ;;
    esac
    
    # Check if backup type is enabled
    local enabled=$(read_config ".backup_schedules.$backup_type.enabled")
    if [ "$enabled" != "true" ]; then
        error_exit "Backup type $backup_type is disabled in configuration"
    fi
    
    # Check system resources
    if ! check_system_resources; then
        log "WARNING: System resources are constrained"
        read -p "Continue with backup? (y/N): " -r
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            log "Manual backup cancelled"
            exit 0
        fi
    fi
    
    # Execute backup
    local priority=100  # High priority for manual backups
    execute_backup "$backup_type" "$priority"
}

# Command line interface
case "${1:-help}" in
    "start")
        acquire_lock
        run_scheduler
        ;;
    "status")
        show_status
        ;;
    "backup")
        if [ -z "${2:-}" ]; then
            error_exit "Usage: $0 backup <type>"
        fi
        acquire_lock
        trigger_backup "$2"
        ;;
    "validate")
        acquire_lock
        execute_validation
        ;;
    "cleanup")
        acquire_lock
        cleanup_old_backups
        ;;
    "config")
        case "${2:-show}" in
            "show")
                cat "$SCHEDULE_CONFIG_FILE" | jq '.'
                ;;
            "edit")
                ${EDITOR:-vi} "$SCHEDULE_CONFIG_FILE"
                ;;
            "validate")
                if jq empty "$SCHEDULE_CONFIG_FILE" 2>/dev/null; then
                    echo "Configuration is valid JSON"
                else
                    error_exit "Configuration contains invalid JSON"
                fi
                ;;
            *)
                error_exit "Usage: $0 config [show|edit|validate]"
                ;;
        esac
        ;;
    "help"|*)
        cat << EOF
GENESIS Orchestrator Backup Scheduler

Usage: $0 <command> [options]

Commands:
    start                        Start the backup scheduler daemon
    status                       Show current scheduler status
    backup <type>               Trigger manual backup (incremental|differential|full)
    validate                    Trigger manual backup validation
    cleanup                     Run backup cleanup based on retention policies
    config [show|edit|validate] Manage scheduler configuration
    help                        Show this help message

Examples:
    $0 start                    # Start scheduler daemon
    $0 status                   # Show current status
    $0 backup full             # Trigger manual full backup
    $0 validate                # Trigger backup validation
    $0 config edit             # Edit configuration
    $0 cleanup                 # Run retention cleanup

Configuration File: $SCHEDULE_CONFIG_FILE
State File: $STATE_FILE
Log File: $LOG_FILE

Environment Variables:
    BACKUP_ROOT_DIR             Root directory for backups
    INCREMENTAL_INTERVAL        Incremental backup interval (seconds)
    DIFFERENTIAL_INTERVAL       Differential backup interval (seconds)
    FULL_INTERVAL              Full backup interval (seconds)
    VALIDATION_INTERVAL        Validation interval (seconds)
    MAX_CONCURRENT_BACKUPS     Maximum concurrent backup jobs

EOF
        ;;
esac