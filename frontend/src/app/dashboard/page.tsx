'use client';

import React, { useState, useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { 
  Calendar, 
  Users, 
  MessageSquare, 
  TrendingUp, 
  Clock, 
  CheckCircle,
  AlertCircle,
  BarChart3,
  Brain,
  Zap,
  Activity
} from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { RealtimeChart } from '@/components/dashboard/realtime-chart';
import { MeetingInsights } from '@/components/dashboard/meeting-insights';
import { ActionItemsWidget } from '@/components/dashboard/action-items-widget';
import { WorkflowStatus } from '@/components/dashboard/workflow-status';
import { AIInsightsPanel } from '@/components/dashboard/ai-insights-panel';
import { useAuth } from '@/hooks/use-auth';
import { useDashboard } from '@/hooks/use-dashboard';
import { useRealtime } from '@/hooks/use-realtime';

/**
 * AI-Enhanced Project Management Dashboard
 * 
 * Main dashboard interface featuring real-time analytics, AI insights,
 * meeting analysis, and autonomous workflow management.
 */
export default function Dashboard() {
  const { user, tenant } = useAuth();
  const { 
    dashboardData, 
    isLoading, 
    error,
    refreshDashboard 
  } = useDashboard();
  const { 
    isConnected, 
    realtimeMetrics, 
    activeMeetings 
  } = useRealtime();

  const [selectedTimeRange, setSelectedTimeRange] = useState('7d');
  const [activeTab, setActiveTab] = useState('overview');

  if (isLoading) {
    return <DashboardSkeleton />;
  }

  if (error) {
    return <DashboardError error={error} onRetry={refreshDashboard} />;
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100 dark:from-slate-900 dark:to-slate-800">
      {/* Header */}
      <div className="sticky top-0 z-40 bg-white/80 backdrop-blur-md dark:bg-slate-900/80 border-b border-slate-200 dark:border-slate-700">
        <div className="flex h-16 items-center justify-between px-6">
          <div className="flex items-center space-x-4">
            <Brain className="h-8 w-8 text-blue-600" />
            <div>
              <h1 className="text-xl font-bold text-slate-900 dark:text-white">
                AI Project Management
              </h1>
              <p className="text-sm text-slate-500 dark:text-slate-400">
                {tenant?.name} • {user?.role}
              </p>
            </div>
          </div>
          
          <div className="flex items-center space-x-4">
            <div className="flex items-center space-x-2">
              <div className={`h-2 w-2 rounded-full ${isConnected ? 'bg-green-500' : 'bg-red-500'}`} />
              <span className="text-sm text-slate-600 dark:text-slate-300">
                {isConnected ? 'Connected' : 'Disconnected'}
              </span>
            </div>
            
            <TimeRangeSelector 
              value={selectedTimeRange}
              onChange={setSelectedTimeRange}
            />
            
            <Button onClick={refreshDashboard} size="sm">
              <Activity className="h-4 w-4 mr-2" />
              Refresh
            </Button>
          </div>
        </div>
      </div>

      {/* Main Content */}
      <div className="p-6 space-y-6">
        {/* KPI Cards */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          <MetricCard
            title="Total Meetings"
            value={dashboardData?.metrics?.totalMeetings || 0}
            change={dashboardData?.metrics?.meetingsChange || 0}
            icon={<Calendar className="h-5 w-5" />}
            color="blue"
          />
          
          <MetricCard
            title="Active Actions"
            value={dashboardData?.metrics?.activeActions || 0}
            change={dashboardData?.metrics?.actionsChange || 0}
            icon={<CheckCircle className="h-5 w-5" />}
            color="green"
          />
          
          <MetricCard
            title="AI Insights"
            value={dashboardData?.metrics?.aiInsights || 0}
            change={dashboardData?.metrics?.insightsChange || 0}
            icon={<Brain className="h-5 w-5" />}
            color="purple"
          />
          
          <MetricCard
            title="Workflows"
            value={dashboardData?.metrics?.activeWorkflows || 0}
            change={dashboardData?.metrics?.workflowsChange || 0}
            icon={<Zap className="h-5 w-5" />}
            color="orange"
          />
        </div>

        {/* Real-time Activity */}
        {(activeMeetings?.length > 0 || realtimeMetrics) && (
          <Card className="border-blue-200 bg-blue-50/50 dark:bg-blue-900/20">
            <CardHeader>
              <CardTitle className="flex items-center space-x-2">
                <Activity className="h-5 w-5 text-blue-600" />
                <span>Live Activity</span>
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                {activeMeetings?.length > 0 && (
                  <div>
                    <h4 className="font-medium mb-2">Active Meetings</h4>
                    <div className="space-y-2">
                      {activeMeetings.map((meeting) => (
                        <div key={meeting.id} className="flex items-center justify-between p-3 bg-white dark:bg-slate-800 rounded-lg">
                          <div>
                            <p className="font-medium">{meeting.title}</p>
                            <p className="text-sm text-slate-500">
                              {meeting.participantCount} participants • {meeting.duration}
                            </p>
                          </div>
                          <Badge variant="secondary" className="bg-green-100 text-green-700">
                            Live
                          </Badge>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
                
                {realtimeMetrics && (
                  <div>
                    <h4 className="font-medium mb-2">Real-time Metrics</h4>
                    <div className="space-y-3">
                      <div className="flex justify-between items-center">
                        <span className="text-sm">Processing Queue</span>
                        <div className="flex items-center space-x-2">
                          <Progress value={realtimeMetrics.queueProgress} className="w-20" />
                          <span className="text-sm">{realtimeMetrics.queueSize}</span>
                        </div>
                      </div>
                      <div className="flex justify-between items-center">
                        <span className="text-sm">AI Response Time</span>
                        <span className="text-sm font-medium">{realtimeMetrics.avgResponseTime}ms</span>
                      </div>
                    </div>
                  </div>
                )}
              </div>
            </CardContent>
          </Card>
        )}

        {/* Main Dashboard Tabs */}
        <Tabs value={activeTab} onValueChange={setActiveTab}>
          <TabsList className="grid w-full grid-cols-5">
            <TabsTrigger value="overview">Overview</TabsTrigger>
            <TabsTrigger value="meetings">Meetings</TabsTrigger>
            <TabsTrigger value="actions">Actions</TabsTrigger>
            <TabsTrigger value="workflows">Workflows</TabsTrigger>
            <TabsTrigger value="insights">AI Insights</TabsTrigger>
          </TabsList>

          <TabsContent value="overview" className="space-y-6">
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              {/* Analytics Chart */}
              <Card className="lg:col-span-2">
                <CardHeader>
                  <CardTitle>Meeting & Activity Analytics</CardTitle>
                  <CardDescription>
                    Real-time insights into your team's meeting patterns and productivity
                  </CardDescription>
                </CardHeader>
                <CardContent>
                  <RealtimeChart 
                    data={dashboardData?.analytics?.timeSeriesData}
                    timeRange={selectedTimeRange}
                    metrics={['meetings', 'actions', 'insights']}
                  />
                </CardContent>
              </Card>
              
              {/* Recent Meeting Insights */}
              <MeetingInsights 
                meetings={dashboardData?.recentMeetings}
                insights={dashboardData?.recentInsights}
              />
              
              {/* Action Items Summary */}
              <ActionItemsWidget 
                actionItems={dashboardData?.actionItems}
                onActionUpdate={refreshDashboard}
              />
            </div>
          </TabsContent>

          <TabsContent value="meetings" className="space-y-6">
            <MeetingsOverview 
              meetings={dashboardData?.meetings}
              analytics={dashboardData?.meetingAnalytics}
              timeRange={selectedTimeRange}
            />
          </TabsContent>

          <TabsContent value="actions" className="space-y-6">
            <ActionsOverview 
              actions={dashboardData?.actions}
              analytics={dashboardData?.actionAnalytics}
              onUpdate={refreshDashboard}
            />
          </TabsContent>

          <TabsContent value="workflows" className="space-y-6">
            <WorkflowsOverview 
              workflows={dashboardData?.workflows}
              executions={dashboardData?.workflowExecutions}
              onUpdate={refreshDashboard}
            />
          </TabsContent>

          <TabsContent value="insights" className="space-y-6">
            <AIInsightsPanel 
              insights={dashboardData?.aiInsights}
              analytics={dashboardData?.insightAnalytics}
              tenant={tenant}
            />
          </TabsContent>
        </Tabs>
      </div>
    </div>
  );
}

/**
 * Metric Card Component
 */
function MetricCard({ 
  title, 
  value, 
  change, 
  icon, 
  color 
}: {
  title: string;
  value: number;
  change: number;
  icon: React.ReactNode;
  color: 'blue' | 'green' | 'purple' | 'orange';
}) {
  const colorClasses = {
    blue: 'text-blue-600 bg-blue-100 dark:bg-blue-900/20',
    green: 'text-green-600 bg-green-100 dark:bg-green-900/20',
    purple: 'text-purple-600 bg-purple-100 dark:bg-purple-900/20',
    orange: 'text-orange-600 bg-orange-100 dark:bg-orange-900/20',
  };

  return (
    <Card>
      <CardContent className="p-6">
        <div className="flex items-center justify-between">
          <div>
            <p className="text-sm font-medium text-slate-600 dark:text-slate-400">
              {title}
            </p>
            <div className="flex items-center space-x-2">
              <p className="text-2xl font-bold text-slate-900 dark:text-white">
                {value.toLocaleString()}
              </p>
              {change !== 0 && (
                <Badge 
                  variant={change > 0 ? "default" : "destructive"}
                  className="text-xs"
                >
                  {change > 0 ? '+' : ''}{change}%
                </Badge>
              )}
            </div>
          </div>
          <div className={`p-2 rounded-full ${colorClasses[color]}`}>
            {icon}
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

/**
 * Time Range Selector Component
 */
function TimeRangeSelector({ 
  value, 
  onChange 
}: {
  value: string;
  onChange: (value: string) => void;
}) {
  const options = [
    { value: '24h', label: '24 Hours' },
    { value: '7d', label: '7 Days' },
    { value: '30d', label: '30 Days' },
    { value: '90d', label: '90 Days' },
  ];

  return (
    <div className="flex items-center space-x-1 bg-slate-100 dark:bg-slate-800 rounded-lg p-1">
      {options.map((option) => (
        <button
          key={option.value}
          onClick={() => onChange(option.value)}
          className={`px-3 py-1 text-sm rounded-md transition-colors ${
            value === option.value
              ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-sm'
              : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'
          }`}
        >
          {option.label}
        </button>
      ))}
    </div>
  );
}

/**
 * Dashboard Loading Skeleton
 */
function DashboardSkeleton() {
  return (
    <div className="min-h-screen bg-slate-50 dark:bg-slate-900 p-6">
      <div className="space-y-6">
        <div className="h-8 w-64 bg-slate-200 dark:bg-slate-700 rounded animate-pulse" />
        
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="h-32 bg-slate-200 dark:bg-slate-700 rounded-lg animate-pulse" />
          ))}
        </div>
        
        <div className="h-96 bg-slate-200 dark:bg-slate-700 rounded-lg animate-pulse" />
      </div>
    </div>
  );
}

/**
 * Dashboard Error Component
 */
function DashboardError({ 
  error, 
  onRetry 
}: {
  error: Error;
  onRetry: () => void;
}) {
  return (
    <div className="min-h-screen bg-slate-50 dark:bg-slate-900 flex items-center justify-center">
      <Card className="w-full max-w-md">
        <CardContent className="p-6 text-center">
          <AlertCircle className="h-12 w-12 text-red-500 mx-auto mb-4" />
          <h3 className="text-lg font-semibold mb-2">Dashboard Error</h3>
          <p className="text-slate-600 dark:text-slate-400 mb-4">
            {error.message || 'Failed to load dashboard data'}
          </p>
          <Button onClick={onRetry}>
            Try Again
          </Button>
        </CardContent>
      </Card>
    </div>
  );
}

// Import placeholder components (would be implemented separately)
import { MeetingsOverview } from '@/components/dashboard/meetings-overview';
import { ActionsOverview } from '@/components/dashboard/actions-overview';
import { WorkflowsOverview } from '@/components/dashboard/workflows-overview';