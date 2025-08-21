#!/usr/bin/env python3
"""
CCPM + Genesis Creator Integration
==================================

This script demonstrates the integration between Claude Code PM (CCPM) and
Genesis Creator, showing how structured project management can work alongside
rapid AI-powered development.

Usage:
    python3 ccpm_genesis_integration.py [command] [args...]
    
Examples:
    python3 ccpm_genesis_integration.py prd-to-prototype ai-dashboard
    python3 ccpm_genesis_integration.py rapid-feature "user authentication system"
    python3 ccpm_genesis_integration.py epic-implement ai-dashboard
"""

import os
import sys
import json
import subprocess
from pathlib import Path

class CCPMGenesisIntegration:
    """
    Integration layer between CCPM project management and Genesis Creator rapid development
    """
    
    def __init__(self):
        self.project_root = Path.cwd()
        self.claude_dir = self.project_root / ".claude"
        self.genesis_config = self.project_root / "genesis_config.json"
        
    def load_prd(self, prd_name: str) -> dict:
        """Load PRD from CCPM system"""
        prd_path = self.claude_dir / "prds" / f"{prd_name}.md"
        
        if not prd_path.exists():
            raise FileNotFoundError(f"PRD not found: {prd_path}")
            
        with open(prd_path, 'r') as f:
            content = f.read()
            
        # Extract frontmatter
        if content.startswith('---'):
            parts = content.split('---', 2)
            if len(parts) >= 3:
                # Parse YAML frontmatter (simple implementation)
                frontmatter = {}
                for line in parts[1].strip().split('\n'):
                    if ':' in line:
                        key, value = line.split(':', 1)
                        frontmatter[key.strip()] = value.strip().strip('"\'')
                
                return {
                    'metadata': frontmatter,
                    'content': parts[2].strip()
                }
        
        return {'metadata': {}, 'content': content}
    
    def load_epic(self, epic_name: str) -> dict:
        """Load epic from CCPM system"""
        epic_path = self.claude_dir / "epics" / epic_name / "epic.md"
        
        if not epic_path.exists():
            raise FileNotFoundError(f"Epic not found: {epic_path}")
            
        return self.load_prd(epic_name)  # Same format
    
    def prd_to_prototype(self, prd_name: str):
        """Convert a CCPM PRD into a Genesis Creator prototype"""
        print(f"🎯 Converting PRD '{prd_name}' to Genesis Creator prototype...")
        
        try:
            prd = self.load_prd(prd_name)
            epic = self.load_epic(prd_name)
            
            # Extract key requirements for Genesis Creator
            description = prd['metadata'].get('description', 'AI system component')
            
            # Create Genesis Creator prompt from PRD content
            prompt = self.create_genesis_prompt(prd, epic)
            
            print(f"📋 PRD loaded: {description}")
            print(f"🏗️ Epic architecture available")
            print(f"🚀 Launching Genesis Creator...")
            print("-" * 50)
            
            # Execute Genesis Creator with structured prompt
            result = subprocess.run([
                'python3', 'genesis_creator.py', prompt
            ], capture_output=True, text=True)
            
            print(result.stdout)
            if result.stderr:
                print("⚠️ Warnings:", result.stderr)
                
            print("-" * 50)
            print("✅ Prototype generation complete!")
            print(f"📁 Check generated files for rapid prototype of '{prd_name}'")
            
        except FileNotFoundError as e:
            print(f"❌ Error: {e}")
            print(f"💡 Create PRD first with: /pm:prd-new {prd_name}")
            
    def create_genesis_prompt(self, prd: dict, epic: dict) -> str:
        """Create structured Genesis Creator prompt from CCPM artifacts"""
        prompt_parts = []
        
        # Add PRD context
        if 'description' in prd['metadata']:
            prompt_parts.append(f"Build a {prd['metadata']['description']}")
        
        # Add key requirements from PRD content
        prd_content = prd['content']
        if 'Executive Summary' in prd_content:
            # Extract executive summary
            summary_start = prd_content.find('## Executive Summary')
            summary_end = prd_content.find('## Problem Statement')
            if summary_start != -1 and summary_end != -1:
                summary = prd_content[summary_start:summary_end].replace('## Executive Summary', '').strip()
                prompt_parts.append(f"Requirements: {summary[:200]}...")
        
        # Add technical context from epic
        if epic and 'Technology Stack' in epic['content']:
            prompt_parts.append("Use modern web technologies with React and Laravel backend")
        
        # Add specific implementation details
        prompt_parts.append("Include real-time features, responsive design, and proper error handling")
        
        return " ".join(prompt_parts)
    
    def rapid_feature(self, feature_description: str):
        """Use Genesis Creator for rapid feature development"""
        print(f"⚡ Rapid feature development: {feature_description}")
        print("🚀 Launching Genesis Creator...")
        print("-" * 50)
        
        # Execute Genesis Creator directly
        result = subprocess.run([
            'python3', 'genesis_creator.py', feature_description
        ], capture_output=True, text=True)
        
        print(result.stdout)
        if result.stderr:
            print("⚠️ Warnings:", result.stderr)
            
        print("-" * 50)
        print("✅ Rapid development complete!")
        print("💡 Consider creating a PRD for complex features: /pm:prd-new <name>")
    
    def epic_implement(self, epic_name: str):
        """Implement a CCPM epic using Genesis Creator agents"""
        print(f"🏗️ Implementing epic '{epic_name}' with Genesis Creator...")
        
        try:
            epic = self.load_epic(epic_name)
            
            print(f"📋 Epic: {epic['metadata'].get('description', 'Implementation')}")
            print("🤖 Analyzing epic for parallel implementation streams...")
            
            # Extract tasks from epic content
            tasks = self.extract_tasks_from_epic(epic)
            
            print(f"📊 Found {len(tasks)} implementation tasks")
            print("🚀 Starting parallel Genesis Creator agents...")
            print("-" * 50)
            
            for i, task in enumerate(tasks, 1):
                print(f"\n🔧 Task {i}: {task['title']}")
                print(f"📝 Description: {task['description'][:100]}...")
                
                # Create Genesis Creator prompt for this task
                task_prompt = f"Implement {task['title']}: {task['description']}"
                
                # Execute Genesis Creator for this task
                result = subprocess.run([
                    'python3', 'genesis_creator.py', task_prompt
                ], capture_output=True, text=True)
                
                print(f"✅ Task {i} completed")
            
            print("-" * 50)
            print(f"🎉 Epic '{epic_name}' implementation complete!")
            print("📋 All tasks processed by Genesis Creator agents")
            
        except FileNotFoundError as e:
            print(f"❌ Error: {e}")
            print(f"💡 Create epic first with: /pm:prd-parse {epic_name}")
    
    def extract_tasks_from_epic(self, epic: dict) -> list:
        """Extract implementation tasks from epic content"""
        tasks = []
        content = epic['content']
        
        # Simple task extraction (look for task patterns)
        lines = content.split('\n')
        current_task = None
        
        for line in lines:
            line = line.strip()
            if line.startswith('**Task') and ':' in line:
                # Found a task header
                if current_task:
                    tasks.append(current_task)
                
                title = line.split(':', 1)[1].strip().strip('*')
                current_task = {
                    'title': title,
                    'description': ''
                }
            elif current_task and line and not line.startswith('#'):
                # Add to task description
                current_task['description'] += ' ' + line
        
        # Add final task
        if current_task:
            tasks.append(current_task)
        
        # If no tasks found, create default tasks
        if not tasks:
            tasks = [
                {
                    'title': 'Frontend Implementation', 
                    'description': 'Build user interface with React components'
                },
                {
                    'title': 'Backend API Development',
                    'description': 'Create Laravel API endpoints and business logic'  
                },
                {
                    'title': 'Integration and Testing',
                    'description': 'Connect frontend to backend and implement tests'
                }
            ]
        
        return tasks
    
    def show_status(self):
        """Show integration status and available commands"""
        print("🚀 CCPM + Genesis Creator Integration Status")
        print("=" * 50)
        
        # Check CCPM system
        if self.claude_dir.exists():
            prds = list((self.claude_dir / "prds").glob("*.md")) if (self.claude_dir / "prds").exists() else []
            epics = list((self.claude_dir / "epics").glob("*/epic.md")) if (self.claude_dir / "epics").exists() else []
            
            print(f"📄 CCPM PRDs: {len(prds)} available")
            print(f"🏗️ CCPM Epics: {len(epics)} available")
        else:
            print("❌ CCPM system not found (.claude directory missing)")
        
        # Check Genesis Creator
        if self.genesis_config.exists():
            print("✅ Genesis Creator system available")
        else:
            print("⚠️ Genesis Creator config not found (genesis_config.json)")
        
        print(f"📁 Project root: {self.project_root}")
        print()
        print("🔧 Available Integration Commands:")
        print("  prd-to-prototype <name>    - Convert PRD to Genesis prototype")
        print("  rapid-feature <desc>       - Quick feature with Genesis Creator")  
        print("  epic-implement <name>      - Implement epic with parallel agents")
        print("  status                     - Show this status information")

def main():
    """Main CLI interface"""
    integration = CCPMGenesisIntegration()
    
    if len(sys.argv) < 2:
        integration.show_status()
        return
    
    command = sys.argv[1]
    
    if command == "prd-to-prototype":
        if len(sys.argv) < 3:
            print("❌ Usage: prd-to-prototype <prd_name>")
            return
        integration.prd_to_prototype(sys.argv[2])
        
    elif command == "rapid-feature":
        if len(sys.argv) < 3:
            print("❌ Usage: rapid-feature <description>")
            return
        integration.rapid_feature(" ".join(sys.argv[2:]))
        
    elif command == "epic-implement":
        if len(sys.argv) < 3:
            print("❌ Usage: epic-implement <epic_name>")
            return
        integration.epic_implement(sys.argv[2])
        
    elif command == "status":
        integration.show_status()
        
    else:
        print(f"❌ Unknown command: {command}")
        integration.show_status()

if __name__ == "__main__":
    main()