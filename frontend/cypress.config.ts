import { defineConfig } from 'cypress'

export default defineConfig({
  e2e: {
    // Base URL for the application
    baseUrl: 'http://localhost:3000',
    
    // Viewport settings
    viewportWidth: 1280,
    viewportHeight: 720,
    
    // Test files location
    specPattern: 'cypress/e2e/**/*.cy.{js,jsx,ts,tsx}',
    
    // Support file
    supportFile: 'cypress/support/e2e.ts',
    
    // Downloads folder
    downloadsFolder: 'cypress/downloads',
    
    // Screenshots and videos
    screenshotsFolder: 'cypress/screenshots',
    videosFolder: 'cypress/videos',
    
    // Video recording
    video: true,
    videoCompression: 32,
    
    // Screenshot settings
    screenshotOnRunFailure: true,
    
    // Test isolation
    testIsolation: true,
    
    // Timeouts
    defaultCommandTimeout: 10000,
    requestTimeout: 10000,
    responseTimeout: 10000,
    pageLoadTimeout: 30000,
    
    // Retry settings
    retries: {
      runMode: 2,
      openMode: 0,
    },
    
    // Experimental features
    experimentalStudio: true,
    experimentalWebKitSupport: true,
    
    // Browser settings
    chromeWebSecurity: false,
    
    // Environment variables
    env: {
      // API URLs
      apiUrl: 'http://localhost:8000/api/v1',
      wsUrl: 'ws://localhost:8000/ws',
      
      // Test users
      adminEmail: 'admin@test.com',
      adminPassword: 'password123',
      userEmail: 'user@test.com',
      userPassword: 'password123',
      
      // Feature flags
      enableRealtime: true,
      enableAnalytics: true,
      enableSecurity: true,
      
      // Test data
      testTenantId: '1',
      testMeetingId: '1',
      
      // Coverage
      coverage: true,
      codeCoverage: {
        url: 'http://localhost:3000/__coverage__',
      },
    },

    setupNodeEvents(on, config) {
      // Code coverage task
      require('@cypress/code-coverage/task')(on, config)
      
      // Custom tasks
      on('task', {
        // Log to console
        log(message) {
          console.log(message)
          return null
        },
        
        // Clear test data
        clearTestData() {
          // Implementation would clear test database
          console.log('Clearing test data...')
          return null
        },
        
        // Seed test data
        seedTestData() {
          // Implementation would seed test database
          console.log('Seeding test data...')
          return null
        },
        
        // Get test data
        getTestData(type: string) {
          // Return mock test data based on type
          const testData = {
            meetings: [
              {
                id: '1',
                title: 'Test Meeting',
                status: 'scheduled',
                scheduled_at: new Date().toISOString(),
              },
            ],
            users: [
              {
                id: '1',
                email: 'test@example.com',
                name: 'Test User',
                role: 'user',
              },
            ],
          }
          return testData[type] || []
        },
        
        // Performance monitoring
        measurePerformance(metrics) {
          console.log('Performance metrics:', metrics)
          return null
        },
        
        // Database operations
        dbQuery(query: string) {
          // Mock database query for testing
          console.log('DB Query:', query)
          return { success: true, data: [] }
        },
        
        // File operations
        readFile(filePath: string) {
          const fs = require('fs')
          return fs.readFileSync(filePath, 'utf8')
        },
        
        writeFile({ filePath, content }: { filePath: string; content: string }) {
          const fs = require('fs')
          fs.writeFileSync(filePath, content)
          return null
        },
        
        // Network simulation
        simulateNetworkConditions(conditions: any) {
          console.log('Simulating network conditions:', conditions)
          return null
        },
      })
      
      // Browser launch options
      on('before:browser:launch', (browser, launchOptions) => {
        if (browser.name === 'chrome') {
          // Add Chrome flags for better testing
          launchOptions.args.push('--disable-dev-shm-usage')
          launchOptions.args.push('--disable-gpu')
          launchOptions.args.push('--no-sandbox')
          launchOptions.args.push('--disable-web-security')
          launchOptions.args.push('--allow-running-insecure-content')
          
          // Enable code coverage
          if (config.env.coverage) {
            launchOptions.args.push('--disable-background-timer-throttling')
            launchOptions.args.push('--disable-backgrounding-occluded-windows')
            launchOptions.args.push('--disable-renderer-backgrounding')
          }
        }
        
        return launchOptions
      })
      
      // After run
      on('after:run', (results) => {
        console.log('Test run completed:', {
          totalTests: results.totalTests,
          totalPassed: results.totalPassed,
          totalFailed: results.totalFailed,
          totalDuration: results.totalDuration,
        })
      })
      
      // After spec
      on('after:spec', (spec, results) => {
        if (results && results.video) {
          // Move video file if test passed (optional cleanup)
          if (results.stats.failures === 0) {
            // Could delete video for passed tests to save space
            // fs.unlinkSync(results.video)
          }
        }
      })

      return config
    },
  },

  component: {
    // Component testing configuration
    devServer: {
      framework: 'next',
      bundler: 'webpack',
    },
    
    // Component test files
    specPattern: 'src/**/*.cy.{js,jsx,ts,tsx}',
    
    // Viewport for component tests
    viewportWidth: 1000,
    viewportHeight: 660,
    
    // Support file for component tests
    supportFile: 'cypress/support/component.ts',
    
    // Video recording for component tests
    video: false,
    
    setupNodeEvents(on, config) {
      // Component-specific setup
      require('@cypress/code-coverage/task')(on, config)
      return config
    },
  },

  // Global configuration
  projectId: 'ai-project-management',
  
  // File server settings
  fileServerFolder: '.',
  
  // Fixtures folder
  fixturesFolder: 'cypress/fixtures',
  
  // Node version
  nodeVersion: 'system',
  
  // Exclude files from watching
  watchForFileChanges: true,
  excludeSpecPattern: [
    '**/1-getting-started/*',
    '**/2-advanced-examples/*',
    '**/__snapshots__/*',
    '**/*.hot-update.js',
  ],
  
  // Include shadow DOM
  includeShadowDom: true,
  
  // Modifiers
  modifyObstructiveCode: true,
  
  // Scrolling behavior
  scrollBehavior: 'center',
  
  // Wait for animations
  waitForAnimations: true,
  animationDistanceThreshold: 5,
  
  // User agent
  userAgent: 'Cypress E2E Tests',
  
  // Block hosts (for testing offline scenarios)
  blockHosts: [],
  
  // Hosts to exclude from proxy
  hosts: {},
  
  // Number of tests to keep in memory
  numTestsKeptInMemory: 50,
  
  // Platform specific settings
  platform: 'linux',
  
  // Report configuration
  reporter: 'cypress-multi-reporters',
  reporterOptions: {
    configFile: 'cypress/reporter-config.json',
  },
  
  // Trashcan cleanup
  trashAssetsBeforeRuns: true,
})