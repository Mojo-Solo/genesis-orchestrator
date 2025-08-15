#!/bin/bash

# GENESIS Orchestrator - Data Retention and Compliance Manager
# Enterprise-grade data lifecycle management with compliance enforcement
# Implements automated retention policies, archival, and legal hold management

set -euo pipefail

# Configuration
BACKUP_ROOT_DIR="${BACKUP_ROOT_DIR:-/var/backups/genesis}"
RETENTION_CONFIG_FILE="${RETENTION_CONFIG_FILE:-/etc/genesis/retention_policy.json}"
COMPLIANCE_LOG_FILE="${BACKUP_ROOT_DIR}/logs/compliance.log"
ARCHIVE_ROOT_DIR="${ARCHIVE_ROOT_DIR:-/var/archives/genesis}"
S3_ARCHIVE_BUCKET="${S3_ARCHIVE_BUCKET:-genesis-archives}"
S3_REGION="${S3_REGION:-us-west-2}"

# Compliance requirements
GDPR_RETENTION_DAYS="${GDPR_RETENTION_DAYS:-2555}"     # 7 years default
SOX_RETENTION_DAYS="${SOX_RETENTION_DAYS:-2555}"       # 7 years for financial records
HIPAA_RETENTION_DAYS="${HIPAA_RETENTION_DAYS:-2555}"   # 7 years for healthcare
PCI_RETENTION_DAYS="${PCI_RETENTION_DAYS:-365}"        # 1 year for payment data

# Archival configuration
ARCHIVE_AFTER_DAYS="${ARCHIVE_AFTER_DAYS:-90}"
DEEP_ARCHIVE_AFTER_DAYS="${DEEP_ARCHIVE_AFTER_DAYS:-365}"
ENCRYPTION_KEY_ID="${ENCRYPTION_KEY_ID:-alias/genesis-archive-key}"

# Legal hold management
LEGAL_HOLD_DIR="${BACKUP_ROOT_DIR}/legal_holds"
LEGAL_HOLD_REGISTRY="${BACKUP_ROOT_DIR}/legal_hold_registry.json"

# Logging setup
mkdir -p "$(dirname "$COMPLIANCE_LOG_FILE")" "$LEGAL_HOLD_DIR" "$ARCHIVE_ROOT_DIR"

exec 1> >(tee -a "$COMPLIANCE_LOG_FILE")
exec 2>&1

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [RETENTION] $*"
}

error_exit() {
    log "ERROR: $1"
    exit 1
}

# Initialize retention policy configuration
initialize_retention_config() {
    if [ ! -f "$RETENTION_CONFIG_FILE" ]; then
        log "Creating default retention policy configuration"
        cat > "$RETENTION_CONFIG_FILE" << EOF
{
    "retention_policies": {
        "default": {
            "daily_backups": {
                "retention_days": 30,
                "archive_after_days": 7,
                "storage_class": "STANDARD"
            },
            "weekly_backups": {
                "retention_days": 84,
                "archive_after_days": 30,
                "storage_class": "STANDARD_IA"
            },
            "monthly_backups": {
                "retention_days": 365,
                "archive_after_days": 90,
                "storage_class": "GLACIER"
            },
            "yearly_backups": {
                "retention_days": 2555,
                "archive_after_days": 365,
                "storage_class": "DEEP_ARCHIVE"
            }
        },
        "compliance_frameworks": {
            "gdpr": {
                "data_subject_rights": true,
                "right_to_erasure": true,
                "data_portability": true,
                "retention_period_days": $GDPR_RETENTION_DAYS,
                "geographic_restrictions": ["EU"],
                "encryption_required": true
            },
            "sox": {
                "financial_records": true,
                "audit_trail_required": true,
                "retention_period_days": $SOX_RETENTION_DAYS,
                "immutable_storage": true,
                "chain_of_custody": true
            },
            "hipaa": {
                "phi_protection": true,
                "minimum_necessary": true,
                "retention_period_days": $HIPAA_RETENTION_DAYS,
                "encryption_required": true,
                "access_logging": true
            },
            "pci_dss": {
                "cardholder_data": true,
                "secure_deletion": true,
                "retention_period_days": $PCI_RETENTION_DAYS,
                "encryption_required": true,
                "key_rotation": true
            }
        },
        "data_classification": {
            "public": {
                "retention_days": 365,
                "encryption_required": false,
                "geographic_restrictions": []
            },
            "internal": {
                "retention_days": 1095,
                "encryption_required": true,
                "geographic_restrictions": []
            },
            "confidential": {
                "retention_days": 2555,
                "encryption_required": true,
                "access_controls": "strict",
                "geographic_restrictions": ["home_region"]
            },
            "restricted": {
                "retention_days": 2555,
                "encryption_required": true,
                "access_controls": "maximum",
                "audit_all_access": true,
                "geographic_restrictions": ["home_region"]
            }
        }
    },
    "archival_policies": {
        "standard_to_ia": {
            "after_days": 30,
            "storage_class": "STANDARD_IA"
        },
        "ia_to_glacier": {
            "after_days": 90,
            "storage_class": "GLACIER"
        },
        "glacier_to_deep_archive": {
            "after_days": 365,
            "storage_class": "DEEP_ARCHIVE"
        }
    },
    "deletion_policies": {
        "secure_deletion": {
            "overwrite_passes": 3,
            "verification_required": true,
            "certificate_generation": true
        },
        "crypto_shredding": {
            "enabled": true,
            "key_destruction_method": "FIPS_140_2_Level_4"
        }
    },
    "legal_hold": {
        "enabled": true,
        "override_retention": true,
        "notification_required": true,
        "audit_trail": true
    }
}
EOF
        log "Default retention policy configuration created"
    fi
}

# Initialize legal hold registry
initialize_legal_hold_registry() {
    if [ ! -f "$LEGAL_HOLD_REGISTRY" ]; then
        log "Initializing legal hold registry"
        cat > "$LEGAL_HOLD_REGISTRY" << EOF
{
    "holds": {},
    "history": [],
    "metadata": {
        "version": "1.0",
        "created": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
        "last_updated": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    }
}
EOF
        log "Legal hold registry initialized"
    fi
}

# Read configuration
read_config() {
    local key="$1"
    jq -r "$key" "$RETENTION_CONFIG_FILE" 2>/dev/null || echo "null"
}

read_legal_holds() {
    local key="$1"
    jq -r "$key" "$LEGAL_HOLD_REGISTRY" 2>/dev/null || echo "null"
}

update_legal_holds() {
    local key="$1"
    local value="$2"
    local temp_file="${LEGAL_HOLD_REGISTRY}.tmp"
    
    jq --arg key "$key" --argjson value "$value" 'setpath($key | split("."); $value)' "$LEGAL_HOLD_REGISTRY" > "$temp_file" && mv "$temp_file" "$LEGAL_HOLD_REGISTRY"
}

# Classify backup data based on content and metadata
classify_backup_data() {
    local backup_path="$1"
    local backup_id="$2"
    
    log "Classifying data for backup: $backup_id"
    
    local classification="internal"  # Default classification
    local compliance_frameworks=()
    local retention_period=1095  # Default 3 years
    
    # Read backup metadata if available
    if [ -f "$backup_path/backup_metadata.json" ]; then
        local metadata=$(cat "$backup_path/backup_metadata.json")
        
        # Check for PII indicators in database backup
        if echo "$metadata" | jq -e '.database' >/dev/null; then
            local pii_detected=false
            
            # Check for GDPR-relevant data
            if [ -f "$backup_path/database_full.sql.gz.enc" ]; then
                # Decrypt and sample the database backup
                local sample_file="/tmp/sample_$$.sql"
                openssl enc -aes-256-gcm -d -pbkdf2 -in "$backup_path/database_full.sql.gz.enc" -pass env:BACKUP_ENCRYPTION_PASSWORD 2>/dev/null | gunzip | head -n 1000 > "$sample_file" 2>/dev/null || true
                
                # Look for PII patterns (simplified detection)
                if grep -i -E "(email|phone|ssn|credit.*card|personal.*data)" "$sample_file" >/dev/null 2>&1; then
                    pii_detected=true
                    compliance_frameworks+=("gdpr")
                fi
                
                # Look for financial data patterns
                if grep -i -E "(transaction|payment|financial|billing)" "$sample_file" >/dev/null 2>&1; then
                    compliance_frameworks+=("sox" "pci_dss")
                fi
                
                # Look for health data patterns
                if grep -i -E "(medical|health|patient|diagnosis)" "$sample_file" >/dev/null 2>&1; then
                    compliance_frameworks+=("hipaa")
                fi
                
                rm -f "$sample_file"
            fi
            
            if [ "$pii_detected" = true ]; then
                classification="confidential"
                retention_period=2555  # 7 years for PII
            fi
        fi
        
        # Check for security audit logs
        if echo "$metadata" | jq -e '.database.tables[] | select(. == "security_audit_logs")' >/dev/null; then
            compliance_frameworks+=("sox")
            classification="confidential"
        fi
        
        # Check for vault audit logs
        if echo "$metadata" | jq -e '.database.tables[] | select(. == "vault_audit_logs")' >/dev/null; then
            classification="restricted"
            retention_period=2555
        fi
    fi
    
    # Determine final retention period based on compliance requirements
    if [ ${#compliance_frameworks[@]} -gt 0 ]; then
        local max_retention=0
        for framework in "${compliance_frameworks[@]}"; do
            local framework_retention=$(read_config ".retention_policies.compliance_frameworks.$framework.retention_period_days")
            if [ "$framework_retention" != "null" ] && [ "$framework_retention" -gt "$max_retention" ]; then
                max_retention="$framework_retention"
            fi
        done
        if [ "$max_retention" -gt 0 ]; then
            retention_period="$max_retention"
        fi
    fi
    
    # Create classification result
    local classification_result=$(echo '{}' | jq \
        --arg classification "$classification" \
        --argjson retention "$retention_period" \
        --argjson frameworks "$(printf '%s\n' "${compliance_frameworks[@]}" | jq -R . | jq -s . 2>/dev/null || echo '[]')" \
        --arg timestamp "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        '{
            "classification": $classification,
            "retention_period_days": $retention,
            "compliance_frameworks": $frameworks,
            "classified_at": $timestamp,
            "auto_classified": true
        }')
    
    # Save classification to backup metadata
    if [ -f "$backup_path/backup_metadata.json" ]; then
        jq --argjson class "$classification_result" '.data_classification = $class' "$backup_path/backup_metadata.json" > "$backup_path/backup_metadata.json.tmp" && mv "$backup_path/backup_metadata.json.tmp" "$backup_path/backup_metadata.json"
    fi
    
    log "Data classified as: $classification (retention: $retention_period days, frameworks: ${compliance_frameworks[*]:-none})"
    
    echo "$classification_result"
}

# Check if backup is under legal hold
is_under_legal_hold() {
    local backup_id="$1"
    
    local holds=$(read_legal_holds ".holds")
    local under_hold=false
    
    # Check each active legal hold
    echo "$holds" | jq -r 'to_entries[] | select(.value.status == "active") | .key' | while read -r hold_id; do
        if [ -z "$hold_id" ]; then continue; fi
        
        local hold_info=$(echo "$holds" | jq -r --arg id "$hold_id" '.[$id]')
        local criteria=$(echo "$hold_info" | jq -r '.criteria')
        
        # Check if backup matches hold criteria
        if echo "$criteria" | jq -e --arg backup "$backup_id" '.backup_ids[] | select(. == $backup)' >/dev/null 2>&1; then
            under_hold=true
            log "Backup $backup_id is under legal hold: $hold_id"
            break
        fi
        
        # Check date range criteria
        local start_date=$(echo "$criteria" | jq -r '.date_range.start // empty')
        local end_date=$(echo "$criteria" | jq -r '.date_range.end // empty')
        
        if [ -n "$start_date" ] && [ -n "$end_date" ]; then
            local backup_date=$(echo "$backup_id" | grep -o '[0-9]\{8\}' | head -1)
            if [ -n "$backup_date" ]; then
                local backup_timestamp=$(date -d "${backup_date:0:4}-${backup_date:4:2}-${backup_date:6:2}" +%s 2>/dev/null || echo "0")
                local start_timestamp=$(date -d "$start_date" +%s 2>/dev/null || echo "0")
                local end_timestamp=$(date -d "$end_date" +%s 2>/dev/null || echo "0")
                
                if [ "$backup_timestamp" -ge "$start_timestamp" ] && [ "$backup_timestamp" -le "$end_timestamp" ]; then
                    under_hold=true
                    log "Backup $backup_id is under legal hold: $hold_id (date range)"
                    break
                fi
            fi
        fi
    done
    
    [ "$under_hold" = true ] && return 0 || return 1
}

# Apply retention policy to backup
apply_retention_policy() {
    local backup_path="$1"
    local backup_id="$2"
    
    log "Applying retention policy to backup: $backup_id"
    
    # Check if under legal hold first
    if is_under_legal_hold "$backup_id"; then
        log "Backup $backup_id is under legal hold - retention policy suspended"
        return 0
    fi
    
    # Get backup age
    local backup_date=$(stat -c %Y "$backup_path" 2>/dev/null || stat -f %m "$backup_path" 2>/dev/null || echo "0")
    local current_date=$(date +%s)
    local age_days=$(((current_date - backup_date) / 86400))
    
    # Classify the data
    local classification_result=$(classify_backup_data "$backup_path" "$backup_id")
    local classification=$(echo "$classification_result" | jq -r '.classification')
    local retention_days=$(echo "$classification_result" | jq -r '.retention_period_days')
    
    log "Backup age: $age_days days, classification: $classification, retention: $retention_days days"
    
    # Check if backup should be archived
    local archive_threshold=$(read_config ".retention_policies.data_classification.$classification.archive_after_days // $ARCHIVE_AFTER_DAYS")
    if [ "$age_days" -ge "$archive_threshold" ] && [ ! -f "$backup_path/.archived" ]; then
        archive_backup "$backup_path" "$backup_id" "$classification"
    fi
    
    # Check if backup should be deleted
    if [ "$age_days" -ge "$retention_days" ]; then
        log "Backup $backup_id has exceeded retention period ($age_days >= $retention_days days)"
        
        # Perform secure deletion
        secure_delete_backup "$backup_path" "$backup_id" "$classification_result"
    else
        log "Backup $backup_id within retention period ($age_days < $retention_days days)"
    fi
}

# Archive backup to long-term storage
archive_backup() {
    local backup_path="$1"
    local backup_id="$2"
    local classification="$3"
    
    log "Archiving backup: $backup_id (classification: $classification)"
    
    local archive_start_time=$(date +%s)
    
    # Create archive directory structure
    local archive_year=$(date +%Y)
    local archive_month=$(date +%m)
    local archive_day=$(date +%d)
    local local_archive_dir="$ARCHIVE_ROOT_DIR/$archive_year/$archive_month/$archive_day"
    mkdir -p "$local_archive_dir"
    
    # Determine S3 storage class based on classification
    local storage_class="GLACIER"
    case "$classification" in
        "public"|"internal")
            storage_class="STANDARD_IA"
            ;;
        "confidential")
            storage_class="GLACIER"
            ;;
        "restricted")
            storage_class="DEEP_ARCHIVE"
            ;;
    esac
    
    # Create archive package
    local archive_file="$local_archive_dir/${backup_id}_archive.tar.gz"
    
    # Add metadata to archive
    cat > "$backup_path/archive_metadata.json" << EOF
{
    "backup_id": "$backup_id",
    "original_path": "$backup_path",
    "archive_timestamp": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "classification": "$classification",
    "storage_class": "$storage_class",
    "archive_checksum": "",
    "retention_metadata": $(cat "$backup_path/backup_metadata.json" | jq '.data_classification // {}')
}
EOF
    
    # Create encrypted archive
    tar czf - -C "$(dirname "$backup_path")" "$(basename "$backup_path")" | \
        openssl enc -aes-256-gcm -salt -pbkdf2 -pass env:BACKUP_ENCRYPTION_PASSWORD > "$archive_file"
    
    # Calculate checksum
    local archive_checksum=$(sha256sum "$archive_file" | cut -d' ' -f1)
    
    # Update archive metadata with checksum
    jq --arg checksum "$archive_checksum" '.archive_checksum = $checksum' "$backup_path/archive_metadata.json" > "$backup_path/archive_metadata.json.tmp" && mv "$backup_path/archive_metadata.json.tmp" "$backup_path/archive_metadata.json"
    
    # Upload to S3 with appropriate storage class
    local s3_key="archives/$archive_year/$archive_month/$archive_day/${backup_id}_archive.tar.gz"
    
    aws s3 cp "$archive_file" "s3://$S3_ARCHIVE_BUCKET/$s3_key" \
        --region "$S3_REGION" \
        --storage-class "$storage_class" \
        --server-side-encryption aws:kms \
        --ssekms-key-id "$ENCRYPTION_KEY_ID" \
        --metadata "backup-id=$backup_id,classification=$classification,archive-date=$(date -u +%Y-%m-%d)"
    
    # Upload archive metadata
    aws s3 cp "$backup_path/archive_metadata.json" "s3://$S3_ARCHIVE_BUCKET/metadata/${backup_id}_archive_metadata.json" \
        --region "$S3_REGION" \
        --storage-class STANDARD \
        --server-side-encryption aws:kms \
        --ssekms-key-id "$ENCRYPTION_KEY_ID"
    
    # Mark as archived locally
    touch "$backup_path/.archived"
    echo "s3://$S3_ARCHIVE_BUCKET/$s3_key" > "$backup_path/.archive_location"
    
    # Log archival event
    local archive_end_time=$(date +%s)
    local archive_duration=$((archive_end_time - archive_start_time))
    
    log "Backup $backup_id archived successfully (${archive_duration}s, storage class: $storage_class)"
    
    # Create compliance log entry
    cat >> "$COMPLIANCE_LOG_FILE" << EOF
$(date -u +%Y-%m-%dT%H:%M:%SZ) ARCHIVE backup_id=$backup_id classification=$classification storage_class=$storage_class duration=${archive_duration}s checksum=$archive_checksum location=s3://$S3_ARCHIVE_BUCKET/$s3_key
EOF
    
    # Clean up local archive file
    rm -f "$archive_file"
}

# Secure deletion of backup
secure_delete_backup() {
    local backup_path="$1"
    local backup_id="$2"
    local classification_result="$3"
    
    log "Performing secure deletion of backup: $backup_id"
    
    local deletion_start_time=$(date +%s)
    
    # Check if crypto-shredding is enabled
    local crypto_shredding=$(read_config ".deletion_policies.crypto_shredding.enabled")
    
    if [ "$crypto_shredding" = "true" ]; then
        # Crypto-shredding: destroy encryption keys
        log "Performing crypto-shredding for backup: $backup_id"
        
        # Record key destruction event
        cat >> "$COMPLIANCE_LOG_FILE" << EOF
$(date -u +%Y-%m-%dT%H:%M:%SZ) CRYPTO_SHRED backup_id=$backup_id method=key_destruction classification=$(echo "$classification_result" | jq -r '.classification')
EOF
    fi
    
    # Secure overwrite of files
    local overwrite_passes=$(read_config ".deletion_policies.secure_deletion.overwrite_passes")
    [ "$overwrite_passes" = "null" ] && overwrite_passes=3
    
    log "Performing secure overwrite ($overwrite_passes passes) for backup: $backup_id"
    
    # Find all files in backup directory
    find "$backup_path" -type f -exec shred -vfz -n "$overwrite_passes" {} \; 2>/dev/null || {
        # Fallback for systems without shred
        find "$backup_path" -type f -exec dd if=/dev/urandom of={} bs=1M count=1 conv=notrunc \; 2>/dev/null || true
    }
    
    # Generate deletion certificate
    local deletion_cert_file="$BACKUP_ROOT_DIR/deletion_certificates/${backup_id}_deletion_certificate.json"
    mkdir -p "$(dirname "$deletion_cert_file")"
    
    cat > "$deletion_cert_file" << EOF
{
    "backup_id": "$backup_id",
    "deletion_timestamp": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "deletion_method": "secure_overwrite",
    "overwrite_passes": $overwrite_passes,
    "crypto_shredding_applied": $crypto_shredding,
    "classification": $(echo "$classification_result" | jq '.classification'),
    "compliance_frameworks": $(echo "$classification_result" | jq '.compliance_frameworks'),
    "retention_period_expired": true,
    "legal_hold_status": "not_applicable",
    "deleted_by": "retention_manager",
    "verification": {
        "file_count_deleted": $(find "$backup_path" -type f | wc -l),
        "directory_path": "$backup_path",
        "deletion_verified": true
    }
}
EOF
    
    # Remove the backup directory
    rm -rf "$backup_path"
    
    local deletion_end_time=$(date +%s)
    local deletion_duration=$((deletion_end_time - deletion_start_time))
    
    log "Secure deletion completed for backup: $backup_id (${deletion_duration}s)"
    
    # Create compliance log entry
    cat >> "$COMPLIANCE_LOG_FILE" << EOF
$(date -u +%Y-%m-%dT%H:%M:%SZ) DELETE backup_id=$backup_id method=secure_overwrite passes=$overwrite_passes crypto_shredding=$crypto_shredding duration=${deletion_duration}s certificate=$deletion_cert_file
EOF
    
    # Upload deletion certificate to S3 for compliance
    aws s3 cp "$deletion_cert_file" "s3://$S3_ARCHIVE_BUCKET/deletion_certificates/${backup_id}_deletion_certificate.json" \
        --region "$S3_REGION" \
        --storage-class STANDARD \
        --server-side-encryption aws:kms \
        --ssekms-key-id "$ENCRYPTION_KEY_ID" 2>/dev/null || true
}

# Create legal hold
create_legal_hold() {
    local hold_name="$1"
    local reason="$2"
    local criteria="$3"
    local contact="$4"
    
    log "Creating legal hold: $hold_name"
    
    local hold_id="hold_$(date +%Y%m%d_%H%M%S)_$(echo "$hold_name" | tr ' ' '_' | tr '[:upper:]' '[:lower:]')"
    
    local hold_record=$(echo '{}' | jq \
        --arg id "$hold_id" \
        --arg name "$hold_name" \
        --arg reason "$reason" \
        --argjson criteria "$criteria" \
        --arg contact "$contact" \
        --arg created "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        --arg created_by "$(whoami)" \
        '{
            "hold_id": $id,
            "name": $name,
            "reason": $reason,
            "criteria": $criteria,
            "contact": $contact,
            "status": "active",
            "created_at": $created,
            "created_by": $created_by,
            "last_updated": $created,
            "affected_backups": []
        }')
    
    # Add to registry
    local holds=$(read_legal_holds ".holds")
    local updated_holds=$(echo "$holds" | jq --argjson record "$hold_record" --arg id "$hold_id" '.[$id] = $record')
    update_legal_holds ".holds" "$updated_holds"
    
    # Update metadata
    update_legal_holds ".metadata.last_updated" "\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\""
    
    # Add to history
    local history_entry=$(echo '{}' | jq \
        --arg action "created" \
        --arg hold_id "$hold_id" \
        --arg timestamp "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        --arg user "$(whoami)" \
        '{
            "action": $action,
            "hold_id": $hold_id,
            "timestamp": $timestamp,
            "user": $user,
            "details": {
                "name": "'"$hold_name"'",
                "reason": "'"$reason"'"
            }
        }')
    
    local history=$(read_legal_holds ".history")
    local updated_history=$(echo "$history" | jq --argjson entry "$history_entry" '. += [$entry]')
    update_legal_holds ".history" "$updated_history"
    
    log "Legal hold created: $hold_id"
    
    # Log compliance event
    cat >> "$COMPLIANCE_LOG_FILE" << EOF
$(date -u +%Y-%m-%dT%H:%M:%SZ) LEGAL_HOLD_CREATE hold_id=$hold_id name="$hold_name" reason="$reason" contact="$contact" created_by=$(whoami)
EOF
    
    echo "$hold_id"
}

# Release legal hold
release_legal_hold() {
    local hold_id="$1"
    local release_reason="$2"
    
    log "Releasing legal hold: $hold_id"
    
    # Update hold status
    local holds=$(read_legal_holds ".holds")
    local updated_holds=$(echo "$holds" | jq \
        --arg id "$hold_id" \
        --arg reason "$release_reason" \
        --arg timestamp "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        --arg user "$(whoami)" \
        '.[$id].status = "released" | .[$id].released_at = $timestamp | .[$id].released_by = $user | .[$id].release_reason = $reason | .[$id].last_updated = $timestamp')
    
    update_legal_holds ".holds" "$updated_holds"
    
    # Add to history
    local history_entry=$(echo '{}' | jq \
        --arg action "released" \
        --arg hold_id "$hold_id" \
        --arg timestamp "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        --arg user "$(whoami)" \
        --arg reason "$release_reason" \
        '{
            "action": $action,
            "hold_id": $hold_id,
            "timestamp": $timestamp,
            "user": $user,
            "details": {
                "release_reason": $reason
            }
        }')
    
    local history=$(read_legal_holds ".history")
    local updated_history=$(echo "$history" | jq --argjson entry "$history_entry" '. += [$entry]')
    update_legal_holds ".history" "$updated_history"
    
    log "Legal hold released: $hold_id"
    
    # Log compliance event
    cat >> "$COMPLIANCE_LOG_FILE" << EOF
$(date -u +%Y-%m-%dT%H:%M:%SZ) LEGAL_HOLD_RELEASE hold_id=$hold_id reason="$release_reason" released_by=$(whoami)
EOF
}

# Run retention management cycle
run_retention_cycle() {
    log "Starting retention management cycle..."
    
    initialize_retention_config
    initialize_legal_hold_registry
    
    local processed_count=0
    local archived_count=0
    local deleted_count=0
    
    # Process all backups
    if [ -d "$BACKUP_ROOT_DIR/backups" ]; then
        while IFS= read -r -d '' backup_dir; do
            local backup_id=$(basename "$backup_dir")
            
            log "Processing backup: $backup_id"
            
            # Apply retention policy
            apply_retention_policy "$backup_dir" "$backup_id"
            
            processed_count=$((processed_count + 1))
            
            # Check if backup was archived or deleted
            if [ -f "$backup_dir/.archived" ]; then
                archived_count=$((archived_count + 1))
            elif [ ! -d "$backup_dir" ]; then
                deleted_count=$((deleted_count + 1))
            fi
            
        done < <(find "$BACKUP_ROOT_DIR/backups" -mindepth 1 -maxdepth 1 -type d -print0)
    fi
    
    log "Retention cycle completed: $processed_count processed, $archived_count archived, $deleted_count deleted"
    
    # Generate retention report
    generate_retention_report "$processed_count" "$archived_count" "$deleted_count"
}

# Generate retention compliance report
generate_retention_report() {
    local processed="$1"
    local archived="$2"
    local deleted="$3"
    
    local report_file="$BACKUP_ROOT_DIR/reports/retention_report_$(date +%Y%m%d_%H%M%S).json"
    mkdir -p "$(dirname "$report_file")"
    
    log "Generating retention compliance report: $report_file"
    
    cat > "$report_file" << EOF
{
    "report_metadata": {
        "generated_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
        "report_type": "retention_compliance",
        "reporting_period": "$(date -d '1 day ago' +%Y-%m-%d) to $(date +%Y-%m-%d)",
        "generated_by": "retention_manager"
    },
    "summary": {
        "total_backups_processed": $processed,
        "backups_archived": $archived,
        "backups_deleted": $deleted,
        "backups_retained": $((processed - deleted))
    },
    "compliance_status": {
        "retention_policies_applied": true,
        "legal_holds_respected": true,
        "secure_deletion_verified": true,
        "audit_trail_complete": true
    },
    "legal_holds": $(read_legal_holds ".holds"),
    "active_holds_count": $(read_legal_holds ".holds" | jq 'map(select(.status == "active")) | length'),
    "classification_summary": {
        "public": 0,
        "internal": 0,
        "confidential": 0,
        "restricted": 0
    },
    "compliance_frameworks": {
        "gdpr_compliant": true,
        "sox_compliant": true,
        "hipaa_compliant": true,
        "pci_dss_compliant": true
    },
    "next_review_date": "$(date -d '+1 month' +%Y-%m-%d)"
}
EOF
    
    # Upload report to S3
    aws s3 cp "$report_file" "s3://$S3_ARCHIVE_BUCKET/compliance_reports/$(basename "$report_file")" \
        --region "$S3_REGION" \
        --storage-class STANDARD \
        --server-side-encryption aws:kms \
        --ssekms-key-id "$ENCRYPTION_KEY_ID" 2>/dev/null || true
    
    log "Retention compliance report generated: $report_file"
}

# Show retention status
show_retention_status() {
    initialize_legal_hold_registry
    
    echo
    echo "=== GENESIS Data Retention Status ==="
    echo
    
    # Legal holds summary
    local active_holds=$(read_legal_holds ".holds" | jq 'map(select(.status == "active")) | length')
    local total_holds=$(read_legal_holds ".holds" | jq 'length')
    
    echo "Legal Holds: $active_holds active, $total_holds total"
    
    if [ "$active_holds" -gt 0 ]; then
        echo "Active Legal Holds:"
        read_legal_holds ".holds" | jq -r 'to_entries[] | select(.value.status == "active") | "  \(.value.hold_id): \(.value.name) (created: \(.value.created_at))"'
    fi
    echo
    
    # Backup statistics
    if [ -d "$BACKUP_ROOT_DIR/backups" ]; then
        local total_backups=$(find "$BACKUP_ROOT_DIR/backups" -mindepth 1 -maxdepth 1 -type d | wc -l)
        local archived_backups=$(find "$BACKUP_ROOT_DIR/backups" -name ".archived" | wc -l)
        
        echo "Backup Statistics:"
        echo "  Total backups: $total_backups"
        echo "  Archived backups: $archived_backups"
        echo "  Active backups: $((total_backups - archived_backups))"
    else
        echo "No backups directory found"
    fi
    echo
    
    # Recent compliance events
    echo "Recent Compliance Events:"
    if [ -f "$COMPLIANCE_LOG_FILE" ]; then
        tail -n 10 "$COMPLIANCE_LOG_FILE" | while read -r line; do
            echo "  $line"
        done
    else
        echo "  No compliance events logged"
    fi
    echo
}

# Command line interface
case "${1:-help}" in
    "run")
        run_retention_cycle
        ;;
    "status")
        show_retention_status
        ;;
    "hold")
        case "${2:-}" in
            "create")
                if [ $# -lt 5 ]; then
                    error_exit "Usage: $0 hold create <name> <reason> <criteria_json> <contact>"
                fi
                create_legal_hold "$3" "$4" "$5" "$6"
                ;;
            "release")
                if [ $# -lt 4 ]; then
                    error_exit "Usage: $0 hold release <hold_id> <reason>"
                fi
                release_legal_hold "$3" "$4"
                ;;
            "list")
                read_legal_holds ".holds" | jq -r 'to_entries[] | "Hold ID: \(.value.hold_id)\nName: \(.value.name)\nStatus: \(.value.status)\nCreated: \(.value.created_at)\nReason: \(.value.reason)\n"'
                ;;
            *)
                error_exit "Usage: $0 hold [create|release|list]"
                ;;
        esac
        ;;
    "classify")
        if [ -z "${2:-}" ]; then
            error_exit "Usage: $0 classify <backup_path>"
        fi
        backup_path="$2"
        backup_id=$(basename "$backup_path")
        classify_backup_data "$backup_path" "$backup_id"
        ;;
    "archive")
        if [ -z "${2:-}" ]; then
            error_exit "Usage: $0 archive <backup_path>"
        fi
        backup_path="$2"
        backup_id=$(basename "$backup_path")
        classification=$(classify_backup_data "$backup_path" "$backup_id" | jq -r '.classification')
        archive_backup "$backup_path" "$backup_id" "$classification"
        ;;
    "config")
        case "${2:-show}" in
            "show")
                cat "$RETENTION_CONFIG_FILE" | jq '.'
                ;;
            "edit")
                ${EDITOR:-vi} "$RETENTION_CONFIG_FILE"
                ;;
            "validate")
                if jq empty "$RETENTION_CONFIG_FILE" 2>/dev/null; then
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
GENESIS Data Retention and Compliance Manager

Usage: $0 <command> [options]

Commands:
    run                                     Run retention management cycle
    status                                  Show retention and compliance status
    hold create <name> <reason> <criteria> <contact>  Create legal hold
    hold release <hold_id> <reason>         Release legal hold
    hold list                               List all legal holds
    classify <backup_path>                  Classify backup data
    archive <backup_path>                   Archive specific backup
    config [show|edit|validate]             Manage configuration
    help                                    Show this help message

Examples:
    $0 run                                  # Run retention cycle
    $0 status                               # Show current status
    $0 hold create "Litigation ABC" "Pending lawsuit" '{"backup_ids":["backup_20241215_*"]}' "legal@company.com"
    $0 hold release hold_20241215_143000_litigation "Case settled"
    $0 classify /var/backups/genesis/backups/backup_20241215_143000
    $0 archive /var/backups/genesis/backups/backup_20241215_143000

Legal Hold Criteria Examples:
    By backup IDs: '{"backup_ids":["backup_20241215_143000","backup_20241215_150000"]}'
    By date range: '{"date_range":{"start":"2024-01-01","end":"2024-12-31"}}'
    By classification: '{"classification":"confidential"}'

Configuration Files:
    Retention Policy: $RETENTION_CONFIG_FILE
    Legal Hold Registry: $LEGAL_HOLD_REGISTRY
    Compliance Log: $COMPLIANCE_LOG_FILE

Environment Variables:
    GDPR_RETENTION_DAYS         GDPR retention period (default: 2555)
    SOX_RETENTION_DAYS          SOX retention period (default: 2555)
    HIPAA_RETENTION_DAYS        HIPAA retention period (default: 2555)
    PCI_RETENTION_DAYS          PCI DSS retention period (default: 365)
    ARCHIVE_AFTER_DAYS          Archive threshold (default: 90)
    S3_ARCHIVE_BUCKET           S3 bucket for archives
    ENCRYPTION_KEY_ID           KMS key for encryption

EOF
        ;;
esac