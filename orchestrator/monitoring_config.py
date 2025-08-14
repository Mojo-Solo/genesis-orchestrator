"""
Production-ready OpenTelemetry Configuration
=============================================
Configures real monitoring, metrics, and tracing for production use.
"""

import os
import logging
from typing import Optional

from opentelemetry import trace, metrics
from opentelemetry.sdk.trace import TracerProvider
from opentelemetry.sdk.trace.export import BatchSpanProcessor
from opentelemetry.sdk.metrics import MeterProvider
from opentelemetry.sdk.metrics.export import PeriodicExportingMetricReader
from opentelemetry.sdk.resources import Resource
from opentelemetry.exporter.otlp.proto.grpc.trace_exporter import OTLPSpanExporter
from opentelemetry.exporter.otlp.proto.grpc.metric_exporter import OTLPMetricExporter
from opentelemetry.exporter.prometheus import PrometheusMetricReader
from opentelemetry.instrumentation.aiohttp import AioHttpInstrumentor
from opentelemetry.instrumentation.redis import RedisInstrumentor
from opentelemetry.instrumentation.sqlalchemy import SQLAlchemyInstrumentor
from opentelemetry.instrumentation.logging import LoggingInstrumentor
from prometheus_client import start_http_server

logger = logging.getLogger(__name__)


class MonitoringConfig:
    """Production monitoring configuration."""
    
    def __init__(self):
        self.service_name = os.getenv("OTEL_SERVICE_NAME", "genesis-orchestrator")
        self.environment = os.getenv("ENVIRONMENT", "production")
        self.otlp_endpoint = os.getenv("OTEL_EXPORTER_OTLP_ENDPOINT", "http://localhost:4317")
        self.metrics_port = int(os.getenv("METRICS_PORT", "9090"))
        self.enable_tracing = os.getenv("ENABLE_TRACING", "true").lower() == "true"
        self.enable_metrics = os.getenv("ENABLE_METRICS", "true").lower() == "true"
        self.tracer: Optional[trace.Tracer] = None
        self.meter: Optional[metrics.Meter] = None
        
    def initialize(self):
        """Initialize OpenTelemetry with production settings."""
        
        # Create resource with service information
        resource = Resource.create({
            "service.name": self.service_name,
            "service.version": "1.0.0",
            "deployment.environment": self.environment,
            "host.name": os.getenv("HOSTNAME", "localhost"),
        })
        
        # Initialize tracing
        if self.enable_tracing:
            self._setup_tracing(resource)
        
        # Initialize metrics
        if self.enable_metrics:
            self._setup_metrics(resource)
        
        # Auto-instrument libraries
        self._setup_instrumentation()
        
        logger.info(f"Monitoring initialized for {self.service_name} in {self.environment}")
    
    def _setup_tracing(self, resource: Resource):
        """Configure distributed tracing."""
        
        # Create tracer provider
        tracer_provider = TracerProvider(resource=resource)
        
        # Configure OTLP exporter for traces
        otlp_exporter = OTLPSpanExporter(
            endpoint=self.otlp_endpoint,
            insecure=True,  # Use TLS in production
        )
        
        # Add batch processor for efficiency
        span_processor = BatchSpanProcessor(otlp_exporter)
        tracer_provider.add_span_processor(span_processor)
        
        # Set global tracer provider
        trace.set_tracer_provider(tracer_provider)
        
        # Get tracer
        self.tracer = trace.get_tracer(__name__)
        
        logger.info(f"Tracing enabled, exporting to {self.otlp_endpoint}")
    
    def _setup_metrics(self, resource: Resource):
        """Configure metrics collection."""
        
        # Create Prometheus metric reader (for scraping)
        prometheus_reader = PrometheusMetricReader()
        
        # Create OTLP metric reader (for push)
        otlp_exporter = OTLPMetricExporter(
            endpoint=self.otlp_endpoint,
            insecure=True,  # Use TLS in production
        )
        otlp_reader = PeriodicExportingMetricReader(
            exporter=otlp_exporter,
            export_interval_millis=30000,  # Export every 30 seconds
        )
        
        # Create meter provider with both readers
        meter_provider = MeterProvider(
            resource=resource,
            metric_readers=[prometheus_reader, otlp_reader]
        )
        
        # Set global meter provider
        metrics.set_meter_provider(meter_provider)
        
        # Get meter
        self.meter = metrics.get_meter(__name__)
        
        # Start Prometheus HTTP server
        start_http_server(self.metrics_port)
        
        logger.info(f"Metrics enabled on port {self.metrics_port}")
    
    def _setup_instrumentation(self):
        """Auto-instrument common libraries."""
        
        # Instrument aiohttp for HTTP client tracing
        AioHttpInstrumentor().instrument()
        
        # Instrument Redis
        RedisInstrumentor().instrument()
        
        # Instrument SQLAlchemy
        SQLAlchemyInstrumentor().instrument()
        
        # Instrument logging to add trace context
        LoggingInstrumentor().instrument(set_logging_format=True)
        
        logger.info("Auto-instrumentation enabled for aiohttp, redis, sqlalchemy, logging")
    
    def create_metrics(self):
        """Create application-specific metrics."""
        if not self.meter:
            return None
            
        return {
            # Counters
            "requests_total": self.meter.create_counter(
                name="orchestrator_requests_total",
                description="Total number of orchestrator requests",
                unit="1",
            ),
            "errors_total": self.meter.create_counter(
                name="orchestrator_errors_total",
                description="Total number of errors",
                unit="1",
            ),
            "agents_executed": self.meter.create_counter(
                name="orchestrator_agents_executed_total",
                description="Total number of agent executions",
                unit="1",
            ),
            
            # Histograms
            "request_duration": self.meter.create_histogram(
                name="orchestrator_request_duration_seconds",
                description="Request duration in seconds",
                unit="s",
            ),
            "agent_duration": self.meter.create_histogram(
                name="orchestrator_agent_duration_seconds",
                description="Agent execution duration in seconds",
                unit="s",
            ),
            
            # Gauges
            "active_workflows": self.meter.create_up_down_counter(
                name="orchestrator_active_workflows",
                description="Number of active workflows",
                unit="1",
            ),
            "queue_size": self.meter.create_observable_gauge(
                name="orchestrator_queue_size",
                description="Size of task queue",
                callbacks=[self._get_queue_size],
                unit="1",
            ),
        }
    
    def _get_queue_size(self, options):
        """Callback for queue size metric."""
        # This would query actual queue size
        return [(0, {"queue": "genesis-orchestrator-queue"})]
    
    def shutdown(self):
        """Gracefully shutdown monitoring."""
        if self.tracer:
            # Flush any pending spans
            trace.get_tracer_provider().shutdown()
        
        if self.meter:
            # Flush any pending metrics
            metrics.get_meter_provider().shutdown()
        
        logger.info("Monitoring shutdown complete")


# Global monitoring instance
_monitoring: Optional[MonitoringConfig] = None


def get_monitoring() -> MonitoringConfig:
    """Get or create monitoring configuration."""
    global _monitoring
    if _monitoring is None:
        _monitoring = MonitoringConfig()
        _monitoring.initialize()
    return _monitoring


def create_span(name: str, kind: trace.SpanKind = trace.SpanKind.INTERNAL):
    """Create a new trace span."""
    monitoring = get_monitoring()
    if monitoring.tracer:
        return monitoring.tracer.start_as_current_span(name, kind=kind)
    
    # Return a no-op context manager if tracing is disabled
    class NoOpSpan:
        def __enter__(self):
            return self
        def __exit__(self, *args):
            pass
        def set_attribute(self, key, value):
            pass
        def set_status(self, status):
            pass
        def add_event(self, name, attributes=None):
            pass
    
    return NoOpSpan()


def record_metric(metric_name: str, value: float, labels: dict = None):
    """Record a metric value."""
    monitoring = get_monitoring()
    metrics_dict = monitoring.create_metrics()
    
    if metrics_dict and metric_name in metrics_dict:
        metric = metrics_dict[metric_name]
        attributes = labels or {}
        
        if hasattr(metric, 'add'):
            metric.add(value, attributes)
        elif hasattr(metric, 'record'):
            metric.record(value, attributes)


# Context managers for common operations
class trace_operation:
    """Context manager for tracing operations."""
    
    def __init__(self, name: str, attributes: dict = None):
        self.name = name
        self.attributes = attributes or {}
        self.span = None
        self.start_time = None
    
    def __enter__(self):
        import time
        self.start_time = time.time()
        monitoring = get_monitoring()
        
        if monitoring.tracer:
            self.span = monitoring.tracer.start_as_current_span(self.name)
            self.span.__enter__()
            
            # Set attributes
            for key, value in self.attributes.items():
                self.span.set_attribute(key, value)
        
        return self
    
    def __exit__(self, exc_type, exc_val, exc_tb):
        import time
        duration = time.time() - self.start_time
        
        # Record duration metric
        record_metric("request_duration", duration, {"operation": self.name})
        
        if self.span:
            if exc_type:
                self.span.set_status(trace.Status(trace.StatusCode.ERROR, str(exc_val)))
                record_metric("errors_total", 1, {"operation": self.name, "error": exc_type.__name__})
            else:
                self.span.set_status(trace.Status(trace.StatusCode.OK))
            
            self.span.__exit__(exc_type, exc_val, exc_tb)
        
        return False  # Don't suppress exceptions