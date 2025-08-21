'use client';

import React, { useState, useEffect } from 'react';
import { Card, CardHeader, CardContent, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Separator } from '@/components/ui/separator';
import { 
  Clock, User, CheckCircle, AlertCircle, MessageSquare, 
  FileText, Target, Plus, TrendingUp, Edit, Trash2,
  Users, Calendar, Bell, Filter
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { useAppStore } from '@/lib/store';

interface Activity {
  id: string;
  type: 'initiative_created' | 'project_updated' | 'budget_modified' | 'comment_added' | 'status_changed' | 'vision_updated';
  user: {
    id: string;
    name: string;
    avatar?: string;
    role: string;
  };
  title: string;
  description: string;
  timestamp: Date;
  entityId?: string;
  entityType?: 'initiative' | 'project' | 'budget' | 'vision';
  metadata?: any;
}

interface ActivityFeedProps {
  className?: string;
  maxItems?: number;
  showFilter?: boolean;
}

const ActivityFeed: React.FC<ActivityFeedProps> = ({
  className = '',
  maxItems = 20,
  showFilter = true
}) => {
  const [activities, setActivities] = useState<Activity[]>([]);
  const [filteredActivities, setFilteredActivities] = useState<Activity[]>([]);
  const [selectedFilter, setSelectedFilter] = useState<string>('all');
  const [isLive, setIsLive] = useState(true);
  const { initiatives, projects } = useAppStore();

  // Generate mock activities
  useEffect(() => {
    const generateActivities = (): Activity[] => {
      const mockUsers = [
        { id: '1', name: 'David Chen', role: 'Strategic Lead', avatar: '/avatars/david.jpg' },
        { id: '2', name: 'Sarah Wilson', role: 'Project Manager', avatar: '/avatars/sarah.jpg' },
        { id: '3', name: 'Michael Brown', role: 'Budget Analyst', avatar: '/avatars/michael.jpg' },
        { id: '4', name: 'Emily Rodriguez', role: 'Vision Strategist', avatar: '/avatars/emily.jpg' },
        { id: '5', name: 'Alex Johnson', role: 'Operations Lead', avatar: '/avatars/alex.jpg' }
      ];

      const activityTypes = [
        {
          type: 'initiative_created' as const,
          title: 'New initiative created',
          description: 'Customer Experience Enhancement initiative added to strategic plan',
          entityType: 'initiative' as const
        },
        {
          type: 'project_updated' as const,
          title: 'Project status updated',
          description: 'Digital Transformation project moved from At Risk to On Target',
          entityType: 'project' as const
        },
        {
          type: 'budget_modified' as const,
          title: 'Budget variance noted',
          description: 'Q2 revenue projections increased by 12%',
          entityType: 'budget' as const
        },
        {
          type: 'comment_added' as const,
          title: 'Comment added',
          description: 'Added feedback on strategic alignment for Q3 planning',
          entityType: 'initiative' as const
        },
        {
          type: 'status_changed' as const,
          title: 'Status changed',
          description: 'Product Launch initiative approved and moved to execution phase',
          entityType: 'initiative' as const
        },
        {
          type: 'vision_updated' as const,
          title: 'Vision updated',
          description: 'Strategic vision refined for People & Culture section',
          entityType: 'vision' as const
        }
      ];

      const now = new Date();
      const generatedActivities: Activity[] = [];

      // Generate activities for the last 7 days
      for (let i = 0; i < Math.min(maxItems, 50); i++) {
        const randomActivity = activityTypes[Math.floor(Math.random() * activityTypes.length)];
        const randomUser = mockUsers[Math.floor(Math.random() * mockUsers.length)];
        const hoursAgo = Math.floor(Math.random() * 168); // Last week
        
        generatedActivities.push({
          id: `activity-${i}`,
          type: randomActivity.type,
          user: randomUser,
          title: randomActivity.title,
          description: randomActivity.description,
          timestamp: new Date(now.getTime() - (hoursAgo * 60 * 60 * 1000)),
          entityType: randomActivity.entityType,
          entityId: `entity-${i}`,
          metadata: {
            priority: ['high', 'medium', 'low'][Math.floor(Math.random() * 3)],
            category: ['strategic', 'operational', 'financial'][Math.floor(Math.random() * 3)]
          }
        });
      }

      // Sort by timestamp (most recent first)
      return generatedActivities.sort((a, b) => b.timestamp.getTime() - a.timestamp.getTime());
    };

    setActivities(generateActivities());
  }, [maxItems]);

  // Filter activities
  useEffect(() => {
    let filtered = activities;
    
    if (selectedFilter !== 'all') {
      filtered = activities.filter(activity => {
        switch (selectedFilter) {
          case 'initiatives': return activity.entityType === 'initiative';
          case 'projects': return activity.entityType === 'project';
          case 'budget': return activity.entityType === 'budget';
          case 'vision': return activity.entityType === 'vision';
          case 'comments': return activity.type === 'comment_added';
          default: return true;
        }
      });
    }

    setFilteredActivities(filtered.slice(0, maxItems));
  }, [activities, selectedFilter, maxItems]);

  // Simulate real-time updates
  useEffect(() => {
    if (!isLive) return;

    const interval = setInterval(() => {
      // Randomly add new activities (10% chance per check)
      if (Math.random() < 0.1) {
        const newActivity: Activity = {
          id: `live-${Date.now()}`,
          type: 'status_changed',
          user: {
            id: 'live-user',
            name: 'System Update',
            role: 'Automated',
          },
          title: 'Real-time update',
          description: 'Strategic metrics recalculated with latest data',
          timestamp: new Date(),
          entityType: 'initiative',
          metadata: { automated: true }
        };

        setActivities(prev => [newActivity, ...prev.slice(0, 49)]);
      }
    }, 30000); // Check every 30 seconds

    return () => clearInterval(interval);
  }, [isLive]);

  const getActivityIcon = (type: Activity['type']) => {
    switch (type) {
      case 'initiative_created': return <Plus className="w-4 h-4 text-green-500" />;
      case 'project_updated': return <TrendingUp className="w-4 h-4 text-blue-500" />;
      case 'budget_modified': return <FileText className="w-4 h-4 text-amber-500" />;
      case 'comment_added': return <MessageSquare className="w-4 h-4 text-purple-500" />;
      case 'status_changed': return <CheckCircle className="w-4 h-4 text-green-500" />;
      case 'vision_updated': return <Target className="w-4 h-4 text-brand-brown" />;
      default: return <Clock className="w-4 h-4 text-gray-500" />;
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

  const getInitials = (name: string): string => {
    return name.split(' ').map(n => n[0]).join('').toUpperCase();
  };

  return (
    <Card className={cn('h-full', className)}>
      <CardHeader className="pb-3">
        <div className="flex items-center justify-between">
          <div className="flex items-center space-x-2">
            <Bell className="w-5 h-5 text-brand-brown" />
            <CardTitle className="text-lg">Activity Feed</CardTitle>
            <Badge variant={isLive ? "default" : "secondary"} className="text-xs">
              {isLive ? 'Live' : 'Paused'}
            </Badge>
          </div>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => setIsLive(!isLive)}
            className="text-xs"
          >
            {isLive ? 'Pause' : 'Resume'}
          </Button>
        </div>

        {showFilter && (
          <div className="flex items-center space-x-2 mt-3">
            <Filter className="w-4 h-4 text-gray-500" />
            <div className="flex flex-wrap gap-2">
              {[
                { key: 'all', label: 'All' },
                { key: 'initiatives', label: 'Initiatives' },
                { key: 'projects', label: 'Projects' },
                { key: 'budget', label: 'Budget' },
                { key: 'vision', label: 'Vision' },
                { key: 'comments', label: 'Comments' }
              ].map(filter => (
                <Button
                  key={filter.key}
                  variant={selectedFilter === filter.key ? "default" : "outline"}
                  size="sm"
                  className="text-xs h-7"
                  onClick={() => setSelectedFilter(filter.key)}
                >
                  {filter.label}
                </Button>
              ))}
            </div>
          </div>
        )}
      </CardHeader>

      <CardContent className="p-0">
        <ScrollArea className="h-96 px-6">
          <div className="space-y-4">
            {filteredActivities.map((activity, index) => (
              <div key={activity.id}>
                <div className="flex items-start space-x-3">
                  <Avatar className="w-8 h-8 flex-shrink-0">
                    <AvatarImage src={activity.user.avatar} alt={activity.user.name} />
                    <AvatarFallback className="text-xs bg-brand-brown text-white">
                      {getInitials(activity.user.name)}
                    </AvatarFallback>
                  </Avatar>
                  
                  <div className="flex-1 min-w-0 space-y-1">
                    <div className="flex items-center space-x-2">
                      {getActivityIcon(activity.type)}
                      <p className="text-sm font-medium text-gray-900 truncate">
                        {activity.title}
                      </p>
                      <p className="text-xs text-gray-500 flex-shrink-0">
                        {formatTimestamp(activity.timestamp)}
                      </p>
                    </div>
                    
                    <p className="text-sm text-gray-600">{activity.description}</p>
                    
                    <div className="flex items-center space-x-2">
                      <Badge variant="outline" className="text-xs">
                        {activity.user.name}
                      </Badge>
                      <Badge variant="secondary" className="text-xs">
                        {activity.user.role}
                      </Badge>
                      {activity.metadata?.priority && (
                        <Badge 
                          variant="outline"
                          className={cn(
                            'text-xs',
                            activity.metadata.priority === 'high' && 'border-red-300 text-red-700',
                            activity.metadata.priority === 'medium' && 'border-amber-300 text-amber-700',
                            activity.metadata.priority === 'low' && 'border-green-300 text-green-700'
                          )}
                        >
                          {activity.metadata.priority}
                        </Badge>
                      )}
                    </div>
                  </div>
                </div>
                
                {index < filteredActivities.length - 1 && (
                  <Separator className="mt-4" />
                )}
              </div>
            ))}
          </div>

          {filteredActivities.length === 0 && (
            <div className="text-center py-8">
              <Clock className="w-12 h-12 text-gray-300 mx-auto mb-4" />
              <p className="text-gray-500">No activities found</p>
              <p className="text-sm text-gray-400 mt-1">
                Activities will appear here as team members work on strategic initiatives
              </p>
            </div>
          )}
        </ScrollArea>
      </CardContent>
    </Card>
  );
};

export default ActivityFeed;