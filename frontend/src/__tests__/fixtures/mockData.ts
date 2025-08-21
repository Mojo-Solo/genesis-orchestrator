import { User, Tenant, Meeting, Transcript } from '@/types'

// Mock user data
export const mockUser: User = {
  id: '1',
  email: 'test@example.com',
  name: 'Test User',
  role: 'user',
  tenant_id: '1',
  avatar_url: null,
  settings: {
    timezone: 'America/New_York',
    notifications: {
      email: true,
      push: true,
      meeting_reminders: true,
    },
    preferences: {
      theme: 'light',
      language: 'en',
    },
  },
  created_at: '2024-01-01T00:00:00Z',
  updated_at: '2024-01-01T00:00:00Z',
}

// Mock admin user data
export const mockAdminUser: User = {
  id: '2',
  email: 'admin@example.com',
  name: 'Admin User',
  role: 'admin',
  tenant_id: '1',
  avatar_url: 'https://example.com/avatar.jpg',
  settings: {
    timezone: 'America/New_York',
    notifications: {
      email: true,
      push: true,
      meeting_reminders: true,
      admin_alerts: true,
    },
    preferences: {
      theme: 'dark',
      language: 'en',
    },
  },
  created_at: '2024-01-01T00:00:00Z',
  updated_at: '2024-01-01T00:00:00Z',
}

// Mock tenant data
export const mockTenant: Tenant = {
  id: '1',
  name: 'Test Tenant',
  slug: 'test-tenant',
  tier: 'professional',
  status: 'active',
  settings: {
    features: {
      ai_insights: true,
      real_time_transcription: true,
      advanced_analytics: true,
      custom_integrations: false,
    },
    limits: {
      monthly_meetings: 100,
      storage_gb: 50,
      users: 25,
    },
    integrations: {
      fireflies: {
        enabled: true,
        api_key: 'fireflies_key_123',
      },
      zoom: {
        enabled: true,
        webhook_url: 'https://webhook.example.com/zoom',
      },
    },
  },
  subscription: {
    status: 'active',
    current_period_start: '2024-01-01T00:00:00Z',
    current_period_end: '2024-02-01T00:00:00Z',
    cancel_at_period_end: false,
  },
  created_at: '2024-01-01T00:00:00Z',
  updated_at: '2024-01-01T00:00:00Z',
}

// Mock meeting data
export const mockMeeting: Meeting = {
  id: '1',
  title: 'Test Meeting',
  description: 'A test meeting for the testing suite',
  status: 'scheduled',
  scheduled_at: '2024-12-01T10:00:00Z',
  duration_minutes: 60,
  meeting_url: 'https://zoom.us/j/123456789',
  participants: [
    { 
      email: 'john@example.com', 
      name: 'John Doe',
      role: 'attendee',
      joined_at: null,
      left_at: null,
    },
    { 
      email: 'jane@example.com', 
      name: 'Jane Smith',
      role: 'presenter',
      joined_at: null,
      left_at: null,
    },
  ],
  metadata: {
    source: 'manual',
    priority: 'normal',
    tags: ['weekly', 'team-sync'],
    custom_fields: {
      project_id: 'proj-123',
      department: 'engineering',
    },
  },
  user_id: '1',
  tenant_id: '1',
  started_at: null,
  ended_at: null,
  actual_duration_minutes: null,
  created_at: '2024-01-01T00:00:00Z',
  updated_at: '2024-01-01T00:00:00Z',
}

// Mock completed meeting
export const mockCompletedMeeting: Meeting = {
  ...mockMeeting,
  id: '2',
  title: 'Completed Test Meeting',
  status: 'completed',
  started_at: '2024-01-01T10:00:00Z',
  ended_at: '2024-01-01T11:05:00Z',
  actual_duration_minutes: 65,
  participants: [
    {
      ...mockMeeting.participants[0],
      joined_at: '2024-01-01T10:02:00Z',
      left_at: '2024-01-01T11:05:00Z',
    },
    {
      ...mockMeeting.participants[1],
      joined_at: '2024-01-01T10:00:00Z',
      left_at: '2024-01-01T11:05:00Z',
    },
  ],
}

// Mock transcript data
export const mockTranscript: Transcript = {
  id: '1',
  meeting_id: '1',
  content: 'This is a test transcript of the meeting. John spoke about project updates and Jane discussed the timeline.',
  language: 'en',
  confidence_score: 0.95,
  sentences: [
    {
      speaker: 'John Doe',
      text: 'Welcome everyone to today\'s meeting. Let\'s start with project updates.',
      timestamp: '00:01:00',
      confidence: 0.98,
      start_time: 60.0,
      end_time: 65.5,
    },
    {
      speaker: 'Jane Smith',
      text: 'Thanks John. I\'ll share the current timeline and milestones.',
      timestamp: '00:01:30',
      confidence: 0.96,
      start_time: 90.0,
      end_time: 94.2,
    },
    {
      speaker: 'John Doe',
      text: 'Great, and after that we can discuss the budget allocation.',
      timestamp: '00:02:00',
      confidence: 0.97,
      start_time: 120.0,
      end_time: 123.8,
    },
  ],
  metadata: {
    processing_time: 45.2,
    model_version: '2.1.0',
    language_detection_confidence: 0.99,
    speaker_count: 2,
    total_speaking_time: 180.5,
  },
  ai_insights: {
    action_items: [
      {
        text: 'John will prepare the quarterly budget report by Friday',
        assignee: 'John Doe',
        due_date: '2024-01-15',
        confidence: 0.92,
        extracted_from: 'sentence_5',
      },
      {
        text: 'Jane needs to update the project timeline with new milestones',
        assignee: 'Jane Smith',
        due_date: '2024-01-10',
        confidence: 0.89,
        extracted_from: 'sentence_8',
      },
    ],
    key_topics: [
      { topic: 'project updates', confidence: 0.94, frequency: 3 },
      { topic: 'timeline planning', confidence: 0.91, frequency: 2 },
      { topic: 'budget allocation', confidence: 0.87, frequency: 2 },
    ],
    sentiment_analysis: {
      overall_sentiment: 'positive',
      sentiment_score: 0.72,
      sentiment_breakdown: {
        positive: 0.72,
        neutral: 0.23,
        negative: 0.05,
      },
    },
    summary: 'Team meeting discussing project progress, timeline updates, and budget planning. Action items assigned to team members with specific deadlines.',
  },
  created_at: '2024-01-01T11:30:00Z',
  updated_at: '2024-01-01T11:30:00Z',
}

// Mock dashboard data
export const mockDashboardData = {
  meetings: {
    total: 25,
    completed: 20,
    scheduled: 5,
    in_progress: 0,
    completion_rate: 0.8,
    avg_duration: 52.3,
    trends: {
      weekly_change: 0.15,
      monthly_change: 0.08,
    },
  },
  action_items: {
    total: 45,
    completed: 32,
    overdue: 3,
    due_today: 5,
    completion_rate: 0.71,
    avg_completion_time: 2.4, // days
    trends: {
      weekly_change: -0.05,
      monthly_change: 0.12,
    },
  },
  insights: {
    generated: 18,
    actionable: 15,
    confidence_avg: 0.87,
    processing_time_avg: 42.1, // seconds
    trends: {
      accuracy_improvement: 0.06,
      processing_speed_improvement: 0.23,
    },
  },
  participants: {
    unique_participants: 12,
    avg_per_meeting: 4.2,
    engagement_score: 0.83,
    most_active: 'John Doe',
  },
  trends: {
    meeting_frequency: 'increasing',
    completion_rates: 'stable',
    engagement: 'improving',
    ai_accuracy: 'increasing',
  },
  recent_activity: [
    {
      id: '1',
      type: 'meeting_completed',
      title: 'Weekly Team Sync completed',
      timestamp: '2024-01-01T11:00:00Z',
      meeting_id: '1',
    },
    {
      id: '2',
      type: 'transcript_processed',
      title: 'Transcript generated for Project Planning Meeting',
      timestamp: '2024-01-01T10:30:00Z',
      meeting_id: '2',
    },
    {
      id: '3',
      type: 'action_item_completed',
      title: 'Budget report submitted by John Doe',
      timestamp: '2024-01-01T09:15:00Z',
      meeting_id: '3',
    },
  ],
  system_health: {
    api_response_time: 85, // ms
    processing_queue_length: 3,
    success_rate: 99.2,
    uptime: 99.9,
  },
}

// Mock real-time data
export const mockRealtimeData = {
  active_meetings: [
    {
      id: '1',
      title: 'Team Standup',
      participants: 5,
      duration: 15, // minutes elapsed
      status: 'in_progress',
      started_at: '2024-01-01T10:00:00Z',
    },
    {
      id: '2',
      title: 'Client Review',
      participants: 3,
      duration: 32,
      status: 'in_progress',
      started_at: '2024-01-01T09:30:00Z',
    },
  ],
  system_metrics: {
    active_users: 12,
    processing_jobs: 4,
    api_requests_per_minute: 150,
    queue_depth: 2,
  },
  notifications: [
    {
      id: '1',
      type: 'meeting_reminder',
      title: 'Meeting starting in 5 minutes',
      message: 'Project Planning Meeting',
      priority: 'high',
      timestamp: '2024-01-01T11:55:00Z',
    },
    {
      id: '2',
      type: 'transcript_ready',
      title: 'Transcript ready for review',
      message: 'Weekly Team Sync transcript is ready',
      priority: 'medium',
      timestamp: '2024-01-01T11:30:00Z',
    },
  ],
}

// Mock API responses
export const mockApiResponses = {
  success: {
    status: 200,
    data: {},
    message: 'Operation completed successfully',
  },
  created: {
    status: 201,
    data: {},
    message: 'Resource created successfully',
  },
  noContent: {
    status: 204,
  },
  badRequest: {
    status: 400,
    error: 'Bad Request',
    code: 'VALIDATION_ERROR',
    details: {
      field: 'This field is required',
    },
  },
  unauthorized: {
    status: 401,
    error: 'Unauthorized',
    code: 'AUTHENTICATION_REQUIRED',
    message: 'Please log in to access this resource',
  },
  forbidden: {
    status: 403,
    error: 'Forbidden',
    code: 'INSUFFICIENT_PERMISSIONS',
    message: 'You do not have permission to access this resource',
  },
  notFound: {
    status: 404,
    error: 'Not Found',
    code: 'RESOURCE_NOT_FOUND',
    message: 'The requested resource was not found',
  },
  serverError: {
    status: 500,
    error: 'Internal Server Error',
    code: 'INTERNAL_ERROR',
    message: 'An unexpected error occurred',
  },
}

// Helper functions for generating mock data
export const generateMockMeetings = (count: number) => {
  return Array.from({ length: count }, (_, index) => ({
    ...mockMeeting,
    id: (index + 1).toString(),
    title: `Meeting ${index + 1}`,
    status: ['scheduled', 'in_progress', 'completed'][index % 3] as Meeting['status'],
    scheduled_at: new Date(Date.now() + (index - count / 2) * 24 * 60 * 60 * 1000).toISOString(),
  }))
}

export const generateMockUsers = (count: number) => {
  return Array.from({ length: count }, (_, index) => ({
    ...mockUser,
    id: (index + 1).toString(),
    email: `user${index + 1}@example.com`,
    name: `User ${index + 1}`,
    role: index === 0 ? 'admin' : 'user' as User['role'],
  }))
}

export const generateMockTranscripts = (count: number) => {
  return Array.from({ length: count }, (_, index) => ({
    ...mockTranscript,
    id: (index + 1).toString(),
    meeting_id: (index + 1).toString(),
    content: `This is the transcript content for meeting ${index + 1}.`,
  }))
}

// Export all mock data as a single object for convenience
export const mockData = {
  user: mockUser,
  adminUser: mockAdminUser,
  tenant: mockTenant,
  meeting: mockMeeting,
  completedMeeting: mockCompletedMeeting,
  transcript: mockTranscript,
  dashboardData: mockDashboardData,
  realtimeData: mockRealtimeData,
  apiResponses: mockApiResponses,
  generators: {
    meetings: generateMockMeetings,
    users: generateMockUsers,
    transcripts: generateMockTranscripts,
  },
}

export default mockData