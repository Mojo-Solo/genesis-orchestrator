import { Metadata } from 'next'
import { Suspense } from 'react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Progress } from '@/components/ui/progress'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Activity,
  Brain,
  Zap,
  Users,
  TrendingUp,
  Clock,
  CheckCircle,
  AlertTriangle,
  BarChart3,
  PieChart,
  Settings,
  Plus,
} from 'lucide-react'

export const metadata: Metadata = {
  title: 'Dashboard - GENESIS Eval Spec',
  description: 'Unified dashboard for GENESIS Eval Spec platform monitoring and management.',
}

// Mock data for demonstration
const systemMetrics = {
  stability: 98.7,
  tokenEfficiency: 23.5,
  responseTime: 187,
  activeUsers: 1247,
  totalProjects: 89,
  completedTasks: 456,
}

const recentActivities = [
  {
    id: 1,
    type: 'lag_execution',
    title: 'LAG Engine completed complex query decomposition',
    description: 'Successfully processed multi-hop reasoning task with 98.9% confidence',
    timestamp: '2 minutes ago',
    status: 'success',
  },
  {
    id: 2,
    type: 'rcr_optimization',
    title: 'RCR Router optimized token usage',
    description: 'Achieved 24.7% token reduction while maintaining quality',
    timestamp: '8 minutes ago',
    status: 'success',
  },
  {
    id: 3,
    type: 'system_alert',
    title: 'Database migration completed',
    description: 'All conflict resolutions successful, schema integrity validated',
    timestamp: '15 minutes ago',
    status: 'success',
  },
  {
    id: 4,
    type: 'user_action',
    title: 'New assessment completed',
    description: 'Business readiness assessment for TechCorp Inc.',
    timestamp: '23 minutes ago',
    status: 'info',
  },
]

const performanceData = {
  lag: {
    stability: 98.7,
    avgExecutionTime: 1.2,
    totalExecutions: 15647,
    successRate: 99.2,
  },
  rcr: {
    tokenReduction: 23.5,
    avgResponseTime: 187,
    routingAccuracy: 97.8,
    totalRoutes: 89234,
  },
}

function DashboardHeader() {
  return (
    <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
      <div>
        <h1 className="text-3xl font-bold tracking-tight">Dashboard</h1>
        <p className="text-muted-foreground">
          Monitor your GENESIS platform performance and manage operations
        </p>
      </div>
      <div className="flex items-center gap-2">
        <Button variant="outline" size="sm">
          <Settings className="mr-2 h-4 w-4" />
          Settings
        </Button>
        <Button size="sm">
          <Plus className="mr-2 h-4 w-4" />
          New Project
        </Button>
      </div>
    </div>
  )
}

function MetricCard({
  title,
  value,
  description,
  icon: Icon,
  trend,
  color = 'default',
}: {
  title: string
  value: string | number
  description: string
  icon: any
  trend?: number
  color?: 'default' | 'success' | 'warning' | 'error'
}) {
  const colorClasses = {
    default: 'text-primary',
    success: 'text-green-600',
    warning: 'text-amber-600',
    error: 'text-red-600',
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
        <CardTitle className="text-sm font-medium">{title}</CardTitle>
        <Icon className={`h-4 w-4 ${colorClasses[color]}`} />
      </CardHeader>
      <CardContent>
        <div className="text-2xl font-bold">{value}</div>
        <p className="text-xs text-muted-foreground">{description}</p>
        {trend !== undefined && (
          <div className="mt-2 flex items-center text-xs">
            <TrendingUp className="mr-1 h-3 w-3 text-green-600" />
            <span className="text-green-600">+{trend}%</span>
            <span className="text-muted-foreground ml-1">from last month</span>
          </div>
        )}
      </CardContent>
    </Card>
  )
}

function ActivityItem({ activity }: { activity: typeof recentActivities[0] }) {
  const statusColors = {
    success: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-100',
    info: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-100',
    warning: 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100',
    error: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-100',
  }

  const statusIcons = {
    success: CheckCircle,
    info: Activity,
    warning: AlertTriangle,
    error: AlertTriangle,
  }

  const StatusIcon = statusIcons[activity.status as keyof typeof statusIcons]

  return (
    <div className="flex items-start space-x-3 p-3 rounded-lg hover:bg-muted/50 transition-colors">
      <div className={`p-1 rounded-full ${statusColors[activity.status as keyof typeof statusColors]}`}>
        <StatusIcon className="h-3 w-3" />
      </div>
      <div className="flex-1 space-y-1">
        <p className="text-sm font-medium">{activity.title}</p>
        <p className="text-xs text-muted-foreground">{activity.description}</p>
        <p className="text-xs text-muted-foreground flex items-center">
          <Clock className="mr-1 h-3 w-3" />
          {activity.timestamp}
        </p>
      </div>
    </div>
  )
}

function LAGPerformancePanel() {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Brain className="h-5 w-5 text-blue-600" />
          LAG Engine Performance
        </CardTitle>
        <CardDescription>
          Logical Answer Generation metrics and stability tracking
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <div className="text-2xl font-bold">{performanceData.lag.stability}%</div>
            <p className="text-sm text-muted-foreground">Stability Score</p>
            <Progress value={performanceData.lag.stability} className="mt-1" />
          </div>
          <div>
            <div className="text-2xl font-bold">{performanceData.lag.avgExecutionTime}s</div>
            <p className="text-sm text-muted-foreground">Avg Execution Time</p>
          </div>
        </div>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <div className="text-lg font-semibold">{performanceData.lag.totalExecutions.toLocaleString()}</div>
            <p className="text-sm text-muted-foreground">Total Executions</p>
          </div>
          <div>
            <div className="text-lg font-semibold">{performanceData.lag.successRate}%</div>
            <p className="text-sm text-muted-foreground">Success Rate</p>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

function RCRPerformancePanel() {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Zap className="h-5 w-5 text-amber-600" />
          RCR Router Performance
        </CardTitle>
        <CardDescription>
          Role-aware Context Routing optimization metrics
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <div className="text-2xl font-bold">{performanceData.rcr.tokenReduction}%</div>
            <p className="text-sm text-muted-foreground">Token Reduction</p>
            <Progress value={performanceData.rcr.tokenReduction} className="mt-1" />
          </div>
          <div>
            <div className="text-2xl font-bold">{performanceData.rcr.avgResponseTime}ms</div>
            <p className="text-sm text-muted-foreground">Avg Response Time</p>
          </div>
        </div>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <div className="text-lg font-semibold">{performanceData.rcr.totalRoutes.toLocaleString()}</div>
            <p className="text-sm text-muted-foreground">Total Routes</p>
          </div>
          <div>
            <div className="text-lg font-semibold">{performanceData.rcr.routingAccuracy}%</div>
            <p className="text-sm text-muted-foreground">Routing Accuracy</p>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

export default function DashboardPage() {
  return (
    <div className="min-h-screen bg-background">
      <div className="container mx-auto px-6 py-8 space-y-8">
        <DashboardHeader />

        {/* Key Metrics */}
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <MetricCard
            title="System Stability"
            value={`${systemMetrics.stability}%`}
            description="Current stability score"
            icon={CheckCircle}
            trend={2.1}
            color="success"
          />
          <MetricCard
            title="Token Efficiency"
            value={`${systemMetrics.tokenEfficiency}%`}
            description="Reduction in token usage"
            icon={Zap}
            trend={5.3}
            color="warning"
          />
          <MetricCard
            title="Response Time"
            value={`${systemMetrics.responseTime}ms`}
            description="Average API response time"
            icon={Clock}
            trend={-8.2}
            color="success"
          />
          <MetricCard
            title="Active Users"
            value={systemMetrics.activeUsers.toLocaleString()}
            description="Currently active users"
            icon={Users}
            trend={12.5}
            color="default"
          />
        </div>

        {/* Main Content Tabs */}
        <Tabs defaultValue="overview" className="space-y-4">
          <TabsList>
            <TabsTrigger value="overview">Overview</TabsTrigger>
            <TabsTrigger value="lag-performance">LAG Performance</TabsTrigger>
            <TabsTrigger value="rcr-metrics">RCR Metrics</TabsTrigger>
            <TabsTrigger value="business-intelligence">Business Intelligence</TabsTrigger>
          </TabsList>

          <TabsContent value="overview" className="space-y-4">
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-7">
              {/* Recent Activity */}
              <Card className="col-span-4">
                <CardHeader>
                  <CardTitle>Recent Activity</CardTitle>
                  <CardDescription>Latest system events and user actions</CardDescription>
                </CardHeader>
                <CardContent>
                  <div className="space-y-2 max-h-96 overflow-y-auto">
                    {recentActivities.map((activity) => (
                      <ActivityItem key={activity.id} activity={activity} />
                    ))}
                  </div>
                </CardContent>
              </Card>

              {/* Quick Stats */}
              <div className="col-span-3 space-y-4">
                <Card>
                  <CardHeader>
                    <CardTitle className="text-sm font-medium">Projects Overview</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="text-2xl font-bold">{systemMetrics.totalProjects}</div>
                    <p className="text-xs text-muted-foreground">Active projects</p>
                    <div className="mt-4 space-y-2">
                      <div className="flex items-center justify-between text-sm">
                        <span>Completed</span>
                        <span>67%</span>
                      </div>
                      <Progress value={67} />
                    </div>
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader>
                    <CardTitle className="text-sm font-medium">Task Completion</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="text-2xl font-bold">{systemMetrics.completedTasks}</div>
                    <p className="text-xs text-muted-foreground">Tasks completed this week</p>
                    <div className="mt-4 space-y-2">
                      <div className="flex items-center justify-between text-sm">
                        <span>Progress</span>
                        <span>89%</span>
                      </div>
                      <Progress value={89} />
                    </div>
                  </CardContent>
                </Card>
              </div>
            </div>
          </TabsContent>

          <TabsContent value="lag-performance" className="space-y-4">
            <div className="grid gap-4 md:grid-cols-2">
              <LAGPerformancePanel />
              <Card>
                <CardHeader>
                  <CardTitle>Execution History</CardTitle>
                  <CardDescription>LAG execution trends over time</CardDescription>
                </CardHeader>
                <CardContent>
                  <div className="h-[300px] flex items-center justify-center text-muted-foreground">
                    <div className="text-center">
                      <BarChart3 className="h-12 w-12 mx-auto mb-2 opacity-50" />
                      <p>Execution history chart will be rendered here</p>
                    </div>
                  </div>
                </CardContent>
              </Card>
            </div>
          </TabsContent>

          <TabsContent value="rcr-metrics" className="space-y-4">
            <div className="grid gap-4 md:grid-cols-2">
              <RCRPerformancePanel />
              <Card>
                <CardHeader>
                  <CardTitle>Token Usage Trends</CardTitle>
                  <CardDescription>Token optimization over time</CardDescription>
                </CardHeader>
                <CardContent>
                  <div className="h-[300px] flex items-center justify-center text-muted-foreground">
                    <div className="text-center">
                      <PieChart className="h-12 w-12 mx-auto mb-2 opacity-50" />
                      <p>Token usage visualization will be rendered here</p>
                    </div>
                  </div>
                </CardContent>
              </Card>
            </div>
          </TabsContent>

          <TabsContent value="business-intelligence" className="space-y-4">
            <div className="grid gap-4 md:grid-cols-3">
              <Card>
                <CardHeader>
                  <CardTitle className="text-sm font-medium">Assessment Completion Rate</CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="text-2xl font-bold">94.7%</div>
                  <p className="text-xs text-muted-foreground">This quarter</p>
                  <Progress value={94.7} className="mt-2" />
                </CardContent>
              </Card>
              
              <Card>
                <CardHeader>
                  <CardTitle className="text-sm font-medium">Average Readiness Score</CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="text-2xl font-bold">0.78</div>
                  <p className="text-xs text-muted-foreground">Across all assessments</p>
                  <Badge variant="secondary" className="mt-2">Moderately Ready</Badge>
                </CardContent>
              </Card>
              
              <Card>
                <CardHeader>
                  <CardTitle className="text-sm font-medium">AI Insights Generated</CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="text-2xl font-bold">1,247</div>
                  <p className="text-xs text-muted-foreground">This month</p>
                  <div className="mt-2 flex items-center text-xs text-green-600">
                    <TrendingUp className="mr-1 h-3 w-3" />
                    +18.3% from last month
                  </div>
                </CardContent>
              </Card>
            </div>
          </TabsContent>
        </Tabs>
      </div>
    </div>
  )
}

// Loading component for Suspense boundaries
function DashboardSkeleton() {
  return (
    <div className="container mx-auto px-6 py-8 space-y-8">
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <Skeleton className="h-8 w-48" />
          <Skeleton className="h-4 w-96 mt-2" />
        </div>
        <div className="flex items-center gap-2">
          <Skeleton className="h-9 w-20" />
          <Skeleton className="h-9 w-28" />
        </div>
      </div>
      
      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Card key={i}>
            <CardHeader>
              <Skeleton className="h-4 w-32" />
            </CardHeader>
            <CardContent>
              <Skeleton className="h-8 w-16" />
              <Skeleton className="h-3 w-24 mt-1" />
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  )
}