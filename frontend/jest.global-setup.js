const { spawn } = require('child_process')
const { createServer } = require('http')
const handler = require('serve-handler')
const path = require('path')

let testServer

module.exports = async () => {
  console.log('🚀 Setting up global test environment...')

  // Set test environment variables
  process.env.NODE_ENV = 'test'
  process.env.NEXT_PUBLIC_API_BASE_URL = 'http://localhost:8000/api/v1'
  process.env.NEXT_PUBLIC_WS_URL = 'ws://localhost:8000/ws'
  process.env.NEXT_PUBLIC_APP_ENV = 'test'

  // Start mock API server for integration tests
  if (!process.env.SKIP_MOCK_SERVER) {
    console.log('🔧 Starting mock API server...')
    
    testServer = createServer((request, response) => {
      return handler(request, response, {
        public: path.join(__dirname, 'src/__tests__/mocks/api'),
        rewrites: [
          { source: '/api/v1/**', destination: '/$1.json' },
        ],
        headers: [
          {
            source: '**',
            headers: [
              {
                key: 'Access-Control-Allow-Origin',
                value: '*',
              },
              {
                key: 'Access-Control-Allow-Methods',
                value: 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
              },
              {
                key: 'Access-Control-Allow-Headers',
                value: 'Content-Type, Authorization, X-Requested-With',
              },
            ],
          },
        ],
      })
    })

    await new Promise((resolve, reject) => {
      testServer.listen(8000, (err) => {
        if (err) {
          console.error('❌ Failed to start mock API server:', err)
          reject(err)
        } else {
          console.log('✅ Mock API server started on http://localhost:8000')
          resolve()
        }
      })
    })
  }

  // Initialize test database if needed
  if (process.env.INIT_TEST_DB) {
    console.log('🗄️ Initializing test database...')
    
    try {
      // Run database migrations for testing
      const migration = spawn('npm', ['run', 'db:test:migrate'], {
        stdio: 'inherit',
        cwd: path.join(__dirname, '../backend'),
      })

      await new Promise((resolve, reject) => {
        migration.on('close', (code) => {
          if (code === 0) {
            console.log('✅ Test database initialized')
            resolve()
          } else {
            console.error('❌ Failed to initialize test database')
            reject(new Error(`Database migration failed with code ${code}`))
          }
        })

        migration.on('error', reject)
      })
    } catch (error) {
      console.error('❌ Database initialization error:', error)
      // Don't fail the entire test suite for database issues
    }
  }

  // Warm up critical system components
  console.log('🔥 Warming up system components...')
  
  try {
    // Pre-load common modules to improve test performance
    require('@testing-library/react')
    require('@testing-library/jest-dom')
    require('@testing-library/user-event')
    
    console.log('✅ System components warmed up')
  } catch (error) {
    console.warn('⚠️ Warning: Failed to warm up some components:', error.message)
  }

  // Setup performance monitoring
  if (process.env.MONITOR_PERFORMANCE) {
    console.log('📊 Setting up performance monitoring...')
    
    global.testPerformance = {
      startTime: Date.now(),
      testTimes: {},
      memoryUsage: {},
    }

    // Monitor memory usage
    const initialMemory = process.memoryUsage()
    global.testPerformance.memoryUsage.initial = initialMemory
    
    console.log('✅ Performance monitoring enabled')
    console.log(`📈 Initial memory usage: ${Math.round(initialMemory.heapUsed / 1024 / 1024)}MB`)
  }

  // Setup coverage collection
  if (process.env.COLLECT_COVERAGE) {
    console.log('📋 Setting up code coverage collection...')
    
    // Ensure coverage directory exists
    const fs = require('fs')
    const coverageDir = path.join(__dirname, 'coverage')
    
    if (!fs.existsSync(coverageDir)) {
      fs.mkdirSync(coverageDir, { recursive: true })
    }
    
    console.log('✅ Coverage collection enabled')
  }

  // Setup test data fixtures
  console.log('📦 Loading test fixtures...')
  
  try {
    const mockData = require('./src/__tests__/fixtures/mockData')
    global.testFixtures = mockData
    
    console.log('✅ Test fixtures loaded')
  } catch (error) {
    console.warn('⚠️ Warning: Failed to load test fixtures:', error.message)
    global.testFixtures = {}
  }

  // Setup mock external services
  console.log('🔌 Setting up mock external services...')
  
  global.mockServices = {
    fireflies: {
      apiKey: 'test_fireflies_key',
      endpoint: 'http://localhost:8001/fireflies',
    },
    pinecone: {
      apiKey: 'test_pinecone_key',
      environment: 'test-env',
      endpoint: 'http://localhost:8002/pinecone',
    },
    openai: {
      apiKey: 'test_openai_key',
      endpoint: 'http://localhost:8003/openai',
    },
  }

  console.log('✅ Mock external services configured')

  // Final setup validation
  console.log('🔍 Validating test environment...')
  
  const requiredEnvVars = [
    'NODE_ENV',
    'NEXT_PUBLIC_API_BASE_URL',
    'NEXT_PUBLIC_WS_URL',
  ]

  const missingVars = requiredEnvVars.filter(varName => !process.env[varName])
  
  if (missingVars.length > 0) {
    console.warn('⚠️ Warning: Missing environment variables:', missingVars.join(', '))
  } else {
    console.log('✅ Environment validation passed')
  }

  // Store server reference for cleanup
  global.__TEST_SERVER__ = testServer

  console.log('🎉 Global test environment setup complete!')
  console.log('=' .repeat(60))
}