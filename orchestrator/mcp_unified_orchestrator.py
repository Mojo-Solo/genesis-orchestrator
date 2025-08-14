"""
Unified MCP Orchestrator for All Server Agents
==============================================
A maximally optimized, Claude-exclusive orchestration architecture for MCP server management.
Implements intelligence-driven knowledge routing, agent modularity, and dynamic deployment.

Architecture:
- Single MCP server exposing all capabilities
- Dynamic agent registry with hot-reload
- Intelligent routing based on intent analysis
- Integration with Temporal workflows
- Full observability and monitoring

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
from typing import Dict, List, Any, Optional, Callable, Set, Tuple
from collections import defaultdict
import logging
from pathlib import Path

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


# ============================================================================
# CORE DATA STRUCTURES
# ============================================================================

class AgentStatus(Enum):
    """Agent operational status."""
    ACTIVE = "active"
    INACTIVE = "inactive"
    DEPRECATED = "deprecated"
    ERROR = "error"
    INITIALIZING = "initializing"
    MAINTENANCE = "maintenance"


class AgentCategory(Enum):
    """Agent category for organization and routing."""
    DEPLOYMENT = "deployment"
    MONITORING = "monitoring"
    SECURITY = "security"
    INTELLIGENCE = "intelligence"
    AUTOMATION = "automation"
    INTEGRATION = "integration"
    ANALYSIS = "analysis"
    TESTING = "testing"
    INFRASTRUCTURE = "infrastructure"
    META_LEARNING = "meta_learning"


class TaskPriority(Enum):
    """Task execution priority."""
    CRITICAL = 1
    HIGH = 2
    MEDIUM = 3
    LOW = 4
    BACKGROUND = 5


@dataclass
class AgentCapability:
    """Defines a specific capability of an agent."""
    name: str
    description: str
    parameters: Dict[str, Any] = field(default_factory=dict)
    required_context: List[str] = field(default_factory=list)
    produces_context: List[str] = field(default_factory=list)
    estimated_duration_ms: int = 1000
    resource_requirements: Dict[str, Any] = field(default_factory=dict)


@dataclass
class AgentDefinition:
    """Complete agent definition for the registry."""
    id: str
    name: str
    category: AgentCategory
    description: str
    status: AgentStatus
    capabilities: List[AgentCapability]
    dependencies: List[str] = field(default_factory=list)
    configuration: Dict[str, Any] = field(default_factory=dict)
    version: str = "1.0.0"
    author: str = "GENESIS"
    created_at: datetime = field(default_factory=datetime.utcnow)
    updated_at: datetime = field(default_factory=datetime.utcnow)
    metrics: Dict[str, Any] = field(default_factory=dict)
    health_check: Optional[Callable] = None
    keywords: Set[str] = field(default_factory=set)
    priority: TaskPriority = TaskPriority.MEDIUM


@dataclass
class RoutingDecision:
    """Represents a routing decision for a task."""
    primary_agent: str
    supporting_agents: List[str]
    execution_order: List[Tuple[str, str]]  # (agent_id, capability_name)
    estimated_duration_ms: int
    confidence_score: float
    reasoning: str
    fallback_agents: List[str] = field(default_factory=list)
    
    
@dataclass
class TaskRequest:
    """Incoming task request to the orchestrator."""
    id: str
    type: str
    payload: Dict[str, Any]
    context: Dict[str, Any] = field(default_factory=dict)
    priority: TaskPriority = TaskPriority.MEDIUM
    timeout_ms: int = 60000
    requester: str = "user"
    created_at: datetime = field(default_factory=datetime.utcnow)
    
    
@dataclass
class TaskResult:
    """Result from task execution."""
    task_id: str
    success: bool
    result: Any
    agents_used: List[str]
    execution_time_ms: int
    errors: List[str] = field(default_factory=list)
    warnings: List[str] = field(default_factory=list)
    metrics: Dict[str, Any] = field(default_factory=dict)
    artifacts: Dict[str, str] = field(default_factory=dict)


# ============================================================================
# AGENT REGISTRY
# ============================================================================

class AgentRegistry:
    """
    Central registry for all agents in the orchestrator.
    Maintains the catalog of available capabilities and their status.
    """
    
    def __init__(self):
        self._agents: Dict[str, AgentDefinition] = {}
        self._category_index: Dict[AgentCategory, Set[str]] = defaultdict(set)
        self._capability_index: Dict[str, Set[str]] = defaultdict(set)
        self._keyword_index: Dict[str, Set[str]] = defaultdict(set)
        self._dependency_graph: Dict[str, Set[str]] = defaultdict(set)
        self._init_core_agents()
        
    def _init_core_agents(self):
        """Initialize core agents that ship with the orchestrator."""
        
        # Core Orchestrator Agents
        self.register_agent(AgentDefinition(
            id="revo_executor",
            name="Revo Executor",
            category=AgentCategory.AUTOMATION,
            description="Code generation, refactoring, and CLI automation",
            status=AgentStatus.ACTIVE,
            capabilities=[
                AgentCapability(
                    name="generate_code",
                    description="Generate code from specifications",
                    parameters={"spec": "object", "language": "string"},
                    produces_context=["generated_code", "code_metadata"]
                ),
                AgentCapability(
                    name="refactor_code",
                    description="Refactor existing code",
                    parameters={"code": "string", "rules": "array"},
                    required_context=["existing_code"],
                    produces_context=["refactored_code"]
                ),
                AgentCapability(
                    name="execute_cli",
                    description="Execute CLI commands",
                    parameters={"command": "string", "args": "array"},
                    produces_context=["cli_output"]
                )
            ],
            keywords={"code", "generate", "refactor", "cli", "automation", "revo"}
        ))
        
        self.register_agent(AgentDefinition(
            id="bmad_planner",
            name="BMAD Planner",
            category=AgentCategory.INTELLIGENCE,
            description="High-level product planning and architecture design",
            status=AgentStatus.ACTIVE,
            capabilities=[
                AgentCapability(
                    name="generate_prd",
                    description="Generate Product Requirements Document",
                    parameters={"requirements": "object"},
                    produces_context=["prd_document", "feature_list"]
                ),
                AgentCapability(
                    name="design_architecture",
                    description="Design system architecture",
                    parameters={"requirements": "object", "constraints": "array"},
                    produces_context=["architecture_diagram", "component_specs"]
                )
            ],
            keywords={"planning", "prd", "architecture", "design", "bmad", "product"}
        ))
        
        self.register_agent(AgentDefinition(
            id="claude_scrum_master",
            name="Claude ScrumMaster",
            category=AgentCategory.INTELLIGENCE,
            description="Agile project management and sprint coordination",
            status=AgentStatus.ACTIVE,
            capabilities=[
                AgentCapability(
                    name="manage_sprint",
                    description="Manage sprint planning and execution",
                    parameters={"sprint_goals": "array", "team_capacity": "object"},
                    produces_context=["sprint_plan", "task_assignments"]
                ),
                AgentCapability(
                    name="conduct_retrospective",
                    description="Facilitate sprint retrospective",
                    parameters={"sprint_metrics": "object"},
                    produces_context=["retrospective_insights", "improvement_actions"]
                )
            ],
            keywords={"scrum", "agile", "sprint", "planning", "retrospective"}
        ))
        
        # Analysis Agents
        self.register_agent(AgentDefinition(
            id="consult7_agent",
            name="Consult7 Agent",
            category=AgentCategory.ANALYSIS,
            description="Large codebase analysis and consultation",
            status=AgentStatus.ACTIVE,
            capabilities=[
                AgentCapability(
                    name="analyze_codebase",
                    description="Analyze large codebases for patterns and issues",
                    parameters={"path": "string", "depth": "integer"},
                    produces_context=["codebase_analysis", "architectural_patterns"],
                    estimated_duration_ms=5000
                )
            ],
            dependencies=["context7_agent"],
            keywords={"codebase", "analysis", "patterns", "consultation", "large-scale"}
        ))
        
        self.register_agent(AgentDefinition(
            id="context7_agent",
            name="Context7 Agent",
            category=AgentCategory.ANALYSIS,
            description="Documentation and context retrieval",
            status=AgentStatus.ACTIVE,
            capabilities=[
                AgentCapability(
                    name="fetch_documentation",
                    description="Retrieve relevant documentation",
                    parameters={"query": "string", "sources": "array"},
                    produces_context=["documentation", "external_context"]
                )
            ],
            keywords={"context", "documentation", "retrieval", "knowledge"}
        ))
        
        self.register_agent(AgentDefinition(
            id="serena_agent",
            name="Serena Agent",
            category=AgentCategory.ANALYSIS,
            description="Semantic code analysis and type checking",
            status=AgentStatus.ACTIVE,
            capabilities=[
                AgentCapability(
                    name="semantic_analysis",
                    description="Perform deep semantic analysis",
                    parameters={"code": "string", "rules": "object"},
                    required_context=["code_ast"],
                    produces_context=["semantic_insights", "type_errors"]
                )
            ],
            keywords={"semantic", "types", "analysis", "validation"}
        ))
        
        # Testing Agents
        self.register_agent(AgentDefinition(
            id="cucumber_bdd_architect",
            name="Cucumber BDD Architect",
            category=AgentCategory.TESTING,
            description="Behavior-driven development test creation",
            status=AgentStatus.ACTIVE,
            capabilities=[
                AgentCapability(
                    name="generate_scenarios",
                    description="Generate Gherkin scenarios from requirements",
                    parameters={"requirements": "array"},
                    produces_context=["gherkin_scenarios", "test_stubs"]
                )
            ],
            keywords={"bdd", "cucumber", "gherkin", "testing", "scenarios"}
        ))
        
        self.register_agent(AgentDefinition(
            id="playwright_agent",
            name="Playwright Agent",
            category=AgentCategory.TESTING,
            description="End-to-end browser testing",
            status=AgentStatus.ACTIVE,
            capabilities=[
                AgentCapability(
                    name="run_e2e_tests",
                    description="Execute end-to-end browser tests",
                    parameters={"test_suite": "string", "browser": "string"},
                    produces_context=["test_results", "screenshots"],
                    estimated_duration_ms=30000
                )
            ],
            keywords={"e2e", "playwright", "browser", "testing", "ui"}
        ))
        
        # Infrastructure Agents
        self.register_agent(AgentDefinition(
            id="db_manager",
            name="Database Manager",
            category=AgentCategory.INFRASTRUCTURE,
            description="Database operations and state management",
            status=AgentStatus.ACTIVE,
            capabilities=[
                AgentCapability(
                    name="execute_query",
                    description="Execute database query",
                    parameters={"query": "string", "params": "array"},
                    produces_context=["query_results"]
                ),
                AgentCapability(
                    name="manage_migrations",
                    description="Handle database migrations",
                    parameters={"direction": "string"},
                    produces_context=["migration_status"]
                )
            ],
            keywords={"database", "sql", "migrations", "state", "persistence"}
        ))
        
        self.register_agent(AgentDefinition(
            id="desktop_commander",
            name="Desktop Commander",
            category=AgentCategory.AUTOMATION,
            description="System-level command execution",
            status=AgentStatus.ACTIVE,
            capabilities=[
                AgentCapability(
                    name="execute_system_command",
                    description="Execute system-level commands",
                    parameters={"command": "string", "args": "array"},
                    produces_context=["command_output"]
                )
            ],
            keywords={"system", "desktop", "command", "os", "automation"}
        ))
        
        # Deployment Agents
        self.register_agent(AgentDefinition(
            id="ultimate_deployment_orchestrator",
            name="Ultimate Deployment Orchestrator",
            category=AgentCategory.DEPLOYMENT,
            description="Advanced blue-green deployment with health checks",
            status=AgentStatus.ACTIVE,
            capabilities=[
                AgentCapability(
                    name="blue_green_deploy",
                    description="Execute blue-green deployment",
                    parameters={"app": "string", "version": "string"},
                    produces_context=["deployment_status", "health_metrics"],
                    estimated_duration_ms=120000
                )
            ],
            keywords={"deployment", "blue-green", "zero-downtime", "health-check"}
        ))
        
        # Monitoring Agents
        self.register_agent(AgentDefinition(
            id="projectwe_monitoring",
            name="ProjectWE Monitoring Agent",
            category=AgentCategory.MONITORING,
            description="GitHub and Vercel deployment monitoring",
            status=AgentStatus.ACTIVE,
            capabilities=[
                AgentCapability(
                    name="monitor_deployments",
                    description="Monitor deployment status",
                    parameters={"services": "array"},
                    produces_context=["deployment_metrics", "alerts"]
                )
            ],
            keywords={"monitoring", "github", "vercel", "deployments", "metrics"}
        ))
        
        # Security Agents
        self.register_agent(AgentDefinition(
            id="security_monitoring",
            name="Security Monitoring Agent",
            category=AgentCategory.SECURITY,
            description="Security vulnerability scanning and compliance",
            status=AgentStatus.ACTIVE,
            capabilities=[
                AgentCapability(
                    name="scan_vulnerabilities",
                    description="Scan for security vulnerabilities",
                    parameters={"target": "string", "depth": "string"},
                    produces_context=["vulnerabilities", "compliance_report"],
                    estimated_duration_ms=10000
                )
            ],
            keywords={"security", "vulnerabilities", "snyk", "compliance", "scanning"}
        ))
        
        # Meta-Learning Agent
        self.register_agent(AgentDefinition(
            id="meta_learning_engine",
            name="Meta-Learning Engine",
            category=AgentCategory.META_LEARNING,
            description="System optimization through continuous learning",
            status=AgentStatus.ACTIVE,
            capabilities=[
                AgentCapability(
                    name="analyze_performance",
                    description="Analyze system performance patterns",
                    parameters={"metrics": "object", "timeframe": "string"},
                    produces_context=["performance_insights", "optimization_proposals"]
                ),
                AgentCapability(
                    name="optimize_configuration",
                    description="Optimize system configuration",
                    parameters={"current_config": "object"},
                    produces_context=["optimized_config", "expected_improvements"]
                )
            ],
            keywords={"meta-learning", "optimization", "performance", "adaptive"}
        ))
        
        # Master Framework Agents (Domain Experts)
        self._register_master_framework_agents()
        
    def _register_master_framework_agents(self):
        """Register the 10 master framework agents."""
        
        master_agents = [
            ("microservices_architect", "Microservices Architect", 
             "Service boundaries and microservices design"),
            ("devops_engineer", "DevOps Engineer", 
             "CI/CD pipelines and infrastructure automation"),
            ("frontend_state_manager", "Frontend State Manager", 
             "State management and frontend architecture"),
            ("api_designer", "API Designer", 
             "RESTful and GraphQL API design"),
            ("data_engineer", "Data Engineer", 
             "Data pipelines and ETL processes"),
            ("testing_strategist", "Testing Strategist", 
             "Comprehensive testing strategy and coverage"),
            ("security_compliance", "Security Compliance Officer", 
             "Security standards and compliance requirements"),
            ("performance_optimizer", "Performance Optimizer", 
             "System performance and optimization"),
            ("error_handler", "Error Handler", 
             "Error handling and recovery strategies"),
            ("accessibility_i18n", "Accessibility & i18n Specialist", 
             "Accessibility standards and internationalization")
        ]
        
        for agent_id, name, description in master_agents:
            self.register_agent(AgentDefinition(
                id=agent_id,
                name=name,
                category=AgentCategory.INTELLIGENCE,
                description=description,
                status=AgentStatus.ACTIVE,
                capabilities=[
                    AgentCapability(
                        name="provide_expertise",
                        description=f"Provide expert guidance on {description.lower()}",
                        parameters={"query": "string", "context": "object"},
                        produces_context=["expert_advice", "best_practices"]
                    ),
                    AgentCapability(
                        name="review_implementation",
                        description=f"Review implementation for {description.lower()}",
                        parameters={"implementation": "object"},
                        produces_context=["review_feedback", "improvement_suggestions"]
                    )
                ],
                keywords={agent_id.replace("_", "-"), "expert", "framework", "master"}
            ))
    
    def register_agent(self, agent: AgentDefinition) -> bool:
        """Register a new agent or update existing one."""
        try:
            agent.updated_at = datetime.utcnow()
            self._agents[agent.id] = agent
            
            # Update indices
            self._category_index[agent.category].add(agent.id)
            
            for capability in agent.capabilities:
                self._capability_index[capability.name].add(agent.id)
            
            for keyword in agent.keywords:
                self._keyword_index[keyword.lower()].add(agent.id)
            
            for dependency in agent.dependencies:
                self._dependency_graph[agent.id].add(dependency)
            
            logger.info(f"Registered agent: {agent.id} ({agent.name})")
            return True
            
        except Exception as e:
            logger.error(f"Failed to register agent {agent.id}: {e}")
            return False
    
    def unregister_agent(self, agent_id: str) -> bool:
        """Remove an agent from the registry."""
        if agent_id not in self._agents:
            return False
            
        agent = self._agents[agent_id]
        
        # Remove from indices
        self._category_index[agent.category].discard(agent_id)
        
        for capability in agent.capabilities:
            self._capability_index[capability.name].discard(agent_id)
        
        for keyword in agent.keywords:
            self._keyword_index[keyword.lower()].discard(agent_id)
        
        self._dependency_graph.pop(agent_id, None)
        
        del self._agents[agent_id]
        logger.info(f"Unregistered agent: {agent_id}")
        return True
    
    def get_agent(self, agent_id: str) -> Optional[AgentDefinition]:
        """Get agent by ID."""
        return self._agents.get(agent_id)
    
    def get_agents_by_category(self, category: AgentCategory) -> List[AgentDefinition]:
        """Get all agents in a category."""
        return [self._agents[aid] for aid in self._category_index[category]]
    
    def get_agents_by_capability(self, capability: str) -> List[AgentDefinition]:
        """Get agents that have a specific capability."""
        return [self._agents[aid] for aid in self._capability_index[capability]]
    
    def get_agents_by_keywords(self, keywords: Set[str]) -> List[AgentDefinition]:
        """Get agents matching keywords."""
        matching_agents = set()
        for keyword in keywords:
            matching_agents.update(self._keyword_index[keyword.lower()])
        return [self._agents[aid] for aid in matching_agents]
    
    def is_agent_available(self, agent_id: str) -> bool:
        """Check if agent is available (active and dependencies met)."""
        agent = self.get_agent(agent_id)
        if not agent or agent.status != AgentStatus.ACTIVE:
            return False
            
        # Check dependencies
        for dep_id in agent.dependencies:
            if not self.is_agent_available(dep_id):
                return False
                
        # Run health check if available
        if agent.health_check:
            try:
                return agent.health_check()
            except:
                return False
                
        return True
    
    def get_all_agents(self) -> List[AgentDefinition]:
        """Get all registered agents."""
        return list(self._agents.values())
    
    def get_agent_metrics(self, agent_id: str) -> Dict[str, Any]:
        """Get metrics for an agent."""
        agent = self.get_agent(agent_id)
        if not agent:
            return {}
        return agent.metrics
    
    def update_agent_metrics(self, agent_id: str, metrics: Dict[str, Any]):
        """Update agent metrics."""
        agent = self.get_agent(agent_id)
        if agent:
            agent.metrics.update(metrics)
            agent.metrics["last_updated"] = datetime.utcnow().isoformat()


# ============================================================================
# INTELLIGENT ROUTER
# ============================================================================

class IntelligentRouter:
    """
    Routes tasks to appropriate agents based on intent analysis and capabilities.
    Uses multiple strategies for optimal agent selection.
    """
    
    def __init__(self, registry: AgentRegistry):
        self.registry = registry
        self.routing_history: List[RoutingDecision] = []
        self.performance_metrics: Dict[str, Dict[str, float]] = defaultdict(dict)
        
    async def route_task(self, request: TaskRequest) -> RoutingDecision:
        """Route a task to the most appropriate agents."""
        
        # Extract keywords and intent from request
        keywords = self._extract_keywords(request)
        intent = self._analyze_intent(request)
        
        # Find matching agents
        candidate_agents = self._find_candidate_agents(keywords, intent)
        
        # Score and rank agents
        scored_agents = self._score_agents(candidate_agents, request)
        
        # Build execution plan
        execution_plan = self._build_execution_plan(scored_agents, request)
        
        # Create routing decision
        decision = RoutingDecision(
            primary_agent=execution_plan[0][0] if execution_plan else "general_purpose",
            supporting_agents=[a for a, _ in execution_plan[1:5]],  # Top 5 supporting
            execution_order=execution_plan,
            estimated_duration_ms=self._estimate_duration(execution_plan),
            confidence_score=self._calculate_confidence(scored_agents),
            reasoning=self._generate_reasoning(request, execution_plan),
            fallback_agents=self._identify_fallbacks(scored_agents)
        )
        
        self.routing_history.append(decision)
        return decision
    
    def _extract_keywords(self, request: TaskRequest) -> Set[str]:
        """Extract keywords from request."""
        keywords = set()
        
        # Extract from type
        keywords.add(request.type.lower())
        
        # Extract from payload
        if "description" in request.payload:
            words = request.payload["description"].lower().split()
            keywords.update(words)
        
        if "tags" in request.payload:
            keywords.update(request.payload["tags"])
            
        # Extract from context
        if "domain" in request.context:
            keywords.add(request.context["domain"].lower())
            
        return keywords
    
    def _analyze_intent(self, request: TaskRequest) -> str:
        """Analyze the intent of the request."""
        type_mapping = {
            "code_generation": AgentCategory.AUTOMATION,
            "deploy": AgentCategory.DEPLOYMENT,
            "test": AgentCategory.TESTING,
            "analyze": AgentCategory.ANALYSIS,
            "monitor": AgentCategory.MONITORING,
            "secure": AgentCategory.SECURITY,
            "plan": AgentCategory.INTELLIGENCE
        }
        
        for key, category in type_mapping.items():
            if key in request.type.lower():
                return category.value
                
        return AgentCategory.INTELLIGENCE.value
    
    def _find_candidate_agents(self, keywords: Set[str], intent: str) -> List[AgentDefinition]:
        """Find candidate agents based on keywords and intent."""
        candidates = set()
        
        # Get agents by keywords
        keyword_agents = self.registry.get_agents_by_keywords(keywords)
        candidates.update(keyword_agents)
        
        # Get agents by category (intent)
        try:
            category = AgentCategory(intent)
            category_agents = self.registry.get_agents_by_category(category)
            candidates.update(category_agents)
        except ValueError:
            pass
        
        # Filter for available agents
        available_candidates = [
            agent for agent in candidates 
            if self.registry.is_agent_available(agent.id)
        ]
        
        return available_candidates
    
    def _score_agents(self, agents: List[AgentDefinition], request: TaskRequest) -> List[Tuple[AgentDefinition, float]]:
        """Score agents based on suitability for the task."""
        scored = []
        
        for agent in agents:
            score = 0.0
            
            # Keyword match score
            request_keywords = self._extract_keywords(request)
            keyword_overlap = len(agent.keywords.intersection(request_keywords))
            score += keyword_overlap * 10
            
            # Capability match score
            for capability in agent.capabilities:
                if any(kw in capability.name.lower() for kw in request_keywords):
                    score += 20
                if any(kw in capability.description.lower() for kw in request_keywords):
                    score += 10
            
            # Historical performance score
            if agent.id in self.performance_metrics:
                metrics = self.performance_metrics[agent.id]
                if "success_rate" in metrics:
                    score += metrics["success_rate"] * 30
                if "avg_duration" in metrics:
                    # Prefer faster agents
                    score += (1.0 / max(metrics["avg_duration"], 0.001)) * 5
            
            # Priority boost
            priority_boost = {
                TaskPriority.CRITICAL: 50,
                TaskPriority.HIGH: 30,
                TaskPriority.MEDIUM: 10,
                TaskPriority.LOW: 0,
                TaskPriority.BACKGROUND: -10
            }
            if request.priority in priority_boost:
                score += priority_boost[request.priority]
            
            scored.append((agent, score))
        
        # Sort by score descending
        scored.sort(key=lambda x: x[1], reverse=True)
        return scored
    
    def _build_execution_plan(self, scored_agents: List[Tuple[AgentDefinition, float]], 
                             request: TaskRequest) -> List[Tuple[str, str]]:
        """Build execution plan from scored agents."""
        plan = []
        used_capabilities = set()
        
        for agent, score in scored_agents:
            if score < 10:  # Minimum score threshold
                break
                
            for capability in agent.capabilities:
                cap_id = f"{agent.id}.{capability.name}"
                if cap_id not in used_capabilities:
                    # Check if required context is available
                    context_available = all(
                        ctx in request.context or 
                        any(ctx in prod for _, prod_cap in plan 
                            for prod in self.registry.get_agent(prod_cap.split('.')[0])
                            .capabilities if prod_cap.split('.')[1] == prod.name)
                        for ctx in capability.required_context
                    )
                    
                    if context_available:
                        plan.append((agent.id, capability.name))
                        used_capabilities.add(cap_id)
                        
                        # Stop if we have enough steps
                        if len(plan) >= 10:
                            return plan
        
        return plan
    
    def _estimate_duration(self, execution_plan: List[Tuple[str, str]]) -> int:
        """Estimate total duration for execution plan."""
        total_duration = 0
        
        for agent_id, capability_name in execution_plan:
            agent = self.registry.get_agent(agent_id)
            if agent:
                for capability in agent.capabilities:
                    if capability.name == capability_name:
                        total_duration += capability.estimated_duration_ms
                        break
        
        return total_duration
    
    def _calculate_confidence(self, scored_agents: List[Tuple[AgentDefinition, float]]) -> float:
        """Calculate confidence in the routing decision."""
        if not scored_agents:
            return 0.0
            
        top_score = scored_agents[0][1] if scored_agents else 0
        
        if top_score > 100:
            return 0.95
        elif top_score > 50:
            return 0.80
        elif top_score > 20:
            return 0.60
        else:
            return 0.40
    
    def _generate_reasoning(self, request: TaskRequest, execution_plan: List[Tuple[str, str]]) -> str:
        """Generate reasoning for the routing decision."""
        if not execution_plan:
            return "No suitable agents found for this task"
            
        primary = execution_plan[0][0]
        agent = self.registry.get_agent(primary)
        
        reasoning = f"Selected {agent.name} as primary agent based on "
        reasoning += f"task type '{request.type}' and keywords. "
        reasoning += f"Execution involves {len(execution_plan)} steps across "
        reasoning += f"{len(set(a for a, _ in execution_plan))} agents."
        
        return reasoning
    
    def _identify_fallbacks(self, scored_agents: List[Tuple[AgentDefinition, float]]) -> List[str]:
        """Identify fallback agents."""
        fallbacks = []
        
        for agent, score in scored_agents[5:10]:  # Next 5 agents as fallbacks
            if score > 10:
                fallbacks.append(agent.id)
        
        # Always include general purpose as last fallback
        if "general_purpose" not in fallbacks:
            fallbacks.append("general_purpose")
            
        return fallbacks
    
    def update_performance_metrics(self, agent_id: str, success: bool, duration_ms: int):
        """Update performance metrics for an agent."""
        if agent_id not in self.performance_metrics:
            self.performance_metrics[agent_id] = {
                "success_count": 0,
                "failure_count": 0,
                "total_duration": 0,
                "execution_count": 0
            }
        
        metrics = self.performance_metrics[agent_id]
        
        if success:
            metrics["success_count"] += 1
        else:
            metrics["failure_count"] += 1
            
        metrics["total_duration"] += duration_ms
        metrics["execution_count"] += 1
        
        # Calculate derived metrics
        total = metrics["success_count"] + metrics["failure_count"]
        metrics["success_rate"] = metrics["success_count"] / total if total > 0 else 0
        metrics["avg_duration"] = metrics["total_duration"] / metrics["execution_count"]


# ============================================================================
# WORKFLOW ENGINE
# ============================================================================

class WorkflowEngine:
    """
    Executes multi-step workflows with agent coordination.
    Integrates with Temporal for reliability and state management.
    """
    
    def __init__(self, registry: AgentRegistry, router: IntelligentRouter):
        self.registry = registry
        self.router = router
        self.active_workflows: Dict[str, Any] = {}
        self.workflow_history: List[Dict[str, Any]] = []
        
    async def execute_task(self, request: TaskRequest) -> TaskResult:
        """Execute a task using the workflow engine."""
        start_time = time.time()
        
        # Route the task
        routing_decision = await self.router.route_task(request)
        
        # Initialize workflow context
        workflow_context = {
            "task_id": request.id,
            "routing": routing_decision,
            "completed_steps": [],
            "errors": [],
            "warnings": [],
            "artifacts": {}
        }
        
        self.active_workflows[request.id] = workflow_context
        
        try:
            # Execute the workflow
            result = await self._execute_workflow(request, routing_decision, workflow_context)
            
            # Update performance metrics
            duration_ms = int((time.time() - start_time) * 1000)
            for agent_id, _ in routing_decision.execution_order:
                self.router.update_performance_metrics(agent_id, result.success, duration_ms)
            
            # Clean up
            del self.active_workflows[request.id]
            self.workflow_history.append({
                "task_id": request.id,
                "completed_at": datetime.utcnow().isoformat(),
                "success": result.success,
                "duration_ms": duration_ms
            })
            
            return result
            
        except Exception as e:
            logger.error(f"Workflow execution failed for task {request.id}: {e}")
            
            duration_ms = int((time.time() - start_time) * 1000)
            
            return TaskResult(
                task_id=request.id,
                success=False,
                result=None,
                agents_used=[a for a, _ in routing_decision.execution_order],
                execution_time_ms=duration_ms,
                errors=[str(e)],
                warnings=workflow_context.get("warnings", []),
                artifacts=workflow_context.get("artifacts", {})
            )
    
    async def _execute_workflow(self, request: TaskRequest, routing: RoutingDecision, 
                               context: Dict[str, Any]) -> TaskResult:
        """Execute the workflow steps."""
        
        execution_context = dict(request.context)
        results = []
        agents_used = []
        
        for agent_id, capability_name in routing.execution_order:
            agent = self.registry.get_agent(agent_id)
            if not agent:
                context["warnings"].append(f"Agent {agent_id} not found, skipping")
                continue
            
            # Find the capability
            capability = None
            for cap in agent.capabilities:
                if cap.name == capability_name:
                    capability = cap
                    break
            
            if not capability:
                context["warnings"].append(f"Capability {capability_name} not found in {agent_id}")
                continue
            
            # Check required context
            missing_context = [
                ctx for ctx in capability.required_context 
                if ctx not in execution_context
            ]
            
            if missing_context:
                context["warnings"].append(
                    f"Missing required context for {agent_id}.{capability_name}: {missing_context}"
                )
                continue
            
            # Execute the capability (mock execution for now)
            try:
                result = await self._execute_capability(agent, capability, execution_context)
                
                # Update execution context with produced context
                for ctx_key in capability.produces_context:
                    execution_context[ctx_key] = f"mock_{ctx_key}_from_{agent_id}"
                
                results.append(result)
                agents_used.append(agent_id)
                context["completed_steps"].append(f"{agent_id}.{capability_name}")
                
            except Exception as e:
                context["errors"].append(f"Error in {agent_id}.{capability_name}: {e}")
                
                # Try fallback agents
                for fallback_id in routing.fallback_agents:
                    fallback_agent = self.registry.get_agent(fallback_id)
                    if fallback_agent and self.registry.is_agent_available(fallback_id):
                        try:
                            result = await self._execute_capability(
                                fallback_agent, capability, execution_context
                            )
                            results.append(result)
                            agents_used.append(fallback_id)
                            context["warnings"].append(
                                f"Used fallback agent {fallback_id} after {agent_id} failed"
                            )
                            break
                        except:
                            continue
        
        # Determine success
        success = len(context["errors"]) == 0 and len(results) > 0
        
        return TaskResult(
            task_id=request.id,
            success=success,
            result=results[-1] if results else None,  # Return last result
            agents_used=agents_used,
            execution_time_ms=0,  # Will be calculated by caller
            errors=context["errors"],
            warnings=context["warnings"],
            artifacts=context["artifacts"]
        )
    
    async def _execute_capability(self, agent: AgentDefinition, capability: AgentCapability, 
                                 context: Dict[str, Any]) -> Any:
        """Execute a specific capability of an agent."""
        
        # This is where actual agent execution would happen
        # For now, return mock result
        await asyncio.sleep(capability.estimated_duration_ms / 1000)
        
        return {
            "agent": agent.id,
            "capability": capability.name,
            "status": "completed",
            "result": f"Mock result from {agent.id}.{capability.name}"
        }


# ============================================================================
# UNIFIED MCP ORCHESTRATOR
# ============================================================================

class UnifiedMCPOrchestrator:
    """
    The main orchestrator that exposes all capabilities through MCP.
    This is the single entry point for all AI interactions.
    """
    
    def __init__(self, config_path: Optional[Path] = None):
        self.config = self._load_config(config_path)
        self.registry = AgentRegistry()
        self.router = IntelligentRouter(self.registry)
        self.workflow_engine = WorkflowEngine(self.registry, self.router)
        self.request_counter = 0
        self.start_time = datetime.utcnow()
        
        logger.info(f"Unified MCP Orchestrator initialized with {len(self.registry.get_all_agents())} agents")
    
    def _load_config(self, config_path: Optional[Path]) -> Dict[str, Any]:
        """Load orchestrator configuration."""
        if config_path and config_path.exists():
            with open(config_path, 'r') as f:
                return json.load(f)
        
        # Default configuration
        return {
            "max_concurrent_workflows": 10,
            "default_timeout_ms": 60000,
            "enable_monitoring": True,
            "enable_meta_learning": True,
            "router_config": {
                "min_confidence_threshold": 0.4,
                "max_execution_steps": 20
            }
        }
    
    async def handle_mcp_request(self, request: Dict[str, Any]) -> Dict[str, Any]:
        """
        Handle an incoming MCP request.
        This is the main entry point for AI interactions.
        """
        self.request_counter += 1
        
        # Parse request into TaskRequest
        task_request = TaskRequest(
            id=request.get("id", str(uuid.uuid4())),
            type=request.get("type", "general"),
            payload=request.get("payload", {}),
            context=request.get("context", {}),
            priority=TaskPriority[request.get("priority", "MEDIUM").upper()],
            timeout_ms=request.get("timeout_ms", self.config["default_timeout_ms"]),
            requester=request.get("requester", "mcp_client")
        )
        
        # Execute the task
        result = await self.workflow_engine.execute_task(task_request)
        
        # Format response for MCP
        return {
            "id": result.task_id,
            "success": result.success,
            "result": result.result,
            "metadata": {
                "agents_used": result.agents_used,
                "execution_time_ms": result.execution_time_ms,
                "errors": result.errors,
                "warnings": result.warnings,
                "artifacts": result.artifacts
            }
        }
    
    def register_external_agent(self, agent_definition: Dict[str, Any]) -> bool:
        """Register an external agent at runtime."""
        try:
            # Convert dict to AgentDefinition
            agent = AgentDefinition(
                id=agent_definition["id"],
                name=agent_definition["name"],
                category=AgentCategory[agent_definition["category"].upper()],
                description=agent_definition["description"],
                status=AgentStatus[agent_definition.get("status", "ACTIVE").upper()],
                capabilities=[
                    AgentCapability(**cap) for cap in agent_definition.get("capabilities", [])
                ],
                dependencies=agent_definition.get("dependencies", []),
                configuration=agent_definition.get("configuration", {}),
                keywords=set(agent_definition.get("keywords", []))
            )
            
            return self.registry.register_agent(agent)
            
        except Exception as e:
            logger.error(f"Failed to register external agent: {e}")
            return False
    
    def get_status(self) -> Dict[str, Any]:
        """Get orchestrator status."""
        uptime = (datetime.utcnow() - self.start_time).total_seconds()
        
        agents = self.registry.get_all_agents()
        active_agents = [a for a in agents if a.status == AgentStatus.ACTIVE]
        
        return {
            "status": "operational",
            "uptime_seconds": uptime,
            "total_requests": self.request_counter,
            "agents": {
                "total": len(agents),
                "active": len(active_agents),
                "by_category": {
                    cat.value: len(self.registry.get_agents_by_category(cat))
                    for cat in AgentCategory
                }
            },
            "active_workflows": len(self.workflow_engine.active_workflows),
            "configuration": self.config
        }
    
    def get_capabilities(self) -> List[Dict[str, Any]]:
        """Get all available capabilities."""
        capabilities = []
        
        for agent in self.registry.get_all_agents():
            if agent.status == AgentStatus.ACTIVE:
                for capability in agent.capabilities:
                    capabilities.append({
                        "agent_id": agent.id,
                        "agent_name": agent.name,
                        "capability": capability.name,
                        "description": capability.description,
                        "parameters": capability.parameters,
                        "required_context": capability.required_context,
                        "produces_context": capability.produces_context
                    })
        
        return capabilities
    
    async def shutdown(self):
        """Gracefully shutdown the orchestrator."""
        logger.info("Shutting down Unified MCP Orchestrator...")
        
        # Wait for active workflows to complete
        if self.workflow_engine.active_workflows:
            logger.info(f"Waiting for {len(self.workflow_engine.active_workflows)} active workflows...")
            await asyncio.sleep(5)  # Give workflows time to complete
        
        logger.info("Orchestrator shutdown complete")


# ============================================================================
# MAIN ENTRY POINT
# ============================================================================

async def main():
    """Main entry point for testing the orchestrator."""
    
    # Initialize orchestrator
    orchestrator = UnifiedMCPOrchestrator()
    
    # Example: Register a custom agent
    custom_agent = {
        "id": "custom_analyzer",
        "name": "Custom Analyzer",
        "category": "ANALYSIS",
        "description": "Custom analysis agent for demonstration",
        "status": "ACTIVE",
        "capabilities": [
            {
                "name": "analyze_custom",
                "description": "Perform custom analysis",
                "parameters": {"data": "object"},
                "produces_context": ["custom_analysis"]
            }
        ],
        "keywords": ["custom", "analysis", "demo"]
    }
    
    orchestrator.register_external_agent(custom_agent)
    
    # Example: Handle an MCP request
    example_request = {
        "id": "test_001",
        "type": "code_generation",
        "payload": {
            "description": "Generate a Python function to calculate fibonacci numbers",
            "language": "python"
        },
        "context": {
            "domain": "algorithms"
        },
        "priority": "HIGH"
    }
    
    print("Processing example request...")
    result = await orchestrator.handle_mcp_request(example_request)
    print(f"Result: {json.dumps(result, indent=2)}")
    
    # Get status
    status = orchestrator.get_status()
    print(f"\nOrchestrator Status: {json.dumps(status, indent=2)}")
    
    # Get capabilities
    capabilities = orchestrator.get_capabilities()
    print(f"\nTotal Capabilities: {len(capabilities)}")
    
    # Shutdown
    await orchestrator.shutdown()


if __name__ == "__main__":
    asyncio.run(main())