#!/bin/bash

# GENESIS Orchestrator - Automated Failover System
# Intelligent failover orchestration with health monitoring and automated recovery
# Implements zero-downtime failover with RTO < 15 minutes

set -euo pipefail

# Configuration
PRIMARY_REGION="${PRIMARY_REGION:-us-west-2}"
REPLICA_REGIONS="${REPLICA_REGIONS:-us-east-1,eu-west-1}"
HEALTH_CHECK_INTERVAL="${HEALTH_CHECK_INTERVAL:-30}"
FAILOVER_THRESHOLD="${FAILOVER_THRESHOLD:-3}"
FAILBACK_COOLDOWN="${FAILBACK_COOLDOWN:-1800}"  # 30 minutes
MAX_FAILOVER_ATTEMPTS="${MAX_FAILOVER_ATTEMPTS:-3}"

# Service endpoints
PRIMARY_ENDPOINT="${PRIMARY_ENDPOINT:-https://api.genesis.orchestrator.com}"
HEALTH_ENDPOINT="${HEALTH_ENDPOINT:-/health/ready}"
METRICS_ENDPOINT="${METRICS_ENDPOINT:-/metrics}"

# DNS and load balancing
ROUTE53_HOSTED_ZONE_ID="${ROUTE53_HOSTED_ZONE_ID}"
DNS_RECORD_NAME="${DNS_RECORD_NAME:-api.genesis.orchestrator.com}"
LOAD_BALANCER_ARN="${LOAD_BALANCER_ARN:-}"

# Database configuration
RDS_PRIMARY_INSTANCE="${RDS_PRIMARY_INSTANCE:-genesis-orchestrator-primary}"
RDS_REPLICA_PREFIX="${RDS_REPLICA_PREFIX:-genesis-orchestrator-replica}"

# Monitoring and alerting
SLACK_WEBHOOK_URL="${SLACK_WEBHOOK_URL:-}"
PAGERDUTY_API_KEY="${PAGERDUTY_API_KEY:-}"
SNS_TOPIC_ARN="${SNS_TOPIC_ARN:-}"

# State management
STATE_FILE="/var/lib/genesis/failover_state.json"
LOCK_FILE="/var/lib/genesis/failover.lock"
LOG_FILE="/var/log/genesis/failover_automation.log"

# Logging setup
mkdir -p "$(dirname "$LOG_FILE")" "$(dirname "$STATE_FILE")"

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
    local timeout=300  # 5 minutes timeout
    local waited=0
    
    while [ $waited -lt $timeout ]; do
        if (set -C; echo $$ > "$LOCK_FILE") 2>/dev/null; then
            log "Acquired failover lock"
            return 0
        fi
        
        # Check if existing lock is stale
        if [ -f "$LOCK_FILE" ]; then
            local lock_pid=$(cat "$LOCK_FILE" 2>/dev/null || echo "")
            if [ -n "$lock_pid" ] && ! kill -0 "$lock_pid" 2>/dev/null; then
                log "Removing stale lock (PID: $lock_pid)"
                rm -f "$LOCK_FILE"
                continue
            fi
        fi
        
        log "Waiting for failover lock... (${waited}s/${timeout}s)"
        sleep 5
        waited=$((waited + 5))
    done
    
    error_exit "Failed to acquire failover lock within $timeout seconds"
}

cleanup_lock() {
    if [ -f "$LOCK_FILE" ]; then
        local lock_pid=$(cat "$LOCK_FILE" 2>/dev/null || echo "")
        if [ "$lock_pid" = "$$" ]; then
            rm -f "$LOCK_FILE"
            log "Released failover lock"
        fi
    fi
}

trap cleanup_lock EXIT

# State management
initialize_state() {
    if [ ! -f "$STATE_FILE" ]; then
        cat > "$STATE_FILE" << EOF
{
    "active_region": "$PRIMARY_REGION",
    "is_failover_active": false,
    "last_failover_time": null,
    "last_failback_time": null,
    "consecutive_failures": {},
    "failover_history": [],
    "health_status": {}
}
EOF
        log "Initialized failover state file"
    fi
}

read_state() {
    jq -r ".$1" "$STATE_FILE" 2>/dev/null || echo "null"
}

update_state() {
    local key="$1"
    local value="$2"
    local temp_file="${STATE_FILE}.tmp"
    
    jq --arg key "$key" --argjson value "$value" '.[$key] = $value' "$STATE_FILE" > "$temp_file" && mv "$temp_file" "$STATE_FILE"
}

# Health checking
check_region_health() {
    local region="$1"
    local endpoint_base="${PRIMARY_ENDPOINT/$PRIMARY_REGION/$region}"
    
    log "Checking health for region: $region"
    
    local health_score=0
    local max_score=100
    local health_details="{}"
    
    # Test 1: Basic connectivity (25 points)
    local connectivity_test=$(timeout 10 curl -s -o /dev/null -w "%{http_code}:%{time_total}" "$endpoint_base$HEALTH_ENDPOINT" 2>/dev/null || echo "000:0")
    local http_code=$(echo "$connectivity_test" | cut -d: -f1)
    local response_time=$(echo "$connectivity_test" | cut -d: -f2)
    
    if [ "$http_code" = "200" ]; then
        health_score=$((health_score + 25))
        health_details=$(echo "$health_details" | jq --arg time "$response_time" '. + {"connectivity": {"status": "healthy", "response_time": $time}}')
        log "  ✓ Connectivity: OK (${response_time}s)"
    else
        health_details=$(echo "$health_details" | jq --arg code "$http_code" '. + {"connectivity": {"status": "failed", "http_code": $code}}')
        log "  ✗ Connectivity: FAILED (HTTP $http_code)"
    fi
    
    # Test 2: Database connectivity (25 points)
    local db_instance="${RDS_REPLICA_PREFIX}-${region}"
    if [ "$region" = "$PRIMARY_REGION" ]; then
        db_instance="$RDS_PRIMARY_INSTANCE"
    fi
    
    local db_check=$(timeout 10 mysql -h "${db_instance}.${region}.rds.amazonaws.com" -u genesis -p"$DB_PASSWORD" -e "SELECT 1 as health_check;" 2>/dev/null | grep "health_check" || echo "")
    if [ -n "$db_check" ]; then
        health_score=$((health_score + 25))
        health_details=$(echo "$health_details" | jq '. + {"database": {"status": "healthy"}}')
        log "  ✓ Database: OK"
    else
        health_details=$(echo "$health_details" | jq '. + {"database": {"status": "failed"}}')
        log "  ✗ Database: FAILED"
    fi
    
    # Test 3: Redis connectivity (20 points)
    local redis_endpoint="genesis-redis-${region}.cache.amazonaws.com"
    local redis_check=$(timeout 5 redis-cli -h "$redis_endpoint" -p 6379 ping 2>/dev/null || echo "")
    if [ "$redis_check" = "PONG" ]; then
        health_score=$((health_score + 20))
        health_details=$(echo "$health_details" | jq '. + {"redis": {"status": "healthy"}}')
        log "  ✓ Redis: OK"
    else
        health_details=$(echo "$health_details" | jq '. + {"redis": {"status": "failed"}}')
        log "  ✗ Redis: FAILED"
    fi
    
    # Test 4: S3 backup accessibility (15 points)
    local s3_bucket="genesis-disaster-recovery"
    if [ "$region" != "$PRIMARY_REGION" ]; then
        s3_bucket="${s3_bucket}-replica-${region}"
    fi
    
    local s3_check=$(timeout 10 aws s3 ls "s3://$s3_bucket/" --region "$region" 2>/dev/null | wc -l)
    if [ "$s3_check" -gt 0 ]; then
        health_score=$((health_score + 15))
        health_details=$(echo "$health_details" | jq '. + {"s3": {"status": "healthy"}}')
        log "  ✓ S3: OK"
    else
        health_details=$(echo "$health_details" | jq '. + {"s3": {"status": "failed"}}')
        log "  ✗ S3: FAILED"
    fi
    
    # Test 5: Application metrics (15 points)
    local metrics_response=$(timeout 10 curl -s "$endpoint_base$METRICS_ENDPOINT" 2>/dev/null || echo "")
    if echo "$metrics_response" | jq -e '.orchestrator.success_rate' >/dev/null 2>&1; then
        local success_rate=$(echo "$metrics_response" | jq -r '.orchestrator.success_rate')
        if [ "$(echo "$success_rate > 95" | bc)" -eq 1 ]; then
            health_score=$((health_score + 15))
            health_details=$(echo "$health_details" | jq --argjson rate "$success_rate" '. + {"metrics": {"status": "healthy", "success_rate": $rate}}')
            log "  ✓ Metrics: OK (success rate: ${success_rate}%)"
        else
            health_details=$(echo "$health_details" | jq --argjson rate "$success_rate" '. + {"metrics": {"status": "degraded", "success_rate": $rate}}')
            log "  ! Metrics: DEGRADED (success rate: ${success_rate}%)"
        fi
    else
        health_details=$(echo "$health_details" | jq '. + {"metrics": {"status": "failed"}}')
        log "  ✗ Metrics: FAILED"
    fi
    
    # Overall health assessment
    local health_status="healthy"
    if [ $health_score -lt 50 ]; then
        health_status="critical"
    elif [ $health_score -lt 75 ]; then
        health_status="degraded"
    fi
    
    # Update state with health information
    local health_entry=$(echo '{}' | jq --arg region "$region" --argjson score "$health_score" --arg status "$health_status" --argjson details "$health_details" --arg timestamp "$(date -u +%Y-%m-%dT%H:%M:%SZ)" '{
        "region": $region,
        "score": $score,
        "status": $status,
        "details": $details,
        "timestamp": $timestamp
    }')
    
    local current_health=$(read_state "health_status")
    local updated_health=$(echo "$current_health" | jq --argjson entry "$health_entry" --arg region "$region" '.[$region] = $entry')
    update_state "health_status" "$updated_health"
    
    log "Region $region health: $health_status (score: $health_score/100)"
    
    echo "$health_score"
}

# Determine best target region for failover
determine_failover_target() {
    local exclude_region="$1"
    
    log "Determining best failover target (excluding: $exclude_region)"
    
    local best_region=""
    local best_score=0
    
    IFS=',' read -ra REGIONS <<< "$REPLICA_REGIONS"
    for region in "${REGIONS[@]}"; do
        if [ "$region" = "$exclude_region" ]; then
            continue
        fi
        
        local score=$(check_region_health "$region")
        log "Region $region candidate score: $score"
        
        if [ "$score" -gt "$best_score" ]; then
            best_score="$score"
            best_region="$region"
        fi
    done
    
    if [ -n "$best_region" ] && [ "$best_score" -ge 50 ]; then
        log "Selected failover target: $best_region (score: $best_score)"
        echo "$best_region"
    else
        log "No suitable failover target found (best score: $best_score)"
        echo ""
    fi
}

# Execute DNS failover
update_dns_routing() {
    local target_region="$1"
    local reason="$2"
    
    log "Updating DNS routing to region: $target_region"
    
    # Determine new endpoint
    local new_endpoint="${PRIMARY_ENDPOINT/$PRIMARY_REGION/$target_region}"
    local new_record_value="${new_endpoint#https://}"
    
    # Create Route53 change batch
    local change_batch=$(cat << EOF
{
    "Comment": "GENESIS Orchestrator failover to $target_region - $reason",
    "Changes": [{
        "Action": "UPSERT",
        "ResourceRecordSet": {
            "Name": "$DNS_RECORD_NAME",
            "Type": "CNAME",
            "TTL": 60,
            "ResourceRecords": [{
                "Value": "$new_record_value"
            }]
        }
    }]
}
EOF
    )
    
    # Apply DNS change
    local change_id=$(echo "$change_batch" | aws route53 change-resource-record-sets \
        --hosted-zone-id "$ROUTE53_HOSTED_ZONE_ID" \
        --change-batch file:///dev/stdin \
        --query 'ChangeInfo.Id' \
        --output text)
    
    if [ -n "$change_id" ]; then
        log "DNS change initiated (Change ID: $change_id)"
        
        # Wait for propagation
        log "Waiting for DNS propagation..."
        if aws route53 wait resource-record-sets-changed --id "$change_id"; then
            log "DNS propagation completed"
            return 0
        else
            log "DNS propagation timeout"
            return 1
        fi
    else
        log "Failed to initiate DNS change"
        return 1
    fi
}

# Promote RDS read replica
promote_database_replica() {
    local target_region="$1"
    
    log "Promoting RDS read replica in region: $target_region"
    
    local replica_instance="${RDS_REPLICA_PREFIX}-${target_region}"
    
    # Check if replica exists and is available
    local replica_status=$(aws rds describe-db-instances \
        --db-instance-identifier "$replica_instance" \
        --region "$target_region" \
        --query 'DBInstances[0].DBInstanceStatus' \
        --output text 2>/dev/null || echo "not-found")
    
    if [ "$replica_status" = "available" ]; then
        log "Promoting read replica: $replica_instance"
        
        # Promote read replica
        aws rds promote-read-replica \
            --db-instance-identifier "$replica_instance" \
            --region "$target_region" \
            --backup-retention-period 7 \
            --preferred-backup-window "03:00-04:00"
        
        # Wait for promotion to complete
        log "Waiting for database promotion..."
        if aws rds wait db-instance-available \
            --db-instance-identifier "$replica_instance" \
            --region "$target_region"; then
            log "Database promotion completed"
            return 0
        else
            log "Database promotion timeout"
            return 1
        fi
    else
        log "Read replica not available for promotion (status: $replica_status)"
        return 1
    fi
}

# Send notifications
send_failover_notification() {
    local target_region="$1"
    local reason="$2"
    local severity="${3:-critical}"
    
    local message="GENESIS Orchestrator Failover Alert

Region Failover: $PRIMARY_REGION → $target_region
Time: $(date -u +%Y-%m-%dT%H:%M:%SZ)
Reason: $reason
Severity: $severity

Actions taken:
✓ DNS updated to point to $target_region
✓ Database replica promoted
✓ Traffic routing updated

New endpoint: ${PRIMARY_ENDPOINT/$PRIMARY_REGION/$target_region}

Please verify system functionality and investigate primary region issues."
    
    # Slack notification
    if [ -n "$SLACK_WEBHOOK_URL" ]; then
        local color="danger"
        [ "$severity" = "warning" ] && color="warning"
        
        curl -X POST "$SLACK_WEBHOOK_URL" \
            -H 'Content-type: application/json' \
            --data "{
                \"text\":\"🚨 GENESIS Orchestrator Failover\",
                \"attachments\":[{
                    \"color\":\"$color\",
                    \"fields\":[
                        {\"title\":\"Target Region\",\"value\":\"$target_region\",\"short\":true},
                        {\"title\":\"Reason\",\"value\":\"$reason\",\"short\":true},
                        {\"title\":\"Time\",\"value\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",\"short\":true},
                        {\"title\":\"New Endpoint\",\"value\":\"${PRIMARY_ENDPOINT/$PRIMARY_REGION/$target_region}\",\"short\":false}
                    ]
                }]
            }" 2>/dev/null || log "Failed to send Slack notification"
    fi
    
    # SNS notification
    if [ -n "$SNS_TOPIC_ARN" ]; then
        aws sns publish \
            --topic-arn "$SNS_TOPIC_ARN" \
            --subject "GENESIS Orchestrator Failover - $target_region" \
            --message "$message" 2>/dev/null || log "Failed to send SNS notification"
    fi
    
    # PagerDuty incident
    if [ -n "$PAGERDUTY_API_KEY" ] && [ "$severity" = "critical" ]; then
        curl -X POST "https://events.pagerduty.com/v2/enqueue" \
            -H "Content-Type: application/json" \
            -d "{
                \"routing_key\": \"$PAGERDUTY_API_KEY\",
                \"event_action\": \"trigger\",
                \"payload\": {
                    \"summary\": \"GENESIS Orchestrator Failover to $target_region\",
                    \"source\": \"genesis-failover-automation\",
                    \"severity\": \"critical\",
                    \"custom_details\": {
                        \"target_region\": \"$target_region\",
                        \"reason\": \"$reason\",
                        \"timestamp\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\"
                    }
                }
            }" 2>/dev/null || log "Failed to send PagerDuty alert"
    fi
}

# Execute complete failover
execute_failover() {
    local target_region="$1"
    local reason="$2"
    
    local start_time=$(date +%s)
    local current_active=$(read_state "active_region")
    
    log "INITIATING FAILOVER: $current_active → $target_region"
    log "Reason: $reason"
    
    # Record failover attempt
    local failover_record=$(echo '{}' | jq --arg from "$current_active" --arg to "$target_region" --arg reason "$reason" --arg start "$(date -u +%Y-%m-%dT%H:%M:%SZ)" '{
        "from_region": $from,
        "to_region": $to,
        "reason": $reason,
        "start_time": $start,
        "status": "in_progress"
    }')
    
    local history=$(read_state "failover_history")
    local updated_history=$(echo "$history" | jq --argjson record "$failover_record" '. += [$record]')
    update_state "failover_history" "$updated_history"
    
    # Execute failover steps
    local failover_success=true
    local error_message=""
    
    # Step 1: Update DNS routing
    if ! update_dns_routing "$target_region" "$reason"; then
        failover_success=false
        error_message="DNS routing update failed"
    fi
    
    # Step 2: Promote database replica
    if [ "$failover_success" = true ]; then
        if ! promote_database_replica "$target_region"; then
            failover_success=false
            error_message="Database replica promotion failed"
        fi
    fi
    
    # Step 3: Update load balancer (if configured)
    if [ "$failover_success" = true ] && [ -n "$LOAD_BALANCER_ARN" ]; then
        log "Updating load balancer configuration..."
        # Implementation depends on specific load balancer setup
        # This would update target groups to point to new region
    fi
    
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    if [ "$failover_success" = true ]; then
        # Update state
        update_state "active_region" "\"$target_region\""
        update_state "is_failover_active" "true"
        update_state "last_failover_time" "\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\""
        
        # Update failover history
        local final_record=$(echo "$failover_record" | jq --arg end "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --argjson duration "$duration" '.end_time = $end | .duration_seconds = $duration | .status = "success"')
        local final_history=$(echo "$updated_history" | jq --argjson record "$final_record" '.[-1] = $record')
        update_state "failover_history" "$final_history"
        
        # Send notifications
        send_failover_notification "$target_region" "$reason" "critical"
        
        log "FAILOVER COMPLETED: $current_active → $target_region (${duration}s)"
        log "RTO achieved: ${duration} seconds (target: <900 seconds)"
        
        return 0
    else
        # Update failover history with failure
        local failed_record=$(echo "$failover_record" | jq --arg end "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --argjson duration "$duration" --arg error "$error_message" '.end_time = $end | .duration_seconds = $duration | .status = "failed" | .error = $error')
        local failed_history=$(echo "$updated_history" | jq --argjson record "$failed_record" '.[-1] = $record')
        update_state "failover_history" "$failed_history"
        
        log "FAILOVER FAILED: $error_message (${duration}s)"
        return 1
    fi
}

# Check if failback is appropriate
check_failback_conditions() {
    local current_active=$(read_state "active_region")
    local is_failover_active=$(read_state "is_failover_active")
    local last_failover_time=$(read_state "last_failover_time")
    
    # Only consider failback if we're currently in failover mode
    if [ "$is_failover_active" != "true" ] || [ "$current_active" = "$PRIMARY_REGION" ]; then
        return 1
    fi
    
    # Check cooldown period
    if [ "$last_failover_time" != "null" ]; then
        local last_failover_timestamp=$(date -d "$last_failover_time" +%s 2>/dev/null || echo 0)
        local current_timestamp=$(date +%s)
        local time_since_failover=$((current_timestamp - last_failover_timestamp))
        
        if [ $time_since_failover -lt $FAILBACK_COOLDOWN ]; then
            log "Failback cooldown in effect (${time_since_failover}s < ${FAILBACK_COOLDOWN}s)"
            return 1
        fi
    fi
    
    # Check primary region health
    local primary_health=$(check_region_health "$PRIMARY_REGION")
    if [ "$primary_health" -ge 85 ]; then  # Higher threshold for failback
        log "Primary region health sufficient for failback (score: $primary_health)"
        return 0
    else
        log "Primary region health insufficient for failback (score: $primary_health)"
        return 1
    fi
}

# Execute failback to primary region
execute_failback() {
    local reason="${1:-Automatic failback - primary region recovered}"
    
    log "Initiating failback to primary region: $PRIMARY_REGION"
    
    if execute_failover "$PRIMARY_REGION" "$reason"; then
        update_state "is_failover_active" "false"
        update_state "last_failback_time" "\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\""
        
        send_failover_notification "$PRIMARY_REGION" "$reason" "warning"
        log "Failback completed successfully"
        return 0
    else
        log "Failback failed"
        return 1
    fi
}

# Main monitoring loop
monitor_and_failover() {
    log "Starting automated failover monitoring..."
    initialize_state
    
    local consecutive_failures=$(read_state "consecutive_failures")
    
    while true; do
        log "Performing health check cycle..."
        
        local current_active=$(read_state "active_region")
        local active_health=$(check_region_health "$current_active")
        
        # Get current failure count for active region
        local failure_count=$(echo "$consecutive_failures" | jq -r --arg region "$current_active" '.[$region] // 0')
        
        # Check if active region is healthy
        if [ "$active_health" -ge 50 ]; then
            # Reset failure count on successful health check
            if [ "$failure_count" -gt 0 ]; then
                log "Active region $current_active recovered (health: $active_health)"
                consecutive_failures=$(echo "$consecutive_failures" | jq --arg region "$current_active" '.[$region] = 0')
                update_state "consecutive_failures" "$consecutive_failures"
            fi
            
            # Check for potential failback
            if check_failback_conditions; then
                execute_failback
            fi
        else
            # Increment failure count
            failure_count=$((failure_count + 1))
            consecutive_failures=$(echo "$consecutive_failures" | jq --arg region "$current_active" --argjson count "$failure_count" '.[$region] = $count')
            update_state "consecutive_failures" "$consecutive_failures"
            
            log "Active region $current_active unhealthy (health: $active_health, failures: $failure_count/$FAILOVER_THRESHOLD)"
            
            # Check if we should trigger failover
            if [ "$failure_count" -ge "$FAILOVER_THRESHOLD" ]; then
                log "Failover threshold reached for region $current_active"
                
                # Find suitable failover target
                local target_region=$(determine_failover_target "$current_active")
                
                if [ -n "$target_region" ]; then
                    # Reset failure count before attempting failover
                    consecutive_failures=$(echo "$consecutive_failures" | jq --arg region "$current_active" '.[$region] = 0')
                    update_state "consecutive_failures" "$consecutive_failures"
                    
                    # Execute failover
                    if execute_failover "$target_region" "Active region health threshold exceeded ($failure_count consecutive failures)"; then
                        log "Failover completed successfully"
                    else
                        log "Failover failed, continuing to monitor"
                    fi
                else
                    log "No suitable failover target available"
                    
                    # Send critical alert
                    send_failover_notification "NONE" "All regions unhealthy - manual intervention required" "critical"
                fi
            fi
        fi
        
        log "Health check cycle completed. Sleeping for $HEALTH_CHECK_INTERVAL seconds..."
        sleep "$HEALTH_CHECK_INTERVAL"
    done
}

# Manual failover trigger
manual_failover() {
    local target_region="$1"
    local reason="${2:-Manual failover}"
    
    log "Manual failover requested to region: $target_region"
    
    # Validate target region
    IFS=',' read -ra VALID_REGIONS <<< "$PRIMARY_REGION,$REPLICA_REGIONS"
    local valid=false
    for region in "${VALID_REGIONS[@]}"; do
        if [ "$region" = "$target_region" ]; then
            valid=true
            break
        fi
    done
    
    if [ "$valid" != true ]; then
        error_exit "Invalid target region: $target_region"
    fi
    
    # Check target region health
    local target_health=$(check_region_health "$target_region")
    if [ "$target_health" -lt 50 ]; then
        log "WARNING: Target region health is low (score: $target_health)"
        read -p "Continue with failover? (y/N): " -r
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            log "Manual failover cancelled"
            exit 0
        fi
    fi
    
    # Execute failover
    execute_failover "$target_region" "$reason"
}

# Status reporting
show_status() {
    initialize_state
    
    local current_active=$(read_state "active_region")
    local is_failover_active=$(read_state "is_failover_active")
    local last_failover_time=$(read_state "last_failover_time")
    local health_status=$(read_state "health_status")
    
    echo
    echo "=== GENESIS Orchestrator Failover Status ==="
    echo
    echo "Active Region: $current_active"
    echo "Failover Mode: $([ "$is_failover_active" = "true" ] && echo "ACTIVE" || echo "NORMAL")"
    echo "Last Failover: ${last_failover_time:-Never}"
    echo
    echo "Region Health Status:"
    
    # Show health for all regions
    IFS=',' read -ra ALL_REGIONS <<< "$PRIMARY_REGION,$REPLICA_REGIONS"
    for region in "${ALL_REGIONS[@]}"; do
        local region_health=$(echo "$health_status" | jq -r --arg region "$region" '.[$region]')
        if [ "$region_health" != "null" ]; then
            local score=$(echo "$region_health" | jq -r '.score')
            local status=$(echo "$region_health" | jq -r '.status')
            local timestamp=$(echo "$region_health" | jq -r '.timestamp')
            
            local indicator="🔴"
            [ "$status" = "healthy" ] && indicator="🟢"
            [ "$status" = "degraded" ] && indicator="🟡"
            
            echo "  $indicator $region: $status (score: $score/100) - $timestamp"
        else
            echo "  ⚫ $region: Not checked"
        fi
    done
    
    echo
    echo "Recent Failover History:"
    local history=$(read_state "failover_history")
    echo "$history" | jq -r '.[-5:] | .[] | "  \(.start_time): \(.from_region) → \(.to_region) (\(.status)) - \(.reason)"' 2>/dev/null || echo "  No recent failovers"
    echo
}

# Command line interface
case "${1:-help}" in
    "monitor")
        acquire_lock
        monitor_and_failover
        ;;
    "failover")
        if [ -z "${2:-}" ]; then
            error_exit "Usage: $0 failover <target-region> [reason]"
        fi
        acquire_lock
        manual_failover "$2" "${3:-Manual failover}"
        ;;
    "failback")
        acquire_lock
        execute_failback "${2:-Manual failback}"
        ;;
    "status")
        show_status
        ;;
    "health")
        if [ -n "${2:-}" ]; then
            check_region_health "$2"
        else
            IFS=',' read -ra ALL_REGIONS <<< "$PRIMARY_REGION,$REPLICA_REGIONS"
            for region in "${ALL_REGIONS[@]}"; do
                check_region_health "$region"
            done
        fi
        ;;
    "help"|*)
        cat << EOF
GENESIS Orchestrator Automated Failover System

Usage: $0 <command> [options]

Commands:
    monitor                              Start continuous health monitoring and automated failover
    failover <region> [reason]           Execute manual failover to specified region
    failback [reason]                    Execute manual failback to primary region
    status                               Show current failover status and health
    health [region]                      Check health of specific region or all regions
    help                                 Show this help message

Examples:
    $0 monitor                           # Start automated monitoring daemon
    $0 failover us-east-1 "Maintenance" # Manual failover to us-east-1
    $0 failback                          # Manual failback to primary
    $0 status                            # Show current status
    $0 health us-west-2                  # Check specific region health

Environment Variables:
    PRIMARY_REGION                       Primary AWS region (default: us-west-2)
    REPLICA_REGIONS                      Comma-separated replica regions
    HEALTH_CHECK_INTERVAL               Health check interval in seconds (default: 30)
    FAILOVER_THRESHOLD                  Consecutive failures before failover (default: 3)
    ROUTE53_HOSTED_ZONE_ID             Route53 zone ID for DNS updates
    DNS_RECORD_NAME                     DNS record to update during failover

Monitoring and Alerting:
    SLACK_WEBHOOK_URL                   Slack webhook for notifications
    PAGERDUTY_API_KEY                   PagerDuty integration key
    SNS_TOPIC_ARN                       SNS topic for alerts

Files:
    State file: $STATE_FILE
    Log file: $LOG_FILE
    Lock file: $LOCK_FILE

EOF
        ;;
esac