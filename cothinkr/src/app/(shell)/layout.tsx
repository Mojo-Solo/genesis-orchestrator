import React from 'react';
import Sidebar, { DesktopSidebar, MobileSidebar } from '@/components/Sidebar';
import { Toaster } from '@/components/ui/sonner';

interface ShellLayoutProps {
  children: React.ReactNode;
}

const ShellLayout: React.FC<ShellLayoutProps> = ({ children }) => {
  return (
    <div className="h-screen flex bg-gray-50">
      {/* Desktop Sidebar */}
      <DesktopSidebar />
      
      {/* Main Content */}
      <div className="flex-1 lg:ml-64 flex flex-col min-h-0">
        {/* Mobile Header */}
        <div className="lg:hidden bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between">
          <div className="flex items-center space-x-3">
            <div className="w-6 h-6 bg-brand-brown rounded flex items-center justify-center">
              <span className="text-white font-bold text-sm">C</span>
            </div>
            <span className="text-lg font-bold text-brand-ink">COTHINK&apos;R</span>
          </div>
          <MobileSidebar />
        </div>

        {/* Page Content */}
        <main className="flex-1 overflow-auto">
          <div className="p-6">
            {children}
          </div>
        </main>
      </div>
      
      {/* Toast Notifications */}
      <Toaster />
    </div>
  );
};

export default ShellLayout;