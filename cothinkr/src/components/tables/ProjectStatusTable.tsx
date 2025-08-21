'use client';

import React from 'react';
import { Card, CardHeader, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { useAppStore, useProjectsByQuarter } from '@/lib/store';
import { getStatusColor } from '@/lib/utils';
import { Status, Quarter } from '@/lib/types';

const ProjectStatusTable: React.FC = () => {
  const { updateProjectWeek, updateProjectIssues, updateProjectNextActions } = useAppStore();
  const projectsByQuarter = useProjectsByQuarter();

  const quarters: Quarter[] = ['Q1', 'Q2', 'Q3', 'Q4'];
  const weeks = Array.from({ length: 13 }, (_, i) => i + 1);

  const getProjectForQuarter = (quarter: Quarter) => {
    return projectsByQuarter[quarter]?.[0]; // Get first project for each quarter for demo
  };

  const handlePercentChange = (quarter: Quarter, week: number, value: string) => {
    const project = getProjectForQuarter(quarter);
    if (!project) return;

    const percent = Math.min(100, Math.max(0, parseInt(value) || 0));
    const currentWeek = project.weekly.find(w => w.week === week);
    if (currentWeek) {
      updateProjectWeek(project.id, week, percent, currentWeek.status);
    }
  };

  const handleStatusChange = (quarter: Quarter, week: number, status: Status) => {
    const project = getProjectForQuarter(quarter);
    if (!project) return;

    const currentWeek = project.weekly.find(w => w.week === week);
    if (currentWeek) {
      updateProjectWeek(project.id, week, currentWeek.percent, status);
    }
  };

  const handleIssuesChange = (quarter: Quarter, value: string) => {
    const project = getProjectForQuarter(quarter);
    if (project) {
      updateProjectIssues(project.id, quarter, value);
    }
  };

  const handleNextActionsChange = (quarter: Quarter, value: string) => {
    const project = getProjectForQuarter(quarter);
    if (project) {
      updateProjectNextActions(project.id, quarter, value);
    }
  };

  return (
    <div className="space-y-6">
      {/* Project Status Grid */}
      <Card>
        <CardHeader className="bg-brand-brown text-white">
          <div className="flex justify-between items-center">
            <div>
              <h2 className="text-xl font-semibold">Strategic Project Status — 2025</h2>
              <p className="text-brand-sand">Weekly progress tracking across quarters</p>
            </div>
          </div>
        </CardHeader>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                {/* Quarter Headers */}
                <TableRow>
                  <TableHead className="w-20 sticky left-0 bg-white border-r"></TableHead>
                  {quarters.map((quarter) => {
                    const project = getProjectForQuarter(quarter);
                    return (
                      <TableHead 
                        key={quarter}
                        className="text-center bg-brand-sand text-brand-ink border-l border-r p-4 min-w-48"
                      >
                        <div className="font-semibold text-lg">{quarter}</div>
                        {project && (
                          <div className="text-sm mt-1">
                            <div className="font-medium">{project.title}</div>
                            <div className="text-xs">Owner: {project.owner}</div>
                          </div>
                        )}
                      </TableHead>
                    );
                  })}
                </TableRow>
              </TableHeader>
              <TableBody>
                {/* Week Rows */}
                {weeks.map((week) => (
                  <TableRow key={week} className={week % 2 === 0 ? 'bg-gray-50' : ''}>
                    <TableCell className="font-medium sticky left-0 bg-inherit border-r text-center">
                      WK{week}
                    </TableCell>
                    {quarters.map((quarter) => {
                      const project = getProjectForQuarter(quarter);
                      const weekData = project?.weekly.find(w => w.week === week);
                      
                      return (
                        <TableCell key={`${quarter}-${week}`} className="p-2 border-l">
                          {project && weekData ? (
                            <div className="flex items-center space-x-2">
                              {/* Percent Input */}
                              <Input
                                type="number"
                                min="0"
                                max="100"
                                value={weekData.percent}
                                onChange={(e) => handlePercentChange(quarter, week, e.target.value)}
                                className="w-16 h-8 text-xs text-center"
                              />
                              <span className="text-xs text-gray-500">%</span>
                              
                              {/* Status Select */}
                              <Select
                                value={weekData.status}
                                onValueChange={(value) => handleStatusChange(quarter, week, value as Status)}
                              >
                                <SelectTrigger className="w-32 h-8 text-xs">
                                  <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                  <SelectItem value="Not Started">Not Started</SelectItem>
                                  <SelectItem value="On Target">On Target</SelectItem>
                                  <SelectItem value="At Risk">At Risk</SelectItem>
                                  <SelectItem value="Off Track">Off Track</SelectItem>
                                  <SelectItem value="Blocked">Blocked</SelectItem>
                                  <SelectItem value="Done">Done</SelectItem>
                                </SelectContent>
                              </Select>
                              
                              {/* Status Badge */}
                              <Badge className={getStatusColor(weekData.status)}>
                                {weekData.status}
                              </Badge>
                            </div>
                          ) : (
                            <div className="text-xs text-gray-400 italic text-center">
                              No project
                            </div>
                          )}
                        </TableCell>
                      );
                    })}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </CardContent>
      </Card>

      {/* Project Issues Section */}
      <Card>
        <CardHeader className="bg-red-100 border-b border-red-200">
          <h3 className="text-lg font-semibold text-red-900">PROJECT ISSUES</h3>
          <p className="text-sm text-red-700">Current blockers and challenges by quarter</p>
        </CardHeader>
        <CardContent className="p-6">
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            {quarters.map((quarter) => {
              const project = getProjectForQuarter(quarter);
              return (
                <div key={quarter} className="space-y-2">
                  <label className="text-sm font-medium text-gray-700">
                    {quarter} Issues
                  </label>
                  <Textarea
                    value={project?.issues || ''}
                    onChange={(e) => handleIssuesChange(quarter, e.target.value)}
                    placeholder={`Enter ${quarter} project issues...`}
                    rows={4}
                    className="text-sm"
                  />
                </div>
              );
            })}
          </div>
        </CardContent>
      </Card>

      {/* Next Actions Section */}
      <Card>
        <CardHeader className="bg-blue-100 border-b border-blue-200">
          <h3 className="text-lg font-semibold text-blue-900">NEXT ACTIONS</h3>
          <p className="text-sm text-blue-700">Planned next steps and priorities by quarter</p>
        </CardHeader>
        <CardContent className="p-6">
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            {quarters.map((quarter) => {
              const project = getProjectForQuarter(quarter);
              return (
                <div key={quarter} className="space-y-2">
                  <label className="text-sm font-medium text-gray-700">
                    {quarter} Next Actions
                  </label>
                  <Textarea
                    value={project?.nextActions || ''}
                    onChange={(e) => handleNextActionsChange(quarter, e.target.value)}
                    placeholder={`Enter ${quarter} next actions...`}
                    rows={4}
                    className="text-sm"
                  />
                </div>
              );
            })}
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default ProjectStatusTable;