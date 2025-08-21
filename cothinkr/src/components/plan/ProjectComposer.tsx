'use client';

import React from 'react';
import { Card, CardHeader, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useAppStore, useInitiativesByStatus } from '@/lib/store';
import { smartifyProject } from '@/lib/ai';
import { Quarter } from '@/lib/types';
import { toast } from 'sonner';

const ProjectComposer: React.FC = () => {
  const { addProject } = useAppStore();
  const { approved: approvedInitiatives } = useInitiativesByStatus();
  
  const [formData, setFormData] = React.useState({
    year: 2025,
    quarter: '' as Quarter | '',
    initiativeId: '',
    owner: '',
    title: '',
    suggestion: ''
  });
  
  const [isLoading, setIsLoading] = React.useState(false);

  const handleSubmit = async () => {
    if (!formData.title.trim()) {
      toast.error('Please enter a project title');
      return;
    }

    setIsLoading(true);
    try {
      const result = await smartifyProject({
        title: formData.title,
        description: ''
      });
      
      setFormData(prev => ({
        ...prev,
        suggestion: result.description
      }));
      
      toast.success('AI suggestion generated');
    } catch (error) {
      toast.error('Failed to generate suggestion');
    } finally {
      setIsLoading(false);
    }
  };

  const handleAccept = () => {
    if (!formData.quarter || !formData.initiativeId || !formData.owner || !formData.title) {
      toast.error('Please fill in all required fields');
      return;
    }

    const newProject = {
      id: `proj-${Date.now()}`,
      quarter: formData.quarter as Quarter,
      initiativeId: formData.initiativeId,
      title: formData.title,
      owner: formData.owner,
      weekly: Array.from({ length: 13 }, (_, i) => ({
        week: i + 1,
        percent: 0,
        status: 'Not Started' as const
      })),
      issues: '',
      nextActions: ''
    };

    addProject(newProject);
    
    // Reset form
    setFormData({
      year: 2025,
      quarter: '',
      initiativeId: '',
      owner: '',
      title: '',
      suggestion: ''
    });

    toast.success('Project added successfully');
  };

  return (
    <Card className="mt-6">
      <CardHeader>
        <h3 className="text-lg font-semibold text-gray-900">Create New Project</h3>
        <p className="text-sm text-gray-600">Define and plan new strategic projects</p>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          {/* Year */}
          <div className="space-y-2">
            <label className="text-sm font-medium text-gray-700">Year</label>
            <Input
              type="number"
              value={formData.year}
              onChange={(e) => setFormData(prev => ({ ...prev, year: parseInt(e.target.value) || 2025 }))}
            />
          </div>

          {/* Quarter */}
          <div className="space-y-2">
            <label className="text-sm font-medium text-gray-700">Quarter</label>
            <Select
              value={formData.quarter}
              onValueChange={(value) => setFormData(prev => ({ ...prev, quarter: value as Quarter }))}
            >
              <SelectTrigger>
                <SelectValue placeholder="Select quarter" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="Q1">Q1</SelectItem>
                <SelectItem value="Q2">Q2</SelectItem>
                <SelectItem value="Q3">Q3</SelectItem>
                <SelectItem value="Q4">Q4</SelectItem>
              </SelectContent>
            </Select>
          </div>

          {/* Initiative */}
          <div className="space-y-2">
            <label className="text-sm font-medium text-gray-700">Initiative</label>
            <Select
              value={formData.initiativeId}
              onValueChange={(value) => setFormData(prev => ({ ...prev, initiativeId: value }))}
            >
              <SelectTrigger>
                <SelectValue placeholder="Select initiative" />
              </SelectTrigger>
              <SelectContent>
                {approvedInitiatives.map((initiative) => (
                  <SelectItem key={initiative.id} value={initiative.id}>
                    Initiative {initiative.idx}: {initiative.title}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {/* Owner */}
          <div className="space-y-2">
            <label className="text-sm font-medium text-gray-700">Owner</label>
            <Input
              value={formData.owner}
              onChange={(e) => setFormData(prev => ({ ...prev, owner: e.target.value }))}
              placeholder="Project owner name"
            />
          </div>
        </div>

        {/* Project Title */}
        <div className="space-y-2">
          <label className="text-sm font-medium text-gray-700">Project Title (Draft)</label>
          <Input
            value={formData.title}
            onChange={(e) => setFormData(prev => ({ ...prev, title: e.target.value }))}
            placeholder="Enter project title..."
          />
        </div>

        {/* AI Suggestion */}
        <div className="space-y-2">
          <label className="text-sm font-medium text-gray-700">AI Suggestion</label>
          <Textarea
            value={formData.suggestion}
            onChange={(e) => setFormData(prev => ({ ...prev, suggestion: e.target.value }))}
            placeholder="AI suggestions will appear here..."
            rows={3}
          />
        </div>

        {/* Action Buttons */}
        <div className="flex space-x-2">
          <Button
            onClick={handleSubmit}
            disabled={isLoading || !formData.title.trim()}
            className="bg-brand-brown hover:bg-brand-brown/90"
          >
            {isLoading ? 'Generating...' : 'Submit'}
          </Button>
          <Button
            onClick={handleAccept}
            disabled={!formData.quarter || !formData.initiativeId || !formData.owner || !formData.title}
            variant="outline"
          >
            Accept
          </Button>
        </div>
      </CardContent>
    </Card>
  );
};

export default ProjectComposer;