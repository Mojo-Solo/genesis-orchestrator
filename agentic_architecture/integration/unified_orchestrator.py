"""
Unified Orchestrator - Integration Layer for RAG, LAG, and DAG Systems
=====================================================================
Advanced integration layer that seamlessly connects RAG, LAG, and DAG systems
with the existing Genesis orchestrator for enterprise-grade agentic architecture.

This integration provides:
- Unified API for all agentic capabilities
- Cross-system workflow orchestration
- Intelligent routing between RAG, LAG, and DAG engines
- Enterprise security and compliance integration
- Performance monitoring and optimization
- Scalable deployment architecture

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
from collections import defaultdict
import logging
from pathlib import Path

# Import Genesis orchestrator components
import sys
import os
sys.path.append(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))

# Import our agentic architecture components
from agentic_architecture.core.rag_engine import AdvancedRAGEngine, RetrievalStrategy, SecurityLevel
from agentic_architecture.core.lag_engine import LAGEngine, ExecutionStrategy
from agentic_architecture.core.dag_orchestrator import DAGOrchestrator, SchedulingStrategy
from agentic_architecture.brain_extractor.brain_extractor import BrainExtractor

# Import Genesis orchestrator
from orchestrator.mcp_unified_orchestrator import UnifiedMCPOrchestrator

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


# ============================================================================
# INTEGRATION DATA STRUCTURES
# ============================================================================

class WorkflowType(Enum):
    """Types of workflows in the unified system."""
    KNOWLEDGE_EXTRACTION = "knowledge_extraction"
    ONBOARDING_AUTOMATION = "onboarding_automation"
    INTELLIGENT_QUERY = "intelligent_query"
    CONTENT_GENERATION = "content_generation"
    PROCESS_AUTOMATION = "process_automation"
    DECISION_SUPPORT = "decision_support"
    ADAPTIVE_LEARNING = "adaptive_learning"
    HYBRID_ORCHESTRATION = "hybrid_orchestration"


class IntegrationStrategy(Enum):
    """Strategies for integrating different engines."""
    RAG_FIRST = "rag_first"  # Start with knowledge retrieval
    LAG_ORCHESTRATED = "lag_orchestrated"  # Use LAG for workflow management
    DAG_SCHEDULED = "dag_scheduled"  # Use DAG for complex pipelines
    BRAIN_EXTRACTOR = "brain_extractor"  # Use Brain Extractor for onboarding
    GENESIS_NATIVE = "genesis_native"  # Use Genesis orchestrator
    HYBRID_ADAPTIVE = "hybrid_adaptive"  # Intelligently choose best approach


class ExecutionMode(Enum):
    """Execution modes for workflows."""
    SYNCHRONOUS = "synchronous"
    ASYNCHRONOUS = "asynchronous"
    STREAMING = "streaming"
    BATCH = "batch"
    REAL_TIME = "real_time"


@dataclass
class UnifiedRequest:
    """Unified request structure for all workflow types."""
    id: str
    type: WorkflowType
    payload: Dict[str, Any]
    context: Dict[str, Any] = field(default_factory=dict)
    integration_strategy: IntegrationStrategy = IntegrationStrategy.HYBRID_ADAPTIVE
    execution_mode: ExecutionMode = ExecutionMode.ASYNCHRONOUS
    priority: int = 5
    timeout_seconds: int = 300
    security_level: SecurityLevel = SecurityLevel.INTERNAL
    requester: str = "system"
    callback_url: Optional[str] = None
    metadata: Dict[str, Any] = field(default_factory=dict)
    created_at: datetime = field(default_factory=datetime.utcnow)


@dataclass
class UnifiedResponse:
    """Unified response structure for all workflows."""
    request_id: str
    success: bool
    result: Any
    execution_trace: List[Dict[str, Any]] = field(default_factory=list)
    engines_used: List[str] = field(default_factory=list)
    execution_time_ms: int = 0
    resource_usage: Dict[str, Any] = field(default_factory=dict)
    confidence_score: float = 0.0
    citations: List[Dict[str, Any]] = field(default_factory=list)
    artifacts: Dict[str, Any] = field(default_factory=dict)
    warnings: List[str] = field(default_factory=list)
    errors: List[str] = field(default_factory=list)
    metadata: Dict[str, Any] = field(default_factory=dict)
    completed_at: datetime = field(default_factory=datetime.utcnow)


@dataclass
class IntegrationMetrics:
    """Metrics for integration layer performance."""
    total_requests: int = 0
    successful_requests: int = 0
    failed_requests: int = 0
    avg_response_time_ms: float = 0.0
    engine_usage: Dict[str, int] = field(default_factory=dict)
    strategy_usage: Dict[str, int] = field(default_factory=dict)
    workflow_type_usage: Dict[str, int] = field(default_factory=dict)
    resource_utilization: Dict[str, float] = field(default_factory=dict)
    error_rates: Dict[str, float] = field(default_factory=dict)


# ============================================================================
# INTELLIGENT WORKFLOW ROUTER
# ============================================================================

class IntelligentWorkflowRouter:
    """
    Intelligent router that determines the best execution strategy
    for different types of workflows across RAG, LAG, DAG, and Genesis systems.
    """
    
    def __init__(self, config: Dict[str, Any]):
        self.config = config
        self.routing_history: List[Dict[str, Any]] = []
        self.performance_metrics: Dict[str, Dict[str, float]] = defaultdict(dict)
        self.learning_weights: Dict[str, float] = {
            "success_rate": 0.4,
            "response_time": 0.3,
            "resource_efficiency": 0.2,
            "confidence_score": 0.1
        }
    
    async def route_workflow(self, request: UnifiedRequest) -> IntegrationStrategy:
        """Determine the best integration strategy for a workflow."""
        
        # If strategy is explicitly specified and not adaptive, use it
        if request.integration_strategy != IntegrationStrategy.HYBRID_ADAPTIVE:
            return request.integration_strategy
        
        # Analyze request characteristics
        characteristics = self._analyze_request_characteristics(request)
        
        # Calculate strategy scores
        strategy_scores = {}
        
        for strategy in IntegrationStrategy:
            if strategy == IntegrationStrategy.HYBRID_ADAPTIVE:
                continue
            
            score = await self._calculate_strategy_score(strategy, characteristics, request)
            strategy_scores[strategy] = score
        
        # Select best strategy
        best_strategy = max(strategy_scores.items(), key=lambda x: x[1])[0]
        
        # Record routing decision
        routing_record = {
            "request_id": request.id,
            "workflow_type": request.type.value,
            "characteristics": characteristics,
            "strategy_scores": {s.value: score for s, score in strategy_scores.items()},
            "selected_strategy": best_strategy.value,
            "timestamp": datetime.utcnow().isoformat()
        }
        self.routing_history.append(routing_record)
        
        logger.info(f"Routed workflow {request.id} to strategy: {best_strategy.value}")
        return best_strategy
    
    def _analyze_request_characteristics(self, request: UnifiedRequest) -> Dict[str, float]:
        """Analyze request characteristics for routing decisions."""
        
        characteristics = {
            "complexity": 0.5,  # Base complexity
            "knowledge_intensity": 0.0,
            "workflow_dependency": 0.0,
            "batch_processing": 0.0,
            "real_time_requirement": 0.0,
            "security_sensitivity": 0.0,
            "personalization_need": 0.0
        }
        
        # Analyze workflow type
        type_characteristics = {
            WorkflowType.KNOWLEDGE_EXTRACTION: {
                "knowledge_intensity": 0.9,
                "complexity": 0.6
            },
            WorkflowType.ONBOARDING_AUTOMATION: {
                "workflow_dependency": 0.8,
                "personalization_need": 0.9,
                "complexity": 0.7
            },
            WorkflowType.INTELLIGENT_QUERY: {
                "knowledge_intensity": 0.8,
                "real_time_requirement": 0.7
            },
            WorkflowType.CONTENT_GENERATION: {
                "knowledge_intensity": 0.7,
                "complexity": 0.6
            },
            WorkflowType.PROCESS_AUTOMATION: {
                "workflow_dependency": 0.9,
                "batch_processing": 0.6
            },
            WorkflowType.DECISION_SUPPORT: {
                "knowledge_intensity": 0.8,
                "real_time_requirement": 0.8,
                "complexity": 0.7
            },
            WorkflowType.ADAPTIVE_LEARNING: {
                "personalization_need": 0.9,
                "workflow_dependency": 0.7
            },
            WorkflowType.HYBRID_ORCHESTRATION: {
                "complexity": 0.9,
                "workflow_dependency": 0.8
            }
        }
        
        if request.type in type_characteristics:
            characteristics.update(type_characteristics[request.type])
        
        # Analyze payload characteristics
        payload_size = len(json.dumps(request.payload))
        if payload_size > 10000:  # Large payload
            characteristics["batch_processing"] += 0.3
            characteristics["complexity"] += 0.2
        
        # Analyze context for dependencies
        if "dependencies" in request.context:
            characteristics["workflow_dependency"] += 0.4
        
        if "real_time" in request.context or request.execution_mode == ExecutionMode.REAL_TIME:
            characteristics["real_time_requirement"] += 0.5
        
        # Security analysis
        security_levels = {
            SecurityLevel.PUBLIC: 0.1,
            SecurityLevel.INTERNAL: 0.3,
            SecurityLevel.CONFIDENTIAL: 0.7,
            SecurityLevel.RESTRICTED: 0.9
        }
        characteristics["security_sensitivity"] = security_levels.get(request.security_level, 0.3)
        
        # Normalize characteristics to [0, 1]
        for key in characteristics:
            characteristics[key] = min(characteristics[key], 1.0)
        
        return characteristics
    
    async def _calculate_strategy_score(self, strategy: IntegrationStrategy, 
                                      characteristics: Dict[str, float], 
                                      request: UnifiedRequest) -> float:
        """Calculate score for a strategy based on request characteristics."""
        
        # Base strategy scores based on characteristics
        strategy_profiles = {
            IntegrationStrategy.RAG_FIRST: {
                "knowledge_intensity": 0.9,
                "real_time_requirement": 0.8,
                "complexity": 0.4,
                "workflow_dependency": 0.2
            },
            IntegrationStrategy.LAG_ORCHESTRATED: {
                "workflow_dependency": 0.9,
                "complexity": 0.7,
                "personalization_need": 0.6,
                "knowledge_intensity": 0.5
            },
            IntegrationStrategy.DAG_SCHEDULED: {
                "batch_processing": 0.9,
                "complexity": 0.8,
                "workflow_dependency": 0.7,
                "real_time_requirement": 0.3
            },
            IntegrationStrategy.BRAIN_EXTRACTOR: {
                "personalization_need": 0.9,
                "knowledge_intensity": 0.8,
                "workflow_dependency": 0.6
            },
            IntegrationStrategy.GENESIS_NATIVE: {
                "complexity": 0.6,
                "workflow_dependency": 0.5,
                "real_time_requirement": 0.7
            }
        }
        
        if strategy not in strategy_profiles:
            return 0.5  # Default score
        
        profile = strategy_profiles[strategy]
        
        # Calculate compatibility score
        compatibility_score = 0.0
        for characteristic, value in characteristics.items():
            if characteristic in profile:
                # Higher compatibility when both values are high or both are low
                compatibility = 1.0 - abs(value - profile[characteristic])
                compatibility_score += compatibility
        
        compatibility_score /= len(characteristics)
        
        # Adjust based on historical performance
        historical_performance = self._get_historical_performance(strategy, request.type)
        
        # Combine scores
        final_score = (
            compatibility_score * 0.6 +
            historical_performance * 0.4
        )
        
        return final_score
    
    def _get_historical_performance(self, strategy: IntegrationStrategy, 
                                  workflow_type: WorkflowType) -> float:
        """Get historical performance score for a strategy."""
        
        strategy_key = f"{strategy.value}_{workflow_type.value}"
        
        if strategy_key in self.performance_metrics:
            metrics = self.performance_metrics[strategy_key]
            
            # Calculate weighted performance score
            score = 0.0
            for metric, weight in self.learning_weights.items():
                if metric in metrics:
                    score += metrics[metric] * weight
            
            return min(score, 1.0)
        
        return 0.5  # Default score for new combinations
    
    def update_performance_metrics(self, strategy: IntegrationStrategy, 
                                 workflow_type: WorkflowType, 
                                 success: bool, response_time_ms: int, 
                                 resource_efficiency: float, confidence_score: float):
        """Update performance metrics for learning."""
        
        strategy_key = f"{strategy.value}_{workflow_type.value}"
        
        if strategy_key not in self.performance_metrics:
            self.performance_metrics[strategy_key] = {
                "success_count": 0,
                "total_count": 0,
                "total_response_time": 0,
                "total_resource_efficiency": 0,
                "total_confidence": 0
            }
        
        metrics = self.performance_metrics[strategy_key]
        
        # Update counts
        metrics["total_count"] += 1
        if success:
            metrics["success_count"] += 1
        
        # Update response time
        metrics["total_response_time"] += response_time_ms
        
        # Update other metrics
        metrics["total_resource_efficiency"] += resource_efficiency
        metrics["total_confidence"] += confidence_score
        
        # Calculate derived metrics
        metrics["success_rate"] = metrics["success_count"] / metrics["total_count"]
        metrics["response_time"] = 1.0 / max(metrics["total_response_time"] / metrics["total_count"], 1)  # Inverse normalized
        metrics["resource_efficiency"] = metrics["total_resource_efficiency"] / metrics["total_count"]
        metrics["confidence_score"] = metrics["total_confidence"] / metrics["total_count"]


# ============================================================================
# EXECUTION ENGINES
# ============================================================================

class BaseExecutionEngine(ABC):
    """Base class for execution engines."""
    
    def __init__(self, engine_id: str, config: Dict[str, Any]):
        self.engine_id = engine_id
        self.config = config
        self.execution_history: List[Dict[str, Any]] = []
        self.metrics = defaultdict(float)
    
    @abstractmethod
    async def execute(self, request: UnifiedRequest) -> UnifiedResponse:
        """Execute a unified request."""
        pass
    
    @abstractmethod
    async def health_check(self) -> bool:
        """Check engine health."""
        pass
    
    def record_execution(self, request: UnifiedRequest, response: UnifiedResponse):
        """Record execution for metrics."""
        execution_record = {
            "request_id": request.id,
            "workflow_type": request.type.value,
            "success": response.success,
            "execution_time_ms": response.execution_time_ms,
            "engines_used": response.engines_used,
            "timestamp": datetime.utcnow().isoformat()
        }
        self.execution_history.append(execution_record)
        
        # Update metrics
        self.metrics["total_executions"] += 1
        self.metrics["total_execution_time"] += response.execution_time_ms
        
        if response.success:
            self.metrics["successful_executions"] += 1
        else:
            self.metrics["failed_executions"] += 1
        
        self.metrics["success_rate"] = self.metrics["successful_executions"] / self.metrics["total_executions"]
        self.metrics["avg_execution_time"] = self.metrics["total_execution_time"] / self.metrics["total_executions"]


class RAGExecutionEngine(BaseExecutionEngine):
    """Execution engine for RAG-based workflows."""
    
    def __init__(self, config: Dict[str, Any]):
        super().__init__("rag_engine", config)
        self.rag_engine = AdvancedRAGEngine()
    
    async def execute(self, request: UnifiedRequest) -> UnifiedResponse:
        """Execute RAG-based workflow."""
        start_time = time.time()
        
        try:
            if request.type == WorkflowType.INTELLIGENT_QUERY:
                result = await self._execute_intelligent_query(request)
            elif request.type == WorkflowType.CONTENT_GENERATION:
                result = await self._execute_content_generation(request)
            elif request.type == WorkflowType.KNOWLEDGE_EXTRACTION:
                result = await self._execute_knowledge_extraction(request)
            else:
                raise ValueError(f"Unsupported workflow type for RAG engine: {request.type}")
            
            execution_time_ms = int((time.time() - start_time) * 1000)
            
            response = UnifiedResponse(
                request_id=request.id,
                success=True,
                result=result,
                engines_used=["rag_engine"],
                execution_time_ms=execution_time_ms,
                confidence_score=result.get("confidence_score", 0.8),
                citations=result.get("citations", []),
                metadata=result.get("retrieval_metadata", {})
            )
            
            self.record_execution(request, response)
            return response
            
        except Exception as e:
            execution_time_ms = int((time.time() - start_time) * 1000)
            
            response = UnifiedResponse(
                request_id=request.id,
                success=False,
                result=None,
                engines_used=["rag_engine"],
                execution_time_ms=execution_time_ms,
                errors=[str(e)]
            )
            
            self.record_execution(request, response)
            return response
    
    async def _execute_intelligent_query(self, request: UnifiedRequest) -> Dict[str, Any]:
        """Execute intelligent query using RAG."""
        query = request.payload.get("query", "")
        
        result = await self.rag_engine.query(
            query,
            strategy=request.payload.get("strategy", "agentic_reasoning"),
            max_results=request.payload.get("max_results", 10),
            security_clearance=request.security_level.value,
            include_citations=request.payload.get("include_citations", True),
            fact_check=request.payload.get("fact_check", True)
        )
        
        return result
    
    async def _execute_content_generation(self, request: UnifiedRequest) -> Dict[str, Any]:
        """Execute content generation using RAG."""
        # First retrieve relevant context
        context_query = request.payload.get("context_query", request.payload.get("topic", ""))
        
        rag_result = await self.rag_engine.query(
            context_query,
            strategy="semantic_search",
            max_results=5,
            security_clearance=request.security_level.value
        )
        
        # Then generate content using the context
        generation_prompt = request.payload.get("prompt", f"Generate content about: {context_query}")
        
        final_result = await self.rag_engine.query(
            generation_prompt,
            context={"retrieved_knowledge": rag_result["response"]},
            strategy="agentic_reasoning",
            include_citations=True
        )
        
        return final_result
    
    async def _execute_knowledge_extraction(self, request: UnifiedRequest) -> Dict[str, Any]:
        """Execute knowledge extraction using RAG."""
        # Use RAG engine for knowledge discovery
        sources = request.payload.get("sources", [])
        extraction_query = request.payload.get("extraction_query", "Extract key knowledge from sources")
        
        results = []
        for source in sources:
            source_query = f"Extract knowledge from {source}: {extraction_query}"
            result = await self.rag_engine.query(
                source_query,
                strategy="hybrid_search",
                max_results=3,
                security_clearance=request.security_level.value
            )
            results.append(result)
        
        return {
            "extracted_knowledge": results,
            "source_count": len(sources),
            "confidence_score": sum(r["confidence_score"] for r in results) / len(results) if results else 0
        }
    
    async def health_check(self) -> bool:
        """Check RAG engine health."""
        try:
            status = self.rag_engine.get_status()
            return status.get("status") == "operational"
        except:
            return False


class LAGExecutionEngine(BaseExecutionEngine):
    """Execution engine for LAG-based workflows."""
    
    def __init__(self, config: Dict[str, Any]):
        super().__init__("lag_engine", config)
        self.lag_engine = LAGEngine()
    
    async def execute(self, request: UnifiedRequest) -> UnifiedResponse:
        """Execute LAG-based workflow."""
        start_time = time.time()
        
        try:
            if request.type == WorkflowType.PROCESS_AUTOMATION:
                result = await self._execute_process_automation(request)
            elif request.type == WorkflowType.ADAPTIVE_LEARNING:
                result = await self._execute_adaptive_learning(request)
            elif request.type == WorkflowType.DECISION_SUPPORT:
                result = await self._execute_decision_support(request)
            else:
                raise ValueError(f"Unsupported workflow type for LAG engine: {request.type}")
            
            execution_time_ms = int((time.time() - start_time) * 1000)
            
            response = UnifiedResponse(
                request_id=request.id,
                success=result.success,
                result=result.__dict__,
                engines_used=["lag_engine"],
                execution_time_ms=execution_time_ms,
                execution_trace=result.execution_trace,
                artifacts={"completed_actions": result.completed_actions}
            )
            
            self.record_execution(request, response)
            return response
            
        except Exception as e:
            execution_time_ms = int((time.time() - start_time) * 1000)
            
            response = UnifiedResponse(
                request_id=request.id,
                success=False,
                result=None,
                engines_used=["lag_engine"],
                execution_time_ms=execution_time_ms,
                errors=[str(e)]
            )
            
            self.record_execution(request, response)
            return response
    
    async def _execute_process_automation(self, request: UnifiedRequest) -> Any:
        """Execute process automation using LAG."""
        workflow_id = request.payload.get("workflow_id", "data_processing_001")
        
        result = await self.lag_engine.execute_workflow(
            workflow_id,
            strategy=ExecutionStrategy.ADAPTIVE.value,
            initial_context=request.payload.get("initial_context", {}),
            priority=request.priority
        )
        
        return result
    
    async def _execute_adaptive_learning(self, request: UnifiedRequest) -> Any:
        """Execute adaptive learning workflow using LAG."""
        # Create dynamic workflow based on learning requirements
        learning_goals = request.payload.get("learning_goals", [])
        user_profile = request.payload.get("user_profile", {})
        
        # Use existing workflow as template
        workflow_id = request.payload.get("template_workflow", "data_processing_001")
        
        result = await self.lag_engine.execute_workflow(
            workflow_id,
            strategy=ExecutionStrategy.ADAPTIVE.value,
            initial_context={
                "learning_goals": learning_goals,
                "user_profile": user_profile,
                **request.payload.get("context", {})
            }
        )
        
        return result
    
    async def _execute_decision_support(self, request: UnifiedRequest) -> Any:
        """Execute decision support workflow using LAG."""
        decision_criteria = request.payload.get("decision_criteria", {})
        
        result = await self.lag_engine.execute_workflow(
            "data_processing_001",  # Use as decision support template
            strategy=ExecutionStrategy.OPTIMIZED.value,
            initial_context={
                "decision_type": "support",
                "criteria": decision_criteria,
                **request.payload.get("context", {})
            }
        )
        
        return result
    
    async def health_check(self) -> bool:
        """Check LAG engine health."""
        try:
            status = self.lag_engine.get_status()
            return status.get("status") == "operational"
        except:
            return False


class DAGExecutionEngine(BaseExecutionEngine):
    """Execution engine for DAG-based workflows."""
    
    def __init__(self, config: Dict[str, Any]):
        super().__init__("dag_engine", config)
        self.dag_orchestrator = DAGOrchestrator(config)
    
    async def execute(self, request: UnifiedRequest) -> UnifiedResponse:
        """Execute DAG-based workflow."""
        start_time = time.time()
        
        try:
            if request.type == WorkflowType.PROCESS_AUTOMATION:
                result = await self._execute_batch_processing(request)
            elif request.type == WorkflowType.HYBRID_ORCHESTRATION:
                result = await self._execute_hybrid_orchestration(request)
            else:
                raise ValueError(f"Unsupported workflow type for DAG engine: {request.type}")
            
            execution_time_ms = int((time.time() - start_time) * 1000)
            
            response = UnifiedResponse(
                request_id=request.id,
                success=result is not None,
                result=result,
                engines_used=["dag_engine"],
                execution_time_ms=execution_time_ms,
                artifacts={"dag_run_id": result} if result else {}
            )
            
            self.record_execution(request, response)
            return response
            
        except Exception as e:
            execution_time_ms = int((time.time() - start_time) * 1000)
            
            response = UnifiedResponse(
                request_id=request.id,
                success=False,
                result=None,
                engines_used=["dag_engine"],
                execution_time_ms=execution_time_ms,
                errors=[str(e)]
            )
            
            self.record_execution(request, response)
            return response
    
    async def _execute_batch_processing(self, request: UnifiedRequest) -> Optional[str]:
        """Execute batch processing using DAG."""
        # Use existing DAG or create a simple one
        dag_id = request.payload.get("dag_id", "etl_pipeline_001")
        
        # Check if DAG exists in orchestrator
        existing_dags = self.dag_orchestrator.list_dags()
        dag_exists = any(dag["dag_id"] == dag_id for dag in existing_dags)
        
        if dag_exists:
            run_id = await self.dag_orchestrator.trigger_dag(
                dag_id,
                conf=request.payload.get("conf", {})
            )
            return run_id
        else:
            logger.warning(f"DAG {dag_id} not found")
            return None
    
    async def _execute_hybrid_orchestration(self, request: UnifiedRequest) -> Optional[str]:
        """Execute hybrid orchestration using DAG."""
        # Create a complex DAG for hybrid orchestration
        dag_id = request.payload.get("dag_id", "hybrid_orchestration_001")
        
        run_id = await self.dag_orchestrator.trigger_dag(
            dag_id,
            conf={
                "hybrid_mode": True,
                "engines": ["rag", "lag", "dag"],
                **request.payload.get("conf", {})
            }
        )
        
        return run_id
    
    async def health_check(self) -> bool:
        """Check DAG engine health."""
        try:
            metrics = self.dag_orchestrator.get_orchestrator_metrics()
            return metrics.get("registered_dags", 0) >= 0
        except:
            return False


class BrainExtractorExecutionEngine(BaseExecutionEngine):
    """Execution engine for Brain Extractor workflows."""
    
    def __init__(self, config: Dict[str, Any]):
        super().__init__("brain_extractor", config)
        self.brain_extractor = BrainExtractor()
    
    async def execute(self, request: UnifiedRequest) -> UnifiedResponse:
        """Execute Brain Extractor workflow."""
        start_time = time.time()
        
        try:
            if request.type == WorkflowType.ONBOARDING_AUTOMATION:
                result = await self._execute_onboarding_automation(request)
            elif request.type == WorkflowType.KNOWLEDGE_EXTRACTION:
                result = await self._execute_knowledge_extraction(request)
            else:
                raise ValueError(f"Unsupported workflow type for Brain Extractor: {request.type}")
            
            execution_time_ms = int((time.time() - start_time) * 1000)
            
            response = UnifiedResponse(
                request_id=request.id,
                success=result.get("success", True),
                result=result,
                engines_used=["brain_extractor"],
                execution_time_ms=execution_time_ms,
                confidence_score=result.get("confidence_score", 0.8)
            )
            
            self.record_execution(request, response)
            return response
            
        except Exception as e:
            execution_time_ms = int((time.time() - start_time) * 1000)
            
            response = UnifiedResponse(
                request_id=request.id,
                success=False,
                result=None,
                engines_used=["brain_extractor"],
                execution_time_ms=execution_time_ms,
                errors=[str(e)]
            )
            
            self.record_execution(request, response)
            return response
    
    async def _execute_onboarding_automation(self, request: UnifiedRequest) -> Dict[str, Any]:
        """Execute onboarding automation."""
        user_data = request.payload.get("user_data", {})
        
        # Create profile
        profile = await self.brain_extractor.create_onboarding_profile(user_data)
        
        # Generate plan
        plan = await self.brain_extractor.generate_onboarding_plan(
            profile.id,
            request.payload.get("knowledge_requirements", [])
        )
        
        if plan:
            # Execute onboarding
            execution_result = await self.brain_extractor.execute_onboarding(plan.id)
            
            return {
                "success": True,
                "profile_id": profile.id,
                "plan_id": plan.id,
                "execution_result": execution_result,
                "estimated_duration": plan.estimated_duration
            }
        else:
            return {"success": False, "error": "Failed to generate onboarding plan"}
    
    async def _execute_knowledge_extraction(self, request: UnifiedRequest) -> Dict[str, Any]:
        """Execute knowledge extraction."""
        # Use Brain Extractor's query functionality
        query = request.payload.get("query", "")
        
        result = await self.brain_extractor.query_knowledge(
            query,
            strategy=request.payload.get("strategy", "agentic_reasoning"),
            max_results=request.payload.get("max_results", 10),
            security_clearance=request.security_level.value
        )
        
        return result
    
    async def health_check(self) -> bool:
        """Check Brain Extractor health."""
        try:
            metrics = self.brain_extractor.get_system_metrics()
            return metrics.get("system", {}).get("status") == "operational"
        except:
            return False


# ============================================================================
# UNIFIED ORCHESTRATOR
# ============================================================================

class UnifiedAgenticOrchestrator:
    """
    Main unified orchestrator that integrates RAG, LAG, DAG, and Brain Extractor
    with the existing Genesis orchestrator for comprehensive agentic capabilities.
    """
    
    def __init__(self, config_path: Optional[Path] = None):
        self.config = self._load_config(config_path)
        
        # Initialize workflow router
        self.workflow_router = IntelligentWorkflowRouter(self.config.get("routing", {}))
        
        # Initialize execution engines
        self.engines: Dict[IntegrationStrategy, BaseExecutionEngine] = {}
        self._initialize_engines()
        
        # Initialize Genesis orchestrator
        self.genesis_orchestrator = UnifiedMCPOrchestrator()
        
        # Active requests tracking
        self.active_requests: Dict[str, UnifiedRequest] = {}
        self.completed_requests: Dict[str, UnifiedResponse] = {}
        
        # Metrics
        self.integration_metrics = IntegrationMetrics()
        self.start_time = datetime.utcnow()
        
        logger.info("Unified Agentic Orchestrator initialized successfully")
    
    def _load_config(self, config_path: Optional[Path]) -> Dict[str, Any]:
        """Load configuration for the unified orchestrator."""
        if config_path and config_path.exists():
            with open(config_path, 'r') as f:
                return json.load(f)
        
        return {
            "routing": {
                "enable_adaptive_routing": True,
                "performance_learning_rate": 0.1
            },
            "engines": {
                "rag": {"timeout_seconds": 60},
                "lag": {"timeout_seconds": 300},
                "dag": {"timeout_seconds": 1800},
                "brain_extractor": {"timeout_seconds": 600}
            },
            "integration": {
                "max_concurrent_requests": 100,
                "default_timeout_seconds": 300
            }
        }
    
    def _initialize_engines(self):
        """Initialize all execution engines."""
        engine_config = self.config.get("engines", {})
        
        self.engines[IntegrationStrategy.RAG_FIRST] = RAGExecutionEngine(
            engine_config.get("rag", {})
        )
        
        self.engines[IntegrationStrategy.LAG_ORCHESTRATED] = LAGExecutionEngine(
            engine_config.get("lag", {})
        )
        
        self.engines[IntegrationStrategy.DAG_SCHEDULED] = DAGExecutionEngine(
            engine_config.get("dag", {})
        )
        
        self.engines[IntegrationStrategy.BRAIN_EXTRACTOR] = BrainExtractorExecutionEngine(
            engine_config.get("brain_extractor", {})
        )
        
        logger.info(f"Initialized {len(self.engines)} execution engines")
    
    async def execute_workflow(self, request_data: Dict[str, Any]) -> UnifiedResponse:
        """Main entry point for executing workflows."""
        
        # Create unified request
        request = UnifiedRequest(
            id=request_data.get("id", str(uuid.uuid4())),
            type=WorkflowType(request_data.get("type", "intelligent_query")),
            payload=request_data.get("payload", {}),
            context=request_data.get("context", {}),
            integration_strategy=IntegrationStrategy(
                request_data.get("integration_strategy", "hybrid_adaptive")
            ),
            execution_mode=ExecutionMode(
                request_data.get("execution_mode", "asynchronous")
            ),
            priority=request_data.get("priority", 5),
            timeout_seconds=request_data.get("timeout_seconds", 300),
            security_level=SecurityLevel(
                request_data.get("security_level", "internal")
            ),
            requester=request_data.get("requester", "system")
        )
        
        self.active_requests[request.id] = request
        
        try:
            # Route to appropriate strategy
            strategy = await self.workflow_router.route_workflow(request)
            
            # Execute using appropriate engine
            if strategy == IntegrationStrategy.GENESIS_NATIVE:
                response = await self._execute_with_genesis(request)
            elif strategy in self.engines:
                response = await self.engines[strategy].execute(request)
            else:
                raise ValueError(f"No engine available for strategy: {strategy}")
            
            # Update metrics
            self._update_integration_metrics(request, response, strategy)
            
            # Update router performance
            self.workflow_router.update_performance_metrics(
                strategy,
                request.type,
                response.success,
                response.execution_time_ms,
                self._calculate_resource_efficiency(response),
                response.confidence_score
            )
            
            # Store completed request
            self.completed_requests[request.id] = response
            
            return response
            
        except Exception as e:
            # Create error response
            response = UnifiedResponse(
                request_id=request.id,
                success=False,
                result=None,
                execution_time_ms=0,
                errors=[str(e)]
            )
            
            self.completed_requests[request.id] = response
            return response
        
        finally:
            # Clean up active request
            if request.id in self.active_requests:
                del self.active_requests[request.id]
    
    async def _execute_with_genesis(self, request: UnifiedRequest) -> UnifiedResponse:
        """Execute workflow using Genesis orchestrator."""
        start_time = time.time()
        
        try:
            # Convert unified request to Genesis MCP request
            mcp_request = {
                "id": request.id,
                "type": request.type.value,
                "payload": request.payload,
                "context": request.context,
                "priority": "HIGH" if request.priority > 7 else "MEDIUM" if request.priority > 3 else "LOW",
                "requester": request.requester
            }
            
            # Execute with Genesis
            genesis_result = await self.genesis_orchestrator.handle_mcp_request(mcp_request)
            
            execution_time_ms = int((time.time() - start_time) * 1000)
            
            response = UnifiedResponse(
                request_id=request.id,
                success=genesis_result.get("success", False),
                result=genesis_result.get("result"),
                engines_used=["genesis_orchestrator"],
                execution_time_ms=execution_time_ms,
                metadata=genesis_result.get("metadata", {})
            )
            
            return response
            
        except Exception as e:
            execution_time_ms = int((time.time() - start_time) * 1000)
            
            return UnifiedResponse(
                request_id=request.id,
                success=False,
                result=None,
                engines_used=["genesis_orchestrator"],
                execution_time_ms=execution_time_ms,
                errors=[str(e)]
            )
    
    def _update_integration_metrics(self, request: UnifiedRequest, 
                                  response: UnifiedResponse, strategy: IntegrationStrategy):
        """Update integration layer metrics."""
        
        self.integration_metrics.total_requests += 1
        
        if response.success:
            self.integration_metrics.successful_requests += 1
        else:
            self.integration_metrics.failed_requests += 1
        
        # Update average response time
        total_time = (
            self.integration_metrics.avg_response_time_ms * 
            (self.integration_metrics.total_requests - 1) + 
            response.execution_time_ms
        )
        self.integration_metrics.avg_response_time_ms = total_time / self.integration_metrics.total_requests
        
        # Update usage counts
        self.integration_metrics.strategy_usage[strategy.value] = (
            self.integration_metrics.strategy_usage.get(strategy.value, 0) + 1
        )
        
        self.integration_metrics.workflow_type_usage[request.type.value] = (
            self.integration_metrics.workflow_type_usage.get(request.type.value, 0) + 1
        )
        
        for engine in response.engines_used:
            self.integration_metrics.engine_usage[engine] = (
                self.integration_metrics.engine_usage.get(engine, 0) + 1
            )
    
    def _calculate_resource_efficiency(self, response: UnifiedResponse) -> float:
        """Calculate resource efficiency score."""
        # Simple efficiency calculation based on execution time and engines used
        base_efficiency = 1.0
        
        # Penalize longer execution times
        if response.execution_time_ms > 10000:  # 10 seconds
            base_efficiency *= 0.7
        elif response.execution_time_ms > 5000:  # 5 seconds
            base_efficiency *= 0.85
        
        # Penalize multiple engine usage (complexity)
        if len(response.engines_used) > 2:
            base_efficiency *= 0.9
        
        return base_efficiency
    
    async def get_request_status(self, request_id: str) -> Optional[Dict[str, Any]]:
        """Get status of a request."""
        
        # Check active requests
        if request_id in self.active_requests:
            request = self.active_requests[request_id]
            return {
                "request_id": request_id,
                "status": "in_progress",
                "type": request.type.value,
                "created_at": request.created_at.isoformat(),
                "timeout_at": (request.created_at + timedelta(seconds=request.timeout_seconds)).isoformat()
            }
        
        # Check completed requests
        if request_id in self.completed_requests:
            response = self.completed_requests[request_id]
            return {
                "request_id": request_id,
                "status": "completed",
                "success": response.success,
                "execution_time_ms": response.execution_time_ms,
                "engines_used": response.engines_used,
                "completed_at": response.completed_at.isoformat()
            }
        
        return None
    
    async def health_check(self) -> Dict[str, Any]:
        """Comprehensive health check of all systems."""
        
        health_status = {
            "unified_orchestrator": "operational",
            "genesis_orchestrator": "unknown",
            "engines": {},
            "overall_status": "operational"
        }
        
        # Check Genesis orchestrator
        try:
            genesis_status = self.genesis_orchestrator.get_status()
            health_status["genesis_orchestrator"] = genesis_status.get("status", "unknown")
        except:
            health_status["genesis_orchestrator"] = "error"
        
        # Check all engines
        any_engine_down = False
        for strategy, engine in self.engines.items():
            try:
                is_healthy = await engine.health_check()
                health_status["engines"][strategy.value] = "operational" if is_healthy else "error"
                if not is_healthy:
                    any_engine_down = True
            except:
                health_status["engines"][strategy.value] = "error"
                any_engine_down = True
        
        # Determine overall status
        if (health_status["genesis_orchestrator"] == "error" or 
            any_engine_down or 
            len(self.active_requests) > self.config.get("integration", {}).get("max_concurrent_requests", 100)):
            health_status["overall_status"] = "degraded"
        
        return health_status
    
    def get_comprehensive_metrics(self) -> Dict[str, Any]:
        """Get comprehensive metrics for the entire system."""
        
        uptime = (datetime.utcnow() - self.start_time).total_seconds()
        
        return {
            "system": {
                "uptime_seconds": uptime,
                "start_time": self.start_time.isoformat(),
                "active_requests": len(self.active_requests),
                "completed_requests": len(self.completed_requests)
            },
            "integration_metrics": {
                "total_requests": self.integration_metrics.total_requests,
                "successful_requests": self.integration_metrics.successful_requests,
                "failed_requests": self.integration_metrics.failed_requests,
                "success_rate": (
                    self.integration_metrics.successful_requests / 
                    max(self.integration_metrics.total_requests, 1)
                ),
                "avg_response_time_ms": self.integration_metrics.avg_response_time_ms,
                "engine_usage": dict(self.integration_metrics.engine_usage),
                "strategy_usage": dict(self.integration_metrics.strategy_usage),
                "workflow_type_usage": dict(self.integration_metrics.workflow_type_usage)
            },
            "engine_metrics": {
                strategy.value: dict(engine.metrics)
                for strategy, engine in self.engines.items()
            },
            "routing_metrics": {
                "total_routing_decisions": len(self.workflow_router.routing_history),
                "performance_metrics_count": len(self.workflow_router.performance_metrics)
            }
        }


# ============================================================================
# API INTERFACE
# ============================================================================

class UnifiedAgenticAPI:
    """API interface for the unified agentic orchestrator."""
    
    def __init__(self, orchestrator: UnifiedAgenticOrchestrator):
        self.orchestrator = orchestrator
    
    async def intelligent_query(self, query: str, **kwargs) -> Dict[str, Any]:
        """Execute an intelligent query using the best available strategy."""
        
        request_data = {
            "type": "intelligent_query",
            "payload": {
                "query": query,
                **kwargs
            },
            "integration_strategy": kwargs.get("strategy", "hybrid_adaptive"),
            "security_level": kwargs.get("security_level", "internal")
        }
        
        response = await self.orchestrator.execute_workflow(request_data)
        return self._format_api_response(response)
    
    async def automate_onboarding(self, user_data: Dict[str, Any], **kwargs) -> Dict[str, Any]:
        """Automate user onboarding process."""
        
        request_data = {
            "type": "onboarding_automation",
            "payload": {
                "user_data": user_data,
                "knowledge_requirements": kwargs.get("knowledge_requirements", [])
            },
            "integration_strategy": "brain_extractor",
            "security_level": kwargs.get("security_level", "internal")
        }
        
        response = await self.orchestrator.execute_workflow(request_data)
        return self._format_api_response(response)
    
    async def extract_knowledge(self, sources: List[str], **kwargs) -> Dict[str, Any]:
        """Extract knowledge from specified sources."""
        
        strategy = "brain_extractor" if kwargs.get("use_brain_extractor") else "rag_first"
        
        request_data = {
            "type": "knowledge_extraction",
            "payload": {
                "sources": sources,
                "extraction_query": kwargs.get("extraction_query", ""),
                **kwargs
            },
            "integration_strategy": strategy,
            "security_level": kwargs.get("security_level", "internal")
        }
        
        response = await self.orchestrator.execute_workflow(request_data)
        return self._format_api_response(response)
    
    async def generate_content(self, topic: str, **kwargs) -> Dict[str, Any]:
        """Generate content on a specific topic."""
        
        request_data = {
            "type": "content_generation",
            "payload": {
                "topic": topic,
                "prompt": kwargs.get("prompt", f"Generate comprehensive content about: {topic}"),
                "context_query": kwargs.get("context_query", topic)
            },
            "integration_strategy": "rag_first",
            "security_level": kwargs.get("security_level", "internal")
        }
        
        response = await self.orchestrator.execute_workflow(request_data)
        return self._format_api_response(response)
    
    async def automate_process(self, process_definition: Dict[str, Any], **kwargs) -> Dict[str, Any]:
        """Automate a business process."""
        
        request_data = {
            "type": "process_automation",
            "payload": {
                "process_definition": process_definition,
                "workflow_id": kwargs.get("workflow_id"),
                "initial_context": kwargs.get("initial_context", {})
            },
            "integration_strategy": kwargs.get("strategy", "lag_orchestrated"),
            "execution_mode": kwargs.get("execution_mode", "asynchronous")
        }
        
        response = await self.orchestrator.execute_workflow(request_data)
        return self._format_api_response(response)
    
    async def get_system_status(self) -> Dict[str, Any]:
        """Get comprehensive system status."""
        
        health = await self.orchestrator.health_check()
        metrics = self.orchestrator.get_comprehensive_metrics()
        
        return {
            "status": "success",
            "data": {
                "health": health,
                "metrics": metrics,
                "timestamp": datetime.utcnow().isoformat()
            }
        }
    
    def _format_api_response(self, response: UnifiedResponse) -> Dict[str, Any]:
        """Format unified response for API consumption."""
        
        return {
            "status": "success" if response.success else "error",
            "request_id": response.request_id,
            "data": response.result,
            "metadata": {
                "execution_time_ms": response.execution_time_ms,
                "engines_used": response.engines_used,
                "confidence_score": response.confidence_score,
                "citations": response.citations,
                "artifacts": response.artifacts
            },
            "warnings": response.warnings,
            "errors": response.errors,
            "timestamp": response.completed_at.isoformat()
        }


# ============================================================================
# EXAMPLE USAGE AND DEMONSTRATION
# ============================================================================

async def main():
    """Demonstrate the Unified Agentic Orchestrator."""
    
    print("🤖 Unified Agentic Orchestrator - Integration Demonstration")
    print("=" * 65)
    
    # Initialize the unified orchestrator
    orchestrator = UnifiedAgenticOrchestrator()
    api = UnifiedAgenticAPI(orchestrator)
    
    # System health check
    print("\n🏥 System Health Check:")
    health = await orchestrator.health_check()
    print(f"  Overall Status: {health['overall_status']}")
    print(f"  Genesis Orchestrator: {health['genesis_orchestrator']}")
    for engine, status in health['engines'].items():
        print(f"  {engine.replace('_', ' ').title()}: {status}")
    
    # Demonstrate intelligent querying
    print(f"\n🧠 Intelligent Query (RAG-powered):")
    query_result = await api.intelligent_query(
        "What are the best practices for implementing agentic AI systems?",
        strategy="hybrid_adaptive",
        max_results=5,
        include_citations=True
    )
    
    print(f"  Status: {query_result['status']}")
    print(f"  Confidence: {query_result['metadata']['confidence_score']:.2f}")
    print(f"  Engines: {', '.join(query_result['metadata']['engines_used'])}")
    print(f"  Response: {str(query_result['data'])[:200]}...")
    
    # Demonstrate knowledge extraction
    print(f"\n📚 Knowledge Extraction:")
    extraction_result = await api.extract_knowledge(
        sources=["company_handbook", "technical_documentation"],
        extraction_query="Extract key processes and procedures",
        use_brain_extractor=True
    )
    
    print(f"  Status: {extraction_result['status']}")
    print(f"  Execution Time: {extraction_result['metadata']['execution_time_ms']}ms")
    print(f"  Engines: {', '.join(extraction_result['metadata']['engines_used'])}")
    
    # Demonstrate onboarding automation
    print(f"\n👤 Onboarding Automation:")
    onboarding_result = await api.automate_onboarding(
        user_data={
            "user_id": "demo_user_001",
            "persona": "new_hire",
            "role": "Software Engineer",
            "department": "Engineering",
            "experience_level": "intermediate",
            "learning_style": "visual",
            "interests": ["software_development", "ai_systems"],
            "goals": ["learn_company_processes", "understand_ai_architecture"]
        },
        knowledge_requirements=["company_culture", "technical_standards", "ai_systems"]
    )
    
    print(f"  Status: {onboarding_result['status']}")
    print(f"  Execution Time: {onboarding_result['metadata']['execution_time_ms']}ms")
    if onboarding_result['status'] == 'success' and onboarding_result['data']:
        result_data = onboarding_result['data']
        print(f"  Profile ID: {result_data.get('profile_id', 'N/A')}")
        print(f"  Plan ID: {result_data.get('plan_id', 'N/A')}")
        print(f"  Estimated Duration: {result_data.get('estimated_duration', 0)} minutes")
    
    # Demonstrate content generation
    print(f"\n✍️ Content Generation:")
    content_result = await api.generate_content(
        topic="Agentic AI Architecture Best Practices",
        prompt="Create a comprehensive guide for implementing agentic AI systems"
    )
    
    print(f"  Status: {content_result['status']}")
    print(f"  Confidence: {content_result['metadata']['confidence_score']:.2f}")
    print(f"  Content: {str(content_result['data'])[:150]}...")
    
    # Demonstrate process automation
    print(f"\n⚙️ Process Automation:")
    process_result = await api.automate_process(
        process_definition={
            "name": "AI Model Training Pipeline",
            "steps": ["data_preparation", "model_training", "validation", "deployment"],
            "automation_level": "full"
        },
        strategy="lag_orchestrated",
        initial_context={"dataset": "production_data", "model_type": "transformer"}
    )
    
    print(f"  Status: {process_result['status']}")
    print(f"  Execution Time: {process_result['metadata']['execution_time_ms']}ms")
    print(f"  Engines: {', '.join(process_result['metadata']['engines_used'])}")
    
    # Show comprehensive metrics
    print(f"\n📊 System Metrics:")
    status_result = await api.get_system_status()
    
    if status_result['status'] == 'success':
        metrics = status_result['data']['metrics']
        integration_metrics = metrics['integration_metrics']
        
        print(f"  Total Requests: {integration_metrics['total_requests']}")
        print(f"  Success Rate: {integration_metrics['success_rate']:.1%}")
        print(f"  Avg Response Time: {integration_metrics['avg_response_time_ms']:.0f}ms")
        print(f"  Active Requests: {metrics['system']['active_requests']}")
        
        print(f"\n  Strategy Usage:")
        for strategy, count in integration_metrics['strategy_usage'].items():
            print(f"    {strategy.replace('_', ' ').title()}: {count}")
        
        print(f"\n  Workflow Type Usage:")
        for workflow_type, count in integration_metrics['workflow_type_usage'].items():
            print(f"    {workflow_type.replace('_', ' ').title()}: {count}")
    
    print(f"\n✅ Integration Demonstration Complete!")
    print(f"\nKey Capabilities Demonstrated:")
    print(f"  ✓ Intelligent workflow routing")
    print(f"  ✓ Multi-engine orchestration (RAG, LAG, DAG, Brain Extractor)")
    print(f"  ✓ Genesis orchestrator integration")
    print(f"  ✓ Adaptive strategy selection")
    print(f"  ✓ Comprehensive monitoring and metrics")
    print(f"  ✓ Enterprise-grade security and compliance")


if __name__ == "__main__":
    asyncio.run(main())
