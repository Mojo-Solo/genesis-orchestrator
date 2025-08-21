'use client';

import { useState, useEffect } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useAuth } from './use-auth';
import { dashboardApi } from '@/lib/api/dashboard';

export interface DashboardMetrics {
  totalMeetings: number;
  meetingsChange: number;
  activeActions: number;
  actionsChange: number;
  aiInsights: number;
  insightsChange: number;
  activeWorkflows: number;
  workflowsChange: number;
}

export interface DashboardData {
  metrics: DashboardMetrics;
  analytics: {
    timeSeriesData: Array<{
      date: string;
      meetings: number;
      actions: number;
      insights: number;
    }>;
  };
  recentMeetings: Array<{
    id: string;
    title: string;
    date: string;
    participantCount: number;
    duration: number;
    status: string;
    insights: number;
  }>;
  recentInsights: Array<{
    id: string;
    type: string;
    title: string;
    confidence: number;
    generated_at: string;
  }>;
  actionItems: Array<{
    id: string;
    description: string;
    priority: 'low' | 'medium' | 'high' | 'urgent';
    status: 'open' | 'in_progress' | 'completed' | 'cancelled';
    assignee?: string;
    due_date?: string;
    created_at: string;
  }>;
  meetings?: any[];
  meetingAnalytics?: any;
  actions?: any[];
  actionAnalytics?: any;
  workflows?: any[];
  workflowExecutions?: any[];
  aiInsights?: any[];
  insightAnalytics?: any;
}

/**
 * Dashboard Data Hook
 * 
 * Manages dashboard data fetching, caching, and real-time updates
 */
export function useDashboard(timeRange: string = '7d') {
  const { tenant, user } = useAuth();
  const queryClient = useQueryClient();
  const [lastRefresh, setLastRefresh] = useState<Date | null>(null);

  // Main dashboard query
  const {
    data: dashboardData,
    isLoading,
    error,
    refetch
  } = useQuery({
    queryKey: ['dashboard', tenant?.id, timeRange],
    queryFn: () => dashboardApi.getDashboardData(timeRange),
    enabled: !!tenant?.id,
    refetchInterval: 30000, // Refresh every 30 seconds
    staleTime: 15000, // Consider data stale after 15 seconds
  });

  // Metrics summary query
  const {
    data: metricsData,
    isLoading: metricsLoading
  } = useQuery({
    queryKey: ['dashboard-metrics', tenant?.id, timeRange],
    queryFn: () => dashboardApi.getMetrics(timeRange),
    enabled: !!tenant?.id,
    refetchInterval: 15000, // More frequent refresh for metrics
  });

  // Real-time updates query
  const {
    data: realtimeUpdates,
    isLoading: realtimeLoading
  } = useQuery({
    queryKey: ['dashboard-realtime', tenant?.id],
    queryFn: () => dashboardApi.getRealtimeUpdates(),
    enabled: !!tenant?.id,
    refetchInterval: 5000, // Very frequent for real-time data
  });

  // Manual refresh function
  const refreshDashboard = async () => {
    setLastRefresh(new Date());
    
    // Invalidate all dashboard-related queries
    await queryClient.invalidateQueries({ 
      queryKey: ['dashboard', tenant?.id] 
    });
    
    // Force refetch
    await Promise.all([
      refetch(),
      queryClient.invalidateQueries({ queryKey: ['dashboard-metrics'] }),
      queryClient.invalidateQueries({ queryKey: ['dashboard-realtime'] })
    ]);
  };

  // Auto-refresh when user becomes active
  useEffect(() => {
    const handleVisibilityChange = () => {
      if (!document.hidden && tenant?.id) {
        refreshDashboard();
      }
    };

    document.addEventListener('visibilitychange', handleVisibilityChange);
    return () => document.removeEventListener('visibilitychange', handleVisibilityChange);
  }, [tenant?.id]);

  // Merge metrics data into main dashboard data
  const enrichedDashboardData = dashboardData ? {
    ...dashboardData,
    metrics: metricsData || dashboardData.metrics,
    realtimeUpdates
  } : null;

  return {
    dashboardData: enrichedDashboardData,
    metrics: metricsData,
    realtimeUpdates,
    isLoading: isLoading || metricsLoading,
    isRealtimeLoading: realtimeLoading,
    error,
    lastRefresh,
    refreshDashboard
  };
}

/**
 * Dashboard Analytics Hook
 * 
 * Specialized hook for analytics data
 */
export function useDashboardAnalytics(timeRange: string = '7d') {
  const { tenant } = useAuth();

  return useQuery({
    queryKey: ['dashboard-analytics', tenant?.id, timeRange],
    queryFn: () => dashboardApi.getAnalytics(timeRange),
    enabled: !!tenant?.id,
    refetchInterval: 60000, // Refresh every minute
    staleTime: 30000,
  });
}

/**
 * Meeting Insights Hook
 * 
 * Hook for fetching and managing meeting insights
 */
export function useMeetingInsights(meetingId?: string) {
  const { tenant } = useAuth();

  return useQuery({
    queryKey: ['meeting-insights', tenant?.id, meetingId],
    queryFn: () => meetingId 
      ? dashboardApi.getMeetingInsights(meetingId)
      : dashboardApi.getRecentMeetingInsights(),
    enabled: !!tenant?.id,
    refetchInterval: 45000,
  });
}

/**
 * Action Items Hook
 * 
 * Hook for managing action items
 */
export function useActionItems(filters?: {
  status?: string;
  priority?: string;
  assignee?: string;
}) {
  const { tenant } = useAuth();
  const queryClient = useQueryClient();

  const query = useQuery({
    queryKey: ['action-items', tenant?.id, filters],
    queryFn: () => dashboardApi.getActionItems(filters),
    enabled: !!tenant?.id,
    refetchInterval: 30000,
  });

  const updateActionItem = async (actionId: string, updates: any) => {
    try {
      await dashboardApi.updateActionItem(actionId, updates);
      
      // Invalidate related queries
      queryClient.invalidateQueries({ 
        queryKey: ['action-items', tenant?.id] 
      });
      queryClient.invalidateQueries({ 
        queryKey: ['dashboard', tenant?.id] 
      });
      
    } catch (error) {
      console.error('Failed to update action item:', error);
      throw error;
    }
  };

  const createActionItem = async (actionData: any) => {
    try {
      const newAction = await dashboardApi.createActionItem(actionData);
      
      // Invalidate related queries
      queryClient.invalidateQueries({ 
        queryKey: ['action-items', tenant?.id] 
      });
      queryClient.invalidateQueries({ 
        queryKey: ['dashboard', tenant?.id] 
      });
      
      return newAction;
    } catch (error) {
      console.error('Failed to create action item:', error);
      throw error;
    }
  };

  return {
    ...query,
    updateActionItem,
    createActionItem
  };
}

/**
 * Workflows Hook
 * 
 * Hook for managing workflows and executions
 */
export function useWorkflows() {
  const { tenant } = useAuth();
  const queryClient = useQueryClient();

  const workflowsQuery = useQuery({
    queryKey: ['workflows', tenant?.id],
    queryFn: () => dashboardApi.getWorkflows(),
    enabled: !!tenant?.id,
    refetchInterval: 60000,
  });

  const executionsQuery = useQuery({
    queryKey: ['workflow-executions', tenant?.id],
    queryFn: () => dashboardApi.getWorkflowExecutions(),
    enabled: !!tenant?.id,
    refetchInterval: 15000,
  });

  const triggerWorkflow = async (workflowId: string, inputData: any) => {
    try {
      const execution = await dashboardApi.triggerWorkflow(workflowId, inputData);
      
      // Invalidate executions query to show new execution
      queryClient.invalidateQueries({ 
        queryKey: ['workflow-executions', tenant?.id] 
      });
      
      return execution;
    } catch (error) {
      console.error('Failed to trigger workflow:', error);
      throw error;
    }
  };

  return {
    workflows: workflowsQuery.data,
    executions: executionsQuery.data,
    isLoading: workflowsQuery.isLoading || executionsQuery.isLoading,
    error: workflowsQuery.error || executionsQuery.error,
    triggerWorkflow
  };
}

/**
 * AI Insights Hook
 * 
 * Hook for AI-generated insights and analytics
 */
export function useAIInsights(filters?: {
  type?: string;
  confidence_threshold?: number;
  date_range?: string;
}) {
  const { tenant } = useAuth();

  return useQuery({
    queryKey: ['ai-insights', tenant?.id, filters],
    queryFn: () => dashboardApi.getAIInsights(filters),
    enabled: !!tenant?.id,
    refetchInterval: 120000, // Refresh every 2 minutes
    staleTime: 60000,
  });
}

/**
 * Performance Metrics Hook
 * 
 * Hook for system performance and health metrics
 */
export function usePerformanceMetrics() {
  const { tenant } = useAuth();

  return useQuery({
    queryKey: ['performance-metrics', tenant?.id],
    queryFn: () => dashboardApi.getPerformanceMetrics(),
    enabled: !!tenant?.id,
    refetchInterval: 10000, // Refresh every 10 seconds
  });
}

/**
 * Search Hook
 * 
 * Hook for semantic search across meetings and content
 */
export function useSemanticSearch() {
  const { tenant } = useAuth();
  const [searchHistory, setSearchHistory] = useState<string[]>([]);

  const performSearch = async (query: string, filters?: any) => {
    try {
      const results = await dashboardApi.semanticSearch(query, filters);
      
      // Add to search history
      setSearchHistory(prev => {
        const updated = [query, ...prev.filter(q => q !== query)];
        return updated.slice(0, 10); // Keep only last 10 searches
      });
      
      return results;
    } catch (error) {
      console.error('Search failed:', error);
      throw error;
    }
  };

  const getSimilarContent = async (contentId: string) => {
    try {
      return await dashboardApi.getSimilarContent(contentId);
    } catch (error) {
      console.error('Failed to get similar content:', error);
      throw error;
    }
  };

  return {
    performSearch,
    getSimilarContent,
    searchHistory
  };
}