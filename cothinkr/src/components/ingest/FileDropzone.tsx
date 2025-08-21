'use client';

import React, { useCallback, useState } from 'react';
import { useDropzone } from 'react-dropzone';
import { Upload, File, FileSpreadsheet, FileText, X, Check, AlertCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';

interface FileUpload {
  id: string;
  file: File;
  status: 'uploading' | 'processing' | 'completed' | 'error';
  progress: number;
  result?: any;
  error?: string;
}

interface FileDropzoneProps {
  acceptedTypes: string[];
  maxSize: number; // in MB
  onFileProcessed: (file: File, result: any) => void;
  className?: string;
  title?: string;
  description?: string;
  multiple?: boolean;
}

const FileDropzone: React.FC<FileDropzoneProps> = ({
  acceptedTypes = ['.csv', '.xlsx', '.pdf', '.txt', '.md'],
  maxSize = 10,
  onFileProcessed,
  className = '',
  title = 'Upload Files',
  description = 'Drag and drop files here, or click to select',
  multiple = true
}) => {
  const [uploads, setUploads] = useState<FileUpload[]>([]);

  const processFile = async (file: File): Promise<any> => {
    return new Promise((resolve, reject) => {
      if (file.type === 'text/csv' || file.name.endsWith('.csv')) {
        // Process CSV file
        const reader = new FileReader();
        reader.onload = (e) => {
          try {
            const text = e.target?.result as string;
            const lines = text.split('\n');
            const headers = lines[0].split(',');
            const data = lines.slice(1).map(line => {
              const values = line.split(',');
              return headers.reduce((obj, header, index) => {
                obj[header.trim()] = values[index]?.trim() || '';
                return obj;
              }, {} as any);
            });
            resolve({ type: 'csv', headers, data, rowCount: data.length });
          } catch (error) {
            reject(new Error('Failed to parse CSV file'));
          }
        };
        reader.onerror = () => reject(new Error('Failed to read file'));
        reader.readAsText(file);
      } else if (file.type === 'application/pdf' || file.name.endsWith('.pdf')) {
        // Process PDF file (mock processing)
        setTimeout(() => {
          resolve({ 
            type: 'pdf', 
            pageCount: Math.floor(Math.random() * 20) + 1,
            textExtracted: `Extracted content from ${file.name}...`,
            size: file.size
          });
        }, 2000);
      } else if (file.type === 'text/plain' || file.name.endsWith('.txt') || file.name.endsWith('.md')) {
        // Process text file
        const reader = new FileReader();
        reader.onload = (e) => {
          const text = e.target?.result as string;
          resolve({
            type: 'text',
            content: text,
            wordCount: text.split(/\s+/).length,
            charCount: text.length
          });
        };
        reader.onerror = () => reject(new Error('Failed to read text file'));
        reader.readAsText(file);
      } else {
        reject(new Error('Unsupported file type'));
      }
    });
  };

  const simulateUpload = async (uploadId: string) => {
    // Simulate upload progress
    for (let progress = 0; progress <= 100; progress += 10) {
      await new Promise(resolve => setTimeout(resolve, 100));
      setUploads(prev => prev.map(upload => 
        upload.id === uploadId 
          ? { ...upload, progress }
          : upload
      ));
    }
  };

  const onDrop = useCallback(async (acceptedFiles: File[]) => {
    const newUploads: FileUpload[] = acceptedFiles.map(file => ({
      id: Math.random().toString(36).substr(2, 9),
      file,
      status: 'uploading',
      progress: 0
    }));

    setUploads(prev => [...prev, ...newUploads]);

    // Process each file
    for (const upload of newUploads) {
      try {
        // Simulate upload
        await simulateUpload(upload.id);
        
        // Update to processing
        setUploads(prev => prev.map(u => 
          u.id === upload.id 
            ? { ...u, status: 'processing', progress: 0 }
            : u
        ));

        // Process file content
        const result = await processFile(upload.file);
        
        // Update to completed
        setUploads(prev => prev.map(u => 
          u.id === upload.id 
            ? { ...u, status: 'completed', progress: 100, result }
            : u
        ));

        // Notify parent component
        onFileProcessed(upload.file, result);
        toast.success(`${upload.file.name} processed successfully`);

      } catch (error) {
        setUploads(prev => prev.map(u => 
          u.id === upload.id 
            ? { 
                ...u, 
                status: 'error', 
                error: error instanceof Error ? error.message : 'Unknown error' 
              }
            : u
        ));
        toast.error(`Failed to process ${upload.file.name}`);
      }
    }
  }, [onFileProcessed]);

  const { getRootProps, getInputProps, isDragActive, isDragReject } = useDropzone({
    onDrop,
    accept: acceptedTypes.reduce((acc, type) => {
      if (type === '.csv') acc['text/csv'] = ['.csv'];
      else if (type === '.xlsx') acc['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'] = ['.xlsx'];
      else if (type === '.pdf') acc['application/pdf'] = ['.pdf'];
      else if (type === '.txt') acc['text/plain'] = ['.txt'];
      else if (type === '.md') acc['text/markdown'] = ['.md'];
      return acc;
    }, {} as any),
    maxSize: maxSize * 1024 * 1024, // Convert MB to bytes
    multiple
  });

  const removeUpload = (id: string) => {
    setUploads(prev => prev.filter(upload => upload.id !== id));
  };

  const getFileIcon = (file: File) => {
    if (file.type === 'text/csv' || file.name.endsWith('.csv')) return FileSpreadsheet;
    if (file.type === 'application/pdf') return FileText;
    return File;
  };

  const getStatusIcon = (status: FileUpload['status']) => {
    switch (status) {
      case 'completed': return Check;
      case 'error': return AlertCircle;
      default: return Upload;
    }
  };

  const getStatusColor = (status: FileUpload['status']) => {
    switch (status) {
      case 'completed': return 'text-green-600';
      case 'error': return 'text-red-600';
      case 'uploading': case 'processing': return 'text-blue-600';
      default: return 'text-gray-600';
    }
  };

  return (
    <div className={cn('space-y-4', className)}>
      {/* Dropzone */}
      <div
        {...getRootProps()}
        className={cn(
          'relative border-2 border-dashed rounded-lg p-8 text-center cursor-pointer transition-colors',
          'hover:border-brand-brown/50 hover:bg-brand-sand/20',
          isDragActive && !isDragReject && 'border-brand-brown bg-brand-sand/30',
          isDragReject && 'border-red-500 bg-red-50',
          'focus:outline-none focus:ring-2 focus:ring-brand-brown focus:ring-offset-2'
        )}
      >
        <input {...getInputProps()} />
        
        <div className="flex flex-col items-center space-y-3">
          <Upload 
            className={cn(
              'w-12 h-12 transition-colors',
              isDragActive && !isDragReject ? 'text-brand-brown' : 'text-gray-400'
            )} 
          />
          
          <div>
            <h3 className="text-lg font-medium text-gray-900">{title}</h3>
            <p className="text-sm text-gray-500 mt-1">{description}</p>
          </div>
          
          <div className="text-xs text-gray-400">
            Accepted: {acceptedTypes.join(', ')} • Max size: {maxSize}MB
            {isDragReject && (
              <div className="text-red-500 font-medium mt-1">
                Some files are not supported or too large
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Upload Progress */}
      {uploads.length > 0 && (
        <Card>
          <CardContent className="p-4">
            <h4 className="font-medium mb-3">File Processing</h4>
            <div className="space-y-3">
              {uploads.map((upload) => {
                const FileIcon = getFileIcon(upload.file);
                const StatusIcon = getStatusIcon(upload.status);
                const statusColor = getStatusColor(upload.status);
                
                return (
                  <div key={upload.id} className="flex items-center space-x-3">
                    <FileIcon className="w-5 h-5 text-gray-400 flex-shrink-0" />
                    
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center justify-between">
                        <p className="text-sm font-medium text-gray-900 truncate">
                          {upload.file.name}
                        </p>
                        <div className="flex items-center space-x-2">
                          <StatusIcon className={cn('w-4 h-4', statusColor)} />
                          <span className={cn('text-xs font-medium', statusColor)}>
                            {upload.status}
                          </span>
                        </div>
                      </div>
                      
                      <div className="flex items-center justify-between mt-1">
                        <p className="text-xs text-gray-500">
                          {(upload.file.size / 1024 / 1024).toFixed(2)} MB
                        </p>
                        {upload.result && (
                          <p className="text-xs text-gray-500">
                            {upload.result.type === 'csv' && `${upload.result.rowCount} rows`}
                            {upload.result.type === 'pdf' && `${upload.result.pageCount} pages`}
                            {upload.result.type === 'text' && `${upload.result.wordCount} words`}
                          </p>
                        )}
                      </div>
                      
                      {(upload.status === 'uploading' || upload.status === 'processing') && (
                        <Progress value={upload.progress} className="mt-2" />
                      )}
                      
                      {upload.error && (
                        <p className="text-xs text-red-600 mt-1">{upload.error}</p>
                      )}
                    </div>
                    
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => removeUpload(upload.id)}
                      className="flex-shrink-0"
                    >
                      <X className="w-4 h-4" />
                    </Button>
                  </div>
                );
              })}
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
};

export default FileDropzone;