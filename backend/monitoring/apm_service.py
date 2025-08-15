"""
GENESIS Orchestrator - Application Performance Monitoring (APM) Service
Comprehensive performance profiling and analysis infrastructure
"""

import os
import sys
import time
import json
import psutil
import threading
import tracemalloc
from typing import Dict, Any, List, Optional, Callable
from dataclasses import dataclass, asdict, field
from datetime import datetime, timedelta
from collections import defaultdict, deque
import logging
import asyncio
import resource
from contextlib import contextmanager
import functools

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

@dataclass
class PerformanceSnapshot:
    """Single point-in-time performance snapshot"""
    timestamp: float
    cpu_percent: float
    memory_rss_mb: float
    memory_vms_mb: float
    memory_percent: float
    disk_io_read_mb: float
    disk_io_write_mb: float
    network_sent_mb: float
    network_recv_mb: float
    open_files: int
    threads_count: int
    gc_collections: Dict[str, int] = field(default_factory=dict)
    
@dataclass
class FunctionProfile:
    """Performance profile for a specific function"""
    function_name: str
    module_name: str
    call_count: int = 0
    total_time: float = 0.0
    min_time: float = float('inf')
    max_time: float = 0.0
    avg_time: float = 0.0
    memory_peak_mb: float = 0.0
    memory_current_mb: float = 0.0
    cpu_time: float = 0.0
    errors: List[str] = field(default_factory=list)
    
    def update_timing(self, execution_time: float):
        """Update timing statistics"""
        self.call_count += 1
        self.total_time += execution_time
        self.min_time = min(self.min_time, execution_time)
        self.max_time = max(self.max_time, execution_time)
        self.avg_time = self.total_time / self.call_count
        
    def add_error(self, error_msg: str):
        """Add error to function profile"""
        self.errors.append({
            'timestamp': datetime.utcnow().isoformat(),
            'error': error_msg
        })

@dataclass
class RequestProfile:
    """Performance profile for HTTP request or operation"""
    request_id: str
    endpoint: str
    method: str
    start_time: float
    end_time: Optional[float] = None
    status_code: Optional[int] = None
    response_size_bytes: int = 0
    database_queries: int = 0
    database_time: float = 0.0
    cache_hits: int = 0
    cache_misses: int = 0
    external_calls: int = 0
    external_time: float = 0.0
    memory_peak_mb: float = 0.0
    cpu_time: float = 0.0
    errors: List[str] = field(default_factory=list)
    
    @property
    def duration_ms(self) -> float:
        """Calculate request duration in milliseconds"""
        if self.end_time:
            return (self.end_time - self.start_time) * 1000
        return 0.0
    
    @property
    def is_slow(self) -> bool:
        """Determine if request is considered slow (>2s)"""
        return self.duration_ms > 2000

class MemoryProfiler:
    """Advanced memory profiling capabilities"""
    
    def __init__(self):
        self.snapshots = []
        self.peak_memory = 0.0
        self.current_tracemalloc = False
        
    def start_tracing(self):
        """Start memory tracing"""
        if not self.current_tracemalloc:
            tracemalloc.start()
            self.current_tracemalloc = True
            logger.info("Memory tracing started")
            
    def stop_tracing(self):
        """Stop memory tracing"""
        if self.current_tracemalloc:
            tracemalloc.stop()
            self.current_tracemalloc = False
            logger.info("Memory tracing stopped")
            
    def take_snapshot(self) -> Dict[str, Any]:
        """Take memory snapshot"""
        if not self.current_tracemalloc:
            self.start_tracing()
            
        snapshot = tracemalloc.take_snapshot()
        top_stats = snapshot.statistics('lineno')
        
        memory_info = {
            'timestamp': time.time(),
            'total_size_mb': sum(stat.size for stat in top_stats) / 1024 / 1024,
            'top_allocations': [
                {
                    'filename': stat.traceback.format()[-1],
                    'size_mb': stat.size / 1024 / 1024,
                    'count': stat.count
                }
                for stat in top_stats[:10]
            ]
        }
        
        self.snapshots.append(memory_info)
        self.peak_memory = max(self.peak_memory, memory_info['total_size_mb'])
        
        return memory_info
    
    def detect_leaks(self) -> List[Dict[str, Any]]:
        """Detect potential memory leaks"""
        if len(self.snapshots) < 2:
            return []
            
        leaks = []
        recent = self.snapshots[-10:]  # Last 10 snapshots
        
        if len(recent) >= 3:
            # Check for consistent memory growth
            growth_trend = []
            for i in range(1, len(recent)):
                growth = recent[i]['total_size_mb'] - recent[i-1]['total_size_mb']
                growth_trend.append(growth)
            
            # If memory consistently grows
            if len([g for g in growth_trend if g > 0]) > len(growth_trend) * 0.7:
                leaks.append({
                    'type': 'consistent_growth',
                    'description': 'Memory usage shows consistent upward trend',
                    'growth_rate_mb_per_snapshot': sum(growth_trend) / len(growth_trend),
                    'total_growth_mb': recent[-1]['total_size_mb'] - recent[0]['total_size_mb']
                })
                
        return leaks

class CPUProfiler:
    """CPU performance profiling"""
    
    def __init__(self):
        self.cpu_samples = deque(maxlen=1000)
        self.process = psutil.Process()
        self.monitoring = False
        self.monitor_thread = None
        
    def start_monitoring(self, interval: float = 0.1):
        """Start CPU monitoring"""
        if not self.monitoring:
            self.monitoring = True
            self.monitor_thread = threading.Thread(
                target=self._monitor_cpu, 
                args=(interval,),
                daemon=True
            )
            self.monitor_thread.start()
            logger.info(f"CPU monitoring started with {interval}s interval")
            
    def stop_monitoring(self):
        """Stop CPU monitoring"""
        self.monitoring = False
        if self.monitor_thread:
            self.monitor_thread.join(timeout=1.0)
        logger.info("CPU monitoring stopped")
        
    def _monitor_cpu(self, interval: float):
        """Internal CPU monitoring loop"""
        while self.monitoring:
            try:
                cpu_percent = self.process.cpu_percent()
                cpu_times = self.process.cpu_times()
                
                sample = {
                    'timestamp': time.time(),
                    'cpu_percent': cpu_percent,
                    'user_time': cpu_times.user,
                    'system_time': cpu_times.system,
                    'cpu_count': psutil.cpu_count()
                }
                
                self.cpu_samples.append(sample)
                time.sleep(interval)
            except Exception as e:
                logger.error(f"CPU monitoring error: {e}")
                time.sleep(interval)
                
    def get_cpu_stats(self) -> Dict[str, Any]:
        """Get CPU statistics"""
        if not self.cpu_samples:
            return {}
            
        samples = list(self.cpu_samples)
        cpu_values = [s['cpu_percent'] for s in samples]
        
        return {
            'current_cpu_percent': samples[-1]['cpu_percent'],
            'avg_cpu_percent': sum(cpu_values) / len(cpu_values),
            'max_cpu_percent': max(cpu_values),
            'min_cpu_percent': min(cpu_values),
            'samples_count': len(samples),
            'high_cpu_periods': len([c for c in cpu_values if c > 80])
        }
        
    def detect_cpu_issues(self) -> List[Dict[str, Any]]:
        """Detect CPU performance issues"""
        issues = []
        stats = self.get_cpu_stats()
        
        if stats.get('avg_cpu_percent', 0) > 70:
            issues.append({
                'type': 'high_avg_cpu',
                'description': 'Average CPU usage is high',
                'avg_cpu': stats['avg_cpu_percent'],
                'severity': 'warning'
            })
            
        if stats.get('max_cpu_percent', 0) > 95:
            issues.append({
                'type': 'cpu_spikes',
                'description': 'CPU spikes detected',
                'max_cpu': stats['max_cpu_percent'],
                'severity': 'critical'
            })
            
        high_periods = stats.get('high_cpu_periods', 0)
        total_samples = stats.get('samples_count', 1)
        if high_periods / total_samples > 0.3:
            issues.append({
                'type': 'sustained_high_cpu',
                'description': 'Sustained high CPU usage periods',
                'high_cpu_ratio': high_periods / total_samples,
                'severity': 'warning'
            })
            
        return issues

class IOProfiler:
    """I/O performance profiling"""
    
    def __init__(self):
        self.io_samples = deque(maxlen=1000)
        self.process = psutil.Process()
        self.monitoring = False
        self.monitor_thread = None
        self.last_io_counters = None
        
    def start_monitoring(self, interval: float = 1.0):
        """Start I/O monitoring"""
        if not self.monitoring:
            self.monitoring = True
            self.last_io_counters = self.process.io_counters()
            self.monitor_thread = threading.Thread(
                target=self._monitor_io,
                args=(interval,),
                daemon=True
            )
            self.monitor_thread.start()
            logger.info(f"I/O monitoring started with {interval}s interval")
            
    def stop_monitoring(self):
        """Stop I/O monitoring"""
        self.monitoring = False
        if self.monitor_thread:
            self.monitor_thread.join(timeout=1.0)
        logger.info("I/O monitoring stopped")
        
    def _monitor_io(self, interval: float):
        """Internal I/O monitoring loop"""
        while self.monitoring:
            try:
                current_io = self.process.io_counters()
                
                if self.last_io_counters:
                    read_bytes = current_io.read_bytes - self.last_io_counters.read_bytes
                    write_bytes = current_io.write_bytes - self.last_io_counters.write_bytes
                    
                    sample = {
                        'timestamp': time.time(),
                        'read_mb_per_sec': (read_bytes / interval) / (1024 * 1024),
                        'write_mb_per_sec': (write_bytes / interval) / (1024 * 1024),
                        'read_count': current_io.read_count - self.last_io_counters.read_count,
                        'write_count': current_io.write_count - self.last_io_counters.write_count
                    }
                    
                    self.io_samples.append(sample)
                
                self.last_io_counters = current_io
                time.sleep(interval)
            except Exception as e:
                logger.error(f"I/O monitoring error: {e}")
                time.sleep(interval)
                
    def get_io_stats(self) -> Dict[str, Any]:
        """Get I/O statistics"""
        if not self.io_samples:
            return {}
            
        samples = list(self.io_samples)
        read_rates = [s['read_mb_per_sec'] for s in samples]
        write_rates = [s['write_mb_per_sec'] for s in samples]
        
        return {
            'avg_read_mb_per_sec': sum(read_rates) / len(read_rates),
            'avg_write_mb_per_sec': sum(write_rates) / len(write_rates),
            'max_read_mb_per_sec': max(read_rates),
            'max_write_mb_per_sec': max(write_rates),
            'total_read_operations': sum(s['read_count'] for s in samples),
            'total_write_operations': sum(s['write_count'] for s in samples)
        }

def profile_function(apm_service=None):
    """Decorator for function profiling"""
    def decorator(func: Callable) -> Callable:
        @functools.wraps(func)
        def wrapper(*args, **kwargs):
            start_time = time.time()
            start_cpu = time.process_time()
            
            # Start memory tracking if available
            memory_before = 0
            if apm_service and apm_service.memory_profiler.current_tracemalloc:
                memory_before = tracemalloc.get_traced_memory()[0]
            
            try:
                result = func(*args, **kwargs)
                
                # Calculate performance metrics
                execution_time = time.time() - start_time
                cpu_time = time.process_time() - start_cpu
                
                memory_after = 0
                if apm_service and apm_service.memory_profiler.current_tracemalloc:
                    memory_after = tracemalloc.get_traced_memory()[0]
                
                # Update function profile
                if apm_service:
                    func_name = f"{func.__module__}.{func.__name__}"
                    profile = apm_service.function_profiles.get(func_name, FunctionProfile(
                        function_name=func.__name__,
                        module_name=func.__module__
                    ))
                    
                    profile.update_timing(execution_time)
                    profile.cpu_time += cpu_time
                    if memory_after > memory_before:
                        profile.memory_current_mb = (memory_after - memory_before) / 1024 / 1024
                        profile.memory_peak_mb = max(profile.memory_peak_mb, profile.memory_current_mb)
                    
                    apm_service.function_profiles[func_name] = profile
                    logger.debug(f"Profiled {func_name}: {execution_time:.4f}s")
                
                return result
                
            except Exception as e:
                execution_time = time.time() - start_time
                
                if apm_service:
                    func_name = f"{func.__module__}.{func.__name__}"
                    profile = apm_service.function_profiles.get(func_name, FunctionProfile(
                        function_name=func.__name__,
                        module_name=func.__module__
                    ))
                    profile.add_error(str(e))
                    profile.update_timing(execution_time)
                    apm_service.function_profiles[func_name] = profile
                
                raise
                
        return wrapper
    return decorator

class APMService:
    """Main Application Performance Monitoring service"""
    
    def __init__(self, output_dir: str = "orchestrator_runs"):
        self.output_dir = output_dir
        self.memory_profiler = MemoryProfiler()
        self.cpu_profiler = CPUProfiler()
        self.io_profiler = IOProfiler()
        self.function_profiles: Dict[str, FunctionProfile] = {}
        self.request_profiles: Dict[str, RequestProfile] = {}
        self.performance_snapshots: List[PerformanceSnapshot] = []
        self.alerts: List[Dict[str, Any]] = []
        self.monitoring_active = False
        
    def start_monitoring(self):
        """Start comprehensive performance monitoring"""
        if not self.monitoring_active:
            self.monitoring_active = True
            self.memory_profiler.start_tracing()
            self.cpu_profiler.start_monitoring(interval=0.5)
            self.io_profiler.start_monitoring(interval=1.0)
            logger.info("APM Service monitoring started")
            
    def stop_monitoring(self):
        """Stop all performance monitoring"""
        if self.monitoring_active:
            self.monitoring_active = False
            self.memory_profiler.stop_tracing()
            self.cpu_profiler.stop_monitoring()
            self.io_profiler.stop_monitoring()
            logger.info("APM Service monitoring stopped")
            
    def take_performance_snapshot(self) -> PerformanceSnapshot:
        """Take comprehensive system performance snapshot"""
        process = psutil.Process()
        memory_info = process.memory_info()
        io_counters = process.io_counters()
        
        try:
            net_io = psutil.net_io_counters()
            network_sent = net_io.bytes_sent / 1024 / 1024
            network_recv = net_io.bytes_recv / 1024 / 1024
        except:
            network_sent = network_recv = 0.0
            
        snapshot = PerformanceSnapshot(
            timestamp=time.time(),
            cpu_percent=process.cpu_percent(),
            memory_rss_mb=memory_info.rss / 1024 / 1024,
            memory_vms_mb=memory_info.vms / 1024 / 1024,
            memory_percent=process.memory_percent(),
            disk_io_read_mb=io_counters.read_bytes / 1024 / 1024,
            disk_io_write_mb=io_counters.write_bytes / 1024 / 1024,
            network_sent_mb=network_sent,
            network_recv_mb=network_recv,
            open_files=len(process.open_files()),
            threads_count=process.num_threads()
        )
        
        self.performance_snapshots.append(snapshot)
        
        # Keep only last 1000 snapshots
        if len(self.performance_snapshots) > 1000:
            self.performance_snapshots = self.performance_snapshots[-1000:]
            
        return snapshot
        
    @contextmanager
    def profile_request(self, request_id: str, endpoint: str, method: str = "GET"):
        """Context manager for request profiling"""
        profile = RequestProfile(
            request_id=request_id,
            endpoint=endpoint,
            method=method,
            start_time=time.time()
        )
        
        self.request_profiles[request_id] = profile
        
        try:
            yield profile
        except Exception as e:
            profile.errors.append(str(e))
            raise
        finally:
            profile.end_time = time.time()
            
    def analyze_performance_trends(self) -> Dict[str, Any]:
        """Analyze performance trends from snapshots"""
        if len(self.performance_snapshots) < 10:
            return {"error": "Insufficient data for trend analysis"}
            
        recent_snapshots = self.performance_snapshots[-100:]  # Last 100 snapshots
        
        cpu_values = [s.cpu_percent for s in recent_snapshots]
        memory_values = [s.memory_rss_mb for s in recent_snapshots]
        
        trends = {
            'cpu_trend': {
                'current': cpu_values[-1],
                'average': sum(cpu_values) / len(cpu_values),
                'trend_direction': 'increasing' if cpu_values[-1] > sum(cpu_values[:-10]) / 10 else 'stable'
            },
            'memory_trend': {
                'current_mb': memory_values[-1],
                'average_mb': sum(memory_values) / len(memory_values),
                'trend_direction': 'increasing' if memory_values[-1] > sum(memory_values[:-10]) / 10 else 'stable',
                'peak_mb': max(memory_values)
            },
            'performance_score': self._calculate_performance_score(recent_snapshots)
        }
        
        return trends
        
    def _calculate_performance_score(self, snapshots: List[PerformanceSnapshot]) -> float:
        """Calculate overall performance score (0-100)"""
        if not snapshots:
            return 0.0
            
        # Scoring based on multiple factors
        cpu_scores = []
        memory_scores = []
        
        for snapshot in snapshots:
            # CPU score (lower usage = higher score)
            cpu_score = max(0, 100 - snapshot.cpu_percent)
            cpu_scores.append(cpu_score)
            
            # Memory score (reasonable memory usage)
            memory_score = max(0, 100 - min(snapshot.memory_percent, 100))
            memory_scores.append(memory_score)
            
        avg_cpu_score = sum(cpu_scores) / len(cpu_scores)
        avg_memory_score = sum(memory_scores) / len(memory_scores)
        
        # Weighted performance score
        performance_score = (avg_cpu_score * 0.4 + avg_memory_score * 0.4 + 
                           (100 - len([s for s in snapshots if s.open_files > 100])) * 0.2)
        
        return min(100.0, max(0.0, performance_score))
        
    def detect_performance_issues(self) -> List[Dict[str, Any]]:
        """Detect performance issues across all profilers"""
        issues = []
        
        # CPU issues
        cpu_issues = self.cpu_profiler.detect_cpu_issues()
        issues.extend([{**issue, 'category': 'cpu'} for issue in cpu_issues])
        
        # Memory issues
        memory_leaks = self.memory_profiler.detect_leaks()
        issues.extend([{**leak, 'category': 'memory'} for leak in memory_leaks])
        
        # Function performance issues
        for func_name, profile in self.function_profiles.items():
            if profile.avg_time > 1.0:  # Slow functions > 1s average
                issues.append({
                    'type': 'slow_function',
                    'category': 'function',
                    'description': f'Function {func_name} is slow',
                    'function': func_name,
                    'avg_time': profile.avg_time,
                    'call_count': profile.call_count,
                    'severity': 'warning' if profile.avg_time < 5.0 else 'critical'
                })
                
        # Request performance issues
        slow_requests = [r for r in self.request_profiles.values() if r.is_slow]
        if slow_requests:
            issues.append({
                'type': 'slow_requests',
                'category': 'requests',
                'description': f'{len(slow_requests)} slow requests detected',
                'count': len(slow_requests),
                'avg_duration': sum(r.duration_ms for r in slow_requests) / len(slow_requests),
                'severity': 'warning'
            })
            
        return issues
        
    def get_performance_report(self) -> Dict[str, Any]:
        """Generate comprehensive performance report"""
        report = {
            'timestamp': datetime.utcnow().isoformat(),
            'monitoring_duration_hours': len(self.performance_snapshots) * 0.5 / 3600,  # Assuming 0.5s intervals
            'system_performance': self.analyze_performance_trends(),
            'cpu_stats': self.cpu_profiler.get_cpu_stats(),
            'io_stats': self.io_profiler.get_io_stats(),
            'function_profiles': {
                name: asdict(profile) for name, profile in 
                sorted(self.function_profiles.items(), 
                       key=lambda x: x[1].total_time, reverse=True)[:20]  # Top 20 by total time
            },
            'slow_requests': [
                asdict(profile) for profile in 
                sorted([r for r in self.request_profiles.values() if r.is_slow],
                       key=lambda x: x.duration_ms, reverse=True)[:10]  # Top 10 slowest
            ],
            'performance_issues': self.detect_performance_issues(),
            'recommendations': self._generate_recommendations()
        }
        
        return report
        
    def _generate_recommendations(self) -> List[str]:
        """Generate performance optimization recommendations"""
        recommendations = []
        issues = self.detect_performance_issues()
        
        cpu_issues = [i for i in issues if i.get('category') == 'cpu']
        memory_issues = [i for i in issues if i.get('category') == 'memory']
        function_issues = [i for i in issues if i.get('category') == 'function']
        
        if cpu_issues:
            recommendations.append("Consider optimizing CPU-intensive operations or scaling horizontally")
            
        if memory_issues:
            recommendations.append("Investigate memory leaks and optimize memory usage patterns")
            
        if function_issues:
            recommendations.append("Profile and optimize slow functions, consider caching or algorithm improvements")
            
        if len(self.request_profiles) > 0:
            slow_ratio = len([r for r in self.request_profiles.values() if r.is_slow]) / len(self.request_profiles)
            if slow_ratio > 0.1:
                recommendations.append("High percentage of slow requests - investigate database queries and external API calls")
                
        if not recommendations:
            recommendations.append("Performance is within acceptable parameters")
            
        return recommendations
        
    def save_report(self, run_id: str):
        """Save performance report to file"""
        report = self.get_performance_report()
        
        run_dir = os.path.join(self.output_dir, run_id)
        os.makedirs(run_dir, exist_ok=True)
        
        report_file = os.path.join(run_dir, "performance_report.json")
        with open(report_file, "w") as f:
            json.dump(report, f, indent=2, default=str)
            
        logger.info(f"Performance report saved to {report_file}")

# Global APM service instance
apm_service = APMService()

def get_apm_service() -> APMService:
    """Get the global APM service instance"""
    return apm_service