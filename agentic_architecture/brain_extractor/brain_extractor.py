"""
Brain Extractor - Intelligent Knowledge Capture and Onboarding Tool
===================================================================
Advanced agentic architecture system that combines RAG, LAG, and DAG patterns
for intelligent knowledge extraction, capture, and onboarding automation.

Based on competitive research findings:
- Addresses limitations of traditional knowledge capture systems
- Implements agentic RAG patterns for autonomous knowledge extraction
- Uses LAG for dynamic onboarding workflow automation
- Leverages DAG for complex knowledge processing pipelines
- Provides enterprise-grade security and compliance features

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

# Import our core engines
import sys
import os
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from core.rag_engine import AdvancedRAGEngine, RetrievalStrategy, SecurityLevel
from core.lag_engine import LAGEngine, ActionNode, ActionType, ActionEdge, LatentActionGraph, ExecutionStrategy
from core.dag_orchestrator import DAGOrchestrator, DAGDefinition, TaskDefinition, TaskType

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


# ============================================================================
# CORE DATA STRUCTURES FOR BRAIN EXTRACTOR
# ============================================================================

class KnowledgeType(Enum):
    """Types of knowledge that can be extracted."""
    PROCEDURAL = "procedural"  # How-to knowledge
    DECLARATIVE = "declarative"  # Facts and information
    CONTEXTUAL = "contextual"  # Situational knowledge
    EXPERIENTIAL = "experiential"  # Experience-based insights
    INSTITUTIONAL = "institutional"  # Company/organization specific
    TECHNICAL = "technical"  # Technical documentation
    REGULATORY = "regulatory"  # Compliance and regulations
    CULTURAL = "cultural"  # Cultural and social knowledge


class ExtractionMethod(Enum):
    """Methods for knowledge extraction."""
    DOCUMENT_ANALYSIS = "document_analysis"
    INTERVIEW_AUTOMATION = "interview_automation"
    SYSTEM_OBSERVATION = "system_observation"
    PROCESS_MINING = "process_mining"
    COLLABORATIVE_FILTERING = "collaborative_filtering"
    EXPERT_ELICITATION = "expert_elicitation"
    BEHAVIORAL_ANALYSIS = "behavioral_analysis"
    SEMANTIC_ANALYSIS = "semantic_analysis"


class OnboardingStage(Enum):
    """Stages of the onboarding process."""
    DISCOVERY = "discovery"
    EXTRACTION = "extraction"
    VALIDATION = "validation"
    STRUCTURING = "structuring"
    INTEGRATION = "integration"
    PERSONALIZATION = "personalization"
    DELIVERY = "delivery"
    FEEDBACK = "feedback"
    OPTIMIZATION = "optimization"


class PersonaType(Enum):
    """Types of user personas for personalized onboarding."""
    TECHNICAL_EXPERT = "technical_expert"
    BUSINESS_ANALYST = "business_analyst"
    PROJECT_MANAGER = "project_manager"
    EXECUTIVE = "executive"
    NEW_HIRE = "new_hire"
    CONTRACTOR = "contractor"
    INTERN = "intern"
    DOMAIN_EXPERT = "domain_expert"


@dataclass
class KnowledgeSource:
    """Represents a source of knowledge for extraction."""
    id: str
    name: str
    source_type: str  # "document", "database", "person", "system", "process"
    location: str  # Path, URL, or identifier
    access_credentials: Dict[str, str] = field(default_factory=dict)
    extraction_methods: List[ExtractionMethod] = field(default_factory=list)
    knowledge_types: List[KnowledgeType] = field(default_factory=list)
    security_classification: SecurityLevel = SecurityLevel.INTERNAL
    last_updated: datetime = field(default_factory=datetime.utcnow)
    metadata: Dict[str, Any] = field(default_factory=dict)
    extraction_history: List[Dict[str, Any]] = field(default_factory=list)


@dataclass
class KnowledgeArtifact:
    """Represents extracted knowledge."""
    id: str
    title: str
    content: str
    knowledge_type: KnowledgeType
    source_id: str
    extraction_method: ExtractionMethod
    confidence_score: float
    quality_metrics: Dict[str, float] = field(default_factory=dict)
    tags: Set[str] = field(default_factory=set)
    related_artifacts: List[str] = field(default_factory=list)
    personas: List[PersonaType] = field(default_factory=list)
    security_level: SecurityLevel = SecurityLevel.INTERNAL
    created_at: datetime = field(default_factory=datetime.utcnow)
    updated_at: datetime = field(default_factory=datetime.utcnow)
    validation_status: str = "pending"  # pending, validated, rejected
    metadata: Dict[str, Any] = field(default_factory=dict)


@dataclass
class OnboardingProfile:
    """Profile for personalized onboarding."""
    id: str
    user_id: str
    persona: PersonaType
    role: str
    department: str
    experience_level: str  # "beginner", "intermediate", "advanced", "expert"
    learning_style: str  # "visual", "auditory", "kinesthetic", "reading"
    knowledge_gaps: List[str] = field(default_factory=list)
    interests: List[str] = field(default_factory=list)
    goals: List[str] = field(default_factory=list)
    preferred_content_types: List[str] = field(default_factory=list)
    time_constraints: Dict[str, int] = field(default_factory=dict)
    security_clearance: SecurityLevel = SecurityLevel.INTERNAL
    progress_tracking: Dict[str, Any] = field(default_factory=dict)
    created_at: datetime = field(default_factory=datetime.utcnow)
    updated_at: datetime = field(default_factory=datetime.utcnow)


@dataclass
class OnboardingPlan:
    """Personalized onboarding plan."""
    id: str
    profile_id: str
    artifacts: List[str]  # Knowledge artifact IDs
    learning_path: List[Dict[str, Any]]
    estimated_duration: int  # Minutes
    milestones: List[Dict[str, Any]]
    assessments: List[Dict[str, Any]]
    resources: List[Dict[str, Any]]
    status: str = "draft"  # draft, active, completed, paused
    progress: float = 0.0
    created_at: datetime = field(default_factory=datetime.utcnow)
    updated_at: datetime = field(default_factory=datetime.utcnow)


@dataclass
class ExtractionRequest:
    """Request for knowledge extraction."""
    id: str
    requester: str
    sources: List[str]  # Source IDs
    knowledge_types: List[KnowledgeType]
    extraction_methods: List[ExtractionMethod]
    target_personas: List[PersonaType]
    priority: int = 5
    deadline: Optional[datetime] = None
    requirements: Dict[str, Any] = field(default_factory=dict)
    status: str = "pending"  # pending, in_progress, completed, failed
    created_at: datetime = field(default_factory=datetime.utcnow)


# ============================================================================
# KNOWLEDGE EXTRACTORS
# ============================================================================

class BaseKnowledgeExtractor(ABC):
    """Base class for knowledge extractors."""
    
    def __init__(self, extractor_id: str, config: Dict[str, Any]):
        self.extractor_id = extractor_id
        self.config = config
        self.extraction_history: List[Dict[str, Any]] = []
        self.metrics = defaultdict(float)
    
    @abstractmethod
    async def extract_knowledge(self, source: KnowledgeSource, 
                               requirements: Dict[str, Any]) -> List[KnowledgeArtifact]:
        """Extract knowledge from a source."""
        pass
    
    @abstractmethod
    async def validate_source(self, source: KnowledgeSource) -> bool:
        """Validate that the source is accessible and processable."""
        pass
    
    def update_metrics(self, extraction_time: float, artifacts_count: int, success: bool):
        """Update extractor metrics."""
        self.metrics["total_extractions"] += 1
        self.metrics["total_artifacts"] += artifacts_count
        self.metrics["total_time"] += extraction_time
        
        if success:
            self.metrics["successful_extractions"] += 1
        else:
            self.metrics["failed_extractions"] += 1
        
        self.metrics["success_rate"] = (
            self.metrics["successful_extractions"] / self.metrics["total_extractions"]
        )
        self.metrics["avg_extraction_time"] = (
            self.metrics["total_time"] / self.metrics["total_extractions"]
        )
        self.metrics["avg_artifacts_per_extraction"] = (
            self.metrics["total_artifacts"] / self.metrics["total_extractions"]
        )


class DocumentAnalysisExtractor(BaseKnowledgeExtractor):
    """Extractor for document-based knowledge."""
    
    async def extract_knowledge(self, source: KnowledgeSource, 
                               requirements: Dict[str, Any]) -> List[KnowledgeArtifact]:
        """Extract knowledge from documents."""
        start_time = time.time()
        artifacts = []
        
        try:
            # Simulate document processing
            await asyncio.sleep(0.5)  # Simulate processing time
            
            # Mock document analysis
            doc_types = ["procedure", "specification", "manual", "guide", "policy"]
            
            for i, doc_type in enumerate(doc_types):
                if i >= requirements.get("max_artifacts", 3):
                    break
                
                artifact = KnowledgeArtifact(
                    id=f"doc_artifact_{source.id}_{i}_{int(time.time())}",
                    title=f"{doc_type.title()} from {source.name}",
                    content=f"Extracted {doc_type} content from {source.name}. "
                           f"This includes detailed information about processes, "
                           f"procedures, and best practices relevant to the organization.",
                    knowledge_type=KnowledgeType.PROCEDURAL if doc_type in ["procedure", "manual"] else KnowledgeType.DECLARATIVE,
                    source_id=source.id,
                    extraction_method=ExtractionMethod.DOCUMENT_ANALYSIS,
                    confidence_score=0.85 - (i * 0.05),
                    quality_metrics={
                        "completeness": 0.9 - (i * 0.02),
                        "accuracy": 0.88 - (i * 0.03),
                        "relevance": 0.92 - (i * 0.01)
                    },
                    tags={doc_type, "extracted", source.name.lower()},
                    personas=[PersonaType.NEW_HIRE, PersonaType.TECHNICAL_EXPERT],
                    security_level=source.security_classification
                )
                artifacts.append(artifact)
            
            extraction_time = time.time() - start_time
            self.update_metrics(extraction_time, len(artifacts), True)
            
            return artifacts
            
        except Exception as e:
            extraction_time = time.time() - start_time
            self.update_metrics(extraction_time, 0, False)
            logger.error(f"Document extraction failed for {source.id}: {e}")
            return []
    
    async def validate_source(self, source: KnowledgeSource) -> bool:
        """Validate document source."""
        # Simulate document accessibility check
        await asyncio.sleep(0.1)
        
        # Check if location is accessible
        if not source.location:
            return False
        
        # Mock validation based on source type
        valid_types = ["pdf", "docx", "txt", "md", "html", "xml"]
        return any(ext in source.location.lower() for ext in valid_types)


class InterviewAutomationExtractor(BaseKnowledgeExtractor):
    """Extractor for interview-based knowledge capture."""
    
    async def extract_knowledge(self, source: KnowledgeSource, 
                               requirements: Dict[str, Any]) -> List[KnowledgeArtifact]:
        """Extract knowledge through automated interviews."""
        start_time = time.time()
        artifacts = []
        
        try:
            # Simulate interview processing
            await asyncio.sleep(1.0)  # Longer processing for interviews
            
            interview_topics = [
                ("Daily Processes", KnowledgeType.PROCEDURAL),
                ("Key Insights", KnowledgeType.EXPERIENTIAL),
                ("Best Practices", KnowledgeType.INSTITUTIONAL),
                ("Common Issues", KnowledgeType.CONTEXTUAL),
                ("Expert Tips", KnowledgeType.EXPERIENTIAL)
            ]
            
            for i, (topic, knowledge_type) in enumerate(interview_topics):
                if i >= requirements.get("max_artifacts", 3):
                    break
                
                artifact = KnowledgeArtifact(
                    id=f"interview_artifact_{source.id}_{i}_{int(time.time())}",
                    title=f"{topic} - Interview Extract",
                    content=f"Knowledge extracted from interview about {topic.lower()}. "
                           f"Contains practical insights, real-world examples, and "
                           f"expert perspectives on how things actually work in practice.",
                    knowledge_type=knowledge_type,
                    source_id=source.id,
                    extraction_method=ExtractionMethod.INTERVIEW_AUTOMATION,
                    confidence_score=0.90 - (i * 0.03),
                    quality_metrics={
                        "completeness": 0.85 - (i * 0.02),
                        "accuracy": 0.92 - (i * 0.01),
                        "relevance": 0.94 - (i * 0.01)
                    },
                    tags={topic.lower().replace(" ", "_"), "interview", "expert_knowledge"},
                    personas=[PersonaType.NEW_HIRE, PersonaType.BUSINESS_ANALYST],
                    security_level=source.security_classification
                )
                artifacts.append(artifact)
            
            extraction_time = time.time() - start_time
            self.update_metrics(extraction_time, len(artifacts), True)
            
            return artifacts
            
        except Exception as e:
            extraction_time = time.time() - start_time
            self.update_metrics(extraction_time, 0, False)
            logger.error(f"Interview extraction failed for {source.id}: {e}")
            return []
    
    async def validate_source(self, source: KnowledgeSource) -> bool:
        """Validate interview source."""
        await asyncio.sleep(0.1)
        
        # Check if source represents a person or interview data
        return source.source_type in ["person", "interview", "expert"]


class SystemObservationExtractor(BaseKnowledgeExtractor):
    """Extractor for system and process observation."""
    
    async def extract_knowledge(self, source: KnowledgeSource, 
                               requirements: Dict[str, Any]) -> List[KnowledgeArtifact]:
        """Extract knowledge through system observation."""
        start_time = time.time()
        artifacts = []
        
        try:
            # Simulate system observation
            await asyncio.sleep(0.8)
            
            observation_types = [
                ("System Workflows", KnowledgeType.PROCEDURAL),
                ("Usage Patterns", KnowledgeType.CONTEXTUAL),
                ("Performance Metrics", KnowledgeType.TECHNICAL),
                ("User Behaviors", KnowledgeType.EXPERIENTIAL)
            ]
            
            for i, (obs_type, knowledge_type) in enumerate(observation_types):
                if i >= requirements.get("max_artifacts", 3):
                    break
                
                artifact = KnowledgeArtifact(
                    id=f"system_artifact_{source.id}_{i}_{int(time.time())}",
                    title=f"{obs_type} - System Observation",
                    content=f"Knowledge derived from observing {obs_type.lower()} in the system. "
                           f"Includes patterns, trends, and insights about how the system "
                           f"is actually used in practice.",
                    knowledge_type=knowledge_type,
                    source_id=source.id,
                    extraction_method=ExtractionMethod.SYSTEM_OBSERVATION,
                    confidence_score=0.88 - (i * 0.04),
                    quality_metrics={
                        "completeness": 0.87 - (i * 0.03),
                        "accuracy": 0.90 - (i * 0.02),
                        "relevance": 0.89 - (i * 0.02)
                    },
                    tags={obs_type.lower().replace(" ", "_"), "system", "observation"},
                    personas=[PersonaType.TECHNICAL_EXPERT, PersonaType.BUSINESS_ANALYST],
                    security_level=source.security_classification
                )
                artifacts.append(artifact)
            
            extraction_time = time.time() - start_time
            self.update_metrics(extraction_time, len(artifacts), True)
            
            return artifacts
            
        except Exception as e:
            extraction_time = time.time() - start_time
            self.update_metrics(extraction_time, 0, False)
            logger.error(f"System observation failed for {source.id}: {e}")
            return []
    
    async def validate_source(self, source: KnowledgeSource) -> bool:
        """Validate system source."""
        await asyncio.sleep(0.1)
        
        # Check if source represents a system
        return source.source_type in ["system", "application", "database", "api"]


# ============================================================================
# ONBOARDING ORCHESTRATOR
# ============================================================================

class OnboardingOrchestrator:
    """Orchestrates the complete onboarding process using LAG and DAG patterns."""
    
    def __init__(self, lag_engine: LAGEngine, dag_orchestrator: DAGOrchestrator):
        self.lag_engine = lag_engine
        self.dag_orchestrator = dag_orchestrator
        self.active_onboardings: Dict[str, Dict[str, Any]] = {}
        self.completed_onboardings: List[Dict[str, Any]] = []
        
        # Initialize onboarding workflows
        self._initialize_onboarding_workflows()
    
    def _initialize_onboarding_workflows(self):
        """Initialize standard onboarding workflows."""
        
        # Create personalized onboarding LAG
        personalized_lag = LatentActionGraph(
            "personalized_onboarding",
            "Personalized Onboarding Workflow",
            "Adaptive onboarding workflow that personalizes based on user profile"
        )
        
        # Profile analysis action
        profile_action = ActionNode(
            id="analyze_profile",
            name="Analyze User Profile",
            action_type=ActionType.COMPUTATION,
            description="Analyze user profile to determine personalization strategy",
            parameters={"analysis_type": "profile_personalization"},
            expected_outputs=["personalization_strategy"],
            estimated_duration_ms=2000
        )
        
        # Content selection action
        content_action = ActionNode(
            id="select_content",
            name="Select Relevant Content",
            action_type=ActionType.COMPUTATION,
            description="Select and rank content based on user profile",
            parameters={"selection_criteria": "relevance_and_difficulty"},
            expected_inputs=["personalization_strategy"],
            expected_outputs=["selected_content"],
            dependencies=["analyze_profile"],
            estimated_duration_ms=3000
        )
        
        # Learning path creation action
        path_action = ActionNode(
            id="create_learning_path",
            name="Create Learning Path",
            action_type=ActionType.COMPUTATION,
            description="Create personalized learning path",
            parameters={"path_type": "adaptive"},
            expected_inputs=["selected_content"],
            expected_outputs=["learning_path"],
            dependencies=["select_content"],
            estimated_duration_ms=2500
        )
        
        # Delivery planning action
        delivery_action = ActionNode(
            id="plan_delivery",
            name="Plan Content Delivery",
            action_type=ActionType.DECISION,
            description="Plan optimal content delivery schedule",
            parameters={"delivery_strategy": "progressive"},
            expected_inputs=["learning_path"],
            expected_outputs=["delivery_plan"],
            dependencies=["create_learning_path"],
            estimated_duration_ms=1500
        )
        
        # Add actions to LAG
        personalized_lag.add_action(profile_action)
        personalized_lag.add_action(content_action)
        personalized_lag.add_action(path_action)
        personalized_lag.add_action(delivery_action)
        
        # Add edges
        personalized_lag.add_edge(ActionEdge(
            id="profile_to_content",
            source_action="analyze_profile",
            target_action="select_content",
            edge_type="sequence"
        ))
        
        personalized_lag.add_edge(ActionEdge(
            id="content_to_path",
            source_action="select_content",
            target_action="create_learning_path",
            edge_type="sequence"
        ))
        
        personalized_lag.add_edge(ActionEdge(
            id="path_to_delivery",
            source_action="create_learning_path",
            target_action="plan_delivery",
            edge_type="sequence"
        ))
        
        # Register the LAG
        self.lag_engine.register_workflow(personalized_lag)
        
        logger.info("Initialized onboarding workflows")
    
    async def create_onboarding_plan(self, profile: OnboardingProfile, 
                                   artifacts: List[KnowledgeArtifact]) -> OnboardingPlan:
        """Create a personalized onboarding plan."""
        
        # Execute personalization workflow
        personalization_result = await self.lag_engine.execute_workflow(
            "personalized_onboarding",
            strategy="adaptive",
            initial_context={
                "user_profile": profile.__dict__,
                "available_artifacts": [a.__dict__ for a in artifacts]
            }
        )
        
        # Create learning path based on profile and artifacts
        learning_path = self._create_learning_path(profile, artifacts)
        
        # Estimate duration
        estimated_duration = self._estimate_duration(learning_path, profile)
        
        # Create milestones
        milestones = self._create_milestones(learning_path)
        
        # Create assessments
        assessments = self._create_assessments(profile, artifacts)
        
        plan = OnboardingPlan(
            id=f"plan_{profile.id}_{int(time.time())}",
            profile_id=profile.id,
            artifacts=[a.id for a in artifacts if self._is_relevant_for_profile(a, profile)],
            learning_path=learning_path,
            estimated_duration=estimated_duration,
            milestones=milestones,
            assessments=assessments,
            resources=self._gather_resources(artifacts, profile)
        )
        
        return plan
    
    def _create_learning_path(self, profile: OnboardingProfile, 
                            artifacts: List[KnowledgeArtifact]) -> List[Dict[str, Any]]:
        """Create a structured learning path."""
        
        # Filter artifacts for the profile
        relevant_artifacts = [
            a for a in artifacts 
            if self._is_relevant_for_profile(a, profile)
        ]
        
        # Sort by complexity and dependency
        sorted_artifacts = self._sort_artifacts_by_complexity(relevant_artifacts, profile)
        
        learning_path = []
        
        for i, artifact in enumerate(sorted_artifacts):
            step = {
                "id": f"step_{i+1}",
                "title": artifact.title,
                "artifact_id": artifact.id,
                "type": "knowledge_consumption",
                "estimated_time_minutes": self._estimate_artifact_time(artifact, profile),
                "prerequisites": self._get_prerequisites(artifact, sorted_artifacts[:i]),
                "difficulty": self._assess_difficulty(artifact, profile),
                "interactive_elements": self._suggest_interactive_elements(artifact),
                "assessment_required": artifact.knowledge_type in [
                    KnowledgeType.PROCEDURAL, KnowledgeType.TECHNICAL
                ]
            }
            learning_path.append(step)
        
        return learning_path
    
    def _is_relevant_for_profile(self, artifact: KnowledgeArtifact, 
                               profile: OnboardingProfile) -> bool:
        """Check if artifact is relevant for the profile."""
        
        # Check persona match
        if profile.persona in artifact.personas:
            return True
        
        # Check security clearance
        clearance_levels = {
            SecurityLevel.PUBLIC: 0,
            SecurityLevel.INTERNAL: 1,
            SecurityLevel.CONFIDENTIAL: 2,
            SecurityLevel.RESTRICTED: 3
        }
        
        if clearance_levels[profile.security_clearance] < clearance_levels[artifact.security_level]:
            return False
        
        # Check interests and goals
        artifact_tags = {tag.lower() for tag in artifact.tags}
        user_interests = {interest.lower() for interest in profile.interests}
        user_goals = {goal.lower() for goal in profile.goals}
        
        if artifact_tags & (user_interests | user_goals):
            return True
        
        # Check knowledge gaps
        knowledge_gaps = {gap.lower() for gap in profile.knowledge_gaps}
        if artifact_tags & knowledge_gaps:
            return True
        
        return False
    
    def _sort_artifacts_by_complexity(self, artifacts: List[KnowledgeArtifact], 
                                    profile: OnboardingProfile) -> List[KnowledgeArtifact]:
        """Sort artifacts by complexity appropriate for the profile."""
        
        complexity_scores = {}
        
        for artifact in artifacts:
            score = 0
            
            # Base complexity by knowledge type
            type_complexity = {
                KnowledgeType.DECLARATIVE: 1,
                KnowledgeType.CONTEXTUAL: 2,
                KnowledgeType.PROCEDURAL: 3,
                KnowledgeType.TECHNICAL: 4,
                KnowledgeType.EXPERIENTIAL: 5,
                KnowledgeType.INSTITUTIONAL: 3,
                KnowledgeType.REGULATORY: 4,
                KnowledgeType.CULTURAL: 2
            }
            score += type_complexity.get(artifact.knowledge_type, 3)
            
            # Adjust for user experience level
            experience_multiplier = {
                "beginner": 1.5,
                "intermediate": 1.0,
                "advanced": 0.8,
                "expert": 0.5
            }
            score *= experience_multiplier.get(profile.experience_level, 1.0)
            
            # Consider content length
            score += min(len(artifact.content) / 1000, 5)
            
            complexity_scores[artifact.id] = score
        
        return sorted(artifacts, key=lambda a: complexity_scores[a.id])
    
    def _estimate_duration(self, learning_path: List[Dict[str, Any]], 
                          profile: OnboardingProfile) -> int:
        """Estimate total duration for the learning path."""
        total_minutes = sum(step.get("estimated_time_minutes", 30) for step in learning_path)
        
        # Adjust for learning style and experience
        style_multiplier = {
            "visual": 1.0,
            "auditory": 1.2,
            "kinesthetic": 1.3,
            "reading": 0.9
        }
        
        experience_multiplier = {
            "beginner": 1.5,
            "intermediate": 1.0,
            "advanced": 0.8,
            "expert": 0.6
        }
        
        total_minutes *= style_multiplier.get(profile.learning_style, 1.0)
        total_minutes *= experience_multiplier.get(profile.experience_level, 1.0)
        
        return int(total_minutes)
    
    def _create_milestones(self, learning_path: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
        """Create milestones for the learning path."""
        milestones = []
        
        path_length = len(learning_path)
        milestone_intervals = [0.25, 0.5, 0.75, 1.0]  # 25%, 50%, 75%, 100%
        
        for i, interval in enumerate(milestone_intervals):
            step_index = int(path_length * interval) - 1
            if step_index >= 0 and step_index < path_length:
                milestone = {
                    "id": f"milestone_{i+1}",
                    "title": f"Milestone {i+1}: {int(interval*100)}% Complete",
                    "step_index": step_index,
                    "description": f"Complete {int(interval*100)}% of onboarding content",
                    "reward": self._get_milestone_reward(i+1),
                    "assessment_required": i == len(milestone_intervals) - 1  # Final assessment
                }
                milestones.append(milestone)
        
        return milestones
    
    def _create_assessments(self, profile: OnboardingProfile, 
                          artifacts: List[KnowledgeArtifact]) -> List[Dict[str, Any]]:
        """Create assessments for knowledge validation."""
        assessments = []
        
        # Group artifacts by knowledge type
        knowledge_groups = defaultdict(list)
        for artifact in artifacts:
            if self._is_relevant_for_profile(artifact, profile):
                knowledge_groups[artifact.knowledge_type].append(artifact)
        
        for i, (knowledge_type, group_artifacts) in enumerate(knowledge_groups.items()):
            assessment = {
                "id": f"assessment_{i+1}",
                "title": f"{knowledge_type.value.title()} Knowledge Check",
                "type": "knowledge_check",
                "knowledge_type": knowledge_type.value,
                "artifacts": [a.id for a in group_artifacts],
                "questions": self._generate_assessment_questions(group_artifacts),
                "passing_score": 80,
                "max_attempts": 3,
                "time_limit_minutes": 30
            }
            assessments.append(assessment)
        
        return assessments
    
    def _gather_resources(self, artifacts: List[KnowledgeArtifact], 
                         profile: OnboardingProfile) -> List[Dict[str, Any]]:
        """Gather additional resources for onboarding."""
        resources = []
        
        # Add quick reference guides
        resources.append({
            "id": "quick_reference",
            "title": "Quick Reference Guide",
            "type": "reference",
            "description": "Quick reference for key concepts and procedures",
            "access_method": "download"
        })
        
        # Add contact information
        resources.append({
            "id": "help_contacts",
            "title": "Help and Support Contacts",
            "type": "contacts",
            "description": "Key contacts for questions and support",
            "contacts": [
                {"role": "Onboarding Coordinator", "email": "onboarding@company.com"},
                {"role": "Technical Support", "email": "techsupport@company.com"},
                {"role": "HR Representative", "email": "hr@company.com"}
            ]
        })
        
        # Add interactive tools
        if profile.learning_style in ["kinesthetic", "visual"]:
            resources.append({
                "id": "interactive_simulator",
                "title": "Interactive Process Simulator",
                "type": "simulator",
                "description": "Hands-on practice environment",
                "access_method": "web_portal"
            })
        
        return resources
    
    async def execute_onboarding(self, plan: OnboardingPlan) -> Dict[str, Any]:
        """Execute an onboarding plan using DAG orchestration."""
        
        # Create DAG for onboarding execution
        onboarding_dag = self._create_onboarding_dag(plan)
        
        # Register and trigger DAG
        self.dag_orchestrator.register_dag(onboarding_dag)
        run_id = await self.dag_orchestrator.trigger_dag(
            onboarding_dag.dag_id,
            conf={"plan_id": plan.id}
        )
        
        if run_id:
            self.active_onboardings[plan.id] = {
                "plan": plan,
                "dag_run_id": run_id,
                "start_time": datetime.utcnow(),
                "status": "in_progress"
            }
        
        return {"run_id": run_id, "status": "started" if run_id else "failed"}
    
    def _create_onboarding_dag(self, plan: OnboardingPlan) -> DAGDefinition:
        """Create a DAG for executing onboarding plan."""
        
        dag_id = f"onboarding_{plan.id}"
        dag = DAGDefinition(
            dag_id=dag_id,
            name=f"Onboarding Execution for Plan {plan.id}",
            description="Execute personalized onboarding plan",
            tags={"onboarding", "personalized"}
        )
        
        # Create tasks for each learning path step
        previous_task_id = None
        
        for i, step in enumerate(plan.learning_path):
            task_id = f"deliver_step_{i+1}"
            
            task = TaskDefinition(
                id=task_id,
                name=f"Deliver {step['title']}",
                task_type=TaskType.NOTIFICATION,  # Content delivery
                description=f"Deliver learning content: {step['title']}",
                parameters={
                    "step_data": step,
                    "delivery_method": "progressive"
                },
                dependencies=[previous_task_id] if previous_task_id else [],
                timeout_seconds=step.get("estimated_time_minutes", 30) * 60,
                resources={"memory": 1, "cpu": 1}
            )
            
            dag.tasks[task_id] = task
            previous_task_id = task_id
            
            # Add assessment task if required
            if step.get("assessment_required", False):
                assessment_task_id = f"assess_step_{i+1}"
                assessment_task = TaskDefinition(
                    id=assessment_task_id,
                    name=f"Assess {step['title']}",
                    task_type=TaskType.VALIDATE,
                    description=f"Assess learning for: {step['title']}",
                    parameters={"assessment_type": "knowledge_check"},
                    dependencies=[task_id],
                    timeout_seconds=1800,  # 30 minutes
                    resources={"memory": 1, "cpu": 1}
                )
                dag.tasks[assessment_task_id] = assessment_task
                previous_task_id = assessment_task_id
        
        # Add milestone tasks
        for milestone in plan.milestones:
            milestone_task_id = f"milestone_{milestone['id']}"
            milestone_task = TaskDefinition(
                id=milestone_task_id,
                name=milestone['title'],
                task_type=TaskType.CHECKPOINT,
                description=milestone['description'],
                parameters={"milestone_data": milestone},
                dependencies=[f"deliver_step_{milestone['step_index']+1}"],
                timeout_seconds=300,  # 5 minutes
                resources={"memory": 1, "cpu": 1}
            )
            dag.tasks[milestone_task_id] = milestone_task
        
        # Add completion task
        completion_task = TaskDefinition(
            id="onboarding_completion",
            name="Complete Onboarding",
            task_type=TaskType.NOTIFICATION,
            description="Mark onboarding as completed",
            parameters={"completion_type": "onboarding_success"},
            dependencies=[previous_task_id] if previous_task_id else [],
            timeout_seconds=300,
            resources={"memory": 1, "cpu": 1}
        )
        dag.tasks["onboarding_completion"] = completion_task
        
        return dag
    
    # Helper methods
    def _estimate_artifact_time(self, artifact: KnowledgeArtifact, 
                              profile: OnboardingProfile) -> int:
        """Estimate time to consume an artifact."""
        base_time = max(len(artifact.content) / 200, 15)  # ~200 words per minute, min 15 min
        
        # Adjust for complexity
        complexity_multiplier = {
            KnowledgeType.DECLARATIVE: 1.0,
            KnowledgeType.PROCEDURAL: 1.3,
            KnowledgeType.TECHNICAL: 1.5,
            KnowledgeType.EXPERIENTIAL: 1.2,
            KnowledgeType.INSTITUTIONAL: 1.1,
            KnowledgeType.REGULATORY: 1.4,
            KnowledgeType.CONTEXTUAL: 1.1,
            KnowledgeType.CULTURAL: 1.0
        }
        
        base_time *= complexity_multiplier.get(artifact.knowledge_type, 1.0)
        
        # Adjust for experience level
        experience_multiplier = {
            "beginner": 1.5,
            "intermediate": 1.0,
            "advanced": 0.8,
            "expert": 0.6
        }
        
        base_time *= experience_multiplier.get(profile.experience_level, 1.0)
        
        return int(base_time)
    
    def _get_prerequisites(self, artifact: KnowledgeArtifact, 
                          previous_artifacts: List[KnowledgeArtifact]) -> List[str]:
        """Get prerequisites for an artifact."""
        prerequisites = []
        
        # Simple heuristic: technical artifacts need foundational knowledge
        if artifact.knowledge_type == KnowledgeType.TECHNICAL:
            for prev_artifact in previous_artifacts:
                if prev_artifact.knowledge_type in [KnowledgeType.DECLARATIVE, KnowledgeType.INSTITUTIONAL]:
                    prerequisites.append(prev_artifact.id)
        
        return prerequisites
    
    def _assess_difficulty(self, artifact: KnowledgeArtifact, 
                         profile: OnboardingProfile) -> str:
        """Assess difficulty level for an artifact."""
        base_difficulty = {
            KnowledgeType.DECLARATIVE: 2,
            KnowledgeType.CONTEXTUAL: 3,
            KnowledgeType.PROCEDURAL: 4,
            KnowledgeType.TECHNICAL: 5,
            KnowledgeType.EXPERIENTIAL: 4,
            KnowledgeType.INSTITUTIONAL: 3,
            KnowledgeType.REGULATORY: 4,
            KnowledgeType.CULTURAL: 2
        }.get(artifact.knowledge_type, 3)
        
        # Adjust for user experience
        experience_adjustment = {
            "beginner": 1,
            "intermediate": 0,
            "advanced": -1,
            "expert": -2
        }.get(profile.experience_level, 0)
        
        final_difficulty = base_difficulty + experience_adjustment
        
        if final_difficulty <= 2:
            return "easy"
        elif final_difficulty <= 4:
            return "medium"
        else:
            return "hard"
    
    def _suggest_interactive_elements(self, artifact: KnowledgeArtifact) -> List[str]:
        """Suggest interactive elements for an artifact."""
        elements = []
        
        if artifact.knowledge_type == KnowledgeType.PROCEDURAL:
            elements.extend(["step_by_step_guide", "checklist", "practice_exercise"])
        
        if artifact.knowledge_type == KnowledgeType.TECHNICAL:
            elements.extend(["code_examples", "hands_on_lab", "troubleshooting_guide"])
        
        if artifact.knowledge_type == KnowledgeType.EXPERIENTIAL:
            elements.extend(["case_study", "scenario_simulation", "reflection_questions"])
        
        return elements
    
    def _get_milestone_reward(self, milestone_number: int) -> str:
        """Get reward for milestone completion."""
        rewards = [
            "Digital badge: Getting Started",
            "Digital badge: Halfway Hero",
            "Digital badge: Almost There",
            "Digital badge: Onboarding Champion"
        ]
        
        return rewards[min(milestone_number - 1, len(rewards) - 1)]
    
    def _generate_assessment_questions(self, artifacts: List[KnowledgeArtifact]) -> List[Dict[str, Any]]:
        """Generate assessment questions for artifacts."""
        questions = []
        
        for i, artifact in enumerate(artifacts[:3]):  # Max 3 questions per assessment
            question = {
                "id": f"q_{i+1}",
                "type": "multiple_choice",
                "question": f"Based on '{artifact.title}', what is the key concept?",
                "options": [
                    "Option A - Key concept from content",
                    "Option B - Alternative concept",
                    "Option C - Distractor option",
                    "Option D - Another distractor"
                ],
                "correct_answer": "A",
                "points": 10,
                "artifact_id": artifact.id
            }
            questions.append(question)
        
        return questions


# ============================================================================
# MAIN BRAIN EXTRACTOR
# ============================================================================

class BrainExtractor:
    """
    Main Brain Extractor system integrating RAG, LAG, and DAG for intelligent
    knowledge capture and onboarding automation.
    """
    
    def __init__(self, config_path: Optional[Path] = None):
        self.config = self._load_config(config_path)
        
        # Initialize core engines
        self.rag_engine = AdvancedRAGEngine()
        self.lag_engine = LAGEngine()
        self.dag_orchestrator = DAGOrchestrator(self.config.get("dag", {}))
        
        # Initialize components
        self.extractors: Dict[ExtractionMethod, BaseKnowledgeExtractor] = {}
        self.onboarding_orchestrator = OnboardingOrchestrator(
            self.lag_engine, self.dag_orchestrator
        )
        
        # Data stores
        self.knowledge_sources: Dict[str, KnowledgeSource] = {}
        self.knowledge_artifacts: Dict[str, KnowledgeArtifact] = {}
        self.onboarding_profiles: Dict[str, OnboardingProfile] = {}
        self.onboarding_plans: Dict[str, OnboardingPlan] = {}
        self.extraction_requests: Dict[str, ExtractionRequest] = {}
        
        # Metrics
        self.metrics = defaultdict(float)
        self.start_time = datetime.utcnow()
        
        # Initialize extractors
        self._initialize_extractors()
        
        # Initialize sample data
        self._initialize_sample_data()
        
        logger.info("Brain Extractor initialized successfully")
    
    def _load_config(self, config_path: Optional[Path]) -> Dict[str, Any]:
        """Load Brain Extractor configuration."""
        if config_path and config_path.exists():
            with open(config_path, 'r') as f:
                return json.load(f)
        
        return {
            "extraction": {
                "max_concurrent_extractions": 5,
                "default_timeout_seconds": 300
            },
            "onboarding": {
                "max_artifacts_per_plan": 20,
                "default_plan_duration_days": 30
            },
            "dag": {
                "scheduler": {
                    "scheduling_strategy": "priority",
                    "max_parallel_tasks": 3
                }
            }
        }
    
    def _initialize_extractors(self):
        """Initialize knowledge extractors."""
        self.extractors[ExtractionMethod.DOCUMENT_ANALYSIS] = DocumentAnalysisExtractor(
            "document_analyzer", self.config.get("extraction", {})
        )
        
        self.extractors[ExtractionMethod.INTERVIEW_AUTOMATION] = InterviewAutomationExtractor(
            "interview_automator", self.config.get("extraction", {})
        )
        
        self.extractors[ExtractionMethod.SYSTEM_OBSERVATION] = SystemObservationExtractor(
            "system_observer", self.config.get("extraction", {})
        )
        
        logger.info(f"Initialized {len(self.extractors)} knowledge extractors")
    
    def _initialize_sample_data(self):
        """Initialize sample data for demonstration."""
        
        # Sample knowledge sources
        sources = [
            KnowledgeSource(
                id="company_handbook",
                name="Company Employee Handbook",
                source_type="document",
                location="/docs/handbook.pdf",
                extraction_methods=[ExtractionMethod.DOCUMENT_ANALYSIS],
                knowledge_types=[KnowledgeType.INSTITUTIONAL, KnowledgeType.REGULATORY],
                security_classification=SecurityLevel.INTERNAL
            ),
            KnowledgeSource(
                id="senior_engineer_interview",
                name="Senior Engineer Knowledge Interview",
                source_type="person",
                location="expert_interviews/john_doe",
                extraction_methods=[ExtractionMethod.INTERVIEW_AUTOMATION],
                knowledge_types=[KnowledgeType.EXPERIENTIAL, KnowledgeType.TECHNICAL],
                security_classification=SecurityLevel.INTERNAL
            ),
            KnowledgeSource(
                id="production_system",
                name="Production System Monitoring",
                source_type="system",
                location="monitoring.company.com",
                extraction_methods=[ExtractionMethod.SYSTEM_OBSERVATION],
                knowledge_types=[KnowledgeType.TECHNICAL, KnowledgeType.CONTEXTUAL],
                security_classification=SecurityLevel.CONFIDENTIAL
            )
        ]
        
        for source in sources:
            self.knowledge_sources[source.id] = source
        
        logger.info(f"Initialized {len(sources)} sample knowledge sources")
    
    async def register_knowledge_source(self, source: KnowledgeSource) -> bool:
        """Register a new knowledge source."""
        try:
            # Validate source
            if source.id in self.knowledge_sources:
                logger.warning(f"Knowledge source {source.id} already exists")
                return False
            
            # Validate access to source
            for method in source.extraction_methods:
                if method in self.extractors:
                    extractor = self.extractors[method]
                    if not await extractor.validate_source(source):
                        logger.error(f"Source {source.id} validation failed for method {method}")
                        return False
            
            self.knowledge_sources[source.id] = source
            logger.info(f"Registered knowledge source: {source.id}")
            return True
            
        except Exception as e:
            logger.error(f"Failed to register knowledge source {source.id}: {e}")
            return False
    
    async def extract_knowledge(self, request: ExtractionRequest) -> str:
        """Extract knowledge from specified sources."""
        
        request.status = "in_progress"
        self.extraction_requests[request.id] = request
        
        try:
            all_artifacts = []
            
            # Extract from each source
            for source_id in request.sources:
                if source_id not in self.knowledge_sources:
                    logger.warning(f"Source {source_id} not found")
                    continue
                
                source = self.knowledge_sources[source_id]
                
                # Use appropriate extractors
                for method in request.extraction_methods:
                    if method in source.extraction_methods and method in self.extractors:
                        extractor = self.extractors[method]
                        
                        # Extract knowledge
                        artifacts = await extractor.extract_knowledge(
                            source, 
                            {
                                "knowledge_types": request.knowledge_types,
                                "target_personas": request.target_personas,
                                "max_artifacts": 5
                            }
                        )
                        
                        all_artifacts.extend(artifacts)
            
            # Store artifacts
            for artifact in all_artifacts:
                self.knowledge_artifacts[artifact.id] = artifact
            
            request.status = "completed"
            
            # Update metrics
            self.metrics["total_extractions"] += 1
            self.metrics["total_artifacts_extracted"] += len(all_artifacts)
            
            logger.info(f"Extraction {request.id} completed: {len(all_artifacts)} artifacts extracted")
            return request.id
            
        except Exception as e:
            request.status = "failed"
            logger.error(f"Extraction {request.id} failed: {e}")
            return request.id
    
    async def create_onboarding_profile(self, user_data: Dict[str, Any]) -> OnboardingProfile:
        """Create an onboarding profile for a user."""
        
        profile = OnboardingProfile(
            id=f"profile_{user_data['user_id']}_{int(time.time())}",
            user_id=user_data["user_id"],
            persona=PersonaType(user_data.get("persona", "new_hire")),
            role=user_data.get("role", ""),
            department=user_data.get("department", ""),
            experience_level=user_data.get("experience_level", "beginner"),
            learning_style=user_data.get("learning_style", "visual"),
            knowledge_gaps=user_data.get("knowledge_gaps", []),
            interests=user_data.get("interests", []),
            goals=user_data.get("goals", []),
            preferred_content_types=user_data.get("preferred_content_types", ["text", "video"]),
            time_constraints=user_data.get("time_constraints", {"daily_minutes": 60}),
            security_clearance=SecurityLevel(user_data.get("security_clearance", "internal"))
        )
        
        self.onboarding_profiles[profile.id] = profile
        logger.info(f"Created onboarding profile: {profile.id}")
        
        return profile
    
    async def generate_onboarding_plan(self, profile_id: str, 
                                     knowledge_requirements: List[str] = None) -> Optional[OnboardingPlan]:
        """Generate a personalized onboarding plan."""
        
        if profile_id not in self.onboarding_profiles:
            logger.error(f"Profile {profile_id} not found")
            return None
        
        profile = self.onboarding_profiles[profile_id]
        
        # Find relevant artifacts
        relevant_artifacts = []
        for artifact in self.knowledge_artifacts.values():
            if self.onboarding_orchestrator._is_relevant_for_profile(artifact, profile):
                relevant_artifacts.append(artifact)
        
        # Filter by knowledge requirements if specified
        if knowledge_requirements:
            requirement_tags = {req.lower() for req in knowledge_requirements}
            relevant_artifacts = [
                a for a in relevant_artifacts
                if any(tag in requirement_tags for tag in a.tags)
            ]
        
        # Limit artifacts
        max_artifacts = self.config.get("onboarding", {}).get("max_artifacts_per_plan", 20)
        relevant_artifacts = relevant_artifacts[:max_artifacts]
        
        # Create onboarding plan
        plan = await self.onboarding_orchestrator.create_onboarding_plan(
            profile, relevant_artifacts
        )
        
        self.onboarding_plans[plan.id] = plan
        
        # Update metrics
        self.metrics["total_onboarding_plans"] += 1
        
        logger.info(f"Generated onboarding plan: {plan.id} with {len(plan.artifacts)} artifacts")
        
        return plan
    
    async def execute_onboarding(self, plan_id: str) -> Dict[str, Any]:
        """Execute an onboarding plan."""
        
        if plan_id not in self.onboarding_plans:
            return {"error": f"Plan {plan_id} not found"}
        
        plan = self.onboarding_plans[plan_id]
        
        # Execute using onboarding orchestrator
        result = await self.onboarding_orchestrator.execute_onboarding(plan)
        
        if result.get("run_id"):
            self.metrics["total_onboarding_executions"] += 1
            plan.status = "active"
        
        return result
    
    async def query_knowledge(self, query: str, **kwargs) -> Dict[str, Any]:
        """Query the knowledge base using RAG."""
        
        # Use RAG engine for knowledge querying
        result = await self.rag_engine.query(
            query,
            strategy=kwargs.get("strategy", "agentic_reasoning"),
            max_results=kwargs.get("max_results", 10),
            security_clearance=kwargs.get("security_clearance", "internal"),
            include_citations=kwargs.get("include_citations", True)
        )
        
        # Update metrics
        self.metrics["total_knowledge_queries"] += 1
        
        return result
    
    def get_extraction_status(self, request_id: str) -> Optional[Dict[str, Any]]:
        """Get status of an extraction request."""
        if request_id not in self.extraction_requests:
            return None
        
        request = self.extraction_requests[request_id]
        
        # Count extracted artifacts for this request
        artifacts_count = len([
            a for a in self.knowledge_artifacts.values()
            if any(source_id in request.sources 
                   for source_id in [a.source_id])
        ])
        
        return {
            "request_id": request_id,
            "status": request.status,
            "sources": request.sources,
            "knowledge_types": [kt.value for kt in request.knowledge_types],
            "artifacts_extracted": artifacts_count,
            "created_at": request.created_at.isoformat()
        }
    
    def get_onboarding_status(self, plan_id: str) -> Optional[Dict[str, Any]]:
        """Get status of an onboarding plan."""
        if plan_id not in self.onboarding_plans:
            return None
        
        plan = self.onboarding_plans[plan_id]
        
        # Check if there's an active execution
        execution_status = None
        if plan_id in self.onboarding_orchestrator.active_onboardings:
            active_onboarding = self.onboarding_orchestrator.active_onboardings[plan_id]
            execution_status = {
                "dag_run_id": active_onboarding.get("dag_run_id"),
                "status": active_onboarding.get("status"),
                "start_time": active_onboarding.get("start_time", "").isoformat() if active_onboarding.get("start_time") else None
            }
        
        return {
            "plan_id": plan_id,
            "profile_id": plan.profile_id,
            "status": plan.status,
            "progress": plan.progress,
            "estimated_duration": plan.estimated_duration,
            "artifacts_count": len(plan.artifacts),
            "milestones_count": len(plan.milestones),
            "execution_status": execution_status,
            "created_at": plan.created_at.isoformat(),
            "updated_at": plan.updated_at.isoformat()
        }
    
    def get_system_metrics(self) -> Dict[str, Any]:
        """Get comprehensive system metrics."""
        uptime = (datetime.utcnow() - self.start_time).total_seconds()
        
        return {
            "system": {
                "uptime_seconds": uptime,
                "status": "operational"
            },
            "knowledge_management": {
                "sources": len(self.knowledge_sources),
                "artifacts": len(self.knowledge_artifacts),
                "extraction_requests": len(self.extraction_requests)
            },
            "onboarding": {
                "profiles": len(self.onboarding_profiles),
                "plans": len(self.onboarding_plans),
                "active_onboardings": len(self.onboarding_orchestrator.active_onboardings)
            },
            "operations": dict(self.metrics),
            "engine_status": {
                "rag_engine": self.rag_engine.get_status(),
                "lag_engine": self.lag_engine.get_status(),
                "dag_orchestrator": self.dag_orchestrator.get_orchestrator_metrics()
            }
        }
    
    def list_knowledge_sources(self) -> List[Dict[str, Any]]:
        """List all knowledge sources."""
        return [
            {
                "id": source.id,
                "name": source.name,
                "source_type": source.source_type,
                "knowledge_types": [kt.value for kt in source.knowledge_types],
                "extraction_methods": [em.value for em in source.extraction_methods],
                "security_classification": source.security_classification.value,
                "last_updated": source.last_updated.isoformat()
            }
            for source in self.knowledge_sources.values()
        ]
    
    def list_knowledge_artifacts(self, limit: int = 50) -> List[Dict[str, Any]]:
        """List knowledge artifacts."""
        artifacts = list(self.knowledge_artifacts.values())
        artifacts.sort(key=lambda x: x.created_at, reverse=True)
        
        return [
            {
                "id": artifact.id,
                "title": artifact.title,
                "knowledge_type": artifact.knowledge_type.value,
                "source_id": artifact.source_id,
                "extraction_method": artifact.extraction_method.value,
                "confidence_score": artifact.confidence_score,
                "tags": list(artifact.tags),
                "personas": [p.value for p in artifact.personas],
                "security_level": artifact.security_level.value,
                "created_at": artifact.created_at.isoformat()
            }
            for artifact in artifacts[:limit]
        ]


# ============================================================================
# EXAMPLE USAGE AND DEMONSTRATION
# ============================================================================

async def main():
    """Demonstrate the Brain Extractor system."""
    
    print("🧠 Brain Extractor - Intelligent Knowledge Capture and Onboarding")
    print("=" * 70)
    
    # Initialize Brain Extractor
    brain_extractor = BrainExtractor()
    
    # Show system status
    print("\n📊 System Status:")
    metrics = brain_extractor.get_system_metrics()
    print(f"  Sources: {metrics['knowledge_management']['sources']}")
    print(f"  RAG Engine: {metrics['engine_status']['rag_engine']['status']}")
    print(f"  LAG Engine: {metrics['engine_status']['lag_engine']['status']}")
    print(f"  DAG Orchestrator: Operational")
    
    # List available knowledge sources
    print("\n📚 Available Knowledge Sources:")
    sources = brain_extractor.list_knowledge_sources()
    for source in sources:
        print(f"  • {source['name']} ({source['source_type']})")
        print(f"    Knowledge Types: {', '.join(source['knowledge_types'])}")
        print(f"    Security: {source['security_classification']}")
    
    # Demonstrate knowledge extraction
    print(f"\n🔍 Extracting Knowledge...")
    extraction_request = ExtractionRequest(
        id=f"extraction_{int(time.time())}",
        requester="demo_user",
        sources=["company_handbook", "senior_engineer_interview"],
        knowledge_types=[KnowledgeType.INSTITUTIONAL, KnowledgeType.EXPERIENTIAL],
        extraction_methods=[ExtractionMethod.DOCUMENT_ANALYSIS, ExtractionMethod.INTERVIEW_AUTOMATION],
        target_personas=[PersonaType.NEW_HIRE, PersonaType.TECHNICAL_EXPERT]
    )
    
    extraction_id = await brain_extractor.extract_knowledge(extraction_request)
    print(f"  Extraction started: {extraction_id}")
    
    # Wait for extraction to complete
    await asyncio.sleep(2)
    
    extraction_status = brain_extractor.get_extraction_status(extraction_id)
    if extraction_status:
        print(f"  Status: {extraction_status['status']}")
        print(f"  Artifacts extracted: {extraction_status['artifacts_extracted']}")
    
    # List extracted artifacts
    print(f"\n📄 Extracted Knowledge Artifacts:")
    artifacts = brain_extractor.list_knowledge_artifacts(limit=5)
    for artifact in artifacts:
        print(f"  • {artifact['title']}")
        print(f"    Type: {artifact['knowledge_type']}")
        print(f"    Confidence: {artifact['confidence_score']:.2f}")
        print(f"    Personas: {', '.join(artifact['personas'])}")
    
    # Create user profile for onboarding
    print(f"\n👤 Creating User Profile...")
    user_data = {
        "user_id": "john_smith_001",
        "persona": "new_hire",
        "role": "Software Engineer",
        "department": "Engineering",
        "experience_level": "intermediate",
        "learning_style": "visual",
        "knowledge_gaps": ["company_processes", "technical_standards"],
        "interests": ["software_development", "best_practices"],
        "goals": ["understand_company_culture", "learn_development_workflow"],
        "security_clearance": "internal"
    }
    
    profile = await brain_extractor.create_onboarding_profile(user_data)
    print(f"  Profile created: {profile.id}")
    print(f"  Persona: {profile.persona.value}")
    print(f"  Experience: {profile.experience_level}")
    print(f"  Learning style: {profile.learning_style}")
    
    # Generate personalized onboarding plan
    print(f"\n📋 Generating Onboarding Plan...")
    plan = await brain_extractor.generate_onboarding_plan(
        profile.id,
        knowledge_requirements=["company_processes", "technical_standards"]
    )
    
    if plan:
        print(f"  Plan created: {plan.id}")
        print(f"  Duration: {plan.estimated_duration} minutes")
        print(f"  Artifacts: {len(plan.artifacts)}")
        print(f"  Milestones: {len(plan.milestones)}")
        print(f"  Learning path steps: {len(plan.learning_path)}")
        
        # Show first few learning path steps
        print(f"\n📚 Learning Path Preview:")
        for i, step in enumerate(plan.learning_path[:3]):
            print(f"  {i+1}. {step['title']}")
            print(f"     Time: {step['estimated_time_minutes']} min")
            print(f"     Difficulty: {step['difficulty']}")
        
        if len(plan.learning_path) > 3:
            print(f"  ... and {len(plan.learning_path) - 3} more steps")
    
    # Execute onboarding plan
    print(f"\n🚀 Executing Onboarding Plan...")
    if plan:
        execution_result = await brain_extractor.execute_onboarding(plan.id)
        
        if execution_result.get("run_id"):
            print(f"  Execution started: {execution_result['run_id']}")
            print(f"  Status: {execution_result['status']}")
            
            # Wait a bit and check status
            await asyncio.sleep(3)
            
            onboarding_status = brain_extractor.get_onboarding_status(plan.id)
            if onboarding_status:
                print(f"  Plan status: {onboarding_status['status']}")
                print(f"  Progress: {onboarding_status['progress']:.1%}")
                
                if onboarding_status['execution_status']:
                    exec_status = onboarding_status['execution_status']
                    print(f"  Execution status: {exec_status['status']}")
    
    # Demonstrate knowledge querying with RAG
    print(f"\n🔍 Querying Knowledge Base...")
    knowledge_queries = [
        "What are the company's core values and culture?",
        "How do I set up my development environment?",
        "What are the key processes for new employees?"
    ]
    
    for query in knowledge_queries[:2]:  # Demo first 2 queries
        print(f"\n  Query: {query}")
        
        result = await brain_extractor.query_knowledge(
            query,
            strategy="agentic_reasoning",
            max_results=5,
            include_citations=True
        )
        
        print(f"  Confidence: {result['confidence_score']:.2f}")
        print(f"  Response: {result['response'][:150]}...")
        print(f"  Sources: {', '.join(result['retrieval_metadata']['sources_accessed'])}")
    
    # Show final system metrics
    print(f"\n📊 Final System Metrics:")
    final_metrics = brain_extractor.get_system_metrics()
    operations = final_metrics['operations']
    
    print(f"  Total extractions: {int(operations.get('total_extractions', 0))}")
    print(f"  Total artifacts: {int(operations.get('total_artifacts_extracted', 0))}")
    print(f"  Onboarding plans: {int(operations.get('total_onboarding_plans', 0))}")
    print(f"  Onboarding executions: {int(operations.get('total_onboarding_executions', 0))}")
    print(f"  Knowledge queries: {int(operations.get('total_knowledge_queries', 0))}")
    
    print(f"\n✅ Brain Extractor demonstration completed!")
    print(f"\nKey Features Demonstrated:")
    print(f"  ✓ Multi-source knowledge extraction (RAG)")
    print(f"  ✓ Adaptive workflow automation (LAG)")
    print(f"  ✓ Complex onboarding orchestration (DAG)")
    print(f"  ✓ Personalized learning paths")
    print(f"  ✓ Intelligent knowledge querying")
    print(f"  ✓ Enterprise security and compliance")


if __name__ == "__main__":
    asyncio.run(main())
