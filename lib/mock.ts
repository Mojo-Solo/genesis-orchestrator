import type { AppState, BudgetPlan, Initiative, ProjectGrid, ProjectWeek } from "@/lib/types";

const months = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];

function genYearPlan(): BudgetPlan {
  const plan = months.map((m, i) => ({ month: m, revenue: [150,150,150,150,150,150,200,200,200,300,300,300][i]*1000, offerings: [100,100,30,20].slice(0,3).map(v=>v*1000), expense: [50,50,50,50,50,50,50,50,50,100,100,100][i]*1000 }));
  const actual = months.map((m, i) => ({ month: m, revenue: [120,115,145,145,114,155,155,155,225,275,225,0][i]*1000 || 0, offerings: [115,115,15,5].slice(0,3).map(v=>v*1000), expense: [45,42,58,52,48,47,56,51,80,102,101,0][i]*1000 || 0 }));
  return { year: 2025, plan, actual };
}

function weeks(): ProjectWeek[] { return Array.from({ length: 13 }, (_, i) => ({ week: i+1, percent: i < 2 ? (i===0?10:20) : 0, status: i<2?"On Target":"Not Started" })); }

export const seedState: Pick<AppState, "budget"|"initiatives"|"projects"|"vision"> = {
  budget: genYearPlan(),
  initiatives: [
    { id: "i1", idx: 1, clientId: "c1", title: "Launch Product CODA", description: "", year: 2025, owner: "Kyle", approved: true },
    { id: "i2", idx: 2, clientId: "c1", title: "Secure Grants", description: "", year: 2025, owner: "Kyle", approved: true },
    { id: "i3", idx: 3, clientId: "c1", title: "Build Basecamp", description: "", year: 2025, owner: "Sam", approved: true },
    { id: "i4", idx: 4, clientId: "c1", title: "Experience Matters", description: "", year: 2025, owner: "Joe", approved: true },
  ],
  projects: { Q1: weeks(), Q2: weeks(), Q3: weeks(), Q4: weeks() },
  vision: {
    sections: { people: "", sales: "", geo: "", offerings: "", impact: "" },
    peopleScore: 22.5, salesScore: 22.7, geoScore: 20.0, offeringsScore: 16.5, impactScore: 17.8,
    initiativesSpectrum: [
      { label: "Schedule", fill: 60 },
      { label: "Resources", fill: 40 },
      { label: "Implement", fill: 55 },
      { label: "Training", fill: 35 },
      { label: "Rollout", fill: 45 },
    ],
    projectsSpectrum: [
      { label: "Schedule", fill: 50 },
      { label: "Resources", fill: 65 },
      { label: "Implement", fill: 40 },
      { label: "Training", fill: 55 },
      { label: "Rollout", fill: 30 },
    ],
  },
};