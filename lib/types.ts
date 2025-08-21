export type Quarter = "Q1" | "Q2" | "Q3" | "Q4";
export type Status = "Not Started" | "On Target" | "At Risk" | "Off Track" | "Blocked" | "Done";

export interface BudgetMonth { month: string; revenue: number; offerings: number[]; expense: number; }
export interface BudgetPlan { year: number; plan: BudgetMonth[]; actual: BudgetMonth[]; }

export interface Initiative { id: string; idx: 1|2|3|4; clientId: string; title: string; description: string; year: number; owner: string; approved: boolean; }

export interface ProjectWeek { week: number; percent: number; status: Status; }
export interface ProjectGrid { [Q in Quarter]: ProjectWeek[] } // length 13 per quarter

export interface AppVision { sections: { people: string; sales: string; geo: string; offerings: string; impact: string; }; peopleScore: number; salesScore: number; geoScore: number; offeringsScore: number; impactScore: number; initiativesSpectrum: { label: string; fill: number }[]; projectsSpectrum: { label: string; fill: number }[]; }

export interface AppState {
  budget: BudgetPlan;
  initiatives: Initiative[];
  projects: ProjectGrid;
  vision: AppVision;
  setVision: (k: keyof AppVision["sections"], value: string) => void;
  updateInitiative: (id: string, patch: Partial<Initiative>) => void;
  addProject: (p: { year: number; quarter: Quarter; initiativeId: string; owner: string; title: string }) => void;
  updateProjectWeek: (q: Quarter, index: number, patch: Partial<ProjectWeek>) => void;
  resetDemo: () => void;
}