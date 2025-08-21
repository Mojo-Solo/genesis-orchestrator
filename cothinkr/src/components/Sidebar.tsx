'use client';

import React from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { 
  LayoutDashboard, 
  Eye, 
  DollarSign, 
  Target, 
  CheckSquare, 
  FolderKanban,
  BarChart3,
  Menu,
  X
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetTrigger, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';

const navigation = [
  { name: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
  { name: 'Analytics', href: '/analytics', icon: BarChart3 },
  { name: 'Vision', href: '/vision', icon: Eye },
  { name: 'Budget', href: '/budget', icon: DollarSign },
  { name: 'Strategic Plan Builder', href: '/strategic-plan', icon: Target },
  { name: 'Initiatives', href: '/initiatives', icon: CheckSquare },
  { name: 'Projects', href: '/projects', icon: FolderKanban },
];

interface SidebarProps {
  className?: string;
}

const SidebarContent: React.FC<{ onItemClick?: () => void }> = ({ onItemClick }) => {
  const pathname = usePathname();

  return (
    <div className="flex flex-col h-full">
      {/* Logo */}
      <div className="p-6 border-b border-gray-200">
        <div className="flex items-center space-x-3">
          <div className="w-8 h-8 bg-brand-brown rounded-lg flex items-center justify-center">
            <span className="text-white font-bold text-lg">C</span>
          </div>
          <span className="text-xl font-bold text-brand-ink">COTHINK&apos;R</span>
        </div>
      </div>

      {/* Navigation */}
      <nav className="flex-1 p-4 space-y-2">
        {navigation.map((item) => {
          const isActive = pathname === item.href;
          const Icon = item.icon;
          
          return (
            <Link
              key={item.name}
              href={item.href}
              onClick={onItemClick}
              className={cn(
                'flex items-center space-x-3 px-3 py-3 rounded-lg text-sm font-medium transition-colors',
                isActive
                  ? 'bg-brand-brown text-white'
                  : 'text-gray-700 hover:bg-gray-100 hover:text-gray-900'
              )}
            >
              <Icon className="w-5 h-5" />
              <span>{item.name}</span>
            </Link>
          );
        })}
      </nav>

      {/* Footer */}
      <div className="p-4 border-t border-gray-200">
        <div className="text-xs text-gray-500 text-center">
          COTHINK&apos;R Demo v1.0
        </div>
      </div>
    </div>
  );
};

// Desktop Sidebar
export const DesktopSidebar: React.FC<SidebarProps> = ({ className }) => {
  return (
    <div className={cn('hidden lg:flex lg:flex-col lg:w-64 lg:fixed lg:inset-y-0 bg-white border-r border-gray-200', className)}>
      <SidebarContent />
    </div>
  );
};

// Mobile Sidebar
export const MobileSidebar: React.FC = () => {
  const [open, setOpen] = React.useState(false);
  
  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>
        <Button variant="ghost" size="icon" className="lg:hidden">
          <Menu className="h-6 w-6" />
          <span className="sr-only">Open sidebar</span>
        </Button>
      </SheetTrigger>
      <SheetContent side="left" className="w-64 p-0">
        <SidebarContent onItemClick={() => setOpen(false)} />
      </SheetContent>
    </Sheet>
  );
};

// Combined Sidebar component
const Sidebar: React.FC<SidebarProps> = ({ className }) => {
  return (
    <>
      <DesktopSidebar className={className} />
      <MobileSidebar />
    </>
  );
};

export default Sidebar;