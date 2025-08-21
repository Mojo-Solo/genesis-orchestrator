import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { Dashboard } from '@/components/dashboard/Dashboard'
import { useDashboard } from '@/hooks/use-dashboard'
import { useRealtime } from '@/hooks/use-realtime'
import { mockDashboardData, mockUser, mockTenant } from '@/__tests__/fixtures/mockData'

// Mock the hooks
jest.mock('@/hooks/use-dashboard')
jest.mock('@/hooks/use-realtime')
jest.mock('@/hooks/use-auth')

const mockUseDashboard = useDashboard as jest.MockedFunction<typeof useDashboard>
const mockUseRealtime = useRealtime as jest.MockedFunction<typeof useRealtime>

// Mock Next.js router
const mockPush = jest.fn()
jest.mock('next/navigation', () => ({
  useRouter: () => ({
    push: mockPush,
    back: jest.fn(),
    forward: jest.fn(),
    refresh: jest.fn(),
  }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => '/dashboard',
}))

// Test wrapper with providers
const TestWrapper = ({ children }: { children: React.ReactNode }) => {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
        cacheTime: 0,
      },
    },
  })

  return (
    <QueryClientProvider client={queryClient}>
      {children}
    </QueryClientProvider>
  )
}

describe('Dashboard Component', () => {
  const user = userEvent.setup()
  
  const defaultDashboardData = {
    dashboardData: mockDashboardData,
    isLoading: false,
    error: null,
    refreshDashboard: jest.fn(),
    lastUpdated: new Date().toISOString(),
  }

  const defaultRealtimeData = {
    isConnected: true,
    realtimeMetrics: {
      activeUsers: 12,
      activeMeetings: 3,
      systemLoad: 0.45,
    },
    activeMeetings: [
      {
        id: '1',
        title: 'Team Standup',
        participants: 5,
        duration: 15,
        status: 'in_progress',
      },
    ],
    connectionStatus: 'connected' as const,
    lastHeartbeat: new Date().toISOString(),
  }

  beforeEach(() => {
    jest.clearAllMocks()
    
    mockUseDashboard.mockReturnValue(defaultDashboardData)
    mockUseRealtime.mockReturnValue(defaultRealtimeData)
  })

  it('renders dashboard with loading state', () => {
    mockUseDashboard.mockReturnValue({
      ...defaultDashboardData,
      isLoading: true,
      dashboardData: null,
    })

    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    expect(screen.getByTestId('dashboard-loading')).toBeInTheDocument()
    expect(screen.getByText(/loading dashboard/i)).toBeInTheDocument()
  })

  it('renders dashboard with error state', () => {
    const errorMessage = 'Failed to load dashboard data'
    mockUseDashboard.mockReturnValue({
      ...defaultDashboardData,
      isLoading: false,
      dashboardData: null,
      error: new Error(errorMessage),
    })

    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    expect(screen.getByTestId('dashboard-error')).toBeInTheDocument()
    expect(screen.getByText(/error loading dashboard/i)).toBeInTheDocument()
    expect(screen.getByText(errorMessage)).toBeInTheDocument()
  })

  it('renders dashboard with complete data', async () => {
    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    // Check main dashboard sections
    expect(screen.getByTestId('dashboard-header')).toBeInTheDocument()
    expect(screen.getByTestId('dashboard-metrics')).toBeInTheDocument()
    expect(screen.getByTestId('dashboard-charts')).toBeInTheDocument()
    expect(screen.getByTestId('dashboard-recent-activity')).toBeInTheDocument()

    // Check metrics cards
    expect(screen.getByTestId('metric-meetings-total')).toBeInTheDocument()
    expect(screen.getByTestId('metric-action-items')).toBeInTheDocument()
    expect(screen.getByTestId('metric-insights')).toBeInTheDocument()

    // Check actual data values
    expect(screen.getByText('25')).toBeInTheDocument() // Total meetings
    expect(screen.getByText('45')).toBeInTheDocument() // Total action items
    expect(screen.getByText('18')).toBeInTheDocument() // Generated insights
  })

  it('displays real-time connection status', () => {
    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    expect(screen.getByTestId('realtime-status')).toBeInTheDocument()
    expect(screen.getByText(/connected/i)).toBeInTheDocument()
    expect(screen.getByTestId('connection-indicator-online')).toBeInTheDocument()
  })

  it('shows disconnected state when realtime is offline', () => {
    mockUseRealtime.mockReturnValue({
      ...defaultRealtimeData,
      isConnected: false,
      connectionStatus: 'disconnected',
    })

    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    expect(screen.getByText(/disconnected/i)).toBeInTheDocument()
    expect(screen.getByTestId('connection-indicator-offline')).toBeInTheDocument()
  })

  it('handles refresh dashboard action', async () => {
    const mockRefresh = jest.fn()
    mockUseDashboard.mockReturnValue({
      ...defaultDashboardData,
      refreshDashboard: mockRefresh,
    })

    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    const refreshButton = screen.getByTestId('refresh-dashboard-button')
    await user.click(refreshButton)

    expect(mockRefresh).toHaveBeenCalledTimes(1)
  })

  it('displays active meetings in real-time', () => {
    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    expect(screen.getByTestId('active-meetings-section')).toBeInTheDocument()
    expect(screen.getByText('Team Standup')).toBeInTheDocument()
    expect(screen.getByText('5 participants')).toBeInTheDocument()
    expect(screen.getByText('15 min')).toBeInTheDocument()
  })

  it('navigates to meetings page when clicking view all meetings', async () => {
    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    const viewAllButton = screen.getByTestId('view-all-meetings-button')
    await user.click(viewAllButton)

    expect(mockPush).toHaveBeenCalledWith('/meetings')
  })

  it('navigates to specific meeting when clicking on active meeting', async () => {
    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    const meetingCard = screen.getByTestId('active-meeting-1')
    await user.click(meetingCard)

    expect(mockPush).toHaveBeenCalledWith('/meetings/1')
  })

  it('displays correct completion rates with progress bars', () => {
    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    // Meeting completion rate
    const meetingProgress = screen.getByTestId('meeting-completion-progress')
    expect(meetingProgress).toHaveAttribute('value', '80') // 80%

    // Action item completion rate
    const actionProgress = screen.getByTestId('action-item-completion-progress')
    expect(actionProgress).toHaveAttribute('value', '71') // 71%
  })

  it('shows trend indicators correctly', () => {
    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    expect(screen.getByTestId('trend-meeting-frequency')).toBeInTheDocument()
    expect(screen.getByTestId('trend-increasing')).toBeInTheDocument()
    
    expect(screen.getByTestId('trend-completion-rates')).toBeInTheDocument()
    expect(screen.getByTestId('trend-stable')).toBeInTheDocument()
    
    expect(screen.getByTestId('trend-engagement')).toBeInTheDocument()
    expect(screen.getByTestId('trend-improving')).toBeInTheDocument()
  })

  it('handles time range filter changes', async () => {
    const mockRefresh = jest.fn()
    mockUseDashboard.mockReturnValue({
      ...defaultDashboardData,
      refreshDashboard: mockRefresh,
    })

    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    const timeRangeSelect = screen.getByTestId('time-range-select')
    await user.selectOptions(timeRangeSelect, '7d')

    expect(mockRefresh).toHaveBeenCalledWith({ timeRange: '7d' })
  })

  it('displays charts section with proper data', () => {
    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    expect(screen.getByTestId('meetings-chart')).toBeInTheDocument()
    expect(screen.getByTestId('action-items-chart')).toBeInTheDocument()
    expect(screen.getByTestId('insights-chart')).toBeInTheDocument()
  })

  it('shows recent activity feed', () => {
    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    expect(screen.getByTestId('recent-activity-feed')).toBeInTheDocument()
    expect(screen.getByText(/recent activity/i)).toBeInTheDocument()
  })

  it('handles responsive layout correctly', () => {
    // Mock window.innerWidth
    Object.defineProperty(window, 'innerWidth', {
      writable: true,
      configurable: true,
      value: 768,
    })

    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    const dashboard = screen.getByTestId('dashboard-container')
    expect(dashboard).toHaveClass('responsive-layout')
  })

  it('updates real-time metrics automatically', async () => {
    const { rerender } = render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    expect(screen.getByText('12')).toBeInTheDocument() // Active users

    // Simulate real-time update
    mockUseRealtime.mockReturnValue({
      ...defaultRealtimeData,
      realtimeMetrics: {
        ...defaultRealtimeData.realtimeMetrics,
        activeUsers: 15,
      },
    })

    rerender(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    expect(screen.getByText('15')).toBeInTheDocument() // Updated active users
  })

  it('handles keyboard navigation correctly', async () => {
    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    const refreshButton = screen.getByTestId('refresh-dashboard-button')
    refreshButton.focus()

    expect(refreshButton).toHaveFocus()

    // Test keyboard activation
    await user.keyboard('{Enter}')
    expect(defaultDashboardData.refreshDashboard).toHaveBeenCalled()
  })

  it('displays accessibility labels correctly', () => {
    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    expect(screen.getByLabelText(/dashboard overview/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/meeting statistics/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/action item statistics/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/refresh dashboard/i)).toBeInTheDocument()
  })

  it('shows loading states for individual sections', () => {
    mockUseDashboard.mockReturnValue({
      ...defaultDashboardData,
      isLoading: false,
      dashboardData: {
        ...mockDashboardData,
        meetings: null, // Simulate partial loading
      },
    })

    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    expect(screen.getByTestId('meetings-section-loading')).toBeInTheDocument()
  })

  it('handles error retry correctly', async () => {
    const mockRefresh = jest.fn()
    mockUseDashboard.mockReturnValue({
      ...defaultDashboardData,
      isLoading: false,
      dashboardData: null,
      error: new Error('Network error'),
      refreshDashboard: mockRefresh,
    })

    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    const retryButton = screen.getByTestId('retry-button')
    await user.click(retryButton)

    expect(mockRefresh).toHaveBeenCalledTimes(1)
  })

  it('displays proper ARIA attributes for screen readers', () => {
    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    const dashboard = screen.getByTestId('dashboard-container')
    expect(dashboard).toHaveAttribute('role', 'main')
    expect(dashboard).toHaveAttribute('aria-label', 'Dashboard')

    const metricsSection = screen.getByTestId('dashboard-metrics')
    expect(metricsSection).toHaveAttribute('aria-label', 'Key metrics')
  })

  it('maintains performance with large datasets', async () => {
    const largeDataset = {
      ...mockDashboardData,
      recentActivity: Array(1000).fill(null).map((_, i) => ({
        id: i.toString(),
        type: 'meeting_completed',
        title: `Meeting ${i}`,
        timestamp: new Date().toISOString(),
      })),
    }

    mockUseDashboard.mockReturnValue({
      ...defaultDashboardData,
      dashboardData: largeDataset,
    })

    const startTime = performance.now()
    
    render(
      <TestWrapper>
        <Dashboard />
      </TestWrapper>
    )

    const endTime = performance.now()
    const renderTime = endTime - startTime

    // Should render within reasonable time even with large dataset
    expect(renderTime).toBeLessThan(1000) // 1 second
    expect(screen.getByTestId('dashboard-container')).toBeInTheDocument()
  })
})