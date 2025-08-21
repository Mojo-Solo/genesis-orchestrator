export type Quarter = 'Q1' | 'Q2' | 'Q3' | 'Q4';
export type Status = 'Not Started' | 'On Target' | 'At Risk' | 'Off Track' | 'Blocked' | 'Done';

export interface Client { 
  id: string; 
  name: string; 
}

export interface Persona { 
  id: string; 
  clientId: string; 
  tone: string; 
  goals: string[]; 
}

export interface Initiative {
  id: string; 
  idx: 1|2|3|4; 
  clientId: string; 
  title: string; 
  description: string;
  approved: boolean; 
  year: number; 
  owner: string;
  draft?: string;
  suggestion?: string;
}

export interface Project {
  id: string; 
  quarter: Quarter; 
  initiativeId: string; 
  title: string; 
  owner: string;
  weekly: { 
    week: number; 
    percent: number; 
    status: Status; 
    note?: string 
  }[]; // 13 weeks
  issues?: string;
  nextActions?: string;
}

export interface BudgetMonth { 
  month: string; 
  revenue: number; 
  offerings: number[]; 
  expense: number; 
}

export interface BudgetPlan { 
  year: number; 
  plan: BudgetMonth[]; 
  actual: BudgetMonth[]; 
}

export interface SpmsRecord { 
  clientId: string; 
  period: string; 
  category: string; 
  value: number; 
  status: Status; 
}

export interface Note { 
  clientId: string; 
  text: string; 
  createdAt: string; 
}

export interface VisionSection {
  people: string;
  salesMarketing: string;
  geography: string;
  offerings: string;
  impact: string;
}

export interface GaugeData {
  label: string;
  value: number;
  min?: number;
  max?: number;
  unit?: string;
}

export interface BarChartData {
  category: string;
  budget: number;
  actual: number;
}

export interface AppState {
  vision: VisionSection;
  budget: BudgetPlan;
  initiatives: Initiative[];
  projects: Project[];
  journal: {
    prompt: string;
    summary: string;
  };
  gauges: GaugeData[];
}