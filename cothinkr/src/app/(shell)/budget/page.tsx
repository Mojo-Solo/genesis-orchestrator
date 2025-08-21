'use client';

import React from 'react';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Button } from '@/components/ui/button';
import { Upload, Download, RefreshCw } from 'lucide-react';
import BudgetTable from '@/components/tables/BudgetTable';
import { useAppStore } from '@/lib/store';
import { toast } from 'sonner';

const BudgetPage: React.FC = () => {
  const { resetDemo } = useAppStore();

  const handleUploadCSV = (type: 'plan' | 'actual') => {
    // In a real app, this would open a file picker and process CSV
    toast.info(`CSV upload for ${type} budget would be implemented here`);
  };

  const handleExport = () => {
    // In a real app, this would export budget data
    toast.info('Budget export functionality would be implemented here');
  };

  const handleRefresh = () => {
    // In a real app, this would refresh data from backend
    toast.success('Budget data refreshed');
  };

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold text-gray-900">Budget</h1>
          <p className="text-gray-600">Financial planning and performance tracking</p>
        </div>
        
        {/* Action Buttons */}
        <div className="flex space-x-2">
          <Button
            variant="outline"
            onClick={() => handleUploadCSV('plan')}
            className="flex items-center space-x-2"
          >
            <Upload className="w-4 h-4" />
            <span>Upload Plan</span>
          </Button>
          <Button
            variant="outline"
            onClick={() => handleUploadCSV('actual')}
            className="flex items-center space-x-2"
          >
            <Upload className="w-4 h-4" />
            <span>Upload Actual</span>
          </Button>
          <Button
            variant="outline"
            onClick={handleExport}
            className="flex items-center space-x-2"
          >
            <Download className="w-4 h-4" />
            <span>Export</span>
          </Button>
          <Button
            variant="outline"
            onClick={handleRefresh}
            className="flex items-center space-x-2"
          >
            <RefreshCw className="w-4 h-4" />
            <span>Refresh</span>
          </Button>
        </div>
      </div>

      {/* Budget Tables */}
      <Tabs defaultValue="budget" className="space-y-6">
        <TabsList className="grid w-full grid-cols-3">
          <TabsTrigger value="budget">Budget</TabsTrigger>
          <TabsTrigger value="actual">Actual</TabsTrigger>
          <TabsTrigger value="variance">Variance</TabsTrigger>
        </TabsList>

        <TabsContent value="budget" className="space-y-4">
          <BudgetTable
            type="plan"
            title="2024 Budget Plan"
          />
        </TabsContent>

        <TabsContent value="actual" className="space-y-4">
          <BudgetTable
            type="actual"
            title="2024 Actual Performance"
          />
        </TabsContent>

        <TabsContent value="variance" className="space-y-4">
          <BudgetTable
            type="variance"
            title="2024 Budget vs Actual Variance"
          />
        </TabsContent>
      </Tabs>

      {/* Budget Analysis Summary */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mt-8">
        <div className="bg-blue-50 p-6 rounded-lg border border-blue-200">
          <h3 className="text-lg font-semibold text-blue-900 mb-2">Budget Overview</h3>
          <p className="text-sm text-blue-700">
            Annual budget planning with quarterly breakdowns across revenue, COGS, and operating expenses.
          </p>
        </div>
        
        <div className="bg-green-50 p-6 rounded-lg border border-green-200">
          <h3 className="text-lg font-semibold text-green-900 mb-2">Performance Tracking</h3>
          <p className="text-sm text-green-700">
            Real-time actual performance tracking with monthly granularity for accurate variance analysis.
          </p>
        </div>
        
        <div className="bg-amber-50 p-6 rounded-lg border border-amber-200">
          <h3 className="text-lg font-semibold text-amber-900 mb-2">Variance Analysis</h3>
          <p className="text-sm text-amber-700">
            Detailed variance tracking showing positive and negative deviations from planned targets.
          </p>
        </div>
      </div>

      {/* Instructions */}
      <div className="bg-gray-50 p-4 rounded-lg border">
        <h4 className="font-medium text-gray-900 mb-2">Instructions</h4>
        <ul className="text-sm text-gray-600 space-y-1">
          <li>• <strong>Budget Tab:</strong> Shows your planned financial targets for 2024</li>
          <li>• <strong>Actual Tab:</strong> Displays real performance data as it comes in</li>
          <li>• <strong>Variance Tab:</strong> Highlights differences between plan and actual (red = unfavorable)</li>
          <li>• <strong>CSV Upload:</strong> Import budget plans or actual data from external systems</li>
          <li>• <strong>Export:</strong> Download current budget data for external analysis</li>
        </ul>
      </div>
    </div>
  );
};

export default BudgetPage;