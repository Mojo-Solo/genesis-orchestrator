#!/usr/bin/env python3
"""
Canary Deployment Monitoring Script
==================================
Monitors canary deployments for error rates, latency, and other key metrics.
"""

import argparse
import asyncio
import json
import logging
import os
import sys
import time
from dataclasses import dataclass
from datetime import datetime, timedelta
from pathlib import Path
from typing import Dict, List, Optional

import aiohttp
import asyncpg
import redis
from prometheus_client.parser import text_string_to_metric_families

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent.parent))

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


@dataclass
class MetricThreshold:
    """Represents a metric threshold for canary monitoring."""
    name: str
    threshold: float
    comparison: str  # 'lt', 'gt', 'eq'
    severity: str  # 'warning', 'critical'


@dataclass
class CanaryMetrics:
    """Container for canary deployment metrics."""
    error_rate: float
    latency_p95: float
    latency_p99: float
    throughput: float
    cpu_usage: float
    memory_usage: float
    timestamp: datetime


class CanaryMonitor:
    """Monitors canary deployment health and performance."""
    
    def __init__(self, environment: str, duration: int, threshold: float):
        self.environment = environment
        self.duration = duration
        self.threshold = threshold
        self.prometheus_url = f"http://prometheus.{environment}.genesis.com"
        self.grafana_url = f"http://grafana.{environment}.genesis.com"
        
        # Define monitoring thresholds
        self.thresholds = [
            MetricThreshold("error_rate", threshold, "lt", "critical"),
            MetricThreshold("latency_p95", 500.0, "lt", "warning"),
            MetricThreshold("latency_p99", 1000.0, "lt", "critical"),
            MetricThreshold("cpu_usage", 80.0, "lt", "warning"),
            MetricThreshold("memory_usage", 85.0, "lt", "critical"),
        ]
        
        # Metrics storage
        self.metrics_history: List[CanaryMetrics] = []
        self.alerts_triggered: List[Dict] = []
        
    async def start_monitoring(self) -> bool:
        """
        Start canary monitoring for the specified duration.
        
        Returns:
            bool: True if monitoring passed, False if thresholds were exceeded
        """
        logger.info(f"Starting canary monitoring for {self.duration} seconds")
        logger.info(f"Environment: {self.environment}")
        logger.info(f"Error rate threshold: {self.threshold}")
        
        start_time = time.time()
        end_time = start_time + self.duration
        monitoring_interval = 30  # seconds
        
        success = True
        
        try:
            while time.time() < end_time:
                # Collect metrics
                metrics = await self.collect_metrics()
                if metrics:
                    self.metrics_history.append(metrics)
                    
                    # Check thresholds
                    violations = self.check_thresholds(metrics)
                    if violations:
                        success = False
                        for violation in violations:
                            self.alerts_triggered.append(violation)
                            logger.error(f"Threshold violation: {violation}")
                            
                            # Send immediate alert for critical violations
                            if violation['severity'] == 'critical':
                                await self.send_alert(violation)
                    
                    # Log current status
                    elapsed = time.time() - start_time
                    remaining = end_time - time.time()
                    logger.info(f"Monitoring progress: {elapsed:.1f}s elapsed, {remaining:.1f}s remaining")
                    self.log_metrics(metrics)
                
                # Wait for next monitoring cycle
                await asyncio.sleep(monitoring_interval)
                
        except Exception as e:
            logger.error(f"Error during monitoring: {e}")
            success = False
        
        # Generate final report
        await self.generate_report()
        
        if success:
            logger.info("Canary monitoring completed successfully - all thresholds met")
        else:
            logger.error("Canary monitoring failed - threshold violations detected")
            
        return success
    
    async def collect_metrics(self) -> Optional[CanaryMetrics]:
        """Collect current metrics from Prometheus."""
        try:
            async with aiohttp.ClientSession() as session:
                # Error rate query
                error_rate = await self._query_prometheus(
                    session,
                    'rate(http_requests_total{status=~"5.."}[5m]) / rate(http_requests_total[5m])'
                )
                
                # Latency queries
                latency_p95 = await self._query_prometheus(
                    session,
                    'histogram_quantile(0.95, rate(http_request_duration_seconds_bucket[5m]))'
                )
                
                latency_p99 = await self._query_prometheus(
                    session,
                    'histogram_quantile(0.99, rate(http_request_duration_seconds_bucket[5m]))'
                )
                
                # Throughput
                throughput = await self._query_prometheus(
                    session,
                    'rate(http_requests_total[5m])'
                )
                
                # Resource usage
                cpu_usage = await self._query_prometheus(
                    session,
                    'avg(rate(container_cpu_usage_seconds_total{pod=~"genesis-orchestrator-canary.*"}[5m])) * 100'
                )
                
                memory_usage = await self._query_prometheus(
                    session,
                    'avg(container_memory_usage_bytes{pod=~"genesis-orchestrator-canary.*"} / container_spec_memory_limit_bytes * 100)'
                )
                
                return CanaryMetrics(
                    error_rate=error_rate or 0.0,
                    latency_p95=latency_p95 or 0.0,
                    latency_p99=latency_p99 or 0.0,
                    throughput=throughput or 0.0,
                    cpu_usage=cpu_usage or 0.0,
                    memory_usage=memory_usage or 0.0,
                    timestamp=datetime.utcnow()
                )
                
        except Exception as e:
            logger.error(f"Failed to collect metrics: {e}")
            return None
    
    async def _query_prometheus(self, session: aiohttp.ClientSession, query: str) -> Optional[float]:
        """Query Prometheus and return the first result value."""
        try:
            url = f"{self.prometheus_url}/api/v1/query"
            params = {"query": query}
            
            async with session.get(url, params=params) as response:
                if response.status == 200:
                    data = await response.json()
                    result = data.get('data', {}).get('result', [])
                    if result:
                        value = result[0].get('value', [None, None])[1]
                        return float(value) if value is not None else None
                else:
                    logger.warning(f"Prometheus query failed: {response.status}")
                    
        except Exception as e:
            logger.error(f"Prometheus query error: {e}")
            
        return None
    
    def check_thresholds(self, metrics: CanaryMetrics) -> List[Dict]:
        """Check if any thresholds are violated."""
        violations = []
        
        metric_values = {
            "error_rate": metrics.error_rate,
            "latency_p95": metrics.latency_p95 * 1000,  # Convert to ms
            "latency_p99": metrics.latency_p99 * 1000,  # Convert to ms
            "cpu_usage": metrics.cpu_usage,
            "memory_usage": metrics.memory_usage,
        }
        
        for threshold in self.thresholds:
            value = metric_values.get(threshold.name)
            if value is None:
                continue
                
            violated = False
            if threshold.comparison == "lt" and value >= threshold.threshold:
                violated = True
            elif threshold.comparison == "gt" and value <= threshold.threshold:
                violated = True
            elif threshold.comparison == "eq" and value != threshold.threshold:
                violated = True
                
            if violated:
                violations.append({
                    "metric": threshold.name,
                    "value": value,
                    "threshold": threshold.threshold,
                    "comparison": threshold.comparison,
                    "severity": threshold.severity,
                    "timestamp": metrics.timestamp.isoformat()
                })
        
        return violations
    
    def log_metrics(self, metrics: CanaryMetrics):
        """Log current metrics in a readable format."""
        logger.info(
            f"Metrics - Error Rate: {metrics.error_rate:.4f}, "
            f"P95 Latency: {metrics.latency_p95*1000:.1f}ms, "
            f"P99 Latency: {metrics.latency_p99*1000:.1f}ms, "
            f"Throughput: {metrics.throughput:.1f} req/s, "
            f"CPU: {metrics.cpu_usage:.1f}%, "
            f"Memory: {metrics.memory_usage:.1f}%"
        )
    
    async def send_alert(self, violation: Dict):
        """Send alert for threshold violation."""
        alert_message = (
            f"🚨 Canary Alert: {violation['metric']} = {violation['value']:.2f} "
            f"exceeds threshold {violation['threshold']} ({violation['severity']})"
        )
        
        # Send to Slack if webhook is configured
        slack_webhook = os.getenv('SLACK_WEBHOOK')
        if slack_webhook:
            try:
                async with aiohttp.ClientSession() as session:
                    payload = {
                        "text": alert_message,
                        "channel": "#genesis-alerts",
                        "username": "Canary Monitor"
                    }
                    await session.post(slack_webhook, json=payload)
            except Exception as e:
                logger.error(f"Failed to send Slack alert: {e}")
        
        logger.warning(alert_message)
    
    async def generate_report(self):
        """Generate final monitoring report."""
        if not self.metrics_history:
            logger.warning("No metrics collected during monitoring period")
            return
        
        # Calculate summary statistics
        error_rates = [m.error_rate for m in self.metrics_history]
        latencies_p95 = [m.latency_p95 * 1000 for m in self.metrics_history]
        latencies_p99 = [m.latency_p99 * 1000 for m in self.metrics_history]
        
        report = {
            "monitoring_summary": {
                "duration_seconds": self.duration,
                "samples_collected": len(self.metrics_history),
                "alerts_triggered": len(self.alerts_triggered),
                "success": len(self.alerts_triggered) == 0
            },
            "error_rate": {
                "avg": sum(error_rates) / len(error_rates),
                "max": max(error_rates),
                "min": min(error_rates),
                "threshold": self.threshold
            },
            "latency_p95_ms": {
                "avg": sum(latencies_p95) / len(latencies_p95),
                "max": max(latencies_p95),
                "min": min(latencies_p95)
            },
            "latency_p99_ms": {
                "avg": sum(latencies_p99) / len(latencies_p99),
                "max": max(latencies_p99),
                "min": min(latencies_p99)
            },
            "violations": self.alerts_triggered
        }
        
        # Save report
        report_file = f"/tmp/canary-report-{int(time.time())}.json"
        with open(report_file, 'w') as f:
            json.dump(report, indent=2, fp=f)
        
        logger.info(f"Monitoring report saved to: {report_file}")
        
        # Log summary
        logger.info("=== CANARY MONITORING REPORT ===")
        logger.info(f"Duration: {self.duration}s")
        logger.info(f"Samples: {len(self.metrics_history)}")
        logger.info(f"Average Error Rate: {report['error_rate']['avg']:.4f}")
        logger.info(f"Average P95 Latency: {report['latency_p95_ms']['avg']:.1f}ms")
        logger.info(f"Average P99 Latency: {report['latency_p99_ms']['avg']:.1f}ms")
        logger.info(f"Violations: {len(self.alerts_triggered)}")
        
        if self.alerts_triggered:
            logger.info("Threshold violations:")
            for violation in self.alerts_triggered:
                logger.info(f"  - {violation['metric']}: {violation['value']:.2f} > {violation['threshold']}")


def main():
    """Main entry point."""
    parser = argparse.ArgumentParser(description="Canary Deployment Monitor")
    parser.add_argument("--environment", default="staging", help="Environment to monitor")
    parser.add_argument("--duration", type=int, default=600, help="Monitoring duration in seconds")
    parser.add_argument("--threshold", type=float, default=0.05, help="Error rate threshold")
    parser.add_argument("--verbose", action="store_true", help="Enable verbose logging")
    
    args = parser.parse_args()
    
    if args.verbose:
        logging.getLogger().setLevel(logging.DEBUG)
    
    # Create monitor and run
    monitor = CanaryMonitor(args.environment, args.duration, args.threshold)
    
    # Run monitoring
    success = asyncio.run(monitor.start_monitoring())
    
    # Exit with appropriate code
    sys.exit(0 if success else 1)


if __name__ == "__main__":
    main()