"""
GENESIS Orchestrator - Bottleneck Detection and Real-time Performance Analysis
Advanced algorithms for identifying performance bottlenecks and system constraints
"""

import os
import json
import time
import threading
import queue
from typing import Dict, Any, List, Optional, Tuple, Set
from dataclasses import dataclass, asdict, field
from datetime import datetime, timedelta
from collections import defaultdict, deque
from enum import Enum
import logging
import asyncio
from concurrent.futures import ThreadPoolExecutor

import numpy as np
import psutil
from scipy import stats
from sklearn.cluster import DBSCAN
from sklearn.preprocessing import StandardScaler
from sklearn.decomposition import PCA

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

class BottleneckType(Enum):
    """Types of performance bottlenecks"""
    CPU_BOUND = "cpu_bound"
    MEMORY_BOUND = "memory_bound"
    IO_BOUND = "io_bound"
    NETWORK_BOUND = "network_bound"
    DATABASE_BOUND = "database_bound"
    LOCK_CONTENTION = "lock_contention"
    QUEUE_BACKLOG = "queue_backlog"
    RESOURCE_EXHAUSTION = "resource_exhaustion"
    ALGORITHM_INEFFICIENCY = "algorithm_inefficiency"
    EXTERNAL_DEPENDENCY = "external_dependency"

class BottleneckSeverity(Enum):
    """Bottleneck severity levels"""
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"
    CRITICAL = "critical"

@dataclass
class PerformanceMetric:
    """Single performance metric measurement"""
    timestamp: float
    metric_name: str
    value: float
    context: Dict[str, Any] = field(default_factory=dict)
    tags: Dict[str, str] = field(default_factory=dict)

@dataclass
class BottleneckEvent:
    """Detected bottleneck event"""
    event_id: str
    bottleneck_type: BottleneckType
    severity: BottleneckSeverity
    detected_at: float
    duration_seconds: Optional[float]
    affected_components: List[str]
    root_cause_confidence: float
    impact_score: float
    description: str
    evidence: Dict[str, Any]
    recommendations: List[str] = field(default_factory=list)
    resolved: bool = False
    resolved_at: Optional[float] = None

@dataclass
class SystemState:
    """Current system performance state"""
    timestamp: float
    cpu_utilization: float
    memory_usage_percent: float
    memory_available_gb: float
    disk_io_read_mb_s: float
    disk_io_write_mb_s: float
    network_bytes_sent_s: float
    network_bytes_recv_s: float
    active_threads: int
    open_file_descriptors: int
    load_average_1m: float
    context_switches_s: float
    queue_depths: Dict[str, int] = field(default_factory=dict)
    response_times: Dict[str, float] = field(default_factory=dict)

@dataclass
class BottleneckPattern:
    """Learned bottleneck pattern for prediction"""
    pattern_id: str
    bottleneck_type: BottleneckType
    precondition_metrics: Dict[str, Tuple[float, float]]  # metric -> (min, max)
    confidence_threshold: float
    historical_occurrences: int
    last_seen: float
    prediction_accuracy: float

class RealTimeAnalyzer:
    """Real-time performance analysis engine"""
    
    def __init__(self, window_size: int = 300):  # 5-minute rolling window
        self.window_size = window_size
        self.metrics_buffer = defaultdict(lambda: deque(maxlen=window_size))
        self.analysis_lock = threading.Lock()
        
    def add_metric(self, metric: PerformanceMetric):
        """Add metric to real-time analysis buffer"""
        with self.analysis_lock:
            self.metrics_buffer[metric.metric_name].append(metric)
    
    def get_trend_analysis(self, metric_name: str, 
                          window_minutes: int = 5) -> Dict[str, Any]:
        """Analyze trend for specific metric"""
        with self.analysis_lock:
            metrics = list(self.metrics_buffer[metric_name])
        
        if len(metrics) < 10:
            return {"error": "Insufficient data for trend analysis"}
        
        # Filter by time window
        cutoff_time = time.time() - (window_minutes * 60)
        recent_metrics = [m for m in metrics if m.timestamp > cutoff_time]
        
        if len(recent_metrics) < 5:
            return {"error": "Insufficient recent data"}
        
        values = [m.value for m in recent_metrics]
        timestamps = [m.timestamp for m in recent_metrics]
        
        # Linear regression for trend
        slope, intercept, r_value, p_value, std_err = stats.linregress(timestamps, values)
        
        # Statistical analysis
        mean_val = np.mean(values)
        std_val = np.std(values)
        cv = std_val / mean_val if mean_val != 0 else 0  # Coefficient of variation
        
        # Trend classification
        if abs(slope) < std_err:
            trend_direction = "stable"
        elif slope > 0:
            trend_direction = "increasing"
        else:
            trend_direction = "decreasing"
        
        # Anomaly detection
        z_scores = np.abs(stats.zscore(values))
        anomalies = sum(z > 2.5 for z in z_scores)  # Values > 2.5 std devs
        
        return {
            "metric_name": metric_name,
            "sample_count": len(recent_metrics),
            "current_value": values[-1],
            "mean": mean_val,
            "std_dev": std_val,
            "coefficient_of_variation": cv,
            "trend_direction": trend_direction,
            "trend_slope": slope,
            "trend_confidence": abs(r_value),
            "anomaly_count": anomalies,
            "anomaly_rate": anomalies / len(values),
            "min_value": min(values),
            "max_value": max(values),
            "percentile_95": np.percentile(values, 95),
            "percentile_99": np.percentile(values, 99)
        }
    
    def detect_spikes(self, metric_name: str, 
                     threshold_std_devs: float = 3.0) -> List[Dict[str, Any]]:
        """Detect sudden spikes in metrics"""
        with self.analysis_lock:
            metrics = list(self.metrics_buffer[metric_name])
        
        if len(metrics) < 20:
            return []
        
        values = [m.value for m in metrics]
        timestamps = [m.timestamp for m in metrics]
        
        # Calculate rolling statistics
        window = min(20, len(values) // 4)
        spikes = []
        
        for i in range(window, len(values)):
            # Rolling window baseline
            baseline_values = values[i-window:i]
            baseline_mean = np.mean(baseline_values)
            baseline_std = np.std(baseline_values)
            
            current_value = values[i]
            
            if baseline_std > 0:
                z_score = (current_value - baseline_mean) / baseline_std
                
                if abs(z_score) > threshold_std_devs:
                    spike = {
                        "timestamp": timestamps[i],
                        "value": current_value,
                        "baseline_mean": baseline_mean,
                        "z_score": z_score,
                        "severity": "critical" if abs(z_score) > 5 else "high",
                        "deviation_percent": ((current_value - baseline_mean) / baseline_mean) * 100
                    }
                    spikes.append(spike)
        
        return spikes
    
    def calculate_correlation_matrix(self, metrics_list: List[str]) -> Dict[str, Dict[str, float]]:
        """Calculate correlation between metrics"""
        with self.analysis_lock:
            # Align metrics by timestamp
            common_timestamps = set()
            for metric_name in metrics_list:
                if metric_name in self.metrics_buffer:
                    timestamps = {m.timestamp for m in self.metrics_buffer[metric_name]}
                    if not common_timestamps:
                        common_timestamps = timestamps
                    else:
                        common_timestamps &= timestamps
            
            if len(common_timestamps) < 10:
                return {}
            
            # Build correlation matrix
            metric_data = {}
            for timestamp in sorted(common_timestamps):
                for metric_name in metrics_list:
                    if metric_name not in metric_data:
                        metric_data[metric_name] = []
                    
                    # Find metric value at this timestamp
                    for metric in self.metrics_buffer[metric_name]:
                        if abs(metric.timestamp - timestamp) < 1.0:  # Within 1 second
                            metric_data[metric_name].append(metric.value)
                            break
            
            # Calculate correlations
            correlation_matrix = {}
            for metric1 in metrics_list:
                correlation_matrix[metric1] = {}
                for metric2 in metrics_list:
                    if metric1 in metric_data and metric2 in metric_data:
                        if len(metric_data[metric1]) == len(metric_data[metric2]):
                            corr_coef = np.corrcoef(metric_data[metric1], metric_data[metric2])[0, 1]
                            correlation_matrix[metric1][metric2] = float(corr_coef) if not np.isnan(corr_coef) else 0.0
                        else:
                            correlation_matrix[metric1][metric2] = 0.0
                    else:
                        correlation_matrix[metric1][metric2] = 0.0
            
            return correlation_matrix

class BottleneckDetector:
    """Advanced bottleneck detection system"""
    
    def __init__(self, detection_interval: int = 30):
        self.detection_interval = detection_interval
        self.system_monitor = SystemMonitor()
        self.analyzer = RealTimeAnalyzer()
        self.detected_bottlenecks: List[BottleneckEvent] = []
        self.learned_patterns: List[BottleneckPattern] = []
        
        # Detection thresholds
        self.thresholds = {
            "cpu_critical": 90.0,
            "cpu_high": 75.0,
            "memory_critical": 95.0,
            "memory_high": 85.0,
            "disk_io_critical": 100.0,  # MB/s
            "network_critical": 50.0,   # MB/s
            "response_time_critical": 5000.0,  # ms
            "queue_depth_critical": 1000,
            "error_rate_critical": 0.05  # 5%
        }
        
        # Detection algorithms
        self.detectors = {
            BottleneckType.CPU_BOUND: self._detect_cpu_bottleneck,
            BottleneckType.MEMORY_BOUND: self._detect_memory_bottleneck,
            BottleneckType.IO_BOUND: self._detect_io_bottleneck,
            BottleneckType.NETWORK_BOUND: self._detect_network_bottleneck,
            BottleneckType.QUEUE_BACKLOG: self._detect_queue_bottleneck,
            BottleneckType.LOCK_CONTENTION: self._detect_lock_contention,
            BottleneckType.ALGORITHM_INEFFICIENCY: self._detect_algorithm_inefficiency,
            BottleneckType.RESOURCE_EXHAUSTION: self._detect_resource_exhaustion
        }
        
        # Start monitoring
        self.monitoring_enabled = True
        self.monitor_thread = threading.Thread(target=self._monitoring_loop, daemon=True)
        self.monitor_thread.start()
        
        logger.info("Bottleneck detector initialized")
    
    def _monitoring_loop(self):
        """Main monitoring loop"""
        while self.monitoring_enabled:
            try:
                # Collect system state
                system_state = self.system_monitor.get_system_state()
                
                # Add metrics to analyzer
                self._add_system_metrics(system_state)
                
                # Run bottleneck detection
                detected = self._run_detection_algorithms(system_state)
                
                # Update learned patterns
                self._update_patterns(detected)
                
                # Log significant bottlenecks
                critical_bottlenecks = [b for b in detected if b.severity == BottleneckSeverity.CRITICAL]
                if critical_bottlenecks:
                    logger.critical(f"Critical bottlenecks detected: {[b.bottleneck_type.value for b in critical_bottlenecks]}")
                
                time.sleep(self.detection_interval)
                
            except Exception as e:
                logger.error(f"Monitoring loop error: {e}")
                time.sleep(60)  # Back off on error
    
    def _add_system_metrics(self, system_state: SystemState):
        """Add system state metrics to analyzer"""
        timestamp = system_state.timestamp
        
        metrics = [
            PerformanceMetric(timestamp, "cpu_utilization", system_state.cpu_utilization),
            PerformanceMetric(timestamp, "memory_usage_percent", system_state.memory_usage_percent),
            PerformanceMetric(timestamp, "disk_io_read_mb_s", system_state.disk_io_read_mb_s),
            PerformanceMetric(timestamp, "disk_io_write_mb_s", system_state.disk_io_write_mb_s),
            PerformanceMetric(timestamp, "network_bytes_sent_s", system_state.network_bytes_sent_s),
            PerformanceMetric(timestamp, "network_bytes_recv_s", system_state.network_bytes_recv_s),
            PerformanceMetric(timestamp, "active_threads", system_state.active_threads),
            PerformanceMetric(timestamp, "load_average_1m", system_state.load_average_1m),
            PerformanceMetric(timestamp, "context_switches_s", system_state.context_switches_s)
        ]
        
        for metric in metrics:
            self.analyzer.add_metric(metric)
    
    def _run_detection_algorithms(self, system_state: SystemState) -> List[BottleneckEvent]:
        """Run all bottleneck detection algorithms"""
        detected_bottlenecks = []
        
        for bottleneck_type, detector_func in self.detectors.items():
            try:
                bottleneck = detector_func(system_state)
                if bottleneck:
                    detected_bottlenecks.append(bottleneck)
                    self.detected_bottlenecks.append(bottleneck)
            except Exception as e:
                logger.error(f"Detection algorithm {bottleneck_type.value} failed: {e}")
        
        return detected_bottlenecks
    
    def _detect_cpu_bottleneck(self, system_state: SystemState) -> Optional[BottleneckEvent]:
        """Detect CPU-bound bottlenecks"""
        cpu_util = system_state.cpu_utilization
        load_avg = system_state.load_average_1m
        context_switches = system_state.context_switches_s
        
        # High CPU utilization
        if cpu_util > self.thresholds["cpu_critical"]:
            severity = BottleneckSeverity.CRITICAL
        elif cpu_util > self.thresholds["cpu_high"]:
            severity = BottleneckSeverity.HIGH
        else:
            return None
        
        # Additional evidence
        evidence = {
            "cpu_utilization": cpu_util,
            "load_average": load_avg,
            "context_switches_per_second": context_switches,
            "cpu_to_core_ratio": load_avg / psutil.cpu_count()
        }
        
        # High load average relative to CPU count indicates CPU pressure
        impact_score = min(100, (cpu_util / 100) * 100)
        
        # Generate recommendations
        recommendations = [
            "Profile application to identify CPU-intensive operations",
            "Consider optimizing algorithms or adding caching",
            "Scale horizontally or upgrade CPU capacity",
            "Review thread pool configurations"
        ]
        
        if context_switches > 10000:  # High context switching
            recommendations.append("High context switching detected - review thread contention")
            evidence["high_context_switching"] = True
        
        return BottleneckEvent(
            event_id=f"cpu_bottleneck_{int(time.time())}",
            bottleneck_type=BottleneckType.CPU_BOUND,
            severity=severity,
            detected_at=time.time(),
            duration_seconds=None,
            affected_components=["cpu", "processing"],
            root_cause_confidence=0.8,
            impact_score=impact_score,
            description=f"CPU utilization at {cpu_util:.1f}%",
            evidence=evidence,
            recommendations=recommendations
        )
    
    def _detect_memory_bottleneck(self, system_state: SystemState) -> Optional[BottleneckEvent]:
        """Detect memory-bound bottlenecks"""
        memory_percent = system_state.memory_usage_percent
        available_gb = system_state.memory_available_gb
        
        if memory_percent > self.thresholds["memory_critical"]:
            severity = BottleneckSeverity.CRITICAL
        elif memory_percent > self.thresholds["memory_high"]:
            severity = BottleneckSeverity.HIGH
        else:
            return None
        
        # Check for memory pressure indicators
        evidence = {
            "memory_usage_percent": memory_percent,
            "available_memory_gb": available_gb,
        }
        
        # Analyze memory trend
        trend = self.analyzer.get_trend_analysis("memory_usage_percent", 10)
        if trend.get("trend_direction") == "increasing":
            evidence["memory_leak_suspected"] = True
            evidence["trend_slope"] = trend.get("trend_slope", 0)
        
        impact_score = min(100, memory_percent)
        
        recommendations = [
            "Analyze memory usage patterns for potential leaks",
            "Review object lifecycle and garbage collection",
            "Consider increasing available memory",
            "Optimize data structures and caching strategies"
        ]
        
        if evidence.get("memory_leak_suspected"):
            recommendations.insert(0, "Memory leak suspected - perform detailed memory profiling")
        
        return BottleneckEvent(
            event_id=f"memory_bottleneck_{int(time.time())}",
            bottleneck_type=BottleneckType.MEMORY_BOUND,
            severity=severity,
            detected_at=time.time(),
            duration_seconds=None,
            affected_components=["memory", "garbage_collection"],
            root_cause_confidence=0.7,
            impact_score=impact_score,
            description=f"Memory usage at {memory_percent:.1f}%",
            evidence=evidence,
            recommendations=recommendations
        )
    
    def _detect_io_bottleneck(self, system_state: SystemState) -> Optional[BottleneckEvent]:
        """Detect I/O-bound bottlenecks"""
        read_mb_s = system_state.disk_io_read_mb_s
        write_mb_s = system_state.disk_io_write_mb_s
        total_io = read_mb_s + write_mb_s
        
        if total_io > self.thresholds["disk_io_critical"]:
            severity = BottleneckSeverity.HIGH
        elif total_io > self.thresholds["disk_io_critical"] * 0.5:
            severity = BottleneckSeverity.MEDIUM
        else:
            return None
        
        evidence = {
            "disk_read_mb_s": read_mb_s,
            "disk_write_mb_s": write_mb_s,
            "total_io_mb_s": total_io,
            "read_write_ratio": read_mb_s / write_mb_s if write_mb_s > 0 else float('inf')
        }
        
        impact_score = min(100, (total_io / self.thresholds["disk_io_critical"]) * 100)
        
        recommendations = [
            "Optimize database queries and indexing",
            "Consider SSD upgrade or I/O optimization",
            "Implement caching to reduce disk access",
            "Review file I/O patterns and batch operations"
        ]
        
        if read_mb_s > write_mb_s * 3:
            recommendations.append("High read activity - consider read replicas or caching")
        elif write_mb_s > read_mb_s * 3:
            recommendations.append("High write activity - consider write optimization or batching")
        
        return BottleneckEvent(
            event_id=f"io_bottleneck_{int(time.time())}",
            bottleneck_type=BottleneckType.IO_BOUND,
            severity=severity,
            detected_at=time.time(),
            duration_seconds=None,
            affected_components=["disk", "database", "file_system"],
            root_cause_confidence=0.6,
            impact_score=impact_score,
            description=f"High I/O activity: {total_io:.1f} MB/s",
            evidence=evidence,
            recommendations=recommendations
        )
    
    def _detect_network_bottleneck(self, system_state: SystemState) -> Optional[BottleneckEvent]:
        """Detect network-bound bottlenecks"""
        sent_mb_s = system_state.network_bytes_sent_s / (1024 * 1024)
        recv_mb_s = system_state.network_bytes_recv_s / (1024 * 1024)
        total_network = sent_mb_s + recv_mb_s
        
        if total_network > self.thresholds["network_critical"]:
            severity = BottleneckSeverity.HIGH
        elif total_network > self.thresholds["network_critical"] * 0.5:
            severity = BottleneckSeverity.MEDIUM
        else:
            return None
        
        evidence = {
            "network_sent_mb_s": sent_mb_s,
            "network_recv_mb_s": recv_mb_s,
            "total_network_mb_s": total_network,
            "send_recv_ratio": sent_mb_s / recv_mb_s if recv_mb_s > 0 else float('inf')
        }
        
        impact_score = min(100, (total_network / self.thresholds["network_critical"]) * 100)
        
        recommendations = [
            "Optimize network communication patterns",
            "Consider request/response compression",
            "Review network topology and bandwidth capacity",
            "Implement connection pooling and keep-alive"
        ]
        
        return BottleneckEvent(
            event_id=f"network_bottleneck_{int(time.time())}",
            bottleneck_type=BottleneckType.NETWORK_BOUND,
            severity=severity,
            detected_at=time.time(),
            duration_seconds=None,
            affected_components=["network", "external_apis"],
            root_cause_confidence=0.5,
            impact_score=impact_score,
            description=f"High network activity: {total_network:.1f} MB/s",
            evidence=evidence,
            recommendations=recommendations
        )
    
    def _detect_queue_bottleneck(self, system_state: SystemState) -> Optional[BottleneckEvent]:
        """Detect queue backlog bottlenecks"""
        total_queue_depth = sum(system_state.queue_depths.values())
        
        if total_queue_depth > self.thresholds["queue_depth_critical"]:
            severity = BottleneckSeverity.CRITICAL
        elif total_queue_depth > self.thresholds["queue_depth_critical"] * 0.5:
            severity = BottleneckSeverity.HIGH
        else:
            return None
        
        # Find problematic queues
        problem_queues = {
            name: depth for name, depth in system_state.queue_depths.items()
            if depth > 100
        }
        
        evidence = {
            "total_queue_depth": total_queue_depth,
            "problem_queues": problem_queues,
            "queue_count": len(system_state.queue_depths)
        }
        
        impact_score = min(100, (total_queue_depth / self.thresholds["queue_depth_critical"]) * 100)
        
        recommendations = [
            "Scale queue processing workers",
            "Optimize queue processing algorithms",
            "Consider queue partitioning or load balancing",
            "Review queue consumer performance"
        ]
        
        return BottleneckEvent(
            event_id=f"queue_bottleneck_{int(time.time())}",
            bottleneck_type=BottleneckType.QUEUE_BACKLOG,
            severity=severity,
            detected_at=time.time(),
            duration_seconds=None,
            affected_components=["queues", "message_processing"],
            root_cause_confidence=0.8,
            impact_score=impact_score,
            description=f"Queue backlog: {total_queue_depth} items",
            evidence=evidence,
            recommendations=recommendations
        )
    
    def _detect_lock_contention(self, system_state: SystemState) -> Optional[BottleneckEvent]:
        """Detect lock contention bottlenecks"""
        # Use context switches as a proxy for lock contention
        context_switches = system_state.context_switches_s
        thread_count = system_state.active_threads
        
        # High context switching relative to thread count suggests contention
        switches_per_thread = context_switches / thread_count if thread_count > 0 else 0
        
        if switches_per_thread > 50:  # Threshold for high contention
            severity = BottleneckSeverity.HIGH if switches_per_thread > 100 else BottleneckSeverity.MEDIUM
        else:
            return None
        
        evidence = {
            "context_switches_per_second": context_switches,
            "active_threads": thread_count,
            "switches_per_thread": switches_per_thread
        }
        
        impact_score = min(100, (switches_per_thread / 100) * 100)
        
        recommendations = [
            "Profile application for lock contention hotspots",
            "Consider reducing critical section size",
            "Review synchronization patterns and algorithms",
            "Consider lock-free data structures where appropriate"
        ]
        
        return BottleneckEvent(
            event_id=f"lock_contention_{int(time.time())}",
            bottleneck_type=BottleneckType.LOCK_CONTENTION,
            severity=severity,
            detected_at=time.time(),
            duration_seconds=None,
            affected_components=["threading", "synchronization"],
            root_cause_confidence=0.6,
            impact_score=impact_score,
            description=f"High context switching: {switches_per_thread:.1f} per thread",
            evidence=evidence,
            recommendations=recommendations
        )
    
    def _detect_algorithm_inefficiency(self, system_state: SystemState) -> Optional[BottleneckEvent]:
        """Detect algorithmic inefficiencies"""
        # Look for patterns indicating inefficient algorithms
        cpu_util = system_state.cpu_utilization
        
        # Get response time trends if available
        response_times = system_state.response_times
        if not response_times:
            return None
        
        avg_response_time = np.mean(list(response_times.values()))
        
        # High CPU with high response times suggests algorithmic issues
        if cpu_util > 70 and avg_response_time > 2000:  # 2 seconds
            severity = BottleneckSeverity.HIGH
        elif cpu_util > 50 and avg_response_time > 5000:  # 5 seconds
            severity = BottleneckSeverity.MEDIUM
        else:
            return None
        
        evidence = {
            "cpu_utilization": cpu_util,
            "avg_response_time_ms": avg_response_time,
            "response_times": response_times,
            "cpu_to_performance_ratio": cpu_util / (1000 / avg_response_time)  # Higher is worse
        }
        
        impact_score = min(100, (avg_response_time / 1000) * 20)  # 5s = 100 points
        
        recommendations = [
            "Profile application for algorithmic hotspots",
            "Review computational complexity of key operations",
            "Consider caching frequently computed results",
            "Optimize database queries and data access patterns"
        ]
        
        return BottleneckEvent(
            event_id=f"algorithm_inefficiency_{int(time.time())}",
            bottleneck_type=BottleneckType.ALGORITHM_INEFFICIENCY,
            severity=severity,
            detected_at=time.time(),
            duration_seconds=None,
            affected_components=["algorithms", "performance"],
            root_cause_confidence=0.4,
            impact_score=impact_score,
            description=f"Poor CPU-to-performance ratio",
            evidence=evidence,
            recommendations=recommendations
        )
    
    def _detect_resource_exhaustion(self, system_state: SystemState) -> Optional[BottleneckEvent]:
        """Detect resource exhaustion bottlenecks"""
        issues = []
        
        # Check file descriptor exhaustion
        fd_count = system_state.open_file_descriptors
        if fd_count > 900:  # Approaching typical limit of 1024
            issues.append(f"High file descriptor usage: {fd_count}")
        
        # Check thread exhaustion
        thread_count = system_state.active_threads
        if thread_count > 500:  # High thread count
            issues.append(f"High thread count: {thread_count}")
        
        if not issues:
            return None
        
        severity = BottleneckSeverity.HIGH if len(issues) > 1 else BottleneckSeverity.MEDIUM
        
        evidence = {
            "open_file_descriptors": fd_count,
            "active_threads": thread_count,
            "resource_issues": issues
        }
        
        impact_score = min(100, ((fd_count / 1024) + (thread_count / 1000)) * 50)
        
        recommendations = [
            "Review resource cleanup and lifecycle management",
            "Check for resource leaks in connections and files",
            "Consider connection pooling and resource limits",
            "Monitor resource usage trends"
        ]
        
        return BottleneckEvent(
            event_id=f"resource_exhaustion_{int(time.time())}",
            bottleneck_type=BottleneckType.RESOURCE_EXHAUSTION,
            severity=severity,
            detected_at=time.time(),
            duration_seconds=None,
            affected_components=["resources", "file_descriptors", "threads"],
            root_cause_confidence=0.7,
            impact_score=impact_score,
            description=f"Resource exhaustion: {', '.join(issues)}",
            evidence=evidence,
            recommendations=recommendations
        )
    
    def _update_patterns(self, detected_bottlenecks: List[BottleneckEvent]):
        """Update learned patterns from detected bottlenecks"""
        # This would implement pattern learning for bottleneck prediction
        # For now, just log the patterns
        for bottleneck in detected_bottlenecks:
            logger.debug(f"Pattern logged: {bottleneck.bottleneck_type.value} - {bottleneck.evidence}")
    
    def get_current_bottlenecks(self, active_only: bool = True) -> List[BottleneckEvent]:
        """Get current active bottlenecks"""
        if active_only:
            return [b for b in self.detected_bottlenecks if not b.resolved]
        return self.detected_bottlenecks.copy()
    
    def get_bottleneck_summary(self, hours: int = 24) -> Dict[str, Any]:
        """Get summary of bottlenecks in the last N hours"""
        cutoff_time = time.time() - (hours * 3600)
        recent_bottlenecks = [
            b for b in self.detected_bottlenecks
            if b.detected_at > cutoff_time
        ]
        
        if not recent_bottlenecks:
            return {"message": "No bottlenecks detected in the specified time period"}
        
        # Aggregate by type
        by_type = defaultdict(list)
        for bottleneck in recent_bottlenecks:
            by_type[bottleneck.bottleneck_type.value].append(bottleneck)
        
        # Aggregate by severity
        by_severity = defaultdict(int)
        for bottleneck in recent_bottlenecks:
            by_severity[bottleneck.severity.value] += 1
        
        return {
            "time_window_hours": hours,
            "total_bottlenecks": len(recent_bottlenecks),
            "active_bottlenecks": len([b for b in recent_bottlenecks if not b.resolved]),
            "by_type": {k: len(v) for k, v in by_type.items()},
            "by_severity": dict(by_severity),
            "most_common_type": max(by_type.keys(), key=lambda k: len(by_type[k])) if by_type else None,
            "avg_impact_score": np.mean([b.impact_score for b in recent_bottlenecks]),
            "critical_bottlenecks": [
                {
                    "type": b.bottleneck_type.value,
                    "description": b.description,
                    "detected_at": b.detected_at
                }
                for b in recent_bottlenecks
                if b.severity == BottleneckSeverity.CRITICAL
            ]
        }
    
    def shutdown(self):
        """Shutdown bottleneck detector"""
        self.monitoring_enabled = False
        if self.monitor_thread.is_alive():
            self.monitor_thread.join(timeout=5.0)
        logger.info("Bottleneck detector shutdown")

class SystemMonitor:
    """System performance monitoring"""
    
    def __init__(self):
        self.last_network_counters = None
        self.last_context_switches = None
        self.last_check_time = None
    
    def get_system_state(self) -> SystemState:
        """Get current system performance state"""
        current_time = time.time()
        
        # CPU and memory
        cpu_percent = psutil.cpu_percent(interval=0.1)
        memory = psutil.virtual_memory()
        
        # Disk I/O
        disk_io = psutil.disk_io_counters()
        disk_read_mb_s = 0
        disk_write_mb_s = 0
        
        if hasattr(self, 'last_disk_io') and self.last_check_time:
            time_delta = current_time - self.last_check_time
            if time_delta > 0:
                read_delta = disk_io.read_bytes - self.last_disk_io.read_bytes
                write_delta = disk_io.write_bytes - self.last_disk_io.write_bytes
                disk_read_mb_s = (read_delta / time_delta) / (1024 * 1024)
                disk_write_mb_s = (write_delta / time_delta) / (1024 * 1024)
        
        self.last_disk_io = disk_io
        
        # Network I/O
        net_io = psutil.net_io_counters()
        net_sent_s = 0
        net_recv_s = 0
        
        if self.last_network_counters and self.last_check_time:
            time_delta = current_time - self.last_check_time
            if time_delta > 0:
                sent_delta = net_io.bytes_sent - self.last_network_counters.bytes_sent
                recv_delta = net_io.bytes_recv - self.last_network_counters.bytes_recv
                net_sent_s = sent_delta / time_delta
                net_recv_s = recv_delta / time_delta
        
        self.last_network_counters = net_io
        
        # System load
        load_avg = os.getloadavg()[0] if hasattr(os, 'getloadavg') else 0.0
        
        # Context switches
        context_switches_s = 0
        try:
            current_switches = psutil.cpu_stats().ctx_switches
            if self.last_context_switches and self.last_check_time:
                time_delta = current_time - self.last_check_time
                if time_delta > 0:
                    switch_delta = current_switches - self.last_context_switches
                    context_switches_s = switch_delta / time_delta
            self.last_context_switches = current_switches
        except AttributeError:
            pass
        
        # Process information
        process = psutil.Process()
        thread_count = process.num_threads()
        
        try:
            open_files = len(process.open_files())
        except (psutil.AccessDenied, OSError):
            open_files = 0
        
        self.last_check_time = current_time
        
        return SystemState(
            timestamp=current_time,
            cpu_utilization=cpu_percent,
            memory_usage_percent=memory.percent,
            memory_available_gb=memory.available / (1024**3),
            disk_io_read_mb_s=disk_read_mb_s,
            disk_io_write_mb_s=disk_write_mb_s,
            network_bytes_sent_s=net_sent_s,
            network_bytes_recv_s=net_recv_s,
            active_threads=thread_count,
            open_file_descriptors=open_files,
            load_average_1m=load_avg,
            context_switches_s=context_switches_s,
            queue_depths={},  # Would be populated from application metrics
            response_times={}  # Would be populated from application metrics
        )

# Global bottleneck detector instance
bottleneck_detector = BottleneckDetector()

def get_bottleneck_detector() -> BottleneckDetector:
    """Get the global bottleneck detector instance"""
    return bottleneck_detector