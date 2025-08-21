# 🎯 CCPM MASTER GUIDE - High-Level Instruction Distillation

**Quick Reference**: Complete guidance for CCPM (Claude Code PM) mastery with direct documentation pathways

## 🚀 ULTRATHINK QUICK START (2 Minutes)

### What is CCPM?
**Spec-driven development system** that transforms Product Requirements Documents (PRDs) into executable GitHub issues through parallel AI agent execution. **Eliminates "vibe coding"** with complete traceability from idea to production.

### Core Value Proposition
- **89% less context switching** through agent specialization
- **5-8x parallel execution** vs sequential development  
- **75% fewer bugs** through spec-driven discipline
- **3x faster delivery** with optimized workflows

### Essential Philosophy
**"No Vibe Coding"** - Every line of code must trace back to a specification through the **5-Phase Discipline**: Brainstorm → Document → Plan → Execute → Track

## 🧠 THE 5-PHASE DEVELOPMENT DISCIPLINE

### Phase 1: Product Planning (`/pm:prd-new`)
**Purpose**: Comprehensive brainstorming and requirements gathering  
**Output**: Complete Product Requirements Document (PRD)  
**Documentation**: README.md "Product Planning" section  
**Next Step**: Phase 2 Implementation Planning

### Phase 2: Implementation Planning (`/pm:prd-parse`)
**Purpose**: Technical architecture and approach definition  
**Output**: Epic with technical implementation strategy  
**Documentation**: README.md "Implementation Planning" section  
**Next Step**: Phase 3 Task Decomposition

### Phase 3: Task Decomposition (`/pm:epic-decompose`)
**Purpose**: Breaking epics into parallel, actionable tasks  
**Output**: 4-6 parallel work streams in GitHub issues  
**Documentation**: COMMANDS.md epic-decompose reference  
**Next Step**: Phase 4 GitHub Synchronization

### Phase 4: GitHub Synchronization (`/pm:epic-sync`)
**Purpose**: Push structured work to GitHub as single source of truth  
**Output**: GitHub issues with parent-child relationships  
**Documentation**: README.md "GitHub Integration" section  
**Next Step**: Phase 5 Parallel Execution

### Phase 5: Parallel Execution (`/pm:issue-start`)
**Purpose**: Specialized agents implementing tasks simultaneously  
**Output**: Production code with complete traceability  
**Documentation**: AGENTS.md parallel-worker section  
**Monitoring**: `/pm:status` for progress tracking

## 🤖 THE 4 CORE AGENTS (Context Preservation)

### 🔍 code-analyzer
**Purpose**: Hunt bugs across multiple files without context pollution  
**When to Use**: Logic flow tracing, bug finding, change validation  
**Documentation**: ccpm/.claude/agents/code-analyzer.md  
**Key Benefit**: 80-90% context reduction while preserving critical findings

### 📄 file-analyzer  
**Purpose**: Read and summarize verbose files (logs, outputs, configs)  
**When to Use**: Understanding log files, analyzing verbose output  
**Documentation**: ccpm/.claude/agents/file-analyzer.md  
**Key Benefit**: Extract actionable insights from massive files

### 🧪 test-runner
**Purpose**: Execute tests without dumping output to main thread  
**When to Use**: Running test suites and understanding failures  
**Documentation**: ccpm/.claude/agents/test-runner.md  
**Key Benefit**: Clean test execution with intelligent failure analysis

### 🔀 parallel-worker
**Purpose**: Coordinate multiple parallel work streams for complex issues  
**When to Use**: Executing 4+ simultaneous development tasks  
**Documentation**: ccpm/.claude/agents/parallel-worker.md  
**Key Benefit**: True parallel development with consolidated reporting

## ⚡ ESSENTIAL COMMAND SEQUENCES

### Complete Feature Development
```bash
/pm:prd-new feature-name          # Comprehensive brainstorming
/pm:prd-parse feature-name        # Technical implementation plan  
/pm:epic-oneshot feature-name     # Decompose and sync to GitHub
/pm:issue-start 1234              # Launch parallel execution
/pm:status                        # Monitor progress
```

### Context Management Workflow
```bash
/context:create                   # Create project context
/context:prime                    # Load context into conversation
/context:update                   # Refresh with recent changes
```

### Testing and Validation
```bash
/testing:prime                    # Configure testing framework
/testing:run                      # Execute tests with analysis
/pm:validate                      # Check system integrity
```

### Project Monitoring
```bash
/pm:next                         # Get next priority task
/pm:standup                      # Daily progress report
/pm:blocked                      # Show blocked tasks
/pm:in-progress                  # List active work
```

## 📚 DOCUMENTATION PATHWAY SYSTEM

### 🎯 By User Level

#### Beginner (First 24 Hours)
1. **Start Here**: README.md "Quick Start" section
2. **Setup**: README.md "Installation" → `/pm:init` command
3. **First PRD**: README.md "5-Phase Discipline" → `/pm:prd-new` usage
4. **Commands Reference**: COMMANDS.md for command syntax

#### Intermediate (Week 1-2)
1. **Agent Mastery**: AGENTS.md complete agent philosophy
2. **Workflow Optimization**: README.md "Parallel Execution System"
3. **GitHub Integration**: README.md "GitHub-Native Integration"
4. **Command Mastery**: Individual files in ccpm/.claude/commands/pm/

#### Advanced (Week 3+)
1. **System Architecture**: ccpm/.claude/rules/ directory
2. **Custom Workflows**: ccmp/.claude/context/ management
3. **Team Collaboration**: README.md "Team Collaboration" patterns
4. **Performance Optimization**: All documentation for workflow tuning

### 🎯 By Problem Type

#### "I'm losing context and getting overwhelmed"
**Solution**: Agent specialization for context preservation  
**Documentation**: AGENTS.md → "Context Firewalls" section  
**Implementation**: Use code-analyzer and file-analyzer agents  

#### "Development is too sequential and slow"
**Solution**: Parallel execution patterns  
**Documentation**: README.md → "Parallel Execution System"  
**Implementation**: `/pm:epic-decompose` → `/pm:issue-start` workflow  

#### "Requirements keep changing during development"
**Solution**: Spec-driven development discipline  
**Documentation**: README.md → "5-Phase Discipline"  
**Implementation**: Complete PRD before any coding  

#### "Team coordination is chaotic"
**Solution**: GitHub-native workflows  
**Documentation**: README.md → "GitHub Integration"  
**Implementation**: `/pm:epic-sync` → GitHub Issues as single source of truth  

### 🎯 By Development Scenario

#### Solo Developer Project
**Focus**: Context preservation + parallel execution  
**Key Commands**: `/pm:prd-new`, `/pm:epic-oneshot`, `/pm:issue-start`  
**Documentation**: README.md + AGENTS.md  

#### Small Team (2-5 developers)
**Focus**: GitHub integration + collaboration patterns  
**Key Commands**: `/pm:epic-sync`, `/pm:standup`, `/pm:status`  
**Documentation**: README.md "Team Collaboration" + COMMANDS.md  

#### Large/Complex Project
**Focus**: Advanced agent coordination + epic decomposition  
**Key Commands**: `/pm:epic-decompose`, parallel-worker agent usage  
**Documentation**: All agent files + rules directory  

## 🧠 ULTRATHINK DECISION FRAMEWORK

### When to Use CCPM
✅ **Complex features** requiring multiple work streams  
✅ **Context-heavy** development with large codebases  
✅ **Team coordination** needing clear specifications  
✅ **Quality-critical** projects requiring traceability  

### When NOT to Use CCPM
❌ **Simple bug fixes** (overhead > benefit)  
❌ **Prototype/exploration** work (specs would slow down)  
❌ **Solo scripts** (<100 lines, no collaboration needed)  

### Agent Selection Logic
- **Multiple file analysis needed** → code-analyzer
- **Verbose logs/output to process** → file-analyzer
- **Test execution required** → test-runner  
- **4+ parallel tasks to coordinate** → parallel-worker
- **Simple task, minimal context** → Handle directly (no agent)

## 🎯 SUCCESS METRICS & OPTIMIZATION

### Key Performance Indicators
- **Context switching time**: Should reduce by 80-90%
- **Parallel work streams**: Target 4-6 simultaneous tasks
- **Bug rates**: 75% reduction through spec-driven development
- **Delivery speed**: 3x improvement through workflow optimization

### Optimization Checklist
- [ ] Following 5-phase discipline without shortcuts
- [ ] Using agents for context reduction (not simple tasks)
- [ ] Maintaining GitHub as single source of truth
- [ ] Decomposing work into true parallel streams
- [ ] Preserving specifications throughout development

## 🚀 NEXT STEPS & MASTERY PATH

### Immediate Actions (Today)
1. **Install**: Follow README.md setup → `/pm:init`
2. **First PRD**: Create simple feature → `/pm:prd-new test-feature`
3. **Practice Workflow**: Complete 5-phase cycle once

### Week 1 Mastery
1. **Agent Understanding**: Read all 4 agent files completely
2. **Command Fluency**: Practice 10+ core commands
3. **Parallel Execution**: Successfully coordinate 3+ work streams

### Advanced Mastery (Month 1)
1. **System Customization**: Adapt workflows for your specific needs
2. **Team Integration**: Implement for collaborative development
3. **Performance Optimization**: Achieve quantified improvements

---

## 📞 GETTING HELP

### Immediate Support
**Invoke the CCPM Master Agent**:
```
"I need CCPM guidance for [specific scenario]"
```
The ccmp-master agent provides expert guidance with exact documentation pathways.

### Documentation Hierarchy
1. **README.md** - Complete system overview (16,091 chars)
2. **COMMANDS.md** - Full command reference (5,872 chars)  
3. **AGENTS.md** - Agent system philosophy (4,217 chars)
4. **ccpm/.claude/** - Detailed implementation files

### Quick References
- **All Commands**: COMMANDS.md
- **Agent Selection**: AGENTS.md  
- **Workflow Patterns**: README.md "5-Phase Discipline"
- **GitHub Setup**: README.md "Installation"
- **Troubleshooting**: `/pm:validate` + agent analysis

---

**🎯 ULTRATHINK SUMMARY**: CCPM transforms chaotic development into structured, parallel, traceable workflows. Master the 5-phase discipline, leverage agent specialization, and maintain GitHub as your single source of truth for 3x faster, higher-quality software delivery.