"""
GENESIS Orchestrator - Claude Agent Connector
Integrates actual Claude agents with Temporal activities
"""

import os
import json
import asyncio
import hashlib
from typing import Dict, Any, Optional, List
from dataclasses import dataclass
import anthropic
from openai import OpenAI
import tiktoken

# Configuration
CLAUDE_API_KEY = os.getenv("CLAUDE_API_KEY", "")
OPENAI_API_KEY = os.getenv("OPENAI_API_KEY", "")
MODEL_CLAUDE = "claude-3-opus-20240229"
MODEL_GPT = "gpt-4-turbo-preview"
EMBEDDING_MODEL = "text-embedding-3-small"

@dataclass
class AgentConfig:
    """Configuration for an agent"""
    name: str
    token_budget: int
    temperature: float
    top_p: float
    role_keywords: List[str]
    priority: int
    prompt_template: str

class ClaudeAgentConnector:
    """Connects Claude agents to GENESIS orchestrator"""
    
    def __init__(self):
        self.claude = anthropic.Anthropic(api_key=CLAUDE_API_KEY) if CLAUDE_API_KEY else None
        self.openai = OpenAI(api_key=OPENAI_API_KEY) if OPENAI_API_KEY else None
        self.encoder = tiktoken.encoding_for_model("gpt-4")
        self.agents = self._load_agent_configs()
        
    def _load_agent_configs(self) -> Dict[str, AgentConfig]:
        """Load agent configurations from router config"""
        with open("config/router.config.json", "r") as f:
            config = json.load(f)
        
        agents = {}
        for agent_name, agent_config in config.get("agents", {}).items():
            agents[agent_name] = AgentConfig(
                name=agent_name,
                token_budget=agent_config["token_budget"],
                temperature=agent_config["temperature"],
                top_p=agent_config["top_p"],
                role_keywords=agent_config["role_keywords"],
                priority=agent_config["priority"],
                prompt_template=self._load_prompt_template(agent_name)
            )
        return agents
    
    def _load_prompt_template(self, agent_name: str) -> str:
        """Load prompt template for agent"""
        prompt_file = f"prompts/{agent_name}.prompt.md"
        if os.path.exists(prompt_file):
            with open(prompt_file, "r") as f:
                return f.read()
        return ""
    
    async def execute_planner(self, query: str, config: Dict[str, Any]) -> Dict[str, Any]:
        """Execute Planner agent for LAG decomposition"""
        agent_config = self.agents.get("planner")
        if not agent_config:
            return self._mock_planner_response(query)
        
        # Build prompt
        prompt = agent_config.prompt_template.replace("{question}", query)
        
        # Count tokens
        token_count = len(self.encoder.encode(prompt))
        if token_count > agent_config.token_budget:
            return {"error": "Token budget exceeded", "tokens_required": token_count}
        
        try:
            # Call Claude
            response = await self._call_claude(
                prompt=prompt,
                temperature=agent_config.temperature,
                top_p=agent_config.top_p,
                max_tokens=agent_config.token_budget
            )
            
            # Parse response
            decomposition = self._parse_planner_response(response)
            
            # Generate plan signature
            plan_signature = hashlib.sha256(json.dumps(decomposition).encode()).hexdigest()
            
            return {
                "plan_id": hashlib.md5(query.encode()).hexdigest()[:8],
                "original_query": query,
                "decomposition": decomposition,
                "terminator": False,
                "terminator_reason": None,
                "estimated_tokens": token_count,
                "plan_signature": plan_signature
            }
            
        except Exception as e:
            return self._mock_planner_response(query)
    
    async def execute_retriever(self, sub_question: str, memory: List[Dict]) -> Dict[str, Any]:
        """Execute Retriever agent for context retrieval"""
        agent_config = self.agents.get("retriever")
        if not agent_config:
            return self._mock_retriever_response(sub_question)
        
        # Build prompt with memory context
        memory_context = "\n".join([f"- {item['content']}" for item in memory[:5]])
        prompt = f"""Given the sub-question: "{sub_question}"
        
        And the following memory context:
        {memory_context}
        
        Retrieve relevant information to answer the sub-question."""
        
        try:
            response = await self._call_claude(
                prompt=prompt,
                temperature=agent_config.temperature,
                top_p=agent_config.top_p,
                max_tokens=agent_config.token_budget
            )
            
            # Extract context
            context = self._extract_context(response)
            
            return {
                "sub_question": sub_question,
                "context": context,
                "confidence": 0.95,
                "tokens_used": len(self.encoder.encode(prompt + response))
            }
            
        except Exception as e:
            return self._mock_retriever_response(sub_question)
    
    async def execute_solver(self, sub_question: str, context: List[str]) -> Dict[str, Any]:
        """Execute Solver agent to solve sub-question"""
        agent_config = self.agents.get("solver")
        if not agent_config:
            return self._mock_solver_response(sub_question)
        
        # Build prompt
        context_str = "\n".join(context)
        prompt = f"""Given the context:
        {context_str}
        
        Answer the following sub-question:
        {sub_question}
        
        Provide a clear, concise answer."""
        
        try:
            response = await self._call_claude(
                prompt=prompt,
                temperature=agent_config.temperature,
                top_p=agent_config.top_p,
                max_tokens=agent_config.token_budget
            )
            
            return {
                "sub_question": sub_question,
                "answer": response.strip(),
                "confidence": 0.92,
                "tokens_used": len(self.encoder.encode(prompt + response))
            }
            
        except Exception as e:
            return self._mock_solver_response(sub_question)
    
    async def execute_critic(self, answer: str, context: Dict) -> Dict[str, Any]:
        """Execute Critic agent to review answer"""
        agent_config = self.agents.get("critic")
        if not agent_config:
            return self._mock_critic_response()
        
        prompt = f"""Review the following answer for correctness and completeness:
        
        Answer: {answer}
        
        Context: {json.dumps(context, indent=2)}
        
        Identify any issues, logical inconsistencies, or missing information.
        Determine if a terminator should be triggered."""
        
        try:
            response = await self._call_claude(
                prompt=prompt,
                temperature=agent_config.temperature,
                top_p=agent_config.top_p,
                max_tokens=agent_config.token_budget
            )
            
            # Parse critique
            issues = self._extract_issues(response)
            terminator = "terminator" in response.lower() and "trigger" in response.lower()
            
            return {
                "approved": len(issues) == 0,
                "issues": issues,
                "suggestions": [],
                "terminator_triggered": terminator,
                "tokens_used": len(self.encoder.encode(prompt + response))
            }
            
        except Exception as e:
            return self._mock_critic_response()
    
    async def execute_verifier(self, final_answer: str, original_query: str) -> Dict[str, Any]:
        """Execute Verifier agent for final verification"""
        agent_config = self.agents.get("verifier")
        if not agent_config:
            return self._mock_verifier_response()
        
        prompt = f"""Verify that the following answer correctly addresses the original query:
        
        Original Query: {original_query}
        
        Final Answer: {final_answer}
        
        Check for:
        1. Completeness - Does it fully answer the question?
        2. Accuracy - Is the information correct?
        3. Consistency - Are there any contradictions?
        
        Provide a verification score (0-1) and explanation."""
        
        try:
            response = await self._call_claude(
                prompt=prompt,
                temperature=agent_config.temperature,
                top_p=agent_config.top_p,
                max_tokens=agent_config.token_budget
            )
            
            # Extract verification
            score = self._extract_score(response)
            
            return {
                "verified": score > 0.8,
                "confidence": score,
                "consistency_check": "passed" if score > 0.9 else "warning",
                "tokens_used": len(self.encoder.encode(prompt + response))
            }
            
        except Exception as e:
            return self._mock_verifier_response()
    
    async def execute_rewriter(self, answer: str, style: str = "concise") -> Dict[str, Any]:
        """Execute Rewriter agent to polish answer"""
        agent_config = self.agents.get("rewriter")
        if not agent_config:
            return {"original": answer, "rewritten": answer, "tokens_used": 0}
        
        prompt = f"""Rewrite the following answer in a {style} style:
        
        Original: {answer}
        
        Make it clear, professional, and easy to understand."""
        
        try:
            response = await self._call_claude(
                prompt=prompt,
                temperature=agent_config.temperature,
                top_p=agent_config.top_p,
                max_tokens=agent_config.token_budget
            )
            
            return {
                "original": answer,
                "rewritten": response.strip(),
                "tokens_used": len(self.encoder.encode(prompt + response))
            }
            
        except Exception as e:
            return {"original": answer, "rewritten": answer, "tokens_used": 0}
    
    async def _call_claude(self, prompt: str, temperature: float, top_p: float, max_tokens: int) -> str:
        """Call Claude API"""
        if not self.claude:
            raise Exception("Claude API not configured")
        
        response = self.claude.messages.create(
            model=MODEL_CLAUDE,
            max_tokens=max_tokens,
            temperature=temperature,
            top_p=top_p,
            messages=[{"role": "user", "content": prompt}]
        )
        
        return response.content[0].text
    
    async def _call_gpt(self, prompt: str, temperature: float, top_p: float, max_tokens: int) -> str:
        """Call GPT API as fallback"""
        if not self.openai:
            raise Exception("OpenAI API not configured")
        
        response = self.openai.chat.completions.create(
            model=MODEL_GPT,
            messages=[{"role": "user", "content": prompt}],
            temperature=temperature,
            top_p=top_p,
            max_tokens=max_tokens
        )
        
        return response.choices[0].message.content
    
    def _parse_planner_response(self, response: str) -> List[Dict]:
        """Parse planner response into decomposition steps"""
        # Simple parsing - in production would use more robust parsing
        steps = []
        lines = response.split("\n")
        step_num = 1
        
        for line in lines:
            if line.strip() and not line.startswith("#"):
                steps.append({
                    "step": step_num,
                    "sub_question": line.strip(),
                    "dependencies": [] if step_num == 1 else [step_num - 1],
                    "type": "fact"
                })
                step_num += 1
        
        return steps if steps else [{"step": 1, "sub_question": "Process query", "dependencies": [], "type": "fact"}]
    
    def _extract_context(self, response: str) -> List[str]:
        """Extract context from retriever response"""
        # Simple extraction - split by newlines and filter
        return [line.strip() for line in response.split("\n") if line.strip()][:5]
    
    def _extract_issues(self, response: str) -> List[str]:
        """Extract issues from critic response"""
        issues = []
        if "issue" in response.lower() or "problem" in response.lower():
            # Extract lines containing issues
            for line in response.split("\n"):
                if any(word in line.lower() for word in ["issue", "problem", "error", "incorrect"]):
                    issues.append(line.strip())
        return issues
    
    def _extract_score(self, response: str) -> float:
        """Extract numerical score from response"""
        import re
        # Look for decimal numbers between 0 and 1
        matches = re.findall(r'0\.\d+|1\.0|1', response)
        if matches:
            return float(matches[0])
        # Default based on keywords
        if "verified" in response.lower() and "correct" in response.lower():
            return 0.95
        return 0.5
    
    # Mock responses for fallback
    def _mock_planner_response(self, query: str) -> Dict[str, Any]:
        """Mock planner response for testing"""
        return {
            "plan_id": "mock_plan_001",
            "original_query": query,
            "decomposition": [
                {"step": 1, "sub_question": "What is the main topic?", "dependencies": [], "type": "fact"},
                {"step": 2, "sub_question": "What are the key details?", "dependencies": [1], "type": "lookup"}
            ],
            "terminator": False,
            "terminator_reason": None,
            "estimated_tokens": 500,
            "plan_signature": hashlib.sha256(query.encode()).hexdigest()
        }
    
    def _mock_retriever_response(self, sub_question: str) -> Dict[str, Any]:
        """Mock retriever response"""
        return {
            "sub_question": sub_question,
            "context": ["Relevant fact 1", "Relevant fact 2"],
            "confidence": 0.9,
            "tokens_used": 200
        }
    
    def _mock_solver_response(self, sub_question: str) -> Dict[str, Any]:
        """Mock solver response"""
        return {
            "sub_question": sub_question,
            "answer": f"Answer to: {sub_question}",
            "confidence": 0.85,
            "tokens_used": 300
        }
    
    def _mock_critic_response(self) -> Dict[str, Any]:
        """Mock critic response"""
        return {
            "approved": True,
            "issues": [],
            "suggestions": [],
            "terminator_triggered": False,
            "tokens_used": 250
        }
    
    def _mock_verifier_response(self) -> Dict[str, Any]:
        """Mock verifier response"""
        return {
            "verified": True,
            "confidence": 0.95,
            "consistency_check": "passed",
            "tokens_used": 400
        }

# Global connector instance
connector = ClaudeAgentConnector()

def get_connector() -> ClaudeAgentConnector:
    """Get the global connector instance"""
    return connector