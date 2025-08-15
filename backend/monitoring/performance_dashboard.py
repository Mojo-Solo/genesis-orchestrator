"""
GENESIS Orchestrator - Performance Analytics Dashboard
Real-time metrics visualization and performance monitoring interface
"""

import os
import json
import time
from typing import Dict, Any, List, Optional, Tuple
from dataclasses import dataclass, asdict
from datetime import datetime, timedelta
from flask import Flask, render_template, jsonify, request, send_file
from flask_socketio import SocketIO, emit
import threading
import logging
from pathlib import Path
import base64
from io import BytesIO

import numpy as np
import pandas as pd
import plotly.graph_objs as go
import plotly.express as px
from plotly.utils import PlotlyJSONEncoder
import plotly

# Import our monitoring services
from backend.monitoring.genesis_monitor import get_monitor
from backend.monitoring.apm_service import get_apm_service
from backend.monitoring.distributed_tracing import get_tracing_service
from backend.monitoring.performance_regression import get_regression_detector
from backend.monitoring.bottleneck_detection import get_bottleneck_detector
from backend.monitoring.ai_optimization_engine import get_optimization_engine
from backend.monitoring.load_testing_automation import get_load_test_engine

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

@dataclass
class DashboardMetrics:
    """Dashboard metrics snapshot"""
    timestamp: float
    orchestration_metrics: Dict[str, Any]
    apm_metrics: Dict[str, Any]
    tracing_metrics: Dict[str, Any]
    regression_metrics: Dict[str, Any]
    bottleneck_metrics: Dict[str, Any]
    optimization_metrics: Dict[str, Any]
    load_test_metrics: Dict[str, Any]

class PerformanceDashboard:
    """Real-time performance analytics dashboard"""
    
    def __init__(self, host: str = "0.0.0.0", port: int = 5000):
        self.app = Flask(__name__, template_folder='templates', static_folder='static')
        self.app.config['SECRET_KEY'] = 'genesis_dashboard_secret'
        self.socketio = SocketIO(self.app, cors_allowed_origins="*")
        
        self.host = host
        self.port = port
        
        # Monitoring services
        self.monitor = get_monitor()
        self.apm_service = get_apm_service()
        self.tracing_service = get_tracing_service()
        self.regression_detector = get_regression_detector()
        self.bottleneck_detector = get_bottleneck_detector()
        self.optimization_engine = get_optimization_engine()
        self.load_test_engine = get_load_test_engine()
        
        # Dashboard state
        self.metrics_history: List[DashboardMetrics] = []
        self.max_history_size = 1000
        self.update_interval = 5  # seconds
        
        # Real-time update thread
        self.updating = False
        self.update_thread = None
        
        # Setup routes
        self._setup_routes()
        
        logger.info(f"Performance dashboard initialized on {host}:{port}")
    
    def _setup_routes(self):
        """Setup Flask routes"""
        
        @self.app.route('/')
        def index():
            """Main dashboard page"""
            return render_template('dashboard.html')
        
        @self.app.route('/api/metrics/current')
        def current_metrics():
            """Get current performance metrics"""
            metrics = self._collect_current_metrics()
            return jsonify(asdict(metrics))
        
        @self.app.route('/api/metrics/history')
        def metrics_history():
            """Get metrics history"""
            hours = request.args.get('hours', 24, type=int)
            limit = request.args.get('limit', 100, type=int)
            
            cutoff_time = time.time() - (hours * 3600)
            recent_metrics = [
                m for m in self.metrics_history
                if m.timestamp > cutoff_time
            ][-limit:]
            
            return jsonify([asdict(m) for m in recent_metrics])
        
        @self.app.route('/api/charts/performance_overview')
        def performance_overview_chart():
            """Generate performance overview chart"""
            chart_data = self._generate_performance_overview_chart()
            return jsonify(chart_data)
        
        @self.app.route('/api/charts/response_times')
        def response_times_chart():
            """Generate response times chart"""
            chart_data = self._generate_response_times_chart()
            return jsonify(chart_data)
        
        @self.app.route('/api/charts/system_resources')
        def system_resources_chart():
            """Generate system resources chart"""
            chart_data = self._generate_system_resources_chart()
            return jsonify(chart_data)
        
        @self.app.route('/api/charts/bottlenecks')
        def bottlenecks_chart():
            """Generate bottlenecks analysis chart"""
            chart_data = self._generate_bottlenecks_chart()
            return jsonify(chart_data)
        
        @self.app.route('/api/charts/load_test_results')
        def load_test_results_chart():
            """Generate load test results chart"""
            chart_data = self._generate_load_test_results_chart()
            return jsonify(chart_data)
        
        @self.app.route('/api/traces/recent')
        def recent_traces():
            """Get recent distributed traces"""
            limit = request.args.get('limit', 50, type=int)
            traces = self.tracing_service.get_recent_traces(limit)
            
            trace_data = []
            for trace in traces:
                trace_data.append({
                    'trace_id': trace.trace_id,
                    'start_time': trace.start_time,
                    'duration_ms': trace.total_duration_ms,
                    'services': trace.services_involved,
                    'error_count': trace.error_count,
                    'span_count': len(trace.spans)
                })\n            \n            return jsonify(trace_data)
        
        @self.app.route('/api/traces/<trace_id>')
        def trace_details(trace_id):
            """Get detailed trace information"""
            trace = self.tracing_service.get_trace_by_id(trace_id)
            if trace:
                return jsonify(asdict(trace))
            return jsonify({'error': 'Trace not found'}), 404
        
        @self.app.route('/api/bottlenecks/current')
        def current_bottlenecks():
            """Get current active bottlenecks"""
            bottlenecks = self.bottleneck_detector.get_current_bottlenecks(active_only=True)
            return jsonify([asdict(b) for b in bottlenecks])
        
        @self.app.route('/api/bottlenecks/summary')
        def bottlenecks_summary():
            """Get bottlenecks summary"""
            hours = request.args.get('hours', 24, type=int)
            summary = self.bottleneck_detector.get_bottleneck_summary(hours)
            return jsonify(summary)
        
        @self.app.route('/api/optimization/recommendations')
        def optimization_recommendations():
            """Get AI-generated optimization recommendations"""
            priority = request.args.get('priority')
            
            if priority:
                from backend.monitoring.ai_optimization_engine import OptimizationPriority
                try:
                    priority_enum = OptimizationPriority(priority.lower())
                    recommendations = self.optimization_engine.get_recommendations_by_priority(priority_enum)
                except ValueError:
                    return jsonify({'error': 'Invalid priority level'}), 400
            else:
                recommendations = self.optimization_engine.recommendation_history[-20:]  # Last 20
            
            return jsonify([asdict(r) for r in recommendations])
        
        @self.app.route('/api/regression/alerts')
        def regression_alerts():
            """Get performance regression alerts"""
            severity = request.args.get('severity')
            alerts = self.regression_detector.get_regression_alerts(severity)
            return jsonify([asdict(a) for a in alerts])
        
        @self.app.route('/api/regression/summary')
        def regression_summary():
            """Get performance regression summary"""
            summary = self.regression_detector.get_performance_summary()
            return jsonify(summary)
        
        @self.app.route('/api/load_tests/results')
        def load_test_results():
            """Get load test results"""
            test_ids = self.load_test_engine.list_test_results()
            results = []
            
            for test_id in test_ids[-10:]:  # Last 10 tests
                result = self.load_test_engine.get_test_result(test_id)
                if result:
                    results.append({
                        'test_id': result.config.test_id,
                        'test_name': result.config.test_name,
                        'test_type': result.config.test_type.value,
                        'success': result.success,
                        'start_time': result.metrics.start_time,
                        'duration_minutes': result.config.duration_minutes,
                        'virtual_users': result.config.virtual_users,
                        'avg_response_time_ms': result.metrics.avg_response_time_ms,
                        'error_rate': result.metrics.error_rate,
                        'requests_per_second': result.metrics.requests_per_second
                    })
            
            return jsonify(results)
        
        # WebSocket events
        @self.socketio.on('connect')
        def handle_connect():
            """Handle client connection"""
            logger.info("Client connected to dashboard")
            emit('status', {'msg': 'Connected to GENESIS Performance Dashboard'})
        
        @self.socketio.on('disconnect')
        def handle_disconnect():
            """Handle client disconnect"""
            logger.info("Client disconnected from dashboard")
        
        @self.socketio.on('request_update')
        def handle_update_request():
            """Handle real-time update request"""
            metrics = self._collect_current_metrics()
            emit('metrics_update', asdict(metrics))
    
    def _collect_current_metrics(self) -> DashboardMetrics:
        """Collect current metrics from all monitoring services"""
        
        timestamp = time.time()
        
        # Orchestration metrics
        orchestration_metrics = self.monitor.get_aggregated_metrics()
        
        # APM metrics
        apm_report = self.apm_service.get_performance_report()
        apm_metrics = {
            'monitoring_duration_hours': apm_report.get('monitoring_duration_hours', 0),
            'cpu_stats': apm_report.get('cpu_stats', {}),
            'io_stats': apm_report.get('io_stats', {}),
            'performance_issues_count': len(apm_report.get('performance_issues', [])),
            'performance_score': apm_report.get('system_performance', {}).get('performance_score', 0)
        }
        
        # Distributed tracing metrics
        trace_analysis = self.tracing_service.analyze_trace_patterns()
        tracing_metrics = {
            'total_traces': trace_analysis.get('total_traces', 0),
            'avg_duration_ms': trace_analysis.get('avg_duration_ms', 0),
            'p95_duration_ms': trace_analysis.get('p95_duration_ms', 0),
            'avg_error_rate': trace_analysis.get('avg_error_rate', 0),
            'services_involved': trace_analysis.get('services_involved', 0)
        }
        
        # Regression detection metrics
        regression_summary = self.regression_detector.get_performance_summary()
        regression_metrics = {
            'total_tests': regression_summary.get('total_tests', 0),
            'success_rate': regression_summary.get('success_rate', 100),
            'recent_alerts': regression_summary.get('recent_alerts', 0),
            'critical_alerts': regression_summary.get('critical_alerts', 0)
        }
        
        # Bottleneck detection metrics
        bottleneck_summary = self.bottleneck_detector.get_bottleneck_summary(hours=1)
        bottleneck_metrics = {
            'total_bottlenecks': bottleneck_summary.get('total_bottlenecks', 0),
            'active_bottlenecks': bottleneck_summary.get('active_bottlenecks', 0),
            'most_common_type': bottleneck_summary.get('most_common_type'),
            'avg_impact_score': bottleneck_summary.get('avg_impact_score', 0)
        }
        
        # Optimization engine metrics
        optimization_metrics = {
            'total_recommendations': len(self.optimization_engine.recommendation_history),
            'high_priority_recommendations': len(
                self.optimization_engine.get_recommendations_by_priority(
                    self.optimization_engine.default_configs.get('HIGH', [])
                )
            ) if hasattr(self.optimization_engine, 'default_configs') else 0
        }
        
        # Load testing metrics
        load_test_results = self.load_test_engine.list_test_results()
        load_test_metrics = {
            'total_tests': len(load_test_results),
            'recent_tests': len([
                test_id for test_id in load_test_results
                if self.load_test_engine.get_test_result(test_id) and
                self.load_test_engine.get_test_result(test_id).metrics.start_time > time.time() - 86400
            ])
        }
        
        metrics = DashboardMetrics(
            timestamp=timestamp,
            orchestration_metrics=orchestration_metrics,
            apm_metrics=apm_metrics,
            tracing_metrics=tracing_metrics,
            regression_metrics=regression_metrics,
            bottleneck_metrics=bottleneck_metrics,
            optimization_metrics=optimization_metrics,
            load_test_metrics=load_test_metrics
        )
        
        # Store in history
        self.metrics_history.append(metrics)
        if len(self.metrics_history) > self.max_history_size:
            self.metrics_history = self.metrics_history[-self.max_history_size:]
        
        return metrics
    
    def _generate_performance_overview_chart(self) -> Dict[str, Any]:
        """Generate performance overview chart"""
        
        if len(self.metrics_history) < 2:
            return {'data': [], 'layout': {'title': 'Performance Overview - Insufficient Data'}}
        
        recent_metrics = self.metrics_history[-50:]  # Last 50 data points
        
        timestamps = [datetime.fromtimestamp(m.timestamp) for m in recent_metrics]
        
        # Response times from orchestration metrics
        avg_latencies = [m.orchestration_metrics.get('avg_latency_ms', 0) for m in recent_metrics]
        
        # Success rates
        success_rates = []
        for m in recent_metrics:
            total_runs = m.orchestration_metrics.get('total_runs', 1)
            successful_runs = m.orchestration_metrics.get('successful_runs', 0)
            success_rate = (successful_runs / total_runs) * 100 if total_runs > 0 else 100
            success_rates.append(success_rate)
        
        # Performance scores from APM
        performance_scores = [m.apm_metrics.get('performance_score', 0) for m in recent_metrics]
        
        fig = go.Figure()
        
        fig.add_trace(go.Scatter(
            x=timestamps,
            y=avg_latencies,
            mode='lines+markers',
            name='Avg Response Time (ms)',
            yaxis='y1'
        ))
        
        fig.add_trace(go.Scatter(
            x=timestamps,
            y=success_rates,
            mode='lines+markers',
            name='Success Rate (%)',
            yaxis='y2'
        ))
        
        fig.add_trace(go.Scatter(
            x=timestamps,
            y=performance_scores,
            mode='lines+markers',
            name='Performance Score',
            yaxis='y2'
        ))
        
        fig.update_layout(
            title='Performance Overview',
            xaxis=dict(title='Time'),
            yaxis=dict(title='Response Time (ms)', side='left'),
            yaxis2=dict(title='Percentage / Score', side='right', overlaying='y'),
            hovermode='x unified',
            template='plotly_white'
        )
        
        return json.loads(json.dumps(fig, cls=PlotlyJSONEncoder))
    
    def _generate_response_times_chart(self) -> Dict[str, Any]:
        """Generate response times distribution chart"""
        
        # Get recent performance data
        apm_report = self.apm_service.get_performance_report()
        function_profiles = apm_report.get('function_profiles', {})
        
        if not function_profiles:
            return {'data': [], 'layout': {'title': 'Response Times - No Data Available'}}
        
        # Extract response time data
        function_names = []
        avg_times = []
        max_times = []
        call_counts = []
        
        for func_name, profile in list(function_profiles.items())[:20]:  # Top 20 functions
            function_names.append(func_name.split('.')[-1])  # Just function name
            avg_times.append(profile.get('avg_time', 0) * 1000)  # Convert to ms
            max_times.append(profile.get('max_time', 0) * 1000)
            call_counts.append(profile.get('call_count', 0))
        
        fig = go.Figure()
        
        fig.add_trace(go.Bar(
            x=function_names,
            y=avg_times,
            name='Average Time (ms)',
            marker_color='lightblue'
        ))
        
        fig.add_trace(go.Bar(
            x=function_names,
            y=max_times,
            name='Maximum Time (ms)',
            marker_color='coral'
        ))
        
        fig.update_layout(
            title='Function Response Times',
            xaxis=dict(title='Function', tickangle=45),
            yaxis=dict(title='Response Time (ms)'),
            barmode='group',
            template='plotly_white'
        )
        
        return json.loads(json.dumps(fig, cls=PlotlyJSONEncoder))
    
    def _generate_system_resources_chart(self) -> Dict[str, Any]:
        """Generate system resources utilization chart"""
        
        if not hasattr(self.apm_service, 'performance_snapshots') or not self.apm_service.performance_snapshots:
            return {'data': [], 'layout': {'title': 'System Resources - No Data Available'}}
        
        recent_snapshots = self.apm_service.performance_snapshots[-50:]  # Last 50 snapshots
        
        timestamps = [datetime.fromtimestamp(s.timestamp) for s in recent_snapshots]
        cpu_values = [s.cpu_percent for s in recent_snapshots]
        memory_values = [s.memory_rss_mb for s in recent_snapshots]
        
        fig = go.Figure()
        
        fig.add_trace(go.Scatter(
            x=timestamps,
            y=cpu_values,
            mode='lines+markers',
            name='CPU Usage (%)',
            yaxis='y1',
            line=dict(color='red')
        ))
        
        fig.add_trace(go.Scatter(
            x=timestamps,
            y=memory_values,
            mode='lines+markers',
            name='Memory Usage (MB)',
            yaxis='y2',
            line=dict(color='blue')
        ))
        
        fig.update_layout(
            title='System Resource Utilization',
            xaxis=dict(title='Time'),
            yaxis=dict(title='CPU Usage (%)', side='left'),
            yaxis2=dict(title='Memory Usage (MB)', side='right', overlaying='y'),
            hovermode='x unified',
            template='plotly_white'
        )
        
        return json.loads(json.dumps(fig, cls=PlotlyJSONEncoder))
    
    def _generate_bottlenecks_chart(self) -> Dict[str, Any]:
        """Generate bottlenecks analysis chart"""
        
        bottlenecks = self.bottleneck_detector.get_current_bottlenecks(active_only=False)
        
        if not bottlenecks:
            return {'data': [], 'layout': {'title': 'Bottlenecks Analysis - No Bottlenecks Detected'}}
        
        # Aggregate by type
        bottleneck_counts = {}
        impact_scores = {}
        
        for bottleneck in bottlenecks:
            btype = bottleneck.bottleneck_type.value
            if btype not in bottleneck_counts:
                bottleneck_counts[btype] = 0
                impact_scores[btype] = []
            
            bottleneck_counts[btype] += 1
            impact_scores[btype].append(bottleneck.impact_score)
        
        # Calculate average impact scores
        avg_impact_scores = {
            btype: np.mean(scores) 
            for btype, scores in impact_scores.items()
        }
        
        types = list(bottleneck_counts.keys())
        counts = list(bottleneck_counts.values())
        impacts = [avg_impact_scores[t] for t in types]
        
        fig = go.Figure()
        
        fig.add_trace(go.Bar(
            x=types,
            y=counts,
            name='Bottleneck Count',
            marker_color='lightcoral',
            yaxis='y1'
        ))
        
        fig.add_trace(go.Scatter(
            x=types,
            y=impacts,
            mode='markers+lines',
            name='Average Impact Score',
            marker=dict(color='darkblue', size=10),
            yaxis='y2'
        ))
        
        fig.update_layout(
            title='Bottlenecks Analysis',
            xaxis=dict(title='Bottleneck Type', tickangle=45),
            yaxis=dict(title='Count', side='left'),
            yaxis2=dict(title='Impact Score', side='right', overlaying='y'),
            template='plotly_white'
        )
        
        return json.loads(json.dumps(fig, cls=PlotlyJSONEncoder))
    
    def _generate_load_test_results_chart(self) -> Dict[str, Any]:
        """Generate load test results chart"""
        
        test_ids = self.load_test_engine.list_test_results()
        
        if not test_ids:
            return {'data': [], 'layout': {'title': 'Load Test Results - No Tests Available'}}
        
        recent_tests = test_ids[-10:]  # Last 10 tests
        test_names = []
        response_times = []
        error_rates = []
        throughputs = []
        
        for test_id in recent_tests:
            result = self.load_test_engine.get_test_result(test_id)
            if result:
                test_names.append(result.config.test_name)
                response_times.append(result.metrics.avg_response_time_ms)
                error_rates.append(result.metrics.error_rate * 100)  # Convert to percentage
                throughputs.append(result.metrics.requests_per_second)
        
        if not test_names:
            return {'data': [], 'layout': {'title': 'Load Test Results - No Data Available'}}
        
        fig = go.Figure()
        
        fig.add_trace(go.Bar(
            x=test_names,
            y=response_times,
            name='Avg Response Time (ms)',
            marker_color='lightblue',
            yaxis='y1'
        ))
        
        fig.add_trace(go.Scatter(
            x=test_names,
            y=error_rates,
            mode='markers+lines',
            name='Error Rate (%)',
            marker=dict(color='red', size=8),
            yaxis='y2'
        ))
        
        fig.add_trace(go.Scatter(
            x=test_names,
            y=throughputs,
            mode='markers+lines',
            name='Throughput (RPS)',
            marker=dict(color='green', size=8),
            yaxis='y3'
        ))
        
        fig.update_layout(
            title='Load Test Results',
            xaxis=dict(title='Test Name', tickangle=45),
            yaxis=dict(title='Response Time (ms)', side='left'),
            yaxis2=dict(title='Error Rate (%)', side='right', overlaying='y'),
            yaxis3=dict(title='Throughput (RPS)', side='right', overlaying='y', position=0.9),
            template='plotly_white'
        )
        
        return json.loads(json.dumps(fig, cls=PlotlyJSONEncoder))
    
    def start_real_time_updates(self):
        """Start real-time metrics updates"""
        if not self.updating:
            self.updating = True
            self.update_thread = threading.Thread(target=self._update_loop, daemon=True)
            self.update_thread.start()
            logger.info("Real-time updates started")
    
    def stop_real_time_updates(self):
        """Stop real-time metrics updates"""
        if self.updating:
            self.updating = False
            if self.update_thread:
                self.update_thread.join(timeout=2.0)
            logger.info("Real-time updates stopped")
    
    def _update_loop(self):
        """Real-time update loop"""
        while self.updating:
            try:
                # Collect current metrics
                metrics = self._collect_current_metrics()
                
                # Emit to connected clients
                self.socketio.emit('metrics_update', asdict(metrics))
                
                time.sleep(self.update_interval)
                
            except Exception as e:
                logger.error(f"Update loop error: {e}")
                time.sleep(self.update_interval)
    
    def run(self, debug: bool = False):
        """Run the dashboard server"""
        logger.info(f"Starting performance dashboard on {self.host}:{self.port}")
        
        # Start real-time updates
        self.start_real_time_updates()
        
        try:
            self.socketio.run(
                self.app,
                host=self.host,
                port=self.port,
                debug=debug,
                allow_unsafe_werkzeug=True
            )
        finally:
            self.stop_real_time_updates()
    
    def create_dashboard_template(self):
        """Create HTML template for dashboard"""
        
        templates_dir = Path('templates')
        templates_dir.mkdir(exist_ok=True)
        
        template_content = '''<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GENESIS Performance Dashboard</title>
    <script src="https://cdn.plot.ly/plotly-latest.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/socket.io/4.0.1/socket.io.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .metric-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin: 10px 0;
        }
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin: 10px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        .status-green { background-color: #28a745; }
        .status-yellow { background-color: #ffc107; }
        .status-red { background-color: #dc3545; }
        .navbar-brand { font-weight: bold; }
        .sidebar {
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
            padding-top: 20px;
            background-color: #f8f9fa;
        }
        .main-content {
            margin-left: 250px;
            padding: 20px;
        }
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-primary">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">
                🚀 GENESIS Performance Dashboard
                <span id="connection-status" class="status-indicator status-green"></span>
                <span id="last-update" class="small text-light ms-3"></span>
            </span>
        </div>
    </nav>

    <div class="sidebar bg-light">
        <div class="list-group list-group-flush">
            <a href="#overview" class="list-group-item list-group-item-action">📊 Overview</a>
            <a href="#performance" class="list-group-item list-group-item-action">⚡ Performance</a>
            <a href="#traces" class="list-group-item list-group-item-action">🔍 Traces</a>
            <a href="#bottlenecks" class="list-group-item list-group-item-action">🔧 Bottlenecks</a>
            <a href="#optimization" class="list-group-item list-group-item-action">🤖 AI Optimization</a>
            <a href="#load-tests" class="list-group-item list-group-item-action">📈 Load Tests</a>
        </div>
    </div>

    <div class="main-content">
        <!-- Key Metrics Row -->
        <div class="row">
            <div class="col-md-3">
                <div class="metric-card">
                    <h5>Total Runs</h5>
                    <h2 id="total-runs">-</h2>
                    <small>Orchestration executions</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card">
                    <h5>Success Rate</h5>
                    <h2 id="success-rate">-</h2>
                    <small>Successful completions</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card">
                    <h5>Avg Latency</h5>
                    <h2 id="avg-latency">-</h2>
                    <small>Response time (ms)</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card">
                    <h5>Active Issues</h5>
                    <h2 id="active-issues">-</h2>
                    <small>Performance bottlenecks</small>
                </div>
            </div>
        </div>

        <!-- Performance Overview Chart -->
        <div class="row">
            <div class="col-12">
                <div class="chart-container">
                    <h4>Performance Overview</h4>
                    <div id="performance-overview-chart" style="height: 400px;"></div>
                </div>
            </div>
        </div>

        <!-- System Resources and Response Times -->
        <div class="row">
            <div class="col-md-6">
                <div class="chart-container">
                    <h4>System Resources</h4>
                    <div id="system-resources-chart" style="height: 300px;"></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="chart-container">
                    <h4>Response Times</h4>
                    <div id="response-times-chart" style="height: 300px;"></div>
                </div>
            </div>
        </div>

        <!-- Bottlenecks and Load Test Results -->
        <div class="row">
            <div class="col-md-6">
                <div class="chart-container">
                    <h4>Bottlenecks Analysis</h4>
                    <div id="bottlenecks-chart" style="height: 300px;"></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="chart-container">
                    <h4>Load Test Results</h4>
                    <div id="load-test-chart" style="height: 300px;"></div>
                </div>
            </div>
        </div>

        <!-- Recent Traces Table -->
        <div class="row">
            <div class="col-12">
                <div class="chart-container">
                    <h4>Recent Distributed Traces</h4>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Trace ID</th>
                                    <th>Duration</th>
                                    <th>Services</th>
                                    <th>Spans</th>
                                    <th>Errors</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody id="traces-table-body">
                                <tr><td colspan="6">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- AI Optimization Recommendations -->
        <div class="row">
            <div class="col-12">
                <div class="chart-container">
                    <h4>AI Optimization Recommendations</h4>
                    <div id="recommendations-list">
                        <div class="text-center">Loading recommendations...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize Socket.IO connection
        const socket = io();
        
        socket.on('connect', function() {
            console.log('Connected to dashboard');
            document.getElementById('connection-status').className = 'status-indicator status-green';
        });
        
        socket.on('disconnect', function() {
            console.log('Disconnected from dashboard');
            document.getElementById('connection-status').className = 'status-indicator status-red';
        });
        
        socket.on('metrics_update', function(data) {
            updateMetrics(data);
            updateLastUpdateTime();
        });
        
        function updateMetrics(metrics) {
            // Update key metric cards
            const orchestration = metrics.orchestration_metrics || {};
            document.getElementById('total-runs').textContent = orchestration.total_runs || 0;
            
            const successRate = orchestration.total_runs > 0 ? 
                ((orchestration.successful_runs / orchestration.total_runs) * 100).toFixed(1) + '%' : '100%';
            document.getElementById('success-rate').textContent = successRate;
            
            const avgLatency = orchestration.avg_latency_ms ? 
                orchestration.avg_latency_ms.toFixed(0) + 'ms' : '0ms';
            document.getElementById('avg-latency').textContent = avgLatency;
            
            const activeIssues = (metrics.bottleneck_metrics?.active_bottlenecks || 0) + 
                               (metrics.regression_metrics?.critical_alerts || 0);
            document.getElementById('active-issues').textContent = activeIssues;
        }
        
        function updateLastUpdateTime() {
            const now = new Date();
            document.getElementById('last-update').textContent = 
                'Last update: ' + now.toLocaleTimeString();
        }
        
        function loadCharts() {
            // Load performance overview chart
            fetch('/api/charts/performance_overview')
                .then(response => response.json())
                .then(data => {
                    Plotly.newPlot('performance-overview-chart', data.data, data.layout, {responsive: true});
                });
            
            // Load system resources chart
            fetch('/api/charts/system_resources')
                .then(response => response.json())
                .then(data => {
                    Plotly.newPlot('system-resources-chart', data.data, data.layout, {responsive: true});
                });
            
            // Load response times chart
            fetch('/api/charts/response_times')
                .then(response => response.json())
                .then(data => {
                    Plotly.newPlot('response-times-chart', data.data, data.layout, {responsive: true});
                });
            
            // Load bottlenecks chart
            fetch('/api/charts/bottlenecks')
                .then(response => response.json())
                .then(data => {
                    Plotly.newPlot('bottlenecks-chart', data.data, data.layout, {responsive: true});
                });
            
            // Load load test results chart
            fetch('/api/charts/load_test_results')
                .then(response => response.json())
                .then(data => {
                    Plotly.newPlot('load-test-chart', data.data, data.layout, {responsive: true});
                });
        }
        
        function loadTraces() {
            fetch('/api/traces/recent?limit=10')
                .then(response => response.json())
                .then(traces => {
                    const tbody = document.getElementById('traces-table-body');
                    if (traces.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="6">No traces available</td></tr>';
                        return;
                    }
                    
                    tbody.innerHTML = traces.map(trace => {
                        const time = new Date(trace.start_time * 1000).toLocaleTimeString();
                        const errorClass = trace.error_count > 0 ? 'text-danger' : '';
                        
                        return `
                            <tr>
                                <td><code>${trace.trace_id.substring(0, 8)}...</code></td>
                                <td>${trace.duration_ms ? trace.duration_ms.toFixed(0) + 'ms' : 'N/A'}</td>
                                <td>${trace.services.join(', ')}</td>
                                <td>${trace.span_count}</td>
                                <td class="${errorClass}">${trace.error_count}</td>
                                <td>${time}</td>
                            </tr>
                        `;
                    }).join('');
                });
        }
        
        function loadRecommendations() {
            fetch('/api/optimization/recommendations')
                .then(response => response.json())
                .then(recommendations => {
                    const container = document.getElementById('recommendations-list');
                    if (recommendations.length === 0) {
                        container.innerHTML = '<div class="text-center text-muted">No recommendations available</div>';
                        return;
                    }
                    
                    container.innerHTML = recommendations.slice(0, 5).map(rec => {
                        const priorityClass = {
                            'critical': 'danger',
                            'high': 'warning',
                            'medium': 'info',
                            'low': 'secondary'
                        }[rec.priority] || 'secondary';
                        
                        return `
                            <div class="card mb-3">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <h6 class="card-title">${rec.title}</h6>
                                        <span class="badge bg-${priorityClass}">${rec.priority.toUpperCase()}</span>
                                    </div>
                                    <p class="card-text">${rec.description}</p>
                                    <small class="text-muted">
                                        Impact: ${rec.estimated_impact.toFixed(1)}% | 
                                        Confidence: ${(rec.confidence * 100).toFixed(0)}%
                                    </small>
                                </div>
                            </div>
                        `;
                    }).join('');
                });
        }
        
        // Initialize dashboard
        document.addEventListener('DOMContentLoaded', function() {
            loadCharts();
            loadTraces();
            loadRecommendations();
            
            // Request initial metrics update
            socket.emit('request_update');
            
            // Refresh data periodically
            setInterval(() => {
                loadTraces();
                loadRecommendations();
            }, 30000); // Every 30 seconds
        });
    </script>
</body>
</html>'''
        
        template_file = templates_dir / 'dashboard.html'
        with open(template_file, 'w') as f:
            f.write(template_content)
        
        logger.info(f"Dashboard template created at {template_file}")

# Global dashboard instance
dashboard = PerformanceDashboard()

def get_dashboard() -> PerformanceDashboard:
    """Get the global dashboard instance"""
    return dashboard

def main():
    """Main entry point for running dashboard"""
    dashboard.create_dashboard_template()
    dashboard.run(debug=True)

if __name__ == '__main__':
    main()