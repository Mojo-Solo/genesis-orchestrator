# CLAUDE.md - GENESIS CREATOR + CCPM INTEGRATION

> Think carefully and implement the most concise solution that changes as little code as possible.

## 🚀 GENESIS CREATOR + CLAUDE CODE PM SYSTEM

This project combines two powerful systems:
1. **Genesis Creator Meta-Agent** - Dynamic sub-agent creation for any development task
2. **Claude Code PM** - Spec-driven development with GitHub integration and parallel execution

## 🧠 USE SUB-AGENTS FOR CONTEXT OPTIMIZATION

### 1. Always use the file-analyzer sub-agent when asked to read files.
The file-analyzer agent is an expert in extracting and summarizing critical information from files, particularly log files and verbose outputs. It provides concise, actionable summaries that preserve essential information while dramatically reducing context usage.

### 2. Always use the code-analyzer sub-agent when asked to search code, analyze code, research bugs, or trace logic flow.
The code-analyzer agent is an expert in code analysis, logic tracing, and vulnerability detection. It provides concise, actionable summaries that preserve essential information while dramatically reducing context usage.

### 3. Always use the test-runner sub-agent to run tests and analyze the test results.
Using the test-runner agent ensures full test output is captured for debugging while keeping the main conversation clean and focused.

## 🎯 PROJECT STRUCTURE

This is a comprehensive development environment with:
- **Frontend Preview Agent** (`preview.sh`) - Live HTML/CSS/JS preview with auto-reload
- **Laravel 12 Backend** (`backend/`) - Modern PHP 8.3 + Symfony 7 architecture  
- **MCP Integration** (`.mcp.json`) - Browser automation and tool orchestration
- **Genesis Creator** (`genesis_creator.py`) - Meta-agent for dynamic task execution
- **CCPM System** (`.claude/`) - Project management with GitHub integration

## 🔧 AVAILABLE COMMANDS

### Genesis Creator Commands
```bash
python3 genesis_creator.py "your development request"
./preview.sh                 # Start live frontend preview
```

### CCPM Project Management Commands
```bash
/pm:prd-new <feature-name>    # Create Product Requirements Document
/pm:prd-parse <feature-name>  # Convert PRD to technical epic
/pm:epic-oneshot <feature>    # Decompose and sync to GitHub
/pm:issue-start <issue-id>    # Start work with specialized agent
/pm:next                      # Get next priority task
/pm:status                    # Overall project dashboard
/pm:help                      # View all PM commands
```

### Context Management
```bash
/context:create               # Create project context
/context:prime                # Load context into conversation
/testing:run                  # Execute tests with analysis
```

## 🚀 DEVELOPMENT WORKFLOW

### For New Features (CCPM Approach):
1. **Plan**: `/pm:prd-new feature-name` - Comprehensive brainstorming
2. **Architect**: `/pm:prd-parse feature-name` - Technical implementation plan  
3. **Execute**: `/pm:epic-oneshot feature-name` - GitHub sync + parallel execution
4. **Track**: `/pm:status` - Monitor progress across all tasks

### For Quick Tasks (Genesis Creator):
```bash
python3 genesis_creator.py "Create a dashboard with charts and real-time data"
```

### For Live Frontend Development:
```bash
./preview.sh                 # Start live server
# Open in Cursor: Ctrl+Shift+P > Simple Browser: Show > http://localhost:5500
```

## 🎯 ABSOLUTE RULES

- **NO PARTIAL IMPLEMENTATION** - Complete all work fully
- **NO SIMPLIFICATION** - No placeholder or incomplete code
- **NO CODE DUPLICATION** - Check existing codebase to reuse functions
- **NO DEAD CODE** - Either use or delete completely
- **IMPLEMENT TESTS FOR EVERY FUNCTION** - No exceptions
- **NO CHEATER TESTS** - Tests must be accurate and reveal flaws
- **NO INCONSISTENT NAMING** - Follow existing patterns
- **NO OVER-ENGINEERING** - Simple solutions over complex abstractions
- **NO MIXED CONCERNS** - Proper separation of responsibilities
- **NO RESOURCE LEAKS** - Clean up connections, timeouts, listeners

## 🔄 ERROR HANDLING PHILOSOPHY

- **Fail fast** for critical configuration
- **Log and continue** for optional features  
- **Graceful degradation** when external services unavailable
- **User-friendly messages** through resilience layer

## 💡 TESTING STANDARDS

- Always use the test-runner agent to execute tests
- Do not use mock services for anything ever
- Do not move on until current test is complete
- If test fails, check test structure before refactoring codebase
- Tests must be verbose for debugging purposes

## 🎨 TONE AND BEHAVIOR

- Criticism is welcome - tell me when I'm wrong
- Be skeptical and concise
- Ask questions if you're unsure of intent
- Don't flatter or give unnecessary compliments
- Feel free to suggest better approaches or relevant standards

## 🔗 KEY INTEGRATIONS

- **GitHub Issues** - Single source of truth for project state
- **Live Preview** - Instant feedback for frontend development
- **MCP Servers** - Extensible tool ecosystem
- **Parallel Execution** - Multiple agents working simultaneously
- **Context Preservation** - Agents handle heavy lifting, return summaries

---

**Ready to build? Start with `/pm:prd-new` for structured development or `python3 genesis_creator.py` for quick prototyping!**