"""
Monitoring and Observability Layer for Unified MCP Orchestrator
================================================================
Comprehensive monitoring, metrics collection, tracing, and alerting
for the orchestrator system with OpenTelemetry integration.

Features:
- Real-time metrics collection and aggregation
- Distributed tracing across agent executions
- Health checks and readiness probes
- Alert management and escalation
- Performance profiling and analysis
- Audit logging and compliance tracking
"""

import asyncio
import json
import time
import hashlib
from datetime import datetime, timedelta
from typing import Dict, List, Any, Optional, Callable
from dataclasses import dataclass, field, asdict
from enum import Enum
from collections import defaultdict, deque
import logging
from pathlib import Path
import statistics

# OpenTelemetry imports (would be actual imports in production)
# from opentelemetry import trace, metrics
# from opentelemetry.exporter.prometheus import PrometheusMetricReader
# from opentelemetry.exporter.jaeger import JaegerExporter

logger = logging.getLogger(__name__)


# ============================================================================
# MONITORING DATA STRUCTURES
# ============================================================================

class MetricType(Enum):
    """Types of metrics collected."""
    COUNTER = "counter"
    GAUGE = "gauge"
    HISTOGRAM = "histogram"
    SUMMARY = "summary"


class AlertSeverity(Enum):
    """Alert severity levels."""
    INFO = "info"
    WARNING = "warning"
    ERROR = "error"
    CRITICAL = "critical"


class HealthStatus(Enum):
    """Component health status."""
    HEALTHY = "healthy"
    DEGRADED = "degraded"
    UNHEALTHY = "unhealthy"
    UNKNOWN = "unknown"


@dataclass
class Metric:
    """Represents a single metric measurement."""
    name: str
    type: MetricType
    value: float
    timestamp: datetime = field(default_factory=datetime.utcnow)
    labels: Dict[str, str] = field(default_factory=dict)
    unit: str = ""
    description: str = ""


@dataclass
class TraceSpan:
    """Represents a trace span for distributed tracing."""
    span_id: str
    trace_id: str
    parent_span_id: Optional[str]
    operation_name: str
    start_time: datetime
    end_time: Optional[datetime] = None
    duration_ms: Optional[int] = None
    status: str = "in_progress"
    attributes: Dict[str, Any] = field(default_factory=dict)
    events: List[Dict[str, Any]] = field(default_factory=list)
    
    def finish(self):
        """Mark span as finished."""
        self.end_time = datetime.utcnow()
        self.duration_ms = int((self.end_time - self.start_time).total_seconds() * 1000)
        self.status = "completed"


@dataclass
class Alert:
    """Represents a system alert."""
    id: str
    severity: AlertSeverity
    source: str
    message: str
    timestamp: datetime = field(default_factory=datetime.utcnow)
    context: Dict[str, Any] = field(default_factory=dict)
    resolved: bool = False
    resolved_at: Optional[datetime] = None
    acknowledged: bool = False
    acknowledged_by: Optional[str] = None


@dataclass
class HealthCheck:
    """Health check result for a component."""
    component: str
    status: HealthStatus
    message: str
    timestamp: datetime = field(default_factory=datetime.utcnow)
    checks: Dict[str, bool] = field(default_factory=dict)
    metadata: Dict[str, Any] = field(default_factory=dict)


# ============================================================================
# METRICS COLLECTOR
# ============================================================================

class MetricsCollector:
    """Collects and aggregates metrics from the orchestrator."""
    
    def __init__(self, retention_minutes: int = 60):
        self.metrics: Dict[str, deque] = defaultdict(lambda: deque(maxlen=10000))
        self.aggregations: Dict[str, Dict[str, float]] = defaultdict(dict)
        self.retention_minutes = retention_minutes
        self._last_cleanup = datetime.utcnow()
        
    def record_metric(self, metric: Metric):
        """Record a metric measurement."""
        key = f"{metric.name}:{json.dumps(metric.labels, sort_keys=True)}"
        self.metrics[key].append(metric)
        
        # Update aggregations
        self._update_aggregations(key, metric)
        
        # Periodic cleanup
        if (datetime.utcnow() - self._last_cleanup).total_seconds() > 300:
            self._cleanup_old_metrics()
    
    def _update_aggregations(self, key: str, metric: Metric):
        """Update aggregated statistics for a metric."""
        if metric.type == MetricType.COUNTER:
            current = self.aggregations[key].get("total", 0)
            self.aggregations[key]["total"] = current + metric.value
            self.aggregations[key]["rate_per_min"] = self._calculate_rate(key)
            
        elif metric.type in [MetricType.GAUGE, MetricType.HISTOGRAM]:
            values = [m.value for m in self.metrics[key]]
            if values:
                self.aggregations[key]["min"] = min(values)
                self.aggregations[key]["max"] = max(values)
                self.aggregations[key]["avg"] = statistics.mean(values)
                self.aggregations[key]["median"] = statistics.median(values)
                if len(values) > 1:
                    self.aggregations[key]["stddev"] = statistics.stdev(values)
    
    def _calculate_rate(self, key: str) -> float:
        """Calculate rate per minute for counter metrics."""
        metrics = list(self.metrics[key])
        if len(metrics) < 2:
            return 0.0
            
        time_diff = (metrics[-1].timestamp - metrics[0].timestamp).total_seconds()
        if time_diff == 0:
            return 0.0
            
        value_diff = sum(m.value for m in metrics)
        return (value_diff / time_diff) * 60
    
    def _cleanup_old_metrics(self):
        """Remove metrics older than retention period."""
        cutoff = datetime.utcnow() - timedelta(minutes=self.retention_minutes)
        
        for key in list(self.metrics.keys()):
            # Remove old metrics
            while self.metrics[key] and self.metrics[key][0].timestamp < cutoff:
                self.metrics[key].popleft()
            
            # Remove empty keys
            if not self.metrics[key]:
                del self.metrics[key]
                del self.aggregations[key]
        
        self._last_cleanup = datetime.utcnow()
    
    def get_metrics(self, name: str, labels: Optional[Dict[str, str]] = None) -> List[Metric]:
        """Get metrics by name and optional labels."""
        results = []
        
        for key, metrics in self.metrics.items():
            metric_name = key.split(":")[0]
            if metric_name == name:
                if labels:
                    metric_labels = json.loads(key.split(":", 1)[1])
                    if all(metric_labels.get(k) == v for k, v in labels.items()):
                        results.extend(metrics)
                else:
                    results.extend(metrics)
        
        return results
    
    def get_aggregations(self, name: str) -> Dict[str, Dict[str, float]]:
        """Get aggregated statistics for a metric."""
        results = {}
        
        for key, agg in self.aggregations.items():
            metric_name = key.split(":")[0]
            if metric_name == name:
                results[key] = agg
        
        return results
    
    def export_prometheus(self) -> str:
        """Export metrics in Prometheus format."""
        lines = []
        
        for key, metrics in self.metrics.items():
            if not metrics:
                continue
                
            metric_name = key.split(":")[0]
            labels_str = key.split(":", 1)[1] if ":" in key else "{}"
            labels = json.loads(labels_str)
            
            # Latest value
            latest = metrics[-1]
            
            # Format labels for Prometheus
            if labels:
                label_str = ",".join(f'{k}="{v}"' for k, v in labels.items())
                prometheus_line = f"{metric_name}{{{label_str}}} {latest.value}"
            else:
                prometheus_line = f"{metric_name} {latest.value}"
            
            lines.append(prometheus_line)
        
        return "\n".join(lines)


# ============================================================================
# DISTRIBUTED TRACER
# ============================================================================

class DistributedTracer:
    """Manages distributed tracing across agent executions."""
    
    def __init__(self):
        self.active_spans: Dict[str, TraceSpan] = {}
        self.completed_spans: List[TraceSpan] = []
        self.trace_map: Dict[str, List[str]] = defaultdict(list)  # trace_id -> span_ids
        
    def start_span(self, operation_name: str, trace_id: Optional[str] = None,
                   parent_span_id: Optional[str] = None) -> TraceSpan:
        """Start a new trace span."""
        span_id = self._generate_span_id()
        
        if not trace_id:
            trace_id = self._generate_trace_id()
        
        span = TraceSpan(
            span_id=span_id,
            trace_id=trace_id,
            parent_span_id=parent_span_id,
            operation_name=operation_name,
            start_time=datetime.utcnow()
        )
        
        self.active_spans[span_id] = span
        self.trace_map[trace_id].append(span_id)
        
        return span
    
    def finish_span(self, span_id: str, status: str = "completed"):
        """Finish a trace span."""
        if span_id in self.active_spans:
            span = self.active_spans[span_id]
            span.finish()
            span.status = status
            
            self.completed_spans.append(span)
            del self.active_spans[span_id]
            
            # Limit completed spans retention
            if len(self.completed_spans) > 10000:
                self.completed_spans = self.completed_spans[-5000:]
    
    def add_span_event(self, span_id: str, event_name: str, attributes: Dict[str, Any] = None):
        """Add an event to a span."""
        if span_id in self.active_spans:
            event = {
                "name": event_name,
                "timestamp": datetime.utcnow().isoformat(),
                "attributes": attributes or {}
            }
            self.active_spans[span_id].events.append(event)
    
    def get_trace(self, trace_id: str) -> List[TraceSpan]:
        """Get all spans for a trace."""
        spans = []
        
        for span_id in self.trace_map.get(trace_id, []):
            if span_id in self.active_spans:
                spans.append(self.active_spans[span_id])
            else:
                # Search in completed spans
                for span in self.completed_spans:
                    if span.span_id == span_id:
                        spans.append(span)
                        break
        
        return spans
    
    def export_jaeger(self, trace_id: str) -> Dict[str, Any]:
        """Export trace in Jaeger format."""
        spans = self.get_trace(trace_id)
        
        jaeger_trace = {
            "traceID": trace_id,
            "spans": []
        }
        
        for span in spans:
            jaeger_span = {
                "traceID": span.trace_id,
                "spanID": span.span_id,
                "parentSpanID": span.parent_span_id or "",
                "operationName": span.operation_name,
                "startTime": int(span.start_time.timestamp() * 1000000),  # microseconds
                "duration": (span.duration_ms or 0) * 1000,  # microseconds
                "tags": [
                    {"key": k, "value": str(v)} 
                    for k, v in span.attributes.items()
                ],
                "logs": span.events,
                "process": {
                    "serviceName": "mcp-orchestrator",
                    "tags": []
                }
            }
            jaeger_trace["spans"].append(jaeger_span)
        
        return jaeger_trace
    
    def _generate_trace_id(self) -> str:
        """Generate a unique trace ID."""
        return hashlib.md5(f"{time.time()}:trace".encode()).hexdigest()[:16]
    
    def _generate_span_id(self) -> str:
        """Generate a unique span ID."""
        return hashlib.md5(f"{time.time()}:span:{id(self)}".encode()).hexdigest()[:16]


# ============================================================================
# ALERT MANAGER
# ============================================================================

class AlertManager:
    """Manages system alerts and escalations."""
    
    def __init__(self):
        self.alerts: Dict[str, Alert] = {}
        self.alert_rules: List[Dict[str, Any]] = []
        self.escalation_policies: List[Dict[str, Any]] = []
        self.notification_channels: Dict[str, Callable] = {}
        self._init_default_rules()
        
    def _init_default_rules(self):
        """Initialize default alert rules."""
        self.alert_rules = [
            {
                "name": "high_error_rate",
                "condition": lambda m: m.get("error_rate", 0) > 0.1,
                "severity": AlertSeverity.ERROR,
                "message": "Error rate exceeds 10%"
            },
            {
                "name": "slow_response",
                "condition": lambda m: m.get("avg_response_time_ms", 0) > 5000,
                "severity": AlertSeverity.WARNING,
                "message": "Average response time exceeds 5 seconds"
            },
            {
                "name": "agent_unavailable",
                "condition": lambda m: m.get("agent_status") == "unavailable",
                "severity": AlertSeverity.ERROR,
                "message": "Agent is unavailable"
            },
            {
                "name": "memory_pressure",
                "condition": lambda m: m.get("memory_usage_percent", 0) > 90,
                "severity": AlertSeverity.CRITICAL,
                "message": "Memory usage exceeds 90%"
            }
        ]
    
    def create_alert(self, severity: AlertSeverity, source: str, message: str, 
                    context: Dict[str, Any] = None) -> Alert:
        """Create a new alert."""
        alert = Alert(
            id=hashlib.md5(f"{time.time()}:{source}:{message}".encode()).hexdigest()[:16],
            severity=severity,
            source=source,
            message=message,
            context=context or {}
        )
        
        self.alerts[alert.id] = alert
        
        # Trigger notifications
        self._notify(alert)
        
        # Check escalation policies
        self._check_escalation(alert)
        
        return alert
    
    def check_metrics(self, metrics: Dict[str, Any]):
        """Check metrics against alert rules."""
        for rule in self.alert_rules:
            try:
                if rule["condition"](metrics):
                    self.create_alert(
                        severity=rule["severity"],
                        source="metrics_checker",
                        message=rule["message"],
                        context={"metrics": metrics, "rule": rule["name"]}
                    )
            except Exception as e:
                logger.error(f"Error checking alert rule {rule['name']}: {e}")
    
    def resolve_alert(self, alert_id: str):
        """Mark an alert as resolved."""
        if alert_id in self.alerts:
            self.alerts[alert_id].resolved = True
            self.alerts[alert_id].resolved_at = datetime.utcnow()
    
    def acknowledge_alert(self, alert_id: str, acknowledged_by: str):
        """Acknowledge an alert."""
        if alert_id in self.alerts:
            self.alerts[alert_id].acknowledged = True
            self.alerts[alert_id].acknowledged_by = acknowledged_by
    
    def _notify(self, alert: Alert):
        """Send notifications for an alert."""
        for channel_name, channel_func in self.notification_channels.items():
            try:
                channel_func(alert)
            except Exception as e:
                logger.error(f"Failed to notify via {channel_name}: {e}")
    
    def _check_escalation(self, alert: Alert):
        """Check if alert needs escalation."""
        # Count similar recent alerts
        similar_count = sum(
            1 for a in self.alerts.values()
            if a.source == alert.source and 
            not a.resolved and
            (datetime.utcnow() - a.timestamp).total_seconds() < 300  # 5 minutes
        )
        
        # Escalate if too many similar alerts
        if similar_count > 3 and alert.severity != AlertSeverity.CRITICAL:
            escalated = Alert(
                id=f"{alert.id}_escalated",
                severity=AlertSeverity.CRITICAL,
                source=alert.source,
                message=f"ESCALATED: {alert.message} (repeated {similar_count} times)",
                context={"original_alert": alert.id, "count": similar_count}
            )
            self.alerts[escalated.id] = escalated
            self._notify(escalated)
    
    def get_active_alerts(self, severity: Optional[AlertSeverity] = None) -> List[Alert]:
        """Get active (unresolved) alerts."""
        alerts = [a for a in self.alerts.values() if not a.resolved]
        
        if severity:
            alerts = [a for a in alerts if a.severity == severity]
        
        return sorted(alerts, key=lambda a: a.timestamp, reverse=True)
    
    def register_notification_channel(self, name: str, func: Callable):
        """Register a notification channel."""
        self.notification_channels[name] = func


# ============================================================================
# HEALTH CHECKER
# ============================================================================

class HealthChecker:
    """Performs health checks on system components."""
    
    def __init__(self):
        self.health_checks: Dict[str, Callable] = {}
        self.last_results: Dict[str, HealthCheck] = {}
        self.check_interval_seconds = 30
        
    def register_check(self, component: str, check_func: Callable):
        """Register a health check function for a component."""
        self.health_checks[component] = check_func
    
    async def run_checks(self) -> Dict[str, HealthCheck]:
        """Run all registered health checks."""
        results = {}
        
        for component, check_func in self.health_checks.items():
            try:
                # Run health check
                check_result = await check_func() if asyncio.iscoroutinefunction(check_func) else check_func()
                
                # Determine overall status
                if isinstance(check_result, dict):
                    all_passed = all(check_result.values())
                    status = HealthStatus.HEALTHY if all_passed else HealthStatus.UNHEALTHY
                    message = "All checks passed" if all_passed else "Some checks failed"
                    checks = check_result
                else:
                    status = HealthStatus.HEALTHY if check_result else HealthStatus.UNHEALTHY
                    message = "Check passed" if check_result else "Check failed"
                    checks = {"main": check_result}
                
                health = HealthCheck(
                    component=component,
                    status=status,
                    message=message,
                    checks=checks
                )
                
            except Exception as e:
                health = HealthCheck(
                    component=component,
                    status=HealthStatus.UNHEALTHY,
                    message=f"Check failed with error: {str(e)}",
                    checks={"error": False}
                )
            
            results[component] = health
            self.last_results[component] = health
        
        return results
    
    def get_overall_health(self) -> HealthStatus:
        """Get overall system health status."""
        if not self.last_results:
            return HealthStatus.UNKNOWN
        
        statuses = [h.status for h in self.last_results.values()]
        
        if all(s == HealthStatus.HEALTHY for s in statuses):
            return HealthStatus.HEALTHY
        elif any(s == HealthStatus.UNHEALTHY for s in statuses):
            return HealthStatus.UNHEALTHY
        elif any(s == HealthStatus.DEGRADED for s in statuses):
            return HealthStatus.DEGRADED
        else:
            return HealthStatus.UNKNOWN
    
    def get_readiness(self) -> bool:
        """Check if system is ready to handle requests."""
        overall = self.get_overall_health()
        return overall in [HealthStatus.HEALTHY, HealthStatus.DEGRADED]
    
    def get_liveness(self) -> bool:
        """Check if system is alive (not deadlocked)."""
        # Check if health checks are recent
        if not self.last_results:
            return False
        
        cutoff = datetime.utcnow() - timedelta(seconds=self.check_interval_seconds * 2)
        return any(h.timestamp > cutoff for h in self.last_results.values())


# ============================================================================
# PERFORMANCE PROFILER
# ============================================================================

class PerformanceProfiler:
    """Profiles performance of agent executions and workflows."""
    
    def __init__(self):
        self.profiles: Dict[str, List[Dict[str, Any]]] = defaultdict(list)
        self.flame_graph_data: Dict[str, Any] = {}
        
    def start_profile(self, operation: str) -> str:
        """Start profiling an operation."""
        profile_id = hashlib.md5(f"{time.time()}:{operation}".encode()).hexdigest()[:16]
        
        self.profiles[operation].append({
            "id": profile_id,
            "start_time": time.perf_counter(),
            "start_timestamp": datetime.utcnow(),
            "segments": []
        })
        
        return profile_id
    
    def add_segment(self, profile_id: str, segment_name: str, duration_ms: float,
                   metadata: Dict[str, Any] = None):
        """Add a segment to a profile."""
        for operation, profiles in self.profiles.items():
            for profile in profiles:
                if profile["id"] == profile_id:
                    profile["segments"].append({
                        "name": segment_name,
                        "duration_ms": duration_ms,
                        "metadata": metadata or {}
                    })
                    return
    
    def end_profile(self, profile_id: str):
        """End profiling an operation."""
        for operation, profiles in self.profiles.items():
            for profile in profiles:
                if profile["id"] == profile_id:
                    profile["end_time"] = time.perf_counter()
                    profile["total_duration_ms"] = (profile["end_time"] - profile["start_time"]) * 1000
                    
                    # Limit retention
                    if len(profiles) > 100:
                        self.profiles[operation] = profiles[-50:]
                    return
    
    def get_profile_summary(self, operation: str) -> Dict[str, Any]:
        """Get summary statistics for an operation."""
        profiles = self.profiles.get(operation, [])
        
        if not profiles:
            return {}
        
        durations = [p.get("total_duration_ms", 0) for p in profiles if "total_duration_ms" in p]
        
        if not durations:
            return {}
        
        return {
            "count": len(durations),
            "min_ms": min(durations),
            "max_ms": max(durations),
            "avg_ms": statistics.mean(durations),
            "median_ms": statistics.median(durations),
            "p95_ms": statistics.quantiles(durations, n=20)[18] if len(durations) > 20 else max(durations),
            "p99_ms": statistics.quantiles(durations, n=100)[98] if len(durations) > 100 else max(durations)
        }
    
    def generate_flame_graph(self, operation: str) -> Dict[str, Any]:
        """Generate flame graph data for an operation."""
        profiles = self.profiles.get(operation, [])
        
        if not profiles:
            return {}
        
        # Aggregate segment times
        segment_times = defaultdict(float)
        segment_counts = defaultdict(int)
        
        for profile in profiles:
            for segment in profile.get("segments", []):
                segment_times[segment["name"]] += segment["duration_ms"]
                segment_counts[segment["name"]] += 1
        
        # Build flame graph structure
        flame_data = {
            "name": operation,
            "value": sum(segment_times.values()),
            "children": [
                {
                    "name": name,
                    "value": time,
                    "count": segment_counts[name]
                }
                for name, time in sorted(segment_times.items(), key=lambda x: x[1], reverse=True)
            ]
        }
        
        self.flame_graph_data[operation] = flame_data
        return flame_data


# ============================================================================
# AUDIT LOGGER
# ============================================================================

class AuditLogger:
    """Logs all actions for audit and compliance purposes."""
    
    def __init__(self, log_file: Path = Path("audit.log")):
        self.log_file = log_file
        self.buffer: List[Dict[str, Any]] = []
        self.flush_interval = 10  # seconds
        self.last_flush = time.time()
        
    def log_action(self, action: str, user: str, details: Dict[str, Any] = None):
        """Log an auditable action."""
        entry = {
            "timestamp": datetime.utcnow().isoformat(),
            "action": action,
            "user": user,
            "details": details or {},
            "hash": self._compute_hash(action, user, details)
        }
        
        self.buffer.append(entry)
        
        # Flush if needed
        if time.time() - self.last_flush > self.flush_interval:
            self.flush()
    
    def flush(self):
        """Write buffered entries to disk."""
        if not self.buffer:
            return
        
        with open(self.log_file, "a") as f:
            for entry in self.buffer:
                f.write(json.dumps(entry) + "\n")
        
        self.buffer.clear()
        self.last_flush = time.time()
    
    def _compute_hash(self, action: str, user: str, details: Any) -> str:
        """Compute hash for audit entry (for tamper detection)."""
        content = f"{action}:{user}:{json.dumps(details, sort_keys=True)}"
        return hashlib.sha256(content.encode()).hexdigest()
    
    def verify_integrity(self) -> bool:
        """Verify audit log integrity."""
        if not self.log_file.exists():
            return True
        
        with open(self.log_file, "r") as f:
            for line in f:
                try:
                    entry = json.loads(line)
                    expected_hash = self._compute_hash(
                        entry["action"], 
                        entry["user"], 
                        entry["details"]
                    )
                    if entry["hash"] != expected_hash:
                        return False
                except:
                    return False
        
        return True


# ============================================================================
# MONITORING DASHBOARD
# ============================================================================

class MonitoringDashboard:
    """Provides a unified view of system monitoring data."""
    
    def __init__(self, metrics_collector: MetricsCollector,
                 tracer: DistributedTracer,
                 alert_manager: AlertManager,
                 health_checker: HealthChecker,
                 profiler: PerformanceProfiler,
                 audit_logger: AuditLogger):
        self.metrics = metrics_collector
        self.tracer = tracer
        self.alerts = alert_manager
        self.health = health_checker
        self.profiler = profiler
        self.audit = audit_logger
        
    def get_dashboard_data(self) -> Dict[str, Any]:
        """Get comprehensive dashboard data."""
        return {
            "timestamp": datetime.utcnow().isoformat(),
            "health": {
                "overall": self.health.get_overall_health().value,
                "components": {
                    name: check.status.value 
                    for name, check in self.health.last_results.items()
                },
                "ready": self.health.get_readiness(),
                "live": self.health.get_liveness()
            },
            "alerts": {
                "critical": len(self.alerts.get_active_alerts(AlertSeverity.CRITICAL)),
                "error": len(self.alerts.get_active_alerts(AlertSeverity.ERROR)),
                "warning": len(self.alerts.get_active_alerts(AlertSeverity.WARNING)),
                "info": len(self.alerts.get_active_alerts(AlertSeverity.INFO)),
                "recent": [
                    {
                        "id": a.id,
                        "severity": a.severity.value,
                        "message": a.message,
                        "timestamp": a.timestamp.isoformat()
                    }
                    for a in self.alerts.get_active_alerts()[:5]
                ]
            },
            "metrics": {
                "request_rate": self.metrics.aggregations.get("requests", {}).get("rate_per_min", 0),
                "error_rate": self.metrics.aggregations.get("errors", {}).get("rate_per_min", 0),
                "avg_latency_ms": self.metrics.aggregations.get("latency", {}).get("avg", 0),
                "active_workflows": len(self.tracer.active_spans),
                "completed_workflows": len(self.tracer.completed_spans)
            },
            "performance": {
                name: self.profiler.get_profile_summary(name)
                for name in ["route_task", "execute_agent", "workflow_execution"]
            },
            "audit": {
                "log_integrity": self.audit.verify_integrity(),
                "recent_actions": len(self.audit.buffer)
            }
        }
    
    def export_metrics(self, format: str = "json") -> str:
        """Export metrics in specified format."""
        if format == "prometheus":
            return self.metrics.export_prometheus()
        elif format == "json":
            return json.dumps(self.get_dashboard_data(), indent=2)
        else:
            raise ValueError(f"Unsupported format: {format}")


# ============================================================================
# INTEGRATION WITH ORCHESTRATOR
# ============================================================================

class MonitoringIntegration:
    """Integrates monitoring with the unified orchestrator."""
    
    def __init__(self):
        # Initialize components
        self.metrics = MetricsCollector()
        self.tracer = DistributedTracer()
        self.alerts = AlertManager()
        self.health = HealthChecker()
        self.profiler = PerformanceProfiler()
        self.audit = AuditLogger()
        self.dashboard = MonitoringDashboard(
            self.metrics, self.tracer, self.alerts,
            self.health, self.profiler, self.audit
        )
        
        # Register default health checks
        self._register_default_health_checks()
        
    def _register_default_health_checks(self):
        """Register default health checks."""
        
        def check_memory():
            import psutil
            mem = psutil.virtual_memory()
            return {
                "memory_available": mem.percent < 90,
                "swap_available": psutil.swap_memory().percent < 80
            }
        
        def check_disk():
            import psutil
            disk = psutil.disk_usage("/")
            return {"disk_space": disk.percent < 90}
        
        # Register checks (would be async in production)
        # self.health.register_check("memory", check_memory)
        # self.health.register_check("disk", check_disk)
    
    def instrument_orchestrator(self, orchestrator):
        """Add monitoring instrumentation to orchestrator."""
        
        # Wrap handle_mcp_request
        original_handle = orchestrator.handle_mcp_request
        
        async def monitored_handle(request):
            # Start trace
            trace_id = request.get("trace_id")
            span = self.tracer.start_span("handle_mcp_request", trace_id)
            
            # Record metric
            self.metrics.record_metric(Metric(
                name="requests",
                type=MetricType.COUNTER,
                value=1,
                labels={"type": request.get("type", "unknown")}
            ))
            
            # Audit log
            self.audit.log_action(
                "mcp_request",
                request.get("requester", "unknown"),
                {"request_id": request.get("id"), "type": request.get("type")}
            )
            
            try:
                # Execute
                start_time = time.perf_counter()
                result = await original_handle(request)
                duration_ms = (time.perf_counter() - start_time) * 1000
                
                # Record latency
                self.metrics.record_metric(Metric(
                    name="latency",
                    type=MetricType.HISTOGRAM,
                    value=duration_ms,
                    unit="ms",
                    labels={"type": request.get("type", "unknown")}
                ))
                
                # Finish span
                span.attributes["success"] = result.get("success", False)
                self.tracer.finish_span(span.span_id, "completed")
                
                return result
                
            except Exception as e:
                # Record error
                self.metrics.record_metric(Metric(
                    name="errors",
                    type=MetricType.COUNTER,
                    value=1,
                    labels={"type": request.get("type", "unknown"), "error": str(e)}
                ))
                
                # Create alert
                self.alerts.create_alert(
                    AlertSeverity.ERROR,
                    "orchestrator",
                    f"Request failed: {str(e)}",
                    {"request_id": request.get("id")}
                )
                
                # Finish span with error
                span.attributes["error"] = str(e)
                self.tracer.finish_span(span.span_id, "failed")
                
                raise
        
        orchestrator.handle_mcp_request = monitored_handle
        
        return orchestrator
    
    async def start_monitoring_loop(self):
        """Start background monitoring tasks."""
        while True:
            try:
                # Run health checks
                await self.health.run_checks()
                
                # Check for alerts
                current_metrics = {
                    "error_rate": self.metrics.aggregations.get("errors", {}).get("rate_per_min", 0) /
                                 max(self.metrics.aggregations.get("requests", {}).get("rate_per_min", 1), 1),
                    "avg_response_time_ms": self.metrics.aggregations.get("latency", {}).get("avg", 0)
                }
                self.alerts.check_metrics(current_metrics)
                
                # Flush audit logs
                self.audit.flush()
                
                # Sleep
                await asyncio.sleep(30)
                
            except Exception as e:
                logger.error(f"Monitoring loop error: {e}")
                await asyncio.sleep(60)


# ============================================================================
# MAIN ENTRY POINT
# ============================================================================

async def main():
    """Test monitoring and observability."""
    
    # Create monitoring integration
    monitoring = MonitoringIntegration()
    
    # Simulate some metrics
    for i in range(10):
        monitoring.metrics.record_metric(Metric(
            name="test_metric",
            type=MetricType.GAUGE,
            value=i * 10,
            labels={"test": "true"}
        ))
    
    # Create a trace
    span = monitoring.tracer.start_span("test_operation")
    monitoring.tracer.add_span_event(span.span_id, "test_event", {"data": "test"})
    monitoring.tracer.finish_span(span.span_id)
    
    # Create an alert
    monitoring.alerts.create_alert(
        AlertSeverity.WARNING,
        "test",
        "This is a test alert"
    )
    
    # Get dashboard data
    dashboard_data = monitoring.dashboard.get_dashboard_data()
    print(json.dumps(dashboard_data, indent=2))
    
    # Export metrics
    prometheus_metrics = monitoring.dashboard.export_metrics("prometheus")
    print("\nPrometheus Metrics:")
    print(prometheus_metrics)


if __name__ == "__main__":
    asyncio.run(main())