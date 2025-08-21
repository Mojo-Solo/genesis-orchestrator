import '@testing-library/jest-dom'
import 'jest-canvas-mock'
import { TextEncoder, TextDecoder } from 'util'
import { server } from './src/__tests__/mocks/server'
import { configure } from '@testing-library/react'

// Polyfills for Node.js environment
global.TextEncoder = TextEncoder
global.TextDecoder = TextDecoder

// Mock window.matchMedia
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: jest.fn().mockImplementation(query => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: jest.fn(), // deprecated
    removeListener: jest.fn(), // deprecated
    addEventListener: jest.fn(),
    removeEventListener: jest.fn(),
    dispatchEvent: jest.fn(),
  })),
})

// Mock window.ResizeObserver
global.ResizeObserver = jest.fn().mockImplementation(() => ({
  observe: jest.fn(),
  unobserve: jest.fn(),
  disconnect: jest.fn(),
}))

// Mock IntersectionObserver
global.IntersectionObserver = jest.fn().mockImplementation(() => ({
  observe: jest.fn(),
  unobserve: jest.fn(),
  disconnect: jest.fn(),
}))

// Mock Notification API
global.Notification = jest.fn().mockImplementation(() => ({
  close: jest.fn(),
}))

// Mock WebSocket
global.WebSocket = jest.fn().mockImplementation(() => ({
  send: jest.fn(),
  close: jest.fn(),
  addEventListener: jest.fn(),
  removeEventListener: jest.fn(),
  readyState: 1,
  CONNECTING: 0,
  OPEN: 1,
  CLOSING: 2,
  CLOSED: 3,
}))

// Mock fetch if not available
if (!global.fetch) {
  global.fetch = jest.fn()
}

// Mock localStorage
const localStorageMock = {
  getItem: jest.fn(),
  setItem: jest.fn(),
  removeItem: jest.fn(),
  clear: jest.fn(),
  length: 0,
  key: jest.fn(),
}
global.localStorage = localStorageMock

// Mock sessionStorage
const sessionStorageMock = {
  getItem: jest.fn(),
  setItem: jest.fn(),
  removeItem: jest.fn(),
  clear: jest.fn(),
  length: 0,
  key: jest.fn(),
}
global.sessionStorage = sessionStorageMock

// Mock URL.createObjectURL
global.URL.createObjectURL = jest.fn(() => 'mocked-url')
global.URL.revokeObjectURL = jest.fn()

// Mock FileReader
global.FileReader = jest.fn().mockImplementation(() => ({
  readAsDataURL: jest.fn(),
  readAsText: jest.fn(),
  readAsArrayBuffer: jest.fn(),
  addEventListener: jest.fn(),
  removeEventListener: jest.fn(),
  result: null,
  error: null,
  readyState: 0,
  EMPTY: 0,
  LOADING: 1,
  DONE: 2,
}))

// Mock canvas context
HTMLCanvasElement.prototype.getContext = jest.fn().mockImplementation(() => ({
  fillRect: jest.fn(),
  clearRect: jest.fn(),
  getImageData: jest.fn(() => ({
    data: new Array(4),
  })),
  putImageData: jest.fn(),
  createImageData: jest.fn(() => []),
  setTransform: jest.fn(),
  drawImage: jest.fn(),
  save: jest.fn(),
  fillText: jest.fn(),
  restore: jest.fn(),
  beginPath: jest.fn(),
  moveTo: jest.fn(),
  lineTo: jest.fn(),
  closePath: jest.fn(),
  stroke: jest.fn(),
  translate: jest.fn(),
  scale: jest.fn(),
  rotate: jest.fn(),
  arc: jest.fn(),
  fill: jest.fn(),
  measureText: jest.fn(() => ({ width: 0 })),
  transform: jest.fn(),
  rect: jest.fn(),
  clip: jest.fn(),
}))

// Mock window.crypto
Object.defineProperty(window, 'crypto', {
  value: {
    getRandomValues: jest.fn().mockImplementation((arr) => {
      for (let i = 0; i < arr.length; i++) {
        arr[i] = Math.floor(Math.random() * 256)
      }
      return arr
    }),
    randomUUID: jest.fn(() => '550e8400-e29b-41d4-a716-446655440000'),
    subtle: {
      digest: jest.fn().mockResolvedValue(new ArrayBuffer(32)),
      encrypt: jest.fn().mockResolvedValue(new ArrayBuffer(16)),
      decrypt: jest.fn().mockResolvedValue(new ArrayBuffer(16)),
    },
  },
})

// Mock console methods for cleaner test output
const originalError = console.error
console.error = (...args) => {
  // Suppress specific React warnings in tests
  if (
    typeof args[0] === 'string' &&
    (args[0].includes('Warning: ReactDOM.render is no longer supported') ||
     args[0].includes('Warning: React.createFactory() is deprecated') ||
     args[0].includes('Warning: componentWillReceiveProps has been renamed'))
  ) {
    return
  }
  originalError.call(console, ...args)
}

// Mock environment variables
process.env.NODE_ENV = 'test'
process.env.NEXT_PUBLIC_API_BASE_URL = 'http://localhost:8000/api/v1'
process.env.NEXT_PUBLIC_WS_URL = 'ws://localhost:8000/ws'
process.env.NEXT_PUBLIC_APP_ENV = 'test'

// Configure React Testing Library
configure({
  testIdAttribute: 'data-testid',
  // Increase timeout for async operations
  asyncUtilTimeout: 5000,
  // Show suggestions for better queries
  showOriginalStackTrace: true,
})

// Setup MSW (Mock Service Worker) for API mocking
beforeAll(() => {
  // Start the MSW server
  server.listen({
    onUnhandledRequest: 'error',
  })
})

afterEach(() => {
  // Reset handlers after each test
  server.resetHandlers()
  
  // Clear all mocks
  jest.clearAllMocks()
  
  // Clear localStorage and sessionStorage
  localStorage.clear()
  sessionStorage.clear()
  
  // Reset any timers
  jest.clearAllTimers()
})

afterAll(() => {
  // Stop the MSW server
  server.close()
  
  // Restore console
  console.error = originalError
})

// Global test utilities
global.testUtils = {
  // Mock API responses
  mockApiResponse: (data, status = 200) => ({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(data),
    text: () => Promise.resolve(JSON.stringify(data)),
  }),

  // Mock user data
  mockUser: {
    id: '1',
    email: 'test@example.com',
    name: 'Test User',
    role: 'user',
    tenant_id: '1',
    avatar_url: null,
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-01T00:00:00Z',
  },

  // Mock admin user data
  mockAdminUser: {
    id: '2',
    email: 'admin@example.com',
    name: 'Admin User',
    role: 'admin',
    tenant_id: '1',
    avatar_url: null,
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-01T00:00:00Z',
  },

  // Mock tenant data
  mockTenant: {
    id: '1',
    name: 'Test Tenant',
    slug: 'test-tenant',
    tier: 'professional',
    status: 'active',
    settings: {},
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-01T00:00:00Z',
  },

  // Mock meeting data
  mockMeeting: {
    id: '1',
    title: 'Test Meeting',
    description: 'A test meeting',
    status: 'scheduled',
    scheduled_at: '2024-12-01T10:00:00Z',
    duration_minutes: 60,
    meeting_url: 'https://zoom.us/j/123456789',
    participants: [
      { email: 'john@example.com', name: 'John Doe' },
      { email: 'jane@example.com', name: 'Jane Smith' },
    ],
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-01T00:00:00Z',
  },

  // Mock transcript data
  mockTranscript: {
    id: '1',
    meeting_id: '1',
    content: 'This is a test transcript of the meeting.',
    language: 'en',
    confidence_score: 0.95,
    sentences: [
      {
        speaker: 'John Doe',
        text: 'Hello everyone, let\'s start the meeting.',
        timestamp: '00:00:10',
        confidence: 0.98,
      },
      {
        speaker: 'Jane Smith',
        text: 'Thanks John, I have updates on the project.',
        timestamp: '00:00:25',
        confidence: 0.95,
      },
    ],
    created_at: '2024-01-01T00:00:00Z',
  },

  // Mock dashboard data
  mockDashboardData: {
    meetings: {
      total: 25,
      completed: 20,
      scheduled: 5,
      completion_rate: 0.8,
    },
    action_items: {
      total: 45,
      completed: 32,
      overdue: 3,
      completion_rate: 0.71,
    },
    insights: {
      generated: 18,
      actionable: 15,
      confidence_avg: 0.87,
    },
    trends: {
      meeting_frequency: 'increasing',
      completion_rates: 'stable',
      engagement: 'improving',
    },
  },

  // Create mock file
  createMockFile: (name = 'test.txt', content = 'test content', type = 'text/plain') => {
    const file = new File([content], name, { type })
    Object.defineProperty(file, 'size', { value: content.length })
    return file
  },

  // Create mock image file
  createMockImageFile: (name = 'test.jpg', width = 100, height = 100) => {
    const canvas = document.createElement('canvas')
    canvas.width = width
    canvas.height = height
    const ctx = canvas.getContext('2d')
    ctx.fillRect(0, 0, width, height)
    
    return new Promise((resolve) => {
      canvas.toBlob((blob) => {
        const file = new File([blob], name, { type: 'image/jpeg' })
        resolve(file)
      }, 'image/jpeg')
    })
  },

  // Wait for async operations
  waitFor: (callback, options = {}) => {
    return new Promise((resolve, reject) => {
      const timeout = options.timeout || 1000
      const interval = options.interval || 50
      const startTime = Date.now()

      const check = () => {
        try {
          const result = callback()
          if (result) {
            resolve(result)
            return
          }
        } catch (error) {
          // Continue checking
        }

        if (Date.now() - startTime > timeout) {
          reject(new Error('Timeout waiting for condition'))
          return
        }

        setTimeout(check, interval)
      }

      check()
    })
  },

  // Simulate user interaction delays
  delay: (ms = 100) => new Promise(resolve => setTimeout(resolve, ms)),
}

// Performance monitoring for tests
let testStartTime
beforeEach(() => {
  testStartTime = performance.now()
})

afterEach(() => {
  const testDuration = performance.now() - testStartTime
  if (testDuration > 5000) { // Warn if test takes longer than 5 seconds
    console.warn(`Test took ${testDuration.toFixed(2)}ms - consider optimizing`)
  }
})

// Memory leak detection
let initialMemory
beforeEach(() => {
  if (global.gc) {
    global.gc()
  }
  initialMemory = process.memoryUsage().heapUsed
})

afterEach(() => {
  if (global.gc) {
    global.gc()
  }
  const finalMemory = process.memoryUsage().heapUsed
  const memoryIncrease = finalMemory - initialMemory
  
  // Warn if memory usage increased significantly (>10MB)
  if (memoryIncrease > 10 * 1024 * 1024) {
    console.warn(`Memory usage increased by ${(memoryIncrease / 1024 / 1024).toFixed(2)}MB during test`)
  }
})