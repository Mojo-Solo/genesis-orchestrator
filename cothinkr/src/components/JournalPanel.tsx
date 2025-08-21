'use client';

import React from 'react';
import { Card, CardHeader, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import { useAppStore } from '@/lib/store';
import { journalSummarize } from '@/lib/ai';
import { toast } from 'sonner';

const JournalPanel: React.FC = () => {
  const { journal, setJournalPrompt, setJournalSummary } = useAppStore();
  const [isLoading, setIsLoading] = React.useState(false);

  const handleSummarize = async () => {
    if (!journal.prompt.trim()) {
      toast.error('Please enter a journal prompt first');
      return;
    }

    setIsLoading(true);
    try {
      const summary = await journalSummarize(journal.prompt, []);
      setJournalSummary(summary);
      toast.success('Journal insights generated');
    } catch (error) {
      toast.error('Failed to generate insights');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <Card className="h-full">
      <CardHeader>
        <h3 className="text-lg font-semibold text-gray-900">Journal Insights</h3>
        <p className="text-sm text-gray-600">
          Enter a strategic prompt to generate AI-powered insights
        </p>
      </CardHeader>
      <CardContent className="space-y-4">
        {/* Prompt Input */}
        <div className="space-y-2">
          <label className="text-sm font-medium text-gray-700">
            Strategic Prompt
          </label>
          <Input
            value={journal.prompt}
            onChange={(e) => setJournalPrompt(e.target.value)}
            placeholder="Enter your strategic question or focus area..."
            className="w-full"
          />
        </div>

        {/* Summarize Button */}
        <Button 
          onClick={handleSummarize}
          disabled={isLoading || !journal.prompt.trim()}
          className="w-full bg-brand-brown hover:bg-brand-brown/90"
        >
          {isLoading ? 'Generating...' : 'Summarize'}
        </Button>

        {/* Summary Output */}
        <div className="space-y-2">
          <label className="text-sm font-medium text-gray-700">
            Strategic Insights
          </label>
          <Textarea
            value={journal.summary}
            readOnly
            rows={6}
            className="w-full bg-gray-50 text-sm"
            placeholder="Insights will appear here after generating summary..."
          />
        </div>

        {/* Quick Prompts */}
        <div className="space-y-2">
          <label className="text-sm font-medium text-gray-700">
            Quick Prompts
          </label>
          <div className="grid grid-cols-1 gap-2">
            {[
              'How are our initiatives progressing?',
              'What are the key risk areas?',
              'Which projects need attention?',
              'How is budget performance?'
            ].map((prompt) => (
              <Button
                key={prompt}
                variant="outline"
                size="sm"
                onClick={() => setJournalPrompt(prompt)}
                className="text-left justify-start text-xs"
              >
                {prompt}
              </Button>
            ))}
          </div>
        </div>
      </CardContent>
    </Card>
  );
};

export default JournalPanel;