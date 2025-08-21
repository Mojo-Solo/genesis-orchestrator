'use client';

import React from 'react';
import { Card, CardHeader, CardContent } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Badge } from '@/components/ui/badge';
import InitiativeCard from '@/components/plan/InitiativeCard';
import ProjectComposer from '@/components/plan/ProjectComposer';
import { useAppStore, useInitiativesByStatus, useProjectsByQuarter } from '@/lib/store';

const StrategicPlanPage: React.FC = () => {
  const { initiatives } = useAppStore();
  const { approved: approvedInitiatives } = useInitiativesByStatus();
  const projectsByQuarter = useProjectsByQuarter();

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div>
        <h1 className="text-3xl font-bold text-gray-900">Strategic Plan Builder</h1>
        <p className="text-gray-600">Develop and manage strategic initiatives and projects</p>
      </div>

      {/* Main Content Tabs */}
      <Tabs defaultValue="initiatives" className="space-y-6">
        <TabsList className="grid w-full grid-cols-2">
          <TabsTrigger value="initiatives">Initiatives</TabsTrigger>
          <TabsTrigger value="projects">Projects</TabsTrigger>
        </TabsList>

        {/* Initiatives Tab */}
        <TabsContent value="initiatives" className="space-y-6">
          {/* Approved Initiatives Display */}
          <Card>
            <CardHeader className="bg-brand-brown text-white">
              <h2 className="text-xl font-semibold">Approved Initiatives</h2>
              <p className="text-brand-sand">Strategic initiatives ready for execution</p>
            </CardHeader>
            <CardContent className="p-6">
              {approvedInitiatives.length > 0 ? (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                  {approvedInitiatives.map((initiative) => (
                    <Card key={initiative.id} className="border-green-200 bg-green-50">
                      <CardContent className="p-4">
                        <div className="flex items-start justify-between mb-2">
                          <h3 className="font-semibold text-green-900">
                            Initiative {initiative.idx}: {initiative.title}
                          </h3>
                          <Badge className="bg-green-500">Approved</Badge>
                        </div>
                        <p className="text-sm text-green-700 mb-2">
                          {initiative.description}
                        </p>
                        <div className="text-xs text-green-600">
                          Owner: {initiative.owner} • Year: {initiative.year}
                        </div>
                      </CardContent>
                    </Card>
                  ))}
                </div>
              ) : (
                <div className="text-center py-8 text-gray-500">
                  No approved initiatives yet. Complete the initiative workflow below to approve initiatives.
                </div>
              )}
            </CardContent>
          </Card>

          {/* Initiative Workflow Cards */}
          <div className="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-4 gap-6">
            {initiatives.map((initiative) => (
              <InitiativeCard
                key={initiative.id}
                initiative={initiative}
              />
            ))}
          </div>
        </TabsContent>

        {/* Projects Tab */}
        <TabsContent value="projects" className="space-y-6">
          {/* Approved Projects Display */}
          <Card>
            <CardHeader className="bg-brand-brown text-white">
              <h2 className="text-xl font-semibold">Approved Projects</h2>
              <p className="text-brand-sand">Quarterly project execution plan</p>
            </CardHeader>
            <CardContent className="p-6">
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                {['Q1', 'Q2', 'Q3', 'Q4'].map((quarter) => (
                  <Card key={quarter} className="border-blue-200 bg-blue-50">
                    <CardHeader className="pb-3">
                      <h3 className="font-semibold text-blue-900">{quarter} Projects</h3>
                      <div className="text-sm text-blue-700">
                        {projectsByQuarter[quarter as keyof typeof projectsByQuarter]?.length || 0} projects
                      </div>
                    </CardHeader>
                    <CardContent className="space-y-3">
                      {projectsByQuarter[quarter as keyof typeof projectsByQuarter]?.map((project) => (
                        <div key={project.id} className="p-3 bg-white rounded border border-blue-200">
                          <div className="font-medium text-blue-900 text-sm">
                            {project.title}
                          </div>
                          <div className="text-xs text-blue-600 mt-1">
                            Owner: {project.owner}
                          </div>
                        </div>
                      )) || (
                        <div className="text-sm text-blue-600 italic">
                          No projects planned
                        </div>
                      )}
                    </CardContent>
                  </Card>
                ))}
              </div>
            </CardContent>
          </Card>

          {/* Project Creation Form */}
          <ProjectComposer />
        </TabsContent>
      </Tabs>

      {/* Strategic Planning Guidelines */}
      <Card>
        <CardHeader>
          <h3 className="text-lg font-semibold text-gray-900">Strategic Planning Guidelines</h3>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <h4 className="font-medium text-gray-900 mb-2">Initiative Development</h4>
              <ul className="text-sm text-gray-600 space-y-1">
                <li>• Start with a clear draft title in the Draft field</li>
                <li>• Click Submit to generate AI-powered SMART suggestions</li>
                <li>• Review and edit the suggestion as needed</li>
                <li>• Click Accept to approve and move to execution</li>
              </ul>
            </div>
            <div>
              <h4 className="font-medium text-gray-900 mb-2">Project Planning</h4>
              <ul className="text-sm text-gray-600 space-y-1">
                <li>• Select an approved initiative as the foundation</li>
                <li>• Choose the appropriate quarter for execution</li>
                <li>• Assign a project owner for accountability</li>
                <li>• Use AI suggestions to refine project scope</li>
              </ul>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default StrategicPlanPage;