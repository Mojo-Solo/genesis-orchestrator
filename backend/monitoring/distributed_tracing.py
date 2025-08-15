"""
GENESIS Orchestrator - Distributed Tracing Service
Comprehensive request flow visualization and tracing infrastructure
"""

import os
import time
import json
import uuid
import logging
import threading
from typing import Dict, Any, List, Optional, Callable, Union
from dataclasses import dataclass, asdict, field
from datetime import datetime, timedelta
from contextlib import contextmanager
from collections import defaultdict, deque
import functools

from opentelemetry import trace, baggage, context
from opentelemetry.exporter.jaeger.thrift import JaegerExporter
from opentelemetry.exporter.otlp.proto.grpc.trace_exporter import OTLPSpanExporter
from opentelemetry.sdk.trace import TracerProvider, Span
from opentelemetry.sdk.trace.export import BatchSpanProcessor, SimpleSpanProcessor
from opentelemetry.sdk.resources import Resource
from opentelemetry.instrumentation.requests import RequestsInstrumentor
from opentelemetry.instrumentation.aiohttp_client import AioHttpClientInstrumentor
from opentelemetry.trace.status import Status, StatusCode
from opentelemetry.trace.propagation.tracecontext import TraceContextTextMapPropagator

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

@dataclass
class SpanMetrics:
    """Metrics for individual spans"""
    span_id: str
    trace_id: str
    parent_span_id: Optional[str]
    operation_name: str
    service_name: str
    start_time: float
    end_time: Optional[float] = None
    duration_ms: Optional[float] = None
    status: str = "OK"
    tags: Dict[str, Any] = field(default_factory=dict)
    logs: List[Dict[str, Any]] = field(default_factory=list)
    errors: List[str] = field(default_factory=list)
    
    def __post_init__(self):
        if self.end_time and self.start_time:
            self.duration_ms = (self.end_time - self.start_time) * 1000

@dataclass
class TraceMetrics:
    """Metrics for complete traces"""
    trace_id: str
    root_span_id: str
    start_time: float
    end_time: Optional[float] = None
    total_duration_ms: Optional[float] = None
    spans: List[SpanMetrics] = field(default_factory=list)
    services_involved: List[str] = field(default_factory=list)
    error_count: int = 0
    critical_path_ms: float = 0.0
    
    def __post_init__(self):
        if self.end_time and self.start_time:
            self.total_duration_ms = (self.end_time - self.start_time) * 1000

@dataclass
class ServiceDependencyMap:
    """Map of service dependencies discovered through tracing"""
    service_name: str
    dependencies: Dict[str, int] = field(default_factory=dict)  # service -> call_count
    dependents: Dict[str, int] = field(default_factory=dict)   # service -> call_count
    avg_latency_ms: float = 0.0
    error_rate: float = 0.0
    call_volume: int = 0

class DistributedTracingService:
    """Main distributed tracing service for GENESIS orchestrator"""
    
    def __init__(self, service_name: str = "genesis-orchestrator", 
                 jaeger_endpoint: str = "http://localhost:14268/api/traces",
                 otlp_endpoint: str = "http://localhost:4317"):
        
        self.service_name = service_name
        self.jaeger_endpoint = jaeger_endpoint
        self.otlp_endpoint = otlp_endpoint
        
        # Initialize OpenTelemetry
        self._setup_tracing()
        
        # Tracing data storage
        self.active_spans: Dict[str, SpanMetrics] = {}
        self.completed_traces: Dict[str, TraceMetrics] = {}
        self.service_dependencies: Dict[str, ServiceDependencyMap] = {}
        self.trace_sampling_rules: Dict[str, float] = {
            "default": 0.1,  # 10% sampling by default
            "error": 1.0,    # Always sample errors
            "slow": 1.0,     # Always sample slow requests (>2s)
            "critical": 1.0  # Always sample critical operations
        }
        
        # Performance tracking
        self.slow_span_threshold_ms = 2000
        self.error_span_patterns = ["error", "exception", "failed", "timeout"]
        
        # Background processing
        self.processing_enabled = True
        self.processor_thread = threading.Thread(target=self._process_traces, daemon=True)
        self.processor_thread.start()
        
        logger.info(f"Distributed tracing initialized for service: {service_name}")
    
    def _setup_tracing(self):
        """Initialize OpenTelemetry tracing"""
        # Resource configuration
        resource = Resource.create({
            "service.name": self.service_name,
            "service.version": "1.0.0",
            "deployment.environment": "production"
        })
        
        # Setup tracer provider
        trace.set_tracer_provider(TracerProvider(resource=resource))
        tracer_provider = trace.get_tracer_provider()
        
        # Setup exporters
        jaeger_exporter = JaegerExporter(
            endpoint=self.jaeger_endpoint,
            max_tag_value_length=4096
        )
        
        otlp_exporter = OTLPSpanExporter(
            endpoint=self.otlp_endpoint,
            insecure=True
        )
        
        # Setup span processors
        tracer_provider.add_span_processor(
            BatchSpanProcessor(jaeger_exporter, max_queue_size=512, max_export_batch_size=64)
        )
        
        tracer_provider.add_span_processor(
            BatchSpanProcessor(otlp_exporter, max_queue_size=512, max_export_batch_size=64)
        )
        
        # Get tracer
        self.tracer = trace.get_tracer(__name__)
        
        # Auto-instrument common libraries
        try:
            RequestsInstrumentor().instrument()
            AioHttpClientInstrumentor().instrument()
            logger.info("Auto-instrumentation enabled for HTTP libraries")
        except Exception as e:
            logger.warning(f"Auto-instrumentation failed: {e}")
    
    @contextmanager
    def trace_operation(self, operation_name: str, 
                       service_name: Optional[str] = None,
                       tags: Optional[Dict[str, Any]] = None,
                       baggage_items: Optional[Dict[str, str]] = None):
        """Context manager for tracing operations"""
        
        service = service_name or self.service_name
        span_tags = tags or {}
        
        # Start span
        with self.tracer.start_as_current_span(operation_name) as span:
            span_id = str(span.get_span_context().span_id)
            trace_id = str(span.get_span_context().trace_id)
            
            # Set baggage items for cross-service context
            if baggage_items:
                for key, value in baggage_items.items():
                    baggage.set_baggage(key, value)
            
            # Create span metrics
            span_metrics = SpanMetrics(
                span_id=span_id,
                trace_id=trace_id,
                parent_span_id=str(span.parent.span_id) if span.parent else None,
                operation_name=operation_name,
                service_name=service,
                start_time=time.time(),
                tags=span_tags
            )
            
            self.active_spans[span_id] = span_metrics
            
            # Set span attributes
            span.set_attribute("service.name", service)
            span.set_attribute("operation.name", operation_name)
            for key, value in span_tags.items():
                span.set_attribute(f"custom.{key}", str(value))
            
            try:
                yield span_metrics
                
                # Mark as successful
                span.set_status(Status(StatusCode.OK))
                span_metrics.status = "OK"
                
            except Exception as e:
                # Mark as error
                span.set_status(Status(StatusCode.ERROR, str(e)))
                span.record_exception(e)
                span_metrics.status = "ERROR"
                span_metrics.errors.append(str(e))
                logger.error(f"Span {span_id} failed: {e}")
                raise
                
            finally:
                # Complete span
                span_metrics.end_time = time.time()
                span_metrics.duration_ms = (span_metrics.end_time - span_metrics.start_time) * 1000
                
                # Move to completed traces
                self._process_completed_span(span_metrics)
                self.active_spans.pop(span_id, None)
    
    def add_span_event(self, span_id: str, event_name: str, 
                      attributes: Optional[Dict[str, Any]] = None):
        """Add event to active span"""
        if span_id in self.active_spans:
            span_metrics = self.active_spans[span_id]
            event = {
                "timestamp": time.time(),
                "name": event_name,
                "attributes": attributes or {}
            }
            span_metrics.logs.append(event)
            
            # Add to current OpenTelemetry span if active
            current_span = trace.get_current_span()
            if current_span and str(current_span.get_span_context().span_id) == span_id:
                current_span.add_event(event_name, attributes)
    
    def add_span_tag(self, span_id: str, key: str, value: Any):
        """Add tag to active span"""
        if span_id in self.active_spans:
            self.active_spans[span_id].tags[key] = value
            
            # Add to current OpenTelemetry span if active
            current_span = trace.get_current_span()
            if current_span and str(current_span.get_span_context().span_id) == span_id:
                current_span.set_attribute(f"custom.{key}", str(value))
    
    def _process_completed_span(self, span_metrics: SpanMetrics):
        """Process completed span and update trace metrics"""
        trace_id = span_metrics.trace_id
        
        # Initialize trace if not exists
        if trace_id not in self.completed_traces:
            self.completed_traces[trace_id] = TraceMetrics(
                trace_id=trace_id,
                root_span_id=span_metrics.span_id,
                start_time=span_metrics.start_time
            )
        
        trace_metrics = self.completed_traces[trace_id]
        trace_metrics.spans.append(span_metrics)
        
        # Update trace end time (latest span end)
        if span_metrics.end_time:
            if not trace_metrics.end_time or span_metrics.end_time > trace_metrics.end_time:
                trace_metrics.end_time = span_metrics.end_time
        
        # Update services involved
        if span_metrics.service_name not in trace_metrics.services_involved:
            trace_metrics.services_involved.append(span_metrics.service_name)
        
        # Update error count
        if span_metrics.status == "ERROR":
            trace_metrics.error_count += 1
        
        # Update service dependencies
        self._update_service_dependencies(span_metrics)
        
        logger.debug(f"Processed span {span_metrics.span_id} for trace {trace_id}")
    
    def _update_service_dependencies(self, span_metrics: SpanMetrics):
        """Update service dependency mapping"""
        service = span_metrics.service_name
        
        # Initialize service dependency map if not exists
        if service not in self.service_dependencies:
            self.service_dependencies[service] = ServiceDependencyMap(service_name=service)
        
        service_map = self.service_dependencies[service]
        service_map.call_volume += 1
        
        if span_metrics.duration_ms:
            # Update average latency
            total_latency = service_map.avg_latency_ms * (service_map.call_volume - 1)
            service_map.avg_latency_ms = (total_latency + span_metrics.duration_ms) / service_map.call_volume
        
        # Update error rate
        if span_metrics.status == "ERROR":
            service_map.error_rate = (service_map.error_rate * (service_map.call_volume - 1) + 1) / service_map.call_volume
        else:
            service_map.error_rate = (service_map.error_rate * (service_map.call_volume - 1)) / service_map.call_volume
        
        # TODO: Parse span tags for downstream service calls to build dependency graph
        # This would require parsing database queries, HTTP calls, etc.
    
    def _process_traces(self):
        """Background processing of completed traces"""
        while self.processing_enabled:
            try:
                # Clean up old completed traces (keep last 1000)
                if len(self.completed_traces) > 1000:
                    oldest_traces = sorted(
                        self.completed_traces.items(), 
                        key=lambda x: x[1].start_time
                    )[:500]  # Remove oldest 500
                    
                    for trace_id, _ in oldest_traces:
                        self.completed_traces.pop(trace_id, None)
                
                # Calculate critical paths for recent traces
                self._calculate_critical_paths()
                
                time.sleep(30)  # Process every 30 seconds
                
            except Exception as e:
                logger.error(f"Trace processing error: {e}")
                time.sleep(60)
    
    def _calculate_critical_paths(self):
        """Calculate critical path for traces (longest execution path)"""
        recent_traces = list(self.completed_traces.values())[-100:]  # Last 100 traces
        
        for trace_metrics in recent_traces:
            if not trace_metrics.spans:
                continue
            
            # Build span dependency graph
            span_graph = {}
            for span in trace_metrics.spans:
                span_graph[span.span_id] = {
                    'span': span,
                    'children': [],
                    'duration': span.duration_ms or 0
                }
            
            # Link parent-child relationships
            for span in trace_metrics.spans:
                if span.parent_span_id and span.parent_span_id in span_graph:
                    span_graph[span.parent_span_id]['children'].append(span.span_id)
            
            # Calculate critical path (DFS for longest path)
            def calculate_longest_path(span_id):
                node = span_graph[span_id]
                if not node['children']:
                    return node['duration']
                
                max_child_path = max(
                    calculate_longest_path(child_id) 
                    for child_id in node['children']
                )
                return node['duration'] + max_child_path
            
            # Find root spans and calculate critical path
            root_spans = [s for s in trace_metrics.spans if not s.parent_span_id]
            if root_spans:
                critical_paths = [
                    calculate_longest_path(root.span_id) 
                    for root in root_spans
                    if root.span_id in span_graph
                ]
                trace_metrics.critical_path_ms = max(critical_paths) if critical_paths else 0
    
    def get_trace_by_id(self, trace_id: str) -> Optional[TraceMetrics]:
        """Get trace metrics by trace ID"""
        return self.completed_traces.get(trace_id)
    
    def get_recent_traces(self, limit: int = 100) -> List[TraceMetrics]:
        """Get recent trace metrics"""
        traces = sorted(
            self.completed_traces.values(), 
            key=lambda x: x.start_time, 
            reverse=True
        )
        return traces[:limit]
    
    def get_slow_traces(self, threshold_ms: float = 2000, limit: int = 50) -> List[TraceMetrics]:
        """Get slow traces above threshold"""
        slow_traces = [
            trace for trace in self.completed_traces.values()
            if trace.total_duration_ms and trace.total_duration_ms > threshold_ms
        ]
        return sorted(slow_traces, key=lambda x: x.total_duration_ms, reverse=True)[:limit]
    
    def get_error_traces(self, limit: int = 50) -> List[TraceMetrics]:
        """Get traces with errors"""
        error_traces = [
            trace for trace in self.completed_traces.values()
            if trace.error_count > 0
        ]
        return sorted(error_traces, key=lambda x: x.error_count, reverse=True)[:limit]
    
    def get_service_dependency_map(self) -> Dict[str, ServiceDependencyMap]:
        """Get complete service dependency mapping"""
        return self.service_dependencies
    
    def analyze_trace_patterns(self) -> Dict[str, Any]:
        """Analyze patterns in trace data"""
        if not self.completed_traces:
            return {"error": "No traces available for analysis"}
        
        traces = list(self.completed_traces.values())
        
        # Calculate statistics
        durations = [t.total_duration_ms for t in traces if t.total_duration_ms]
        error_rates = [t.error_count / len(t.spans) for t in traces if t.spans]
        
        analysis = {
            "total_traces": len(traces),
            "avg_duration_ms": sum(durations) / len(durations) if durations else 0,
            "median_duration_ms": sorted(durations)[len(durations)//2] if durations else 0,
            "p95_duration_ms": sorted(durations)[int(len(durations)*0.95)] if durations else 0,
            "p99_duration_ms": sorted(durations)[int(len(durations)*0.99)] if durations else 0,
            "avg_error_rate": sum(error_rates) / len(error_rates) if error_rates else 0,
            "services_involved": len(self.service_dependencies),
            "most_called_services": sorted(
                [(s.service_name, s.call_volume) for s in self.service_dependencies.values()],
                key=lambda x: x[1], reverse=True
            )[:10],
            "slowest_services": sorted(
                [(s.service_name, s.avg_latency_ms) for s in self.service_dependencies.values()],
                key=lambda x: x[1], reverse=True
            )[:10],
            "highest_error_services": sorted(
                [(s.service_name, s.error_rate) for s in self.service_dependencies.values()],
                key=lambda x: x[1], reverse=True
            )[:10]
        }
        
        return analysis
    
    def export_traces_to_json(self, limit: int = 1000) -> str:
        """Export recent traces to JSON format"""
        recent_traces = self.get_recent_traces(limit)
        export_data = {
            "export_timestamp": datetime.utcnow().isoformat(),
            "total_traces": len(recent_traces),
            "traces": [asdict(trace) for trace in recent_traces]
        }
        return json.dumps(export_data, indent=2, default=str)
    
    def set_sampling_rule(self, rule_name: str, sampling_rate: float):
        """Set sampling rate for specific trace types"""
        if 0.0 <= sampling_rate <= 1.0:
            self.trace_sampling_rules[rule_name] = sampling_rate
            logger.info(f"Updated sampling rule '{rule_name}' to {sampling_rate}")
        else:
            raise ValueError("Sampling rate must be between 0.0 and 1.0")
    
    def should_sample_trace(self, operation_name: str, 
                           has_error: bool = False, 
                           duration_ms: Optional[float] = None) -> bool:
        """Determine if trace should be sampled based on rules"""
        
        # Always sample errors
        if has_error:
            return True
        
        # Always sample slow traces
        if duration_ms and duration_ms > self.slow_span_threshold_ms:
            return True
        
        # Check for critical operations
        critical_patterns = ["auth", "payment", "critical", "security"]
        if any(pattern in operation_name.lower() for pattern in critical_patterns):
            return self.trace_sampling_rules.get("critical", 1.0) > 0.5
        
        # Default sampling
        import random
        return random.random() < self.trace_sampling_rules.get("default", 0.1)
    
    def shutdown(self):
        """Shutdown tracing service"""
        self.processing_enabled = False
        if self.processor_thread.is_alive():
            self.processor_thread.join(timeout=5.0)
        logger.info("Distributed tracing service shutdown complete")

# Decorators for easy tracing
def trace_function(operation_name: Optional[str] = None, 
                  service_name: Optional[str] = None,
                  tags: Optional[Dict[str, Any]] = None):
    """Decorator for automatic function tracing"""
    def decorator(func: Callable) -> Callable:
        op_name = operation_name or f"{func.__module__}.{func.__name__}"
        
        @functools.wraps(func)
        def wrapper(*args, **kwargs):
            with tracing_service.trace_operation(
                operation_name=op_name,
                service_name=service_name,
                tags=tags or {}
            ) as span:
                try:
                    result = func(*args, **kwargs)
                    span.tags["function.result_type"] = type(result).__name__
                    return result
                except Exception as e:
                    span.tags["function.exception"] = str(e)
                    raise
        
        return wrapper
    return decorator

# Global tracing service instance
tracing_service = DistributedTracingService()

def get_tracing_service() -> DistributedTracingService:
    """Get the global tracing service instance"""
    return tracing_service

# Propagator for cross-service context
propagator = TraceContextTextMapPropagator()

def inject_trace_context(headers: Dict[str, str]) -> Dict[str, str]:
    """Inject trace context into HTTP headers"""
    propagator.inject(headers)
    return headers

def extract_trace_context(headers: Dict[str, str]) -> context.Context:
    """Extract trace context from HTTP headers"""
    return propagator.extract(headers)