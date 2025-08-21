'use client';

import React from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { RotateCcw, Plus } from 'lucide-react';
import InitiativeCard from '@/components/plan/InitiativeCard';
import { useAppStore } from '@/lib/store';
import { Initiative } from '@/lib/types';
import { toast } from 'sonner';

const InitiativesPage: React.FC = () => {
  const { initiatives, addInitiative, resetDemo } = useAppStore();

  const handleResetDemo = () => {
    resetDemo();
    toast.success('Demo data has been reset');
  };

  const handleAddInitiative = () => {
    const newId = `init-${Date.now()}`;
    const newIdx = Math.max(...initiatives.map(i => i.idx)) + 1;
    
    const newInitiative: Initiative = {
      id: newId,
      idx: newIdx as 1 | 2 | 3 | 4,
      clientId: 'client-1',
      title: `New Initiative ${newIdx}`,
      description: '',
      approved: false,
      year: 2024,
      owner: 'Unassigned',
      draft: '',
      suggestion: ''
    };

    addInitiative(newInitiative);
    toast.success('New initiative added');
  };

  const completedInitiatives = initiatives.filter(i => i.approved).length;
  const totalInitiatives = initiatives.length;

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold text-gray-900">Initiatives</h1>
          <p className="text-gray-600">
            Strategic initiative development and approval workflow
          </p>
          <div className="flex items-center space-x-4 mt-2">
            <div className="text-sm text-gray-600">
              <span className="font-medium text-green-600">{completedInitiatives}</span> of{' '}
              <span className="font-medium">{totalInitiatives}</span> approved
            </div>
            <div className="w-32 bg-gray-200 rounded-full h-2">
              <div 
                className="bg-green-500 h-2 rounded-full" 
                style={{ width: `${(completedInitiatives / totalInitiatives) * 100}%` }}
              />
            </div>
          </div>
        </div>
        
        {/* Action Buttons */}
        <div className="flex space-x-2">
          <Button
            onClick={handleAddInitiative}
            className="bg-brand-brown hover:bg-brand-brown/90"
          >
            <Plus className="w-4 h-4 mr-2" />
            Add Initiative
          </Button>
          
          <Dialog>
            <DialogTrigger asChild>
              <Button variant="outline">
                <RotateCcw className="w-4 h-4 mr-2" />
                Reset Demo
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Reset Demo Data</DialogTitle>
              </DialogHeader>
              <div className="space-y-4">
                <p className="text-sm text-gray-600">
                  This will reset all data to the original demo state. Are you sure you want to continue?
                </p>
                <div className="flex justify-end space-x-2">
                  <Button variant="outline" onClick={() => {}}>
                    Cancel
                  </Button>
                  <Button onClick={handleResetDemo} variant="destructive">
                    Reset Demo
                  </Button>
                </div>
              </div>
            </DialogContent>
          </Dialog>
        </div>
      </div>

      {/* Initiative Cards Grid */}
      <div className="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-4 gap-6">
        {initiatives
          .sort((a, b) => a.idx - b.idx)
          .map((initiative) => (
            <InitiativeCard
              key={initiative.id}
              initiative={initiative}
            />
          ))}
      </div>

      {/* Workflow Guide */}
      <div className="bg-blue-50 border border-blue-200 rounded-lg p-6">
        <h3 className="text-lg font-semibold text-blue-900 mb-3">
          Initiative Development Workflow
        </h3>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div className="space-y-2">
            <div className="flex items-center space-x-2">
              <div className="w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center text-xs font-bold">
                1
              </div>
              <span className="font-medium text-blue-900">Draft</span>
            </div>
            <p className="text-sm text-blue-700 ml-8">
              Enter your initiative idea in the Draft field. Keep it concise but descriptive.
            </p>
          </div>
          
          <div className="space-y-2">
            <div className="flex items-center space-x-2">
              <div className="w-6 h-6 bg-yellow-500 text-white rounded-full flex items-center justify-center text-xs font-bold">
                2
              </div>
              <span className="font-medium text-blue-900">AI Enhancement</span>
            </div>
            <p className="text-sm text-blue-700 ml-8">
              Click Submit to generate SMART criteria-based suggestions that improve your initiative.
            </p>
          </div>
          
          <div className="space-y-2">
            <div className="flex items-center space-x-2">
              <div className="w-6 h-6 bg-green-500 text-white rounded-full flex items-center justify-center text-xs font-bold">
                3
              </div>
              <span className="font-medium text-blue-900">Approval</span>
            </div>
            <p className="text-sm text-blue-700 ml-8">
              Review the suggestion, make any needed edits, then Accept to approve the initiative.
            </p>
          </div>
        </div>
      </div>

      {/* Statistics Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="bg-white p-6 rounded-lg border border-gray-200">
          <div className="text-2xl font-bold text-gray-900">{totalInitiatives}</div>
          <div className="text-sm text-gray-600">Total Initiatives</div>
        </div>
        
        <div className="bg-white p-6 rounded-lg border border-gray-200">
          <div className="text-2xl font-bold text-green-600">{completedInitiatives}</div>
          <div className="text-sm text-gray-600">Approved</div>
        </div>
        
        <div className="bg-white p-6 rounded-lg border border-gray-200">
          <div className="text-2xl font-bold text-yellow-600">
            {initiatives.filter(i => i.suggestion && !i.approved).length}
          </div>
          <div className="text-sm text-gray-600">Pending Review</div>
        </div>
        
        <div className="bg-white p-6 rounded-lg border border-gray-200">
          <div className="text-2xl font-bold text-blue-600">
            {initiatives.filter(i => i.draft && !i.suggestion).length}
          </div>
          <div className="text-sm text-gray-600">In Draft</div>
        </div>
      </div>
    </div>
  );
};

export default InitiativesPage;