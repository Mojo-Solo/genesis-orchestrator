// GENESIS Business Components
// Specialized components for business intelligence and planning
// Migrated and enhanced from cothinkr/ and app/ implementations

// AI Assistant Components (from cothinkr CothinkrBot)
export { AIAssistant } from './ai-assistant'
export { AIChat } from './ai-chat'
export { AIInsights } from './ai-insights'

// Chart Components (enhanced from multiple sources)
export { BarChart } from './charts/bar-chart'
export { BudgetChart } from './charts/budget-chart'
export { GaugeChart } from './charts/gauge-chart'
export { SparklineChart } from './charts/sparkline-chart'
export { GanttChart } from './charts/gantt-chart'
export { AnalyticsChart } from './charts/analytics-chart'

// Planning Components (from app/ implementation)
export { InitiativeCard } from './planning/initiative-card'
export { ProjectComposer } from './planning/project-composer'
export { StrategicPlan } from './planning/strategic-plan'
export { VisionBoard } from './planning/vision-board'

// Data Management Components
export { BudgetTable } from './tables/budget-table'
export { ProjectStatusTable } from './tables/project-status-table'
export { MetricsTable } from './tables/metrics-table'

// Workflow Components
export { FileDropzone } from './workflow/file-dropzone'
export { ExportManager } from './workflow/export-manager'
export { NotificationCenter } from './workflow/notification-center'
export { ActivityFeed } from './workflow/activity-feed'

// Dashboard Components
export { DashboardHeader } from './dashboard/dashboard-header'
export { DashboardStats } from './dashboard/dashboard-stats'
export { DashboardGrid } from './dashboard/dashboard-grid'
export { KPICard } from './dashboard/kpi-card'

// Collaboration Components
export { CommentThread } from './collaboration/comment-thread'
export { UserPresence } from './collaboration/user-presence'
export { ShareDialog } from './collaboration/share-dialog'
export { VersionHistory } from './collaboration/version-history'

// Form Components (business-specific)
export { AssessmentForm } from './forms/assessment-form'
export { BudgetForm } from './forms/budget-form'
export { ProjectForm } from './forms/project-form'
export { InitiativeForm } from './forms/initiative-form'

// Type Definitions
export type {
  AIAssistantProps,
  ChartDataPoint,
  InitiativeData,
  ProjectData,
  BudgetData,
  MetricsData,
  DashboardConfig,
  CollaborationEvent,
  AssessmentData,
} from './types'