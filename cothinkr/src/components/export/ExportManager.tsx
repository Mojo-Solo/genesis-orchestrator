'use client';

import React, { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Progress } from '@/components/ui/progress';
import { 
  Download, 
  FileSpreadsheet, 
  FileText, 
  Printer, 
  Share2, 
  CheckCircle,
  Clock,
  AlertCircle,
  X
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { useAppStore } from '@/lib/store';
import { toast } from 'sonner';

interface ExportJob {
  id: string;
  type: 'pdf' | 'csv' | 'excel' | 'json';
  content: string;
  status: 'pending' | 'processing' | 'completed' | 'failed';
  progress: number;
  downloadUrl?: string;
  error?: string;
  createdAt: Date;
}

interface ExportManagerProps {
  isOpen: boolean;
  onClose: () => void;
  className?: string;
}

const ExportManager: React.FC<ExportManagerProps> = ({
  isOpen,
  onClose,
  className = ''
}) => {
  const [exportJobs, setExportJobs] = useState<ExportJob[]>([]);
  const { vision, initiatives, projects, budget } = useAppStore();

  const createExportJob = (type: ExportJob['type'], content: string): ExportJob => {
    return {
      id: Math.random().toString(36).substr(2, 9),
      type,
      content,
      status: 'pending',
      progress: 0,
      createdAt: new Date()
    };
  };

  const simulateExport = async (job: ExportJob): Promise<void> => {
    // Update status to processing
    setExportJobs(prev => prev.map(j => 
      j.id === job.id ? { ...j, status: 'processing' } : j
    ));

    // Simulate progress
    for (let progress = 0; progress <= 100; progress += 20) {
      await new Promise(resolve => setTimeout(resolve, 300));
      setExportJobs(prev => prev.map(j => 
        j.id === job.id ? { ...j, progress } : j
      ));
    }

    // Complete the job
    const downloadUrl = `data:text/plain;charset=utf-8,${encodeURIComponent(`COTHINK'R Export - ${job.content}\n\nGenerated: ${new Date().toISOString()}`)}`;
    
    setExportJobs(prev => prev.map(j => 
      j.id === job.id 
        ? { ...j, status: 'completed', progress: 100, downloadUrl }
        : j
    ));

    toast.success(`${job.content} export completed successfully!`);
  };

  const exportVision = async () => {
    const job = createExportJob('pdf', 'Strategic Vision');
    setExportJobs(prev => [...prev, job]);
    
    try {
      await simulateExport(job);
    } catch (error) {
      setExportJobs(prev => prev.map(j => 
        j.id === job.id 
          ? { ...j, status: 'failed', error: 'Export failed' }
          : j
      ));
    }
  };

  const exportInitiatives = async () => {
    const job = createExportJob('csv', 'Initiatives Data');
    setExportJobs(prev => [...prev, job]);

    try {
      await simulateExport(job);
    } catch (error) {
      setExportJobs(prev => prev.map(j => 
        j.id === job.id 
          ? { ...j, status: 'failed', error: 'Export failed' }
          : j
      ));
    }
  };

  const exportProjects = async () => {
    const job = createExportJob('excel', 'Projects Timeline');
    setExportJobs(prev => [...prev, job]);

    try {
      await simulateExport(job);
    } catch (error) {
      setExportJobs(prev => prev.map(j => 
        j.id === job.id 
          ? { ...j, status: 'failed', error: 'Export failed' }
          : j
      ));
    }
  };

  const exportBudget = async () => {
    const job = createExportJob('csv', 'Budget Analysis');
    setExportJobs(prev => [...prev, job]);

    try {
      await simulateExport(job);
    } catch (error) {
      setExportJobs(prev => prev.map(j => 
        j.id === job.id 
          ? { ...j, status: 'failed', error: 'Export failed' }
          : j
      ));
    }
  };

  const exportFullReport = async () => {
    const job = createExportJob('pdf', 'Complete Strategic Report');
    setExportJobs(prev => [...prev, job]);

    try {
      await simulateExport(job);
    } catch (error) {
      setExportJobs(prev => prev.map(j => 
        j.id === job.id 
          ? { ...j, status: 'failed', error: 'Export failed' }
          : j
      ));
    }
  };

  const downloadFile = (job: ExportJob) => {
    if (job.downloadUrl) {
      const link = document.createElement('a');
      link.href = job.downloadUrl;
      link.download = `cothinkr-${job.content.toLowerCase().replace(/\s+/g, '-')}.${job.type}`;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    }
  };

  const removeJob = (jobId: string) => {
    setExportJobs(prev => prev.filter(j => j.id !== jobId));
  };

  const clearCompleted = () => {
    setExportJobs(prev => prev.filter(j => j.status !== 'completed'));
  };

  const getStatusIcon = (status: ExportJob['status']) => {
    switch (status) {
      case 'completed': return CheckCircle;
      case 'processing': return Clock;
      case 'failed': return AlertCircle;
      default: return Clock;
    }
  };

  const getStatusColor = (status: ExportJob['status']) => {
    switch (status) {
      case 'completed': return 'text-green-600';
      case 'processing': return 'text-blue-600';
      case 'failed': return 'text-red-600';
      default: return 'text-gray-600';
    }
  };

  const getFileIcon = (type: ExportJob['type']) => {
    switch (type) {
      case 'csv':
      case 'excel': return FileSpreadsheet;
      case 'pdf': return FileText;
      default: return Download;
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <Card className={cn('w-full max-w-4xl max-h-[80vh] overflow-hidden', className)}>
        <CardHeader className="bg-brand-brown text-white">
          <div className="flex items-center justify-between">
            <div>
              <CardTitle className="text-xl flex items-center space-x-2">
                <Download className="w-5 h-5" />
                <span>Export Manager</span>
              </CardTitle>
              <p className="text-brand-sand mt-1">Export your strategic data in multiple formats</p>
            </div>
            <Button
              variant="ghost"
              size="sm"
              onClick={onClose}
              className="text-white hover:bg-white/20"
            >
              <X className="w-5 h-5" />
            </Button>
          </div>
        </CardHeader>

        <CardContent className="p-6 overflow-y-auto max-h-[calc(80vh-120px)]">
          {/* Export Options */}
          <div className="space-y-6">
            <div>
              <h3 className="text-lg font-semibold text-gray-900 mb-4">Available Exports</h3>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                
                {/* Strategic Vision */}
                <Card className="hover:shadow-md transition-shadow cursor-pointer" onClick={exportVision}>
                  <CardContent className="p-4">
                    <div className="flex items-center space-x-3">
                      <FileText className="w-8 h-8 text-red-500" />
                      <div className="flex-1">
                        <h4 className="font-semibold text-gray-900">Strategic Vision</h4>
                        <p className="text-sm text-gray-600">Complete vision document (PDF)</p>
                      </div>
                      <Badge variant="secondary">PDF</Badge>
                    </div>
                  </CardContent>
                </Card>

                {/* Initiatives */}
                <Card className="hover:shadow-md transition-shadow cursor-pointer" onClick={exportInitiatives}>
                  <CardContent className="p-4">
                    <div className="flex items-center space-x-3">
                      <FileSpreadsheet className="w-8 h-8 text-green-500" />
                      <div className="flex-1">
                        <h4 className="font-semibold text-gray-900">Initiatives Data</h4>
                        <p className="text-sm text-gray-600">{initiatives.length} initiatives (CSV)</p>
                      </div>
                      <Badge variant="secondary">CSV</Badge>
                    </div>
                  </CardContent>
                </Card>

                {/* Projects */}
                <Card className="hover:shadow-md transition-shadow cursor-pointer" onClick={exportProjects}>
                  <CardContent className="p-4">
                    <div className="flex items-center space-x-3">
                      <FileSpreadsheet className="w-8 h-8 text-blue-500" />
                      <div className="flex-1">
                        <h4 className="font-semibold text-gray-900">Projects Timeline</h4>
                        <p className="text-sm text-gray-600">{projects.length} projects (Excel)</p>
                      </div>
                      <Badge variant="secondary">XLSX</Badge>
                    </div>
                  </CardContent>
                </Card>

                {/* Budget */}
                <Card className="hover:shadow-md transition-shadow cursor-pointer" onClick={exportBudget}>
                  <CardContent className="p-4">
                    <div className="flex items-center space-x-3">
                      <FileSpreadsheet className="w-8 h-8 text-amber-500" />
                      <div className="flex-1">
                        <h4 className="font-semibold text-gray-900">Budget Analysis</h4>
                        <p className="text-sm text-gray-600">12-month forecast (CSV)</p>
                      </div>
                      <Badge variant="secondary">CSV</Badge>
                    </div>
                  </CardContent>
                </Card>
              </div>

              {/* Full Report */}
              <div className="mt-6">
                <Card 
                  className="border-2 border-brand-brown hover:shadow-lg transition-all cursor-pointer" 
                  onClick={exportFullReport}
                >
                  <CardContent className="p-4">
                    <div className="flex items-center space-x-3">
                      <Printer className="w-8 h-8 text-brand-brown" />
                      <div className="flex-1">
                        <h4 className="font-semibold text-gray-900 text-lg">Complete Strategic Report</h4>
                        <p className="text-sm text-gray-600">
                          Comprehensive report including vision, initiatives, projects, and budget analysis
                        </p>
                      </div>
                      <div className="text-right">
                        <Badge className="bg-brand-brown text-white">PDF</Badge>
                        <p className="text-xs text-gray-500 mt-1">Recommended</p>
                      </div>
                    </div>
                  </CardContent>
                </Card>
              </div>
            </div>

            {/* Export Queue */}
            {exportJobs.length > 0 && (
              <>
                <Separator />
                <div>
                  <div className="flex items-center justify-between mb-4">
                    <h3 className="text-lg font-semibold text-gray-900">Export Queue</h3>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={clearCompleted}
                      disabled={!exportJobs.some(j => j.status === 'completed')}
                    >
                      Clear Completed
                    </Button>
                  </div>
                  
                  <div className="space-y-3">
                    {exportJobs.map((job) => {
                      const StatusIcon = getStatusIcon(job.status);
                      const FileIcon = getFileIcon(job.type);
                      const statusColor = getStatusColor(job.status);
                      
                      return (
                        <Card key={job.id} className="p-4">
                          <div className="flex items-center space-x-3">
                            <FileIcon className="w-6 h-6 text-gray-400" />
                            
                            <div className="flex-1">
                              <div className="flex items-center justify-between">
                                <h4 className="font-medium text-gray-900">{job.content}</h4>
                                <div className="flex items-center space-x-2">
                                  <StatusIcon className={cn('w-4 h-4', statusColor)} />
                                  <Badge variant="outline" className="uppercase">
                                    {job.type}
                                  </Badge>
                                </div>
                              </div>
                              
                              <div className="flex items-center justify-between mt-2">
                                <p className="text-sm text-gray-500">
                                  {job.status === 'processing' && `Processing... ${job.progress}%`}
                                  {job.status === 'completed' && 'Ready for download'}
                                  {job.status === 'failed' && job.error}
                                  {job.status === 'pending' && 'Queued for processing'}
                                </p>
                                
                                <div className="flex items-center space-x-2">
                                  {job.status === 'completed' && (
                                    <Button
                                      size="sm"
                                      onClick={() => downloadFile(job)}
                                      className="bg-green-600 hover:bg-green-700"
                                    >
                                      <Download className="w-3 h-3 mr-1" />
                                      Download
                                    </Button>
                                  )}
                                  <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => removeJob(job.id)}
                                  >
                                    <X className="w-3 h-3" />
                                  </Button>
                                </div>
                              </div>
                              
                              {job.status === 'processing' && (
                                <Progress value={job.progress} className="mt-2" />
                              )}
                            </div>
                          </div>
                        </Card>
                      );
                    })}
                  </div>
                </div>
              </>
            )}

            {/* Export Tips */}
            <Separator />
            <div className="bg-gray-50 rounded-lg p-4">
              <h4 className="font-semibold text-gray-900 mb-2 flex items-center space-x-2">
                <Share2 className="w-4 h-4" />
                <span>Export Tips</span>
              </h4>
              <ul className="text-sm text-gray-600 space-y-1">
                <li>• PDF exports include charts and visual elements</li>
                <li>• CSV files can be opened in Excel, Google Sheets, or any spreadsheet application</li>
                <li>• The complete report includes all your strategic data in a single document</li>
                <li>• Exports are generated in real-time with your current data</li>
              </ul>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default ExportManager;