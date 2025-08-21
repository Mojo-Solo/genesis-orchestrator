'use client';

import React, { useState, useMemo, useRef, useEffect } from 'react';
import { Card, CardHeader, CardContent, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Progress } from '@/components/ui/progress';
import { Separator } from '@/components/ui/separator';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { 
  Calendar, ChevronLeft, ChevronRight, Plus, Filter, 
  Clock, Users, Target, BarChart3, Maximize2, Minimize2,
  AlertTriangle, CheckCircle, PlayCircle, PauseCircle
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { useAppStore } from '@/lib/store';

interface GanttTask {
  id: string;
  name: string;
  startDate: Date;
  endDate: Date;
  progress: number;
  status: 'not-started' | 'in-progress' | 'completed' | 'on-hold' | 'at-risk';
  priority: 'low' | 'medium' | 'high' | 'critical';
  assignee?: string;
  dependencies?: string[];
  color: string;
  parentId?: string;
  children?: GanttTask[];
  type: 'project' | 'phase' | 'task';
}

interface GanttChartProps {
  className?: string;
  viewMode?: 'days' | 'weeks' | 'months';
  showCriticalPath?: boolean;
}

const GanttChart: React.FC<GanttChartProps> = ({
  className = '',
  viewMode = 'weeks',
  showCriticalPath = false
}) => {
  const [selectedViewMode, setSelectedViewMode] = useState(viewMode);
  const [currentDate, setCurrentDate] = useState(new Date());
  const [selectedTask, setSelectedTask] = useState<string | null>(null);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [filterStatus, setFilterStatus] = useState<string>('all');
  const chartRef = useRef<HTMLDivElement>(null);
  
  const { projects } = useAppStore();

  // Transform projects into Gantt tasks
  const ganttTasks = useMemo((): GanttTask[] => {
    const tasks: GanttTask[] = [];
    
    projects.forEach((project, index) => {
      const startDate = new Date(2024, 5, 1 + index * 14); // Stagger start dates
      const duration = 60 + index * 30; // Variable duration
      const endDate = new Date(startDate.getTime() + duration * 24 * 60 * 60 * 1000);
      
      const statusColors = {
        'not-started': '#6b7280',
        'in-progress': '#3b82f6',
        'on-target': '#10b981',
        'at-risk': '#f59e0b',
        'off-track': '#ef4444',
        'completed': '#10b981',
        'on-hold': '#8b5cf6'
      };

      // Main project
      const mainTask: GanttTask = {
        id: project.id,
        name: project.name,
        startDate,
        endDate,
        progress: project.progress || Math.floor(Math.random() * 100),
        status: project.status === 'on-target' ? 'in-progress' : 
                project.status === 'completed' ? 'completed' :
                project.status === 'at-risk' ? 'at-risk' :
                project.status === 'off-track' ? 'at-risk' : 'not-started',
        priority: ['low', 'medium', 'high'][Math.floor(Math.random() * 3)] as any,
        assignee: ['David Chen', 'Sarah Wilson', 'Michael Brown'][Math.floor(Math.random() * 3)],
        color: statusColors[project.status] || '#6b7280',
        type: 'project',
        children: []
      };

      // Add sub-tasks (phases)
      const phases = [
        'Planning & Design',
        'Development',
        'Testing & QA',
        'Deployment',
        'Post-Launch Review'
      ];

      phases.forEach((phase, phaseIndex) => {
        const phaseStart = new Date(startDate.getTime() + (phaseIndex * duration * 24 * 60 * 60 * 1000 / phases.length));
        const phaseEnd = new Date(startDate.getTime() + ((phaseIndex + 1) * duration * 24 * 60 * 60 * 1000 / phases.length));
        
        const phaseTask: GanttTask = {
          id: `${project.id}-phase-${phaseIndex}`,
          name: phase,
          startDate: phaseStart,
          endDate: phaseEnd,
          progress: Math.max(0, mainTask.progress - (phases.length - phaseIndex - 1) * 20),
          status: phaseIndex < 2 ? 'completed' : phaseIndex === 2 ? 'in-progress' : 'not-started',
          priority: mainTask.priority,
          assignee: mainTask.assignee,
          color: phaseIndex < 2 ? '#10b981' : phaseIndex === 2 ? '#3b82f6' : '#6b7280',
          parentId: project.id,
          type: 'phase'
        };

        mainTask.children?.push(phaseTask);
      });

      tasks.push(mainTask);
    });

    return tasks;
  }, [projects]);

  // Calculate time grid based on view mode
  const timeGrid = useMemo(() => {
    const start = new Date(Math.min(...ganttTasks.map(t => t.startDate.getTime())));
    const end = new Date(Math.max(...ganttTasks.map(t => t.endDate.getTime())));
    
    // Add buffer
    start.setDate(start.getDate() - 7);
    end.setDate(end.getDate() + 7);

    const grid = [];
    const current = new Date(start);

    while (current <= end) {
      grid.push(new Date(current));
      
      switch (selectedViewMode) {
        case 'days':
          current.setDate(current.getDate() + 1);
          break;
        case 'weeks':
          current.setDate(current.getDate() + 7);
          break;
        case 'months':
          current.setMonth(current.getMonth() + 1);
          break;
      }
    }

    return grid;
  }, [ganttTasks, selectedViewMode]);

  // Filter tasks
  const filteredTasks = useMemo(() => {
    if (filterStatus === 'all') return ganttTasks;
    return ganttTasks.filter(task => task.status === filterStatus);
  }, [ganttTasks, filterStatus]);

  const getTaskPosition = (task: GanttTask) => {
    const gridStart = timeGrid[0];
    const gridEnd = timeGrid[timeGrid.length - 1];
    const totalDuration = gridEnd.getTime() - gridStart.getTime();
    
    const taskStart = Math.max(task.startDate.getTime(), gridStart.getTime());
    const taskEnd = Math.min(task.endDate.getTime(), gridEnd.getTime());
    
    const left = ((taskStart - gridStart.getTime()) / totalDuration) * 100;
    const width = ((taskEnd - taskStart) / totalDuration) * 100;
    
    return { left: `${left}%`, width: `${Math.max(width, 0.5)}%` };
  };

  const formatDate = (date: Date) => {
    switch (selectedViewMode) {
      case 'days':
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
      case 'weeks':
        return `Week of ${date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}`;
      case 'months':
        return date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
      default:
        return date.toLocaleDateString();
    }
  };

  const getStatusIcon = (status: GanttTask['status']) => {
    switch (status) {
      case 'completed': return <CheckCircle className="w-4 h-4 text-green-500" />;
      case 'in-progress': return <PlayCircle className="w-4 h-4 text-blue-500" />;
      case 'on-hold': return <PauseCircle className="w-4 h-4 text-purple-500" />;
      case 'at-risk': return <AlertTriangle className="w-4 h-4 text-amber-500" />;
      default: return <Clock className="w-4 h-4 text-gray-500" />;
    }
  };

  const getPriorityColor = (priority: GanttTask['priority']) => {
    switch (priority) {
      case 'critical': return 'bg-red-100 text-red-800 border-red-200';
      case 'high': return 'bg-orange-100 text-orange-800 border-orange-200';
      case 'medium': return 'bg-yellow-100 text-yellow-800 border-yellow-200';
      case 'low': return 'bg-green-100 text-green-800 border-green-200';
      default: return 'bg-gray-100 text-gray-800 border-gray-200';
    }
  };

  return (
    <Card className={cn('h-full', isFullscreen && 'fixed inset-0 z-50', className)}>
      <CardHeader className="pb-3">
        <div className="flex items-center justify-between">
          <div className="flex items-center space-x-3">
            <BarChart3 className="w-6 h-6 text-brand-brown" />
            <div>
              <CardTitle className="text-xl">Project Timeline</CardTitle>
              <p className="text-sm text-gray-600 mt-1">
                Gantt chart view of all projects and phases
              </p>
            </div>
          </div>
          
          <div className="flex items-center space-x-2">
            <Select value={selectedViewMode} onValueChange={setSelectedViewMode as any}>
              <SelectTrigger className="w-24">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="days">Days</SelectItem>
                <SelectItem value="weeks">Weeks</SelectItem>
                <SelectItem value="months">Months</SelectItem>
              </SelectContent>
            </Select>
            
            <Select value={filterStatus} onValueChange={setFilterStatus}>
              <SelectTrigger className="w-32">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Status</SelectItem>
                <SelectItem value="not-started">Not Started</SelectItem>
                <SelectItem value="in-progress">In Progress</SelectItem>
                <SelectItem value="completed">Completed</SelectItem>
                <SelectItem value="at-risk">At Risk</SelectItem>
                <SelectItem value="on-hold">On Hold</SelectItem>
              </SelectContent>
            </Select>

            <Button
              variant="outline"
              size="sm"
              onClick={() => setIsFullscreen(!isFullscreen)}
            >
              {isFullscreen ? <Minimize2 className="w-4 h-4" /> : <Maximize2 className="w-4 h-4" />}
            </Button>
          </div>
        </div>
        
        {/* Legend */}
        <div className="flex items-center space-x-4 text-sm">
          <div className="flex items-center space-x-1">
            <div className="w-3 h-3 bg-gray-400 rounded"></div>
            <span>Not Started</span>
          </div>
          <div className="flex items-center space-x-1">
            <div className="w-3 h-3 bg-blue-500 rounded"></div>
            <span>In Progress</span>
          </div>
          <div className="flex items-center space-x-1">
            <div className="w-3 h-3 bg-green-500 rounded"></div>
            <span>Completed</span>
          </div>
          <div className="flex items-center space-x-1">
            <div className="w-3 h-3 bg-amber-500 rounded"></div>
            <span>At Risk</span>
          </div>
          <div className="flex items-center space-x-1">
            <div className="w-3 h-3 bg-purple-500 rounded"></div>
            <span>On Hold</span>
          </div>
        </div>
      </CardHeader>

      <CardContent className="p-0">
        <div className="relative" ref={chartRef}>
          {/* Time Header */}
          <div className="sticky top-0 bg-white border-b z-10">
            <div className="flex">
              {/* Task Names Column */}
              <div className="w-80 flex-shrink-0 border-r bg-gray-50 p-3 font-medium text-sm text-gray-700">
                Project / Phase
              </div>
              
              {/* Timeline Header */}
              <div className="flex-1 relative">
                <ScrollArea orientation="horizontal" className="w-full">
                  <div className="flex min-w-max h-12 items-center">
                    {timeGrid.map((date, index) => (
                      <div
                        key={index}
                        className="flex-shrink-0 px-2 py-3 text-xs text-gray-600 border-r border-gray-200 min-w-24 text-center"
                      >
                        {formatDate(date)}
                      </div>
                    ))}
                  </div>
                </ScrollArea>
              </div>
            </div>
          </div>

          {/* Chart Body */}
          <ScrollArea className={cn('overflow-auto', isFullscreen ? 'h-[calc(100vh-200px)]' : 'h-96')}>
            <div className="min-h-full">
              {filteredTasks.map((task, taskIndex) => (
                <div key={task.id}>
                  {/* Main Task Row */}
                  <div 
                    className={cn(
                      'flex border-b hover:bg-gray-50 transition-colors',
                      selectedTask === task.id && 'bg-blue-50 border-blue-200'
                    )}
                    onClick={() => setSelectedTask(selectedTask === task.id ? null : task.id)}
                  >
                    {/* Task Info */}
                    <div className="w-80 flex-shrink-0 p-3 border-r">
                      <div className="flex items-center space-x-2">
                        {getStatusIcon(task.status)}
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center space-x-2">
                            <p className="font-medium text-sm text-gray-900 truncate">
                              {task.name}
                            </p>
                            <Badge className={cn('text-xs border', getPriorityColor(task.priority))}>
                              {task.priority}
                            </Badge>
                          </div>
                          {task.assignee && (
                            <div className="flex items-center space-x-1 mt-1">
                              <Users className="w-3 h-3 text-gray-400" />
                              <span className="text-xs text-gray-600">{task.assignee}</span>
                            </div>
                          )}
                          <div className="mt-2">
                            <Progress value={task.progress} className="h-2" />
                            <span className="text-xs text-gray-500">{task.progress}% complete</span>
                          </div>
                        </div>
                      </div>
                    </div>

                    {/* Timeline */}
                    <div className="flex-1 relative min-h-16 p-2">
                      <ScrollArea orientation="horizontal" className="w-full">
                        <div className="relative min-w-max h-full">
                          {/* Grid Lines */}
                          <div className="absolute inset-0 flex">
                            {timeGrid.map((_, index) => (
                              <div key={index} className="flex-shrink-0 border-r border-gray-100 min-w-24" />
                            ))}
                          </div>
                          
                          {/* Task Bar */}
                          <div
                            className="absolute top-1 h-6 rounded flex items-center px-2 text-white text-xs font-medium shadow-sm cursor-pointer hover:shadow-md transition-shadow"
                            style={{
                              backgroundColor: task.color,
                              ...getTaskPosition(task)
                            }}
                            title={`${task.name} (${task.progress}% complete)`}
                          >
                            <span className="truncate">
                              {task.name.length > 20 ? `${task.name.substring(0, 20)}...` : task.name}
                            </span>
                          </div>

                          {/* Progress Overlay */}
                          {task.progress > 0 && (
                            <div
                              className="absolute top-1 h-6 rounded-l bg-black bg-opacity-20"
                              style={{
                                ...getTaskPosition(task),
                                width: `${(parseFloat(getTaskPosition(task).width.replace('%', '')) * task.progress / 100)}%`
                              }}
                            />
                          )}
                        </div>
                      </ScrollArea>
                    </div>
                  </div>

                  {/* Sub-tasks (Phases) */}
                  {selectedTask === task.id && task.children && task.children.length > 0 && (
                    <div className="bg-gray-50 border-b">
                      {task.children.map((phase) => (
                        <div key={phase.id} className="flex hover:bg-gray-100 transition-colors">
                          {/* Phase Info */}
                          <div className="w-80 flex-shrink-0 pl-8 pr-3 py-2 border-r">
                            <div className="flex items-center space-x-2">
                              {getStatusIcon(phase.status)}
                              <div className="flex-1 min-w-0">
                                <p className="text-sm text-gray-700 truncate">{phase.name}</p>
                                <div className="mt-1">
                                  <Progress value={phase.progress} className="h-1" />
                                  <span className="text-xs text-gray-500">{phase.progress}%</span>
                                </div>
                              </div>
                            </div>
                          </div>

                          {/* Phase Timeline */}
                          <div className="flex-1 relative min-h-12 p-1">
                            <ScrollArea orientation="horizontal" className="w-full">
                              <div className="relative min-w-max h-full">
                                {/* Grid Lines */}
                                <div className="absolute inset-0 flex">
                                  {timeGrid.map((_, index) => (
                                    <div key={index} className="flex-shrink-0 border-r border-gray-200 min-w-24" />
                                  ))}
                                </div>
                                
                                {/* Phase Bar */}
                                <div
                                  className="absolute top-1 h-4 rounded flex items-center px-1 text-white text-xs shadow-sm"
                                  style={{
                                    backgroundColor: phase.color,
                                    ...getTaskPosition(phase)
                                  }}
                                >
                                  <span className="truncate text-xs">
                                    {phase.name.length > 15 ? `${phase.name.substring(0, 15)}...` : phase.name}
                                  </span>
                                </div>

                                {/* Phase Progress */}
                                {phase.progress > 0 && (
                                  <div
                                    className="absolute top-1 h-4 rounded-l bg-black bg-opacity-20"
                                    style={{
                                      ...getTaskPosition(phase),
                                      width: `${(parseFloat(getTaskPosition(phase).width.replace('%', '')) * phase.progress / 100)}%`
                                    }}
                                  />
                                )}
                              </div>
                            </ScrollArea>
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              ))}
            </div>
          </ScrollArea>

          {/* Today Line */}
          <div
            className="absolute top-12 bottom-0 w-0.5 bg-red-500 z-20 pointer-events-none"
            style={{
              left: `${((new Date().getTime() - timeGrid[0].getTime()) / 
                      (timeGrid[timeGrid.length - 1].getTime() - timeGrid[0].getTime())) * 100}%`
            }}
          >
            <div className="absolute -top-2 -left-2 w-4 h-4 bg-red-500 rounded-full flex items-center justify-center">
              <div className="w-2 h-2 bg-white rounded-full"></div>
            </div>
          </div>
        </div>

        {/* Chart Summary */}
        <div className="border-t bg-gray-50 p-4">
          <div className="grid grid-cols-4 gap-4 text-center">
            <div>
              <p className="text-2xl font-bold text-gray-900">{filteredTasks.length}</p>
              <p className="text-sm text-gray-600">Total Projects</p>
            </div>
            <div>
              <p className="text-2xl font-bold text-green-600">
                {filteredTasks.filter(t => t.status === 'completed').length}
              </p>
              <p className="text-sm text-gray-600">Completed</p>
            </div>
            <div>
              <p className="text-2xl font-bold text-blue-600">
                {filteredTasks.filter(t => t.status === 'in-progress').length}
              </p>
              <p className="text-sm text-gray-600">In Progress</p>
            </div>
            <div>
              <p className="text-2xl font-bold text-amber-600">
                {filteredTasks.filter(t => t.status === 'at-risk').length}
              </p>
              <p className="text-sm text-gray-600">At Risk</p>
            </div>
          </div>
        </div>
      </CardContent>
    </Card>
  );
};

export default GanttChart;