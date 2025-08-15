"""
GENESIS Orchestrator - Load Testing Automation Suite
Comprehensive load testing and continuous performance validation framework
"""

import os
import json
import time
import asyncio
import aiohttp
import threading
import subprocess
from typing import Dict, Any, List, Optional, Tuple, Callable, Union
from dataclasses import dataclass, asdict, field
from datetime import datetime, timedelta
from concurrent.futures import ThreadPoolExecutor, as_completed
from collections import defaultdict, deque
from enum import Enum
import logging
import multiprocessing
from pathlib import Path
import random
import uuid

import numpy as np
import matplotlib.pyplot as plt
import seaborn as sns
from scipy import stats
import requests
import psutil

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

class LoadTestType(Enum):
    """Types of load tests"""
    SMOKE_TEST = "smoke_test"           # Light load to verify basic functionality
    LOAD_TEST = "load_test"             # Expected normal load
    STRESS_TEST = "stress_test"         # Above normal load to find breaking point
    SPIKE_TEST = "spike_test"           # Sudden load increases
    VOLUME_TEST = "volume_test"         # Large amounts of data
    ENDURANCE_TEST = "endurance_test"   # Extended duration testing
    SCALABILITY_TEST = "scalability_test"  # Gradual load increase

class LoadPattern(Enum):
    """Load generation patterns"""
    CONSTANT = "constant"               # Steady load
    RAMP_UP = "ramp_up"                # Gradual increase
    RAMP_DOWN = "ramp_down"            # Gradual decrease
    STEP = "step"                      # Step increases
    SPIKE = "spike"                    # Sudden spikes
    WAVE = "wave"                      # Sinusoidal pattern
    RANDOM = "random"                  # Random load variations

@dataclass
class LoadTestConfig:
    """Configuration for a load test"""
    test_id: str
    test_name: str
    test_type: LoadTestType
    load_pattern: LoadPattern
    duration_minutes: int
    virtual_users: int
    ramp_up_time_minutes: int
    target_urls: List[str]
    http_methods: List[str] = field(default_factory=lambda: ["GET"])
    request_headers: Dict[str, str] = field(default_factory=dict)
    request_payloads: List[Dict[str, Any]] = field(default_factory=list)
    think_time_seconds: float = 1.0
    timeout_seconds: int = 30
    success_criteria: Dict[str, float] = field(default_factory=dict)
    environment: str = "production"
    tags: List[str] = field(default_factory=list)

@dataclass
class RequestResult:
    """Individual request result"""
    request_id: str
    url: str
    method: str
    start_time: float
    end_time: float
    response_time_ms: float
    status_code: int
    response_size_bytes: int
    success: bool
    error_message: Optional[str] = None
    user_id: str = ""

@dataclass
class LoadTestMetrics:
    """Aggregated metrics for a load test"""
    test_id: str
    start_time: float
    end_time: Optional[float] = None
    total_requests: int = 0
    successful_requests: int = 0
    failed_requests: int = 0
    total_bytes_transferred: int = 0
    avg_response_time_ms: float = 0.0
    min_response_time_ms: float = float('inf')
    max_response_time_ms: float = 0.0
    p50_response_time_ms: float = 0.0
    p90_response_time_ms: float = 0.0
    p95_response_time_ms: float = 0.0
    p99_response_time_ms: float = 0.0
    requests_per_second: float = 0.0
    peak_rps: float = 0.0
    error_rate: float = 0.0
    throughput_mbps: float = 0.0
    concurrent_users: int = 0
    
@dataclass
class SystemPerformanceSnapshot:
    """System performance during load test"""
    timestamp: float
    cpu_percent: float
    memory_percent: float
    memory_used_gb: float
    disk_io_read_mbps: float
    disk_io_write_mbps: float
    network_sent_mbps: float
    network_recv_mbps: float
    active_connections: int
    load_average: float

@dataclass
class LoadTestResult:
    """Complete load test result"""
    config: LoadTestConfig
    metrics: LoadTestMetrics
    system_snapshots: List[SystemPerformanceSnapshot]
    request_results: List[RequestResult]
    error_breakdown: Dict[str, int]
    performance_issues: List[str]
    recommendations: List[str]
    success: bool
    failure_reason: Optional[str] = None

class VirtualUser:
    """Simulates a single virtual user"""
    
    def __init__(self, user_id: str, config: LoadTestConfig):
        self.user_id = user_id
        self.config = config
        self.session = requests.Session()
        self.session.headers.update(config.request_headers)
        self.results: List[RequestResult] = []
        self.active = False
    
    async def run_async(self, duration_seconds: float) -> List[RequestResult]:
        """Run virtual user with async HTTP requests"""
        self.active = True
        start_time = time.time()
        
        async with aiohttp.ClientSession(
            timeout=aiohttp.ClientTimeout(total=self.config.timeout_seconds),
            headers=self.config.request_headers
        ) as session:
            
            while self.active and (time.time() - start_time) < duration_seconds:
                # Select random URL and method
                url = random.choice(self.config.target_urls)
                method = random.choice(self.config.http_methods)
                
                # Execute request
                result = await self._execute_async_request(session, url, method)
                self.results.append(result)
                
                # Think time
                if self.config.think_time_seconds > 0:
                    await asyncio.sleep(self.config.think_time_seconds)
        
        self.active = False
        return self.results
    
    def run_sync(self, duration_seconds: float) -> List[RequestResult]:
        """Run virtual user with synchronous HTTP requests"""
        self.active = True
        start_time = time.time()
        
        while self.active and (time.time() - start_time) < duration_seconds:
            # Select random URL and method
            url = random.choice(self.config.target_urls)
            method = random.choice(self.config.http_methods)
            
            # Execute request
            result = self._execute_sync_request(url, method)
            self.results.append(result)
            
            # Think time
            if self.config.think_time_seconds > 0:
                time.sleep(self.config.think_time_seconds)
        
        self.active = False
        return self.results
    
    async def _execute_async_request(self, session: aiohttp.ClientSession, 
                                   url: str, method: str) -> RequestResult:
        """Execute single async HTTP request"""
        request_id = f"{self.user_id}_{int(time.time() * 1000)}_{random.randint(1000, 9999)}"
        start_time = time.time()
        
        try:
            # Select payload if available
            payload = None
            if self.config.request_payloads:
                payload = random.choice(self.config.request_payloads)
            
            async with session.request(method, url, json=payload) as response:
                content = await response.read()
                end_time = time.time()
                
                return RequestResult(
                    request_id=request_id,
                    url=url,
                    method=method,
                    start_time=start_time,
                    end_time=end_time,
                    response_time_ms=(end_time - start_time) * 1000,
                    status_code=response.status,
                    response_size_bytes=len(content),
                    success=200 <= response.status < 400,
                    user_id=self.user_id
                )
                
        except Exception as e:
            end_time = time.time()
            return RequestResult(
                request_id=request_id,
                url=url,
                method=method,
                start_time=start_time,
                end_time=end_time,
                response_time_ms=(end_time - start_time) * 1000,
                status_code=0,
                response_size_bytes=0,
                success=False,
                error_message=str(e),
                user_id=self.user_id
            )
    
    def _execute_sync_request(self, url: str, method: str) -> RequestResult:
        """Execute single synchronous HTTP request"""
        request_id = f"{self.user_id}_{int(time.time() * 1000)}_{random.randint(1000, 9999)}"
        start_time = time.time()
        
        try:
            # Select payload if available
            payload = None
            if self.config.request_payloads:
                payload = random.choice(self.config.request_payloads)
            
            response = self.session.request(
                method, url, 
                json=payload,
                timeout=self.config.timeout_seconds
            )
            
            end_time = time.time()
            
            return RequestResult(
                request_id=request_id,
                url=url,
                method=method,
                start_time=start_time,
                end_time=end_time,
                response_time_ms=(end_time - start_time) * 1000,
                status_code=response.status_code,
                response_size_bytes=len(response.content),
                success=200 <= response.status_code < 400,
                user_id=self.user_id
            )
            
        except Exception as e:
            end_time = time.time()
            return RequestResult(
                request_id=request_id,
                url=url,
                method=method,
                start_time=start_time,
                end_time=end_time,
                response_time_ms=(end_time - start_time) * 1000,
                status_code=0,
                response_size_bytes=0,
                success=False,
                error_message=str(e),
                user_id=self.user_id
            )
    
    def stop(self):
        """Stop the virtual user"""
        self.active = False

class SystemMonitor:
    """Monitor system performance during load tests"""
    
    def __init__(self):
        self.monitoring = False
        self.snapshots: List[SystemPerformanceSnapshot] = []
        self.monitor_thread = None
    
    def start_monitoring(self, interval_seconds: float = 1.0):
        """Start system monitoring"""
        if not self.monitoring:
            self.monitoring = True
            self.monitor_thread = threading.Thread(
                target=self._monitoring_loop,
                args=(interval_seconds,),
                daemon=True
            )
            self.monitor_thread.start()
            logger.info("System monitoring started")
    
    def stop_monitoring(self):
        """Stop system monitoring"""
        if self.monitoring:
            self.monitoring = False
            if self.monitor_thread:
                self.monitor_thread.join(timeout=2.0)
            logger.info("System monitoring stopped")
    
    def _monitoring_loop(self, interval: float):
        """System monitoring loop"""
        last_net_io = None
        last_disk_io = None
        last_check_time = None
        
        while self.monitoring:
            try:
                current_time = time.time()
                
                # Basic system metrics
                cpu_percent = psutil.cpu_percent(interval=0.1)
                memory = psutil.virtual_memory()
                
                # Network I/O
                net_io = psutil.net_io_counters()
                net_sent_mbps = 0.0
                net_recv_mbps = 0.0
                
                if last_net_io and last_check_time:
                    time_delta = current_time - last_check_time
                    if time_delta > 0:
                        sent_bytes = net_io.bytes_sent - last_net_io.bytes_sent
                        recv_bytes = net_io.bytes_recv - last_net_io.bytes_recv
                        net_sent_mbps = (sent_bytes / time_delta) / (1024 * 1024)
                        net_recv_mbps = (recv_bytes / time_delta) / (1024 * 1024)
                
                last_net_io = net_io
                
                # Disk I/O
                disk_io = psutil.disk_io_counters()
                disk_read_mbps = 0.0
                disk_write_mbps = 0.0
                
                if last_disk_io and last_check_time:
                    time_delta = current_time - last_check_time
                    if time_delta > 0:
                        read_bytes = disk_io.read_bytes - last_disk_io.read_bytes
                        write_bytes = disk_io.write_bytes - last_disk_io.write_bytes
                        disk_read_mbps = (read_bytes / time_delta) / (1024 * 1024)
                        disk_write_mbps = (write_bytes / time_delta) / (1024 * 1024)
                
                last_disk_io = disk_io
                
                # Load average
                load_avg = os.getloadavg()[0] if hasattr(os, 'getloadavg') else 0.0
                
                # Network connections (approximation)
                active_connections = len(psutil.net_connections())
                
                snapshot = SystemPerformanceSnapshot(
                    timestamp=current_time,
                    cpu_percent=cpu_percent,
                    memory_percent=memory.percent,
                    memory_used_gb=memory.used / (1024**3),
                    disk_io_read_mbps=disk_read_mbps,
                    disk_io_write_mbps=disk_write_mbps,
                    network_sent_mbps=net_sent_mbps,
                    network_recv_mbps=net_recv_mbps,
                    active_connections=active_connections,
                    load_average=load_avg
                )
                
                self.snapshots.append(snapshot)
                
                # Keep only last 1000 snapshots (memory management)
                if len(self.snapshots) > 1000:
                    self.snapshots = self.snapshots[-1000:]
                
                last_check_time = current_time
                time.sleep(interval)
                
            except Exception as e:
                logger.error(f"System monitoring error: {e}")
                time.sleep(interval)
    
    def get_snapshots(self) -> List[SystemPerformanceSnapshot]:
        """Get all collected snapshots"""
        return self.snapshots.copy()
    
    def clear_snapshots(self):
        """Clear collected snapshots"""
        self.snapshots.clear()

class LoadTestEngine:
    """Main load testing engine"""
    
    def __init__(self, output_dir: str = "orchestrator_runs/load_tests"):
        self.output_dir = Path(output_dir)
        self.output_dir.mkdir(parents=True, exist_ok=True)
        
        self.system_monitor = SystemMonitor()
        self.active_tests: Dict[str, 'LoadTestExecution'] = {}
        self.test_results: Dict[str, LoadTestResult] = {}
        
        # Default test configurations
        self.default_configs = self._create_default_configs()
        
        logger.info("Load test engine initialized")
    
    def _create_default_configs(self) -> Dict[str, LoadTestConfig]:
        """Create default test configurations"""
        
        configs = {}
        
        # Smoke test
        configs["smoke"] = LoadTestConfig(
            test_id="smoke_test",
            test_name="Smoke Test",
            test_type=LoadTestType.SMOKE_TEST,
            load_pattern=LoadPattern.CONSTANT,
            duration_minutes=2,
            virtual_users=5,
            ramp_up_time_minutes=1,
            target_urls=["http://localhost:8000/health/ready"],
            success_criteria={
                "error_rate": 0.01,  # Max 1% error rate
                "avg_response_time_ms": 1000,  # Max 1s average
                "p95_response_time_ms": 2000   # Max 2s P95
            }
        )
        
        # Standard load test
        configs["load"] = LoadTestConfig(
            test_id="standard_load_test",
            test_name="Standard Load Test",
            test_type=LoadTestType.LOAD_TEST,
            load_pattern=LoadPattern.RAMP_UP,
            duration_minutes=10,
            virtual_users=50,
            ramp_up_time_minutes=3,
            target_urls=[
                "http://localhost:8000/health/ready",
                "http://localhost:8000/health/metrics",
                "http://localhost:8000/api/orchestration/status"
            ],
            success_criteria={
                "error_rate": 0.05,  # Max 5% error rate
                "avg_response_time_ms": 2000,  # Max 2s average
                "p95_response_time_ms": 5000   # Max 5s P95
            }
        )
        
        # Stress test
        configs["stress"] = LoadTestConfig(
            test_id="stress_test",
            test_name="Stress Test",
            test_type=LoadTestType.STRESS_TEST,
            load_pattern=LoadPattern.STEP,
            duration_minutes=15,
            virtual_users=200,
            ramp_up_time_minutes=5,
            target_urls=[
                "http://localhost:8000/health/ready",
                "http://localhost:8000/api/orchestration/status"
            ],
            success_criteria={
                "error_rate": 0.10,  # Max 10% error rate
                "avg_response_time_ms": 5000,  # Max 5s average
                "p95_response_time_ms": 10000  # Max 10s P95
            }
        )
        
        return configs
    
    def run_test(self, config: LoadTestConfig, async_mode: bool = True) -> LoadTestResult:
        """Run a complete load test"""
        
        logger.info(f"Starting load test: {config.test_name}")
        
        # Clear previous system snapshots
        self.system_monitor.clear_snapshots()
        
        # Start system monitoring
        self.system_monitor.start_monitoring(interval_seconds=1.0)
        
        try:
            if async_mode:
                result = asyncio.run(self._run_async_test(config))
            else:
                result = self._run_sync_test(config)
            
            # Stop system monitoring
            self.system_monitor.stop_monitoring()
            
            # Add system snapshots to result
            result.system_snapshots = self.system_monitor.get_snapshots()
            
            # Analyze results and generate recommendations
            result = self._analyze_test_results(result)
            
            # Save results
            self._save_test_results(result)
            
            # Store in memory
            self.test_results[config.test_id] = result
            
            logger.info(f"Load test completed: {config.test_name} - Success: {result.success}")
            return result
            
        except Exception as e:
            self.system_monitor.stop_monitoring()
            logger.error(f"Load test failed: {e}")
            
            return LoadTestResult(
                config=config,
                metrics=LoadTestMetrics(test_id=config.test_id, start_time=time.time()),
                system_snapshots=[],
                request_results=[],
                error_breakdown={},
                performance_issues=[f"Test execution failed: {e}"],
                recommendations=[],
                success=False,
                failure_reason=str(e)
            )
    
    async def _run_async_test(self, config: LoadTestConfig) -> LoadTestResult:
        """Run load test with async virtual users"""
        
        start_time = time.time()
        duration_seconds = config.duration_minutes * 60
        ramp_up_seconds = config.ramp_up_time_minutes * 60
        
        # Create virtual users
        virtual_users = [
            VirtualUser(f"user_{i:04d}", config)
            for i in range(config.virtual_users)
        ]
        
        # Implement load pattern
        user_tasks = []
        
        if config.load_pattern == LoadPattern.CONSTANT:
            # All users start immediately
            for user in virtual_users:
                task = asyncio.create_task(user.run_async(duration_seconds))
                user_tasks.append(task)
        
        elif config.load_pattern == LoadPattern.RAMP_UP:
            # Gradual ramp up
            ramp_interval = ramp_up_seconds / config.virtual_users
            
            for i, user in enumerate(virtual_users):
                # Delay start based on ramp up
                delay = i * ramp_interval
                task = asyncio.create_task(
                    self._delayed_user_start(user, delay, duration_seconds)
                )
                user_tasks.append(task)
        
        elif config.load_pattern == LoadPattern.STEP:
            # Step increases
            users_per_step = config.virtual_users // 5  # 5 steps
            step_interval = ramp_up_seconds / 5
            
            for i, user in enumerate(virtual_users):
                step = i // users_per_step
                delay = step * step_interval
                task = asyncio.create_task(
                    self._delayed_user_start(user, delay, duration_seconds)
                )
                user_tasks.append(task)
        
        # Wait for all users to complete
        await asyncio.gather(*user_tasks)
        
        # Collect all results
        all_results = []
        for user in virtual_users:
            all_results.extend(user.results)
        
        # Calculate metrics
        metrics = self._calculate_metrics(config.test_id, start_time, all_results)
        
        # Analyze errors
        error_breakdown = self._analyze_errors(all_results)
        
        return LoadTestResult(
            config=config,
            metrics=metrics,
            system_snapshots=[],  # Will be added later
            request_results=all_results,
            error_breakdown=error_breakdown,
            performance_issues=[],  # Will be analyzed later
            recommendations=[],  # Will be generated later
            success=True
        )
    
    async def _delayed_user_start(self, user: VirtualUser, delay: float, duration: float):
        """Start virtual user after delay"""
        if delay > 0:
            await asyncio.sleep(delay)
        return await user.run_async(duration)
    
    def _run_sync_test(self, config: LoadTestConfig) -> LoadTestResult:
        """Run load test with synchronous virtual users"""
        
        start_time = time.time()
        duration_seconds = config.duration_minutes * 60
        
        # Create virtual users
        virtual_users = [
            VirtualUser(f"user_{i:04d}", config)
            for i in range(config.virtual_users)
        ]
        
        # Run users in thread pool
        with ThreadPoolExecutor(max_workers=config.virtual_users) as executor:
            futures = [
                executor.submit(user.run_sync, duration_seconds)
                for user in virtual_users
            ]
            
            # Collect results as they complete
            all_results = []
            for future in as_completed(futures):
                try:
                    results = future.result()
                    all_results.extend(results)
                except Exception as e:
                    logger.error(f"Virtual user failed: {e}")
        
        # Calculate metrics
        metrics = self._calculate_metrics(config.test_id, start_time, all_results)
        
        # Analyze errors
        error_breakdown = self._analyze_errors(all_results)
        
        return LoadTestResult(
            config=config,
            metrics=metrics,
            system_snapshots=[],  # Will be added later
            request_results=all_results,
            error_breakdown=error_breakdown,
            performance_issues=[],  # Will be analyzed later
            recommendations=[],  # Will be generated later
            success=True
        )
    
    def _calculate_metrics(self, test_id: str, start_time: float, 
                          results: List[RequestResult]) -> LoadTestMetrics:
        """Calculate aggregated metrics from request results"""
        
        if not results:
            return LoadTestMetrics(test_id=test_id, start_time=start_time, end_time=time.time())
        
        successful_results = [r for r in results if r.success]
        failed_results = [r for r in results if not r.success]
        
        response_times = [r.response_time_ms for r in successful_results]
        all_response_times = [r.response_time_ms for r in results]
        
        # Time-based metrics
        end_time = max(r.end_time for r in results)
        total_duration = end_time - start_time
        
        # Calculate RPS over time
        time_buckets = defaultdict(int)
        for result in results:
            bucket = int(result.start_time)
            time_buckets[bucket] += 1
        
        rps_values = list(time_buckets.values())
        
        metrics = LoadTestMetrics(
            test_id=test_id,
            start_time=start_time,
            end_time=end_time,
            total_requests=len(results),
            successful_requests=len(successful_results),
            failed_requests=len(failed_results),
            total_bytes_transferred=sum(r.response_size_bytes for r in successful_results),
            requests_per_second=len(results) / total_duration if total_duration > 0 else 0,
            peak_rps=max(rps_values) if rps_values else 0,
            error_rate=len(failed_results) / len(results) if results else 0
        )
        
        if response_times:
            metrics.avg_response_time_ms = np.mean(response_times)
            metrics.min_response_time_ms = min(response_times)
            metrics.max_response_time_ms = max(response_times)
            metrics.p50_response_time_ms = np.percentile(response_times, 50)
            metrics.p90_response_time_ms = np.percentile(response_times, 90)
            metrics.p95_response_time_ms = np.percentile(response_times, 95)
            metrics.p99_response_time_ms = np.percentile(response_times, 99)
        
        if all_response_times:
            metrics.throughput_mbps = (metrics.total_bytes_transferred / (1024 * 1024)) / total_duration if total_duration > 0 else 0
        
        return metrics
    
    def _analyze_errors(self, results: List[RequestResult]) -> Dict[str, int]:
        """Analyze error breakdown"""
        error_breakdown = defaultdict(int)
        
        for result in results:
            if not result.success:
                if result.error_message:
                    error_breakdown[result.error_message] += 1
                elif result.status_code > 0:
                    error_breakdown[f"HTTP_{result.status_code}"] += 1
                else:
                    error_breakdown["Unknown_Error"] += 1
        
        return dict(error_breakdown)
    
    def _analyze_test_results(self, result: LoadTestResult) -> LoadTestResult:
        """Analyze test results and generate insights"""
        
        config = result.config
        metrics = result.metrics
        performance_issues = []
        recommendations = []
        
        # Check success criteria
        criteria = config.success_criteria
        
        if criteria.get("error_rate", float('inf')) < metrics.error_rate:
            performance_issues.append(
                f"Error rate {metrics.error_rate:.2%} exceeds threshold {criteria['error_rate']:.2%}"
            )
            recommendations.append("Investigate and fix errors causing high failure rate")
        
        if criteria.get("avg_response_time_ms", float('inf')) < metrics.avg_response_time_ms:
            performance_issues.append(
                f"Average response time {metrics.avg_response_time_ms:.0f}ms exceeds threshold {criteria['avg_response_time_ms']:.0f}ms"
            )
            recommendations.append("Optimize response time through caching or algorithm improvements")
        
        if criteria.get("p95_response_time_ms", float('inf')) < metrics.p95_response_time_ms:
            performance_issues.append(
                f"P95 response time {metrics.p95_response_time_ms:.0f}ms exceeds threshold {criteria['p95_response_time_ms']:.0f}ms"
            )
            recommendations.append("Address long tail latency issues")
        
        # System performance analysis
        if result.system_snapshots:
            avg_cpu = np.mean([s.cpu_percent for s in result.system_snapshots])
            max_cpu = max([s.cpu_percent for s in result.system_snapshots])
            avg_memory = np.mean([s.memory_percent for s in result.system_snapshots])
            max_memory = max([s.memory_percent for s in result.system_snapshots])
            
            if max_cpu > 90:
                performance_issues.append(f"CPU utilization peaked at {max_cpu:.1f}%")
                recommendations.append("Consider CPU optimization or horizontal scaling")
            
            if max_memory > 85:
                performance_issues.append(f"Memory utilization peaked at {max_memory:.1f}%")
                recommendations.append("Investigate memory usage patterns and optimize")
            
            if avg_cpu > 70:
                recommendations.append("Sustained high CPU usage - consider performance tuning")
        
        # Throughput analysis
        if metrics.requests_per_second < 10:  # Low throughput
            performance_issues.append(f"Low throughput: {metrics.requests_per_second:.1f} RPS")
            recommendations.append("Investigate bottlenecks limiting request throughput")
        
        # Update result
        result.performance_issues = performance_issues
        result.recommendations = recommendations
        result.success = len(performance_issues) == 0
        
        return result
    
    def _save_test_results(self, result: LoadTestResult):
        """Save test results to files"""
        
        test_dir = self.output_dir / result.config.test_id
        test_dir.mkdir(exist_ok=True)
        
        # Save main result
        result_file = test_dir / "result.json"
        with open(result_file, 'w') as f:
            # Create serializable version
            serializable_result = {
                "config": asdict(result.config),
                "metrics": asdict(result.metrics),
                "error_breakdown": result.error_breakdown,
                "performance_issues": result.performance_issues,
                "recommendations": result.recommendations,
                "success": result.success,
                "failure_reason": result.failure_reason,
                "system_snapshots_count": len(result.system_snapshots),
                "request_results_count": len(result.request_results)
            }
            json.dump(serializable_result, f, indent=2, default=str)
        
        # Save detailed request results (sample)
        if result.request_results:
            sample_size = min(1000, len(result.request_results))
            sample_results = random.sample(result.request_results, sample_size)
            
            requests_file = test_dir / "request_sample.json"
            with open(requests_file, 'w') as f:
                json.dump([asdict(r) for r in sample_results], f, indent=2, default=str)
        
        # Generate performance charts
        if result.system_snapshots:
            self._generate_performance_charts(result, test_dir)
        
        logger.info(f"Test results saved to {test_dir}")
    
    def _generate_performance_charts(self, result: LoadTestResult, output_dir: Path):
        """Generate performance visualization charts"""
        
        try:
            import matplotlib
            matplotlib.use('Agg')  # Non-interactive backend
            
            snapshots = result.system_snapshots
            if not snapshots:
                return
            
            timestamps = [(s.timestamp - snapshots[0].timestamp) / 60 for s in snapshots]  # Minutes
            
            # Create subplots
            fig, axes = plt.subplots(2, 2, figsize=(15, 10))
            fig.suptitle(f"Load Test Performance: {result.config.test_name}")
            
            # CPU and Memory
            axes[0, 0].plot(timestamps, [s.cpu_percent for s in snapshots], 'b-', label='CPU %')
            axes[0, 0].plot(timestamps, [s.memory_percent for s in snapshots], 'r-', label='Memory %')
            axes[0, 0].set_title('CPU and Memory Usage')
            axes[0, 0].set_xlabel('Time (minutes)')
            axes[0, 0].set_ylabel('Percentage')
            axes[0, 0].legend()
            axes[0, 0].grid(True)
            
            # Network I/O
            axes[0, 1].plot(timestamps, [s.network_sent_mbps for s in snapshots], 'g-', label='Sent MB/s')
            axes[0, 1].plot(timestamps, [s.network_recv_mbps for s in snapshots], 'orange', label='Received MB/s')
            axes[0, 1].set_title('Network I/O')
            axes[0, 1].set_xlabel('Time (minutes)')
            axes[0, 1].set_ylabel('MB/s')
            axes[0, 1].legend()
            axes[0, 1].grid(True)
            
            # Disk I/O
            axes[1, 0].plot(timestamps, [s.disk_io_read_mbps for s in snapshots], 'purple', label='Read MB/s')
            axes[1, 0].plot(timestamps, [s.disk_io_write_mbps for s in snapshots], 'brown', label='Write MB/s')
            axes[1, 0].set_title('Disk I/O')
            axes[1, 0].set_xlabel('Time (minutes)')
            axes[1, 0].set_ylabel('MB/s')
            axes[1, 0].legend()
            axes[1, 0].grid(True)
            
            # Load Average and Connections
            ax2 = axes[1, 1].twinx()
            line1 = axes[1, 1].plot(timestamps, [s.load_average for s in snapshots], 'red', label='Load Average')
            line2 = ax2.plot(timestamps, [s.active_connections for s in snapshots], 'blue', label='Connections')
            
            axes[1, 1].set_title('Load Average and Active Connections')
            axes[1, 1].set_xlabel('Time (minutes)')
            axes[1, 1].set_ylabel('Load Average', color='red')
            ax2.set_ylabel('Active Connections', color='blue')
            
            # Combined legend
            lines = line1 + line2
            labels = [l.get_label() for l in lines]
            axes[1, 1].legend(lines, labels, loc='upper left')
            axes[1, 1].grid(True)
            
            plt.tight_layout()
            plt.savefig(output_dir / "performance_charts.png", dpi=150, bbox_inches='tight')
            plt.close()
            
            logger.info("Performance charts generated")
            
        except Exception as e:
            logger.error(f"Chart generation failed: {e}")
    
    def run_test_suite(self, test_names: List[str] = None) -> Dict[str, LoadTestResult]:
        """Run a suite of predefined tests"""
        
        if test_names is None:
            test_names = ["smoke", "load"]
        
        results = {}
        
        for test_name in test_names:
            if test_name in self.default_configs:
                config = self.default_configs[test_name]
                logger.info(f"Running test suite: {test_name}")
                
                result = self.run_test(config)
                results[test_name] = result
                
                # Stop if smoke test fails
                if test_name == "smoke" and not result.success:
                    logger.error("Smoke test failed - stopping test suite")
                    break
                
                # Brief pause between tests
                time.sleep(10)
            else:
                logger.warning(f"Test configuration '{test_name}' not found")
        
        return results
    
    def get_test_result(self, test_id: str) -> Optional[LoadTestResult]:
        """Get stored test result"""
        return self.test_results.get(test_id)
    
    def list_test_results(self) -> List[str]:
        """List all stored test result IDs"""
        return list(self.test_results.keys())

# Global load test engine instance
load_test_engine = LoadTestEngine()

def get_load_test_engine() -> LoadTestEngine:
    """Get the global load test engine instance"""
    return load_test_engine