import { setupServer } from 'msw/node'
import { rest } from 'msw'
import { mockUser, mockTenant, mockMeeting, mockTranscript, mockDashboardData } from '../fixtures/mockData'

// Define request handlers for the Mock Service Worker
export const handlers = [
  // Authentication endpoints
  rest.post('/api/v1/auth/login', (req, res, ctx) => {
    return res(
      ctx.status(200),
      ctx.json({
        data: {
          user: mockUser,
          tenant: mockTenant,
          token: 'mock_jwt_token_123',
          expires_at: new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString(),
        },
        message: 'Login successful',
      })
    )
  }),

  rest.post('/api/v1/auth/logout', (req, res, ctx) => {
    return res(
      ctx.status(200),
      ctx.json({
        message: 'Logout successful',
      })
    )
  }),

  rest.get('/api/v1/auth/me', (req, res, ctx) => {
    return res(
      ctx.status(200),
      ctx.json({
        data: mockUser,
      })
    )
  }),

  // Meeting endpoints
  rest.get('/api/v1/meetings', (req, res, ctx) => {
    const page = req.url.searchParams.get('page') || '1'
    const perPage = parseInt(req.url.searchParams.get('per_page') || '20')
    const status = req.url.searchParams.get('status')
    const search = req.url.searchParams.get('search')

    // Generate mock meetings
    let meetings = Array.from({ length: 50 }, (_, index) => ({
      ...mockMeeting,
      id: (index + 1).toString(),
      title: `Mock Meeting ${index + 1}`,
      status: index % 3 === 0 ? 'completed' : index % 3 === 1 ? 'scheduled' : 'in_progress',
      created_at: new Date(Date.now() - index * 24 * 60 * 60 * 1000).toISOString(),
    }))

    // Apply filters
    if (status) {
      meetings = meetings.filter(meeting => meeting.status === status)
    }

    if (search) {
      meetings = meetings.filter(meeting => 
        meeting.title.toLowerCase().includes(search.toLowerCase())
      )
    }

    // Apply pagination
    const startIndex = (parseInt(page) - 1) * perPage
    const endIndex = startIndex + perPage
    const paginatedMeetings = meetings.slice(startIndex, endIndex)

    return res(
      ctx.status(200),
      ctx.json({
        data: paginatedMeetings,
        meta: {
          current_page: parseInt(page),
          last_page: Math.ceil(meetings.length / perPage),
          per_page: perPage,
          total: meetings.length,
        },
        links: {
          first: `/api/v1/meetings?page=1&per_page=${perPage}`,
          last: `/api/v1/meetings?page=${Math.ceil(meetings.length / perPage)}&per_page=${perPage}`,
          prev: parseInt(page) > 1 ? `/api/v1/meetings?page=${parseInt(page) - 1}&per_page=${perPage}` : null,
          next: parseInt(page) < Math.ceil(meetings.length / perPage) ? `/api/v1/meetings?page=${parseInt(page) + 1}&per_page=${perPage}` : null,
        },
      })
    )
  }),

  rest.get('/api/v1/meetings/:id', (req, res, ctx) => {
    const { id } = req.params
    
    return res(
      ctx.status(200),
      ctx.json({
        data: {
          ...mockMeeting,
          id,
          recordings: [
            {
              id: '1',
              filename: 'meeting-recording.mp3',
              format: 'mp3',
              duration: 3600,
              file_size: 52428800,
              created_at: '2024-01-01T10:00:00Z',
            },
          ],
          transcripts: [mockTranscript],
        },
      })
    )
  }),

  rest.post('/api/v1/meetings', (req, res, ctx) => {
    return res(
      ctx.status(201),
      ctx.json({
        data: {
          ...mockMeeting,
          id: 'new-meeting-123',
          status: 'scheduled',
        },
        message: 'Meeting created successfully',
      })
    )
  }),

  rest.put('/api/v1/meetings/:id', (req, res, ctx) => {
    const { id } = req.params
    
    return res(
      ctx.status(200),
      ctx.json({
        data: {
          ...mockMeeting,
          id,
          title: 'Updated Meeting Title',
          updated_at: new Date().toISOString(),
        },
        message: 'Meeting updated successfully',
      })
    )
  }),

  rest.delete('/api/v1/meetings/:id', (req, res, ctx) => {
    return res(ctx.status(204))
  }),

  rest.post('/api/v1/meetings/:id/start', (req, res, ctx) => {
    const { id } = req.params
    
    return res(
      ctx.status(200),
      ctx.json({
        data: {
          id,
          status: 'in_progress',
          started_at: new Date().toISOString(),
        },
        message: 'Meeting started successfully',
      })
    )
  }),

  rest.post('/api/v1/meetings/:id/end', (req, res, ctx) => {
    const { id } = req.params
    
    return res(
      ctx.status(200),
      ctx.json({
        data: {
          id,
          status: 'completed',
          ended_at: new Date().toISOString(),
          actual_duration_minutes: 65,
        },
        message: 'Meeting ended successfully',
      })
    )
  }),

  rest.post('/api/v1/meetings/:id/recording', (req, res, ctx) => {
    const { id } = req.params
    
    return res(
      ctx.status(201),
      ctx.json({
        data: {
          id: 'recording-123',
          meeting_id: id,
          filename: 'meeting-recording.mp3',
          file_path: 'recordings/meeting-recording.mp3',
          format: 'mp3',
          duration: 3600,
          file_size: 52428800,
          created_at: new Date().toISOString(),
        },
        message: 'Recording uploaded successfully',
      })
    )
  }),

  rest.get('/api/v1/meetings/:id/transcript', (req, res, ctx) => {
    const { id } = req.params
    
    return res(
      ctx.status(200),
      ctx.json({
        data: {
          ...mockTranscript,
          meeting_id: id,
        },
      })
    )
  }),

  rest.post('/api/v1/meetings/:id/process', (req, res, ctx) => {
    const { id } = req.params
    
    return res(
      ctx.status(202),
      ctx.json({
        data: {
          job_id: 'job-123',
          meeting_id: id,
          status: 'queued',
          estimated_completion: new Date(Date.now() + 5 * 60 * 1000).toISOString(),
        },
        message: 'Meeting processing started',
      })
    )
  }),

  rest.post('/api/v1/meetings/:id/insights', (req, res, ctx) => {
    const { id } = req.params
    
    return res(
      ctx.status(202),
      ctx.json({
        data: {
          job_id: 'insights-job-123',
          meeting_id: id,
          status: 'queued',
          estimated_completion: new Date(Date.now() + 3 * 60 * 1000).toISOString(),
        },
        message: 'Insight generation started',
      })
    )
  }),

  rest.get('/api/v1/meetings/:id/analytics', (req, res, ctx) => {
    return res(
      ctx.status(200),
      ctx.json({
        data: {
          duration_analysis: {
            scheduled_duration: 60,
            actual_duration: 65,
            efficiency_score: 0.92,
          },
          participant_engagement: {
            average_engagement: 0.85,
            most_engaged: 'John Doe',
            least_engaged: 'Jane Smith',
          },
          speaking_time_distribution: {
            'John Doe': 35,
            'Jane Smith': 25,
            'Bob Wilson': 40,
          },
          sentiment_analysis: {
            overall_sentiment: 'positive',
            sentiment_score: 0.75,
            sentiment_timeline: [
              { time: '00:00:00', sentiment: 0.8 },
              { time: '00:15:00', sentiment: 0.7 },
              { time: '00:30:00', sentiment: 0.75 },
            ],
          },
          key_topics: ['project planning', 'budget allocation', 'timeline review'],
          action_items_count: 5,
        },
      })
    )
  }),

  // Dashboard endpoints
  rest.get('/api/v1/dashboard/metrics', (req, res, ctx) => {
    return res(
      ctx.status(200),
      ctx.json({
        data: mockDashboardData,
      })
    )
  }),

  // WebSocket connection simulation
  rest.get('/api/v1/ws/connect', (req, res, ctx) => {
    return res(
      ctx.status(200),
      ctx.json({
        data: {
          connection_id: 'ws-conn-123',
          url: 'ws://localhost:8000/ws',
          channels: ['meetings', 'notifications', 'analytics'],
        },
      })
    )
  }),

  // Error simulation endpoints
  rest.get('/api/v1/test/error/:code', (req, res, ctx) => {
    const { code } = req.params
    const statusCode = parseInt(code as string)
    
    return res(
      ctx.status(statusCode),
      ctx.json({
        error: `Test error with status ${statusCode}`,
        code: `TEST_ERROR_${statusCode}`,
      })
    )
  }),

  // Slow response simulation
  rest.get('/api/v1/test/slow', (req, res, ctx) => {
    return res(
      ctx.delay(2000), // 2 second delay
      ctx.status(200),
      ctx.json({
        data: { message: 'Slow response simulation' },
      })
    )
  }),

  // File upload simulation
  rest.post('/api/v1/upload', (req, res, ctx) => {
    return res(
      ctx.status(201),
      ctx.json({
        data: {
          id: 'upload-123',
          filename: 'test-file.pdf',
          file_path: 'uploads/test-file.pdf',
          file_size: 1024000,
          mime_type: 'application/pdf',
          created_at: new Date().toISOString(),
        },
        message: 'File uploaded successfully',
      })
    )
  }),

  // Search endpoints
  rest.get('/api/v1/search', (req, res, ctx) => {
    const query = req.url.searchParams.get('q') || ''
    const type = req.url.searchParams.get('type') || 'all'
    
    return res(
      ctx.status(200),
      ctx.json({
        data: {
          query,
          type,
          results: {
            meetings: [
              {
                ...mockMeeting,
                title: `Search Result: ${query}`,
                relevance_score: 0.95,
              },
            ],
            transcripts: [
              {
                ...mockTranscript,
                content: `Search result content containing "${query}"`,
                relevance_score: 0.87,
              },
            ],
          },
          total_results: 2,
          search_time: 0.125,
        },
      })
    )
  }),

  // Fallback handler for unmatched requests
  rest.get('*', (req, res, ctx) => {
    console.warn(`Unhandled ${req.method} request to ${req.url}`)
    
    return res(
      ctx.status(404),
      ctx.json({
        error: 'API endpoint not found',
        code: 'ENDPOINT_NOT_FOUND',
        path: req.url.pathname,
      })
    )
  }),
]

// Setup the server with request handlers
export const server = setupServer(...handlers)

// Export individual handlers for test-specific overrides
export const authHandlers = handlers.filter(handler => 
  handler.info.path?.includes('/auth/')
)

export const meetingHandlers = handlers.filter(handler => 
  handler.info.path?.includes('/meetings')
)

export const dashboardHandlers = handlers.filter(handler => 
  handler.info.path?.includes('/dashboard')
)