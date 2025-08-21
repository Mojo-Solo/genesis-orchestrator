'use client';

import React from 'react';
import { Card, CardHeader, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Initiative } from '@/lib/types';
import { useAppStore } from '@/lib/store';
import { smartifyInitiative } from '@/lib/ai';
import { toast } from 'sonner';

interface InitiativeCardProps {
  initiative: Initiative;
  className?: string;
}

const InitiativeCard: React.FC<InitiativeCardProps> = ({ initiative, className = '' }) => {
  const { updateInitiative } = useAppStore();
  const [isLoading, setIsLoading] = React.useState(false);

  const handleSubmit = async () => {
    if (!initiative.draft?.trim()) {
      toast.error('Please enter a draft title first');
      return;
    }

    setIsLoading(true);
    try {
      const result = await smartifyInitiative({
        title: initiative.draft,
        description: initiative.description || ''
      });
      
      updateInitiative(initiative.id, {
        suggestion: result.description
      });
      
      toast.success('AI suggestion generated');
    } catch (error) {
      toast.error('Failed to generate suggestion');
    } finally {
      setIsLoading(false);
    }
  };

  const handleAccept = () => {
    if (!initiative.suggestion?.trim()) {
      toast.error('No suggestion to accept');
      return;
    }

    updateInitiative(initiative.id, {
      title: initiative.draft || initiative.title,
      description: initiative.suggestion,
      approved: true,
      suggestion: '',
      draft: ''
    });

    toast.success('Initiative approved and updated');
  };

  const handleDraftChange = (value: string) => {
    updateInitiative(initiative.id, { draft: value });
  };

  return (
    <Card className={`h-fit ${className}`}>
      <CardHeader>
        <div className="flex items-center justify-between">
          <h3 className="text-lg font-semibold text-gray-900">
            Initiative {initiative.idx}
          </h3>
          {initiative.approved && (
            <Badge variant="default" className="bg-green-500">
              Approved
            </Badge>
          )}
        </div>
        <div className="text-sm text-gray-600">
          Owner: {initiative.owner} • Year: {initiative.year}
        </div>
      </CardHeader>
      
      <CardContent className="space-y-4">
        {/* Approved Section */}
        <div className="space-y-2">
          <label className="text-sm font-medium text-gray-700">
            Approved Initiative
          </label>
          <div className="min-h-20 p-3 bg-gray-50 rounded border text-sm">
            {initiative.approved ? (
              <div>
                <div className="font-medium mb-1">{initiative.title}</div>
                <div className="text-gray-600">{initiative.description}</div>
              </div>
            ) : (
              <div className="text-gray-400 italic">No approved initiative yet</div>
            )}
          </div>
        </div>

        {/* Year Input */}
        <div className="space-y-2">
          <label className="text-sm font-medium text-gray-700">
            Year
          </label>
          <Input
            type="number"
            value={initiative.year}
            onChange={(e) => updateInitiative(initiative.id, { year: parseInt(e.target.value) || 2024 })}
            className="w-full"
          />
        </div>

        {/* Draft Section */}
        <div className="space-y-2">
          <label className="text-sm font-medium text-gray-700">
            Draft
          </label>
          <Input
            value={initiative.draft || ''}
            onChange={(e) => handleDraftChange(e.target.value)}
            placeholder="Enter initiative title..."
            className="w-full"
          />
        </div>

        {/* Suggestion Section */}
        <div className="space-y-2">
          <label className="text-sm font-medium text-gray-700">
            Suggestion
          </label>
          <Textarea
            value={initiative.suggestion || ''}
            onChange={(e) => updateInitiative(initiative.id, { suggestion: e.target.value })}
            placeholder="AI suggestions will appear here..."
            rows={4}
            className="w-full"
          />
        </div>

        {/* Action Buttons */}
        <div className="flex space-x-2">
          <Button
            onClick={handleSubmit}
            disabled={isLoading || !initiative.draft?.trim()}
            className="flex-1 bg-brand-brown hover:bg-brand-brown/90"
          >
            {isLoading ? 'Generating...' : 'Submit'}
          </Button>
          <Button
            onClick={handleAccept}
            disabled={!initiative.suggestion?.trim()}
            variant="outline"
            className="flex-1"
          >
            Accept
          </Button>
        </div>

        {/* Progress Indicator */}
        <div className="flex items-center space-x-2 text-xs text-gray-500">
          <div className={`w-2 h-2 rounded-full ${initiative.draft ? 'bg-blue-500' : 'bg-gray-300'}`} />
          <span>Draft</span>
          <div className="w-4 h-px bg-gray-300" />
          <div className={`w-2 h-2 rounded-full ${initiative.suggestion ? 'bg-yellow-500' : 'bg-gray-300'}`} />
          <span>Suggestion</span>
          <div className="w-4 h-px bg-gray-300" />
          <div className={`w-2 h-2 rounded-full ${initiative.approved ? 'bg-green-500' : 'bg-gray-300'}`} />
          <span>Approved</span>
        </div>
      </CardContent>
    </Card>
  );
};

export default InitiativeCard;