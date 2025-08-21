'use client';

import React, { useState } from 'react';
import { Card, CardHeader, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { BarChart3, TrendingUp, AlertTriangle, CheckCircle, Grid3x3, Calendar } from 'lucide-react';
import ProjectStatusTable from '@/components/tables/ProjectStatusTable';
import GanttChart from '@/components/projects/GanttChart';
import { useAppStore, useProjectsByQuarter } from '@/lib/store';
import { generateInsight } from '@/lib/ai';
import { toast } from 'sonner';

const ProjectsPage: React.FC = () => {
  const [activeTab, setActiveTab] = useState('grid');
  const { projects } = useAppStore();
  const projectsByQuarter = useProjectsByQuarter();
  const [insight, setInsight] = React.useState('');
  const [isGeneratingInsight, setIsGeneratingInsight] = React.useState(false);

  const handleGenerateInsight = async () => {
    setIsGeneratingInsight(true);
    try {
      const result = await generateInsight(projects);
      setInsight(result);
      toast.success('Project insights generated');
    } catch (error) {
      toast.error('Failed to generate insights');
    } finally {
      setIsGeneratingInsight(false);
    }
  };

  // Calculate project statistics
  const totalProjects = projects.length;
  const projectsByStatus = projects.reduce((acc, project) => {
    // Get the most recent week's status for each project
    const latestWeek = project.weekly[project.weekly.length - 1];
    const status = latestWeek?.status || 'Not Started';
    acc[status] = (acc[status] || 0) + 1;
    return acc;
  }, {} as Record<string, number>);

  const onTargetCount = projectsByStatus['On Target'] || 0;
  const atRiskCount = projectsByStatus['At Risk'] || 0;
  const offTrackCount = projectsByStatus['Off Track'] || 0;
  const blockedCount = projectsByStatus['Blocked'] || 0;

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold text-gray-900">Projects</h1>
          <p className="text-gray-600">Strategic project status and comprehensive project management</p>
        </div>
        
        <Button
          onClick={handleGenerateInsight}
          disabled={isGeneratingInsight}
          className="bg-brand-brown hover:bg-brand-brown/90"
        >
          <BarChart3 className="w-4 h-4 mr-2" />
          {isGeneratingInsight ? 'Analyzing...' : 'Generate Insights'}
        </Button>
      </div>

      {/* Project Statistics */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <Card>
          <CardContent className="p-6">
            <div className="flex items-center">
              <CheckCircle className="h-8 w-8 text-green-500" />
              <div className="ml-4">
                <div className="text-2xl font-bold text-gray-900">{onTargetCount}</div>
                <div className="text-sm text-gray-600">On Target</div>
              </div>
            </div>
          </CardContent>
        </Card>
        
        <Card>
          <CardContent className="p-6">
            <div className="flex items-center">
              <AlertTriangle className="h-8 w-8 text-yellow-500" />
              <div className="ml-4">
                <div className="text-2xl font-bold text-gray-900">{atRiskCount}</div>
                <div className="text-sm text-gray-600">At Risk</div>
              </div>
            </div>
          </CardContent>
        </Card>
        
        <Card>
          <CardContent className="p-6">
            <div className="flex items-center">
              <TrendingUp className="h-8 w-8 text-red-500" />
              <div className="ml-4">
                <div className="text-2xl font-bold text-gray-900">{offTrackCount + blockedCount}</div>
                <div className="text-sm text-gray-600">Off Track/Blocked</div>
              </div>
            </div>
          </CardContent>
        </Card>
        
        <Card>
          <CardContent className="p-6">
            <div className="flex items-center">
              <BarChart3 className="h-8 w-8 text-blue-500" />
              <div className="ml-4">
                <div className="text-2xl font-bold text-gray-900">{totalProjects}</div>
                <div className="text-sm text-gray-600">Total Projects</div>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* AI Insights Panel */}
      {insight && (
        <Card>
          <CardHeader className="bg-blue-50 border-b border-blue-200">
            <h3 className="text-lg font-semibold text-blue-900">AI Project Insights</h3>
          </CardHeader>
          <CardContent className="p-6">
            <p className="text-blue-800">{insight}</p>
          </CardContent>
        </Card>
      )}

      {/* Enhanced Project Views */}
      <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
        <TabsList className="grid w-full grid-cols-3">
          <TabsTrigger value="grid" className="flex items-center space-x-2">
            <Grid3x3 className="w-4 h-4" />
            <span>Grid View</span>
          </TabsTrigger>
          <TabsTrigger value="gantt" className="flex items-center space-x-2">
            <Calendar className="w-4 h-4" />
            <span>Gantt Chart</span>
          </TabsTrigger>
          <TabsTrigger value="timeline" className="flex items-center space-x-2">
            <BarChart3 className="w-4 h-4" />
            <span>Timeline View</span>
          </TabsTrigger>
        </TabsList>

        <TabsContent value="grid" className="space-y-6">
          {/* Project Status Table */}
          <ProjectStatusTable />
        </TabsContent>

        <TabsContent value="gantt" className="space-y-6">
          <GanttChart />
        </TabsContent>

        <TabsContent value="timeline" className="space-y-6">
          <Card>
            <CardHeader>
              <h3 className="text-lg font-semibold text-gray-900">Timeline View</h3>
              <p className="text-sm text-gray-600">Coming soon: Enhanced timeline visualization with milestones and dependencies</p>
            </CardHeader>
            <CardContent>
              <div className="text-center py-12">
                <Calendar className="w-16 h-16 text-gray-300 mx-auto mb-4" />
                <p className="text-gray-500">Timeline view is under development</p>
                <p className="text-sm text-gray-400 mt-2">
                  This will include milestone tracking, dependency management, and critical path analysis
                </p>
              </div>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {/* Quarter Overview */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        {['Q1', 'Q2', 'Q3', 'Q4'].map((quarter) => {
          const quarterProjects = projectsByQuarter[quarter as keyof typeof projectsByQuarter] || [];
          const projectCount = quarterProjects.length;
          
          return (
            <Card key={quarter}>
              <CardHeader>
                <div className="flex justify-between items-center">
                  <h3 className="text-lg font-semibold text-gray-900">{quarter} Summary</h3>
                  <Badge variant="outline">{projectCount} projects</Badge>
                </div>
              </CardHeader>
              <CardContent>
                <div className="space-y-3">
                  {quarterProjects.map((project) => {
                    // Calculate average progress
                    const avgProgress = project.weekly.reduce((sum, week) => sum + week.percent, 0) / project.weekly.length;
                    const latestStatus = project.weekly[project.weekly.length - 1]?.status || 'Not Started';
                    
                    return (
                      <div key={project.id} className="border-l-4 border-blue-500 pl-3">
                        <div className="text-sm font-medium text-gray-900">
                          {project.title}
                        </div>
                        <div className="text-xs text-gray-600 mt-1">
                          {avgProgress.toFixed(0)}% complete • {latestStatus}
                        </div>
                        <div className="text-xs text-gray-500">
                          Owner: {project.owner}
                        </div>
                      </div>
                    );
                  })}
                  
                  {projectCount === 0 && (
                    <div className="text-sm text-gray-500 italic">
                      No projects scheduled for {quarter}
                    </div>
                  )}
                </div>
              </CardContent>
            </Card>
          );
        })}
      </div>

      {/* Project Management Guide */}
      <Card>
        <CardHeader>
          <h3 className="text-lg font-semibold text-gray-900">Project Tracking Guide</h3>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <h4 className="font-medium text-gray-900 mb-2">Weekly Updates</h4>
              <ul className="text-sm text-gray-600 space-y-1">
                <li>• Update percentage completion (0-100%) for each week</li>
                <li>• Set appropriate status: On Target, At Risk, Off Track, etc.</li>
                <li>• Document issues and blockers in the Issues section</li>
                <li>• Define next actions and priorities for upcoming weeks</li>
              </ul>
            </div>
            <div>
              <h4 className="font-medium text-gray-900 mb-2">Status Definitions</h4>
              <ul className="text-sm text-gray-600 space-y-1">
                <li>• <strong>On Target:</strong> Project progressing as planned</li>
                <li>• <strong>At Risk:</strong> Minor issues that may impact timeline</li>
                <li>• <strong>Off Track:</strong> Significant delays or problems</li>
                <li>• <strong>Blocked:</strong> Cannot proceed due to external dependencies</li>
              </ul>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default ProjectsPage;