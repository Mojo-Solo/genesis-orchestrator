#!/usr/bin/env python3
"""
GENESIS Orchestrator - SLA Monitoring System
============================================
Tracks and reports on SLA compliance for 99.9% uptime target.
"""

import asyncio
import json
import logging
import os
import sys
from datetime import datetime, timedelta
from pathlib import Path
from typing import Dict, List, Optional, Tuple
import aiohttp
import sqlite3
from dataclasses import dataclass, asdict

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


@dataclass
class SLAMetric:
    """SLA metric data point"""
    timestamp: datetime
    service_available: bool
    response_time_ms: float
    error_rate_percent: float
    requests_per_second: float
    
    def to_dict(self) -> Dict:
        return {
            "timestamp": self.timestamp.isoformat(),
            "service_available": self.service_available,
            "response_time_ms": self.response_time_ms,
            "error_rate_percent": self.error_rate_percent,
            "requests_per_second": self.requests_per_second
        }


@dataclass
class SLAReport:
    """SLA compliance report"""
    period_start: datetime
    period_end: datetime
    target_availability_percent: float
    actual_availability_percent: float
    target_response_time_ms: float
    actual_p95_response_time_ms: float
    target_error_rate_percent: float
    actual_error_rate_percent: float
    total_requests: int
    failed_requests: int
    downtime_minutes: float
    sla_breaches: List[Dict]
    error_budget_remaining_percent: float
    
    def is_sla_met(self) -> bool:
        """Check if SLA targets were met"""
        return (
            self.actual_availability_percent >= self.target_availability_percent and
            self.actual_p95_response_time_ms <= self.target_response_time_ms and
            self.actual_error_rate_percent <= self.target_error_rate_percent
        )


class SLAMonitor:
    """SLA monitoring and reporting system"""
    
    def __init__(self, db_path: str = None):
        self.db_path = db_path or "/var/lib/genesis/sla_metrics.db"
        self.prometheus_url = os.getenv("PROMETHEUS_URL", "http://localhost:9090")
        self.sla_targets = {
            "availability_percent": 99.9,
            "response_time_ms": 2000,  # P95 response time
            "error_rate_percent": 1.0
        }
        self._ensure_database()
    
    def _ensure_database(self):
        """Ensure SLA metrics database exists"""
        os.makedirs(os.path.dirname(self.db_path), exist_ok=True)
        
        with sqlite3.connect(self.db_path) as conn:
            conn.execute("""
                CREATE TABLE IF NOT EXISTS sla_metrics (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    timestamp TEXT NOT NULL,
                    service_available INTEGER NOT NULL,
                    response_time_ms REAL NOT NULL,
                    error_rate_percent REAL NOT NULL,
                    requests_per_second REAL NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            """)
            
            conn.execute("""
                CREATE INDEX IF NOT EXISTS idx_sla_metrics_timestamp 
                ON sla_metrics(timestamp)
            """)
            
            conn.execute("""
                CREATE TABLE IF NOT EXISTS sla_breaches (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    breach_type TEXT NOT NULL,
                    start_time TEXT NOT NULL,
                    end_time TEXT,
                    severity TEXT NOT NULL,
                    description TEXT,
                    impact_minutes REAL,
                    resolved INTEGER DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            """)
    
    async def collect_metrics(self) -> SLAMetric:
        """Collect current SLA metrics from monitoring system"""
        async with aiohttp.ClientSession() as session:
            # Query Prometheus for current metrics
            queries = {
                "availability": 'up{job="genesis-orchestrator"}',
                "response_time": 'histogram_quantile(0.95, rate(genesis_orchestrator_request_duration_seconds_bucket[5m])) * 1000',
                "error_rate": 'sum(rate(genesis_orchestrator_failed_runs[5m])) / sum(rate(genesis_orchestrator_total_runs[5m])) * 100',
                "request_rate": 'sum(rate(genesis_orchestrator_total_runs[5m]))'
            }
            
            results = {}
            for metric_name, query in queries.items():
                try:
                    params = {
                        "query": query,
                        "time": datetime.utcnow().isoformat()
                    }
                    
                    async with session.get(
                        f"{self.prometheus_url}/api/v1/query",
                        params=params
                    ) as response:
                        if response.status == 200:
                            data = await response.json()
                            result = data.get("data", {}).get("result", [])
                            if result:
                                value = float(result[0]["value"][1])
                                results[metric_name] = value
                            else:
                                results[metric_name] = 0.0
                        else:
                            logger.error(f"Failed to query {metric_name}: HTTP {response.status}")
                            results[metric_name] = 0.0
                            
                except Exception as e:
                    logger.error(f"Error querying {metric_name}: {str(e)}")
                    results[metric_name] = 0.0
            
            # Create SLA metric
            metric = SLAMetric(
                timestamp=datetime.utcnow(),
                service_available=results.get("availability", 0) > 0,
                response_time_ms=results.get("response_time", 0),
                error_rate_percent=results.get("error_rate", 0),
                requests_per_second=results.get("request_rate", 0)
            )
            
            # Store in database
            self._store_metric(metric)
            
            # Check for SLA breaches
            await self._check_sla_breaches(metric)
            
            return metric
    
    def _store_metric(self, metric: SLAMetric):
        """Store SLA metric in database"""
        with sqlite3.connect(self.db_path) as conn:
            conn.execute("""
                INSERT INTO sla_metrics (
                    timestamp, service_available, response_time_ms, 
                    error_rate_percent, requests_per_second
                ) VALUES (?, ?, ?, ?, ?)
            """, (
                metric.timestamp.isoformat(),
                1 if metric.service_available else 0,
                metric.response_time_ms,
                metric.error_rate_percent,
                metric.requests_per_second
            ))
    
    async def _check_sla_breaches(self, metric: SLAMetric):
        """Check for SLA breaches and record them"""
        breaches = []
        
        # Check availability breach
        if not metric.service_available:
            breaches.append({
                "type": "availability",
                "severity": "critical",
                "description": "Service unavailable",
                "current_value": 0,
                "threshold": 1
            })
        
        # Check response time breach
        if metric.response_time_ms > self.sla_targets["response_time_ms"]:
            severity = "critical" if metric.response_time_ms > self.sla_targets["response_time_ms"] * 2 else "warning"
            breaches.append({
                "type": "response_time",
                "severity": severity,
                "description": f"Response time {metric.response_time_ms:.0f}ms exceeds {self.sla_targets['response_time_ms']:.0f}ms",
                "current_value": metric.response_time_ms,
                "threshold": self.sla_targets["response_time_ms"]
            })
        
        # Check error rate breach
        if metric.error_rate_percent > self.sla_targets["error_rate_percent"]:
            severity = "critical" if metric.error_rate_percent > self.sla_targets["error_rate_percent"] * 3 else "warning"
            breaches.append({
                "type": "error_rate",
                "severity": severity,
                "description": f"Error rate {metric.error_rate_percent:.2f}% exceeds {self.sla_targets['error_rate_percent']:.2f}%",
                "current_value": metric.error_rate_percent,
                "threshold": self.sla_targets["error_rate_percent"]
            })
        
        # Record breaches
        for breach in breaches:
            self._record_sla_breach(breach, metric.timestamp)
    
    def _record_sla_breach(self, breach: Dict, timestamp: datetime):
        """Record an SLA breach in the database"""
        with sqlite3.connect(self.db_path) as conn:
            conn.execute("""
                INSERT INTO sla_breaches (
                    breach_type, start_time, severity, description
                ) VALUES (?, ?, ?, ?)
            """, (
                breach["type"],
                timestamp.isoformat(),
                breach["severity"],
                breach["description"]
            ))
        
        logger.warning(f"SLA breach recorded: {breach['description']}")
    
    def generate_sla_report(self, period_days: int = 30) -> SLAReport:
        """Generate SLA compliance report for specified period"""
        end_time = datetime.utcnow()
        start_time = end_time - timedelta(days=period_days)
        
        with sqlite3.connect(self.db_path) as conn:
            # Get all metrics for the period
            cursor = conn.execute("""
                SELECT * FROM sla_metrics 
                WHERE timestamp >= ? AND timestamp <= ?
                ORDER BY timestamp
            """, (start_time.isoformat(), end_time.isoformat()))
            
            metrics = cursor.fetchall()
            
            if not metrics:
                logger.warning("No metrics found for the specified period")
                return self._create_empty_report(start_time, end_time)
            
            # Calculate availability
            total_measurements = len(metrics)
            available_measurements = sum(1 for m in metrics if m[2] == 1)  # service_available column
            availability_percent = (available_measurements / total_measurements) * 100 if total_measurements > 0 else 0
            
            # Calculate downtime
            unavailable_measurements = total_measurements - available_measurements
            measurement_interval_minutes = 1  # Assuming 1-minute intervals
            downtime_minutes = unavailable_measurements * measurement_interval_minutes
            
            # Calculate response time percentiles
            response_times = [m[3] for m in metrics if m[2] == 1]  # Only when available
            if response_times:
                response_times.sort()
                p95_index = int(len(response_times) * 0.95)
                p95_response_time = response_times[p95_index] if p95_index < len(response_times) else response_times[-1]
            else:
                p95_response_time = 0
            
            # Calculate error rates
            error_rates = [m[4] for m in metrics]  # error_rate_percent column
            avg_error_rate = sum(error_rates) / len(error_rates) if error_rates else 0
            
            # Calculate request rates
            request_rates = [m[5] for m in metrics]  # requests_per_second column
            total_requests = sum(request_rates) * 60 * measurement_interval_minutes  # Approximate total
            failed_requests = int(total_requests * avg_error_rate / 100)
            
            # Get SLA breaches for the period
            breach_cursor = conn.execute("""
                SELECT breach_type, start_time, severity, description, impact_minutes
                FROM sla_breaches
                WHERE start_time >= ? AND start_time <= ?
                ORDER BY start_time
            """, (start_time.isoformat(), end_time.isoformat()))
            
            breaches = []
            for breach in breach_cursor.fetchall():
                breaches.append({
                    "type": breach[0],
                    "start_time": breach[1],
                    "severity": breach[2],
                    "description": breach[3],
                    "impact_minutes": breach[4] or 0
                })
            
            # Calculate error budget
            monthly_minutes = period_days * 24 * 60
            allowed_downtime_minutes = monthly_minutes * (100 - self.sla_targets["availability_percent"]) / 100
            error_budget_used_minutes = downtime_minutes
            error_budget_remaining_percent = max(0, (allowed_downtime_minutes - error_budget_used_minutes) / allowed_downtime_minutes * 100)
            
            return SLAReport(
                period_start=start_time,
                period_end=end_time,
                target_availability_percent=self.sla_targets["availability_percent"],
                actual_availability_percent=availability_percent,
                target_response_time_ms=self.sla_targets["response_time_ms"],
                actual_p95_response_time_ms=p95_response_time,
                target_error_rate_percent=self.sla_targets["error_rate_percent"],
                actual_error_rate_percent=avg_error_rate,
                total_requests=int(total_requests),
                failed_requests=failed_requests,
                downtime_minutes=downtime_minutes,
                sla_breaches=breaches,
                error_budget_remaining_percent=error_budget_remaining_percent
            )
    
    def _create_empty_report(self, start_time: datetime, end_time: datetime) -> SLAReport:
        """Create an empty SLA report when no data is available"""
        return SLAReport(
            period_start=start_time,
            period_end=end_time,
            target_availability_percent=self.sla_targets["availability_percent"],
            actual_availability_percent=0,
            target_response_time_ms=self.sla_targets["response_time_ms"],
            actual_p95_response_time_ms=0,
            target_error_rate_percent=self.sla_targets["error_rate_percent"],
            actual_error_rate_percent=0,
            total_requests=0,
            failed_requests=0,
            downtime_minutes=0,
            sla_breaches=[],
            error_budget_remaining_percent=100
        )
    
    def export_sla_report(self, report: SLAReport, format: str = "json") -> str:
        """Export SLA report in specified format"""
        if format == "json":
            return json.dumps(asdict(report), indent=2, default=str)
        elif format == "html":
            return self._generate_html_report(report)
        elif format == "csv":
            return self._generate_csv_report(report)
        else:
            raise ValueError(f"Unsupported format: {format}")
    
    def _generate_html_report(self, report: SLAReport) -> str:
        """Generate HTML SLA report"""
        sla_met_status = "✅ SLA MET" if report.is_sla_met() else "❌ SLA BREACHED"
        status_color = "green" if report.is_sla_met() else "red"
        
        return f"""
        <!DOCTYPE html>
        <html>
        <head>
            <title>GENESIS Orchestrator - SLA Report</title>
            <style>
                body {{ font-family: Arial, sans-serif; margin: 20px; }}
                .header {{ background: #f0f0f0; padding: 20px; border-radius: 5px; }}
                .status {{ color: {status_color}; font-weight: bold; font-size: 1.2em; }}
                .metric {{ margin: 10px 0; padding: 10px; border-left: 4px solid #ccc; }}
                .breach {{ background: #ffe6e6; padding: 10px; margin: 5px 0; border-radius: 3px; }}
                table {{ border-collapse: collapse; width: 100%; }}
                th, td {{ border: 1px solid #ddd; padding: 8px; text-align: left; }}
                th {{ background: #f2f2f2; }}
            </style>
        </head>
        <body>
            <div class="header">
                <h1>GENESIS Orchestrator - SLA Report</h1>
                <p><strong>Period:</strong> {report.period_start.strftime('%Y-%m-%d')} to {report.period_end.strftime('%Y-%m-%d')}</p>
                <p class="status">{sla_met_status}</p>
            </div>
            
            <h2>SLA Metrics Summary</h2>
            <table>
                <tr>
                    <th>Metric</th>
                    <th>Target</th>
                    <th>Actual</th>
                    <th>Status</th>
                </tr>
                <tr>
                    <td>Availability</td>
                    <td>{report.target_availability_percent:.1f}%</td>
                    <td>{report.actual_availability_percent:.3f}%</td>
                    <td>{'✅' if report.actual_availability_percent >= report.target_availability_percent else '❌'}</td>
                </tr>
                <tr>
                    <td>Response Time (P95)</td>
                    <td>≤ {report.target_response_time_ms:.0f}ms</td>
                    <td>{report.actual_p95_response_time_ms:.0f}ms</td>
                    <td>{'✅' if report.actual_p95_response_time_ms <= report.target_response_time_ms else '❌'}</td>
                </tr>
                <tr>
                    <td>Error Rate</td>
                    <td>≤ {report.target_error_rate_percent:.1f}%</td>
                    <td>{report.actual_error_rate_percent:.2f}%</td>
                    <td>{'✅' if report.actual_error_rate_percent <= report.target_error_rate_percent else '❌'}</td>
                </tr>
            </table>
            
            <h2>Additional Details</h2>
            <div class="metric"><strong>Total Requests:</strong> {report.total_requests:,}</div>
            <div class="metric"><strong>Failed Requests:</strong> {report.failed_requests:,}</div>
            <div class="metric"><strong>Total Downtime:</strong> {report.downtime_minutes:.1f} minutes</div>
            <div class="metric"><strong>Error Budget Remaining:</strong> {report.error_budget_remaining_percent:.1f}%</div>
            
            <h2>SLA Breaches ({len(report.sla_breaches)})</h2>
            {"<p>No SLA breaches during this period.</p>" if not report.sla_breaches else ""}
            {"".join([f'<div class="breach"><strong>{breach["severity"].upper()}:</strong> {breach["description"]} <em>({breach["start_time"]})</em></div>' for breach in report.sla_breaches])}
        </body>
        </html>
        """
    
    def _generate_csv_report(self, report: SLAReport) -> str:
        """Generate CSV SLA report"""
        lines = [
            "Metric,Target,Actual,Status",
            f"Availability,{report.target_availability_percent:.1f}%,{report.actual_availability_percent:.3f}%,{'PASS' if report.actual_availability_percent >= report.target_availability_percent else 'FAIL'}",
            f"Response Time (P95),{report.target_response_time_ms:.0f}ms,{report.actual_p95_response_time_ms:.0f}ms,{'PASS' if report.actual_p95_response_time_ms <= report.target_response_time_ms else 'FAIL'}",
            f"Error Rate,{report.target_error_rate_percent:.1f}%,{report.actual_error_rate_percent:.2f}%,{'PASS' if report.actual_error_rate_percent <= report.target_error_rate_percent else 'FAIL'}",
            "",
            "Summary",
            f"Period Start,{report.period_start.isoformat()}",
            f"Period End,{report.period_end.isoformat()}",
            f"Total Requests,{report.total_requests}",
            f"Failed Requests,{report.failed_requests}",
            f"Downtime Minutes,{report.downtime_minutes:.1f}",
            f"Error Budget Remaining,{report.error_budget_remaining_percent:.1f}%",
            f"SLA Met,{report.is_sla_met()}",
            "",
            "Breaches",
            "Type,Start Time,Severity,Description"
        ]
        
        for breach in report.sla_breaches:
            lines.append(f"{breach['type']},{breach['start_time']},{breach['severity']},{breach['description']}")
        
        return "\n".join(lines)
    
    async def run_monitoring_loop(self, interval_seconds: int = 60):
        """Run continuous SLA monitoring loop"""
        logger.info(f"Starting SLA monitoring loop with {interval_seconds}s interval")
        
        while True:
            try:
                metric = await self.collect_metrics()
                logger.info(f"Collected SLA metrics: available={metric.service_available}, "
                          f"response_time={metric.response_time_ms:.0f}ms, "
                          f"error_rate={metric.error_rate_percent:.2f}%")
                
                await asyncio.sleep(interval_seconds)
                
            except KeyboardInterrupt:
                logger.info("SLA monitoring stopped by user")
                break
            except Exception as e:
                logger.error(f"Error in SLA monitoring loop: {str(e)}")
                await asyncio.sleep(interval_seconds)


async def main():
    """Main function for SLA monitoring"""
    import argparse
    
    parser = argparse.ArgumentParser(description="GENESIS SLA Monitoring System")
    parser.add_argument("--monitor", action="store_true", help="Run continuous monitoring")
    parser.add_argument("--report", type=int, help="Generate SLA report for N days")
    parser.add_argument("--format", choices=["json", "html", "csv"], default="json", help="Report format")
    parser.add_argument("--output", help="Output file path")
    parser.add_argument("--interval", type=int, default=60, help="Monitoring interval in seconds")
    
    args = parser.parse_args()
    
    monitor = SLAMonitor()
    
    if args.monitor:
        await monitor.run_monitoring_loop(args.interval)
    elif args.report:
        report = monitor.generate_sla_report(args.report)
        output = monitor.export_sla_report(report, args.format)
        
        if args.output:
            with open(args.output, 'w') as f:
                f.write(output)
            print(f"SLA report saved to {args.output}")
        else:
            print(output)
    else:
        # Single metric collection
        metric = await monitor.collect_metrics()
        print(f"Current SLA metrics: {json.dumps(metric.to_dict(), indent=2)}")


if __name__ == "__main__":
    asyncio.run(main())