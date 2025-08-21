'use client';

import React, { useState } from 'react';
import { Card, CardHeader, CardContent } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import GaugeDial from '@/components/charts/GaugeDial';
import BarBudget from '@/components/charts/BarBudget';
import JournalPanel from '@/components/JournalPanel';
import CardSpotlight from '@/components/ui/card-spotlight';
import FileDropzone from '@/components/ingest/FileDropzone';
import CothinkrBot from '@/components/bot/CothinkrBot';
import ActivityFeed from '@/components/collaboration/ActivityFeed';
import NotificationCenter from '@/components/notifications/NotificationCenter';
import { useAppStore } from '@/lib/store';
import { mockProgressBands } from '@/lib/mock';
import { Upload, TrendingUp, Target, DollarSign, Users, Sparkles, Bell } from 'lucide-react';
import { toast } from 'sonner';

const DashboardPage: React.FC = () => {
  const { gauges, initiatives, projects } = useAppStore();
  const [showFileUpload, setShowFileUpload] = useState(false);
  const [showNotifications, setShowNotifications] = useState(false);

  // Calculate dynamic stats
  const activeInitiatives = initiatives.filter(i => i.status === 'approved' || i.status === 'suggestion').length;
  const runningProjects = projects.filter(p => p.status === 'in-progress').length;
  const onTargetProjects = projects.filter(p => p.status === 'on-target').length;
  const totalProjects = projects.length;
  const onTargetPercentage = totalProjects > 0 ? Math.round((onTargetProjects / totalProjects) * 100) : 0;
  
  const handleFileProcessed = (file: File, result: any) => {
    toast.success(`File ${file.name} processed successfully!`);
    console.log('Processed file:', { file, result });
    // Here you would integrate with your data store
  };

  return (
    <div className="space-y-6">
      {/* Enhanced Page Header */}
      <div className="flex flex-col md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-3xl font-bold text-gray-900 flex items-center space-x-3">
            <TrendingUp className="w-8 h-8 text-brand-brown" />
            <span>Strategic Dashboard</span>
          </h1>
          <p className="text-gray-600 mt-1">Real-time insights and performance indicators for your strategic initiatives</p>
        </div>
        <div className="flex items-center space-x-2 mt-4 md:mt-0">
          <Button
            variant="outline"
            onClick={() => setShowNotifications(true)}
            className="flex items-center space-x-2 relative"
          >
            <Bell className="w-4 h-4" />
            <span>Notifications</span>
            <Badge className="absolute -top-2 -right-2 w-5 h-5 rounded-full p-0 text-xs bg-red-500">
              3
            </Badge>
          </Button>
          <Button
            variant="outline"
            onClick={() => setShowFileUpload(!showFileUpload)}
            className="flex items-center space-x-2"
          >
            <Upload className="w-4 h-4" />
            <span>Import Data</span>
          </Button>
          <Badge variant="secondary" className="bg-green-100 text-green-800">
            <Sparkles className="w-3 h-3 mr-1" />
            Live
          </Badge>
        </div>
      </div>

      {/* File Upload Panel */}
      {showFileUpload && (
        <CardSpotlight className="mb-6" spotlightColor="#8B5E3C">
          <CardContent className="p-6">
            <FileDropzone
              acceptedTypes={['.csv', '.xlsx', '.pdf', '.txt']}
              maxSize={10}
              onFileProcessed={handleFileProcessed}
              title="Import Strategic Data"
              description="Upload budget files, project data, or strategic documents to enhance your analysis"
            />
          </CardContent>
        </CardSpotlight>
      )}

      {/* Main Content Grid */}
      <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
        {/* Left Column - Enhanced Charts and Metrics */}
        <div className="lg:col-span-3 space-y-6">
          
          {/* Enhanced Vision Gauges */}
          <CardSpotlight spotlightColor="#10b981">
            <CardHeader>
              <div className="flex items-center justify-between">
                <div>
                  <h2 className="text-xl font-semibold text-gray-900 flex items-center space-x-2">
                    <Target className="w-5 h-5 text-brand-brown" />
                    <span>Vision Progress</span>
                  </h2>
                  <p className="text-sm text-gray-600">Strategic initiative performance indicators</p>
                </div>
                <Badge variant="outline" className="text-green-600 border-green-600">
                  Real-time
                </Badge>
              </div>
            </CardHeader>
            <CardContent>
              <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-6">
                {gauges.map((gauge, index) => (
                  <GaugeDial
                    key={index}
                    label={gauge.label}
                    value={gauge.value}
                    min={gauge.min}
                    max={gauge.max}
                    unit={gauge.unit}
                    size="md"
                    showAnimation={true}
                  />
                ))}
              </div>
            </CardContent>
          </CardSpotlight>

          {/* Enhanced Budget Chart */}
          <CardSpotlight spotlightColor="#f59e0b">
            <CardHeader>
              <div className="flex items-center space-x-2">
                <DollarSign className="w-5 h-5 text-brand-brown" />
                <h2 className="text-xl font-semibold text-gray-900">Financial Performance</h2>
              </div>
            </CardHeader>
            <CardContent className="p-6">
              <BarBudget />
            </CardContent>
          </CardSpotlight>

          {/* Enhanced Progress Bands */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            {/* Initiatives Progress */}
            <CardSpotlight spotlightColor="#8B5E3C">
              <CardHeader>
                <h3 className="text-lg font-semibold text-gray-900">Initiatives Progress</h3>
                <p className="text-sm text-gray-600">Implementation status across key areas</p>
              </CardHeader>
              <CardContent className="space-y-4">
                {mockProgressBands.initiatives.map((band, index) => (
                  <div key={index} className="space-y-2">
                    <div className="flex justify-between text-sm">
                      <span className="font-medium text-gray-700">{band.label}</span>
                      <Badge 
                        variant="secondary"
                        className={
                          band.progress >= 75 ? 'bg-green-100 text-green-800' :
                          band.progress >= 50 ? 'bg-yellow-100 text-yellow-800' :
                          'bg-red-100 text-red-800'
                        }
                      >
                        {band.progress}%
                      </Badge>
                    </div>
                    <Progress 
                      value={band.progress} 
                      className="h-3"
                    />
                  </div>
                ))}
              </CardContent>
            </CardSpotlight>

            {/* Projects Progress */}
            <CardSpotlight spotlightColor="#6366f1">
              <CardHeader>
                <h3 className="text-lg font-semibold text-gray-900">Projects Progress</h3>
                <p className="text-sm text-gray-600">Execution status across project phases</p>
              </CardHeader>
              <CardContent className="space-y-4">
                {mockProgressBands.projects.map((band, index) => (
                  <div key={index} className="space-y-2">
                    <div className="flex justify-between text-sm">
                      <span className="font-medium text-gray-700">{band.label}</span>
                      <Badge 
                        variant="secondary"
                        className={
                          band.progress >= 75 ? 'bg-green-100 text-green-800' :
                          band.progress >= 50 ? 'bg-yellow-100 text-yellow-800' :
                          'bg-red-100 text-red-800'
                        }
                      >
                        {band.progress}%
                      </Badge>
                    </div>
                    <Progress 
                      value={band.progress} 
                      className="h-3"
                    />
                  </div>
                ))}
              </CardContent>
            </CardSpotlight>
          </div>
        </div>

        {/* Enhanced Right Column - Activity & Journal */}
        <div className="lg:col-span-1 space-y-6">
          <ActivityFeed maxItems={10} />
          <CardSpotlight spotlightColor="#8B5E3C" className="h-fit">
            <JournalPanel />
          </CardSpotlight>
        </div>
      </div>

      {/* Enhanced Quick Stats Row */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <CardSpotlight spotlightColor="#10b981">
          <CardContent className="p-6">
            <div className="flex items-center justify-between">
              <div>
                <div className="text-2xl font-bold text-gray-900">{activeInitiatives}</div>
                <div className="text-sm text-gray-600">Active Initiatives</div>
              </div>
              <Target className="w-8 h-8 text-green-500" />
            </div>
          </CardContent>
        </CardSpotlight>
        
        <CardSpotlight spotlightColor="#3b82f6">
          <CardContent className="p-6">
            <div className="flex items-center justify-between">
              <div>
                <div className="text-2xl font-bold text-gray-900">{runningProjects}</div>
                <div className="text-sm text-gray-600">Running Projects</div>
              </div>
              <Users className="w-8 h-8 text-blue-500" />
            </div>
          </CardContent>
        </CardSpotlight>
        
        <CardSpotlight spotlightColor="#10b981">
          <CardContent className="p-6">
            <div className="flex items-center justify-between">
              <div>
                <div className="text-2xl font-bold text-green-600">{onTargetPercentage}%</div>
                <div className="text-sm text-gray-600">On Target</div>
              </div>
              <TrendingUp className="w-8 h-8 text-green-500" />
            </div>
          </CardContent>
        </CardSpotlight>
        
        <CardSpotlight spotlightColor="#8B5E3C">
          <CardContent className="p-6">
            <div className="flex items-center justify-between">
              <div>
                <div className="text-2xl font-bold text-brand-brown">Q{Math.ceil(new Date().getMonth() / 3)}</div>
                <div className="text-sm text-gray-600">Current Quarter</div>
              </div>
              <DollarSign className="w-8 h-8 text-brand-brown" />
            </div>
          </CardContent>
        </CardSpotlight>
      </div>

      {/* COTHINK'R Bot Integration */}
      <CothinkrBot />
      
      {/* Notification Center */}
      <NotificationCenter 
        isOpen={showNotifications} 
        onClose={() => setShowNotifications(false)} 
      />
    </div>
  );
};

export default DashboardPage;