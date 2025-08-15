"""
GENESIS Orchestrator - AI-Driven Optimization Recommendation Engine
Meta-learning system for automated performance optimization suggestions
"""

import os
import json
import time
import pickle
import hashlib
from typing import Dict, Any, List, Optional, Tuple, Set, Union
from dataclasses import dataclass, asdict, field
from datetime import datetime, timedelta
from collections import defaultdict, Counter
from enum import Enum
import logging
from pathlib import Path

import numpy as np
from scipy import stats
from sklearn.ensemble import RandomForestRegressor, GradientBoostingClassifier
from sklearn.cluster import DBSCAN, KMeans
from sklearn.preprocessing import StandardScaler, LabelEncoder
from sklearn.model_selection import cross_val_score, TimeSeriesSplit
from sklearn.metrics import mean_squared_error, classification_report
from sklearn.feature_selection import SelectKBest, f_regression
import joblib

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

class OptimizationType(Enum):
    """Types of optimization recommendations"""
    ALGORITHM_OPTIMIZATION = "algorithm_optimization"
    RESOURCE_SCALING = "resource_scaling"
    CONFIGURATION_TUNING = "configuration_tuning"
    ARCHITECTURE_IMPROVEMENT = "architecture_improvement"
    CACHING_STRATEGY = "caching_strategy"
    DATABASE_OPTIMIZATION = "database_optimization"
    CONCURRENCY_TUNING = "concurrency_tuning"
    MEMORY_MANAGEMENT = "memory_management"

class OptimizationPriority(Enum):
    """Priority levels for optimization recommendations"""
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"
    CRITICAL = "critical"

@dataclass
class PerformanceFeature:
    """Feature extracted from performance data"""
    feature_name: str
    value: float
    timestamp: float
    context: Dict[str, Any] = field(default_factory=dict)
    normalized_value: Optional[float] = None

@dataclass
class OptimizationRecommendation:
    """AI-generated optimization recommendation"""
    recommendation_id: str
    optimization_type: OptimizationType
    priority: OptimizationPriority
    confidence: float
    estimated_impact: float  # Expected performance improvement (%)
    title: str
    description: str
    technical_details: List[str]
    implementation_steps: List[str]
    risk_assessment: str
    effort_estimate: str  # "low", "medium", "high"
    prerequisites: List[str] = field(default_factory=list)
    metrics_to_monitor: List[str] = field(default_factory=list)
    evidence: Dict[str, Any] = field(default_factory=dict)
    similar_cases: List[str] = field(default_factory=list)
    generated_at: float = field(default_factory=time.time)

@dataclass
class OptimizationOutcome:
    """Outcome of an implemented optimization"""
    recommendation_id: str
    implemented_at: float
    before_metrics: Dict[str, float]
    after_metrics: Dict[str, float]
    actual_impact: float
    success: bool
    notes: str
    lessons_learned: List[str] = field(default_factory=list)

@dataclass
class PerformancePattern:
    """Learned performance pattern"""
    pattern_id: str
    pattern_type: str
    features: Dict[str, Tuple[float, float]]  # feature -> (min, max) range
    typical_performance: Dict[str, float]
    optimization_history: List[str]
    effectiveness_score: float
    occurrence_count: int
    last_seen: float

class FeatureExtractor:
    """Extract meaningful features from performance data"""
    
    def __init__(self):
        self.feature_history = defaultdict(list)
        self.scaler = StandardScaler()
    
    def extract_features(self, performance_data: Dict[str, Any], 
                        system_state: Dict[str, Any],
                        time_window_hours: int = 24) -> List[PerformanceFeature]:
        """Extract features from raw performance data"""
        
        features = []
        timestamp = time.time()
        
        # Basic performance metrics
        if 'duration_ms' in performance_data:
            features.append(PerformanceFeature(
                'response_time', performance_data['duration_ms'], timestamp
            ))
        
        if 'cpu_utilization' in system_state:
            features.append(PerformanceFeature(
                'cpu_utilization', system_state['cpu_utilization'], timestamp
            ))
        
        if 'memory_usage_percent' in system_state:
            features.append(PerformanceFeature(
                'memory_usage', system_state['memory_usage_percent'], timestamp
            ))
        
        # Derived features
        if 'operations_per_second' in performance_data:
            ops_per_sec = performance_data['operations_per_second']
            features.append(PerformanceFeature(
                'throughput', ops_per_sec, timestamp
            ))
            
            # Efficiency metric (operations per CPU unit)
            if 'cpu_utilization' in system_state and system_state['cpu_utilization'] > 0:
                efficiency = ops_per_sec / system_state['cpu_utilization']
                features.append(PerformanceFeature(
                    'cpu_efficiency', efficiency, timestamp
                ))
        
        # Queue and concurrency features
        if 'active_threads' in system_state:
            features.append(PerformanceFeature(
                'thread_count', system_state['active_threads'], timestamp
            ))
        
        # I/O features
        if 'disk_io_read_mb_s' in system_state and 'disk_io_write_mb_s' in system_state:
            total_io = system_state['disk_io_read_mb_s'] + system_state['disk_io_write_mb_s']
            features.append(PerformanceFeature(
                'io_intensity', total_io, timestamp
            ))
        
        # Network features
        if 'network_bytes_sent_s' in system_state and 'network_bytes_recv_s' in system_state:
            total_network = system_state['network_bytes_sent_s'] + system_state['network_bytes_recv_s']
            features.append(PerformanceFeature(
                'network_intensity', total_network / (1024 * 1024), timestamp  # MB/s
            ))
        
        # Time-based features
        hour_of_day = datetime.fromtimestamp(timestamp).hour
        features.append(PerformanceFeature(
            'hour_of_day', hour_of_day, timestamp
        ))
        
        day_of_week = datetime.fromtimestamp(timestamp).weekday()
        features.append(PerformanceFeature(
            'day_of_week', day_of_week, timestamp
        ))
        
        # Historical trend features
        for feature_name in ['response_time', 'cpu_utilization', 'memory_usage']:
            historical_values = [
                f.value for f in self.feature_history[feature_name]
                if f.timestamp > timestamp - (time_window_hours * 3600)
            ]
            
            if len(historical_values) >= 10:
                # Trend features
                recent_values = historical_values[-10:]
                older_values = historical_values[:-10] if len(historical_values) > 10 else historical_values[:5]
                
                if older_values:
                    trend = (np.mean(recent_values) - np.mean(older_values)) / np.mean(older_values)
                    features.append(PerformanceFeature(
                        f'{feature_name}_trend', trend, timestamp
                    ))
                
                # Volatility features
                volatility = np.std(recent_values) / np.mean(recent_values) if np.mean(recent_values) > 0 else 0
                features.append(PerformanceFeature(
                    f'{feature_name}_volatility', volatility, timestamp
                ))
        
        # Store features for future trend analysis
        for feature in features:
            self.feature_history[feature.feature_name].append(feature)
            
            # Keep only recent history (memory management)
            cutoff_time = timestamp - (7 * 24 * 3600)  # 7 days
            self.feature_history[feature.feature_name] = [
                f for f in self.feature_history[feature.feature_name]
                if f.timestamp > cutoff_time
            ]
        
        return features
    
    def normalize_features(self, features: List[PerformanceFeature]) -> List[PerformanceFeature]:
        """Normalize feature values"""
        feature_values = np.array([f.value for f in features]).reshape(-1, 1)
        
        try:
            normalized_values = self.scaler.fit_transform(feature_values).flatten()
            
            for i, feature in enumerate(features):
                feature.normalized_value = normalized_values[i]
                
        except Exception as e:
            logger.warning(f"Feature normalization failed: {e}")
        
        return features

class MetaLearningEngine:
    """Meta-learning engine for optimization recommendation"""
    
    def __init__(self, model_dir: str = "orchestrator_runs/models"):
        self.model_dir = Path(model_dir)
        self.model_dir.mkdir(parents=True, exist_ok=True)
        
        # ML models
        self.performance_predictor = RandomForestRegressor(
            n_estimators=100, random_state=42, n_jobs=-1
        )
        self.bottleneck_classifier = GradientBoostingClassifier(
            n_estimators=100, random_state=42
        )
        self.optimization_recommender = RandomForestRegressor(
            n_estimators=50, random_state=42
        )
        
        # Feature processing
        self.feature_selector = SelectKBest(f_regression, k=20)
        self.label_encoder = LabelEncoder()
        self.scaler = StandardScaler()
        
        # Training data
        self.training_features: List[List[float]] = []
        self.training_targets: List[float] = []
        self.training_labels: List[str] = []
        self.optimization_outcomes: List[OptimizationOutcome] = []
        
        # Pattern learning
        self.learned_patterns: Dict[str, PerformancePattern] = {}
        
        # Load existing models
        self._load_models()
        
        logger.info("Meta-learning engine initialized")
    
    def add_training_data(self, features: List[PerformanceFeature], 
                         performance_target: float,
                         bottleneck_type: Optional[str] = None):
        """Add training data to the meta-learning engine"""
        
        feature_vector = [f.normalized_value or f.value for f in features]
        
        self.training_features.append(feature_vector)
        self.training_targets.append(performance_target)
        
        if bottleneck_type:
            self.training_labels.append(bottleneck_type)
    
    def train_models(self, min_samples: int = 100):
        """Train the meta-learning models"""
        
        if len(self.training_features) < min_samples:
            logger.warning(f"Insufficient training data: {len(self.training_features)} < {min_samples}")
            return False
        
        try:
            X = np.array(self.training_features)
            y_performance = np.array(self.training_targets)
            
            # Feature selection and scaling
            X_scaled = self.scaler.fit_transform(X)
            X_selected = self.feature_selector.fit_transform(X_scaled, y_performance)
            
            # Train performance predictor
            logger.info("Training performance predictor...")
            cv_scores = cross_val_score(
                self.performance_predictor, X_selected, y_performance, 
                cv=TimeSeriesSplit(n_splits=5), scoring='neg_mean_squared_error'
            )
            logger.info(f"Performance predictor CV RMSE: {np.sqrt(-cv_scores.mean()):.3f}")
            
            self.performance_predictor.fit(X_selected, y_performance)
            
            # Train bottleneck classifier if we have labels
            if self.training_labels and len(set(self.training_labels)) > 1:
                logger.info("Training bottleneck classifier...")
                y_bottleneck = self.label_encoder.fit_transform(self.training_labels)
                
                self.bottleneck_classifier.fit(X_selected, y_bottleneck)
                
                # Get feature importance
                feature_importance = self.bottleneck_classifier.feature_importances_
                logger.info(f"Top bottleneck features: {np.argsort(feature_importance)[-5:]}")
            
            # Save models
            self._save_models()
            
            logger.info("Model training completed successfully")
            return True
            
        except Exception as e:
            logger.error(f"Model training failed: {e}")
            return False
    
    def predict_performance_impact(self, features: List[PerformanceFeature], 
                                 optimization_type: OptimizationType) -> float:
        """Predict the performance impact of an optimization"""
        
        try:
            feature_vector = np.array([[f.normalized_value or f.value for f in features]])
            feature_vector_scaled = self.scaler.transform(feature_vector)
            feature_vector_selected = self.feature_selector.transform(feature_vector_scaled)
            
            # Base prediction
            baseline_performance = self.performance_predictor.predict(feature_vector_selected)[0]
            
            # Apply optimization impact based on historical data
            impact_multiplier = self._get_optimization_impact_multiplier(optimization_type)
            expected_improvement = baseline_performance * impact_multiplier
            
            return min(50.0, max(1.0, expected_improvement))  # Cap between 1-50%
            
        except Exception as e:
            logger.error(f"Performance impact prediction failed: {e}")
            return 5.0  # Default conservative estimate
    
    def _get_optimization_impact_multiplier(self, optimization_type: OptimizationType) -> float:
        """Get expected impact multiplier for optimization type"""
        
        # Historical effectiveness of different optimization types
        impact_multipliers = {
            OptimizationType.ALGORITHM_OPTIMIZATION: 0.25,  # 25% average improvement
            OptimizationType.CACHING_STRATEGY: 0.30,
            OptimizationType.DATABASE_OPTIMIZATION: 0.20,
            OptimizationType.CONCURRENCY_TUNING: 0.15,
            OptimizationType.RESOURCE_SCALING: 0.10,
            OptimizationType.CONFIGURATION_TUNING: 0.08,
            OptimizationType.MEMORY_MANAGEMENT: 0.12,
            OptimizationType.ARCHITECTURE_IMPROVEMENT: 0.35
        }
        
        return impact_multipliers.get(optimization_type, 0.10)
    
    def learn_from_outcome(self, outcome: OptimizationOutcome):
        """Learn from optimization implementation outcome"""
        
        self.optimization_outcomes.append(outcome)
        
        # Update effectiveness scores for similar patterns
        recommendation_type = self._get_recommendation_type(outcome.recommendation_id)
        if recommendation_type:
            # Find similar patterns and update their effectiveness
            for pattern in self.learned_patterns.values():
                if recommendation_type.value in pattern.optimization_history:
                    # Update effectiveness based on actual vs expected impact
                    if outcome.success and outcome.actual_impact > 0:
                        pattern.effectiveness_score = min(1.0, pattern.effectiveness_score + 0.1)
                    else:
                        pattern.effectiveness_score = max(0.0, pattern.effectiveness_score - 0.1)
        
        logger.info(f"Learned from optimization outcome: {outcome.actual_impact:.1f}% impact")
    
    def _get_recommendation_type(self, recommendation_id: str) -> Optional[OptimizationType]:
        """Extract optimization type from recommendation ID"""
        # This would normally look up the recommendation type from storage
        # For now, infer from the ID pattern
        for opt_type in OptimizationType:
            if opt_type.value in recommendation_id.lower():
                return opt_type
        return None
    
    def discover_patterns(self, features_history: List[List[PerformanceFeature]],
                         performance_history: List[float]) -> List[PerformancePattern]:
        """Discover performance patterns using clustering"""
        
        if len(features_history) < 50:
            return []
        
        try:
            # Prepare feature matrix
            feature_matrix = []
            for features in features_history:
                feature_vector = [f.normalized_value or f.value for f in features]
                if len(feature_vector) == len(features_history[0]):  # Consistent feature count
                    feature_matrix.append(feature_vector)
            
            if len(feature_matrix) < 20:
                return []
            
            X = np.array(feature_matrix)
            X_scaled = self.scaler.fit_transform(X)
            
            # Clustering to find patterns
            clusterer = DBSCAN(eps=0.5, min_samples=5)
            cluster_labels = clusterer.fit_predict(X_scaled)
            
            patterns = []
            for cluster_id in set(cluster_labels):
                if cluster_id == -1:  # Noise cluster
                    continue
                
                cluster_indices = np.where(cluster_labels == cluster_id)[0]
                cluster_features = X[cluster_indices]
                cluster_performance = [performance_history[i] for i in cluster_indices]
                
                # Calculate pattern characteristics
                feature_ranges = {}
                for i, feature_name in enumerate(['response_time', 'cpu_utilization', 'memory_usage']):  # Sample features
                    if i < cluster_features.shape[1]:
                        feature_values = cluster_features[:, i]
                        feature_ranges[feature_name] = (
                            float(np.min(feature_values)),
                            float(np.max(feature_values))
                        )
                
                pattern = PerformancePattern(
                    pattern_id=f"pattern_{cluster_id}_{int(time.time())}",
                    pattern_type=self._classify_pattern_type(feature_ranges, cluster_performance),
                    features=feature_ranges,
                    typical_performance={
                        'avg_performance': float(np.mean(cluster_performance)),
                        'std_performance': float(np.std(cluster_performance))
                    },
                    optimization_history=[],
                    effectiveness_score=0.5,
                    occurrence_count=len(cluster_indices),
                    last_seen=time.time()
                )
                
                patterns.append(pattern)
                self.learned_patterns[pattern.pattern_id] = pattern
            
            logger.info(f"Discovered {len(patterns)} performance patterns")
            return patterns
            
        except Exception as e:
            logger.error(f"Pattern discovery failed: {e}")
            return []
    
    def _classify_pattern_type(self, feature_ranges: Dict[str, Tuple[float, float]], 
                              performance_values: List[float]) -> str:
        """Classify pattern type based on feature characteristics"""
        
        avg_performance = np.mean(performance_values)
        
        # Simple heuristic classification
        if 'cpu_utilization' in feature_ranges:
            cpu_min, cpu_max = feature_ranges['cpu_utilization']
            if cpu_max > 80:
                return "cpu_intensive"
        
        if 'memory_usage' in feature_ranges:
            mem_min, mem_max = feature_ranges['memory_usage']
            if mem_max > 85:
                return "memory_intensive"
        
        if avg_performance > 2000:  # High response time
            return "slow_performance"
        elif avg_performance < 100:
            return "fast_performance"
        else:
            return "normal_performance"
    
    def _save_models(self):
        """Save trained models to disk"""
        try:
            model_files = {
                'performance_predictor.pkl': self.performance_predictor,
                'bottleneck_classifier.pkl': self.bottleneck_classifier,
                'feature_selector.pkl': self.feature_selector,
                'scaler.pkl': self.scaler,
                'label_encoder.pkl': self.label_encoder
            }
            
            for filename, model in model_files.items():
                filepath = self.model_dir / filename
                joblib.dump(model, filepath)
            
            # Save patterns
            patterns_file = self.model_dir / 'learned_patterns.json'
            with open(patterns_file, 'w') as f:
                json.dump(
                    {k: asdict(v) for k, v in self.learned_patterns.items()},
                    f, indent=2, default=str
                )
            
            logger.info("Models saved successfully")
            
        except Exception as e:
            logger.error(f"Model saving failed: {e}")
    
    def _load_models(self):
        """Load trained models from disk"""
        try:
            model_files = {
                'performance_predictor.pkl': 'performance_predictor',
                'bottleneck_classifier.pkl': 'bottleneck_classifier',
                'feature_selector.pkl': 'feature_selector',
                'scaler.pkl': 'scaler',
                'label_encoder.pkl': 'label_encoder'
            }
            
            for filename, attr_name in model_files.items():
                filepath = self.model_dir / filename
                if filepath.exists():
                    model = joblib.load(filepath)
                    setattr(self, attr_name, model)
            
            # Load patterns
            patterns_file = self.model_dir / 'learned_patterns.json'
            if patterns_file.exists():
                with open(patterns_file, 'r') as f:
                    patterns_data = json.load(f)
                
                for pattern_id, pattern_dict in patterns_data.items():
                    # Convert back to enum
                    pattern = PerformancePattern(**pattern_dict)
                    self.learned_patterns[pattern_id] = pattern
            
            logger.info("Models loaded successfully")
            
        except Exception as e:
            logger.warning(f"Model loading failed: {e}")

class OptimizationRecommendationEngine:
    """Main AI-driven optimization recommendation engine"""
    
    def __init__(self, output_dir: str = "orchestrator_runs/optimization"):
        self.output_dir = Path(output_dir)
        self.output_dir.mkdir(parents=True, exist_ok=True)
        
        # Core components
        self.feature_extractor = FeatureExtractor()
        self.meta_learner = MetaLearningEngine()
        
        # Recommendation storage
        self.recommendations: Dict[str, OptimizationRecommendation] = {}
        self.recommendation_history: List[OptimizationRecommendation] = []
        
        # Knowledge base of optimization strategies
        self.optimization_strategies = self._initialize_optimization_strategies()
        
        logger.info("AI Optimization Engine initialized")
    
    def generate_recommendations(self, performance_data: Dict[str, Any],
                               system_state: Dict[str, Any],
                               bottleneck_events: List[Dict[str, Any]] = None) -> List[OptimizationRecommendation]:
        """Generate optimization recommendations based on current system state"""
        
        # Extract features
        features = self.feature_extractor.extract_features(performance_data, system_state)
        normalized_features = self.feature_extractor.normalize_features(features)
        
        recommendations = []
        
        # Generate recommendations based on bottlenecks
        if bottleneck_events:
            for bottleneck in bottleneck_events:
                bottleneck_recommendations = self._generate_bottleneck_recommendations(
                    bottleneck, normalized_features
                )
                recommendations.extend(bottleneck_recommendations)
        
        # Generate proactive recommendations based on patterns
        pattern_recommendations = self._generate_pattern_recommendations(normalized_features)
        recommendations.extend(pattern_recommendations)
        
        # Generate general optimization recommendations
        general_recommendations = self._generate_general_recommendations(
            normalized_features, performance_data, system_state
        )
        recommendations.extend(general_recommendations)
        
        # Rank and filter recommendations
        ranked_recommendations = self._rank_recommendations(recommendations)
        
        # Store recommendations
        for rec in ranked_recommendations:
            self.recommendations[rec.recommendation_id] = rec
            self.recommendation_history.append(rec)
        
        logger.info(f"Generated {len(ranked_recommendations)} optimization recommendations")
        return ranked_recommendations
    
    def _generate_bottleneck_recommendations(self, bottleneck: Dict[str, Any],
                                           features: List[PerformanceFeature]) -> List[OptimizationRecommendation]:
        """Generate recommendations for specific bottlenecks"""
        
        recommendations = []
        bottleneck_type = bottleneck.get('bottleneck_type', '').lower()
        
        if 'cpu' in bottleneck_type:
            recommendations.extend(self._generate_cpu_optimization_recommendations(bottleneck, features))
        
        if 'memory' in bottleneck_type:
            recommendations.extend(self._generate_memory_optimization_recommendations(bottleneck, features))
        
        if 'io' in bottleneck_type:
            recommendations.extend(self._generate_io_optimization_recommendations(bottleneck, features))
        
        if 'queue' in bottleneck_type or 'backlog' in bottleneck_type:
            recommendations.extend(self._generate_concurrency_optimization_recommendations(bottleneck, features))
        
        return recommendations
    
    def _generate_cpu_optimization_recommendations(self, bottleneck: Dict[str, Any],
                                                 features: List[PerformanceFeature]) -> List[OptimizationRecommendation]:
        """Generate CPU optimization recommendations"""
        
        recommendations = []
        
        # Algorithm optimization recommendation
        cpu_utilization = bottleneck.get('evidence', {}).get('cpu_utilization', 0)
        
        if cpu_utilization > 90:
            priority = OptimizationPriority.CRITICAL
        elif cpu_utilization > 75:
            priority = OptimizationPriority.HIGH
        else:
            priority = OptimizationPriority.MEDIUM
        
        estimated_impact = self.meta_learner.predict_performance_impact(
            features, OptimizationType.ALGORITHM_OPTIMIZATION
        )
        
        rec = OptimizationRecommendation(
            recommendation_id=f"cpu_algo_opt_{int(time.time())}",
            optimization_type=OptimizationType.ALGORITHM_OPTIMIZATION,
            priority=priority,
            confidence=0.8,
            estimated_impact=estimated_impact,
            title="Optimize CPU-Intensive Algorithms",
            description=f"High CPU utilization detected ({cpu_utilization:.1f}%). Algorithm optimization can reduce computational complexity.",
            technical_details=[
                "Profile application hotspots using CPU profiler",
                "Identify O(n²) or higher complexity algorithms",
                "Consider algorithmic improvements or approximations",
                "Implement result caching for expensive computations"
            ],
            implementation_steps=[
                "1. Run CPU profiler to identify hotspot functions",
                "2. Analyze algorithmic complexity of top CPU consumers",
                "3. Research and implement more efficient algorithms",
                "4. Add caching layer for frequently computed results",
                "5. Benchmark performance improvements"
            ],
            risk_assessment="Low - Algorithm improvements typically have minimal risk",
            effort_estimate="medium",
            prerequisites=["CPU profiling tools", "Performance baseline"],
            metrics_to_monitor=["cpu_utilization", "response_time", "throughput"],
            evidence={"cpu_utilization": cpu_utilization}
        )
        recommendations.append(rec)
        
        # Concurrency optimization if high context switching
        context_switches = bottleneck.get('evidence', {}).get('context_switches_per_second', 0)
        if context_switches > 5000:
            rec = OptimizationRecommendation(
                recommendation_id=f"cpu_concurrency_opt_{int(time.time())}",
                optimization_type=OptimizationType.CONCURRENCY_TUNING,
                priority=OptimizationPriority.HIGH,
                confidence=0.7,
                estimated_impact=self.meta_learner.predict_performance_impact(
                    features, OptimizationType.CONCURRENCY_TUNING
                ),
                title="Optimize Thread Management and Concurrency",
                description=f"High context switching detected ({context_switches:.0f}/sec). Thread contention may be reducing CPU efficiency.",
                technical_details=[
                    "Reduce thread pool sizes to match CPU cores",
                    "Implement lock-free data structures where possible",
                    "Use async/await patterns to reduce thread blocking",
                    "Consider work-stealing thread pools"
                ],
                implementation_steps=[
                    "1. Analyze thread dump for contention points",
                    "2. Reduce thread pool sizes to 2x CPU cores",
                    "3. Replace synchronized blocks with concurrent collections",
                    "4. Implement async processing for I/O operations"
                ],
                risk_assessment="Medium - Changes to concurrency can introduce subtle bugs",
                effort_estimate="high",
                evidence={"context_switches_per_second": context_switches}
            )
            recommendations.append(rec)
        
        return recommendations
    
    def _generate_memory_optimization_recommendations(self, bottleneck: Dict[str, Any],
                                                    features: List[PerformanceFeature]) -> List[OptimizationRecommendation]:
        """Generate memory optimization recommendations"""
        
        recommendations = []
        memory_usage = bottleneck.get('evidence', {}).get('memory_usage_percent', 0)
        
        if memory_usage > 90:
            priority = OptimizationPriority.CRITICAL
            effort = "high"
        elif memory_usage > 80:
            priority = OptimizationPriority.HIGH
            effort = "medium"
        else:
            priority = OptimizationPriority.MEDIUM
            effort = "low"
        
        rec = OptimizationRecommendation(
            recommendation_id=f"memory_opt_{int(time.time())}",
            optimization_type=OptimizationType.MEMORY_MANAGEMENT,
            priority=priority,
            confidence=0.8,
            estimated_impact=self.meta_learner.predict_performance_impact(
                features, OptimizationType.MEMORY_MANAGEMENT
            ),
            title="Optimize Memory Usage and Garbage Collection",
            description=f"High memory utilization detected ({memory_usage:.1f}%). Memory optimization can improve performance and stability.",
            technical_details=[
                "Implement object pooling for frequently allocated objects",
                "Optimize data structures to reduce memory overhead",
                "Tune garbage collection parameters",
                "Add memory leak detection and monitoring"
            ],
            implementation_steps=[
                "1. Analyze memory dumps for large object retention",
                "2. Implement object pooling for hot paths",
                "3. Optimize data serialization formats",
                "4. Tune GC settings for workload characteristics",
                "5. Add memory monitoring and alerting"
            ],
            risk_assessment="Low - Memory optimizations typically improve stability",
            effort_estimate=effort,
            evidence={"memory_usage_percent": memory_usage}
        )
        recommendations.append(rec)
        
        return recommendations
    
    def _generate_io_optimization_recommendations(self, bottleneck: Dict[str, Any],
                                                features: List[PerformanceFeature]) -> List[OptimizationRecommendation]:
        """Generate I/O optimization recommendations"""
        
        recommendations = []
        
        rec = OptimizationRecommendation(
            recommendation_id=f"io_opt_{int(time.time())}",
            optimization_type=OptimizationType.DATABASE_OPTIMIZATION,
            priority=OptimizationPriority.HIGH,
            confidence=0.7,
            estimated_impact=self.meta_learner.predict_performance_impact(
                features, OptimizationType.DATABASE_OPTIMIZATION
            ),
            title="Optimize Database and I/O Operations",
            description="High I/O activity detected. Database and file I/O optimization can significantly improve performance.",
            technical_details=[
                "Optimize database queries and add proper indexing",
                "Implement connection pooling and query caching",
                "Use batch operations to reduce I/O overhead",
                "Consider read replicas for read-heavy workloads"
            ],
            implementation_steps=[
                "1. Analyze slow query logs and execution plans",
                "2. Add missing database indexes",
                "3. Implement query result caching",
                "4. Optimize batch sizes for bulk operations",
                "5. Consider database scaling strategies"
            ],
            risk_assessment="Medium - Database changes require careful testing",
            effort_estimate="medium",
            evidence=bottleneck.get('evidence', {})
        )
        recommendations.append(rec)
        
        return recommendations
    
    def _generate_concurrency_optimization_recommendations(self, bottleneck: Dict[str, Any],
                                                         features: List[PerformanceFeature]) -> List[OptimizationRecommendation]:
        """Generate concurrency optimization recommendations"""
        
        recommendations = []
        
        rec = OptimizationRecommendation(
            recommendation_id=f"concurrency_opt_{int(time.time())}",
            optimization_type=OptimizationType.CONCURRENCY_TUNING,
            priority=OptimizationPriority.HIGH,
            confidence=0.7,
            estimated_impact=self.meta_learner.predict_performance_impact(
                features, OptimizationType.CONCURRENCY_TUNING
            ),
            title="Optimize Queue Processing and Concurrency",
            description="Queue backlog detected. Concurrency optimization can improve throughput and reduce latency.",
            technical_details=[
                "Scale queue processing workers based on load",
                "Implement priority queues for critical operations",
                "Use async processing to improve throughput",
                "Add circuit breakers for external dependencies"
            ],
            implementation_steps=[
                "1. Implement auto-scaling for queue workers",
                "2. Add priority queues for different operation types",
                "3. Convert blocking operations to async",
                "4. Implement backpressure handling",
                "5. Add monitoring for queue metrics"
            ],
            risk_assessment="Medium - Concurrency changes require thorough testing",
            effort_estimate="high",
            evidence=bottleneck.get('evidence', {})
        )
        recommendations.append(rec)
        
        return recommendations
    
    def _generate_pattern_recommendations(self, features: List[PerformanceFeature]) -> List[OptimizationRecommendation]:
        """Generate recommendations based on learned patterns"""
        
        recommendations = []
        
        # Match current features against learned patterns
        current_feature_dict = {f.feature_name: f.value for f in features}
        
        for pattern in self.meta_learner.learned_patterns.values():
            if self._matches_pattern(current_feature_dict, pattern):
                # Generate recommendation based on pattern history
                if pattern.effectiveness_score > 0.7:
                    rec = self._create_pattern_recommendation(pattern, features)
                    if rec:
                        recommendations.append(rec)
        
        return recommendations
    
    def _matches_pattern(self, current_features: Dict[str, float],
                        pattern: PerformancePattern) -> bool:
        """Check if current features match a learned pattern"""
        
        matches = 0
        total_features = 0
        
        for feature_name, (min_val, max_val) in pattern.features.items():
            if feature_name in current_features:
                total_features += 1
                current_value = current_features[feature_name]
                if min_val <= current_value <= max_val:
                    matches += 1
        
        return total_features > 0 and matches / total_features >= 0.7
    
    def _create_pattern_recommendation(self, pattern: PerformancePattern,
                                     features: List[PerformanceFeature]) -> Optional[OptimizationRecommendation]:
        """Create recommendation based on successful pattern"""
        
        if not pattern.optimization_history:
            return None
        
        # Use most successful optimization from pattern history
        most_common_opt = Counter(pattern.optimization_history).most_common(1)[0][0]
        
        try:
            opt_type = OptimizationType(most_common_opt)
        except ValueError:
            return None
        
        return OptimizationRecommendation(
            recommendation_id=f"pattern_{pattern.pattern_id}_{int(time.time())}",
            optimization_type=opt_type,
            priority=OptimizationPriority.MEDIUM,
            confidence=pattern.effectiveness_score,
            estimated_impact=15.0,  # Conservative estimate for pattern-based
            title=f"Apply Proven {opt_type.value.replace('_', ' ').title()} Strategy",
            description=f"Similar performance patterns have been successfully optimized using {opt_type.value}.",
            technical_details=[f"Based on pattern {pattern.pattern_id} with {pattern.occurrence_count} occurrences"],
            implementation_steps=["Follow established optimization procedure for this pattern type"],
            risk_assessment="Low - Based on proven successful pattern",
            effort_estimate="medium",
            similar_cases=[pattern.pattern_id]
        )
    
    def _generate_general_recommendations(self, features: List[PerformanceFeature],
                                        performance_data: Dict[str, Any],
                                        system_state: Dict[str, Any]) -> List[OptimizationRecommendation]:
        """Generate general optimization recommendations"""
        
        recommendations = []
        
        # Caching recommendation based on response times
        response_time = performance_data.get('duration_ms', 0)
        if response_time > 1000:  # >1 second response time
            rec = OptimizationRecommendation(
                recommendation_id=f"caching_opt_{int(time.time())}",
                optimization_type=OptimizationType.CACHING_STRATEGY,
                priority=OptimizationPriority.MEDIUM,
                confidence=0.6,
                estimated_impact=self.meta_learner.predict_performance_impact(
                    features, OptimizationType.CACHING_STRATEGY
                ),
                title="Implement Strategic Caching",
                description=f"Response time of {response_time:.0f}ms suggests caching opportunities.",
                technical_details=[
                    "Add application-level caching for frequently accessed data",
                    "Implement CDN for static content",
                    "Use Redis/Memcached for session and query caching",
                    "Add cache invalidation strategies"
                ],
                implementation_steps=[
                    "1. Identify frequently accessed data patterns",
                    "2. Implement LRU cache for hot data",
                    "3. Add cache-aside pattern for database queries",
                    "4. Monitor cache hit ratios and optimize"
                ],
                risk_assessment="Low - Caching improvements are typically safe",
                effort_estimate="medium",
                evidence={"response_time_ms": response_time}
            )
            recommendations.append(rec)
        
        return recommendations
    
    def _rank_recommendations(self, recommendations: List[OptimizationRecommendation]) -> List[OptimizationRecommendation]:
        """Rank recommendations by priority and impact"""
        
        priority_weights = {
            OptimizationPriority.CRITICAL: 4,
            OptimizationPriority.HIGH: 3,
            OptimizationPriority.MEDIUM: 2,
            OptimizationPriority.LOW: 1
        }
        
        def recommendation_score(rec: OptimizationRecommendation) -> float:
            priority_score = priority_weights[rec.priority]
            impact_score = rec.estimated_impact / 100  # Normalize to 0-1
            confidence_score = rec.confidence
            
            return (priority_score * 0.4 + impact_score * 0.4 + confidence_score * 0.2)
        
        return sorted(recommendations, key=recommendation_score, reverse=True)
    
    def _initialize_optimization_strategies(self) -> Dict[OptimizationType, Dict[str, Any]]:
        """Initialize knowledge base of optimization strategies"""
        
        return {
            OptimizationType.ALGORITHM_OPTIMIZATION: {
                "typical_impact": 25.0,
                "effort": "medium",
                "risk": "low",
                "success_rate": 0.8
            },
            OptimizationType.CACHING_STRATEGY: {
                "typical_impact": 30.0,
                "effort": "medium",
                "risk": "low",
                "success_rate": 0.9
            },
            OptimizationType.DATABASE_OPTIMIZATION: {
                "typical_impact": 20.0,
                "effort": "medium",
                "risk": "medium",
                "success_rate": 0.7
            },
            OptimizationType.RESOURCE_SCALING: {
                "typical_impact": 10.0,
                "effort": "low",
                "risk": "low",
                "success_rate": 0.95
            }
        }
    
    def get_recommendations_by_priority(self, priority: OptimizationPriority) -> List[OptimizationRecommendation]:
        """Get recommendations filtered by priority"""
        return [
            rec for rec in self.recommendation_history
            if rec.priority == priority
        ]
    
    def get_recommendation_by_id(self, recommendation_id: str) -> Optional[OptimizationRecommendation]:
        """Get specific recommendation by ID"""
        return self.recommendations.get(recommendation_id)

# Global optimization engine instance
optimization_engine = OptimizationRecommendationEngine()

def get_optimization_engine() -> OptimizationRecommendationEngine:
    """Get the global optimization engine instance"""
    return optimization_engine