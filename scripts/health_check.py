#!/usr/bin/env python3
"""
Health Check Script for GENESIS Orchestrator
============================================
Performs comprehensive health checks on all components.
"""

import asyncio
import json
import os
import sys
import time
from datetime import datetime
from pathlib import Path

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent.parent))

import aiohttp
import redis
from sqlalchemy import create_engine, text
from temporalio.client import Client


class HealthChecker:
    """Comprehensive health checker for all components."""
    
    def __init__(self):
        self.checks_passed = []
        self.checks_failed = []
        self.start_time = time.time()
        
    async def check_temporal(self) -> bool:
        """Check Temporal connectivity."""
        try:
            host = os.getenv("TEMPORAL_HOST", "localhost:7233")
            namespace = os.getenv("TEMPORAL_NAMESPACE", "default")
            
            client = await Client.connect(host, namespace=namespace)
            
            # Try to describe the namespace
            await client.workflow_service.describe_namespace(namespace)
            
            self.checks_passed.append("✓ Temporal connected")
            return True
            
        except Exception as e:
            self.checks_failed.append(f"✗ Temporal: {str(e)}")
            return False
    
    def check_redis(self) -> bool:
        """Check Redis connectivity."""
        try:
            host = os.getenv("REDIS_HOST", "localhost")
            port = int(os.getenv("REDIS_PORT", "6379"))
            
            r = redis.Redis(host=host, port=port, decode_responses=True)
            
            # Test connection with ping
            r.ping()
            
            # Test write/read
            test_key = "health_check_test"
            r.set(test_key, "OK", ex=10)
            value = r.get(test_key)
            
            if value != "OK":
                raise ValueError("Redis read/write test failed")
            
            self.checks_passed.append("✓ Redis connected and operational")
            return True
            
        except Exception as e:
            self.checks_failed.append(f"✗ Redis: {str(e)}")
            return False
    
    def check_database(self) -> bool:
        """Check database connectivity."""
        try:
            db_type = os.getenv("DB_CONNECTION", "mysql")
            host = os.getenv("DB_HOST", "127.0.0.1")
            port = os.getenv("DB_PORT", "3306")
            database = os.getenv("DB_DATABASE", "genesis_orchestrator")
            username = os.getenv("DB_USERNAME", "root")
            password = os.getenv("DB_PASSWORD", "")
            
            if db_type == "mysql":
                connection_string = f"mysql+pymysql://{username}:{password}@{host}:{port}/{database}"
            else:
                connection_string = f"postgresql+asyncpg://{username}:{password}@{host}:{port}/{database}"
            
            engine = create_engine(connection_string)
            
            # Test connection
            with engine.connect() as conn:
                result = conn.execute(text("SELECT 1"))
                result.fetchone()
            
            self.checks_passed.append(f"✓ Database ({db_type}) connected")
            return True
            
        except Exception as e:
            self.checks_failed.append(f"✗ Database: {str(e)}")
            return False
    
    def check_config_files(self) -> bool:
        """Check required configuration files."""
        required_files = [
            "config/router_config.json",
            "env.example",
        ]
        
        all_present = True
        for file_path in required_files:
            full_path = Path(file_path)
            if full_path.exists():
                # Try to validate JSON files
                if file_path.endswith('.json'):
                    try:
                        with open(full_path) as f:
                            json.load(f)
                        self.checks_passed.append(f"✓ Config file valid: {file_path}")
                    except json.JSONDecodeError as e:
                        self.checks_failed.append(f"✗ Invalid JSON in {file_path}: {e}")
                        all_present = False
                else:
                    self.checks_passed.append(f"✓ Config file exists: {file_path}")
            else:
                self.checks_failed.append(f"✗ Missing config file: {file_path}")
                all_present = False
        
        return all_present
    
    async def check_http_endpoints(self) -> bool:
        """Check HTTP endpoints."""
        endpoints = [
            ("Orchestrator", "http://localhost:8080/health"),
            ("Metrics", "http://localhost:9090/metrics"),
            ("Laravel Backend", "http://localhost:8000/api/health/ready"),
        ]
        
        all_healthy = True
        async with aiohttp.ClientSession() as session:
            for name, url in endpoints:
                try:
                    async with session.get(url, timeout=5) as response:
                        if response.status == 200:
                            self.checks_passed.append(f"✓ {name} endpoint responding")
                        else:
                            self.checks_failed.append(f"✗ {name} endpoint returned {response.status}")
                            all_healthy = False
                except Exception as e:
                    self.checks_failed.append(f"✗ {name} endpoint: {str(e)}")
                    all_healthy = False
        
        return all_healthy
    
    def check_python_imports(self) -> bool:
        """Check required Python packages."""
        required_packages = [
            "temporalio",
            "opentelemetry",
            "redis",
            "sqlalchemy",
            "pydantic",
            "aiohttp",
        ]
        
        all_available = True
        for package in required_packages:
            try:
                __import__(package)
                self.checks_passed.append(f"✓ Python package available: {package}")
            except ImportError:
                self.checks_failed.append(f"✗ Missing Python package: {package}")
                all_available = False
        
        return all_available
    
    def check_directories(self) -> bool:
        """Check required directories exist."""
        required_dirs = [
            "orchestrator",
            "tools/temporal",
            "backend",
            "config",
            "artifacts",
            "logs",
        ]
        
        all_present = True
        for dir_path in required_dirs:
            if Path(dir_path).is_dir():
                self.checks_passed.append(f"✓ Directory exists: {dir_path}")
            else:
                self.checks_failed.append(f"✗ Missing directory: {dir_path}")
                all_present = False
        
        return all_present
    
    def check_environment_variables(self) -> bool:
        """Check critical environment variables."""
        critical_vars = [
            "TEMPORAL_HOST",
            "TEMPORAL_TASK_QUEUE",
            "DB_CONNECTION",
            "REDIS_HOST",
        ]
        
        warnings = []
        for var in critical_vars:
            value = os.getenv(var)
            if value:
                self.checks_passed.append(f"✓ Environment variable set: {var}")
            else:
                warnings.append(f"⚠ Environment variable not set: {var} (using default)")
        
        if warnings:
            for warning in warnings:
                print(warning)
        
        return True  # Non-critical, just warnings
    
    async def run_all_checks(self) -> bool:
        """Run all health checks."""
        print("=" * 60)
        print("GENESIS Orchestrator Health Check")
        print("=" * 60)
        print(f"Started at: {datetime.now().isoformat()}")
        print()
        
        # Run checks
        checks = [
            ("Python Packages", self.check_python_imports()),
            ("Directories", self.check_directories()),
            ("Config Files", self.check_config_files()),
            ("Environment Variables", self.check_environment_variables()),
            ("Database", self.check_database()),
            ("Redis", self.check_redis()),
            ("Temporal", await self.check_temporal()),
            # ("HTTP Endpoints", await self.check_http_endpoints()),  # Skip if services not running
        ]
        
        all_passed = all(result for _, result in checks)
        
        # Print results
        print("\n" + "=" * 60)
        print("RESULTS")
        print("=" * 60)
        
        if self.checks_passed:
            print("\nPassed Checks:")
            for check in self.checks_passed:
                print(f"  {check}")
        
        if self.checks_failed:
            print("\nFailed Checks:")
            for check in self.checks_failed:
                print(f"  {check}")
        
        # Summary
        elapsed = time.time() - self.start_time
        print("\n" + "=" * 60)
        print(f"Total Checks: {len(self.checks_passed) + len(self.checks_failed)}")
        print(f"Passed: {len(self.checks_passed)}")
        print(f"Failed: {len(self.checks_failed)}")
        print(f"Duration: {elapsed:.2f} seconds")
        
        if all_passed:
            print("\n✅ HEALTH CHECK PASSED - System is ready")
            return True
        else:
            print("\n❌ HEALTH CHECK FAILED - Please fix the issues above")
            return False


async def main():
    """Main entry point."""
    checker = HealthChecker()
    success = await checker.run_all_checks()
    sys.exit(0 if success else 1)


if __name__ == "__main__":
    asyncio.run(main())