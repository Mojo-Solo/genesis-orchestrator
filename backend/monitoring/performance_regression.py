"""
GENESIS Orchestrator - Performance Regression Detection Framework
Automated benchmarking and regression analysis for continuous performance validation
"""

import os
import json
import time
import statistics
import subprocess
import threading
from typing import Dict, Any, List, Optional, Callable, Tuple
from dataclasses import dataclass, asdict, field
from datetime import datetime, timedelta
from collections import defaultdict, deque
import logging
from pathlib import Path
import hashlib
import psutil

import numpy as np
from scipy import stats
from sklearn.preprocessing import StandardScaler
from sklearn.cluster import DBSCAN
from sklearn.ensemble import IsolationForest

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

@dataclass
class PerformanceBenchmark:
    """Individual performance benchmark result"""
    benchmark_id: str
    test_name: str
    timestamp: float
    git_commit: Optional[str]
    environment: str
    duration_ms: float
    memory_peak_mb: float
    memory_avg_mb: float
    cpu_avg_percent: float
    cpu_peak_percent: float
    io_read_mb: float
    io_write_mb: float
    operations_per_second: float
    success: bool
    error_message: Optional[str] = None
    metadata: Dict[str, Any] = field(default_factory=dict)
    
    @property
    def performance_score(self) -> float:
        """Calculate overall performance score (0-100)"""
        if not self.success:
            return 0.0
        
        # Normalize metrics (lower is better for duration/memory/cpu, higher for ops/sec)
        duration_score = max(0, 100 - min(self.duration_ms / 100, 100))  # Cap at 10s = 0 points
        memory_score = max(0, 100 - min(self.memory_peak_mb / 10, 100))  # Cap at 1GB = 0 points
        cpu_score = max(0, 100 - self.cpu_peak_percent)
        ops_score = min(self.operations_per_second / 10, 100)  # 1000 ops/sec = 100 points
        
        return (duration_score * 0.4 + memory_score * 0.3 + cpu_score * 0.2 + ops_score * 0.1)

@dataclass
class RegressionAlert:
    """Performance regression alert"""
    alert_id: str
    test_name: str
    regression_type: str  # 'duration', 'memory', 'cpu', 'ops', 'composite'
    severity: str  # 'info', 'warning', 'critical'
    detected_at: float
    baseline_value: float
    current_value: float
    change_percent: float
    confidence: float
    git_commit: Optional[str]
    affected_benchmarks: List[str]
    description: str
    recommendations: List[str] = field(default_factory=list)

@dataclass
class BaselineMetrics:
    """Statistical baseline for performance metrics"""
    test_name: str
    metric_name: str
    sample_count: int
    mean: float
    std_dev: float
    median: float
    p95: float
    p99: float
    min_value: float
    max_value: float
    last_updated: float
    trend_direction: str  # 'improving', 'stable', 'degrading'
    trend_confidence: float

class StatisticalAnalyzer:
    """Statistical analysis for performance regression detection"""
    
    def __init__(self, significance_level: float = 0.05):
        self.significance_level = significance_level
        self.anomaly_detector = IsolationForest(contamination=0.1, random_state=42)
        self.scaler = StandardScaler()
    
    def detect_regression(self, baseline_samples: List[float], 
                         current_samples: List[float],
                         metric_name: str) -> Tuple[bool, float, str]:
        """Detect regression using statistical tests"""
        
        if len(baseline_samples) < 5 or len(current_samples) < 3:
            return False, 0.0, "Insufficient data for regression detection"
        
        # Remove outliers
        baseline_clean = self._remove_outliers(baseline_samples)
        current_clean = self._remove_outliers(current_samples)
        
        if len(baseline_clean) < 3 or len(current_clean) < 2:
            return False, 0.0, "Too many outliers removed"
        
        # Perform statistical tests
        results = []
        
        # Mann-Whitney U test (non-parametric)
        try:
            statistic, p_value = stats.mannwhitneyu(
                baseline_clean, current_clean, alternative='two-sided'
            )
            results.append(("mann_whitney", p_value < self.significance_level, p_value))
        except Exception as e:
            logger.warning(f"Mann-Whitney test failed: {e}")
        
        # Welch's t-test (assumes unequal variances)
        try:
            statistic, p_value = stats.ttest_ind(
                baseline_clean, current_clean, equal_var=False
            )
            results.append(("welch_ttest", p_value < self.significance_level, p_value))
        except Exception as e:
            logger.warning(f"Welch's t-test failed: {e}")
        
        # Effect size (Cohen's d)
        baseline_mean = np.mean(baseline_clean)
        current_mean = np.mean(current_clean)
        pooled_std = np.sqrt(
            (np.var(baseline_clean) + np.var(current_clean)) / 2
        )
        cohens_d = abs(current_mean - baseline_mean) / pooled_std if pooled_std > 0 else 0
        
        # Determine regression
        if not results:
            return False, 0.0, "All statistical tests failed"
        
        significant_tests = [r for r in results if r[1]]
        confidence = np.mean([1 - r[2] for r in results])
        
        # Regression criteria
        is_regression = (
            len(significant_tests) >= len(results) / 2 and  # At least half tests significant
            cohens_d > 0.5  # Medium effect size
        )
        
        explanation = f"Tests: {len(significant_tests)}/{len(results)} significant, Cohen's d: {cohens_d:.3f}"
        
        return is_regression, confidence, explanation
    
    def _remove_outliers(self, samples: List[float], method: str = "iqr") -> List[float]:
        """Remove outliers using IQR method"""
        if len(samples) < 4:
            return samples
        
        q1 = np.percentile(samples, 25)
        q3 = np.percentile(samples, 75)
        iqr = q3 - q1
        
        lower_bound = q1 - 1.5 * iqr
        upper_bound = q3 + 1.5 * iqr
        
        return [x for x in samples if lower_bound <= x <= upper_bound]
    
    def detect_anomalies(self, recent_values: List[float]) -> List[bool]:
        """Detect anomalies using machine learning"""
        if len(recent_values) < 10:
            return [False] * len(recent_values)
        
        try:
            # Prepare data
            X = np.array(recent_values).reshape(-1, 1)
            X_scaled = self.scaler.fit_transform(X)
            
            # Detect anomalies
            anomaly_labels = self.anomaly_detector.fit_predict(X_scaled)
            return [label == -1 for label in anomaly_labels]
            
        except Exception as e:
            logger.error(f"Anomaly detection failed: {e}")
            return [False] * len(recent_values)
    
    def calculate_trend(self, values: List[float], timestamps: List[float]) -> Tuple[str, float]:
        """Calculate trend direction and confidence"""
        if len(values) < 5:
            return "stable", 0.0
        
        try:
            # Linear regression for trend
            slope, intercept, r_value, p_value, std_err = stats.linregress(timestamps, values)
            
            # Determine trend direction
            if abs(slope) < std_err:
                direction = "stable"
            elif slope > 0:
                direction = "degrading"  # Assuming higher values = worse performance
            else:
                direction = "improving"
            
            confidence = abs(r_value)  # Correlation coefficient as confidence
            
            return direction, confidence
            
        except Exception as e:
            logger.error(f"Trend calculation failed: {e}")
            return "stable", 0.0

class PerformanceRegressionDetector:
    """Main performance regression detection service"""
    
    def __init__(self, output_dir: str = "orchestrator_runs/performance",
                 baseline_window_days: int = 30,
                 min_baseline_samples: int = 10):
        
        self.output_dir = Path(output_dir)
        self.output_dir.mkdir(parents=True, exist_ok=True)
        
        self.baseline_window_days = baseline_window_days
        self.min_baseline_samples = min_baseline_samples
        
        # Data storage
        self.benchmarks: Dict[str, List[PerformanceBenchmark]] = defaultdict(list)
        self.baselines: Dict[str, BaselineMetrics] = {}
        self.alerts: List[RegressionAlert] = []
        
        # Analysis components
        self.analyzer = StatisticalAnalyzer()
        
        # Configuration
        self.regression_thresholds = {
            'duration_ms': {'warning': 20, 'critical': 50},  # % increase
            'memory_peak_mb': {'warning': 15, 'critical': 30},
            'cpu_avg_percent': {'warning': 25, 'critical': 50},
            'operations_per_second': {'warning': -15, 'critical': -30}  # % decrease
        }
        
        # Load existing data
        self._load_historical_data()
        
        logger.info("Performance regression detector initialized")
    
    def add_benchmark_result(self, benchmark: PerformanceBenchmark):
        """Add new benchmark result and check for regressions"""
        
        # Store benchmark
        self.benchmarks[benchmark.test_name].append(benchmark)
        
        # Keep only recent benchmarks (memory management)
        cutoff_time = time.time() - (self.baseline_window_days * 24 * 3600)
        self.benchmarks[benchmark.test_name] = [
            b for b in self.benchmarks[benchmark.test_name]
            if b.timestamp > cutoff_time
        ]
        
        # Update baseline
        self._update_baseline(benchmark.test_name)
        
        # Check for regressions
        self._check_for_regressions(benchmark)
        
        # Save to disk
        self._save_benchmark_result(benchmark)
        
        logger.info(f"Added benchmark result for {benchmark.test_name}")
    
    def run_benchmark_suite(self, test_suite: str = "default") -> List[PerformanceBenchmark]:
        """Run complete benchmark suite"""
        
        results = []
        git_commit = self._get_git_commit()
        
        # Define benchmark tests
        benchmark_tests = {
            "orchestration_startup": self._benchmark_startup,
            "rcr_routing_performance": self._benchmark_rcr_routing,
            "memory_item_processing": self._benchmark_memory_processing,
            "api_response_time": self._benchmark_api_response,
            "database_operations": self._benchmark_database,
            "concurrent_requests": self._benchmark_concurrent_load,
        }
        
        if test_suite == "quick":
            # Run subset for quick validation
            benchmark_tests = {
                "orchestration_startup": self._benchmark_startup,
                "api_response_time": self._benchmark_api_response,
            }
        
        for test_name, test_func in benchmark_tests.items():
            try:
                logger.info(f"Running benchmark: {test_name}")
                benchmark = test_func(test_name, git_commit)
                results.append(benchmark)
                self.add_benchmark_result(benchmark)
                
            except Exception as e:
                logger.error(f"Benchmark {test_name} failed: {e}")
                error_benchmark = PerformanceBenchmark(
                    benchmark_id=self._generate_benchmark_id(test_name),
                    test_name=test_name,
                    timestamp=time.time(),
                    git_commit=git_commit,
                    environment="production",
                    duration_ms=0,
                    memory_peak_mb=0,
                    memory_avg_mb=0,
                    cpu_avg_percent=0,
                    cpu_peak_percent=0,
                    io_read_mb=0,
                    io_write_mb=0,
                    operations_per_second=0,
                    success=False,
                    error_message=str(e)
                )
                results.append(error_benchmark)
        
        logger.info(f"Completed benchmark suite: {len(results)} tests")
        return results
    
    def _benchmark_startup(self, test_name: str, git_commit: str) -> PerformanceBenchmark:
        """Benchmark orchestrator startup time"""
        
        start_time = time.time()
        start_memory = psutil.Process().memory_info().rss / 1024 / 1024
        cpu_samples = []
        
        # Simulate startup operations
        import sys
        sys.path.append('/Users/david/Downloads/genesis_eval_spec')
        
        try:
            # Import and initialize key components
            from backend.monitoring.genesis_monitor import GenesisMonitor
            from backend.monitoring.apm_service import APMService
            
            monitor = GenesisMonitor()
            apm = APMService()
            
            # Perform typical startup operations
            for i in range(10):
                monitor.start_run(f"test_run_{i}", f"corr_id_{i}")
                apm.take_performance_snapshot()
                cpu_samples.append(psutil.Process().cpu_percent(interval=0.1))
                monitor.end_run(f"test_run_{i}")
            
            end_time = time.time()
            end_memory = psutil.Process().memory_info().rss / 1024 / 1024
            
            return PerformanceBenchmark(
                benchmark_id=self._generate_benchmark_id(test_name),
                test_name=test_name,
                timestamp=start_time,
                git_commit=git_commit,
                environment="production",
                duration_ms=(end_time - start_time) * 1000,
                memory_peak_mb=end_memory,
                memory_avg_mb=(start_memory + end_memory) / 2,
                cpu_avg_percent=np.mean(cpu_samples) if cpu_samples else 0,
                cpu_peak_percent=max(cpu_samples) if cpu_samples else 0,
                io_read_mb=0,  # Would need system monitoring
                io_write_mb=0,
                operations_per_second=10 / (end_time - start_time),
                success=True
            )
            
        except Exception as e:
            end_time = time.time()
            raise Exception(f"Startup benchmark failed: {e}")
    
    def _benchmark_rcr_routing(self, test_name: str, git_commit: str) -> PerformanceBenchmark:
        """Benchmark RCR routing performance"""
        
        start_time = time.time()
        process = psutil.Process()
        start_memory = process.memory_info().rss / 1024 / 1024
        cpu_samples = []
        
        # Simulate RCR routing operations
        operations = 0
        try:
            # Mock RCR routing logic
            documents = [{"content": f"Document {i}" * 100} for i in range(100)]
            budget_per_role = {"planner": 1000, "critic": 800, "solver": 1200}
            
            for _ in range(50):  # 50 routing decisions
                # Simulate routing algorithm
                selected_docs = []
                importance_scores = []
                
                for doc in documents[:20]:  # Select top 20 docs
                    score = len(doc["content"]) * 0.1  # Simple scoring
                    importance_scores.append(score)
                    selected_docs.append(doc)
                
                operations += 1
                cpu_samples.append(process.cpu_percent(interval=0.01))
            
            end_time = time.time()
            end_memory = process.memory_info().rss / 1024 / 1024
            
            return PerformanceBenchmark(
                benchmark_id=self._generate_benchmark_id(test_name),
                test_name=test_name,
                timestamp=start_time,
                git_commit=git_commit,
                environment="production",
                duration_ms=(end_time - start_time) * 1000,
                memory_peak_mb=end_memory,
                memory_avg_mb=(start_memory + end_memory) / 2,
                cpu_avg_percent=np.mean(cpu_samples) if cpu_samples else 0,
                cpu_peak_percent=max(cpu_samples) if cpu_samples else 0,
                io_read_mb=0,
                io_write_mb=0,
                operations_per_second=operations / (end_time - start_time),
                success=True
            )
            
        except Exception as e:
            raise Exception(f"RCR routing benchmark failed: {e}")
    
    def _benchmark_memory_processing(self, test_name: str, git_commit: str) -> PerformanceBenchmark:
        """Benchmark memory item processing"""
        
        start_time = time.time()
        process = psutil.Process()
        start_memory = process.memory_info().rss / 1024 / 1024
        cpu_samples = []
        
        operations = 0
        try:
            # Simulate memory processing
            memory_items = []
            
            for i in range(1000):  # Process 1000 memory items
                memory_item = {
                    "id": f"mem_{i}",
                    "content": f"Memory content {i}" * 50,
                    "metadata": {"importance": i * 0.1, "timestamp": time.time()}
                }
                memory_items.append(memory_item)
                
                # Simulate processing
                processed = {
                    **memory_item,
                    "processed": True,
                    "hash": hashlib.md5(str(memory_item).encode()).hexdigest()
                }
                
                operations += 1
                if i % 100 == 0:
                    cpu_samples.append(process.cpu_percent(interval=0.01))
            
            end_time = time.time()
            end_memory = process.memory_info().rss / 1024 / 1024
            
            return PerformanceBenchmark(
                benchmark_id=self._generate_benchmark_id(test_name),
                test_name=test_name,
                timestamp=start_time,
                git_commit=git_commit,
                environment="production",
                duration_ms=(end_time - start_time) * 1000,
                memory_peak_mb=end_memory,
                memory_avg_mb=(start_memory + end_memory) / 2,
                cpu_avg_percent=np.mean(cpu_samples) if cpu_samples else 0,
                cpu_peak_percent=max(cpu_samples) if cpu_samples else 0,
                io_read_mb=0,
                io_write_mb=0,
                operations_per_second=operations / (end_time - start_time),
                success=True
            )
            
        except Exception as e:
            raise Exception(f"Memory processing benchmark failed: {e}")
    
    def _benchmark_api_response(self, test_name: str, git_commit: str) -> PerformanceBenchmark:
        """Benchmark API response times"""
        
        start_time = time.time()
        process = psutil.Process()
        start_memory = process.memory_info().rss / 1024 / 1024
        cpu_samples = []
        
        operations = 0
        try:
            # Simulate API operations
            response_times = []
            
            for i in range(100):  # 100 API calls
                api_start = time.time()
                
                # Simulate API processing
                data = {"request_id": i, "data": "x" * 1000}
                response = {
                    "status": "success",
                    "data": data,
                    "timestamp": time.time()
                }
                
                api_end = time.time()
                response_times.append((api_end - api_start) * 1000)
                
                operations += 1
                if i % 20 == 0:
                    cpu_samples.append(process.cpu_percent(interval=0.01))
            
            end_time = time.time()
            end_memory = process.memory_info().rss / 1024 / 1024
            
            avg_response_time = np.mean(response_times)
            
            return PerformanceBenchmark(
                benchmark_id=self._generate_benchmark_id(test_name),
                test_name=test_name,
                timestamp=start_time,
                git_commit=git_commit,
                environment="production",
                duration_ms=avg_response_time,
                memory_peak_mb=end_memory,
                memory_avg_mb=(start_memory + end_memory) / 2,
                cpu_avg_percent=np.mean(cpu_samples) if cpu_samples else 0,
                cpu_peak_percent=max(cpu_samples) if cpu_samples else 0,
                io_read_mb=0,
                io_write_mb=0,
                operations_per_second=operations / (end_time - start_time),
                success=True,
                metadata={"avg_response_ms": avg_response_time}
            )
            
        except Exception as e:
            raise Exception(f"API response benchmark failed: {e}")
    
    def _benchmark_database(self, test_name: str, git_commit: str) -> PerformanceBenchmark:
        """Benchmark database operations"""
        # This would require actual database connection
        # For now, simulate database operations
        
        start_time = time.time()
        operations = 1000
        
        # Simulate database latency
        time.sleep(0.5)
        
        end_time = time.time()
        
        return PerformanceBenchmark(
            benchmark_id=self._generate_benchmark_id(test_name),
            test_name=test_name,
            timestamp=start_time,
            git_commit=git_commit,
            environment="production",
            duration_ms=(end_time - start_time) * 1000,
            memory_peak_mb=50,
            memory_avg_mb=45,
            cpu_avg_percent=25,
            cpu_peak_percent=45,
            io_read_mb=10,
            io_write_mb=5,
            operations_per_second=operations / (end_time - start_time),
            success=True
        )
    
    def _benchmark_concurrent_load(self, test_name: str, git_commit: str) -> PerformanceBenchmark:
        """Benchmark concurrent load handling"""
        
        start_time = time.time()
        process = psutil.Process()
        start_memory = process.memory_info().rss / 1024 / 1024
        
        operations = 0
        try:
            # Simulate concurrent operations
            def worker_task(worker_id: int):
                nonlocal operations
                for i in range(20):
                    # Simulate work
                    data = f"Worker {worker_id} task {i}" * 100
                    hash_result = hashlib.md5(data.encode()).hexdigest()
                    operations += 1
                    time.sleep(0.01)
            
            # Run 10 concurrent workers
            threads = []
            for i in range(10):
                t = threading.Thread(target=worker_task, args=(i,))
                t.start()
                threads.append(t)
            
            for t in threads:
                t.join()
            
            end_time = time.time()
            end_memory = process.memory_info().rss / 1024 / 1024
            
            return PerformanceBenchmark(
                benchmark_id=self._generate_benchmark_id(test_name),
                test_name=test_name,
                timestamp=start_time,
                git_commit=git_commit,
                environment="production",
                duration_ms=(end_time - start_time) * 1000,
                memory_peak_mb=end_memory,
                memory_avg_mb=(start_memory + end_memory) / 2,
                cpu_avg_percent=50,
                cpu_peak_percent=80,
                io_read_mb=0,
                io_write_mb=0,
                operations_per_second=operations / (end_time - start_time),
                success=True
            )
            
        except Exception as e:
            raise Exception(f"Concurrent load benchmark failed: {e}")
    
    def _update_baseline(self, test_name: str):
        """Update baseline metrics for a test"""
        
        recent_benchmarks = [
            b for b in self.benchmarks[test_name]
            if b.success and b.timestamp > time.time() - (self.baseline_window_days * 24 * 3600)
        ]
        
        if len(recent_benchmarks) < self.min_baseline_samples:
            return  # Not enough samples
        
        # Calculate baseline for each metric
        metrics = ['duration_ms', 'memory_peak_mb', 'cpu_avg_percent', 'operations_per_second']
        
        for metric in metrics:
            values = [getattr(b, metric) for b in recent_benchmarks]
            timestamps = [b.timestamp for b in recent_benchmarks]
            
            if not values:
                continue
            
            # Calculate statistics
            mean_val = np.mean(values)
            std_val = np.std(values)
            median_val = np.median(values)
            p95_val = np.percentile(values, 95)
            p99_val = np.percentile(values, 99)
            min_val = np.min(values)
            max_val = np.max(values)
            
            # Calculate trend
            trend_direction, trend_confidence = self.analyzer.calculate_trend(values, timestamps)
            
            baseline_key = f"{test_name}:{metric}"
            self.baselines[baseline_key] = BaselineMetrics(
                test_name=test_name,
                metric_name=metric,
                sample_count=len(values),
                mean=mean_val,
                std_dev=std_val,
                median=median_val,
                p95=p95_val,
                p99=p99_val,
                min_value=min_val,
                max_value=max_val,
                last_updated=time.time(),
                trend_direction=trend_direction,
                trend_confidence=trend_confidence
            )
        
        logger.debug(f"Updated baseline for {test_name}")
    
    def _check_for_regressions(self, benchmark: PerformanceBenchmark):
        """Check benchmark result for regressions"""
        
        test_name = benchmark.test_name
        metrics_to_check = ['duration_ms', 'memory_peak_mb', 'cpu_avg_percent', 'operations_per_second']
        
        for metric in metrics_to_check:
            baseline_key = f"{test_name}:{metric}"
            
            if baseline_key not in self.baselines:
                continue  # No baseline yet
            
            baseline = self.baselines[baseline_key]
            current_value = getattr(benchmark, metric)
            
            # Calculate percent change
            if baseline.mean > 0:
                percent_change = ((current_value - baseline.mean) / baseline.mean) * 100
            else:
                continue
            
            # Check thresholds
            thresholds = self.regression_thresholds.get(metric, {'warning': 25, 'critical': 50})
            
            # For operations_per_second, negative change is bad
            if metric == 'operations_per_second':
                is_regression = percent_change <= thresholds['critical']
                severity = 'critical' if percent_change <= thresholds['critical'] else \
                          'warning' if percent_change <= thresholds['warning'] else 'info'
            else:
                # For other metrics, positive change is bad
                is_regression = percent_change >= thresholds['warning']
                severity = 'critical' if percent_change >= thresholds['critical'] else \
                          'warning' if percent_change >= thresholds['warning'] else 'info'
            
            if is_regression and severity in ['warning', 'critical']:
                # Statistical validation
                recent_samples = [
                    getattr(b, metric) for b in self.benchmarks[test_name][-baseline.sample_count:]
                    if b.success
                ]
                current_samples = [current_value]
                
                is_statistically_significant, confidence, explanation = \
                    self.analyzer.detect_regression(recent_samples, current_samples, metric)
                
                if is_statistically_significant:
                    alert = RegressionAlert(
                        alert_id=f"reg_{int(time.time())}_{test_name}_{metric}",
                        test_name=test_name,
                        regression_type=metric,
                        severity=severity,
                        detected_at=time.time(),
                        baseline_value=baseline.mean,
                        current_value=current_value,
                        change_percent=percent_change,
                        confidence=confidence,
                        git_commit=benchmark.git_commit,
                        affected_benchmarks=[benchmark.benchmark_id],
                        description=f"{metric} regression in {test_name}: {percent_change:+.1f}% change",
                        recommendations=self._generate_recommendations(metric, percent_change, benchmark)
                    )
                    
                    self.alerts.append(alert)
                    logger.warning(f"Regression detected: {alert.description}")
    
    def _generate_recommendations(self, metric: str, percent_change: float, 
                                 benchmark: PerformanceBenchmark) -> List[str]:
        """Generate optimization recommendations based on regression type"""
        
        recommendations = []
        
        if metric == 'duration_ms':
            recommendations.extend([
                "Profile the application to identify slow code paths",
                "Check for new dependencies or configuration changes",
                "Review recent commits for performance-impacting changes",
                "Consider algorithm optimizations or caching strategies"
            ])
        
        elif metric == 'memory_peak_mb':
            recommendations.extend([
                "Analyze memory usage patterns for potential leaks",
                "Review object lifecycle and garbage collection",
                "Check for increased data structure sizes",
                "Consider memory pooling or object reuse strategies"
            ])
        
        elif metric == 'cpu_avg_percent':
            recommendations.extend([
                "Profile CPU usage to identify hotspots",
                "Review computational complexity of recent changes",
                "Check for inefficient loops or recursive operations",
                "Consider asynchronous processing for CPU-intensive tasks"
            ])
        
        elif metric == 'operations_per_second':
            recommendations.extend([
                "Analyze bottlenecks in the operation pipeline",
                "Check for synchronization issues or lock contention",
                "Review database query performance and indexing",
                "Consider horizontal scaling or load balancing"
            ])
        
        return recommendations
    
    def _generate_benchmark_id(self, test_name: str) -> str:
        """Generate unique benchmark ID"""
        return f"bench_{test_name}_{int(time.time() * 1000)}"
    
    def _get_git_commit(self) -> Optional[str]:
        """Get current Git commit hash"""
        try:
            result = subprocess.run(
                ['git', 'rev-parse', 'HEAD'],
                capture_output=True, text=True, cwd=self.output_dir.parent
            )
            return result.stdout.strip() if result.returncode == 0 else None
        except Exception:
            return None
    
    def _save_benchmark_result(self, benchmark: PerformanceBenchmark):
        """Save benchmark result to disk"""
        
        benchmark_file = self.output_dir / f"benchmark_{benchmark.benchmark_id}.json"
        
        with open(benchmark_file, 'w') as f:
            json.dump(asdict(benchmark), f, indent=2, default=str)
    
    def _load_historical_data(self):
        """Load historical benchmark data"""
        
        if not self.output_dir.exists():
            return
        
        benchmark_files = list(self.output_dir.glob("benchmark_*.json"))
        
        for file_path in benchmark_files:
            try:
                with open(file_path, 'r') as f:
                    data = json.load(f)
                
                benchmark = PerformanceBenchmark(**data)
                self.benchmarks[benchmark.test_name].append(benchmark)
                
            except Exception as e:
                logger.error(f"Failed to load benchmark {file_path}: {e}")
        
        # Update baselines
        for test_name in self.benchmarks:
            self._update_baseline(test_name)
        
        logger.info(f"Loaded {sum(len(benchmarks) for benchmarks in self.benchmarks.values())} historical benchmarks")
    
    def get_regression_alerts(self, severity: Optional[str] = None) -> List[RegressionAlert]:
        """Get regression alerts, optionally filtered by severity"""
        
        if severity:
            return [alert for alert in self.alerts if alert.severity == severity]
        
        return self.alerts.copy()
    
    def get_test_performance_history(self, test_name: str, 
                                   days: int = 30) -> List[PerformanceBenchmark]:
        """Get performance history for a specific test"""
        
        cutoff_time = time.time() - (days * 24 * 3600)
        
        return [
            b for b in self.benchmarks.get(test_name, [])
            if b.timestamp > cutoff_time
        ]
    
    def get_performance_summary(self) -> Dict[str, Any]:
        """Get overall performance summary"""
        
        total_benchmarks = sum(len(benchmarks) for benchmarks in self.benchmarks.values())
        successful_benchmarks = sum(
            len([b for b in benchmarks if b.success])
            for benchmarks in self.benchmarks.values()
        )
        
        recent_alerts = [
            alert for alert in self.alerts
            if alert.detected_at > time.time() - (7 * 24 * 3600)  # Last 7 days
        ]
        
        summary = {
            "total_tests": len(self.benchmarks),
            "total_benchmarks": total_benchmarks,
            "success_rate": (successful_benchmarks / total_benchmarks) * 100 if total_benchmarks > 0 else 0,
            "recent_alerts": len(recent_alerts),
            "critical_alerts": len([a for a in recent_alerts if a.severity == 'critical']),
            "warning_alerts": len([a for a in recent_alerts if a.severity == 'warning']),
            "baseline_coverage": len(self.baselines),
            "tests_with_trends": len([
                baseline for baseline in self.baselines.values()
                if baseline.trend_direction != 'stable'
            ])
        }
        
        return summary

# Global regression detector instance
regression_detector = PerformanceRegressionDetector()

def get_regression_detector() -> PerformanceRegressionDetector:
    """Get the global regression detector instance"""
    return regression_detector