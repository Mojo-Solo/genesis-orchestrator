import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import { AppState, Initiative, Project, VisionSection, BudgetPlan, Status } from './types';
import { createMockAppState } from './mock';

interface AppStore extends AppState {
  // Vision actions
  setVision: (section: keyof VisionSection, value: string) => void;
  
  // Initiative actions
  updateInitiative: (id: string, updates: Partial<Initiative>) => void;
  addInitiative: (initiative: Initiative) => void;
  
  // Project actions
  updateProject: (id: string, updates: Partial<Project>) => void;
  addProject: (project: Project) => void;
  updateProjectWeek: (projectId: string, week: number, percent: number, status: Status) => void;
  updateProjectIssues: (projectId: string, quarter: string, issues: string) => void;
  updateProjectNextActions: (projectId: string, quarter: string, nextActions: string) => void;
  
  // Journal actions
  setJournalPrompt: (prompt: string) => void;
  setJournalSummary: (summary: string) => void;
  
  // Budget actions
  updateBudgetMonth: (type: 'plan' | 'actual', monthIndex: number, updates: Partial<any>) => void;
  
  // Utility actions
  resetDemo: () => void;
}

export const useAppStore = create<AppStore>()(
  persist(
    (set, get) => ({
      // Initialize with mock data
      ...createMockAppState(),
      
      // Vision actions
      setVision: (section, value) => set((state) => ({
        vision: { ...state.vision, [section]: value }
      })),
      
      // Initiative actions
      updateInitiative: (id, updates) => set((state) => ({
        initiatives: state.initiatives.map(init => 
          init.id === id ? { ...init, ...updates } : init
        )
      })),
      
      addInitiative: (initiative) => set((state) => ({
        initiatives: [...state.initiatives, initiative]
      })),
      
      // Project actions
      updateProject: (id, updates) => set((state) => ({
        projects: state.projects.map(project => 
          project.id === id ? { ...project, ...updates } : project
        )
      })),
      
      addProject: (project) => set((state) => ({
        projects: [...state.projects, project]
      })),
      
      updateProjectWeek: (projectId, week, percent, status) => set((state) => ({
        projects: state.projects.map(project => 
          project.id === projectId 
            ? {
                ...project,
                weekly: project.weekly.map(w => 
                  w.week === week ? { ...w, percent, status } : w
                )
              }
            : project
        )
      })),
      
      updateProjectIssues: (projectId, quarter, issues) => set((state) => ({
        projects: state.projects.map(project => 
          project.id === projectId && project.quarter === quarter
            ? { ...project, issues }
            : project
        )
      })),
      
      updateProjectNextActions: (projectId, quarter, nextActions) => set((state) => ({
        projects: state.projects.map(project => 
          project.id === projectId && project.quarter === quarter
            ? { ...project, nextActions }
            : project
        )
      })),
      
      // Journal actions
      setJournalPrompt: (prompt) => set((state) => ({
        journal: { ...state.journal, prompt }
      })),
      
      setJournalSummary: (summary) => set((state) => ({
        journal: { ...state.journal, summary }
      })),
      
      // Budget actions
      updateBudgetMonth: (type, monthIndex, updates) => set((state) => ({
        budget: {
          ...state.budget,
          [type]: state.budget[type].map((month, i) => 
            i === monthIndex ? { ...month, ...updates } : month
          )
        }
      })),
      
      // Utility actions
      resetDemo: () => set(createMockAppState())
    }),
    {
      name: 'cothinkr-app-storage',
      partialize: (state) => ({
        vision: state.vision,
        initiatives: state.initiatives,
        projects: state.projects,
        journal: state.journal,
        budget: state.budget
      })
    }
  )
);

// Selector hooks for common data patterns
export const useInitiativesByStatus = () => {
  const initiatives = useAppStore(state => state.initiatives);
  return {
    approved: initiatives.filter(i => i.approved),
    pending: initiatives.filter(i => !i.approved)
  };
};

export const useProjectsByQuarter = () => {
  const projects = useAppStore(state => state.projects);
  return {
    Q1: projects.filter(p => p.quarter === 'Q1'),
    Q2: projects.filter(p => p.quarter === 'Q2'),
    Q3: projects.filter(p => p.quarter === 'Q3'),
    Q4: projects.filter(p => p.quarter === 'Q4')
  };
};

export const useBudgetSummary = () => {
  const budget = useAppStore(state => state.budget);
  
  const calculateTotal = (months: any[], field: string) => 
    months.reduce((sum, month) => sum + (month[field] || 0), 0);
  
  const calculateOfferingsTotal = (months: any[]) =>
    months.reduce((sum, month) => sum + month.offerings.reduce((a: number, b: number) => a + b, 0), 0);
  
  return {
    plan: {
      revenue: calculateTotal(budget.plan, 'revenue'),
      offerings: calculateOfferingsTotal(budget.plan),
      expense: calculateTotal(budget.plan, 'expense')
    },
    actual: {
      revenue: calculateTotal(budget.actual, 'revenue'),
      offerings: calculateOfferingsTotal(budget.actual),
      expense: calculateTotal(budget.actual, 'expense')
    }
  };
};