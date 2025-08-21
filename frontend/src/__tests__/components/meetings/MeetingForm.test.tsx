import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MeetingForm } from '@/components/meetings/MeetingForm'
import { useMeetings } from '@/hooks/use-meetings'
import { useAuth } from '@/hooks/use-auth'
import { mockUser, mockTenant } from '@/__tests__/fixtures/mockData'

// Mock the hooks
jest.mock('@/hooks/use-meetings')
jest.mock('@/hooks/use-auth')

const mockUseMeetings = useMeetings as jest.MockedFunction<typeof useMeetings>
const mockUseAuth = useAuth as jest.MockedFunction<typeof useAuth>

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
  usePathname: () => '/meetings/create',
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

describe('MeetingForm Component', () => {
  const user = userEvent.setup()
  
  const mockCreateMeeting = jest.fn()
  const mockUpdateMeeting = jest.fn()

  const defaultMeetingsData = {
    createMeeting: mockCreateMeeting,
    updateMeeting: mockUpdateMeeting,
    isCreating: false,
    isUpdating: false,
    createError: null,
    updateError: null,
  }

  const defaultAuthData = {
    user: mockUser,
    tenant: mockTenant,
    isAuthenticated: true,
    isLoading: false,
  }

  beforeEach(() => {
    jest.clearAllMocks()
    
    mockUseMeetings.mockReturnValue(defaultMeetingsData)
    mockUseAuth.mockReturnValue(defaultAuthData)
  })

  it('renders create meeting form correctly', () => {
    render(
      <TestWrapper>
        <MeetingForm mode="create" />
      </TestWrapper>
    )

    expect(screen.getByTestId('meeting-form')).toBeInTheDocument()
    expect(screen.getByLabelText(/meeting title/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/description/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/scheduled date/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/duration/i)).toBeInTheDocument()
    expect(screen.getByTestId('participants-section')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /create meeting/i })).toBeInTheDocument()
  })

  it('renders edit meeting form with existing data', () => {
    const existingMeeting = {
      id: '1',
      title: 'Existing Meeting',
      description: 'Meeting description',
      scheduledAt: '2024-12-01T10:00:00Z',
      durationMinutes: 60,
      participants: [
        { email: 'john@example.com', name: 'John Doe' },
        { email: 'jane@example.com', name: 'Jane Smith' },
      ],
    }

    render(
      <TestWrapper>
        <MeetingForm mode="edit" meeting={existingMeeting} />
      </TestWrapper>
    )

    expect(screen.getByDisplayValue('Existing Meeting')).toBeInTheDocument()
    expect(screen.getByDisplayValue('Meeting description')).toBeInTheDocument()
    expect(screen.getByDisplayValue('60')).toBeInTheDocument()
    expect(screen.getByDisplayValue('john@example.com')).toBeInTheDocument()
    expect(screen.getByDisplayValue('jane@example.com')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /update meeting/i })).toBeInTheDocument()
  })

  it('validates required fields', async () => {
    render(
      <TestWrapper>
        <MeetingForm mode="create" />
      </TestWrapper>
    )

    const submitButton = screen.getByRole('button', { name: /create meeting/i })
    await user.click(submitButton)

    await waitFor(() => {
      expect(screen.getByTestId('title-error')).toBeInTheDocument()
      expect(screen.getByText(/title is required/i)).toBeInTheDocument()
    })

    await waitFor(() => {
      expect(screen.getByTestId('datetime-error')).toBeInTheDocument()
      expect(screen.getByText(/scheduled time is required/i)).toBeInTheDocument()
    })

    expect(mockCreateMeeting).not.toHaveBeenCalled()
  })

  it('prevents scheduling meetings in the past', async () => {
    render(
      <TestWrapper>
        <MeetingForm mode="create" />
      </TestWrapper>
    )

    const titleInput = screen.getByLabelText(/meeting title/i)
    const datetimeInput = screen.getByLabelText(/scheduled date/i)
    const submitButton = screen.getByRole('button', { name: /create meeting/i })

    await user.type(titleInput, 'Test Meeting')
    
    // Set past date
    const pastDate = new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString().slice(0, 16)
    await user.type(datetimeInput, pastDate)

    await user.click(submitButton)

    await waitFor(() => {
      expect(screen.getByTestId('datetime-error')).toBeInTheDocument()
      expect(screen.getByText(/cannot schedule meetings in the past/i)).toBeInTheDocument()
    })

    expect(mockCreateMeeting).not.toHaveBeenCalled()
  })

  it('validates email format for participants', async () => {
    render(
      <TestWrapper>
        <MeetingForm mode="create" />
      </TestWrapper>
    )

    const addParticipantButton = screen.getByTestId('add-participant-button')
    await user.click(addParticipantButton)

    const emailInput = screen.getByTestId('participant-email-0')
    await user.type(emailInput, 'invalid-email')

    const submitButton = screen.getByRole('button', { name: /create meeting/i })
    await user.click(submitButton)

    await waitFor(() => {
      expect(screen.getByTestId('participant-email-error-0')).toBeInTheDocument()
      expect(screen.getByText(/invalid email format/i)).toBeInTheDocument()
    })
  })

  it('allows adding and removing participants', async () => {
    render(
      <TestWrapper>
        <MeetingForm mode="create" />
      </TestWrapper>
    )

    // Add first participant
    const addButton = screen.getByTestId('add-participant-button')
    await user.click(addButton)

    expect(screen.getByTestId('participant-email-0')).toBeInTheDocument()
    expect(screen.getByTestId('participant-name-0')).toBeInTheDocument()

    // Add second participant
    await user.click(addButton)
    expect(screen.getByTestId('participant-email-1')).toBeInTheDocument()

    // Remove first participant
    const removeButton = screen.getByTestId('remove-participant-0')
    await user.click(removeButton)

    expect(screen.queryByTestId('participant-email-0')).not.toBeInTheDocument()
    expect(screen.getByTestId('participant-email-1')).toBeInTheDocument()
  })

  it('enforces maximum participants limit', async () => {
    render(
      <TestWrapper>
        <MeetingForm mode="create" maxParticipants={2} />
      </TestWrapper>
    )

    const addButton = screen.getByTestId('add-participant-button')
    
    // Add maximum number of participants
    await user.click(addButton)
    await user.click(addButton)

    // Button should be disabled after reaching limit
    expect(addButton).toBeDisabled()
    expect(screen.getByText(/maximum 2 participants allowed/i)).toBeInTheDocument()
  })

  it('submits create form with valid data', async () => {
    mockCreateMeeting.mockResolvedValue({
      success: true,
      data: { id: 'new-meeting-123' },
    })

    render(
      <TestWrapper>
        <MeetingForm mode="create" />
      </TestWrapper>
    )

    // Fill in form data
    const titleInput = screen.getByLabelText(/meeting title/i)
    const descriptionInput = screen.getByLabelText(/description/i)
    const datetimeInput = screen.getByLabelText(/scheduled date/i)
    const durationInput = screen.getByLabelText(/duration/i)

    await user.type(titleInput, 'Test Meeting')
    await user.type(descriptionInput, 'Test description')
    
    const futureDate = new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString().slice(0, 16)
    await user.type(datetimeInput, futureDate)
    await user.clear(durationInput)
    await user.type(durationInput, '60')

    // Add participant
    const addParticipantButton = screen.getByTestId('add-participant-button')
    await user.click(addParticipantButton)

    const emailInput = screen.getByTestId('participant-email-0')
    const nameInput = screen.getByTestId('participant-name-0')
    await user.type(emailInput, 'john@example.com')
    await user.type(nameInput, 'John Doe')

    // Submit form
    const submitButton = screen.getByRole('button', { name: /create meeting/i })
    await user.click(submitButton)

    await waitFor(() => {
      expect(mockCreateMeeting).toHaveBeenCalledWith({
        title: 'Test Meeting',
        description: 'Test description',
        scheduledAt: expect.any(String),
        durationMinutes: 60,
        participants: [
          { email: 'john@example.com', name: 'John Doe' },
        ],
      })
    })

    // Should redirect after successful creation
    expect(mockPush).toHaveBeenCalledWith('/meetings/new-meeting-123')
  })

  it('submits update form with valid data', async () => {
    const existingMeeting = {
      id: 'meeting-123',
      title: 'Existing Meeting',
      description: 'Old description',
      scheduledAt: '2024-12-01T10:00:00Z',
      durationMinutes: 30,
      participants: [],
    }

    mockUpdateMeeting.mockResolvedValue({
      success: true,
      data: { ...existingMeeting, title: 'Updated Meeting' },
    })

    render(
      <TestWrapper>
        <MeetingForm mode="edit" meeting={existingMeeting} />
      </TestWrapper>
    )

    const titleInput = screen.getByDisplayValue('Existing Meeting')
    await user.clear(titleInput)
    await user.type(titleInput, 'Updated Meeting')

    const submitButton = screen.getByRole('button', { name: /update meeting/i })
    await user.click(submitButton)

    await waitFor(() => {
      expect(mockUpdateMeeting).toHaveBeenCalledWith('meeting-123', {
        title: 'Updated Meeting',
        description: 'Old description',
        scheduledAt: '2024-12-01T10:00:00Z',
        durationMinutes: 30,
        participants: [],
      })
    })
  })

  it('shows loading state during submission', async () => {
    mockUseMeetings.mockReturnValue({
      ...defaultMeetingsData,
      isCreating: true,
    })

    render(
      <TestWrapper>
        <MeetingForm mode="create" />
      </TestWrapper>
    )

    const submitButton = screen.getByRole('button', { name: /creating/i })
    expect(submitButton).toBeDisabled()
    expect(screen.getByTestId('loading-spinner')).toBeInTheDocument()
  })

  it('displays error messages on submission failure', async () => {
    const errorMessage = 'Failed to create meeting'
    mockUseMeetings.mockReturnValue({
      ...defaultMeetingsData,
      createError: new Error(errorMessage),
    })

    render(
      <TestWrapper>
        <MeetingForm mode="create" />
      </TestWrapper>
    )

    expect(screen.getByTestId('form-error')).toBeInTheDocument()
    expect(screen.getByText(errorMessage)).toBeInTheDocument()
  })

  it('allows clearing form data', async () => {
    render(
      <TestWrapper>
        <MeetingForm mode="create" />
      </TestWrapper>
    )

    const titleInput = screen.getByLabelText(/meeting title/i)
    const descriptionInput = screen.getByLabelText(/description/i)

    await user.type(titleInput, 'Test Meeting')
    await user.type(descriptionInput, 'Test description')

    const clearButton = screen.getByTestId('clear-form-button')
    await user.click(clearButton)

    expect(titleInput).toHaveValue('')
    expect(descriptionInput).toHaveValue('')
  })

  it('handles keyboard navigation correctly', async () => {
    render(
      <TestWrapper>
        <MeetingForm mode="create" />
      </TestWrapper>
    )

    const titleInput = screen.getByLabelText(/meeting title/i)
    titleInput.focus()

    expect(titleInput).toHaveFocus()

    // Tab to next field
    await user.tab()
    expect(screen.getByLabelText(/description/i)).toHaveFocus()

    // Tab to next field
    await user.tab()
    expect(screen.getByLabelText(/scheduled date/i)).toHaveFocus()
  })

  it('supports meeting URL integration', async () => {
    render(
      <TestWrapper>
        <MeetingForm mode="create" showMeetingUrl={true} />
      </TestWrapper>
    )

    expect(screen.getByLabelText(/meeting url/i)).toBeInTheDocument()
    expect(screen.getByTestId('generate-zoom-url')).toBeInTheDocument()
    expect(screen.getByTestId('generate-meet-url')).toBeInTheDocument()

    const generateZoomButton = screen.getByTestId('generate-zoom-url')
    await user.click(generateZoomButton)

    const meetingUrlInput = screen.getByLabelText(/meeting url/i)
    expect(meetingUrlInput).toHaveValue(expect.stringContaining('zoom.us'))
  })

  it('validates meeting duration limits', async () => {
    render(
      <TestWrapper>
        <MeetingForm mode="create" />
      </TestWrapper>
    )

    const durationInput = screen.getByLabelText(/duration/i)
    await user.clear(durationInput)
    await user.type(durationInput, '480') // 8 hours

    const submitButton = screen.getByRole('button', { name: /create meeting/i })
    await user.click(submitButton)

    await waitFor(() => {
      expect(screen.getByTestId('duration-error')).toBeInTheDocument()
      expect(screen.getByText(/duration cannot exceed 6 hours/i)).toBeInTheDocument()
    })
  })

  it('supports recurring meeting options', async () => {
    render(
      <TestWrapper>
        <MeetingForm mode="create" showRecurring={true} />
      </TestWrapper>
    )

    const recurringCheckbox = screen.getByLabelText(/recurring meeting/i)
    await user.click(recurringCheckbox)

    expect(screen.getByTestId('recurring-options')).toBeInTheDocument()
    expect(screen.getByLabelText(/frequency/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/end date/i)).toBeInTheDocument()

    const frequencySelect = screen.getByLabelText(/frequency/i)
    await user.selectOptions(frequencySelect, 'weekly')

    expect(screen.getByDisplayValue('weekly')).toBeInTheDocument()
  })

  it('handles timezone selection', async () => {
    render(
      <TestWrapper>
        <MeetingForm mode="create" showTimezone={true} />
      </TestWrapper>
    )

    expect(screen.getByLabelText(/timezone/i)).toBeInTheDocument()
    
    const timezoneSelect = screen.getByLabelText(/timezone/i)
    await user.selectOptions(timezoneSelect, 'America/Los_Angeles')

    expect(screen.getByDisplayValue('America/Los_Angeles')).toBeInTheDocument()
  })

  it('shows character count for description field', async () => {
    render(
      <TestWrapper>
        <MeetingForm mode="create" />
      </TestWrapper>
    )

    const descriptionInput = screen.getByLabelText(/description/i)
    await user.type(descriptionInput, 'Test description')

    expect(screen.getByTestId('description-character-count')).toBeInTheDocument()
    expect(screen.getByText(/16 \/ 500/)).toBeInTheDocument()
  })

  it('supports drag and drop for participant import', async () => {
    render(
      <TestWrapper>
        <MeetingForm mode="create" />
      </TestWrapper>
    )

    const dropZone = screen.getByTestId('participant-drop-zone')
    expect(dropZone).toBeInTheDocument()

    // Simulate drag and drop of CSV file
    const csvContent = 'name,email\nJohn Doe,john@example.com\nJane Smith,jane@example.com'
    const file = new File([csvContent], 'participants.csv', { type: 'text/csv' })

    fireEvent.drop(dropZone, {
      dataTransfer: {
        files: [file],
      },
    })

    await waitFor(() => {
      expect(screen.getByDisplayValue('john@example.com')).toBeInTheDocument()
      expect(screen.getByDisplayValue('jane@example.com')).toBeInTheDocument()
    })
  })

  it('remembers form data on page refresh', async () => {
    // Mock localStorage
    const mockGetItem = jest.fn()
    const mockSetItem = jest.fn()
    
    Object.defineProperty(window, 'localStorage', {
      value: {
        getItem: mockGetItem,
        setItem: mockSetItem,
      },
      writable: true,
    })

    render(
      <TestWrapper>
        <MeetingForm mode="create" autosave={true} />
      </TestWrapper>
    )

    const titleInput = screen.getByLabelText(/meeting title/i)
    await user.type(titleInput, 'Draft Meeting')

    await waitFor(() => {
      expect(mockSetItem).toHaveBeenCalledWith(
        'meeting-form-draft',
        expect.stringContaining('Draft Meeting')
      )
    })
  })
})