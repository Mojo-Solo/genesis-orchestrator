'use client';

import React, { useState, useMemo } from 'react';
import { Card, CardHeader, CardContent, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import CardSpotlight from '@/components/ui/card-spotlight';
import { 
  BarChart, Bar, XAxis, YAxis, CartesianGrid, ResponsiveContainer,
  LineChart, Line, PieChart, Pie, Cell, RadarChart, PolarGrid,
  PolarAngleAxis, PolarRadiusAxis, Radar, Area, AreaChart, 
  ScatterChart, Scatter, Tooltip, Legend
} from 'recharts';
import { 
  TrendingUp, TrendingDown, AlertTriangle, CheckCircle, 
  Clock, Target, DollarSign, Users, BarChart3, PieChart as PieChartIcon,
  Activity, Zap, Brain, Eye, Calendar, Filter, Download,
  ArrowUp, ArrowDown, Minus, Sparkles
} from 'lucide-react';
import { useAppStore } from '@/lib/store';
import { cn } from '@/lib/utils';
import { toast } from 'sonner';

interface AnalyticsMetric {
  id: string;
  label: string;
  value: number;
  change: number;
  trend: 'up' | 'down' | 'stable';
  format: 'number' | 'percentage' | 'currency' | 'days';
  category: 'performance' | 'financial' | 'strategic' | 'operational';
}

interface AnalyticsInsight {
  id: string;
  title: string;
  description: string;
  impact: 'high' | 'medium' | 'low';
  actionRequired: boolean;
  category: 'opportunity' | 'risk' | 'achievement' | 'recommendation';
  data: any;
}

const AnalyticsPage: React.FC = () => {
  const { initiatives, projects, budget, vision } = useAppStore();
  const [timeRange, setTimeRange] = useState('3m');
  const [selectedCategory, setSelectedCategory] = useState('all');
  const [activeTab, setActiveTab] = useState('overview');

  // Calculate Analytics Metrics
  const metrics = useMemo((): AnalyticsMetric[] => {
    const totalInitiatives = initiatives.length;
    const approvedInitiatives = initiatives.filter(i => i.status === 'approved').length;
    const completedProjects = projects.filter(p => p.status === 'completed').length;
    const onTargetProjects = projects.filter(p => p.status === 'on-target').length;
    const totalRevenue = budget.plan.reduce((sum, month) => sum + month.revenue, 0);
    const totalExpenses = budget.plan.reduce((sum, month) => sum + month.expense, 0);
    const actualRevenue = budget.actual.reduce((sum, month) => sum + month.revenue, 0);
    const visionCompletion = Object.values(vision).filter(v => v.length > 20).length / 5 * 100;

    return [
      {
        id: 'initiative-approval-rate',
        label: 'Initiative Approval Rate',
        value: totalInitiatives > 0 ? (approvedInitiatives / totalInitiatives) * 100 : 0,
        change: 12.5,
        trend: 'up',
        format: 'percentage',
        category: 'strategic'
      },
      {
        id: 'project-success-rate',
        label: 'Project Success Rate',
        value: projects.length > 0 ? (completedProjects / projects.length) * 100 : 0,
        change: -3.2,
        trend: 'down',
        format: 'percentage',
        category: 'operational'
      },
      {
        id: 'revenue-realization',
        label: 'Revenue Realization',
        value: totalRevenue > 0 ? (actualRevenue / totalRevenue) * 100 : 0,
        change: 8.7,
        trend: 'up',
        format: 'percentage',
        category: 'financial'
      },
      {
        id: 'vision-completeness',
        label: 'Vision Completeness',
        value: visionCompletion,
        change: 15.3,
        trend: 'up',
        format: 'percentage',
        category: 'strategic'
      },
      {
        id: 'avg-project-duration',
        label: 'Avg Project Duration',
        value: 47,
        change: -5.8,
        trend: 'up',
        format: 'days',
        category: 'operational'
      },
      {
        id: 'strategic-alignment',
        label: 'Strategic Alignment Score',
        value: 82.4,
        change: 6.2,
        trend: 'up',
        format: 'number',
        category: 'strategic'
      },
      {
        id: 'budget-variance',
        label: 'Budget Variance',
        value: Math.abs(((totalRevenue - actualRevenue) / totalRevenue) * 100),
        change: -2.1,
        trend: 'up',
        format: 'percentage',
        category: 'financial'
      },
      {
        id: 'team-utilization',
        label: 'Team Utilization',
        value: 89.2,
        change: 4.5,
        trend: 'up',
        format: 'percentage',
        category: 'operational'
      }
    ];
  }, [initiatives, projects, budget, vision]);

  // Generate Strategic Insights
  const insights = useMemo((): AnalyticsInsight[] => {
    const highRiskProjects = projects.filter(p => p.status === 'at-risk' || p.status === 'off-track').length;
    const pendingInitiatives = initiatives.filter(i => i.status === 'draft').length;
    const budgetVariance = metrics.find(m => m.id === 'budget-variance')?.value || 0;
    
    const generatedInsights: AnalyticsInsight[] = [];

    if (highRiskProjects > 0) {
      generatedInsights.push({
        id: 'high-risk-projects',
        title: 'Projects Requiring Attention',
        description: `${highRiskProjects} projects are currently at risk or off track. Consider resource reallocation or timeline adjustment.`,
        impact: 'high',
        actionRequired: true,
        category: 'risk',
        data: { count: highRiskProjects, total: projects.length }
      });
    }

    if (pendingInitiatives > 2) {
      generatedInsights.push({
        id: 'pending-initiatives',
        title: 'Initiative Bottleneck',
        description: `${pendingInitiatives} initiatives are pending approval. Streamline the approval process to maintain momentum.`,
        impact: 'medium',
        actionRequired: true,
        category: 'opportunity',
        data: { pending: pendingInitiatives, total: initiatives.length }
      });
    }

    if (budgetVariance < 5) {
      generatedInsights.push({
        id: 'budget-performance',
        title: 'Excellent Budget Management',
        description: `Budget variance is only ${budgetVariance.toFixed(1)}%, indicating strong financial discipline.`,
        impact: 'high',
        actionRequired: false,
        category: 'achievement',
        data: { variance: budgetVariance }
      });
    }

    const visionMetric = metrics.find(m => m.id === 'vision-completeness');
    if (visionMetric && visionMetric.value > 80) {
      generatedInsights.push({
        id: 'strategic-clarity',
        title: 'Strong Strategic Foundation',
        description: `Vision is ${visionMetric.value.toFixed(0)}% complete, providing clear direction for all initiatives.`,
        impact: 'high',
        actionRequired: false,
        category: 'achievement',
        data: { completion: visionMetric.value }
      });
    }

    generatedInsights.push({
      id: 'quarterly-focus',
      title: 'Q2 Priority Recommendation',
      description: 'Based on current progress, focus on completing at-risk projects before starting new initiatives.',
      impact: 'medium',
      actionRequired: true,
      category: 'recommendation',
      data: { quarter: 'Q2' }
    });

    return generatedInsights;
  }, [metrics, projects, initiatives]);

  // Chart Data
  const performanceChartData = [
    { month: 'Jan', initiatives: 12, projects: 8, budget: 95 },
    { month: 'Feb', initiatives: 15, projects: 12, budget: 88 },
    { month: 'Mar', initiatives: 18, projects: 15, budget: 92 },
    { month: 'Apr', initiatives: 22, projects: 18, budget: 89 },
    { month: 'May', initiatives: 25, projects: 22, budget: 94 },
    { month: 'Jun', initiatives: 28, projects: 25, budget: 91 }
  ];

  const statusDistribution = [
    { name: 'On Target', value: projects.filter(p => p.status === 'on-target').length, color: '#10b981' },
    { name: 'At Risk', value: projects.filter(p => p.status === 'at-risk').length, color: '#f59e0b' },
    { name: 'Off Track', value: projects.filter(p => p.status === 'off-track').length, color: '#ef4444' },
    { name: 'Completed', value: projects.filter(p => p.status === 'completed').length, color: '#6366f1' },
    { name: 'Not Started', value: projects.filter(p => p.status === 'not-started').length, color: '#6b7280' }
  ];

  const strategicAlignment = [
    { area: 'People', current: 85, target: 90 },
    { area: 'Sales', current: 78, target: 85 },
    { area: 'Geography', current: 92, target: 95 },
    { area: 'Offerings', current: 74, target: 80 },
    { area: 'Impact', current: 88, target: 92 }
  ];

  const formatValue = (value: number, format: string) => {
    switch (format) {
      case 'percentage': return `${value.toFixed(1)}%`;
      case 'currency': return `$${value.toLocaleString()}`;
      case 'days': return `${value} days`;
      default: return value.toFixed(1);
    }
  };

  const getTrendIcon = (trend: string, change: number) => {
    if (trend === 'up') return <ArrowUp className="w-4 h-4 text-green-500" />;
    if (trend === 'down') return <ArrowDown className="w-4 h-4 text-red-500" />;
    return <Minus className="w-4 h-4 text-gray-500" />;
  };

  const getInsightIcon = (category: string) => {
    switch (category) {
      case 'opportunity': return <Zap className="w-5 h-5 text-blue-500" />;
      case 'risk': return <AlertTriangle className="w-5 h-5 text-red-500" />;
      case 'achievement': return <CheckCircle className="w-5 h-5 text-green-500" />;
      case 'recommendation': return <Brain className="w-5 h-5 text-purple-500" />;
      default: return <Activity className="w-5 h-5 text-gray-500" />;
    }
  };

  const getImpactColor = (impact: string) => {
    switch (impact) {
      case 'high': return 'bg-red-100 text-red-800 border-red-200';
      case 'medium': return 'bg-yellow-100 text-yellow-800 border-yellow-200';
      case 'low': return 'bg-green-100 text-green-800 border-green-200';
      default: return 'bg-gray-100 text-gray-800 border-gray-200';
    }
  };

  const filteredMetrics = selectedCategory === 'all' 
    ? metrics 
    : metrics.filter(m => m.category === selectedCategory);

  return (
    <div className="space-y-6">
      {/* Enhanced Header */}
      <div className="flex flex-col md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-3xl font-bold text-gray-900 flex items-center space-x-3">
            <BarChart3 className="w-8 h-8 text-brand-brown" />
            <span>Strategic Analytics</span>
          </h1>
          <p className="text-gray-600 mt-1">Deep insights into your strategic performance and progress</p>
        </div>
        <div className="flex items-center space-x-3 mt-4 md:mt-0">
          <Select value={timeRange} onValueChange={setTimeRange}>
            <SelectTrigger className="w-32">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="1m">Last Month</SelectItem>
              <SelectItem value="3m">Last 3 Months</SelectItem>
              <SelectItem value="6m">Last 6 Months</SelectItem>
              <SelectItem value="1y">Last Year</SelectItem>
            </SelectContent>
          </Select>
          <Select value={selectedCategory} onValueChange={setSelectedCategory}>
            <SelectTrigger className="w-40">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Categories</SelectItem>
              <SelectItem value="strategic">Strategic</SelectItem>
              <SelectItem value="financial">Financial</SelectItem>
              <SelectItem value="operational">Operational</SelectItem>
              <SelectItem value="performance">Performance</SelectItem>
            </SelectContent>
          </Select>
          <Button variant="outline" className="flex items-center space-x-2">
            <Download className="w-4 h-4" />
            <span>Export Report</span>
          </Button>
        </div>
      </div>

      <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
        <TabsList className="grid w-full grid-cols-5">
          <TabsTrigger value="overview">Overview</TabsTrigger>
          <TabsTrigger value="performance">Performance</TabsTrigger>
          <TabsTrigger value="insights">AI Insights</TabsTrigger>
          <TabsTrigger value="trends">Trends</TabsTrigger>
          <TabsTrigger value="forecasting">Forecasting</TabsTrigger>
        </TabsList>

        <TabsContent value="overview" className="space-y-6">
          {/* Key Metrics Grid */}
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            {filteredMetrics.map((metric) => (
              <CardSpotlight key={metric.id} spotlightColor="#8B5E3C">
                <CardContent className="p-6">
                  <div className="flex items-center justify-between">
                    <div className="space-y-2">
                      <p className="text-sm font-medium text-gray-600">{metric.label}</p>
                      <p className="text-2xl font-bold text-gray-900">
                        {formatValue(metric.value, metric.format)}
                      </p>
                      <div className="flex items-center space-x-2">
                        {getTrendIcon(metric.trend, metric.change)}
                        <span className={cn(
                          'text-sm font-medium',
                          metric.trend === 'up' && metric.change > 0 ? 'text-green-600' :
                          metric.trend === 'down' && metric.change < 0 ? 'text-red-600' :
                          'text-gray-600'
                        )}>
                          {Math.abs(metric.change)}%
                        </span>
                      </div>
                    </div>
                    <Badge variant="outline" className="capitalize">
                      {metric.category}
                    </Badge>
                  </div>
                </CardContent>
              </CardSpotlight>
            ))}
          </div>

          {/* Status Overview */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <CardSpotlight spotlightColor="#10b981">
              <CardHeader>
                <CardTitle className="flex items-center space-x-2">
                  <PieChartIcon className="w-5 h-5 text-brand-brown" />
                  <span>Project Status Distribution</span>
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="h-64">
                  <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                      <Pie
                        data={statusDistribution}
                        cx="50%"
                        cy="50%"
                        innerRadius={60}
                        outerRadius={100}
                        paddingAngle={5}
                        dataKey="value"
                      >
                        {statusDistribution.map((entry, index) => (
                          <Cell key={`cell-${index}`} fill={entry.color} />
                        ))}
                      </Pie>
                      <Tooltip />
                      <Legend />
                    </PieChart>
                  </ResponsiveContainer>
                </div>
              </CardContent>
            </CardSpotlight>

            <CardSpotlight spotlightColor="#8B5E3C">
              <CardHeader>
                <CardTitle className="flex items-center space-x-2">
                  <Target className="w-5 h-5 text-brand-brown" />
                  <span>Strategic Alignment</span>
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="h-64">
                  <ResponsiveContainer width="100%" height="100%">
                    <RadarChart data={strategicAlignment}>
                      <PolarGrid />
                      <PolarAngleAxis dataKey="area" />
                      <PolarRadiusAxis angle={18} domain={[0, 100]} />
                      <Radar
                        name="Current"
                        dataKey="current"
                        stroke="#8B5E3C"
                        fill="#8B5E3C"
                        fillOpacity={0.3}
                      />
                      <Radar
                        name="Target"
                        dataKey="target"
                        stroke="#10b981"
                        fill="#10b981"
                        fillOpacity={0.1}
                      />
                      <Legend />
                    </RadarChart>
                  </ResponsiveContainer>
                </div>
              </CardContent>
            </CardSpotlight>
          </div>
        </TabsContent>

        <TabsContent value="performance" className="space-y-6">
          <CardSpotlight spotlightColor="#3b82f6">
            <CardHeader>
              <CardTitle>Performance Trends</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="h-80">
                <ResponsiveContainer width="100%" height="100%">
                  <AreaChart data={performanceChartData}>
                    <CartesianGrid strokeDasharray="3 3" />
                    <XAxis dataKey="month" />
                    <YAxis />
                    <Tooltip />
                    <Legend />
                    <Area
                      type="monotone"
                      dataKey="initiatives"
                      stackId="1"
                      stroke="#8B5E3C"
                      fill="#8B5E3C"
                      fillOpacity={0.6}
                    />
                    <Area
                      type="monotone"
                      dataKey="projects"
                      stackId="1"
                      stroke="#10b981"
                      fill="#10b981"
                      fillOpacity={0.6}
                    />
                    <Area
                      type="monotone"
                      dataKey="budget"
                      stackId="1"
                      stroke="#3b82f6"
                      fill="#3b82f6"
                      fillOpacity={0.6}
                    />
                  </AreaChart>
                </ResponsiveContainer>
              </div>
            </CardContent>
          </CardSpotlight>
        </TabsContent>

        <TabsContent value="insights" className="space-y-6">
          <div className="space-y-4">
            {insights.map((insight) => (
              <CardSpotlight key={insight.id} spotlightColor={insight.actionRequired ? "#ef4444" : "#10b981"}>
                <CardContent className="p-6">
                  <div className="flex items-start space-x-4">
                    {getInsightIcon(insight.category)}
                    <div className="flex-1 space-y-3">
                      <div className="flex items-center justify-between">
                        <h3 className="text-lg font-semibold text-gray-900">{insight.title}</h3>
                        <div className="flex items-center space-x-2">
                          <Badge className={cn('border', getImpactColor(insight.impact))}>
                            {insight.impact} impact
                          </Badge>
                          {insight.actionRequired && (
                            <Badge variant="destructive">Action Required</Badge>
                          )}
                        </div>
                      </div>
                      <p className="text-gray-600">{insight.description}</p>
                      {insight.actionRequired && (
                        <Button size="sm" className="bg-brand-brown hover:bg-brand-brown/90">
                          Take Action
                        </Button>
                      )}
                    </div>
                  </div>
                </CardContent>
              </CardSpotlight>
            ))}
          </div>
        </TabsContent>

        <TabsContent value="trends" className="space-y-6">
          <CardSpotlight spotlightColor="#f59e0b">
            <CardHeader>
              <CardTitle className="flex items-center space-x-2">
                <TrendingUp className="w-5 h-5 text-brand-brown" />
                <span>6-Month Performance Trends</span>
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="h-80">
                <ResponsiveContainer width="100%" height="100%">
                  <LineChart data={performanceChartData}>
                    <CartesianGrid strokeDasharray="3 3" />
                    <XAxis dataKey="month" />
                    <YAxis />
                    <Tooltip />
                    <Legend />
                    <Line
                      type="monotone"
                      dataKey="initiatives"
                      stroke="#8B5E3C"
                      strokeWidth={3}
                      dot={{ fill: '#8B5E3C' }}
                    />
                    <Line
                      type="monotone"
                      dataKey="projects"
                      stroke="#10b981"
                      strokeWidth={3}
                      dot={{ fill: '#10b981' }}
                    />
                    <Line
                      type="monotone"
                      dataKey="budget"
                      stroke="#3b82f6"
                      strokeWidth={3}
                      dot={{ fill: '#3b82f6' }}
                    />
                  </LineChart>
                </ResponsiveContainer>
              </div>
            </CardContent>
          </CardSpotlight>
        </TabsContent>

        <TabsContent value="forecasting" className="space-y-6">
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <CardSpotlight spotlightColor="#8B5E3C">
              <CardHeader>
                <CardTitle className="flex items-center space-x-2">
                  <Eye className="w-5 h-5 text-brand-brown" />
                  <span>Q3 Projections</span>
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="space-y-3">
                  <div className="flex justify-between items-center">
                    <span className="text-sm font-medium text-gray-600">Initiative Completion</span>
                    <span className="font-semibold">78%</span>
                  </div>
                  <Progress value={78} className="h-2" />
                </div>
                <div className="space-y-3">
                  <div className="flex justify-between items-center">
                    <span className="text-sm font-medium text-gray-600">Budget Utilization</span>
                    <span className="font-semibold">85%</span>
                  </div>
                  <Progress value={85} className="h-2" />
                </div>
                <div className="space-y-3">
                  <div className="flex justify-between items-center">
                    <span className="text-sm font-medium text-gray-600">Project Delivery</span>
                    <span className="font-semibold">92%</span>
                  </div>
                  <Progress value={92} className="h-2" />
                </div>
              </CardContent>
            </CardSpotlight>

            <CardSpotlight spotlightColor="#6366f1">
              <CardHeader>
                <CardTitle className="flex items-center space-x-2">
                  <Sparkles className="w-5 h-5 text-brand-brown" />
                  <span>AI Recommendations</span>
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="p-4 bg-blue-50 rounded-lg border border-blue-200">
                  <h4 className="font-semibold text-blue-900 mb-2">Resource Optimization</h4>
                  <p className="text-sm text-blue-700">Reallocate 2 team members from Project Alpha to Project Beta to improve delivery timeline by 15%.</p>
                </div>
                <div className="p-4 bg-green-50 rounded-lg border border-green-200">
                  <h4 className="font-semibold text-green-900 mb-2">Initiative Priority</h4>
                  <p className="text-sm text-green-700">Focus on Customer Experience initiative - highest ROI potential based on current data.</p>
                </div>
                <div className="p-4 bg-amber-50 rounded-lg border border-amber-200">
                  <h4 className="font-semibold text-amber-900 mb-2">Risk Mitigation</h4>
                  <p className="text-sm text-amber-700">Address timeline slippage in Q2 projects to prevent Q3 cascade effects.</p>
                </div>
              </CardContent>
            </CardSpotlight>
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default AnalyticsPage;