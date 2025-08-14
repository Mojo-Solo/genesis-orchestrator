"""
GENESIS Orchestrator - Temporal Worker
Runs the workflow and activities
"""

import asyncio
import os
import sys
import logging
from pathlib import Path

# Add parent directory to path for proper imports
sys.path.insert(0, str(Path(__file__).parent.parent.parent))

from temporalio.client import Client
from temporalio.worker import Worker
from tools.temporal.genesis_workflow import (
    GenesisOrchestrationWorkflow,
    preflight_activity,
    load_router_config_activity,
    plan_activity,
    retrieve_activity,
    solve_activity,
    critic_activity,
    verify_activity,
    rewrite_activity,
    route_activity,
    frontend_gates_activity,
    backend_gates_activity,
    generate_artifacts_activity
)

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Read configuration from environment
TEMPORAL_HOST = os.getenv("TEMPORAL_HOST", "localhost:7233")
TASK_QUEUE = os.getenv("TEMPORAL_TASK_QUEUE", "genesis-orchestrator-queue")
NAMESPACE = os.getenv("TEMPORAL_NAMESPACE", "default")

async def main():
    """Start the Temporal worker"""
    
    # Connect to Temporal
    client = await Client.connect(TEMPORAL_HOST, namespace=NAMESPACE)
    
    # Create worker with all activities
    worker = Worker(
        client,
        task_queue=TASK_QUEUE,
        workflows=[GenesisOrchestrationWorkflow],
        activities=[
            preflight_activity,
            load_router_config_activity,
            plan_activity,
            retrieve_activity,
            solve_activity,
            critic_activity,
            verify_activity,
            rewrite_activity,
            route_activity,
            frontend_gates_activity,
            backend_gates_activity,
            generate_artifacts_activity
        ]
    )
    
    logger.info(f"Starting GENESIS worker on {TASK_QUEUE}...")
    logger.info(f"Connected to Temporal at {TEMPORAL_HOST}")
    logger.info(f"Namespace: {NAMESPACE}")
    logger.info("Worker is running. Press Ctrl+C to stop.")
    
    # Run the worker
    await worker.run()

if __name__ == "__main__":
    asyncio.run(main())