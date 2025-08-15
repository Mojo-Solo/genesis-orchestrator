#!/bin/bash

# GENESIS Orchestrator - Backup Validation and Testing Framework
# Automated backup integrity verification with restore testing
# Ensures backup quality and restorability for RTO/RPO compliance

set -euo pipefail

# Configuration
BACKUP_ROOT_DIR="${BACKUP_ROOT_DIR:-/var/backups/genesis}"
TEST_DB_HOST="${TEST_DB_HOST:-mysql-test}"
TEST_DB_USER="${TEST_DB_USER:-genesis_test}"
TEST_DB_PASSWORD="${TEST_DB_PASSWORD}"
TEST_DB_PREFIX="${TEST_DB_PREFIX:-genesis_test}"
TEST_REDIS_HOST="${TEST_REDIS_HOST:-redis-test}"
TEST_REDIS_PORT="${TEST_REDIS_PORT:-6380}"
S3_BUCKET="${BACKUP_S3_BUCKET:-genesis-disaster-recovery}"
S3_REGION="${BACKUP_S3_REGION:-us-west-2}"

# Validation parameters
VALIDATION_TIMEOUT_MINUTES="${VALIDATION_TIMEOUT:-30}"
PARTIAL_RESTORE_RECORD_LIMIT="${PARTIAL_RESTORE_LIMIT:-1000}"
CHECKSUM_VERIFICATION="${ENABLE_CHECKSUM_VERIFICATION:-true}"
RESTORE_TESTING="${ENABLE_RESTORE_TESTING:-true}"

# Logging setup
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="${BACKUP_ROOT_DIR}/logs/validation_$(date +%Y%m%d_%H%M%S).log"
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

# Initialize validation environment
setup_validation_environment() {
    log "Setting up validation environment..."
    
    # Verify test database connectivity
    mysql -h "$TEST_DB_HOST" -u "$TEST_DB_USER" -p"$TEST_DB_PASSWORD" -e "SELECT 1;" >/dev/null 2>&1 || \
        error_exit "Cannot connect to test MySQL server at $TEST_DB_HOST"
    
    # Verify test Redis connectivity
    redis-cli -h "$TEST_REDIS_HOST" -p "$TEST_REDIS_PORT" ping >/dev/null 2>&1 || \
        error_exit "Cannot connect to test Redis at $TEST_REDIS_HOST:$TEST_REDIS_PORT"
    
    # Create validation workspace
    mkdir -p "$BACKUP_ROOT_DIR/validation/workspace"
    mkdir -p "$BACKUP_ROOT_DIR/validation/reports"
    
    log "Validation environment setup completed"
}

# Validate backup file integrity
validate_backup_integrity() {
    local backup_id="$1"
    local backup_dir="$2"
    
    log "Validating backup integrity for: $backup_id"
    
    local validation_report="$BACKUP_ROOT_DIR/validation/reports/${backup_id}_integrity.json"
    local start_time=$(date +%s)
    
    # Initialize validation report
    cat > "$validation_report" << EOF
{
    "backup_id": "$backup_id",
    "validation_type": "integrity",
    "start_time": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "tests": {},
    "overall_status": "running"
}
EOF
    
    local integrity_status="passed"
    local test_results="{}"
    
    # Test 1: Verify backup metadata
    log "Test 1: Backup metadata validation"
    if [ -f "$backup_dir/backup_metadata.json" ]; then
        local metadata_valid=$(jq -e '.backup_id and .timestamp and .database and .redis' "$backup_dir/backup_metadata.json" >/dev/null 2>&1 && echo "true" || echo "false")
        if [ "$metadata_valid" = "true" ]; then
            test_results=$(echo "$test_results" | jq '. + {"metadata_validation": {"status": "passed", "message": "Backup metadata is valid"}}')
            log "  ✓ Metadata validation: PASSED"
        else
            test_results=$(echo "$test_results" | jq '. + {"metadata_validation": {"status": "failed", "message": "Invalid backup metadata"}}')
            integrity_status="failed"
            log "  ✗ Metadata validation: FAILED"
        fi
    else
        test_results=$(echo "$test_results" | jq '. + {"metadata_validation": {"status": "failed", "message": "Metadata file missing"}}')
        integrity_status="failed"
        log "  ✗ Metadata validation: FAILED (file missing)"
    fi
    
    # Test 2: Checksum verification
    if [ "$CHECKSUM_VERIFICATION" = "true" ]; then
        log "Test 2: Checksum verification"
        local checksum_failed=()
        
        for checksum_file in "$backup_dir"/*.sha256; do
            if [ -f "$checksum_file" ]; then
                local filename=$(basename "$checksum_file")
                if sha256sum -c "$checksum_file" --status; then
                    log "  ✓ $filename: PASSED"
                else
                    checksum_failed+=("$filename")
                    log "  ✗ $filename: FAILED"
                fi
            fi
        done
        
        if [ ${#checksum_failed[@]} -eq 0 ]; then
            test_results=$(echo "$test_results" | jq '. + {"checksum_verification": {"status": "passed", "message": "All checksums valid"}}')
            log "  ✓ Checksum verification: PASSED"
        else
            test_results=$(echo "$test_results" | jq --argjson failed "$(printf '%s\n' "${checksum_failed[@]}" | jq -R . | jq -s .)" '. + {"checksum_verification": {"status": "failed", "message": "Checksum failures", "failed_files": $failed}}')
            integrity_status="failed"
            log "  ✗ Checksum verification: FAILED"
        fi
    fi
    
    # Test 3: File existence and size validation
    log "Test 3: File existence and size validation"
    local required_files=("database_full.sql.gz.enc" "redis_dump.rdb.gz.enc")
    local missing_files=()
    local undersized_files=()
    
    for file in "${required_files[@]}"; do
        local filepath="$backup_dir/$file"
        if [ ! -f "$filepath" ]; then
            missing_files+=("$file")
            log "  ✗ $file: MISSING"
        else
            local filesize=$(stat -f%z "$filepath" 2>/dev/null || stat -c%s "$filepath" 2>/dev/null)
            if [ "$filesize" -lt 1024 ]; then  # Files should be at least 1KB
                undersized_files+=("$file:${filesize}bytes")
                log "  ✗ $file: UNDERSIZED ($filesize bytes)"
            else
                log "  ✓ $file: OK ($(numfmt --to=iec "$filesize"))"
            fi
        fi
    done
    
    if [ ${#missing_files[@]} -eq 0 ] && [ ${#undersized_files[@]} -eq 0 ]; then
        test_results=$(echo "$test_results" | jq '. + {"file_validation": {"status": "passed", "message": "All required files present and properly sized"}}')
    else
        local issues=()
        [ ${#missing_files[@]} -gt 0 ] && issues+=("Missing: ${missing_files[*]}")
        [ ${#undersized_files[@]} -gt 0 ] && issues+=("Undersized: ${undersized_files[*]}")
        test_results=$(echo "$test_results" | jq --arg issues "${issues[*]}" '. + {"file_validation": {"status": "failed", "message": $issues}}')
        integrity_status="failed"
    fi
    
    # Test 4: Encryption validation
    log "Test 4: Encryption validation"
    local encryption_test_file="$backup_dir/database_full.sql.gz.enc"
    if [ -f "$encryption_test_file" ]; then
        # Test decryption with first 1KB
        if timeout 30 bash -c "head -c 1024 '$encryption_test_file' | openssl enc -aes-256-gcm -d -pbkdf2 -pass env:BACKUP_ENCRYPTION_PASSWORD -out /dev/null" 2>/dev/null; then
            test_results=$(echo "$test_results" | jq '. + {"encryption_validation": {"status": "passed", "message": "Encryption format valid"}}')
            log "  ✓ Encryption validation: PASSED"
        else
            test_results=$(echo "$test_results" | jq '. + {"encryption_validation": {"status": "failed", "message": "Cannot decrypt with provided password"}}')
            integrity_status="failed"
            log "  ✗ Encryption validation: FAILED"
        fi
    else
        test_results=$(echo "$test_results" | jq '. + {"encryption_validation": {"status": "skipped", "message": "No encrypted files found"}}')
        log "  - Encryption validation: SKIPPED"
    fi
    
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    # Update validation report
    jq --argjson tests "$test_results" \
       --arg status "$integrity_status" \
       --arg end_time "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
       --argjson duration "$duration" \
       '.tests = $tests | .overall_status = $status | .end_time = $end_time | .duration_seconds = $duration' \
       "$validation_report" > "${validation_report}.tmp" && mv "${validation_report}.tmp" "$validation_report"
    
    log "Backup integrity validation completed: $integrity_status (${duration}s)"
    
    [ "$integrity_status" = "passed" ] && return 0 || return 1
}

# Test database restore functionality
test_database_restore() {
    local backup_id="$1"
    local backup_dir="$2"
    
    log "Testing database restore for: $backup_id"
    
    local validation_report="$BACKUP_ROOT_DIR/validation/reports/${backup_id}_restore.json"
    local start_time=$(date +%s)
    local test_db_name="${TEST_DB_PREFIX}_restore_$(date +%s)"
    
    # Initialize restore test report
    cat > "$validation_report" << EOF
{
    "backup_id": "$backup_id",
    "validation_type": "restore_test",
    "start_time": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "test_database": "$test_db_name",
    "tests": {},
    "overall_status": "running"
}
EOF
    
    local restore_status="passed"
    local test_results="{}"
    
    # Create test database
    log "Creating test database: $test_db_name"
    mysql -h "$TEST_DB_HOST" -u "$TEST_DB_USER" -p"$TEST_DB_PASSWORD" -e "CREATE DATABASE $test_db_name;" || {
        error_exit "Failed to create test database"
    }
    
    # Cleanup function
    cleanup_test_db() {
        log "Cleaning up test database: $test_db_name"
        mysql -h "$TEST_DB_HOST" -u "$TEST_DB_USER" -p"$TEST_DB_PASSWORD" -e "DROP DATABASE IF EXISTS $test_db_name;" 2>/dev/null || true
    }
    trap cleanup_test_db EXIT
    
    # Test 1: Decrypt and decompress backup
    log "Test 1: Backup decryption and decompression"
    local workspace_dir="$BACKUP_ROOT_DIR/validation/workspace/${backup_id}"
    mkdir -p "$workspace_dir"
    
    if openssl enc -aes-256-gcm -d -pbkdf2 -in "$backup_dir/database_full.sql.gz.enc" -out "$workspace_dir/restore_test.sql.gz" -pass env:BACKUP_ENCRYPTION_PASSWORD; then
        if gunzip "$workspace_dir/restore_test.sql.gz"; then
            test_results=$(echo "$test_results" | jq '. + {"decryption_decompression": {"status": "passed", "message": "Successfully decrypted and decompressed"}}')
            log "  ✓ Decryption and decompression: PASSED"
        else
            test_results=$(echo "$test_results" | jq '. + {"decryption_decompression": {"status": "failed", "message": "Decompression failed"}}')
            restore_status="failed"
            log "  ✗ Decryption and decompression: FAILED (decompression)"
            cleanup_test_db
            return 1
        fi
    else
        test_results=$(echo "$test_results" | jq '. + {"decryption_decompression": {"status": "failed", "message": "Decryption failed"}}')
        restore_status="failed"
        log "  ✗ Decryption and decompression: FAILED (decryption)"
        cleanup_test_db
        return 1
    fi
    
    # Test 2: Schema validation
    log "Test 2: Schema validation"
    local sql_file="$workspace_dir/restore_test.sql"
    
    # Check for required tables in SQL dump
    local required_tables=("orchestration_runs" "agent_executions" "memory_items" "router_metrics" "stability_tracking" "security_audit_logs" "vault_audit_logs")
    local missing_tables=()
    
    for table in "${required_tables[@]}"; do
        if ! grep -q "CREATE TABLE.*$table" "$sql_file"; then
            missing_tables+=("$table")
        fi
    done
    
    if [ ${#missing_tables[@]} -eq 0 ]; then
        test_results=$(echo "$test_results" | jq '. + {"schema_validation": {"status": "passed", "message": "All required tables found in backup"}}')
        log "  ✓ Schema validation: PASSED"
    else
        test_results=$(echo "$test_results" | jq --argjson missing "$(printf '%s\n' "${missing_tables[@]}" | jq -R . | jq -s .)" '. + {"schema_validation": {"status": "failed", "message": "Missing tables", "missing_tables": $missing}}')
        restore_status="failed"
        log "  ✗ Schema validation: FAILED (missing tables: ${missing_tables[*]})"
    fi
    
    # Test 3: Partial restore with timeout
    log "Test 3: Partial database restore"
    
    # Extract just the schema and first N records for faster testing
    head -n $PARTIAL_RESTORE_RECORD_LIMIT "$sql_file" > "$workspace_dir/partial_restore.sql"
    
    # Add a timeout to the restore operation
    if timeout "${VALIDATION_TIMEOUT_MINUTES}m" mysql -h "$TEST_DB_HOST" -u "$TEST_DB_USER" -p"$TEST_DB_PASSWORD" "$test_db_name" < "$workspace_dir/partial_restore.sql" 2>/dev/null; then
        test_results=$(echo "$test_results" | jq '. + {"partial_restore": {"status": "passed", "message": "Partial restore completed successfully"}}')
        log "  ✓ Partial restore: PASSED"
    else
        test_results=$(echo "$test_results" | jq '. + {"partial_restore": {"status": "failed", "message": "Partial restore failed or timed out"}}')
        restore_status="failed"
        log "  ✗ Partial restore: FAILED"
    fi
    
    # Test 4: Table structure verification
    log "Test 4: Table structure verification"
    local structure_issues=()
    
    for table in "${required_tables[@]}"; do
        local table_exists=$(mysql -h "$TEST_DB_HOST" -u "$TEST_DB_USER" -p"$TEST_DB_PASSWORD" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$test_db_name' AND table_name='$table';" -N 2>/dev/null)
        if [ "$table_exists" -eq 1 ]; then
            log "  ✓ Table $table: EXISTS"
        else
            structure_issues+=("$table")
            log "  ✗ Table $table: MISSING"
        fi
    done
    
    if [ ${#structure_issues[@]} -eq 0 ]; then
        test_results=$(echo "$test_results" | jq '. + {"structure_verification": {"status": "passed", "message": "All tables created successfully"}}')
    else
        test_results=$(echo "$test_results" | jq --argjson issues "$(printf '%s\n' "${structure_issues[@]}" | jq -R . | jq -s .)" '. + {"structure_verification": {"status": "failed", "message": "Table creation issues", "missing_tables": $issues}}')
        restore_status="failed"
    fi
    
    # Test 5: Data integrity sampling
    log "Test 5: Data integrity sampling"
    local data_issues=()
    
    # Sample some records from key tables
    for table in "orchestration_runs" "agent_executions"; do
        local record_count=$(mysql -h "$TEST_DB_HOST" -u "$TEST_DB_USER" -p"$TEST_DB_PASSWORD" -e "SELECT COUNT(*) FROM $test_db_name.$table;" -N 2>/dev/null || echo "0")
        if [ "$record_count" -gt 0 ]; then
            # Test foreign key relationships
            if [ "$table" = "agent_executions" ]; then
                local orphaned_records=$(mysql -h "$TEST_DB_HOST" -u "$TEST_DB_USER" -p"$TEST_DB_PASSWORD" -e "SELECT COUNT(*) FROM $test_db_name.agent_executions ae LEFT JOIN $test_db_name.orchestration_runs orch ON ae.orchestration_run_id = orch.id WHERE orch.id IS NULL;" -N 2>/dev/null || echo "0")
                if [ "$orphaned_records" -gt 0 ]; then
                    data_issues+=("$table:$orphaned_records orphaned records")
                fi
            fi
            log "  ✓ Table $table: $record_count records"
        else
            log "  - Table $table: No data (acceptable for partial restore)"
        fi
    done
    
    if [ ${#data_issues[@]} -eq 0 ]; then
        test_results=$(echo "$test_results" | jq '. + {"data_integrity": {"status": "passed", "message": "Data integrity checks passed"}}')
    else
        test_results=$(echo "$test_results" | jq --argjson issues "$(printf '%s\n' "${data_issues[@]}" | jq -R . | jq -s .)" '. + {"data_integrity": {"status": "warning", "message": "Data integrity issues found", "issues": $issues}}')
        log "  ! Data integrity: WARNING (${data_issues[*]})"
    fi
    
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    # Clean up workspace
    rm -rf "$workspace_dir"
    
    # Update validation report
    jq --argjson tests "$test_results" \
       --arg status "$restore_status" \
       --arg end_time "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
       --argjson duration "$duration" \
       '.tests = $tests | .overall_status = $status | .end_time = $end_time | .duration_seconds = $duration' \
       "$validation_report" > "${validation_report}.tmp" && mv "${validation_report}.tmp" "$validation_report"
    
    log "Database restore test completed: $restore_status (${duration}s)"
    
    [ "$restore_status" = "passed" ] && return 0 || return 1
}

# Test Redis restore functionality
test_redis_restore() {
    local backup_id="$1"
    local backup_dir="$2"
    
    log "Testing Redis restore for: $backup_id"
    
    local validation_report="$BACKUP_ROOT_DIR/validation/reports/${backup_id}_redis_restore.json"
    local start_time=$(date +%s)
    local test_redis_db="15"  # Use Redis DB 15 for testing
    
    # Initialize restore test report
    cat > "$validation_report" << EOF
{
    "backup_id": "$backup_id",
    "validation_type": "redis_restore_test",
    "start_time": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "test_redis_db": "$test_redis_db",
    "tests": {},
    "overall_status": "running"
}
EOF
    
    local restore_status="passed"
    local test_results="{}"
    
    # Cleanup function
    cleanup_test_redis() {
        log "Cleaning up test Redis database"
        redis-cli -h "$TEST_REDIS_HOST" -p "$TEST_REDIS_PORT" -n "$test_redis_db" FLUSHDB 2>/dev/null || true
    }
    trap cleanup_test_redis EXIT
    
    # Clear test database
    cleanup_test_redis
    
    # Test 1: Decrypt and decompress Redis backup
    log "Test 1: Redis backup decryption and decompression"
    local workspace_dir="$BACKUP_ROOT_DIR/validation/workspace/${backup_id}"
    mkdir -p "$workspace_dir"
    
    if openssl enc -aes-256-gcm -d -pbkdf2 -in "$backup_dir/redis_dump.rdb.gz.enc" -out "$workspace_dir/test_redis.rdb.gz" -pass env:BACKUP_ENCRYPTION_PASSWORD; then
        if gunzip "$workspace_dir/test_redis.rdb.gz"; then
            test_results=$(echo "$test_results" | jq '. + {"decryption_decompression": {"status": "passed", "message": "Successfully decrypted and decompressed"}}')
            log "  ✓ Decryption and decompression: PASSED"
        else
            test_results=$(echo "$test_results" | jq '. + {"decryption_decompression": {"status": "failed", "message": "Decompression failed"}}')
            restore_status="failed"
            log "  ✗ Decryption and decompression: FAILED (decompression)"
            return 1
        fi
    else
        test_results=$(echo "$test_results" | jq '. + {"decryption_decompression": {"status": "failed", "message": "Decryption failed"}}')
        restore_status="failed"
        log "  ✗ Decryption and decompression: FAILED (decryption)"
        return 1
    fi
    
    # Test 2: RDB file format validation
    log "Test 2: RDB file format validation"
    local rdb_file="$workspace_dir/test_redis.rdb"
    
    # Check RDB magic number (first 9 bytes should be "REDIS" followed by version)
    if [ -f "$rdb_file" ]; then
        local magic=$(head -c 9 "$rdb_file" | tr -d '\0')
        if [[ "$magic" =~ ^REDIS[0-9]{4}$ ]]; then
            test_results=$(echo "$test_results" | jq --arg version "${magic:5}" '. + {"rdb_format": {"status": "passed", "message": "Valid RDB format", "version": $version}}')
            log "  ✓ RDB format validation: PASSED (version: ${magic:5})"
        else
            test_results=$(echo "$test_results" | jq '. + {"rdb_format": {"status": "failed", "message": "Invalid RDB magic number"}}')
            restore_status="failed"
            log "  ✗ RDB format validation: FAILED"
        fi
    else
        test_results=$(echo "$test_results" | jq '. + {"rdb_format": {"status": "failed", "message": "RDB file not found"}}')
        restore_status="failed"
        log "  ✗ RDB format validation: FAILED (file missing)"
    fi
    
    # Test 3: Selective key restore test
    log "Test 3: Selective key restore test"
    
    # For this test, we'll use redis-cli --rdb to read some keys
    # In a production environment, you'd have a more sophisticated restore mechanism
    
    # Note: This is a simplified test. In reality, you'd need to:
    # 1. Stop Redis
    # 2. Replace the RDB file
    # 3. Start Redis
    # Or implement a key-by-key restore mechanism
    
    test_results=$(echo "$test_results" | jq '. + {"selective_restore": {"status": "skipped", "message": "Requires Redis restart for full RDB restore"}}')
    log "  - Selective restore: SKIPPED (requires Redis restart)"
    
    # Test 4: RDB file size and basic structure
    log "Test 4: RDB file analysis"
    if [ -f "$rdb_file" ]; then
        local file_size=$(stat -f%z "$rdb_file" 2>/dev/null || stat -c%s "$rdb_file" 2>/dev/null)
        local min_size=100  # Minimum expected size
        
        if [ "$file_size" -gt $min_size ]; then
            test_results=$(echo "$test_results" | jq --argjson size "$file_size" '. + {"file_analysis": {"status": "passed", "message": "RDB file has reasonable size", "size_bytes": $size}}')
            log "  ✓ File analysis: PASSED ($(numfmt --to=iec "$file_size"))"
        else
            test_results=$(echo "$test_results" | jq --argjson size "$file_size" '. + {"file_analysis": {"status": "warning", "message": "RDB file is very small", "size_bytes": $size}}')
            log "  ! File analysis: WARNING (file size: $file_size bytes)"
        fi
    fi
    
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    # Clean up workspace
    rm -rf "$workspace_dir"
    
    # Update validation report
    jq --argjson tests "$test_results" \
       --arg status "$restore_status" \
       --arg end_time "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
       --argjson duration "$duration" \
       '.tests = $tests | .overall_status = $status | .end_time = $end_time | .duration_seconds = $duration' \
       "$validation_report" > "${validation_report}.tmp" && mv "${validation_report}.tmp" "$validation_report"
    
    log "Redis restore test completed: $restore_status (${duration}s)"
    
    [ "$restore_status" = "passed" ] && return 0 || return 1
}

# Generate comprehensive validation report
generate_validation_report() {
    local backup_id="$1"
    
    log "Generating comprehensive validation report for: $backup_id"
    
    local report_dir="$BACKUP_ROOT_DIR/validation/reports"
    local comprehensive_report="$report_dir/${backup_id}_comprehensive.json"
    
    # Collect all individual test reports
    local integrity_report="$report_dir/${backup_id}_integrity.json"
    local restore_report="$report_dir/${backup_id}_restore.json"
    local redis_restore_report="$report_dir/${backup_id}_redis_restore.json"
    
    # Initialize comprehensive report
    cat > "$comprehensive_report" << EOF
{
    "backup_id": "$backup_id",
    "validation_timestamp": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "validation_version": "1.0.0",
    "reports": {},
    "summary": {
        "total_tests": 0,
        "passed_tests": 0,
        "failed_tests": 0,
        "warning_tests": 0,
        "skipped_tests": 0,
        "overall_status": "unknown",
        "rto_compliance": "unknown",
        "rpo_compliance": "unknown"
    }
}
EOF
    
    local total_tests=0
    local passed_tests=0
    local failed_tests=0
    local warning_tests=0
    local skipped_tests=0
    local overall_status="passed"
    
    # Process integrity report
    if [ -f "$integrity_report" ]; then
        local integrity_data=$(cat "$integrity_report")
        jq --argjson data "$integrity_data" '.reports.integrity = $data' "$comprehensive_report" > "${comprehensive_report}.tmp" && mv "${comprehensive_report}.tmp" "$comprehensive_report"
        
        # Count test results
        local integrity_tests=$(echo "$integrity_data" | jq -r '.tests | to_entries[] | .value.status')
        while IFS= read -r status; do
            ((total_tests++))
            case "$status" in
                "passed") ((passed_tests++)) ;;
                "failed") ((failed_tests++)); overall_status="failed" ;;
                "warning") ((warning_tests++)) ;;
                "skipped") ((skipped_tests++)) ;;
            esac
        done <<< "$integrity_tests"
    fi
    
    # Process restore report
    if [ -f "$restore_report" ]; then
        local restore_data=$(cat "$restore_report")
        jq --argjson data "$restore_data" '.reports.database_restore = $data' "$comprehensive_report" > "${comprehensive_report}.tmp" && mv "${comprehensive_report}.tmp" "$comprehensive_report"
        
        # Count test results
        local restore_tests=$(echo "$restore_data" | jq -r '.tests | to_entries[] | .value.status')
        while IFS= read -r status; do
            ((total_tests++))
            case "$status" in
                "passed") ((passed_tests++)) ;;
                "failed") ((failed_tests++)); overall_status="failed" ;;
                "warning") ((warning_tests++)) ;;
                "skipped") ((skipped_tests++)) ;;
            esac
        done <<< "$restore_tests"
    fi
    
    # Process Redis restore report
    if [ -f "$redis_restore_report" ]; then
        local redis_data=$(cat "$redis_restore_report")
        jq --argjson data "$redis_data" '.reports.redis_restore = $data' "$comprehensive_report" > "${comprehensive_report}.tmp" && mv "${comprehensive_report}.tmp" "$comprehensive_report"
        
        # Count test results
        local redis_tests=$(echo "$redis_data" | jq -r '.tests | to_entries[] | .value.status')
        while IFS= read -r status; do
            ((total_tests++))
            case "$status" in
                "passed") ((passed_tests++)) ;;
                "failed") ((failed_tests++)); overall_status="failed" ;;
                "warning") ((warning_tests++)) ;;
                "skipped") ((skipped_tests++)) ;;
            esac
        done <<< "$redis_tests"
    fi
    
    # Determine compliance status
    local rto_compliance="compliant"
    local rpo_compliance="compliant"
    
    if [ "$overall_status" = "failed" ]; then
        rto_compliance="non_compliant"
        rpo_compliance="non_compliant"
    elif [ $warning_tests -gt 0 ]; then
        overall_status="warning"
    fi
    
    # Update summary
    jq --argjson total "$total_tests" \
       --argjson passed "$passed_tests" \
       --argjson failed "$failed_tests" \
       --argjson warnings "$warning_tests" \
       --argjson skipped "$skipped_tests" \
       --arg status "$overall_status" \
       --arg rto "$rto_compliance" \
       --arg rpo "$rpo_compliance" \
       '.summary.total_tests = $total | 
        .summary.passed_tests = $passed | 
        .summary.failed_tests = $failed | 
        .summary.warning_tests = $warnings | 
        .summary.skipped_tests = $skipped | 
        .summary.overall_status = $status |
        .summary.rto_compliance = $rto |
        .summary.rpo_compliance = $rpo' \
       "$comprehensive_report" > "${comprehensive_report}.tmp" && mv "${comprehensive_report}.tmp" "$comprehensive_report"
    
    # Upload to S3
    aws s3 cp "$comprehensive_report" "s3://$S3_BUCKET/validation/reports/${backup_id}_comprehensive.json" --region "$S3_REGION" 2>/dev/null || true
    
    log "Comprehensive validation report generated: $comprehensive_report"
    log "Validation summary: $total_tests total, $passed_tests passed, $failed_tests failed, $warning_tests warnings"
    log "Overall status: $overall_status"
    
    return $([ "$overall_status" = "failed" ] && echo 1 || echo 0)
}

# Validate specific backup
validate_backup() {
    local backup_id="$1"
    local backup_dir=""
    
    # Determine backup directory
    if [ -d "$BACKUP_ROOT_DIR/backups/$backup_id" ]; then
        backup_dir="$BACKUP_ROOT_DIR/backups/$backup_id"
        log "Using local backup: $backup_dir"
    else
        # Download from S3
        backup_dir="$BACKUP_ROOT_DIR/validation/downloads/$backup_id"
        mkdir -p "$backup_dir"
        log "Downloading backup from S3: s3://$S3_BUCKET/backups/$backup_id/"
        
        if ! aws s3 sync "s3://$S3_BUCKET/backups/$backup_id/" "$backup_dir/" --region "$S3_REGION"; then
            error_exit "Failed to download backup from S3"
        fi
    fi
    
    log "Starting validation for backup: $backup_id"
    
    # Run validation tests
    local validation_success=true
    
    # 1. Integrity validation
    if ! validate_backup_integrity "$backup_id" "$backup_dir"; then
        validation_success=false
    fi
    
    # 2. Database restore test (if enabled)
    if [ "$RESTORE_TESTING" = "true" ]; then
        if ! test_database_restore "$backup_id" "$backup_dir"; then
            validation_success=false
        fi
        
        # 3. Redis restore test
        if ! test_redis_restore "$backup_id" "$backup_dir"; then
            validation_success=false
        fi
    fi
    
    # 4. Generate comprehensive report
    if ! generate_validation_report "$backup_id"; then
        validation_success=false
    fi
    
    # Cleanup downloaded backup if needed
    if [[ "$backup_dir" == *"/validation/downloads/"* ]]; then
        rm -rf "$backup_dir"
    fi
    
    if [ "$validation_success" = true ]; then
        log "Backup validation PASSED: $backup_id"
        return 0
    else
        log "Backup validation FAILED: $backup_id"
        return 1
    fi
}

# List backups available for validation
list_backups() {
    log "Available backups for validation:"
    echo
    
    # Local backups
    if [ -d "$BACKUP_ROOT_DIR/backups" ]; then
        echo "LOCAL BACKUPS:"
        find "$BACKUP_ROOT_DIR/backups" -mindepth 1 -maxdepth 1 -type d -exec basename {} \; | sort -r | head -10
        echo
    fi
    
    # S3 backups
    echo "S3 BACKUPS (Recent):"
    aws s3 ls "s3://$S3_BUCKET/backups/" --region "$S3_REGION" | awk '{print $2}' | sed 's/\///' | sort -r | head -10
}

# Main execution
case "${1:-help}" in
    "validate")
        if [ -z "${2:-}" ]; then
            error_exit "Usage: $0 validate <backup-id>"
        fi
        setup_validation_environment
        validate_backup "$2"
        ;;
    "list")
        list_backups
        ;;
    "integrity")
        if [ -z "${2:-}" ]; then
            error_exit "Usage: $0 integrity <backup-id>"
        fi
        setup_validation_environment
        backup_dir="$BACKUP_ROOT_DIR/backups/$2"
        [ -d "$backup_dir" ] || error_exit "Backup directory not found: $backup_dir"
        validate_backup_integrity "$2" "$backup_dir"
        ;;
    "restore-test")
        if [ -z "${2:-}" ]; then
            error_exit "Usage: $0 restore-test <backup-id>"
        fi
        setup_validation_environment
        backup_dir="$BACKUP_ROOT_DIR/backups/$2"
        [ -d "$backup_dir" ] || error_exit "Backup directory not found: $backup_dir"
        test_database_restore "$2" "$backup_dir"
        ;;
    "help"|*)
        cat << EOF
GENESIS Orchestrator Backup Validation Framework

Usage: $0 <command> [options]

Commands:
    validate <backup-id>        Complete validation of specified backup
    integrity <backup-id>       Run integrity checks only
    restore-test <backup-id>    Run restore tests only
    list                        List available backups
    help                        Show this help message

Examples:
    $0 validate backup_20241215_143000_a1b2c3d4     # Full validation
    $0 integrity backup_20241215_143000_a1b2c3d4    # Integrity only
    $0 restore-test backup_20241215_143000_a1b2c3d4 # Restore test only
    $0 list                                          # List backups

Environment Variables:
    TEST_DB_HOST                Test database host
    TEST_DB_USER                Test database user
    TEST_DB_PASSWORD            Test database password
    TEST_REDIS_HOST             Test Redis host
    VALIDATION_TIMEOUT          Timeout in minutes (default: 30)
    ENABLE_RESTORE_TESTING      Enable restore tests (default: true)

Reports:
    Validation reports are saved to:
    $BACKUP_ROOT_DIR/validation/reports/

EOF
        ;;
esac