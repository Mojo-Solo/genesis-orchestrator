"""
GENESIS Orchestrator - Monitoring Integration
Collects and exports metrics for observability
"""

import json
import time
import os
from typing import Dict, Any, Optional
from dataclasses import dataclass, asdict
from datetime import datetime
import logging

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

@dataclass
class OrchestrationMetrics:
    """Metrics for a single orchestration run"""
    run_id: str
    correlation_id: str
    start_time: float
    end_time: Optional[float] = None
    total_tokens: int = 0
    total_cost_usd: float = 0.0
    steps_completed: int = 0
    terminator_triggered: bool = False
    stability_score: float = 0.0
    rcr_efficiency: float = 0.0
    errors: list = None
    
    def __post_init__(self):
        if self.errors is None:
            self.errors = []
    
    @property
    def duration_ms(self) -> float:
        """Calculate duration in milliseconds"""
        if self.end_time:
            return (self.end_time - self.start_time) * 1000
        return 0.0
    
    @property
    def success(self) -> bool:
        """Determine if run was successful"""
        return not self.terminator_triggered and len(self.errors) == 0

class GenesisMonitor:
    """Main monitoring class for GENESIS orchestrator"""
    
    def __init__(self, output_dir: str = "orchestrator_runs"):
        self.output_dir = output_dir
        self.current_metrics: Dict[str, OrchestrationMetrics] = {}
        self.aggregated_metrics = {
            "total_runs": 0,
            "successful_runs": 0,
            "failed_runs": 0,
            "total_tokens": 0,
            "total_cost": 0.0,
            "avg_latency_ms": 0.0,
            "stability_scores": [],
            "rcr_efficiency_gains": []
        }
    
    def start_run(self, run_id: str, correlation_id: str) -> None:
        """Start tracking a new orchestration run"""
        self.current_metrics[run_id] = OrchestrationMetrics(
            run_id=run_id,
            correlation_id=correlation_id,
            start_time=time.time()
        )
        logger.info(f"Started monitoring run {run_id}")
    
    def end_run(self, run_id: str) -> None:
        """End tracking for an orchestration run"""
        if run_id in self.current_metrics:
            metrics = self.current_metrics[run_id]
            metrics.end_time = time.time()
            
            # Update aggregated metrics
            self.aggregated_metrics["total_runs"] += 1
            if metrics.success:
                self.aggregated_metrics["successful_runs"] += 1
            else:
                self.aggregated_metrics["failed_runs"] += 1
            
            self.aggregated_metrics["total_tokens"] += metrics.total_tokens
            self.aggregated_metrics["total_cost"] += metrics.total_cost_usd
            self.aggregated_metrics["stability_scores"].append(metrics.stability_score)
            self.aggregated_metrics["rcr_efficiency_gains"].append(metrics.rcr_efficiency)
            
            # Calculate running average latency
            current_avg = self.aggregated_metrics["avg_latency_ms"]
            total_runs = self.aggregated_metrics["total_runs"]
            new_avg = ((current_avg * (total_runs - 1)) + metrics.duration_ms) / total_runs
            self.aggregated_metrics["avg_latency_ms"] = new_avg
            
            # Save metrics to file
            self._save_metrics(metrics)
            
            logger.info(f"Completed monitoring run {run_id} - Duration: {metrics.duration_ms:.2f}ms")
    
    def record_tokens(self, run_id: str, agent: str, tokens: int) -> None:
        """Record token usage for an agent"""
        if run_id in self.current_metrics:
            self.current_metrics[run_id].total_tokens += tokens
            logger.debug(f"Run {run_id}: {agent} used {tokens} tokens")
    
    def record_step(self, run_id: str, step_name: str) -> None:
        """Record completion of a step"""
        if run_id in self.current_metrics:
            self.current_metrics[run_id].steps_completed += 1
            logger.debug(f"Run {run_id}: Completed step {step_name}")
    
    def record_error(self, run_id: str, error: str) -> None:
        """Record an error in the run"""
        if run_id in self.current_metrics:
            self.current_metrics[run_id].errors.append({
                "timestamp": datetime.utcnow().isoformat(),
                "error": error
            })
            logger.error(f"Run {run_id}: Error - {error}")
    
    def record_terminator(self, run_id: str, reason: str) -> None:
        """Record terminator trigger"""
        if run_id in self.current_metrics:
            self.current_metrics[run_id].terminator_triggered = True
            self.current_metrics[run_id].errors.append({
                "timestamp": datetime.utcnow().isoformat(),
                "terminator_reason": reason
            })
            logger.warning(f"Run {run_id}: Terminator triggered - {reason}")
    
    def record_stability(self, run_id: str, score: float) -> None:
        """Record stability score"""
        if run_id in self.current_metrics:
            self.current_metrics[run_id].stability_score = score
            logger.info(f"Run {run_id}: Stability score {score:.3f}")
    
    def record_rcr_efficiency(self, run_id: str, efficiency: float) -> None:
        """Record RCR routing efficiency gain"""
        if run_id in self.current_metrics:
            self.current_metrics[run_id].rcr_efficiency = efficiency
            logger.info(f"Run {run_id}: RCR efficiency gain {efficiency:.2%}")
    
    def _save_metrics(self, metrics: OrchestrationMetrics) -> None:
        """Save metrics to file"""
        run_dir = os.path.join(self.output_dir, metrics.run_id)
        os.makedirs(run_dir, exist_ok=True)
        
        metrics_file = os.path.join(run_dir, "metrics.json")
        with open(metrics_file, "w") as f:
            json.dump(asdict(metrics), f, indent=2, default=str)
    
    def get_aggregated_metrics(self) -> Dict[str, Any]:
        """Get aggregated metrics across all runs"""
        metrics = self.aggregated_metrics.copy()
        
        # Calculate averages
        if metrics["stability_scores"]:
            metrics["avg_stability"] = sum(metrics["stability_scores"]) / len(metrics["stability_scores"])
        else:
            metrics["avg_stability"] = 0.0
        
        if metrics["rcr_efficiency_gains"]:
            metrics["avg_rcr_efficiency"] = sum(metrics["rcr_efficiency_gains"]) / len(metrics["rcr_efficiency_gains"])
        else:
            metrics["avg_rcr_efficiency"] = 0.0
        
        # Remove raw lists for cleaner output
        del metrics["stability_scores"]
        del metrics["rcr_efficiency_gains"]
        
        return metrics
    
    def export_prometheus_metrics(self) -> str:
        """Export metrics in Prometheus format"""
        metrics = self.get_aggregated_metrics()
        
        prometheus_format = []
        prometheus_format.append(f"# HELP genesis_total_runs Total orchestration runs")
        prometheus_format.append(f"# TYPE genesis_total_runs counter")
        prometheus_format.append(f"genesis_total_runs {metrics['total_runs']}")
        
        prometheus_format.append(f"# HELP genesis_successful_runs Successful orchestration runs")
        prometheus_format.append(f"# TYPE genesis_successful_runs counter")
        prometheus_format.append(f"genesis_successful_runs {metrics['successful_runs']}")
        
        prometheus_format.append(f"# HELP genesis_failed_runs Failed orchestration runs")
        prometheus_format.append(f"# TYPE genesis_failed_runs counter")
        prometheus_format.append(f"genesis_failed_runs {metrics['failed_runs']}")
        
        prometheus_format.append(f"# HELP genesis_total_tokens Total tokens used")
        prometheus_format.append(f"# TYPE genesis_total_tokens counter")
        prometheus_format.append(f"genesis_total_tokens {metrics['total_tokens']}")
        
        prometheus_format.append(f"# HELP genesis_avg_latency_ms Average latency in milliseconds")
        prometheus_format.append(f"# TYPE genesis_avg_latency_ms gauge")
        prometheus_format.append(f"genesis_avg_latency_ms {metrics['avg_latency_ms']:.2f}")
        
        prometheus_format.append(f"# HELP genesis_avg_stability Average stability score")
        prometheus_format.append(f"# TYPE genesis_avg_stability gauge")
        prometheus_format.append(f"genesis_avg_stability {metrics['avg_stability']:.3f}")
        
        prometheus_format.append(f"# HELP genesis_avg_rcr_efficiency Average RCR efficiency gain")
        prometheus_format.append(f"# TYPE genesis_avg_rcr_efficiency gauge")
        prometheus_format.append(f"genesis_avg_rcr_efficiency {metrics['avg_rcr_efficiency']:.3f}")
        
        return "\n".join(prometheus_format)

# Global monitor instance
monitor = GenesisMonitor()

def get_monitor() -> GenesisMonitor:
    """Get the global monitor instance"""
    return monitor