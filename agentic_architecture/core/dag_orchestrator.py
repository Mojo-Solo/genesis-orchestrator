"""
DAG (Directed Acyclic Graphs) Orchestration Layer
================================================
Advanced DAG orchestration system for managing complex, multi-step workflows
with dependencies. Integrates with existing Genesis orchestrator and provides
enterprise-grade workflow management capabilities.

Based on research findings:
- DAGs ensure clear progression from start to finish without cycles
- Optimize execution of complex processes with dependencies
- Enable dynamic decision-making and real-time adjustments
- Support enterprise-scale workflow orchestration

Author: GENESIS Orchestrator Team
Version: 1.0.0
"""

import asyncio
import json
import hashlib
import time
import uuid
from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from datetime import datetime, timedelta
from enum import Enum
from typing import Dict, List, Any, Optional, Callable, Set, Tuple, Union
from collections import defaultdict, deque
import logging
import networkx as nx
from pathlib import Path

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


# ============================================================================
# CORE DATA STRUCTURES FOR DAG ORCHESTRATOR
# ============================================================================

class TaskType(Enum):
    """Types of tasks in the DAG."""
    EXTRACT = "extract"
    TRANSFORM = "transform"
    LOAD = "load"
    VALIDATE = "validate"
    COMPUTE = "compute"
    DECISION = "decision"
    NOTIFICATION = "notification"
    CHECKPOINT = "checkpoint"
    CLEANUP = "cleanup"
    MONITOR = "monitor"


class TaskStatus(Enum):
    """Status of task execution."""
    PENDING = "pending"
    READY = "ready"
    RUNNING = "running"
    SUCCESS = "success"
    FAILED = "failed"
    SKIPPED = "skipped"
    CANCELLED = "cancelled"
    RETRY = "retry"


class SchedulingStrategy(Enum):
    """Strategies for task scheduling."""
    FIFO = "fifo"  # First In, First Out
    PRIORITY = "priority"  # Priority-based scheduling
    RESOURCE_AWARE = "resource_aware"  # Resource availability based
    CRITICAL_PATH = "critical_path"  # Critical path method
    DEADLINE_FIRST = "deadline_first"  # Earliest deadline first
    LOAD_BALANCED = "load_balanced"  # Load balancing across resources


class DependencyType(Enum):
    """Types of dependencies between tasks."""
    SEQUENTIAL = "sequential"  # Task B must wait for Task A to complete
    CONDITIONAL = "conditional"  # Task B runs only if condition from Task A is met
    DATA_FLOW = "data_flow"  # Task B needs data output from Task A
    RESOURCE = "resource"  # Tasks share the same resource
    TIME_BASED = "time_based"  # Time-based dependency


class RetryPolicy(Enum):
    """Retry policies for failed tasks."""
    NO_RETRY = "no_retry"
    FIXED_INTERVAL = "fixed_interval"
    EXPONENTIAL_BACKOFF = "exponential_backoff"
    LINEAR_BACKOFF = "linear_backoff"
    CUSTOM = "custom"


@dataclass
class TaskDefinition:
    """Defines a task in the DAG."""
    id: str
    name: str
    task_type: TaskType
    description: str
    command: Optional[str] = None
    parameters: Dict[str, Any] = field(default_factory=dict)
    inputs: List[str] = field(default_factory=list)
    outputs: List[str] = field(default_factory=list)
    dependencies: List[str] = field(default_factory=list)
    resources: Dict[str, Any] = field(default_factory=dict)
    timeout_seconds: int = 3600
    max_retries: int = 3
    retry_policy: RetryPolicy = RetryPolicy.EXPONENTIAL_BACKOFF
    priority: int = 5  # 1-10, 10 being highest
    tags: Set[str] = field(default_factory=set)
    metadata: Dict[str, Any] = field(default_factory=dict)
    created_at: datetime = field(default_factory=datetime.utcnow)
    updated_at: datetime = field(default_factory=datetime.utcnow)


@dataclass
class TaskInstance:
    """Runtime instance of a task."""
    task_id: str
    instance_id: str
    dag_run_id: str
    status: TaskStatus = TaskStatus.PENDING
    start_time: Optional[datetime] = None
    end_time: Optional[datetime] = None
    duration_seconds: Optional[float] = None
    attempt_number: int = 1
    executor_id: Optional[str] = None
    worker_id: Optional[str] = None
    exit_code: Optional[int] = None
    logs: List[str] = field(default_factory=list)
    metrics: Dict[str, Any] = field(default_factory=dict)
    context: Dict[str, Any] = field(default_factory=dict)
    error_message: Optional[str] = None
    retry_at: Optional[datetime] = None


@dataclass
class DAGDefinition:
    """Defines a complete DAG workflow."""
    dag_id: str
    name: str
    description: str
    tasks: Dict[str, TaskDefinition] = field(default_factory=dict)
    dependencies: Dict[str, List[str]] = field(default_factory=dict)
    schedule_interval: Optional[str] = None  # Cron expression
    start_date: Optional[datetime] = None
    end_date: Optional[datetime] = None
    max_active_runs: int = 1
    concurrency: int = 16
    catchup: bool = False
    tags: Set[str] = field(default_factory=set)
    default_args: Dict[str, Any] = field(default_factory=dict)
    metadata: Dict[str, Any] = field(default_factory=dict)
    created_at: datetime = field(default_factory=datetime.utcnow)
    updated_at: datetime = field(default_factory=datetime.utcnow)


@dataclass
class DAGRun:
    """Runtime instance of a DAG execution."""
    run_id: str
    dag_id: str
    execution_date: datetime
    status: TaskStatus = TaskStatus.PENDING
    start_date: Optional[datetime] = None
    end_date: Optional[datetime] = None
    external_trigger: bool = False
    run_type: str = "scheduled"
    conf: Dict[str, Any] = field(default_factory=dict)
    task_instances: Dict[str, TaskInstance] = field(default_factory=dict)
    metrics: Dict[str, Any] = field(default_factory=dict)
    created_at: datetime = field(default_factory=datetime.utcnow)


@dataclass
class ExecutionPlan:
    """Plan for executing a DAG."""
    dag_run_id: str
    execution_order: List[List[str]]  # List of parallel execution groups
    resource_allocation: Dict[str, str]  # Task to resource mapping
    estimated_duration: int  # Estimated total duration in seconds
    critical_path: List[str]
    bottlenecks: List[str]
    scheduling_strategy: SchedulingStrategy
    parallel_capacity: int
    created_at: datetime = field(default_factory=datetime.utcnow)


# ============================================================================
# DAG VALIDATION AND ANALYSIS
# ============================================================================

class DAGValidator:
    """Validates DAG definitions for correctness and optimization."""
    
    @staticmethod
    def validate_dag(dag: DAGDefinition) -> Tuple[bool, List[str]]:
        """Validate a DAG definition."""
        errors = []
        
        try:
            # Build graph for analysis
            graph = nx.DiGraph()
            
            # Add nodes
            for task_id in dag.tasks:
                graph.add_node(task_id)
            
            # Add edges based on dependencies
            for task_id, task_def in dag.tasks.items():
                for dep in task_def.dependencies:
                    if dep not in dag.tasks:
                        errors.append(f"Task {task_id} depends on non-existent task {dep}")
                    else:
                        graph.add_edge(dep, task_id)
            
            # Check for cycles
            if not nx.is_directed_acyclic_graph(graph):
                cycles = list(nx.simple_cycles(graph))
                errors.append(f"DAG contains cycles: {cycles}")
            
            # Check for orphaned tasks
            if graph.nodes():
                # Find tasks with no incoming edges (roots)
                roots = [n for n in graph.nodes() if graph.in_degree(n) == 0]
                if not roots:
                    errors.append("DAG has no root tasks (all tasks have dependencies)")
                
                # Find tasks with no outgoing edges (leaves)
                leaves = [n for n in graph.nodes() if graph.out_degree(n) == 0]
                if not leaves:
                    errors.append("DAG has no leaf tasks (no final tasks)")
                
                # Check connectivity
                if not nx.is_weakly_connected(graph):
                    components = list(nx.weakly_connected_components(graph))
                    if len(components) > 1:
                        errors.append(f"DAG has {len(components)} disconnected components")
            
            # Validate task definitions
            for task_id, task_def in dag.tasks.items():
                task_errors = DAGValidator._validate_task(task_def)
                errors.extend([f"Task {task_id}: {err}" for err in task_errors])
            
            # Check resource constraints
            resource_errors = DAGValidator._validate_resources(dag)
            errors.extend(resource_errors)
            
            return len(errors) == 0, errors
            
        except Exception as e:
            errors.append(f"Validation error: {str(e)}")
            return False, errors
    
    @staticmethod
    def _validate_task(task: TaskDefinition) -> List[str]:
        """Validate individual task definition."""
        errors = []
        
        if not task.id:
            errors.append("Task ID cannot be empty")
        
        if not task.name:
            errors.append("Task name cannot be empty")
        
        if task.timeout_seconds <= 0:
            errors.append("Timeout must be positive")
        
        if task.max_retries < 0:
            errors.append("Max retries cannot be negative")
        
        if not 1 <= task.priority <= 10:
            errors.append("Priority must be between 1 and 10")
        
        # Validate circular dependencies in inputs/outputs
        if set(task.inputs) & set(task.outputs):
            overlap = set(task.inputs) & set(task.outputs)
            errors.append(f"Task has overlapping inputs/outputs: {overlap}")
        
        return errors
    
    @staticmethod
    def _validate_resources(dag: DAGDefinition) -> List[str]:
        """Validate resource constraints across tasks."""
        errors = []
        
        # Check for resource conflicts
        resource_usage = defaultdict(list)
        for task_id, task_def in dag.tasks.items():
            for resource_type, amount in task_def.resources.items():
                resource_usage[resource_type].append((task_id, amount))
        
        # Simple validation - could be enhanced with actual resource scheduling
        for resource_type, usage_list in resource_usage.items():
            if len(usage_list) > 10:  # Arbitrary threshold
                errors.append(f"High contention for resource {resource_type}: {len(usage_list)} tasks")
        
        return errors


class DAGAnalyzer:
    """Analyzes DAGs for optimization opportunities."""
    
    @staticmethod
    def analyze_critical_path(dag: DAGDefinition) -> Tuple[List[str], int]:
        """Find the critical path in the DAG."""
        try:
            graph = nx.DiGraph()
            
            # Add nodes with duration weights
            for task_id, task_def in dag.tasks.items():
                duration = task_def.metadata.get("estimated_duration_seconds", 3600)
                graph.add_node(task_id, duration=duration)
            
            # Add edges
            for task_id, task_def in dag.tasks.items():
                for dep in task_def.dependencies:
                    if dep in dag.tasks:
                        graph.add_edge(dep, task_id)
            
            # Calculate longest path (critical path)
            if not graph.nodes():
                return [], 0
            
            # Use longest path algorithm
            try:
                # NetworkX doesn't have direct longest path, so we negate weights
                for node in graph.nodes():
                    graph.nodes[node]['weight'] = -graph.nodes[node]['duration']
                
                # Find shortest path with negative weights = longest path
                longest_path = []
                max_duration = 0
                
                # Try from each root node
                roots = [n for n in graph.nodes() if graph.in_degree(n) == 0]
                for root in roots:
                    try:
                        distances = nx.single_source_shortest_path_length(
                            graph, root, weight='weight'
                        )
                        # Find the path with maximum negative distance (minimum positive distance)
                        if distances:
                            max_node = min(distances.items(), key=lambda x: x[1])
                            path_duration = -max_node[1]
                            if path_duration > max_duration:
                                max_duration = path_duration
                                # Reconstruct path
                                try:
                                    longest_path = nx.shortest_path(graph, root, max_node[0], weight='weight')
                                except:
                                    longest_path = [root, max_node[0]]
                    except:
                        continue
                
                return longest_path, max_duration
                
            except Exception as e:
                logger.warning(f"Critical path calculation failed: {e}")
                # Fallback: return topological sort
                return list(nx.topological_sort(graph)), sum(
                    task.metadata.get("estimated_duration_seconds", 3600)
                    for task in dag.tasks.values()
                )
        
        except Exception as e:
            logger.error(f"Critical path analysis failed: {e}")
            return [], 0
    
    @staticmethod
    def identify_bottlenecks(dag: DAGDefinition) -> List[str]:
        """Identify potential bottlenecks in the DAG."""
        bottlenecks = []
        
        try:
            graph = nx.DiGraph()
            
            # Build graph
            for task_id in dag.tasks:
                graph.add_node(task_id)
            
            for task_id, task_def in dag.tasks.items():
                for dep in task_def.dependencies:
                    if dep in dag.tasks:
                        graph.add_edge(dep, task_id)
            
            # Identify bottlenecks based on various criteria
            
            # 1. High fan-out nodes (many dependent tasks)
            for node in graph.nodes():
                if graph.out_degree(node) > 3:  # Arbitrary threshold
                    bottlenecks.append(node)
            
            # 2. Tasks with high resource requirements
            for task_id, task_def in dag.tasks.items():
                total_resources = sum(task_def.resources.values()) if task_def.resources else 0
                if total_resources > 100:  # Arbitrary threshold
                    bottlenecks.append(task_id)
            
            # 3. Tasks with long estimated duration
            for task_id, task_def in dag.tasks.items():
                duration = task_def.metadata.get("estimated_duration_seconds", 3600)
                if duration > 7200:  # More than 2 hours
                    bottlenecks.append(task_id)
            
            return list(set(bottlenecks))  # Remove duplicates
            
        except Exception as e:
            logger.error(f"Bottleneck identification failed: {e}")
            return []
    
    @staticmethod
    def calculate_parallelism_opportunities(dag: DAGDefinition) -> Dict[str, int]:
        """Calculate parallelism opportunities in the DAG."""
        try:
            graph = nx.DiGraph()
            
            # Build graph
            for task_id in dag.tasks:
                graph.add_node(task_id)
            
            for task_id, task_def in dag.tasks.items():
                for dep in task_def.dependencies:
                    if dep in dag.tasks:
                        graph.add_edge(dep, task_id)
            
            # Calculate parallelism levels
            levels = []
            remaining_nodes = set(graph.nodes())
            
            while remaining_nodes:
                # Find nodes with no dependencies in remaining set
                current_level = []
                for node in list(remaining_nodes):
                    dependencies = [
                        pred for pred in graph.predecessors(node)
                        if pred in remaining_nodes
                    ]
                    if not dependencies:
                        current_level.append(node)
                
                if not current_level:
                    # Handle potential issues with remaining nodes
                    current_level = [list(remaining_nodes)[0]]
                
                levels.append(current_level)
                remaining_nodes -= set(current_level)
            
            return {
                "max_parallel_tasks": max(len(level) for level in levels) if levels else 0,
                "total_levels": len(levels),
                "parallelism_by_level": [len(level) for level in levels],
                "average_parallelism": sum(len(level) for level in levels) / len(levels) if levels else 0
            }
            
        except Exception as e:
            logger.error(f"Parallelism calculation failed: {e}")
            return {"max_parallel_tasks": 1, "total_levels": 1, "parallelism_by_level": [1], "average_parallelism": 1}


# ============================================================================
# TASK EXECUTORS
# ============================================================================

class BaseTaskExecutor(ABC):
    """Base class for task executors."""
    
    def __init__(self, executor_id: str, config: Dict[str, Any]):
        self.executor_id = executor_id
        self.config = config
        self.active_tasks: Dict[str, TaskInstance] = {}
        self.completed_tasks: List[TaskInstance] = []
        self.metrics = defaultdict(float)
    
    @abstractmethod
    async def execute_task(self, task_def: TaskDefinition, task_instance: TaskInstance, 
                          context: Dict[str, Any]) -> bool:
        """Execute a task and return success status."""
        pass
    
    @abstractmethod
    async def stop_task(self, task_instance: TaskInstance) -> bool:
        """Stop a running task."""
        pass
    
    @abstractmethod
    async def get_task_status(self, task_instance: TaskInstance) -> TaskStatus:
        """Get current status of a task."""
        pass
    
    def update_metrics(self, task_instance: TaskInstance, success: bool):
        """Update executor metrics."""
        self.metrics["total_tasks"] += 1
        if success:
            self.metrics["successful_tasks"] += 1
        else:
            self.metrics["failed_tasks"] += 1
        
        self.metrics["success_rate"] = self.metrics["successful_tasks"] / self.metrics["total_tasks"]
        
        if task_instance.duration_seconds:
            self.metrics["total_duration"] += task_instance.duration_seconds
            self.metrics["average_duration"] = self.metrics["total_duration"] / self.metrics["total_tasks"]


class LocalTaskExecutor(BaseTaskExecutor):
    """Executor for running tasks locally."""
    
    async def execute_task(self, task_def: TaskDefinition, task_instance: TaskInstance, 
                          context: Dict[str, Any]) -> bool:
        """Execute task locally."""
        task_instance.status = TaskStatus.RUNNING
        task_instance.start_time = datetime.utcnow()
        task_instance.executor_id = self.executor_id
        
        self.active_tasks[task_instance.instance_id] = task_instance
        
        try:
            # Simulate task execution based on task type
            await self._simulate_task_execution(task_def, task_instance, context)
            
            task_instance.status = TaskStatus.SUCCESS
            task_instance.end_time = datetime.utcnow()
            task_instance.duration_seconds = (
                task_instance.end_time - task_instance.start_time
            ).total_seconds()
            task_instance.exit_code = 0
            
            # Move to completed tasks
            self.completed_tasks.append(task_instance)
            del self.active_tasks[task_instance.instance_id]
            
            self.update_metrics(task_instance, True)
            return True
            
        except Exception as e:
            task_instance.status = TaskStatus.FAILED
            task_instance.end_time = datetime.utcnow()
            task_instance.duration_seconds = (
                task_instance.end_time - task_instance.start_time
            ).total_seconds() if task_instance.start_time else 0
            task_instance.error_message = str(e)
            task_instance.exit_code = 1
            
            self.completed_tasks.append(task_instance)
            if task_instance.instance_id in self.active_tasks:
                del self.active_tasks[task_instance.instance_id]
            
            self.update_metrics(task_instance, False)
            return False
    
    async def _simulate_task_execution(self, task_def: TaskDefinition, 
                                     task_instance: TaskInstance, context: Dict[str, Any]):
        """Simulate task execution based on task type."""
        
        # Simulate different execution times based on task type
        execution_times = {
            TaskType.EXTRACT: 2.0,
            TaskType.TRANSFORM: 3.0,
            TaskType.LOAD: 1.5,
            TaskType.VALIDATE: 0.5,
            TaskType.COMPUTE: 4.0,
            TaskType.DECISION: 0.2,
            TaskType.NOTIFICATION: 0.1,
            TaskType.CHECKPOINT: 0.3,
            TaskType.CLEANUP: 1.0,
            TaskType.MONITOR: 0.5
        }
        
        execution_time = execution_times.get(task_def.task_type, 2.0)
        
        # Add some randomness
        import random
        execution_time *= random.uniform(0.5, 1.5)
        
        # Log task start
        task_instance.logs.append(f"Starting {task_def.task_type.value} task: {task_def.name}")
        
        # Simulate work
        await asyncio.sleep(execution_time)
        
        # Simulate potential failure based on task complexity
        failure_probability = {
            TaskType.EXTRACT: 0.05,
            TaskType.TRANSFORM: 0.10,
            TaskType.LOAD: 0.03,
            TaskType.VALIDATE: 0.02,
            TaskType.COMPUTE: 0.15,
            TaskType.DECISION: 0.01,
            TaskType.NOTIFICATION: 0.01,
            TaskType.CHECKPOINT: 0.01,
            TaskType.CLEANUP: 0.02,
            TaskType.MONITOR: 0.01
        }
        
        if random.random() < failure_probability.get(task_def.task_type, 0.05):
            raise Exception(f"Simulated failure in {task_def.task_type.value} task")
        
        # Generate mock output
        if task_def.outputs:
            for output in task_def.outputs:
                context[output] = f"output_from_{task_def.id}_{int(time.time())}"
        
        # Log task completion
        task_instance.logs.append(f"Completed {task_def.task_type.value} task: {task_def.name}")
        
        # Update metrics
        task_instance.metrics = {
            "execution_time_seconds": execution_time,
            "memory_usage_mb": random.randint(50, 500),
            "cpu_usage_percent": random.randint(10, 90),
            "io_operations": random.randint(100, 1000)
        }
    
    async def stop_task(self, task_instance: TaskInstance) -> bool:
        """Stop a running task."""
        if task_instance.instance_id in self.active_tasks:
            task_instance.status = TaskStatus.CANCELLED
            task_instance.end_time = datetime.utcnow()
            if task_instance.start_time:
                task_instance.duration_seconds = (
                    task_instance.end_time - task_instance.start_time
                ).total_seconds()
            
            self.completed_tasks.append(task_instance)
            del self.active_tasks[task_instance.instance_id]
            return True
        return False
    
    async def get_task_status(self, task_instance: TaskInstance) -> TaskStatus:
        """Get current status of a task."""
        if task_instance.instance_id in self.active_tasks:
            return self.active_tasks[task_instance.instance_id].status
        return task_instance.status


# ============================================================================
# DAG SCHEDULER
# ============================================================================

class DAGScheduler:
    """Scheduler for managing DAG execution."""
    
    def __init__(self, config: Dict[str, Any]):
        self.config = config
        self.scheduling_strategy = SchedulingStrategy(
            config.get("scheduling_strategy", "resource_aware")
        )
        self.max_parallel_tasks = config.get("max_parallel_tasks", 10)
        self.resource_limits = config.get("resource_limits", {})
        
    def create_execution_plan(self, dag: DAGDefinition, dag_run: DAGRun) -> ExecutionPlan:
        """Create an execution plan for a DAG run."""
        
        # Analyze the DAG
        critical_path, estimated_duration = DAGAnalyzer.analyze_critical_path(dag)
        bottlenecks = DAGAnalyzer.identify_bottlenecks(dag)
        parallelism_info = DAGAnalyzer.calculate_parallelism_opportunities(dag)
        
        # Create execution order based on scheduling strategy
        execution_order = self._create_execution_order(dag, dag_run)
        
        # Allocate resources
        resource_allocation = self._allocate_resources(dag, execution_order)
        
        return ExecutionPlan(
            dag_run_id=dag_run.run_id,
            execution_order=execution_order,
            resource_allocation=resource_allocation,
            estimated_duration=estimated_duration,
            critical_path=critical_path,
            bottlenecks=bottlenecks,
            scheduling_strategy=self.scheduling_strategy,
            parallel_capacity=min(
                self.max_parallel_tasks,
                parallelism_info.get("max_parallel_tasks", 1)
            )
        )
    
    def _create_execution_order(self, dag: DAGDefinition, dag_run: DAGRun) -> List[List[str]]:
        """Create execution order based on scheduling strategy."""
        
        graph = nx.DiGraph()
        
        # Build graph
        for task_id in dag.tasks:
            graph.add_node(task_id)
        
        for task_id, task_def in dag.tasks.items():
            for dep in task_def.dependencies:
                if dep in dag.tasks:
                    graph.add_edge(dep, task_id)
        
        if self.scheduling_strategy == SchedulingStrategy.FIFO:
            return self._fifo_scheduling(graph)
        elif self.scheduling_strategy == SchedulingStrategy.PRIORITY:
            return self._priority_scheduling(graph, dag)
        elif self.scheduling_strategy == SchedulingStrategy.CRITICAL_PATH:
            return self._critical_path_scheduling(graph, dag)
        elif self.scheduling_strategy == SchedulingStrategy.RESOURCE_AWARE:
            return self._resource_aware_scheduling(graph, dag)
        else:
            return self._default_scheduling(graph)
    
    def _fifo_scheduling(self, graph: nx.DiGraph) -> List[List[str]]:
        """FIFO scheduling - simple topological sort."""
        try:
            return [[node] for node in nx.topological_sort(graph)]
        except:
            return [[node] for node in graph.nodes()]
    
    def _priority_scheduling(self, graph: nx.DiGraph, dag: DAGDefinition) -> List[List[str]]:
        """Priority-based scheduling."""
        levels = []
        remaining_nodes = set(graph.nodes())
        
        while remaining_nodes:
            # Find ready tasks (no pending dependencies)
            ready_tasks = []
            for node in remaining_nodes:
                dependencies = [
                    pred for pred in graph.predecessors(node)
                    if pred in remaining_nodes
                ]
                if not dependencies:
                    ready_tasks.append(node)
            
            if not ready_tasks:
                # Handle deadlock
                ready_tasks = [list(remaining_nodes)[0]]
            
            # Sort by priority
            ready_tasks.sort(
                key=lambda x: dag.tasks[x].priority if x in dag.tasks else 5,
                reverse=True
            )
            
            levels.append(ready_tasks)
            remaining_nodes -= set(ready_tasks)
        
        return levels
    
    def _critical_path_scheduling(self, graph: nx.DiGraph, dag: DAGDefinition) -> List[List[str]]:
        """Critical path method scheduling."""
        # Calculate critical path
        critical_path, _ = DAGAnalyzer.analyze_critical_path(dag)
        
        levels = []
        remaining_nodes = set(graph.nodes())
        critical_path_set = set(critical_path)
        
        while remaining_nodes:
            ready_tasks = []
            for node in remaining_nodes:
                dependencies = [
                    pred for pred in graph.predecessors(node)
                    if pred in remaining_nodes
                ]
                if not dependencies:
                    ready_tasks.append(node)
            
            if not ready_tasks:
                ready_tasks = [list(remaining_nodes)[0]]
            
            # Prioritize critical path tasks
            critical_tasks = [t for t in ready_tasks if t in critical_path_set]
            non_critical_tasks = [t for t in ready_tasks if t not in critical_path_set]
            
            if critical_tasks:
                levels.append(critical_tasks)
                remaining_nodes -= set(critical_tasks)
            
            if non_critical_tasks and len(levels) == 0 or len(levels[-1]) < self.max_parallel_tasks:
                if levels:
                    levels[-1].extend(non_critical_tasks[:self.max_parallel_tasks - len(levels[-1])])
                    remaining_nodes -= set(non_critical_tasks[:self.max_parallel_tasks - len(levels[-1])])
                else:
                    levels.append(non_critical_tasks)
                    remaining_nodes -= set(non_critical_tasks)
        
        return levels
    
    def _resource_aware_scheduling(self, graph: nx.DiGraph, dag: DAGDefinition) -> List[List[str]]:
        """Resource-aware scheduling."""
        levels = []
        remaining_nodes = set(graph.nodes())
        
        while remaining_nodes:
            ready_tasks = []
            for node in remaining_nodes:
                dependencies = [
                    pred for pred in graph.predecessors(node)
                    if pred in remaining_nodes
                ]
                if not dependencies:
                    ready_tasks.append(node)
            
            if not ready_tasks:
                ready_tasks = [list(remaining_nodes)[0]]
            
            # Group tasks by resource requirements
            current_level = []
            current_resources = defaultdict(int)
            
            # Sort by resource requirements (lighter first for better packing)
            ready_tasks.sort(
                key=lambda x: sum(dag.tasks[x].resources.values()) if x in dag.tasks else 0
            )
            
            for task in ready_tasks:
                if task in dag.tasks:
                    task_resources = dag.tasks[task].resources
                    
                    # Check if adding this task would exceed resource limits
                    can_add = True
                    for resource_type, amount in task_resources.items():
                        if (current_resources[resource_type] + amount > 
                            self.resource_limits.get(resource_type, float('inf'))):
                            can_add = False
                            break
                    
                    if can_add and len(current_level) < self.max_parallel_tasks:
                        current_level.append(task)
                        for resource_type, amount in task_resources.items():
                            current_resources[resource_type] += amount
                else:
                    if len(current_level) < self.max_parallel_tasks:
                        current_level.append(task)
            
            if not current_level:
                current_level = [ready_tasks[0]]
            
            levels.append(current_level)
            remaining_nodes -= set(current_level)
        
        return levels
    
    def _default_scheduling(self, graph: nx.DiGraph) -> List[List[str]]:
        """Default scheduling with parallelism."""
        levels = []
        remaining_nodes = set(graph.nodes())
        
        while remaining_nodes:
            current_level = []
            for node in list(remaining_nodes):
                dependencies = [
                    pred for pred in graph.predecessors(node)
                    if pred in remaining_nodes
                ]
                if not dependencies:
                    current_level.append(node)
                    if len(current_level) >= self.max_parallel_tasks:
                        break
            
            if not current_level:
                current_level = [list(remaining_nodes)[0]]
            
            levels.append(current_level)
            remaining_nodes -= set(current_level)
        
        return levels
    
    def _allocate_resources(self, dag: DAGDefinition, execution_order: List[List[str]]) -> Dict[str, str]:
        """Allocate resources to tasks."""
        allocation = {}
        available_resources = list(self.resource_limits.keys()) if self.resource_limits else ["default"]
        
        if not available_resources:
            available_resources = ["default"]
        
        resource_index = 0
        
        for level in execution_order:
            for task_id in level:
                if task_id in dag.tasks:
                    # Simple round-robin allocation
                    allocation[task_id] = available_resources[resource_index % len(available_resources)]
                    resource_index += 1
                else:
                    allocation[task_id] = "default"
        
        return allocation


# ============================================================================
# DAG ORCHESTRATOR
# ============================================================================

class DAGOrchestrator:
    """
    Main orchestrator for managing DAG workflows.
    Integrates validation, scheduling, execution, and monitoring.
    """
    
    def __init__(self, config: Dict[str, Any]):
        self.config = config
        self.dags: Dict[str, DAGDefinition] = {}
        self.dag_runs: Dict[str, DAGRun] = {}
        self.scheduler = DAGScheduler(config.get("scheduler", {}))
        self.executors: Dict[str, BaseTaskExecutor] = {}
        self.active_runs: Dict[str, ExecutionPlan] = {}
        self.metrics = defaultdict(float)
        
        # Initialize executors
        self._initialize_executors()
        
        logger.info("DAG Orchestrator initialized")
    
    def _initialize_executors(self):
        """Initialize task executors."""
        executor_config = self.config.get("executors", {})
        
        # Local executor
        self.executors["local"] = LocalTaskExecutor(
            "local", executor_config.get("local", {})
        )
        
        logger.info(f"Initialized {len(self.executors)} executors")
    
    def register_dag(self, dag: DAGDefinition) -> bool:
        """Register a new DAG."""
        try:
            # Validate DAG
            is_valid, errors = DAGValidator.validate_dag(dag)
            if not is_valid:
                logger.error(f"DAG validation failed for {dag.dag_id}: {errors}")
                return False
            
            self.dags[dag.dag_id] = dag
            logger.info(f"Registered DAG: {dag.dag_id} with {len(dag.tasks)} tasks")
            return True
            
        except Exception as e:
            logger.error(f"Failed to register DAG {dag.dag_id}: {e}")
            return False
    
    def unregister_dag(self, dag_id: str) -> bool:
        """Unregister a DAG."""
        if dag_id in self.dags:
            # Check for active runs
            active_runs_for_dag = [
                run_id for run_id, run in self.dag_runs.items()
                if run.dag_id == dag_id and run.status in [TaskStatus.PENDING, TaskStatus.RUNNING]
            ]
            
            if active_runs_for_dag:
                logger.warning(f"Cannot unregister DAG {dag_id}: has active runs {active_runs_for_dag}")
                return False
            
            del self.dags[dag_id]
            logger.info(f"Unregistered DAG: {dag_id}")
            return True
        
        return False
    
    async def trigger_dag(self, dag_id: str, **kwargs) -> Optional[str]:
        """Trigger a DAG execution."""
        if dag_id not in self.dags:
            logger.error(f"DAG {dag_id} not found")
            return None
        
        dag = self.dags[dag_id]
        
        # Create DAG run
        run_id = f"{dag_id}_{int(time.time())}_{uuid.uuid4().hex[:8]}"
        execution_date = kwargs.get("execution_date", datetime.utcnow())
        
        dag_run = DAGRun(
            run_id=run_id,
            dag_id=dag_id,
            execution_date=execution_date,
            external_trigger=True,
            run_type="manual",
            conf=kwargs.get("conf", {})
        )
        
        # Create task instances
        for task_id, task_def in dag.tasks.items():
            instance_id = f"{run_id}_{task_id}"
            task_instance = TaskInstance(
                task_id=task_id,
                instance_id=instance_id,
                dag_run_id=run_id
            )
            dag_run.task_instances[task_id] = task_instance
        
        self.dag_runs[run_id] = dag_run
        
        # Create execution plan
        execution_plan = self.scheduler.create_execution_plan(dag, dag_run)
        self.active_runs[run_id] = execution_plan
        
        # Start execution
        asyncio.create_task(self._execute_dag_run(dag_run, execution_plan))
        
        logger.info(f"Triggered DAG run: {run_id}")
        return run_id
    
    async def _execute_dag_run(self, dag_run: DAGRun, execution_plan: ExecutionPlan):
        """Execute a DAG run according to the execution plan."""
        
        dag_run.status = TaskStatus.RUNNING
        dag_run.start_date = datetime.utcnow()
        
        dag = self.dags[dag_run.dag_id]
        context = dict(dag_run.conf)
        
        try:
            logger.info(f"Starting DAG run {dag_run.run_id} with {len(execution_plan.execution_order)} levels")
            
            # Execute levels sequentially, tasks within levels in parallel
            for level_index, task_level in enumerate(execution_plan.execution_order):
                logger.info(f"Executing level {level_index + 1}/{len(execution_plan.execution_order)}: {task_level}")
                
                # Create tasks for parallel execution
                level_tasks = []
                for task_id in task_level:
                    if task_id in dag.tasks and task_id in dag_run.task_instances:
                        task_def = dag.tasks[task_id]
                        task_instance = dag_run.task_instances[task_id]
                        
                        # Select executor
                        executor_id = execution_plan.resource_allocation.get(task_id, "local")
                        executor = self.executors.get(executor_id, self.executors["local"])
                        
                        # Create execution task
                        task_coroutine = self._execute_task_with_retry(
                            executor, task_def, task_instance, context
                        )
                        level_tasks.append(task_coroutine)
                
                # Execute all tasks in the level
                if level_tasks:
                    level_results = await asyncio.gather(*level_tasks, return_exceptions=True)
                    
                    # Check results
                    level_success = True
                    for i, result in enumerate(level_results):
                        task_id = task_level[i] if i < len(task_level) else "unknown"
                        if isinstance(result, Exception):
                            logger.error(f"Task {task_id} failed with exception: {result}")
                            level_success = False
                        elif not result:
                            logger.error(f"Task {task_id} failed")
                            level_success = False
                    
                    # Check if we should continue after failures
                    if not level_success:
                        # Check if any failed task is critical
                        critical_failed = False
                        for task_id in task_level:
                            if task_id in dag.tasks:
                                task_instance = dag_run.task_instances[task_id]
                                if (task_instance.status == TaskStatus.FAILED and 
                                    dag.tasks[task_id].metadata.get("critical", False)):
                                    critical_failed = True
                                    break
                        
                        if critical_failed:
                            logger.error(f"Critical task failed in level {level_index + 1}, stopping DAG run")
                            break
            
            # Determine final status
            failed_tasks = [
                ti for ti in dag_run.task_instances.values()
                if ti.status == TaskStatus.FAILED
            ]
            
            if not failed_tasks:
                dag_run.status = TaskStatus.SUCCESS
            else:
                dag_run.status = TaskStatus.FAILED
            
            dag_run.end_date = datetime.utcnow()
            
            # Calculate metrics
            if dag_run.start_date:
                total_duration = (dag_run.end_date - dag_run.start_date).total_seconds()
                dag_run.metrics = {
                    "total_duration_seconds": total_duration,
                    "successful_tasks": len([
                        ti for ti in dag_run.task_instances.values()
                        if ti.status == TaskStatus.SUCCESS
                    ]),
                    "failed_tasks": len(failed_tasks),
                    "total_tasks": len(dag_run.task_instances)
                }
            
            # Update orchestrator metrics
            self.metrics["total_dag_runs"] += 1
            if dag_run.status == TaskStatus.SUCCESS:
                self.metrics["successful_dag_runs"] += 1
            else:
                self.metrics["failed_dag_runs"] += 1
            
            self.metrics["success_rate"] = (
                self.metrics["successful_dag_runs"] / self.metrics["total_dag_runs"]
            )
            
            logger.info(f"DAG run {dag_run.run_id} completed with status: {dag_run.status}")
            
        except Exception as e:
            logger.error(f"DAG run {dag_run.run_id} failed with error: {e}")
            dag_run.status = TaskStatus.FAILED
            dag_run.end_date = datetime.utcnow()
        
        finally:
            # Clean up
            if dag_run.run_id in self.active_runs:
                del self.active_runs[dag_run.run_id]
    
    async def _execute_task_with_retry(self, executor: BaseTaskExecutor, 
                                     task_def: TaskDefinition, task_instance: TaskInstance,
                                     context: Dict[str, Any]) -> bool:
        """Execute a task with retry logic."""
        
        max_attempts = task_def.max_retries + 1
        
        for attempt in range(max_attempts):
            task_instance.attempt_number = attempt + 1
            
            try:
                success = await executor.execute_task(task_def, task_instance, context)
                if success:
                    return True
                
                # Task failed, check if we should retry
                if attempt < max_attempts - 1:
                    retry_delay = self._calculate_retry_delay(task_def.retry_policy, attempt)
                    logger.info(f"Task {task_def.id} failed, retrying in {retry_delay}s (attempt {attempt + 1}/{max_attempts})")
                    task_instance.status = TaskStatus.RETRY
                    task_instance.retry_at = datetime.utcnow() + timedelta(seconds=retry_delay)
                    await asyncio.sleep(retry_delay)
                else:
                    logger.error(f"Task {task_def.id} failed after {max_attempts} attempts")
                    return False
                    
            except Exception as e:
                logger.error(f"Task {task_def.id} execution error: {e}")
                if attempt >= max_attempts - 1:
                    task_instance.error_message = str(e)
                    return False
                
                # Wait before retry
                if attempt < max_attempts - 1:
                    retry_delay = self._calculate_retry_delay(task_def.retry_policy, attempt)
                    await asyncio.sleep(retry_delay)
        
        return False
    
    def _calculate_retry_delay(self, retry_policy: RetryPolicy, attempt: int) -> float:
        """Calculate retry delay based on policy."""
        
        if retry_policy == RetryPolicy.NO_RETRY:
            return 0
        elif retry_policy == RetryPolicy.FIXED_INTERVAL:
            return 30  # 30 seconds
        elif retry_policy == RetryPolicy.EXPONENTIAL_BACKOFF:
            return min(2 ** attempt, 300)  # Max 5 minutes
        elif retry_policy == RetryPolicy.LINEAR_BACKOFF:
            return min(30 * (attempt + 1), 300)  # 30s, 60s, 90s, etc., max 5 minutes
        else:
            return 60  # Default 1 minute
    
    def get_dag_run_status(self, run_id: str) -> Optional[Dict[str, Any]]:
        """Get status of a DAG run."""
        if run_id not in self.dag_runs:
            return None
        
        dag_run = self.dag_runs[run_id]
        
        return {
            "run_id": run_id,
            "dag_id": dag_run.dag_id,
            "status": dag_run.status.value,
            "start_date": dag_run.start_date.isoformat() if dag_run.start_date else None,
            "end_date": dag_run.end_date.isoformat() if dag_run.end_date else None,
            "task_instances": {
                task_id: {
                    "status": ti.status.value,
                    "start_time": ti.start_time.isoformat() if ti.start_time else None,
                    "end_time": ti.end_time.isoformat() if ti.end_time else None,
                    "duration_seconds": ti.duration_seconds,
                    "attempt_number": ti.attempt_number,
                    "error_message": ti.error_message
                }
                for task_id, ti in dag_run.task_instances.items()
            },
            "metrics": dag_run.metrics
        }
    
    def list_dags(self) -> List[Dict[str, Any]]:
        """List all registered DAGs."""
        return [
            {
                "dag_id": dag.dag_id,
                "name": dag.name,
                "description": dag.description,
                "task_count": len(dag.tasks),
                "schedule_interval": dag.schedule_interval,
                "tags": list(dag.tags),
                "created_at": dag.created_at.isoformat(),
                "updated_at": dag.updated_at.isoformat()
            }
            for dag in self.dags.values()
        ]
    
    def get_orchestrator_metrics(self) -> Dict[str, Any]:
        """Get orchestrator metrics."""
        return {
            "registered_dags": len(self.dags),
            "active_runs": len(self.active_runs),
            "total_runs": len(self.dag_runs),
            "metrics": dict(self.metrics),
            "executor_metrics": {
                exec_id: dict(executor.metrics)
                for exec_id, executor in self.executors.items()
            }
        }


# ============================================================================
# EXAMPLE DAG FACTORY
# ============================================================================

class DAGFactory:
    """Factory for creating common DAG patterns."""
    
    @staticmethod
    def create_etl_dag(dag_id: str, name: str, sources: List[str], 
                      target: str, **kwargs) -> DAGDefinition:
        """Create a standard ETL DAG."""
        
        dag = DAGDefinition(
            dag_id=dag_id,
            name=name,
            description=f"ETL pipeline from {sources} to {target}",
            schedule_interval=kwargs.get("schedule_interval"),
            tags={"etl", "data_pipeline"}
        )
        
        # Extract tasks
        extract_tasks = []
        for i, source in enumerate(sources):
            task_id = f"extract_{source}_{i}"
            extract_task = TaskDefinition(
                id=task_id,
                name=f"Extract from {source}",
                task_type=TaskType.EXTRACT,
                description=f"Extract data from {source}",
                parameters={"source": source},
                outputs=[f"raw_data_{i}"],
                resources={"memory": 2, "cpu": 1}
            )
            dag.tasks[task_id] = extract_task
            extract_tasks.append(task_id)
        
        # Transform task
        transform_task = TaskDefinition(
            id="transform_data",
            name="Transform Data",
            task_type=TaskType.TRANSFORM,
            description="Transform and clean extracted data",
            inputs=[f"raw_data_{i}" for i in range(len(sources))],
            outputs=["transformed_data"],
            dependencies=extract_tasks,
            resources={"memory": 4, "cpu": 2}
        )
        dag.tasks["transform_data"] = transform_task
        
        # Validate task
        validate_task = TaskDefinition(
            id="validate_data",
            name="Validate Data",
            task_type=TaskType.VALIDATE,
            description="Validate transformed data quality",
            inputs=["transformed_data"],
            outputs=["validation_results"],
            dependencies=["transform_data"],
            resources={"memory": 1, "cpu": 1}
        )
        dag.tasks["validate_data"] = validate_task
        
        # Load task
        load_task = TaskDefinition(
            id="load_data",
            name="Load Data",
            task_type=TaskType.LOAD,
            description=f"Load data to {target}",
            parameters={"target": target},
            inputs=["transformed_data", "validation_results"],
            dependencies=["validate_data"],
            resources={"memory": 2, "cpu": 1}
        )
        dag.tasks["load_data"] = load_task
        
        # Cleanup task
        cleanup_task = TaskDefinition(
            id="cleanup",
            name="Cleanup",
            task_type=TaskType.CLEANUP,
            description="Clean up temporary files and resources",
            dependencies=["load_data"],
            resources={"memory": 1, "cpu": 1}
        )
        dag.tasks["cleanup"] = cleanup_task
        
        return dag
    
    @staticmethod
    def create_ml_training_dag(dag_id: str, name: str, dataset: str, 
                              model_type: str, **kwargs) -> DAGDefinition:
        """Create a machine learning training DAG."""
        
        dag = DAGDefinition(
            dag_id=dag_id,
            name=name,
            description=f"ML training pipeline for {model_type} on {dataset}",
            tags={"ml", "training", model_type}
        )
        
        # Data preparation
        prep_task = TaskDefinition(
            id="prepare_data",
            name="Prepare Training Data",
            task_type=TaskType.EXTRACT,
            description="Load and prepare training dataset",
            parameters={"dataset": dataset},
            outputs=["training_data", "validation_data"],
            resources={"memory": 8, "cpu": 2}
        )
        dag.tasks["prepare_data"] = prep_task
        
        # Feature engineering
        feature_task = TaskDefinition(
            id="feature_engineering",
            name="Feature Engineering",
            task_type=TaskType.TRANSFORM,
            description="Create and select features",
            inputs=["training_data"],
            outputs=["features"],
            dependencies=["prepare_data"],
            resources={"memory": 4, "cpu": 2}
        )
        dag.tasks["feature_engineering"] = feature_task
        
        # Model training
        train_task = TaskDefinition(
            id="train_model",
            name="Train Model",
            task_type=TaskType.COMPUTE,
            description=f"Train {model_type} model",
            parameters={"model_type": model_type},
            inputs=["features"],
            outputs=["trained_model"],
            dependencies=["feature_engineering"],
            resources={"memory": 16, "cpu": 4, "gpu": 1},
            timeout_seconds=7200,  # 2 hours
            metadata={"critical": True}
        )
        dag.tasks["train_model"] = train_task
        
        # Model validation
        validate_task = TaskDefinition(
            id="validate_model",
            name="Validate Model",
            task_type=TaskType.VALIDATE,
            description="Validate model performance",
            inputs=["trained_model", "validation_data"],
            outputs=["validation_metrics"],
            dependencies=["train_model"],
            resources={"memory": 4, "cpu": 2}
        )
        dag.tasks["validate_model"] = validate_task
        
        # Model deployment decision
        deploy_decision = TaskDefinition(
            id="deployment_decision",
            name="Deployment Decision",
            task_type=TaskType.DECISION,
            description="Decide if model should be deployed",
            parameters={"criteria": {"accuracy": 0.85}, "threshold": 0.8},
            inputs=["validation_metrics"],
            dependencies=["validate_model"],
            resources={"memory": 1, "cpu": 1}
        )
        dag.tasks["deployment_decision"] = deploy_decision
        
        return dag


# ============================================================================
# MAIN USAGE EXAMPLE
# ============================================================================

async def main():
    """Example usage of the DAG Orchestrator."""
    
    # Initialize orchestrator
    config = {
        "scheduler": {
            "scheduling_strategy": "critical_path",
            "max_parallel_tasks": 5,
            "resource_limits": {"memory": 32, "cpu": 8, "gpu": 2}
        },
        "executors": {
            "local": {"max_workers": 4}
        }
    }
    
    orchestrator = DAGOrchestrator(config)
    
    # Create example DAGs
    print("Creating example DAGs...")
    
    # ETL DAG
    etl_dag = DAGFactory.create_etl_dag(
        "etl_pipeline_001",
        "Daily ETL Pipeline",
        sources=["source_db_1", "source_db_2", "api_endpoint"],
        target="data_warehouse",
        schedule_interval="0 2 * * *"  # Daily at 2 AM
    )
    
    # ML Training DAG
    ml_dag = DAGFactory.create_ml_training_dag(
        "ml_training_001",
        "Customer Churn Model Training",
        dataset="customer_data",
        model_type="random_forest"
    )
    
    # Register DAGs
    orchestrator.register_dag(etl_dag)
    orchestrator.register_dag(ml_dag)
    
    # List registered DAGs
    print("\nRegistered DAGs:")
    for dag_info in orchestrator.list_dags():
        print(f"  - {dag_info['dag_id']}: {dag_info['name']} ({dag_info['task_count']} tasks)")
    
    # Trigger ETL DAG
    print(f"\n{'='*50}")
    print("Triggering ETL DAG...")
    print(f"{'='*50}")
    
    etl_run_id = await orchestrator.trigger_dag(
        "etl_pipeline_001",
        conf={"batch_size": 10000, "quality_threshold": 0.95}
    )
    
    if etl_run_id:
        print(f"ETL DAG run started: {etl_run_id}")
        
        # Wait a bit and check status
        await asyncio.sleep(5)
        
        etl_status = orchestrator.get_dag_run_status(etl_run_id)
        if etl_status:
            print(f"ETL Status: {etl_status['status']}")
            print(f"Tasks: {len(etl_status['task_instances'])}")
    
    # Trigger ML Training DAG
    print(f"\n{'='*50}")
    print("Triggering ML Training DAG...")
    print(f"{'='*50}")
    
    ml_run_id = await orchestrator.trigger_dag(
        "ml_training_001",
        conf={"hyperparameters": {"n_estimators": 100, "max_depth": 10}}
    )
    
    if ml_run_id:
        print(f"ML DAG run started: {ml_run_id}")
        
        # Wait for completion (longer for ML training)
        await asyncio.sleep(15)
        
        ml_status = orchestrator.get_dag_run_status(ml_run_id)
        if ml_status:
            print(f"ML Status: {ml_status['status']}")
            print(f"Tasks: {len(ml_status['task_instances'])}")
            
            # Show individual task statuses
            for task_id, task_info in ml_status['task_instances'].items():
                print(f"  - {task_id}: {task_info['status']}")
                if task_info['duration_seconds']:
                    print(f"    Duration: {task_info['duration_seconds']:.2f}s")
    
    # Get orchestrator metrics
    print(f"\n{'='*50}")
    print("Orchestrator Metrics")
    print(f"{'='*50}")
    
    metrics = orchestrator.get_orchestrator_metrics()
    print(json.dumps(metrics, indent=2, default=str))


if __name__ == "__main__":
    asyncio.run(main())
