"""
GENESIS Orchestrator - Temporal Worker
Runs the workflow and activities
"""

import asyncio
import os
from temporalio.client import Client
from temporalio.worker import Worker
from genesis_workflow import (
    GenesisOrchestrationWorkflow,
    preflight_activity,
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

TEMPORAL_HOST = os.getenv("TEMPORAL_HOST", "localhost:7233")
TASK_QUEUE = "genesis-orchestrator-queue"
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
    
    print(f"Starting GENESIS worker on {TASK_QUEUE}...")
    print(f"Connected to Temporal at {TEMPORAL_HOST}")
    print(f"Namespace: {NAMESPACE}")
    print("\nWorker is running. Press Ctrl+C to stop.")
    
    # Run the worker
    await worker.run()

if __name__ == "__main__":
    asyncio.run(main())