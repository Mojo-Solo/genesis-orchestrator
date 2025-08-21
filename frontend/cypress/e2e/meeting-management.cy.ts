describe('Meeting Management E2E Tests', () => {
  const adminUser = {
    email: Cypress.env('adminEmail'),
    password: Cypress.env('adminPassword'),
  }

  const testMeeting = {
    title: 'E2E Test Meeting',
    description: 'A test meeting created during E2E testing',
    scheduledAt: new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString(), // Tomorrow
    durationMinutes: 60,
    participants: [
      { email: 'john@example.com', name: 'John Doe' },
      { email: 'jane@example.com', name: 'Jane Smith' },
    ],
  }

  beforeEach(() => {
    // Clear test data before each test
    cy.task('clearTestData')
    
    // Visit the application
    cy.visit('/')
    
    // Login as admin user
    cy.login(adminUser.email, adminUser.password)
    
    // Wait for dashboard to load
    cy.url().should('include', '/dashboard')
    cy.get('[data-testid="dashboard-container"]').should('be.visible')
  })

  afterEach(() => {
    // Clean up after each test
    cy.task('clearTestData')
  })

  describe('Meeting Creation Flow', () => {
    it('should create a new meeting successfully', () => {
      // Navigate to meetings page
      cy.get('[data-testid="nav-meetings"]').click()
      cy.url().should('include', '/meetings')
      
      // Click create meeting button
      cy.get('[data-testid="create-meeting-button"]').click()
      
      // Fill in meeting details
      cy.get('[data-testid="meeting-title-input"]')
        .type(testMeeting.title)
        .should('have.value', testMeeting.title)
      
      cy.get('[data-testid="meeting-description-input"]')
        .type(testMeeting.description)
        .should('contain.value', testMeeting.description)
      
      // Set scheduled date and time
      cy.get('[data-testid="meeting-datetime-input"]')
        .clear()
        .type(testMeeting.scheduledAt.slice(0, 16)) // Format for datetime-local input
      
      // Set duration
      cy.get('[data-testid="meeting-duration-input"]')
        .clear()
        .type(testMeeting.durationMinutes.toString())
      
      // Add participants
      testMeeting.participants.forEach((participant, index) => {
        cy.get('[data-testid="add-participant-button"]').click()
        
        cy.get(`[data-testid="participant-email-${index}"]`)
          .type(participant.email)
          .should('have.value', participant.email)
        
        cy.get(`[data-testid="participant-name-${index}"]`)
          .type(participant.name)
          .should('have.value', participant.name)
      })
      
      // Submit the form
      cy.get('[data-testid="submit-meeting-button"]').click()
      
      // Verify success message
      cy.get('[data-testid="success-notification"]')
        .should('be.visible')
        .and('contain', 'Meeting created successfully')
      
      // Verify redirect to meeting details
      cy.url().should('match', /\/meetings\/\d+/)
      
      // Verify meeting details are displayed
      cy.get('[data-testid="meeting-title"]').should('contain', testMeeting.title)
      cy.get('[data-testid="meeting-description"]').should('contain', testMeeting.description)
      cy.get('[data-testid="meeting-status"]').should('contain', 'Scheduled')
      
      // Verify participants are listed
      testMeeting.participants.forEach((participant) => {
        cy.get('[data-testid="participants-list"]')
          .should('contain', participant.name)
          .and('contain', participant.email)
      })
    })

    it('should validate required fields', () => {
      cy.get('[data-testid="nav-meetings"]').click()
      cy.get('[data-testid="create-meeting-button"]').click()
      
      // Try to submit without required fields
      cy.get('[data-testid="submit-meeting-button"]').click()
      
      // Verify validation errors
      cy.get('[data-testid="title-error"]')
        .should('be.visible')
        .and('contain', 'Title is required')
      
      cy.get('[data-testid="datetime-error"]')
        .should('be.visible')
        .and('contain', 'Scheduled time is required')
      
      // Form should not submit
      cy.url().should('include', '/meetings/create')
    })

    it('should prevent scheduling meetings in the past', () => {
      cy.get('[data-testid="nav-meetings"]').click()
      cy.get('[data-testid="create-meeting-button"]').click()
      
      // Fill in title
      cy.get('[data-testid="meeting-title-input"]').type('Past Meeting')
      
      // Try to set past date
      const pastDate = new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString()
      cy.get('[data-testid="meeting-datetime-input"]')
        .type(pastDate.slice(0, 16))
      
      cy.get('[data-testid="submit-meeting-button"]').click()
      
      // Verify validation error
      cy.get('[data-testid="datetime-error"]')
        .should('be.visible')
        .and('contain', 'Cannot schedule meetings in the past')
    })
  })

  describe('Meeting Management', () => {
    let meetingId: string

    beforeEach(() => {
      // Create a test meeting
      cy.createMeeting(testMeeting).then((response) => {
        meetingId = response.body.data.id
      })
    })

    it('should display meetings in the list', () => {
      cy.get('[data-testid="nav-meetings"]').click()
      
      // Verify meeting appears in list
      cy.get('[data-testid="meetings-table"]').should('be.visible')
      cy.get(`[data-testid="meeting-row-${meetingId}"]`)
        .should('be.visible')
        .and('contain', testMeeting.title)
        .and('contain', 'Scheduled')
    })

    it('should filter meetings by status', () => {
      cy.get('[data-testid="nav-meetings"]').click()
      
      // Apply status filter
      cy.get('[data-testid="status-filter"]').select('scheduled')
      
      // Verify filtered results
      cy.get(`[data-testid="meeting-row-${meetingId}"]`).should('be.visible')
      
      // Change filter to completed
      cy.get('[data-testid="status-filter"]').select('completed')
      
      // Verify meeting is not shown
      cy.get(`[data-testid="meeting-row-${meetingId}"]`).should('not.exist')
    })

    it('should search meetings by title', () => {
      cy.get('[data-testid="nav-meetings"]').click()
      
      // Use search
      cy.get('[data-testid="meeting-search"]')
        .type(testMeeting.title.slice(0, 5))
      
      // Verify search results
      cy.get(`[data-testid="meeting-row-${meetingId}"]`).should('be.visible')
      
      // Search for non-existent meeting
      cy.get('[data-testid="meeting-search"]')
        .clear()
        .type('NonexistentMeeting')
      
      // Verify no results
      cy.get('[data-testid="no-meetings-message"]')
        .should('be.visible')
        .and('contain', 'No meetings found')
    })

    it('should edit meeting details', () => {
      cy.get('[data-testid="nav-meetings"]').click()
      
      // Click edit button
      cy.get(`[data-testid="edit-meeting-${meetingId}"]`).click()
      
      // Update meeting title
      const updatedTitle = 'Updated E2E Test Meeting'
      cy.get('[data-testid="meeting-title-input"]')
        .clear()
        .type(updatedTitle)
      
      // Update description
      const updatedDescription = 'Updated description for E2E test'
      cy.get('[data-testid="meeting-description-input"]')
        .clear()
        .type(updatedDescription)
      
      // Save changes
      cy.get('[data-testid="save-meeting-button"]').click()
      
      // Verify success message
      cy.get('[data-testid="success-notification"]')
        .should('be.visible')
        .and('contain', 'Meeting updated successfully')
      
      // Verify changes are reflected
      cy.get('[data-testid="meeting-title"]').should('contain', updatedTitle)
      cy.get('[data-testid="meeting-description"]').should('contain', updatedDescription)
    })

    it('should delete a meeting', () => {
      cy.get('[data-testid="nav-meetings"]').click()
      
      // Click delete button
      cy.get(`[data-testid="delete-meeting-${meetingId}"]`).click()
      
      // Confirm deletion in modal
      cy.get('[data-testid="confirm-delete-modal"]').should('be.visible')
      cy.get('[data-testid="confirm-delete-button"]').click()
      
      // Verify success message
      cy.get('[data-testid="success-notification"]')
        .should('be.visible')
        .and('contain', 'Meeting deleted successfully')
      
      // Verify meeting is removed from list
      cy.get(`[data-testid="meeting-row-${meetingId}"]`).should('not.exist')
    })
  })

  describe('Meeting Execution Flow', () => {
    let meetingId: string

    beforeEach(() => {
      cy.createMeeting(testMeeting).then((response) => {
        meetingId = response.body.data.id
      })
    })

    it('should start and end a meeting', () => {
      // Navigate to meeting details
      cy.visit(`/meetings/${meetingId}`)
      
      // Verify initial status
      cy.get('[data-testid="meeting-status"]').should('contain', 'Scheduled')
      
      // Start the meeting
      cy.get('[data-testid="start-meeting-button"]').click()
      
      // Verify status change
      cy.get('[data-testid="meeting-status"]').should('contain', 'In Progress')
      
      // Verify start time is displayed
      cy.get('[data-testid="meeting-start-time"]').should('be.visible')
      
      // End the meeting
      cy.get('[data-testid="end-meeting-button"]').click()
      
      // Confirm in modal
      cy.get('[data-testid="confirm-end-modal"]').should('be.visible')
      cy.get('[data-testid="confirm-end-button"]').click()
      
      // Verify status change
      cy.get('[data-testid="meeting-status"]').should('contain', 'Completed')
      
      // Verify end time is displayed
      cy.get('[data-testid="meeting-end-time"]').should('be.visible')
      
      // Verify duration calculation
      cy.get('[data-testid="actual-duration"]').should('be.visible')
    })

    it('should upload meeting recording', () => {
      // Start and end meeting first
      cy.visit(`/meetings/${meetingId}`)
      cy.get('[data-testid="start-meeting-button"]').click()
      cy.get('[data-testid="end-meeting-button"]').click()
      cy.get('[data-testid="confirm-end-button"]').click()
      
      // Upload recording
      cy.get('[data-testid="upload-recording-button"]').click()
      
      // Select file
      cy.get('[data-testid="recording-file-input"]')
        .selectFile('cypress/fixtures/test-recording.mp3', { force: true })
      
      // Add metadata
      cy.get('[data-testid="recording-format-select"]').select('mp3')
      cy.get('[data-testid="recording-duration-input"]').type('3600')
      
      // Upload
      cy.get('[data-testid="upload-button"]').click()
      
      // Verify upload progress
      cy.get('[data-testid="upload-progress"]').should('be.visible')
      
      // Verify success
      cy.get('[data-testid="upload-success"]', { timeout: 30000 })
        .should('be.visible')
        .and('contain', 'Recording uploaded successfully')
      
      // Verify recording appears in meeting details
      cy.get('[data-testid="meeting-recording"]').should('be.visible')
      cy.get('[data-testid="recording-download-link"]').should('be.visible')
    })
  })

  describe('Transcript Management', () => {
    let meetingId: string

    beforeEach(() => {
      cy.createMeeting(testMeeting).then((response) => {
        meetingId = response.body.data.id
      })
      
      // Complete the meeting
      cy.request('POST', `/api/v1/meetings/${meetingId}/start`)
      cy.request('POST', `/api/v1/meetings/${meetingId}/end`)
    })

    it('should process and display transcript', () => {
      cy.visit(`/meetings/${meetingId}`)
      
      // Upload transcript
      cy.get('[data-testid="transcript-tab"]').click()
      cy.get('[data-testid="upload-transcript-button"]').click()
      
      // Use manual transcript input
      const transcriptContent = 'This is a test transcript for the E2E meeting. John said hello and Jane responded with project updates.'
      
      cy.get('[data-testid="transcript-content-input"]')
        .type(transcriptContent)
      
      cy.get('[data-testid="transcript-language-select"]').select('en')
      cy.get('[data-testid="confidence-score-input"]').type('0.95')
      
      // Submit transcript
      cy.get('[data-testid="submit-transcript-button"]').click()
      
      // Verify processing notification
      cy.get('[data-testid="processing-notification"]')
        .should('be.visible')
        .and('contain', 'Processing transcript')
      
      // Wait for processing to complete
      cy.get('[data-testid="transcript-content"]', { timeout: 30000 })
        .should('be.visible')
        .and('contain', transcriptContent)
      
      // Verify transcript metadata
      cy.get('[data-testid="transcript-language"]').should('contain', 'English')
      cy.get('[data-testid="transcript-confidence"]').should('contain', '95%')
    })

    it('should extract action items from transcript', () => {
      // First add a transcript with action items
      const transcriptWithActions = 'John will prepare the quarterly report by Friday. Jane needs to follow up with the client next week. Bob should review the budget proposal.'
      
      cy.visit(`/meetings/${meetingId}`)
      cy.addTranscript(meetingId, transcriptWithActions)
      
      // Extract action items
      cy.get('[data-testid="action-items-tab"]').click()
      cy.get('[data-testid="extract-action-items-button"]').click()
      
      // Verify extraction process
      cy.get('[data-testid="extraction-progress"]').should('be.visible')
      
      // Verify extracted action items
      cy.get('[data-testid="extracted-action-items"]', { timeout: 30000 })
        .should('be.visible')
      
      cy.get('[data-testid="action-item-0"]')
        .should('contain', 'prepare the quarterly report')
        .and('contain', 'John')
        .and('contain', 'Friday')
      
      cy.get('[data-testid="action-item-1"]')
        .should('contain', 'follow up with the client')
        .and('contain', 'Jane')
        .and('contain', 'next week')
      
      // Accept action items
      cy.get('[data-testid="accept-action-items-button"]').click()
      
      // Verify action items are saved
      cy.get('[data-testid="success-notification"]')
        .should('contain', 'Action items saved successfully')
    })

    it('should generate meeting summary', () => {
      cy.visit(`/meetings/${meetingId}`)
      cy.addTranscript(meetingId, 'Comprehensive meeting discussion about project status, budget allocation, and next quarter planning.')
      
      // Generate summary
      cy.get('[data-testid="summary-tab"]').click()
      cy.get('[data-testid="generate-summary-button"]').click()
      
      // Verify generation process
      cy.get('[data-testid="summary-generation-progress"]').should('be.visible')
      
      // Verify summary components
      cy.get('[data-testid="meeting-summary"]', { timeout: 30000 })
        .should('be.visible')
      
      cy.get('[data-testid="key-points"]').should('be.visible')
      cy.get('[data-testid="decisions-made"]').should('be.visible')
      cy.get('[data-testid="next-steps"]').should('be.visible')
      
      // Verify sentiment analysis
      cy.get('[data-testid="sentiment-analysis"]')
        .should('be.visible')
        .and('contain', 'Sentiment')
      
      // Export summary
      cy.get('[data-testid="export-summary-button"]').click()
      cy.get('[data-testid="export-format-select"]').select('pdf')
      cy.get('[data-testid="confirm-export-button"]').click()
      
      // Verify download
      cy.get('[data-testid="download-link"]', { timeout: 15000 })
        .should('be.visible')
    })
  })

  describe('Real-time Features', () => {
    let meetingId: string

    beforeEach(() => {
      cy.createMeeting(testMeeting).then((response) => {
        meetingId = response.body.data.id
      })
    })

    it('should show real-time meeting updates', () => {
      // Open meeting in multiple tabs simulation
      cy.visit(`/meetings/${meetingId}`)
      
      // Verify real-time connection
      cy.get('[data-testid="realtime-status"]')
        .should('be.visible')
        .and('contain', 'Connected')
      
      // Start meeting and verify real-time update
      cy.get('[data-testid="start-meeting-button"]').click()
      
      // Simulate real-time update from another user
      cy.window().then((win) => {
        // Mock WebSocket message
        win.dispatchEvent(new CustomEvent('meeting-updated', {
          detail: {
            meeting_id: meetingId,
            status: 'in_progress',
            participants_joined: 3
          }
        }))
      })
      
      // Verify real-time updates are reflected
      cy.get('[data-testid="participants-count"]')
        .should('contain', '3 participants')
      
      cy.get('[data-testid="meeting-status"]')
        .should('contain', 'In Progress')
    })

    it('should handle connection issues gracefully', () => {
      cy.visit(`/meetings/${meetingId}`)
      
      // Simulate connection loss
      cy.window().then((win) => {
        win.dispatchEvent(new CustomEvent('websocket-disconnected'))
      })
      
      // Verify disconnection indicator
      cy.get('[data-testid="realtime-status"]')
        .should('contain', 'Disconnected')
      
      cy.get('[data-testid="reconnect-notification"]')
        .should('be.visible')
        .and('contain', 'Connection lost. Attempting to reconnect...')
      
      // Simulate reconnection
      cy.window().then((win) => {
        win.dispatchEvent(new CustomEvent('websocket-connected'))
      })
      
      // Verify reconnection
      cy.get('[data-testid="realtime-status"]')
        .should('contain', 'Connected')
    })
  })

  describe('Performance and Accessibility', () => {
    it('should meet performance benchmarks', () => {
      // Measure page load time
      cy.visit('/meetings', {
        onBeforeLoad: (win) => {
          win.performance.mark('start')
        },
        onLoad: (win) => {
          win.performance.mark('end')
          win.performance.measure('pageLoad', 'start', 'end')
        }
      })
      
      // Verify page loads within acceptable time
      cy.window().then((win) => {
        const measure = win.performance.getEntriesByName('pageLoad')[0]
        expect(measure.duration).to.be.lessThan(3000) // 3 seconds
      })
      
      // Verify Core Web Vitals
      cy.window().then((win) => {
        return new Promise((resolve) => {
          new win.PerformanceObserver((list) => {
            const entries = list.getEntries()
            entries.forEach((entry) => {
              if (entry.entryType === 'largest-contentful-paint') {
                expect(entry.startTime).to.be.lessThan(2500) // LCP < 2.5s
              }
            })
            resolve(true)
          }).observe({ entryTypes: ['largest-contentful-paint'] })
        })
      })
    })

    it('should be accessible to screen readers', () => {
      cy.visit('/meetings')
      cy.injectAxe() // Inject axe-core
      
      // Check for accessibility violations
      cy.checkA11y()
      
      // Test keyboard navigation
      cy.get('body').tab()
      cy.focused().should('have.attr', 'data-testid', 'skip-to-content')
      
      // Test ARIA labels
      cy.get('[data-testid="create-meeting-button"]')
        .should('have.attr', 'aria-label', 'Create new meeting')
      
      // Test proper heading hierarchy
      cy.get('h1').should('exist')
      cy.get('h2').should('exist')
      
      // Test form labels
      cy.get('[data-testid="create-meeting-button"]').click()
      cy.get('[data-testid="meeting-title-input"]')
        .should('have.attr', 'aria-label', 'Meeting title')
    })
  })

  describe('Error Handling', () => {
    it('should handle API errors gracefully', () => {
      // Intercept API calls and return error
      cy.intercept('POST', '/api/v1/meetings', {
        statusCode: 500,
        body: { error: 'Internal server error' }
      }).as('createMeetingError')
      
      cy.get('[data-testid="nav-meetings"]').click()
      cy.get('[data-testid="create-meeting-button"]').click()
      
      // Fill form and submit
      cy.get('[data-testid="meeting-title-input"]').type('Test Meeting')
      cy.get('[data-testid="meeting-datetime-input"]')
        .type(new Date(Date.now() + 86400000).toISOString().slice(0, 16))
      
      cy.get('[data-testid="submit-meeting-button"]').click()
      
      // Verify error handling
      cy.wait('@createMeetingError')
      cy.get('[data-testid="error-notification"]')
        .should('be.visible')
        .and('contain', 'Failed to create meeting')
      
      // Verify form remains filled
      cy.get('[data-testid="meeting-title-input"]')
        .should('have.value', 'Test Meeting')
    })

    it('should handle network connectivity issues', () => {
      // Simulate offline mode
      cy.visit('/meetings')
      
      cy.window().then((win) => {
        win.navigator.onLine = false
        win.dispatchEvent(new Event('offline'))
      })
      
      // Verify offline indicator
      cy.get('[data-testid="offline-indicator"]')
        .should('be.visible')
        .and('contain', 'You are currently offline')
      
      // Verify cached data is still available
      cy.get('[data-testid="meetings-table"]').should('be.visible')
      
      // Simulate going back online
      cy.window().then((win) => {
        win.navigator.onLine = true
        win.dispatchEvent(new Event('online'))
      })
      
      // Verify online indicator
      cy.get('[data-testid="offline-indicator"]').should('not.exist')
    })
  })
})