#!/bin/bash

# GENESIS Orchestrator - Cross-Region Replication System
# Geographic redundancy for disaster recovery with automated failover capabilities
# Ensures business continuity across multiple AWS regions

set -euo pipefail

# Configuration
S3_PRIMARY_BUCKET="${BACKUP_S3_BUCKET:-genesis-disaster-recovery}"
S3_PRIMARY_REGION="${BACKUP_S3_REGION:-us-west-2}"
CROSS_REGION_REPLICAS="${BACKUP_CROSS_REGIONS:-us-east-1,eu-west-1}"
RDS_PRIMARY_REGION="$S3_PRIMARY_REGION"
REPLICA_SYNC_INTERVAL_MINUTES="${REPLICA_SYNC_INTERVAL:-15}"
HEALTH_CHECK_INTERVAL_SECONDS="${HEALTH_CHECK_INTERVAL:-30}"

# Failover thresholds
MAX_REPLICATION_LAG_MINUTES="${MAX_REPLICATION_LAG:-10}"
MAX_FAILED_HEALTH_CHECKS="${MAX_FAILED_HEALTH_CHECKS:-3}"
FAILOVER_COOLDOWN_MINUTES="${FAILOVER_COOLDOWN:-60}"

# Infrastructure endpoints
PRIMARY_ENDPOINT="${PRIMARY_ORCHESTRATOR_ENDPOINT:-https://api.genesis.orchestrator.com}"
ROUTE53_HOSTED_ZONE_ID="${ROUTE53_HOSTED_ZONE_ID}"
DNS_RECORD_NAME="${DNS_RECORD_NAME:-api.genesis.orchestrator.com}"

# Logging setup
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="/var/log/genesis/cross_region_replication.log"
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

# Initialize cross-region infrastructure
initialize_regions() {
    log "Initializing cross-region infrastructure..."
    
    IFS=',' read -ra REGIONS <<< "$CROSS_REGION_REPLICAS"
    
    for region in "${REGIONS[@]}"; do
        log "Setting up region: $region"
        
        # Create replica S3 bucket
        local replica_bucket="${S3_PRIMARY_BUCKET}-replica-${region}"
        
        if ! aws s3api head-bucket --bucket "$replica_bucket" --region "$region" 2>/dev/null; then
            log "Creating S3 bucket in $region: $replica_bucket"
            
            if [ "$region" = "us-east-1" ]; then
                aws s3api create-bucket --bucket "$replica_bucket" --region "$region"
            else
                aws s3api create-bucket --bucket "$replica_bucket" --region "$region" \
                    --create-bucket-configuration LocationConstraint="$region"
            fi
            
            # Configure bucket versioning
            aws s3api put-bucket-versioning --bucket "$replica_bucket" --region "$region" \
                --versioning-configuration Status=Enabled
            
            # Configure lifecycle policies
            cat > /tmp/lifecycle_policy_${region}.json << EOF
{
    "Rules": [
        {
            "ID": "GenesisDRRetention",
            "Status": "Enabled",
            "Filter": {"Prefix": "backups/"},
            "Transitions": [
                {
                    "Days": 30,
                    "StorageClass": "STANDARD_IA"
                },
                {
                    "Days": 90,
                    "StorageClass": "GLACIER"
                },
                {
                    "Days": 365,
                    "StorageClass": "DEEP_ARCHIVE"
                }
            ],
            "Expiration": {
                "Days": 2555
            }
        }
    ]
}
EOF
            
            aws s3api put-bucket-lifecycle-configuration --bucket "$replica_bucket" --region "$region" \
                --lifecycle-configuration file:///tmp/lifecycle_policy_${region}.json
            
            rm "/tmp/lifecycle_policy_${region}.json"
        else
            log "S3 bucket already exists in $region: $replica_bucket"
        fi
        
        # Setup CloudWatch alarms for replication monitoring
        aws cloudwatch put-metric-alarm \
            --alarm-name "Genesis-Replication-Lag-${region}" \
            --alarm-description "Genesis replication lag alarm for $region" \
            --metric-name ReplicationLag \
            --namespace Genesis/Replication \
            --statistic Maximum \
            --period 300 \
            --threshold $((MAX_REPLICATION_LAG_MINUTES * 60)) \
            --comparison-operator GreaterThanThreshold \
            --evaluation-periods 2 \
            --alarm-actions "arn:aws:sns:${region}:$(aws sts get-caller-identity --query Account --output text):genesis-alerts" \
            --region "$region" \
            --unit Seconds
        
        # Create RDS read replica (if RDS is used)
        if [ "${USE_RDS_REPLICAS:-false}" = "true" ]; then
            setup_rds_replica "$region"
        fi
        
        log "Region setup completed: $region"
    done
    
    log "Cross-region infrastructure initialization completed"
}

# Setup RDS read replica
setup_rds_replica() {
    local region="$1"
    local primary_db_instance_id="${RDS_PRIMARY_INSTANCE_ID:-genesis-orchestrator-primary}"
    local replica_db_instance_id="genesis-orchestrator-replica-${region}"
    
    log "Setting up RDS read replica in $region"
    
    # Check if replica already exists
    if aws rds describe-db-instances --db-instance-identifier "$replica_db_instance_id" --region "$region" 2>/dev/null; then
        log "RDS read replica already exists in $region"
        return
    fi
    
    # Create read replica
    aws rds create-db-instance-read-replica \
        --db-instance-identifier "$replica_db_instance_id" \
        --source-db-instance-identifier "arn:aws:rds:${RDS_PRIMARY_REGION}:$(aws sts get-caller-identity --query Account --output text):db:${primary_db_instance_id}" \
        --db-instance-class db.t3.large \
        --publicly-accessible \
        --auto-minor-version-upgrade \
        --multi-az \
        --storage-encrypted \
        --monitoring-interval 60 \
        --monitoring-role-arn "arn:aws:iam::$(aws sts get-caller-identity --query Account --output text):role/rds-monitoring-role" \
        --region "$region" \
        --tags Key=Environment,Value=production Key=Application,Value=genesis-orchestrator Key=Role,Value=replica
    
    log "RDS read replica creation initiated in $region"
}

# Sync data to replica regions
sync_to_replicas() {
    log "Starting cross-region data synchronization..."
    
    IFS=',' read -ra REGIONS <<< "$CROSS_REGION_REPLICAS"
    
    for region in "${REGIONS[@]}"; do
        local replica_bucket="${S3_PRIMARY_BUCKET}-replica-${region}"
        
        log "Syncing to $region..."
        
        # Sync backups directory
        aws s3 sync "s3://$S3_PRIMARY_BUCKET/backups/" "s3://$replica_bucket/backups/" \
            --source-region "$S3_PRIMARY_REGION" \
            --region "$region" \
            --delete \
            --storage-class STANDARD_IA
        
        # Sync configuration and metadata
        aws s3 sync "s3://$S3_PRIMARY_BUCKET/" "s3://$replica_bucket/" \
            --source-region "$S3_PRIMARY_REGION" \
            --region "$region" \
            --exclude "backups/*" \
            --storage-class STANDARD
        
        # Record replication timestamp
        echo "$(date -u +%Y-%m-%dT%H:%M:%SZ)" | aws s3 cp - "s3://$replica_bucket/last_sync.txt" --region "$region"
        
        # Publish CloudWatch metrics
        aws cloudwatch put-metric-data \
            --namespace Genesis/Replication \
            --metric-data MetricName=SyncTimestamp,Value=$(date +%s),Unit=None \
            --region "$region"
        
        log "Sync completed for $region"
    done
    
    log "Cross-region synchronization completed"
}

# Monitor replication health
monitor_replication_health() {
    log "Monitoring replication health across regions..."
    
    local health_summary="[]"
    
    IFS=',' read -ra REGIONS <<< "$CROSS_REGION_REPLICAS"
    
    for region in "${REGIONS[@]}"; do
        local replica_bucket="${S3_PRIMARY_BUCKET}-replica-${region}"
        local region_healthy=true
        local health_issues=()
        
        log "Checking health for region: $region"
        
        # Check S3 bucket accessibility
        if ! aws s3 ls "s3://$replica_bucket/" --region "$region" >/dev/null 2>&1; then
            region_healthy=false
            health_issues+=("S3_INACCESSIBLE")
        fi
        
        # Check replication lag
        local last_sync_time=$(aws s3 cp "s3://$replica_bucket/last_sync.txt" - --region "$region" 2>/dev/null || echo "1970-01-01T00:00:00Z")
        local last_sync_timestamp=$(date -d "$last_sync_time" +%s 2>/dev/null || echo 0)
        local current_timestamp=$(date +%s)
        local replication_lag_seconds=$((current_timestamp - last_sync_timestamp))
        local replication_lag_minutes=$((replication_lag_seconds / 60))
        
        if [ $replication_lag_minutes -gt $MAX_REPLICATION_LAG_MINUTES ]; then
            region_healthy=false
            health_issues+=("REPLICATION_LAG:${replication_lag_minutes}min")
        fi
        
        # Check backup consistency
        local primary_backup_count=$(aws s3 ls "s3://$S3_PRIMARY_BUCKET/backups/" --region "$S3_PRIMARY_REGION" --recursive | wc -l)
        local replica_backup_count=$(aws s3 ls "s3://$replica_bucket/backups/" --region "$region" --recursive | wc -l)
        local backup_diff=$((primary_backup_count - replica_backup_count))
        
        if [ $backup_diff -gt 5 ]; then  # Allow small variance
            region_healthy=false
            health_issues+=("BACKUP_INCONSISTENCY:${backup_diff}")
        fi
        
        # Update health summary
        local region_status=$(cat << EOF
{
    "region": "$region",
    "healthy": $region_healthy,
    "replication_lag_minutes": $replication_lag_minutes,
    "backup_count": $replica_backup_count,
    "issues": [$(printf '"%s",' "${health_issues[@]}" | sed 's/,$//')]
}
EOF
        )
        
        health_summary=$(echo "$health_summary" | jq --argjson region_data "$region_status" '. += [$region_data]')
        
        # Publish CloudWatch metrics
        aws cloudwatch put-metric-data \
            --namespace Genesis/Replication \
            --metric-data \
                MetricName=ReplicationLag,Value=$replication_lag_seconds,Unit=Seconds,Dimensions=Region=$region \
                MetricName=BackupCount,Value=$replica_backup_count,Unit=Count,Dimensions=Region=$region \
                MetricName=Healthy,Value=$([ "$region_healthy" = true ] && echo 1 || echo 0),Unit=None,Dimensions=Region=$region \
            --region "$region"
        
        if [ "$region_healthy" = true ]; then
            log "Region $region: HEALTHY (lag: ${replication_lag_minutes}min, backups: $replica_backup_count)"
        else
            log "Region $region: UNHEALTHY - Issues: ${health_issues[*]}"
        fi
    done
    
    # Save health summary
    echo "$health_summary" > /var/log/genesis/replication_health.json
    aws s3 cp /var/log/genesis/replication_health.json "s3://$S3_PRIMARY_BUCKET/monitoring/replication_health.json" --region "$S3_PRIMARY_REGION"
    
    log "Replication health monitoring completed"
}

# Determine best failover region
determine_failover_region() {
    local health_summary="$1"
    
    local best_region=$(echo "$health_summary" | jq -r '
        map(select(.healthy == true)) 
        | sort_by(.replication_lag_minutes) 
        | first 
        | .region'
    )
    
    if [ "$best_region" = "null" ] || [ -z "$best_region" ]; then
        # No healthy regions, pick the least unhealthy one
        best_region=$(echo "$health_summary" | jq -r '
            sort_by(.replication_lag_minutes) 
            | first 
            | .region'
        )
        log "WARNING: No fully healthy regions available. Selected least unhealthy: $best_region"
    else
        log "Selected failover region: $best_region"
    fi
    
    echo "$best_region"
}

# Execute failover to replica region
execute_failover() {
    local target_region="$1"
    local reason="$2"
    
    log "INITIATING FAILOVER to region $target_region. Reason: $reason"
    
    # Check failover cooldown
    local last_failover_file="/var/log/genesis/last_failover.txt"
    if [ -f "$last_failover_file" ]; then
        local last_failover_time=$(cat "$last_failover_file")
        local current_time=$(date +%s)
        local time_since_last=$((current_time - last_failover_time))
        local cooldown_seconds=$((FAILOVER_COOLDOWN_MINUTES * 60))
        
        if [ $time_since_last -lt $cooldown_seconds ]; then
            log "Failover cooldown in effect. Skipping failover."
            return
        fi
    fi
    
    # Record failover attempt
    date +%s > "$last_failover_file"
    
    # Update DNS to point to replica region
    log "Updating Route53 DNS records..."
    
    # Get current record
    local current_record=$(aws route53 list-resource-record-sets \
        --hosted-zone-id "$ROUTE53_HOSTED_ZONE_ID" \
        --query "ResourceRecordSets[?Name=='${DNS_RECORD_NAME}.']" \
        --output json)
    
    # Prepare new record pointing to replica region
    local replica_endpoint="${PRIMARY_ORCHESTRATOR_ENDPOINT//$S3_PRIMARY_REGION/$target_region}"
    
    # Create change batch
    cat > /tmp/route53_changeset.json << EOF
{
    "Comment": "Genesis Orchestrator failover to $target_region",
    "Changes": [{
        "Action": "UPSERT",
        "ResourceRecordSet": {
            "Name": "$DNS_RECORD_NAME",
            "Type": "CNAME",
            "TTL": 60,
            "ResourceRecords": [{
                "Value": "${replica_endpoint#https://}"
            }]
        }
    }]
}
EOF
    
    # Apply DNS change
    local change_id=$(aws route53 change-resource-record-sets \
        --hosted-zone-id "$ROUTE53_HOSTED_ZONE_ID" \
        --change-batch file:///tmp/route53_changeset.json \
        --query 'ChangeInfo.Id' \
        --output text)
    
    # Wait for DNS propagation
    log "Waiting for DNS propagation (Change ID: $change_id)..."
    aws route53 wait resource-record-sets-changed --id "$change_id"
    
    # Update load balancer configuration (if using ALB/NLB)
    if [ -n "${LOAD_BALANCER_ARN:-}" ]; then
        log "Updating load balancer target groups..."
        # Implementation depends on specific load balancer setup
    fi
    
    # Promote RDS read replica if using RDS
    if [ "${USE_RDS_REPLICAS:-false}" = "true" ]; then
        log "Promoting RDS read replica in $target_region..."
        local replica_db_instance_id="genesis-orchestrator-replica-${target_region}"
        
        aws rds promote-read-replica \
            --db-instance-identifier "$replica_db_instance_id" \
            --region "$target_region"
        
        # Wait for promotion to complete
        aws rds wait db-instance-available \
            --db-instance-identifier "$replica_db_instance_id" \
            --region "$target_region"
    fi
    
    # Update application configuration
    log "Updating application configuration for failover..."
    
    # Create failover configuration
    cat > /tmp/failover_config.json << EOF
{
    "failover_active": true,
    "primary_region": "$S3_PRIMARY_REGION",
    "active_region": "$target_region",
    "failover_timestamp": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "failover_reason": "$reason",
    "dns_record": "$DNS_RECORD_NAME",
    "s3_bucket": "${S3_PRIMARY_BUCKET}-replica-${target_region}"
}
EOF
    
    # Upload failover configuration
    aws s3 cp /tmp/failover_config.json "s3://${S3_PRIMARY_BUCKET}-replica-${target_region}/failover_config.json" --region "$target_region"
    
    # Send notifications
    send_failover_notification "$target_region" "$reason"
    
    # Update monitoring to watch the new active region
    update_monitoring_for_failover "$target_region"
    
    rm /tmp/route53_changeset.json /tmp/failover_config.json
    
    log "FAILOVER COMPLETED to region $target_region"
}

# Send failover notifications
send_failover_notification() {
    local target_region="$1"
    local reason="$2"
    
    local message="GENESIS ORCHESTRATOR FAILOVER ALERT

    Failover Event: Active region changed from $S3_PRIMARY_REGION to $target_region
    Time: $(date -u +%Y-%m-%dT%H:%M:%SZ)
    Reason: $reason
    
    Actions taken:
    - DNS updated to point to $target_region
    - Application traffic redirected
    - RDS replica promoted (if applicable)
    
    Please verify system functionality and investigate primary region issues."
    
    # Send to SNS topics in all regions
    IFS=',' read -ra REGIONS <<< "$CROSS_REGION_REPLICAS"
    for region in "$S3_PRIMARY_REGION" "${REGIONS[@]}"; do
        aws sns publish \
            --topic-arn "arn:aws:sns:${region}:$(aws sts get-caller-identity --query Account --output text):genesis-critical-alerts" \
            --subject "GENESIS Orchestrator Failover - $target_region" \
            --message "$message" \
            --region "$region" 2>/dev/null || true
    done
    
    # Send Slack notification if configured
    if [ -n "${SLACK_WEBHOOK_URL:-}" ]; then
        curl -X POST "$SLACK_WEBHOOK_URL" \
            -H 'Content-type: application/json' \
            --data "{
                \"text\":\"🚨 GENESIS Orchestrator Failover\",
                \"attachments\":[{
                    \"color\":\"danger\",
                    \"fields\":[
                        {\"title\":\"Target Region\",\"value\":\"$target_region\",\"short\":true},
                        {\"title\":\"Reason\",\"value\":\"$reason\",\"short\":true},
                        {\"title\":\"Time\",\"value\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",\"short\":true}
                    ]
                }]
            }" 2>/dev/null || true
    fi
}

# Update monitoring for failover scenario
update_monitoring_for_failover() {
    local active_region="$1"
    
    log "Updating monitoring configuration for active region: $active_region"
    
    # Update CloudWatch dashboard to focus on active region
    # Update Grafana configuration
    # Adjust alerting thresholds
    # This would be customized based on specific monitoring setup
}

# Check if failover is needed
check_failover_conditions() {
    local health_summary="$1"
    
    # Check if primary region is experiencing issues
    local primary_region_healthy=$(echo "$health_summary" | jq -r --arg region "$S3_PRIMARY_REGION" '.[] | select(.region == $region) | .healthy')
    
    if [ "$primary_region_healthy" = "false" ]; then
        log "Primary region $S3_PRIMARY_REGION is unhealthy"
        
        # Find best failover target
        local target_region=$(determine_failover_region "$health_summary")
        
        if [ -n "$target_region" ] && [ "$target_region" != "null" ]; then
            execute_failover "$target_region" "Primary region unhealthy"
            return 0
        else
            log "ERROR: No suitable failover region available"
            return 1
        fi
    fi
    
    log "Primary region is healthy, no failover needed"
    return 0
}

# Main monitoring loop
monitoring_loop() {
    log "Starting cross-region replication monitoring loop..."
    
    local failed_checks=0
    
    while true; do
        log "Performing replication health check cycle..."
        
        # Sync data to replicas
        if ! sync_to_replicas; then
            ((failed_checks++))
            log "Sync failed (attempt $failed_checks/$MAX_FAILED_HEALTH_CHECKS)"
        else
            failed_checks=0
        fi
        
        # Monitor health
        monitor_replication_health
        
        # Check for failover conditions
        local health_summary=$(cat /var/log/genesis/replication_health.json 2>/dev/null || echo "[]")
        if ! check_failover_conditions "$health_summary"; then
            ((failed_checks++))
        fi
        
        # If too many consecutive failures, try emergency failover
        if [ $failed_checks -ge $MAX_FAILED_HEALTH_CHECKS ]; then
            log "Maximum consecutive failures reached, attempting emergency failover"
            local best_region=$(determine_failover_region "$health_summary")
            if [ -n "$best_region" ] && [ "$best_region" != "null" ]; then
                execute_failover "$best_region" "Maximum health check failures exceeded"
                failed_checks=0
            fi
        fi
        
        log "Health check cycle completed. Sleeping for $HEALTH_CHECK_INTERVAL_SECONDS seconds..."
        sleep "$HEALTH_CHECK_INTERVAL_SECONDS"
    done
}

# Command line interface
case "${1:-help}" in
    "init")
        initialize_regions
        ;;
    "sync")
        sync_to_replicas
        ;;
    "monitor")
        monitor_replication_health
        ;;
    "failover")
        if [ -z "${2:-}" ]; then
            error_exit "Usage: $0 failover <target-region> [reason]"
        fi
        execute_failover "$2" "${3:-Manual failover}"
        ;;
    "status")
        monitor_replication_health
        cat /var/log/genesis/replication_health.json | jq '.'
        ;;
    "daemon")
        monitoring_loop
        ;;
    "help"|*)
        cat << EOF
GENESIS Cross-Region Replication Manager

Usage: $0 <command> [options]

Commands:
    init                    Initialize cross-region infrastructure
    sync                    Manually sync data to all replicas
    monitor                 Perform one-time health check
    failover <region> [reason]  Execute manual failover to specified region
    status                  Show current replication status
    daemon                  Start continuous monitoring daemon

Examples:
    $0 init                                    # Setup cross-region infrastructure
    $0 sync                                    # Sync data to all replicas
    $0 failover us-east-1 "Maintenance"       # Manual failover
    $0 daemon                                  # Start monitoring service

Environment Variables:
    BACKUP_S3_BUCKET                Primary S3 bucket name
    BACKUP_S3_REGION                Primary AWS region
    BACKUP_CROSS_REGIONS           Comma-separated replica regions
    ROUTE53_HOSTED_ZONE_ID         Route53 zone for DNS updates
    DNS_RECORD_NAME                DNS record to update during failover
    SLACK_WEBHOOK_URL              Slack webhook for notifications

EOF
        ;;
esac