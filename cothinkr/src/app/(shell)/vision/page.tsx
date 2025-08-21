'use client';

import React from 'react';
import { Card, CardHeader, CardContent } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import { useAppStore } from '@/lib/store';
import { VisionSection } from '@/lib/types';
import { toast } from 'sonner';

const visionSections = [
  {
    key: 'people' as keyof VisionSection,
    title: 'People',
    description: 'Define your vision for team, culture, and human capital development',
    placeholder: 'Describe your vision for building and developing your team, company culture, leadership structure, and employee experience...'
  },
  {
    key: 'salesMarketing' as keyof VisionSection,
    title: 'Sales & Marketing',
    description: 'Outline your go-to-market strategy and customer acquisition vision',
    placeholder: 'Detail your sales strategy, marketing approach, customer segments, revenue targets, and growth tactics...'
  },
  {
    key: 'geography' as keyof VisionSection,
    title: 'Geography / Locations',
    description: 'Map out your geographic expansion and location strategy',
    placeholder: 'Describe your geographic footprint, expansion plans, market penetration strategy, and location-based operations...'
  },
  {
    key: 'offerings' as keyof VisionSection,
    title: 'Offerings',
    description: 'Define your product and service portfolio vision',
    placeholder: 'Outline your products, services, value propositions, innovation pipeline, and customer solutions...'
  },
  {
    key: 'impact' as keyof VisionSection,
    title: 'Impact',
    description: 'Articulate your mission and the impact you want to create',
    placeholder: 'Define the positive impact you want to make on customers, community, industry, and society at large...'
  }
];

const VisionPage: React.FC = () => {
  const { vision, setVision } = useAppStore();
  const [hasChanges, setHasChanges] = React.useState<Record<string, boolean>>({});

  const handleSectionChange = (section: keyof VisionSection, value: string) => {
    setVision(section, value);
    setHasChanges(prev => ({ ...prev, [section]: true }));
  };

  const handleSave = (section: keyof VisionSection) => {
    // In a real app, this would save to a backend
    setHasChanges(prev => ({ ...prev, [section]: false }));
    toast.success(`${visionSections.find(s => s.key === section)?.title} vision saved`);
  };

  const handleSaveAll = () => {
    // Save all sections
    Object.keys(hasChanges).forEach(section => {
      setHasChanges(prev => ({ ...prev, [section]: false }));
    });
    toast.success('All vision sections saved');
  };

  const totalChanges = Object.values(hasChanges).filter(Boolean).length;

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold text-gray-900">Vision</h1>
          <p className="text-gray-600">Define your strategic vision across key business dimensions</p>
        </div>
        {totalChanges > 0 && (
          <Button 
            onClick={handleSaveAll}
            className="bg-brand-brown hover:bg-brand-brown/90"
          >
            Save All ({totalChanges})
          </Button>
        )}
      </div>

      {/* Vision Sections */}
      <div className="space-y-6">
        {visionSections.map((section) => (
          <Card key={section.key}>
            <CardHeader>
              <div className="flex justify-between items-start">
                <div>
                  <h2 className="text-xl font-semibold text-gray-900">
                    {section.title}
                  </h2>
                  <p className="text-sm text-gray-600 mt-1">
                    {section.description}
                  </p>
                </div>
                {hasChanges[section.key] && (
                  <Button
                    onClick={() => handleSave(section.key)}
                    size="sm"
                    className="bg-brand-brown hover:bg-brand-brown/90"
                  >
                    Save
                  </Button>
                )}
              </div>
            </CardHeader>
            <CardContent>
              <Textarea
                value={vision[section.key]}
                onChange={(e) => handleSectionChange(section.key, e.target.value)}
                placeholder={section.placeholder}
                className="min-h-40 resize-y"
                rows={6}
              />
              <div className="mt-2 text-xs text-gray-500">
                {vision[section.key].length} characters
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Vision Summary */}
      <Card>
        <CardHeader>
          <h2 className="text-xl font-semibold text-gray-900">Vision Summary</h2>
          <p className="text-sm text-gray-600">
            Overview of your strategic vision completeness
          </p>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
            {visionSections.map((section) => {
              const wordCount = vision[section.key].trim().split(/\s+/).filter(word => word.length > 0).length;
              const isComplete = wordCount >= 20; // Consider 20+ words as complete
              
              return (
                <div
                  key={section.key}
                  className={`p-4 rounded-lg border-2 ${
                    isComplete 
                      ? 'border-green-200 bg-green-50' 
                      : 'border-gray-200 bg-gray-50'
                  }`}
                >
                  <div className="text-sm font-medium text-gray-900">
                    {section.title}
                  </div>
                  <div className="text-xs text-gray-600 mt-1">
                    {wordCount} words
                  </div>
                  <div className={`text-xs mt-2 ${
                    isComplete ? 'text-green-600' : 'text-gray-500'
                  }`}>
                    {isComplete ? '✓ Complete' : 'In Progress'}
                  </div>
                </div>
              );
            })}
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default VisionPage;