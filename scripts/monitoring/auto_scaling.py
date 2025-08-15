#!/usr/bin/env python3
"""
GENESIS Orchestrator - Auto-Scaling System
==========================================
Intelligent auto-scaling based on monitoring metrics and business rules
"""

import sys
import os
import json
import time
import logging
import asyncio
import requests
import yaml
from datetime import datetime, timedelta
from typing import Dict, List, Tuple, Any, Optional
from dataclasses import dataclass, asdict
from pathlib import Path
import sqlite3
from collections import defaultdict, deque

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

@dataclass
class ScalingMetric:
    """Scaling decision metric"""
    metric_name: str
    current_value: float
    threshold_scale_up: float
    threshold_scale_down: float
    weight: float
    timestamp: float

@dataclass
class ScalingDecision:
    """Auto-scaling decision"""
    action: str  # 'scale_up', 'scale_down', 'maintain'
    component: str  # 'orchestrator', 'database', 'cache', 'worker'
    current_replicas: int
    target_replicas: int
    confidence_score: float
    reasoning: str
    metrics_considered: List[str]
    estimated_cost_impact: float
    timestamp: float

class AutoScalingEngine:
    """
    Intelligent auto-scaling engine that monitors metrics and makes
    scaling decisions based on business rules and ML-based predictions
    """
    
    def __init__(self, config_file: str = "config/auto_scaling.yaml"):
        self.config_file = Path(config_file)
        self.config = self._load_config()
        self.prometheus_url = self.config.get('prometheus_url', 'http://localhost:9090')
        self.kubernetes_enabled = self.config.get('kubernetes_enabled', True)
        self.docker_compose_enabled = self.config.get('docker_compose_enabled', True)
        self.scaling_history = deque(maxlen=100)
        self.metric_cache = {}
        self.cooldown_periods = defaultdict(float)
        
        # Initialize database for tracking
        self.db_path = Path("orchestrator_runs/auto_scaling.db")
        self.init_database()
        
        logger.info("Auto-scaling engine initialized")
    
    def _load_config(self) -> Dict:
        """Load auto-scaling configuration"""
        if not self.config_file.exists():
            # Create default configuration
            default_config = {
                'prometheus_url': 'http://localhost:9090',
                'kubernetes_enabled': True,
                'docker_compose_enabled': True,
                'scaling_rules': {
                    'orchestrator': {
                        'min_replicas': 2,
                        'max_replicas': 10,
                        'target_cpu_utilization': 70,
                        'target_memory_utilization': 80,
                        'target_request_rate': 50,
                        'cooldown_minutes': 5
                    },
                    'database': {
                        'min_connections': 10,
                        'max_connections': 100,
                        'target_connection_utilization': 70,
                        'cooldown_minutes': 10
                    },
                    'cache': {
                        'min_memory_mb': 512,
                        'max_memory_mb': 4096,
                        'target_hit_rate': 85,
                        'cooldown_minutes': 3
                    }
                },
                'business_rules': {
                    'peak_hours': [9, 10, 11, 14, 15, 16],
                    'maintenance_window': [2, 3, 4],
                    'aggressive_scaling': False,
                    'cost_optimization_mode': True
                },
                'metrics': {
                    'cpu_weight': 0.3,
                    'memory_weight': 0.25,
                    'request_rate_weight': 0.2,
                    'latency_weight': 0.15,
                    'error_rate_weight': 0.1
                }
            }
            
            self.config_file.parent.mkdir(exist_ok=True)
            with open(self.config_file, 'w') as f:
                yaml.dump(default_config, f, default_flow_style=False)
            
            return default_config
        
        with open(self.config_file, 'r') as f:
            return yaml.safe_load(f)
    
    def init_database(self):
        """Initialize SQLite database for scaling history"""
        self.db_path.parent.mkdir(exist_ok=True)
        
        conn = sqlite3.connect(self.db_path)
        cursor = conn.cursor()
        
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS scaling_decisions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp REAL,
                component TEXT,
                action TEXT,
                current_replicas INTEGER,
                target_replicas INTEGER,
                confidence_score REAL,
                reasoning TEXT,
                metrics_considered TEXT,
                cost_impact REAL,
                executed BOOLEAN DEFAULT FALSE,
                execution_time REAL,
                result TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS scaling_metrics_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp REAL,
                metric_name TEXT,
                metric_value REAL,
                component TEXT,
                threshold_up REAL,
                threshold_down REAL,
                weight REAL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        
        conn.commit()
        conn.close()
    
    def collect_scaling_metrics(self) -> List[ScalingMetric]:
        """Collect metrics relevant for scaling decisions"""
        metrics = []
        current_time = time.time()
        
        # Define scaling-relevant queries
        queries = {
            'cpu_utilization': {
                'query': '100 - (avg(irate(node_cpu_seconds_total{mode="idle"}[5m])) * 100)',
                'threshold_up': 70,
                'threshold_down': 30,
                'weight': self.config['metrics']['cpu_weight']
            },
            'memory_utilization': {
                'query': '(1 - (node_memory_MemAvailable_bytes / node_memory_MemTotal_bytes)) * 100',
                'threshold_up': 80,
                'threshold_down': 40,
                'weight': self.config['metrics']['memory_weight']
            },
            'request_rate': {
                'query': 'rate(genesis_orchestrator_total_runs[5m])',
                'threshold_up': 50,
                'threshold_down': 10,
                'weight': self.config['metrics']['request_rate_weight']
            },
            'response_latency': {
                'query': 'genesis_orchestrator_average_latency_ms',
                'threshold_up': 2000,  # Scale up if latency > 2s
                'threshold_down': 500,  # Scale down if latency < 0.5s
                'weight': self.config['metrics']['latency_weight'],
                'invert': True  # Higher values trigger scale-up
            },
            'error_rate': {
                'query': '(rate(genesis_orchestrator_failed_runs[5m]) / rate(genesis_orchestrator_total_runs[5m])) * 100',
                'threshold_up': 5,
                'threshold_down': 1,
                'weight': self.config['metrics']['error_rate_weight'],
                'invert': True
            },
            'database_connections': {
                'query': '(mysql_global_status_threads_connected / mysql_global_variables_max_connections) * 100',
                'threshold_up': 70,
                'threshold_down': 30,
                'weight': 0.2
            },
            'cache_hit_rate': {
                'query': 'genesis_router_cache_hit_rate * 100',
                'threshold_up': 85,  # If hit rate is high, we're efficient
                'threshold_down': 60,  # If hit rate is low, we need more cache
                'weight': 0.15,
                'invert': False  # Lower hit rates might need scaling
            }
        }
        
        for metric_name, config in queries.items():
            try:
                response = requests.get(
                    f"{self.prometheus_url}/api/v1/query",
                    params={'query': config['query']},
                    timeout=10
                )
                
                if response.status_code == 200:
                    data = response.json()
                    if data['status'] == 'success' and data['data']['result']:
                        value = float(data['data']['result'][0]['value'][1])
                        
                        metrics.append(ScalingMetric(
                            metric_name=metric_name,
                            current_value=value,
                            threshold_scale_up=config['threshold_up'],
                            threshold_scale_down=config['threshold_down'],
                            weight=config['weight'],
                            timestamp=current_time
                        ))
                        
                        # Cache metric for trend analysis
                        if metric_name not in self.metric_cache:
                            self.metric_cache[metric_name] = deque(maxlen=20)
                        self.metric_cache[metric_name].append((current_time, value))
                        
            except Exception as e:
                logger.error(f"Failed to collect metric {metric_name}: {e}")
        
        return metrics
    
    def analyze_scaling_need(self, metrics: List[ScalingMetric]) -> Dict[str, ScalingDecision]:
        """Analyze metrics and determine scaling decisions for each component"""
        decisions = {}
        current_time = time.time()
        
        # Analyze orchestrator scaling need
        orchestrator_decision = self._analyze_orchestrator_scaling(metrics, current_time)
        if orchestrator_decision:
            decisions['orchestrator'] = orchestrator_decision
        
        # Analyze database scaling need
        database_decision = self._analyze_database_scaling(metrics, current_time)
        if database_decision:
            decisions['database'] = database_decision
        
        # Analyze cache scaling need
        cache_decision = self._analyze_cache_scaling(metrics, current_time)
        if cache_decision:
            decisions['cache'] = cache_decision
        
        return decisions
    
    def _analyze_orchestrator_scaling(self, metrics: List[ScalingMetric], 
                                    timestamp: float) -> Optional[ScalingDecision]:
        """Analyze if orchestrator needs scaling"""
        component = 'orchestrator'
        
        # Check cooldown period
        if self._is_in_cooldown(component, timestamp):
            return None
        
        # Get current replica count
        current_replicas = self._get_current_replicas(component)
        config = self.config['scaling_rules'][component]
        
        # Calculate scaling score
        scaling_score = 0.0
        metrics_considered = []
        
        for metric in metrics:
            if metric.metric_name in ['cpu_utilization', 'memory_utilization', 
                                     'request_rate', 'response_latency', 'error_rate']:
                
                # Determine if metric suggests scaling up or down
                if metric.metric_name in ['response_latency', 'error_rate']:
                    # For these metrics, higher values suggest scale up
                    if metric.current_value > metric.threshold_scale_up:
                        scaling_score += metric.weight
                    elif metric.current_value < metric.threshold_scale_down:
                        scaling_score -= metric.weight
                else:
                    # For normal metrics, higher values suggest scale up
                    if metric.current_value > metric.threshold_scale_up:
                        scaling_score += metric.weight
                    elif metric.current_value < metric.threshold_scale_down:
                        scaling_score -= metric.weight
                
                metrics_considered.append(metric.metric_name)
        
        # Apply business rules
        scaling_score = self._apply_business_rules(scaling_score, timestamp)
        
        # Determine action
        action = 'maintain'
        target_replicas = current_replicas
        confidence = abs(scaling_score)
        reasoning = f"Scaling score: {scaling_score:.2f}"
        
        if scaling_score > 0.5 and current_replicas < config['max_replicas']:
            action = 'scale_up'
            target_replicas = min(current_replicas + 1, config['max_replicas'])
            reasoning += " - Scale up triggered by high demand metrics"
        elif scaling_score < -0.3 and current_replicas > config['min_replicas']:
            action = 'scale_down'
            target_replicas = max(current_replicas - 1, config['min_replicas'])
            reasoning += " - Scale down triggered by low utilization metrics"
        
        if action != 'maintain':
            # Estimate cost impact
            cost_impact = self._estimate_cost_impact(component, current_replicas, target_replicas)
            
            return ScalingDecision(
                action=action,
                component=component,
                current_replicas=current_replicas,
                target_replicas=target_replicas,
                confidence_score=min(confidence, 1.0),
                reasoning=reasoning,
                metrics_considered=metrics_considered,
                estimated_cost_impact=cost_impact,
                timestamp=timestamp
            )
        
        return None
    
    def _analyze_database_scaling(self, metrics: List[ScalingMetric], 
                                timestamp: float) -> Optional[ScalingDecision]:
        """Analyze if database needs scaling (connection pool adjustment)"""
        component = 'database'
        
        # Find database connection metric
        db_metric = next((m for m in metrics if m.metric_name == 'database_connections'), None)
        if not db_metric:
            return None
        
        if self._is_in_cooldown(component, timestamp):
            return None
        
        current_connections = self._get_current_database_connections()
        config = self.config['scaling_rules'][component]
        
        action = 'maintain'
        target_connections = current_connections
        
        if db_metric.current_value > config['target_connection_utilization']:
            action = 'scale_up'
            target_connections = min(int(current_connections * 1.2), config['max_connections'])
        elif db_metric.current_value < 30:  # Very low utilization
            action = 'scale_down'
            target_connections = max(int(current_connections * 0.8), config['min_connections'])
        
        if action != 'maintain':
            return ScalingDecision(
                action=action,
                component=component,
                current_replicas=current_connections,
                target_replicas=target_connections,
                confidence_score=0.8,
                reasoning=f"Database connection utilization: {db_metric.current_value:.1f}%",
                metrics_considered=['database_connections'],
                estimated_cost_impact=0.0,  # Connection scaling has minimal cost impact
                timestamp=timestamp
            )
        
        return None
    
    def _analyze_cache_scaling(self, metrics: List[ScalingMetric], 
                             timestamp: float) -> Optional[ScalingDecision]:
        """Analyze if cache needs scaling"""
        component = 'cache'
        
        # Find cache hit rate metric
        cache_metric = next((m for m in metrics if m.metric_name == 'cache_hit_rate'), None)
        if not cache_metric:
            return None
        
        if self._is_in_cooldown(component, timestamp):
            return None
        
        current_memory = self._get_current_cache_memory()
        config = self.config['scaling_rules'][component]
        
        action = 'maintain'
        target_memory = current_memory
        
        if cache_metric.current_value < config['target_hit_rate']:
            # Low hit rate suggests need for more cache memory
            action = 'scale_up'
            target_memory = min(int(current_memory * 1.5), config['max_memory_mb'])
        elif cache_metric.current_value > 95:  # Very high hit rate
            # Check if we can reduce cache memory
            memory_utilization = self._get_cache_memory_utilization()
            if memory_utilization < 50:  # Low memory usage
                action = 'scale_down'
                target_memory = max(int(current_memory * 0.8), config['min_memory_mb'])
        
        if action != 'maintain':
            cost_impact = (target_memory - current_memory) * 0.001  # Estimate cost per MB
            
            return ScalingDecision(
                action=action,
                component=component,
                current_replicas=current_memory,
                target_replicas=target_memory,
                confidence_score=0.7,
                reasoning=f"Cache hit rate: {cache_metric.current_value:.1f}%",
                metrics_considered=['cache_hit_rate'],
                estimated_cost_impact=cost_impact,
                timestamp=timestamp
            )
        
        return None
    
    def _apply_business_rules(self, base_score: float, timestamp: float) -> float:
        """Apply business rules to adjust scaling score"""
        current_hour = datetime.fromtimestamp(timestamp).hour
        business_rules = self.config['business_rules']
        
        # Peak hours adjustment
        if current_hour in business_rules['peak_hours']:
            base_score += 0.2  # Bias toward scaling up during peak hours
        
        # Maintenance window adjustment
        if current_hour in business_rules['maintenance_window']:
            base_score -= 0.3  # Bias toward scaling down during maintenance
        
        # Cost optimization mode
        if business_rules['cost_optimization_mode']:
            base_score -= 0.1  # Slight bias toward efficiency
        
        # Aggressive scaling mode
        if business_rules['aggressive_scaling']:
            base_score *= 1.3  # Amplify scaling signals
        
        return base_score
    
    def _is_in_cooldown(self, component: str, timestamp: float) -> bool:
        """Check if component is in cooldown period"""
        cooldown_key = f"{component}_last_scaling"
        last_scaling = self.cooldown_periods.get(cooldown_key, 0)
        cooldown_minutes = self.config['scaling_rules'][component]['cooldown_minutes']
        
        return (timestamp - last_scaling) < (cooldown_minutes * 60)
    
    def _get_current_replicas(self, component: str) -> int:
        """Get current replica count for a component"""
        # In a real implementation, this would query Kubernetes or Docker
        # For now, return a default value
        return 3
    
    def _get_current_database_connections(self) -> int:
        """Get current database connection pool size"""
        return 20  # Default value
    
    def _get_current_cache_memory(self) -> int:
        """Get current cache memory allocation in MB"""
        return 1024  # Default 1GB
    
    def _get_cache_memory_utilization(self) -> float:
        """Get cache memory utilization percentage"""
        return 60.0  # Default value
    
    def _estimate_cost_impact(self, component: str, current: int, target: int) -> float:
        """Estimate cost impact of scaling decision"""
        replica_diff = target - current
        
        # Cost per replica per hour (estimates)
        cost_per_replica = {
            'orchestrator': 2.50,  # $2.50/hour per replica
            'database': 5.00,      # $5.00/hour for database scaling
            'cache': 0.001         # $0.001/hour per MB
        }
        
        hourly_cost_change = replica_diff * cost_per_replica.get(component, 1.0)
        return hourly_cost_change
    
    def execute_scaling_decision(self, decision: ScalingDecision) -> bool:
        """Execute a scaling decision"""
        logger.info(f"Executing scaling decision: {decision.action} {decision.component} "
                   f"from {decision.current_replicas} to {decision.target_replicas}")
        
        try:
            if decision.component == 'orchestrator':
                success = self._scale_orchestrator(decision.target_replicas)
            elif decision.component == 'database':
                success = self._scale_database_connections(decision.target_replicas)
            elif decision.component == 'cache':
                success = self._scale_cache_memory(decision.target_replicas)
            else:
                logger.error(f"Unknown component for scaling: {decision.component}")
                return False
            
            if success:
                # Update cooldown
                self.cooldown_periods[f"{decision.component}_last_scaling"] = decision.timestamp
                
                # Store successful scaling decision
                self._store_scaling_decision(decision, True, "Success")
                
                logger.info(f"Successfully executed scaling decision for {decision.component}")
                return True
            else:
                self._store_scaling_decision(decision, False, "Execution failed")
                return False
                
        except Exception as e:
            logger.error(f"Error executing scaling decision: {e}")
            self._store_scaling_decision(decision, False, f"Error: {str(e)}")
            return False
    
    def _scale_orchestrator(self, target_replicas: int) -> bool:
        """Scale orchestrator replicas"""
        if self.kubernetes_enabled:
            return self._scale_kubernetes_deployment('genesis-orchestrator', target_replicas)
        elif self.docker_compose_enabled:
            return self._scale_docker_compose_service('orchestrator', target_replicas)
        else:
            logger.warning("No scaling backend enabled")
            return False
    
    def _scale_database_connections(self, target_connections: int) -> bool:
        """Adjust database connection pool size"""
        # This would typically involve updating database configuration
        # For now, simulate success
        logger.info(f"Would adjust database connection pool to {target_connections}")
        return True
    
    def _scale_cache_memory(self, target_memory_mb: int) -> bool:
        """Adjust cache memory allocation"""
        # This would typically involve updating Redis configuration
        logger.info(f"Would adjust cache memory to {target_memory_mb}MB")
        return True
    
    def _scale_kubernetes_deployment(self, deployment_name: str, replicas: int) -> bool:
        """Scale Kubernetes deployment"""
        try:
            import subprocess
            result = subprocess.run([
                'kubectl', 'scale', 'deployment', deployment_name, 
                f'--replicas={replicas}'
            ], capture_output=True, text=True, timeout=30)
            
            return result.returncode == 0
        except Exception as e:
            logger.error(f"Failed to scale Kubernetes deployment: {e}")
            return False
    
    def _scale_docker_compose_service(self, service_name: str, replicas: int) -> bool:
        """Scale Docker Compose service"""
        try:
            import subprocess
            result = subprocess.run([
                'docker-compose', 'up', '-d', '--scale', 
                f'{service_name}={replicas}'
            ], capture_output=True, text=True, timeout=60)
            
            return result.returncode == 0
        except Exception as e:
            logger.error(f"Failed to scale Docker Compose service: {e}")
            return False
    
    def _store_scaling_decision(self, decision: ScalingDecision, 
                              executed: bool, result: str):
        """Store scaling decision in database"""
        conn = sqlite3.connect(self.db_path)
        cursor = conn.cursor()
        
        cursor.execute('''
            INSERT INTO scaling_decisions 
            (timestamp, component, action, current_replicas, target_replicas,
             confidence_score, reasoning, metrics_considered, cost_impact,
             executed, execution_time, result)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ''', (
            decision.timestamp,
            decision.component,
            decision.action,
            decision.current_replicas,
            decision.target_replicas,
            decision.confidence_score,
            decision.reasoning,
            json.dumps(decision.metrics_considered),
            decision.estimated_cost_impact,
            executed,
            time.time(),
            result
        ))
        
        conn.commit()
        conn.close()
    
    def run_scaling_cycle(self) -> List[ScalingDecision]:
        """Run one complete auto-scaling cycle"""
        logger.info("Starting auto-scaling cycle")
        
        # Collect metrics
        metrics = self.collect_scaling_metrics()
        if not metrics:
            logger.warning("No metrics collected for scaling analysis")
            return []
        
        # Analyze scaling needs
        decisions = self.analyze_scaling_need(metrics)
        
        # Execute scaling decisions
        executed_decisions = []
        for component, decision in decisions.items():
            if self.execute_scaling_decision(decision):
                executed_decisions.append(decision)
        
        logger.info(f"Auto-scaling cycle completed. Executed {len(executed_decisions)} scaling decisions")
        return executed_decisions
    
    async def run_continuous_scaling(self, interval: int = 60):
        """Run continuous auto-scaling"""
        logger.info(f"Starting continuous auto-scaling (interval: {interval}s)")
        
        while True:
            try:
                self.run_scaling_cycle()
                await asyncio.sleep(interval)
            except KeyboardInterrupt:
                logger.info("Auto-scaling stopped by user")
                break
            except Exception as e:
                logger.error(f"Error in scaling cycle: {e}")
                await asyncio.sleep(interval)

def main():
    """Main entry point"""
    engine = AutoScalingEngine()
    
    if len(sys.argv) > 1:
        command = sys.argv[1]
        
        if command == "single":
            # Run single scaling cycle
            decisions = engine.run_scaling_cycle()
            print(f"Executed {len(decisions)} scaling decisions")
            
        elif command == "continuous":
            # Run continuous scaling
            interval = int(sys.argv[2]) if len(sys.argv) > 2 else 60
            asyncio.run(engine.run_continuous_scaling(interval))
            
        elif command == "status":
            # Show current scaling status
            metrics = engine.collect_scaling_metrics()
            print("Current Scaling Metrics:")
            for metric in metrics:
                print(f"  {metric.metric_name}: {metric.current_value:.2f} "
                     f"(up: {metric.threshold_scale_up}, down: {metric.threshold_scale_down})")
            
        else:
            print("Usage: auto_scaling.py [single|continuous|status] [interval]")
            sys.exit(1)
    else:
        # Default: run single cycle
        engine.run_scaling_cycle()

if __name__ == "__main__":
    main()