#!/usr/bin/env python3
"""
GENESIS Orchestrator - Anomaly Detection System
==============================================
Leverages meta-learning framework for intelligent anomaly detection
"""

import sys
import os
import json
import time
import logging
import asyncio
import statistics
import numpy as np
from datetime import datetime, timedelta
from typing import Dict, List, Tuple, Any, Optional
from dataclasses import dataclass, asdict
from pathlib import Path
import requests
import sqlite3
from collections import deque, defaultdict

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

@dataclass
class MetricDatapoint:
    """Single metric measurement"""
    timestamp: float
    value: float
    metric_name: str
    labels: Dict[str, str]

@dataclass
class AnomalyResult:
    """Anomaly detection result"""
    metric_name: str
    timestamp: float
    actual_value: float
    expected_value: float
    confidence_score: float
    severity: str  # 'low', 'medium', 'high', 'critical'
    description: str
    suggested_actions: List[str]

class MetaLearningAnomalyDetector:
    """
    Anomaly detector using meta-learning approach
    Adapts to system patterns and improves over time
    """
    
    def __init__(self, data_dir: str = "orchestrator_runs"):
        self.data_dir = Path(data_dir)
        self.db_path = self.data_dir / "anomaly_detection.db"
        self.models = {}  # Store learned patterns per metric
        self.baseline_windows = defaultdict(lambda: deque(maxlen=100))  # Rolling baseline
        self.pattern_memory = defaultdict(list)  # Historical patterns
        self.init_database()
        
    def init_database(self):
        """Initialize SQLite database for storing anomaly data"""
        self.data_dir.mkdir(exist_ok=True)
        
        conn = sqlite3.connect(self.db_path)
        cursor = conn.cursor()
        
        # Create tables
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS anomalies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp REAL,
                metric_name TEXT,
                actual_value REAL,
                expected_value REAL,
                confidence_score REAL,
                severity TEXT,
                description TEXT,
                suggested_actions TEXT,
                resolved BOOLEAN DEFAULT FALSE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS metric_baselines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                metric_name TEXT,
                window_start REAL,
                window_end REAL,
                mean_value REAL,
                std_deviation REAL,
                min_value REAL,
                max_value REAL,
                sample_count INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS learning_feedback (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                anomaly_id INTEGER,
                feedback_type TEXT,  -- 'true_positive', 'false_positive', 'missed'
                user_id TEXT,
                notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (anomaly_id) REFERENCES anomalies(id)
            )
        ''')
        
        conn.commit()
        conn.close()
        
        logger.info("Anomaly detection database initialized")
    
    def collect_metrics(self, prometheus_url: str = "http://localhost:9090") -> List[MetricDatapoint]:
        """Collect metrics from Prometheus"""
        metrics = []
        
        # Key metrics to monitor for anomalies
        queries = {
            'orchestrator_latency': 'genesis_orchestrator_average_latency_ms',
            'success_rate': '(genesis_orchestrator_successful_runs / genesis_orchestrator_total_runs) * 100',
            'token_usage': 'rate(genesis_orchestrator_total_tokens_used[5m])',
            'router_efficiency': 'genesis_router_efficiency_gain',
            'stability_score': 'genesis_stability_current_score * 100',
            'security_violations': 'increase(genesis_security_violations_24h[1h])',
            'cpu_usage': '100 - (avg(irate(node_cpu_seconds_total{mode="idle"}[5m])) * 100)',
            'memory_usage': '(1 - (node_memory_MemAvailable_bytes / node_memory_MemTotal_bytes)) * 100',
            'request_rate': 'rate(genesis_orchestrator_total_runs[5m])'
        }
        
        for metric_name, query in queries.items():
            try:
                response = requests.get(
                    f"{prometheus_url}/api/v1/query",
                    params={'query': query},
                    timeout=10
                )
                
                if response.status_code == 200:
                    data = response.json()
                    if data['status'] == 'success' and data['data']['result']:
                        for result in data['data']['result']:
                            timestamp = float(result['value'][0])
                            value = float(result['value'][1])
                            labels = result.get('metric', {})
                            
                            metrics.append(MetricDatapoint(
                                timestamp=timestamp,
                                value=value,
                                metric_name=metric_name,
                                labels=labels
                            ))
                            
            except Exception as e:
                logger.error(f"Failed to collect metric {metric_name}: {e}")
        
        logger.debug(f"Collected {len(metrics)} metric datapoints")
        return metrics
    
    def update_baseline(self, metric: MetricDatapoint):
        """Update rolling baseline for a metric"""
        self.baseline_windows[metric.metric_name].append(metric.value)
        
        # Store baseline in database every 100 samples
        if len(self.baseline_windows[metric.metric_name]) >= 100:
            values = list(self.baseline_windows[metric.metric_name])
            mean_val = statistics.mean(values)
            std_val = statistics.stdev(values) if len(values) > 1 else 0
            min_val = min(values)
            max_val = max(values)
            
            conn = sqlite3.connect(self.db_path)
            cursor = conn.cursor()
            
            cursor.execute('''
                INSERT INTO metric_baselines 
                (metric_name, window_start, window_end, mean_value, std_deviation, 
                 min_value, max_value, sample_count)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ''', (
                metric.metric_name,
                metric.timestamp - 3600,  # 1 hour window
                metric.timestamp,
                mean_val,
                std_val,
                min_val,
                max_val,
                len(values)
            ))
            
            conn.commit()
            conn.close()
    
    def detect_statistical_anomaly(self, metric: MetricDatapoint) -> Optional[AnomalyResult]:
        """Detect anomalies using statistical methods"""
        if len(self.baseline_windows[metric.metric_name]) < 20:
            return None  # Need sufficient baseline
        
        baseline_values = list(self.baseline_windows[metric.metric_name])
        mean_val = statistics.mean(baseline_values)
        std_val = statistics.stdev(baseline_values) if len(baseline_values) > 1 else 0
        
        if std_val == 0:
            return None  # No variation in baseline
        
        # Z-score anomaly detection
        z_score = abs((metric.value - mean_val) / std_val)
        
        # Dynamic thresholds based on metric type
        thresholds = self._get_metric_thresholds(metric.metric_name)
        
        if z_score > thresholds['critical']:
            severity = 'critical'
            confidence = min(0.95, z_score / thresholds['critical'])
        elif z_score > thresholds['high']:
            severity = 'high'
            confidence = min(0.85, z_score / thresholds['high'])
        elif z_score > thresholds['medium']:
            severity = 'medium'
            confidence = min(0.75, z_score / thresholds['medium'])
        else:
            return None
        
        # Generate contextual description and actions
        description, actions = self._generate_anomaly_context(
            metric.metric_name, metric.value, mean_val, severity
        )
        
        return AnomalyResult(
            metric_name=metric.metric_name,
            timestamp=metric.timestamp,
            actual_value=metric.value,
            expected_value=mean_val,
            confidence_score=confidence,
            severity=severity,
            description=description,
            suggested_actions=actions
        )
    
    def detect_pattern_anomaly(self, metric: MetricDatapoint) -> Optional[AnomalyResult]:
        """Detect anomalies using pattern recognition"""
        # Store recent patterns
        self.pattern_memory[metric.metric_name].append({
            'timestamp': metric.timestamp,
            'value': metric.value,
            'hour': datetime.fromtimestamp(metric.timestamp).hour,
            'day_of_week': datetime.fromtimestamp(metric.timestamp).weekday()
        })
        
        # Keep only last 1000 patterns
        if len(self.pattern_memory[metric.metric_name]) > 1000:
            self.pattern_memory[metric.metric_name] = self.pattern_memory[metric.metric_name][-1000:]
        
        # Analyze temporal patterns
        current_hour = datetime.fromtimestamp(metric.timestamp).hour
        current_dow = datetime.fromtimestamp(metric.timestamp).weekday()
        
        # Find similar time periods
        similar_patterns = [
            p for p in self.pattern_memory[metric.metric_name]
            if abs(p['hour'] - current_hour) <= 1 and p['day_of_week'] == current_dow
        ]
        
        if len(similar_patterns) < 5:
            return None  # Not enough historical data
        
        similar_values = [p['value'] for p in similar_patterns]
        pattern_mean = statistics.mean(similar_values)
        pattern_std = statistics.stdev(similar_values) if len(similar_values) > 1 else 0
        
        if pattern_std == 0:
            return None
        
        # Pattern-based anomaly score
        pattern_z_score = abs((metric.value - pattern_mean) / pattern_std)
        
        if pattern_z_score > 3.0:  # Significant deviation from temporal pattern
            return AnomalyResult(
                metric_name=metric.metric_name,
                timestamp=metric.timestamp,
                actual_value=metric.value,
                expected_value=pattern_mean,
                confidence_score=min(0.9, pattern_z_score / 3.0),
                severity='high' if pattern_z_score > 4.0 else 'medium',
                description=f"Temporal pattern anomaly detected for {metric.metric_name}",
                suggested_actions=[
                    "Check for scheduled maintenance or deployments",
                    "Verify system configuration changes",
                    "Review historical incidents at similar times"
                ]
            )
        
        return None
    
    def _get_metric_thresholds(self, metric_name: str) -> Dict[str, float]:
        """Get anomaly detection thresholds for specific metrics"""
        thresholds = {
            'orchestrator_latency': {'medium': 2.0, 'high': 3.0, 'critical': 4.0},
            'success_rate': {'medium': 2.5, 'high': 3.5, 'critical': 5.0},
            'token_usage': {'medium': 2.0, 'high': 3.0, 'critical': 4.0},
            'router_efficiency': {'medium': 2.5, 'high': 3.5, 'critical': 5.0},
            'stability_score': {'medium': 3.0, 'high': 4.0, 'critical': 6.0},
            'security_violations': {'medium': 1.5, 'high': 2.0, 'critical': 2.5},
            'cpu_usage': {'medium': 2.0, 'high': 3.0, 'critical': 4.0},
            'memory_usage': {'medium': 2.0, 'high': 3.0, 'critical': 4.0},
            'request_rate': {'medium': 2.5, 'high': 3.5, 'critical': 5.0}
        }
        
        return thresholds.get(metric_name, {'medium': 2.0, 'high': 3.0, 'critical': 4.0})
    
    def _generate_anomaly_context(self, metric_name: str, actual: float, 
                                 expected: float, severity: str) -> Tuple[str, List[str]]:
        """Generate contextual description and suggested actions"""
        
        contexts = {
            'orchestrator_latency': {
                'description': f"Orchestration latency anomaly: {actual:.1f}ms vs expected {expected:.1f}ms",
                'actions': [
                    "Check database query performance",
                    "Verify agent response times",
                    "Review system resource utilization",
                    "Check for network latency issues"
                ]
            },
            'success_rate': {
                'description': f"Success rate anomaly: {actual:.1f}% vs expected {expected:.1f}%",
                'actions': [
                    "Review recent error logs",
                    "Check agent availability",
                    "Verify input validation",
                    "Examine circuit breaker status"
                ]
            },
            'token_usage': {
                'description': f"Token usage rate anomaly: {actual:.2f}/s vs expected {expected:.2f}/s",
                'actions': [
                    "Check for request volume spikes",
                    "Verify router efficiency",
                    "Review prompt optimization",
                    "Monitor budget consumption"
                ]
            },
            'stability_score': {
                'description': f"Stability score anomaly: {actual:.1f}% vs expected {expected:.1f}%",
                'actions': [
                    "Analyze determinism variance",
                    "Check for configuration changes",
                    "Review agent selection patterns",
                    "Verify model consistency"
                ]
            },
            'security_violations': {
                'description': f"Security violations spike: {actual:.0f} vs expected {expected:.0f}",
                'actions': [
                    "Review security logs immediately",
                    "Check for attack patterns",
                    "Verify authentication systems",
                    "Update security rules if needed"
                ]
            }
        }
        
        context = contexts.get(metric_name, {
            'description': f"Anomaly detected in {metric_name}: {actual} vs expected {expected}",
            'actions': ["Investigate metric source", "Check system logs", "Verify configuration"]
        })
        
        return context['description'], context['actions']
    
    def store_anomaly(self, anomaly: AnomalyResult) -> int:
        """Store detected anomaly in database"""
        conn = sqlite3.connect(self.db_path)
        cursor = conn.cursor()
        
        cursor.execute('''
            INSERT INTO anomalies 
            (timestamp, metric_name, actual_value, expected_value, confidence_score,
             severity, description, suggested_actions)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ''', (
            anomaly.timestamp,
            anomaly.metric_name,
            anomaly.actual_value,
            anomaly.expected_value,
            anomaly.confidence_score,
            anomaly.severity,
            anomaly.description,
            json.dumps(anomaly.suggested_actions)
        ))
        
        anomaly_id = cursor.lastrowid
        conn.commit()
        conn.close()
        
        return anomaly_id
    
    def send_alert(self, anomaly: AnomalyResult):
        """Send alert to configured channels"""
        # Slack webhook
        slack_webhook = os.getenv('SLACK_WEBHOOK_URL')
        if slack_webhook:
            self._send_slack_alert(slack_webhook, anomaly)
        
        # PagerDuty integration
        pd_key = os.getenv('PAGERDUTY_INTEGRATION_KEY')
        if pd_key and anomaly.severity in ['high', 'critical']:
            self._send_pagerduty_alert(pd_key, anomaly)
    
    def _send_slack_alert(self, webhook_url: str, anomaly: AnomalyResult):
        """Send Slack alert"""
        color = {
            'low': 'good',
            'medium': 'warning',
            'high': 'warning',
            'critical': 'danger'
        }.get(anomaly.severity, 'warning')
        
        payload = {
            'attachments': [{
                'color': color,
                'title': f'🤖 GENESIS Anomaly Detected - {anomaly.severity.upper()}',
                'text': anomaly.description,
                'fields': [
                    {'title': 'Metric', 'value': anomaly.metric_name, 'short': True},
                    {'title': 'Actual Value', 'value': f'{anomaly.actual_value:.2f}', 'short': True},
                    {'title': 'Expected Value', 'value': f'{anomaly.expected_value:.2f}', 'short': True},
                    {'title': 'Confidence', 'value': f'{anomaly.confidence_score:.1%}', 'short': True}
                ],
                'actions': [
                    {
                        'type': 'button',
                        'text': 'View Grafana',
                        'url': 'http://localhost:3000/d/genesis-orchestrator'
                    }
                ],
                'footer': 'GENESIS Anomaly Detection',
                'ts': int(anomaly.timestamp)
            }]
        }
        
        try:
            response = requests.post(webhook_url, json=payload, timeout=10)
            if response.status_code == 200:
                logger.info(f"Slack alert sent for {anomaly.metric_name} anomaly")
            else:
                logger.error(f"Failed to send Slack alert: {response.status_code}")
        except Exception as e:
            logger.error(f"Error sending Slack alert: {e}")
    
    def _send_pagerduty_alert(self, integration_key: str, anomaly: AnomalyResult):
        """Send PagerDuty alert"""
        payload = {
            'routing_key': integration_key,
            'event_action': 'trigger',
            'dedup_key': f"genesis-anomaly-{anomaly.metric_name}-{int(anomaly.timestamp)}",
            'payload': {
                'summary': f'GENESIS Anomaly: {anomaly.description}',
                'severity': anomaly.severity,
                'source': 'GENESIS Orchestrator',
                'component': anomaly.metric_name,
                'group': 'monitoring',
                'class': 'anomaly',
                'custom_details': {
                    'metric_name': anomaly.metric_name,
                    'actual_value': anomaly.actual_value,
                    'expected_value': anomaly.expected_value,
                    'confidence_score': anomaly.confidence_score,
                    'suggested_actions': anomaly.suggested_actions
                }
            }
        }
        
        try:
            response = requests.post(
                'https://events.pagerduty.com/v2/enqueue',
                json=payload,
                headers={'Content-Type': 'application/json'},
                timeout=10
            )
            if response.status_code == 202:
                logger.info(f"PagerDuty alert sent for {anomaly.metric_name} anomaly")
            else:
                logger.error(f"Failed to send PagerDuty alert: {response.status_code}")
        except Exception as e:
            logger.error(f"Error sending PagerDuty alert: {e}")
    
    def run_detection_cycle(self):
        """Run one complete anomaly detection cycle"""
        logger.info("Starting anomaly detection cycle")
        
        # Collect current metrics
        metrics = self.collect_metrics()
        anomalies_detected = []
        
        for metric in metrics:
            # Update baseline
            self.update_baseline(metric)
            
            # Run detection algorithms
            stat_anomaly = self.detect_statistical_anomaly(metric)
            pattern_anomaly = self.detect_pattern_anomaly(metric)
            
            # Process detected anomalies
            for anomaly in [stat_anomaly, pattern_anomaly]:
                if anomaly:
                    logger.warning(f"Anomaly detected: {anomaly.description}")
                    
                    # Store anomaly
                    anomaly_id = self.store_anomaly(anomaly)
                    
                    # Send alerts
                    self.send_alert(anomaly)
                    
                    anomalies_detected.append(anomaly)
        
        logger.info(f"Anomaly detection cycle completed. Detected: {len(anomalies_detected)} anomalies")
        return anomalies_detected
    
    async def run_continuous_detection(self, interval: int = 60):
        """Run continuous anomaly detection"""
        logger.info(f"Starting continuous anomaly detection (interval: {interval}s)")
        
        while True:
            try:
                self.run_detection_cycle()
                await asyncio.sleep(interval)
            except KeyboardInterrupt:
                logger.info("Anomaly detection stopped by user")
                break
            except Exception as e:
                logger.error(f"Error in detection cycle: {e}")
                await asyncio.sleep(interval)

def main():
    """Main entry point"""
    detector = MetaLearningAnomalyDetector()
    
    # Check for command line arguments
    if len(sys.argv) > 1:
        command = sys.argv[1]
        
        if command == "single":
            # Run single detection cycle
            anomalies = detector.run_detection_cycle()
            print(f"Detected {len(anomalies)} anomalies")
            
        elif command == "continuous":
            # Run continuous detection
            interval = int(sys.argv[2]) if len(sys.argv) > 2 else 60
            asyncio.run(detector.run_continuous_detection(interval))
            
        elif command == "test":
            # Test mode - generate sample alerts
            test_anomaly = AnomalyResult(
                metric_name="test_metric",
                timestamp=time.time(),
                actual_value=100.0,
                expected_value=50.0,
                confidence_score=0.95,
                severity="critical",
                description="Test anomaly for system validation",
                suggested_actions=["This is a test", "No action required"]
            )
            detector.send_alert(test_anomaly)
            print("Test alert sent")
            
        else:
            print("Usage: anomaly_detection.py [single|continuous|test] [interval]")
            sys.exit(1)
    else:
        # Default: run single cycle
        detector.run_detection_cycle()

if __name__ == "__main__":
    main()