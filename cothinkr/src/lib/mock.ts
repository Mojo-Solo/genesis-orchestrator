import { 
  AppState, 
  Initiative, 
  Project, 
  BudgetPlan, 
  VisionSection, 
  GaugeData, 
  Quarter,
  Status,
  BudgetMonth 
} from './types';

// Mock gauge data for dashboard
export const mockGauges: GaugeData[] = [
  { label: 'People', value: 22.5, min: 0, max: 100, unit: '°' },
  { label: 'Sales & Mktg', value: 45.8, min: 0, max: 100, unit: '°' },
  { label: 'Geo & Locs', value: 67.2, min: 0, max: 100, unit: '°' },
  { label: 'Offerings', value: 38.9, min: 0, max: 100, unit: '°' },
  { label: 'Impact', value: 78.4, min: 0, max: 100, unit: '°' },
];

// Mock vision data
export const mockVision: VisionSection = {
  people: 'Build a diverse, high-performing team of 50+ employees across key functions, fostering innovation and collaboration while maintaining our core values of integrity and excellence.',
  salesMarketing: 'Establish market leadership in our core segments with $10M+ ARR, implementing data-driven growth strategies and multi-channel customer acquisition.',
  geography: 'Expand operations to 5 major metropolitan markets, with strong local partnerships and remote-first culture supporting nationwide reach.',
  offerings: 'Deliver comprehensive suite of premium services with 95%+ customer satisfaction, continuous innovation, and clear value proposition differentiation.',
  impact: 'Create measurable positive impact for 1000+ clients, contributing to community development and sustainable business practices with transparent reporting.'
};

// Mock budget data matching screenshot structure
const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const createBudgetMonth = (month: string, baseRevenue: number, variance: number = 0): BudgetMonth => ({
  month,
  revenue: baseRevenue + variance,
  offerings: [baseRevenue * 0.15, baseRevenue * 0.10, baseRevenue * 0.05], // COGS breakdown
  expense: baseRevenue * 0.60 + (variance * 0.2) // Operating expenses
});

export const mockBudget: BudgetPlan = {
  year: 2024,
  plan: months.map((month, i) => createBudgetMonth(month, 85000 + (i * 2000))), // Growing plan
  actual: months.map((month, i) => createBudgetMonth(month, 88000 + (i * 2200), Math.random() * 4000 - 2000)) // Actual with variance
};

// Mock initiatives data (4 initiatives as shown in screenshots)
export const mockInitiatives: Initiative[] = [
  {
    id: 'init-1',
    idx: 1,
    clientId: 'client-1',
    title: 'Digital Transformation Initiative',
    description: 'Transform core business processes through technology adoption and digital workflow optimization.',
    approved: true,
    year: 2024,
    owner: 'Sarah Chen',
    draft: '',
    suggestion: ''
  },
  {
    id: 'init-2',
    idx: 2,
    clientId: 'client-1',
    title: 'Market Expansion Strategy',
    description: 'Expand into new geographic markets and customer segments to drive revenue growth.',
    approved: true,
    year: 2024,
    owner: 'Mike Rodriguez',
    draft: '',
    suggestion: ''
  },
  {
    id: 'init-3',
    idx: 3,
    clientId: 'client-1',
    title: 'Operational Excellence Program',
    description: 'Implement lean methodologies and process improvements to enhance efficiency.',
    approved: false,
    year: 2024,
    owner: 'Lisa Park',
    draft: 'Streamline operations for better efficiency',
    suggestion: ''
  },
  {
    id: 'init-4',
    idx: 4,
    clientId: 'client-1',
    title: 'Innovation & Product Development',
    description: 'Develop new products and services to meet evolving market demands.',
    approved: false,
    year: 2024,
    owner: 'David Kim',
    draft: 'Create innovative solutions for market needs',
    suggestion: ''
  }
];

// Helper function to generate weekly status data
const generateWeeklyData = (basePercent: number, statusDistribution: Status[]) => {
  return Array.from({ length: 13 }, (_, i) => ({
    week: i + 1,
    percent: Math.min(100, basePercent + (i * 7) + Math.random() * 10),
    status: statusDistribution[i % statusDistribution.length],
    note: Math.random() > 0.7 ? 'Review needed' : undefined
  }));
};

// Mock projects data with Q1-Q4 distribution
export const mockProjects: Project[] = [
  // Q1 Projects
  {
    id: 'proj-q1-1',
    quarter: 'Q1',
    initiativeId: 'init-1',
    title: 'CRM System Implementation',
    owner: 'Sarah Chen',
    weekly: generateWeeklyData(15, ['Not Started', 'On Target', 'On Target']),
    issues: 'Data migration complexity higher than expected',
    nextActions: 'Complete vendor assessment and data mapping'
  },
  {
    id: 'proj-q1-2',
    quarter: 'Q1',
    initiativeId: 'init-2',
    title: 'East Coast Market Research',
    owner: 'Mike Rodriguez',
    weekly: generateWeeklyData(25, ['On Target', 'On Target', 'At Risk']),
    issues: 'Delayed survey responses from target demographics',
    nextActions: 'Expand outreach channels and incentive programs'
  },
  
  // Q2 Projects
  {
    id: 'proj-q2-1',
    quarter: 'Q2',
    initiativeId: 'init-1',
    title: 'Process Automation Pilot',
    owner: 'Sarah Chen',
    weekly: generateWeeklyData(40, ['On Target', 'On Target', 'Done']),
    issues: 'Integration challenges with legacy systems',
    nextActions: 'Finalize API specifications and testing protocols'
  },
  {
    id: 'proj-q2-2',
    quarter: 'Q2',
    initiativeId: 'init-3',
    title: 'Lean Manufacturing Rollout',
    owner: 'Lisa Park',
    weekly: generateWeeklyData(60, ['At Risk', 'On Target', 'On Target']),
    issues: 'Training schedule conflicts with production demands',
    nextActions: 'Adjust training timeline and resource allocation'
  },

  // Q3 Projects
  {
    id: 'proj-q3-1',
    quarter: 'Q3',
    initiativeId: 'init-2',
    title: 'West Coast Partnership Development',
    owner: 'Mike Rodriguez',
    weekly: generateWeeklyData(30, ['Not Started', 'At Risk', 'On Target']),
    issues: 'Partner vetting process taking longer than anticipated',
    nextActions: 'Streamline due diligence and accelerate negotiations'
  },
  {
    id: 'proj-q3-2',
    quarter: 'Q3',
    initiativeId: 'init-4',
    title: 'Product MVP Development',
    owner: 'David Kim',
    weekly: generateWeeklyData(50, ['On Target', 'On Target', 'Off Track']),
    issues: 'Technical architecture requires significant revision',
    nextActions: 'Conduct technical review and revise development roadmap'
  },

  // Q4 Projects
  {
    id: 'proj-q4-1',
    quarter: 'Q4',
    initiativeId: 'init-1',
    title: 'Digital Platform Launch',
    owner: 'Sarah Chen',
    weekly: generateWeeklyData(10, ['Not Started', 'Not Started', 'At Risk']),
    issues: 'Dependent on Q2 and Q3 deliverables completion',
    nextActions: 'Define launch criteria and communication strategy'
  },
  {
    id: 'proj-q4-2',
    quarter: 'Q4',
    initiativeId: 'init-4',
    title: 'Market Validation & Launch',
    owner: 'David Kim',
    weekly: generateWeeklyData(20, ['Not Started', 'Not Started', 'On Target']),
    issues: 'Market readiness assessment pending',
    nextActions: 'Complete competitive analysis and pricing strategy'
  }
];

// Mock application state
export const createMockAppState = (): AppState => ({
  vision: mockVision,
  budget: mockBudget,
  initiatives: mockInitiatives,
  projects: mockProjects,
  journal: {
    prompt: '',
    summary: 'Enter a journal prompt to generate strategic insights and recommendations.'
  },
  gauges: mockGauges
});

// Progress bands data for dashboard
export const mockProgressBands = {
  initiatives: [
    { label: 'Schedule', progress: 75, color: 'bg-blue-500' },
    { label: 'Resources', progress: 60, color: 'bg-green-500' },
    { label: 'Implement', progress: 45, color: 'bg-yellow-500' },
    { label: 'Training', progress: 30, color: 'bg-orange-500' },
    { label: 'Rollout', progress: 15, color: 'bg-red-500' }
  ],
  projects: [
    { label: 'Planning', progress: 85, color: 'bg-blue-500' },
    { label: 'Development', progress: 70, color: 'bg-green-500' },
    { label: 'Testing', progress: 55, color: 'bg-yellow-500' },
    { label: 'Deployment', progress: 40, color: 'bg-orange-500' },
    { label: 'Monitoring', progress: 25, color: 'bg-red-500' }
  ]
};