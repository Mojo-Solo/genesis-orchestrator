'use client';

import React, { useState, useEffect } from 'react';
import { Card, CardHeader, CardContent, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { 
  Bell, BellRing, AlertTriangle, CheckCircle, Info, 
  X, Settings, Filter, Archive, Trash2, Clock,
  Target, DollarSign, Users, TrendingUp, Calendar,
  Zap, Shield, MessageSquare
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { useAppStore } from '@/lib/store';

interface Notification {
  id: string;
  type: 'info' | 'warning' | 'error' | 'success' | 'urgent';
  category: 'strategic' | 'financial' | 'operational' | 'system' | 'deadline' | 'collaboration';
  title: string;
  message: string;
  timestamp: Date;
  read: boolean;
  actionRequired: boolean;
  entityId?: string;
  entityType?: 'initiative' | 'project' | 'budget' | 'vision';
  metadata?: any;
}

interface NotificationCenterProps {
  isOpen: boolean;
  onClose: () => void;
  className?: string;
}

const NotificationCenter: React.FC<NotificationCenterProps> = ({
  isOpen,
  onClose,
  className = ''
}) => {
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [filteredNotifications, setFilteredNotifications] = useState<Notification[]>([]);
  const [selectedCategory, setSelectedCategory] = useState<string>('all');
  const [showOnlyUnread, setShowOnlyUnread] = useState(false);
  const [enableRealTime, setEnableRealTime] = useState(true);
  const { initiatives, projects, budget } = useAppStore();

  // Generate mock notifications
  useEffect(() => {
    const generateNotifications = (): Notification[] => {
      const now = new Date();
      const mockNotifications: Notification[] = [
        {
          id: '1',
          type: 'urgent',
          category: 'deadline',
          title: 'Project Deadline Approaching',
          message: 'Digital Transformation project is due in 3 days with 23% remaining work',
          timestamp: new Date(now.getTime() - 15 * 60 * 1000), // 15 minutes ago
          read: false,
          actionRequired: true,
          entityType: 'project',
          entityId: 'proj-1',
          metadata: { daysRemaining: 3, completion: 77 }
        },
        {
          id: '2',
          type: 'warning',
          category: 'financial',
          title: 'Budget Variance Alert',
          message: 'Q2 expenses are 15% over planned budget. Review allocation for Marketing initiatives.',
          timestamp: new Date(now.getTime() - 2 * 60 * 60 * 1000), // 2 hours ago
          read: false,
          actionRequired: true,
          entityType: 'budget',
          metadata: { variance: 15, category: 'marketing' }
        },
        {
          id: '3',
          type: 'success',
          category: 'strategic',
          title: 'Initiative Approved',
          message: 'Customer Experience Enhancement initiative has been approved and moved to execution phase',
          timestamp: new Date(now.getTime() - 4 * 60 * 60 * 1000), // 4 hours ago
          read: true,
          actionRequired: false,
          entityType: 'initiative',
          entityId: 'init-3'
        },
        {
          id: '4',
          type: 'info',
          category: 'collaboration',
          title: 'New Team Member Added',
          message: 'Sarah Wilson joined the Strategic Planning team and has been assigned to Vision development',
          timestamp: new Date(now.getTime() - 6 * 60 * 60 * 1000), // 6 hours ago
          read: true,
          actionRequired: false,
          metadata: { teamMember: 'Sarah Wilson', role: 'Vision Strategist' }
        },
        {
          id: '5',
          type: 'warning',
          category: 'operational',
          title: 'Resource Conflict',
          message: '2 projects competing for the same team resources in Q3. Priority alignment needed.',
          timestamp: new Date(now.getTime() - 1 * 24 * 60 * 60 * 1000), // 1 day ago
          read: false,
          actionRequired: true,
          metadata: { conflictingProjects: ['Project Alpha', 'Project Beta'] }
        },
        {
          id: '6',
          type: 'info',
          category: 'system',
          title: 'Weekly Report Generated',
          message: 'Your strategic performance report for week of June 12-18 is ready for review',
          timestamp: new Date(now.getTime() - 2 * 24 * 60 * 60 * 1000), // 2 days ago
          read: true,
          actionRequired: false,
          metadata: { reportType: 'weekly', period: 'June 12-18' }
        },
        {
          id: '7',
          type: 'success',
          category: 'strategic',
          title: 'Milestone Achieved',
          message: 'Product Launch initiative reached 90% completion ahead of schedule',
          timestamp: new Date(now.getTime() - 3 * 24 * 60 * 60 * 1000), // 3 days ago
          read: true,
          actionRequired: false,
          entityType: 'initiative',
          metadata: { completion: 90, status: 'ahead' }
        },
        {
          id: '8',
          type: 'error',
          category: 'system',
          title: 'Data Sync Issue',
          message: 'Budget data sync failed. Some financial metrics may be outdated.',
          timestamp: new Date(now.getTime() - 5 * 24 * 60 * 60 * 1000), // 5 days ago
          read: false,
          actionRequired: true,
          metadata: { affectedData: 'budget', lastSync: '5 days ago' }
        }
      ];

      return mockNotifications.sort((a, b) => b.timestamp.getTime() - a.timestamp.getTime());
    };

    setNotifications(generateNotifications());
  }, []);

  // Filter notifications
  useEffect(() => {
    let filtered = notifications;

    // Category filter
    if (selectedCategory !== 'all') {
      filtered = filtered.filter(n => n.category === selectedCategory);
    }

    // Unread filter
    if (showOnlyUnread) {
      filtered = filtered.filter(n => !n.read);
    }

    setFilteredNotifications(filtered);
  }, [notifications, selectedCategory, showOnlyUnread]);

  // Simulate real-time notifications
  useEffect(() => {
    if (!enableRealTime) return;

    const interval = setInterval(() => {
      // 5% chance of new notification every 30 seconds
      if (Math.random() < 0.05) {
        const newNotification: Notification = {
          id: `real-time-${Date.now()}`,
          type: 'info',
          category: 'operational',
          title: 'Real-time Update',
          message: 'Strategic metrics have been updated with latest performance data',
          timestamp: new Date(),
          read: false,
          actionRequired: false,
          metadata: { automated: true }
        };

        setNotifications(prev => [newNotification, ...prev]);
      }
    }, 30000);

    return () => clearInterval(interval);
  }, [enableRealTime]);

  const markAsRead = (id: string) => {
    setNotifications(prev =>
      prev.map(n => n.id === id ? { ...n, read: true } : n)
    );
  };

  const markAllAsRead = () => {
    setNotifications(prev =>
      prev.map(n => ({ ...n, read: true }))
    );
  };

  const deleteNotification = (id: string) => {
    setNotifications(prev => prev.filter(n => n.id !== id));
  };

  const getNotificationIcon = (type: Notification['type']) => {
    switch (type) {
      case 'success': return <CheckCircle className="w-5 h-5 text-green-500" />;
      case 'warning': return <AlertTriangle className="w-5 h-5 text-amber-500" />;
      case 'error': return <AlertTriangle className="w-5 h-5 text-red-500" />;
      case 'urgent': return <BellRing className="w-5 h-5 text-red-600" />;
      default: return <Info className="w-5 h-5 text-blue-500" />;
    }
  };

  const getCategoryIcon = (category: Notification['category']) => {
    switch (category) {
      case 'strategic': return <Target className="w-4 h-4" />;
      case 'financial': return <DollarSign className="w-4 h-4" />;
      case 'operational': return <Users className="w-4 h-4" />;
      case 'deadline': return <Clock className="w-4 h-4" />;
      case 'collaboration': return <MessageSquare className="w-4 h-4" />;
      case 'system': return <Settings className="w-4 h-4" />;
      default: return <Bell className="w-4 h-4" />;
    }
  };

  const formatTimestamp = (timestamp: Date): string => {
    const now = new Date();
    const diff = now.getTime() - timestamp.getTime();
    const minutes = Math.floor(diff / (1000 * 60));
    const hours = Math.floor(diff / (1000 * 60 * 60));
    const days = Math.floor(diff / (1000 * 60 * 60 * 24));

    if (minutes < 60) return `${minutes}m ago`;
    if (hours < 24) return `${hours}h ago`;
    if (days < 7) return `${days}d ago`;
    return timestamp.toLocaleDateString();
  };

  const unreadCount = notifications.filter(n => !n.read).length;
  const urgentCount = notifications.filter(n => n.type === 'urgent' && !n.read).length;

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <Card className={cn('w-full max-w-2xl max-h-[80vh] overflow-hidden', className)}>
        <CardHeader className="bg-brand-brown text-white">
          <div className="flex items-center justify-between">
            <div className="flex items-center space-x-3">
              <Bell className="w-6 h-6" />
              <div>
                <CardTitle className="text-xl">Notifications</CardTitle>
                <div className="flex items-center space-x-2 mt-1">
                  <Badge variant="secondary" className="bg-white/20 text-white">
                    {unreadCount} unread
                  </Badge>
                  {urgentCount > 0 && (
                    <Badge className="bg-red-600 text-white">
                      {urgentCount} urgent
                    </Badge>
                  )}
                </div>
              </div>
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

        <CardContent className="p-0">
          {/* Controls */}
          <div className="p-4 border-b bg-gray-50">
            <div className="flex items-center justify-between mb-3">
              <div className="flex items-center space-x-4">
                <div className="flex items-center space-x-2">
                  <Filter className="w-4 h-4 text-gray-500" />
                  <select
                    value={selectedCategory}
                    onChange={(e) => setSelectedCategory(e.target.value)}
                    className="text-sm border rounded px-2 py-1"
                  >
                    <option value="all">All Categories</option>
                    <option value="strategic">Strategic</option>
                    <option value="financial">Financial</option>
                    <option value="operational">Operational</option>
                    <option value="deadline">Deadlines</option>
                    <option value="collaboration">Collaboration</option>
                    <option value="system">System</option>
                  </select>
                </div>
                
                <div className="flex items-center space-x-2">
                  <Switch
                    checked={showOnlyUnread}
                    onCheckedChange={setShowOnlyUnread}
                    className="scale-75"
                  />
                  <span className="text-sm text-gray-600">Unread only</span>
                </div>
              </div>

              <div className="flex items-center space-x-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={markAllAsRead}
                  disabled={unreadCount === 0}
                >
                  Mark all read
                </Button>
              </div>
            </div>

            <div className="flex items-center justify-between text-sm">
              <div className="flex items-center space-x-2">
                <Switch
                  checked={enableRealTime}
                  onCheckedChange={setEnableRealTime}
                  className="scale-75"
                />
                <span className="text-gray-600">Real-time notifications</span>
              </div>
              <span className="text-gray-500">
                {filteredNotifications.length} of {notifications.length} shown
              </span>
            </div>
          </div>

          {/* Notifications List */}
          <ScrollArea className="h-96">
            <div className="divide-y divide-gray-100">
              {filteredNotifications.map((notification) => (
                <div
                  key={notification.id}
                  className={cn(
                    'p-4 hover:bg-gray-50 transition-colors',
                    !notification.read && 'bg-blue-50/50 border-l-4 border-l-blue-500'
                  )}
                >
                  <div className="flex items-start space-x-3">
                    <div className="flex-shrink-0 mt-0.5">
                      {getNotificationIcon(notification.type)}
                    </div>
                    
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center justify-between">
                        <div className="flex items-center space-x-2">
                          <h4 className={cn(
                            'text-sm font-medium',
                            !notification.read ? 'text-gray-900' : 'text-gray-700'
                          )}>
                            {notification.title}
                          </h4>
                          {notification.actionRequired && (
                            <Badge variant="destructive" className="text-xs">
                              Action Required
                            </Badge>
                          )}
                        </div>
                        
                        <div className="flex items-center space-x-2">
                          <div className="flex items-center space-x-1">
                            {getCategoryIcon(notification.category)}
                            <span className="text-xs text-gray-500 capitalize">
                              {notification.category}
                            </span>
                          </div>
                          <span className="text-xs text-gray-500">
                            {formatTimestamp(notification.timestamp)}
                          </span>
                        </div>
                      </div>
                      
                      <p className="text-sm text-gray-600 mt-1 pr-8">
                        {notification.message}
                      </p>
                      
                      {notification.metadata && (
                        <div className="flex items-center space-x-2 mt-2">
                          {notification.metadata.daysRemaining && (
                            <Badge variant="outline" className="text-xs">
                              {notification.metadata.daysRemaining} days remaining
                            </Badge>
                          )}
                          {notification.metadata.completion && (
                            <Badge variant="outline" className="text-xs">
                              {notification.metadata.completion}% complete
                            </Badge>
                          )}
                          {notification.metadata.variance && (
                            <Badge variant="outline" className="text-xs text-amber-700">
                              {notification.metadata.variance}% over budget
                            </Badge>
                          )}
                        </div>
                      )}
                      
                      <div className="flex items-center justify-between mt-3">
                        <div className="flex items-center space-x-2">
                          {notification.actionRequired && (
                            <Button size="sm" className="text-xs h-7">
                              Take Action
                            </Button>
                          )}
                          {!notification.read && (
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => markAsRead(notification.id)}
                              className="text-xs h-7"
                            >
                              Mark as read
                            </Button>
                          )}
                        </div>
                        
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => deleteNotification(notification.id)}
                          className="text-xs h-7 text-red-600 hover:text-red-700"
                        >
                          <Trash2 className="w-3 h-3" />
                        </Button>
                      </div>
                    </div>
                  </div>
                </div>
              ))}
            </div>

            {filteredNotifications.length === 0 && (
              <div className="text-center py-8">
                <Bell className="w-12 h-12 text-gray-300 mx-auto mb-4" />
                <p className="text-gray-500">No notifications found</p>
                <p className="text-sm text-gray-400 mt-1">
                  {showOnlyUnread 
                    ? 'All caught up! No unread notifications.'
                    : 'Notifications will appear here as events occur.'}
                </p>
              </div>
            )}
          </ScrollArea>
        </CardContent>
      </Card>
    </div>
  );
};

export default NotificationCenter;