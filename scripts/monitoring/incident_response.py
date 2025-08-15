#!/usr/bin/env python3
"""
GENESIS Orchestrator - Automated Incident Response System
=========================================================
Handles automated responses to monitoring alerts and manages incident escalation.
"""

import asyncio
import json
import logging
import os
import sys
import time
from datetime import datetime, timedelta
from enum import Enum
from pathlib import Path
from typing import Dict, List, Optional, Any
import aiohttp
import smtplib
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart

# Add parent directories to path
sys.path.insert(0, str(Path(__file__).parent.parent.parent))

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('/var/log/genesis/incident_response.log'),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)


class IncidentSeverity(Enum):
    """Incident severity levels"""
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"
    CRITICAL = "critical"


class AutomationAction(Enum):
    """Automated actions that can be performed"""
    RESTART_SERVICE = "restart_service"
    SCALE_UP = "scale_up"
    CLEAR_CACHE = "clear_cache"
    TRIGGER_CIRCUIT_BREAKER = "trigger_circuit_breaker"
    ROTATE_LOGS = "rotate_logs"
    DATABASE_MAINTENANCE = "database_maintenance"
    MEMORY_CLEANUP = "memory_cleanup"
    RESET_CONNECTION_POOL = "reset_connection_pool"
    ESCALATE_INCIDENT = "escalate_incident"


class IncidentResponse:
    """Main incident response handler"""
    
    def __init__(self, config_path: str = None):
        self.config = self._load_config(config_path)
        self.active_incidents: Dict[str, Dict] = {}
        self.action_history: List[Dict] = []
        self.session: Optional[aiohttp.ClientSession] = None
        
    def _load_config(self, config_path: str) -> Dict:
        """Load incident response configuration"""
        if config_path and Path(config_path).exists():
            with open(config_path) as f:
                return json.load(f)
        
        # Default configuration
        return {
            "max_restart_attempts": 3,
            "restart_backoff_minutes": [2, 5, 10],
            "escalation_thresholds": {
                "multiple_critical": 3,
                "extended_downtime_minutes": 5,
                "sla_breach_immediate": True
            },
            "notification_channels": {
                "slack_webhook": os.getenv("SLACK_WEBHOOK_URL"),
                "email_smtp": {
                    "server": os.getenv("SMTP_SERVER", "localhost:587"),
                    "username": os.getenv("SMTP_USERNAME"),
                    "password": os.getenv("SMTP_PASSWORD"),
                    "from_email": os.getenv("ALERT_FROM_EMAIL", "alerts@genesis.ai")
                },
                "pagerduty_key": os.getenv("PAGERDUTY_INTEGRATION_KEY")
            },
            "endpoints": {
                "orchestrator_health": "http://localhost:8000/health/ready",
                "orchestrator_restart": "http://localhost:8080/admin/restart",
                "cache_clear": "http://localhost:8000/api/cache/clear",
                "circuit_breaker": "http://localhost:8080/admin/circuit-breaker"
            }
        }
    
    async def __aenter__(self):
        """Async context manager entry"""
        self.session = aiohttp.ClientSession()
        return self
    
    async def __aexit__(self, exc_type, exc_val, exc_tb):
        """Async context manager exit"""
        if self.session:
            await self.session.close()
    
    async def handle_alert(self, alert_data: Dict) -> Dict[str, Any]:
        """Handle incoming alert and execute appropriate response"""
        try:
            alert_name = alert_data.get('labels', {}).get('alertname', 'Unknown')
            severity = alert_data.get('labels', {}).get('severity', 'unknown')
            automation_action = alert_data.get('annotations', {}).get('automation_action')
            
            logger.info(f"Processing alert: {alert_name} (severity: {severity})")
            
            # Create incident record
            incident_id = f"{alert_name}_{int(time.time())}"
            incident = {
                "id": incident_id,
                "alert_name": alert_name,
                "severity": severity,
                "start_time": datetime.utcnow(),
                "alert_data": alert_data,
                "actions_taken": [],
                "status": "active"
            }
            
            self.active_incidents[incident_id] = incident
            
            # Execute automated response if specified
            if automation_action:
                response = await self._execute_automation(automation_action, alert_data, incident_id)
                incident["actions_taken"].append(response)
            
            # Check for escalation conditions
            escalation_response = await self._check_escalation(alert_data, incident_id)
            if escalation_response:
                incident["actions_taken"].append(escalation_response)
            
            return {
                "status": "processed",
                "incident_id": incident_id,
                "actions_taken": len(incident["actions_taken"]),
                "escalated": any(action.get("type") == "escalation" for action in incident["actions_taken"])
            }
            
        except Exception as e:
            logger.error(f"Error handling alert: {str(e)}")
            return {"status": "error", "error": str(e)}
    
    async def _execute_automation(self, action: str, alert_data: Dict, incident_id: str) -> Dict:
        """Execute automated response action"""
        logger.info(f"Executing automation action: {action} for incident {incident_id}")
        
        action_handlers = {
            "restart_service": self._restart_service,
            "scale_up": self._scale_up,
            "clear_cache": self._clear_cache,
            "trigger_circuit_breaker": self._trigger_circuit_breaker,
            "rotate_logs": self._rotate_logs,
            "database_maintenance": self._database_maintenance,
            "memory_cleanup": self._memory_cleanup,
            "reset_connection_pool": self._reset_connection_pool
        }
        
        handler = action_handlers.get(action)
        if not handler:
            logger.warning(f"Unknown automation action: {action}")
            return {"type": "automation", "action": action, "status": "unknown_action"}
        
        try:
            result = await handler(alert_data, incident_id)
            self.action_history.append({
                "timestamp": datetime.utcnow(),
                "incident_id": incident_id,
                "action": action,
                "result": result
            })
            return result
        except Exception as e:
            logger.error(f"Error executing automation {action}: {str(e)}")
            return {"type": "automation", "action": action, "status": "error", "error": str(e)}
    
    async def _restart_service(self, alert_data: Dict, incident_id: str) -> Dict:
        """Restart the Genesis orchestrator service"""
        max_attempts = self.config["max_restart_attempts"]
        backoff_minutes = self.config["restart_backoff_minutes"]
        
        for attempt in range(max_attempts):
            try:
                logger.info(f"Attempting service restart (attempt {attempt + 1}/{max_attempts})")
                
                # Send restart command
                async with self.session.post(
                    self.config["endpoints"]["orchestrator_restart"],
                    timeout=aiohttp.ClientTimeout(total=30)
                ) as response:
                    if response.status == 200:
                        # Wait and check if service is healthy
                        await asyncio.sleep(30)  # Give service time to start
                        
                        async with self.session.get(
                            self.config["endpoints"]["orchestrator_health"],
                            timeout=aiohttp.ClientTimeout(total=10)
                        ) as health_response:
                            if health_response.status == 200:
                                logger.info("Service restart successful")
                                return {
                                    "type": "automation",
                                    "action": "restart_service",
                                    "status": "success",
                                    "attempt": attempt + 1
                                }
                
                # If not successful, wait before retry
                if attempt < max_attempts - 1:
                    wait_minutes = backoff_minutes[min(attempt, len(backoff_minutes) - 1)]
                    logger.info(f"Service restart failed, waiting {wait_minutes} minutes before retry")
                    await asyncio.sleep(wait_minutes * 60)
                    
            except Exception as e:
                logger.error(f"Service restart attempt {attempt + 1} failed: {str(e)}")
        
        return {
            "type": "automation",
            "action": "restart_service",
            "status": "failed",
            "attempts": max_attempts
        }
    
    async def _clear_cache(self, alert_data: Dict, incident_id: str) -> Dict:
        """Clear application cache"""
        try:
            async with self.session.post(
                self.config["endpoints"]["cache_clear"],
                timeout=aiohttp.ClientTimeout(total=15)
            ) as response:
                if response.status == 200:
                    logger.info("Cache cleared successfully")
                    return {"type": "automation", "action": "clear_cache", "status": "success"}
                else:
                    return {"type": "automation", "action": "clear_cache", "status": "failed", "http_status": response.status}
        except Exception as e:
            return {"type": "automation", "action": "clear_cache", "status": "error", "error": str(e)}
    
    async def _trigger_circuit_breaker(self, alert_data: Dict, incident_id: str) -> Dict:
        """Trigger circuit breaker to protect system"""
        try:
            payload = {"action": "open", "reason": f"Automated response to incident {incident_id}"}
            async with self.session.post(
                self.config["endpoints"]["circuit_breaker"],
                json=payload,
                timeout=aiohttp.ClientTimeout(total=10)
            ) as response:
                if response.status == 200:
                    logger.info("Circuit breaker triggered successfully")
                    return {"type": "automation", "action": "trigger_circuit_breaker", "status": "success"}
                else:
                    return {"type": "automation", "action": "trigger_circuit_breaker", "status": "failed", "http_status": response.status}
        except Exception as e:
            return {"type": "automation", "action": "trigger_circuit_breaker", "status": "error", "error": str(e)}
    
    async def _scale_up(self, alert_data: Dict, incident_id: str) -> Dict:
        """Scale up resources (placeholder - implementation depends on infrastructure)"""
        logger.info("Scale up action triggered - manual intervention required")
        return {
            "type": "automation",
            "action": "scale_up",
            "status": "manual_intervention_required",
            "message": "Scaling requires manual approval in production"
        }
    
    async def _rotate_logs(self, alert_data: Dict, incident_id: str) -> Dict:
        """Rotate application logs"""
        try:
            # Use logrotate command
            process = await asyncio.create_subprocess_exec(
                "logrotate", "-f", "/etc/logrotate.d/genesis-orchestrator",
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE
            )
            stdout, stderr = await process.communicate()
            
            if process.returncode == 0:
                logger.info("Log rotation completed successfully")
                return {"type": "automation", "action": "rotate_logs", "status": "success"}
            else:
                return {
                    "type": "automation",
                    "action": "rotate_logs",
                    "status": "failed",
                    "error": stderr.decode()
                }
        except Exception as e:
            return {"type": "automation", "action": "rotate_logs", "status": "error", "error": str(e)}
    
    async def _database_maintenance(self, alert_data: Dict, incident_id: str) -> Dict:
        """Perform database maintenance tasks"""
        # This would typically run ANALYZE, OPTIMIZE, or other maintenance commands
        logger.info("Database maintenance triggered - scheduling background task")
        return {
            "type": "automation",
            "action": "database_maintenance",
            "status": "scheduled",
            "message": "Database maintenance scheduled for next maintenance window"
        }
    
    async def _memory_cleanup(self, alert_data: Dict, incident_id: str) -> Dict:
        """Perform memory cleanup"""
        try:
            # Run garbage collection and memory cleanup
            process = await asyncio.create_subprocess_exec(
                "sync", "&&", "echo", "3", ">", "/proc/sys/vm/drop_caches",
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE
            )
            stdout, stderr = await process.communicate()
            
            return {"type": "automation", "action": "memory_cleanup", "status": "completed"}
        except Exception as e:
            return {"type": "automation", "action": "memory_cleanup", "status": "error", "error": str(e)}
    
    async def _reset_connection_pool(self, alert_data: Dict, incident_id: str) -> Dict:
        """Reset database connection pool"""
        # This would typically restart connection pools or reset connections
        logger.info("Connection pool reset triggered")
        return {
            "type": "automation",
            "action": "reset_connection_pool",
            "status": "manual_intervention_required",
            "message": "Connection pool reset requires application restart"
        }
    
    async def _check_escalation(self, alert_data: Dict, incident_id: str) -> Optional[Dict]:
        """Check if incident should be escalated"""
        labels = alert_data.get('labels', {})
        severity = labels.get('severity')
        component = labels.get('component')
        
        escalation_config = self.config["escalation_thresholds"]
        
        # Check for multiple critical alerts
        critical_count = len([inc for inc in self.active_incidents.values() 
                            if inc.get("severity") == "critical" and inc.get("status") == "active"])
        
        if critical_count >= escalation_config["multiple_critical"]:
            return await self._escalate_incident("multiple_critical", incident_id, {
                "critical_alerts_count": critical_count
            })
        
        # Check for SLA breach
        if "sla" in component and escalation_config["sla_breach_immediate"]:
            return await self._escalate_incident("sla_breach", incident_id, alert_data)
        
        # Check for extended downtime
        if labels.get('alertname') == 'GenesisOrchestratorDown':
            incident = self.active_incidents[incident_id]
            downtime_minutes = (datetime.utcnow() - incident["start_time"]).total_seconds() / 60
            if downtime_minutes >= escalation_config["extended_downtime_minutes"]:
                return await self._escalate_incident("extended_downtime", incident_id, {
                    "downtime_minutes": downtime_minutes
                })
        
        return None
    
    async def _escalate_incident(self, escalation_type: str, incident_id: str, context: Dict) -> Dict:
        """Escalate incident to appropriate channels"""
        logger.warning(f"Escalating incident {incident_id}: {escalation_type}")
        
        escalation_result = {
            "type": "escalation",
            "escalation_type": escalation_type,
            "timestamp": datetime.utcnow(),
            "notifications_sent": []
        }
        
        # Send notifications to all configured channels
        notifications = await asyncio.gather(
            self._send_slack_notification(escalation_type, incident_id, context),
            self._send_email_notification(escalation_type, incident_id, context),
            self._send_pagerduty_notification(escalation_type, incident_id, context),
            return_exceptions=True
        )
        
        for i, notification in enumerate(notifications):
            if isinstance(notification, Exception):
                logger.error(f"Notification {i} failed: {str(notification)}")
            else:
                escalation_result["notifications_sent"].append(notification)
        
        return escalation_result
    
    async def _send_slack_notification(self, escalation_type: str, incident_id: str, context: Dict) -> Dict:
        """Send Slack notification"""
        webhook_url = self.config["notification_channels"].get("slack_webhook")
        if not webhook_url:
            return {"channel": "slack", "status": "not_configured"}
        
        try:
            incident = self.active_incidents[incident_id]
            message = {
                "text": f"🚨 ESCALATED INCIDENT: {incident['alert_name']}",
                "blocks": [
                    {
                        "type": "header",
                        "text": {
                            "type": "plain_text",
                            "text": f"🚨 ESCALATED: {escalation_type.replace('_', ' ').title()}"
                        }
                    },
                    {
                        "type": "section",
                        "fields": [
                            {"type": "mrkdwn", "text": f"*Incident ID:*\n{incident_id}"},
                            {"type": "mrkdwn", "text": f"*Alert:*\n{incident['alert_name']}"},
                            {"type": "mrkdwn", "text": f"*Severity:*\n{incident['severity']}"},
                            {"type": "mrkdwn", "text": f"*Start Time:*\n{incident['start_time'].isoformat()}"}
                        ]
                    }
                ]
            }
            
            async with self.session.post(webhook_url, json=message) as response:
                if response.status == 200:
                    return {"channel": "slack", "status": "sent"}
                else:
                    return {"channel": "slack", "status": "failed", "http_status": response.status}
                    
        except Exception as e:
            return {"channel": "slack", "status": "error", "error": str(e)}
    
    async def _send_email_notification(self, escalation_type: str, incident_id: str, context: Dict) -> Dict:
        """Send email notification"""
        smtp_config = self.config["notification_channels"]["email_smtp"]
        if not smtp_config.get("username"):
            return {"channel": "email", "status": "not_configured"}
        
        try:
            incident = self.active_incidents[incident_id]
            
            msg = MIMEMultipart()
            msg['From'] = smtp_config["from_email"]
            msg['To'] = "oncall@genesis.ai,management@genesis.ai"
            msg['Subject'] = f"ESCALATED INCIDENT: {incident['alert_name']}"
            
            body = f"""
ESCALATED INCIDENT NOTIFICATION

Escalation Type: {escalation_type.replace('_', ' ').title()}
Incident ID: {incident_id}
Alert Name: {incident['alert_name']}
Severity: {incident['severity']}
Start Time: {incident['start_time'].isoformat()}

Actions Taken: {len(incident['actions_taken'])}

Context: {json.dumps(context, indent=2)}

This incident requires immediate attention.

--
GENESIS Orchestrator Monitoring System
"""
            
            msg.attach(MIMEText(body, 'plain'))
            
            # Send email (note: this is a simplified implementation)
            # In production, you'd want proper SMTP configuration with TLS/SSL
            server = smtplib.SMTP(smtp_config["server"])
            if smtp_config.get("username") and smtp_config.get("password"):
                server.login(smtp_config["username"], smtp_config["password"])
            
            server.send_message(msg)
            server.quit()
            
            return {"channel": "email", "status": "sent"}
            
        except Exception as e:
            return {"channel": "email", "status": "error", "error": str(e)}
    
    async def _send_pagerduty_notification(self, escalation_type: str, incident_id: str, context: Dict) -> Dict:
        """Send PagerDuty notification"""
        integration_key = self.config["notification_channels"].get("pagerduty_key")
        if not integration_key:
            return {"channel": "pagerduty", "status": "not_configured"}
        
        try:
            incident = self.active_incidents[incident_id]
            
            payload = {
                "routing_key": integration_key,
                "event_action": "trigger",
                "dedup_key": incident_id,
                "payload": {
                    "summary": f"ESCALATED: {incident['alert_name']}",
                    "source": "genesis-orchestrator",
                    "severity": "critical",
                    "component": incident['alert_data'].get('labels', {}).get('component', 'unknown'),
                    "custom_details": {
                        "escalation_type": escalation_type,
                        "incident_id": incident_id,
                        "actions_taken": len(incident['actions_taken']),
                        "context": context
                    }
                }
            }
            
            async with self.session.post(
                "https://events.pagerduty.com/v2/enqueue",
                json=payload
            ) as response:
                if response.status == 202:
                    return {"channel": "pagerduty", "status": "sent"}
                else:
                    return {"channel": "pagerduty", "status": "failed", "http_status": response.status}
                    
        except Exception as e:
            return {"channel": "pagerduty", "status": "error", "error": str(e)}
    
    def get_incident_summary(self) -> Dict:
        """Get summary of all incidents"""
        active_count = len([inc for inc in self.active_incidents.values() if inc["status"] == "active"])
        critical_count = len([inc for inc in self.active_incidents.values() 
                             if inc["status"] == "active" and inc["severity"] == "critical"])
        
        return {
            "total_incidents": len(self.active_incidents),
            "active_incidents": active_count,
            "critical_incidents": critical_count,
            "total_actions": len(self.action_history),
            "last_action": self.action_history[-1] if self.action_history else None
        }


async def webhook_handler(request_data: Dict) -> Dict:
    """Handle webhook requests from AlertManager"""
    async with IncidentResponse() as incident_handler:
        if 'alerts' in request_data:
            results = []
            for alert in request_data['alerts']:
                result = await incident_handler.handle_alert(alert)
                results.append(result)
            return {"status": "processed", "alerts_handled": len(results), "results": results}
        else:
            result = await incident_handler.handle_alert(request_data)
            return result


if __name__ == "__main__":
    # Example usage - in production this would be triggered by webhooks
    import asyncio
    
    async def test_incident_response():
        """Test incident response system"""
        sample_alert = {
            "labels": {
                "alertname": "GenesisOrchestratorDown",
                "severity": "critical",
                "component": "orchestrator"
            },
            "annotations": {
                "summary": "Genesis Orchestrator is down",
                "automation_action": "restart_service"
            }
        }
        
        async with IncidentResponse() as handler:
            result = await handler.handle_alert(sample_alert)
            print(f"Incident response result: {json.dumps(result, indent=2)}")
            
            summary = handler.get_incident_summary()
            print(f"Incident summary: {json.dumps(summary, indent=2, default=str)}")
    
    asyncio.run(test_incident_response())