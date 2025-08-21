const fs = require('fs')
const path = require('path')

module.exports = async () => {
  console.log('🧹 Starting global test environment cleanup...')

  // Stop mock API server
  if (global.__TEST_SERVER__) {
    console.log('🛑 Stopping mock API server...')
    
    try {
      await new Promise((resolve) => {
        global.__TEST_SERVER__.close(() => {
          console.log('✅ Mock API server stopped')
          resolve()
        })
      })
    } catch (error) {
      console.error('❌ Failed to stop mock API server:', error)
    }
  }

  // Generate performance report
  if (process.env.MONITOR_PERFORMANCE && global.testPerformance) {
    console.log('📊 Generating performance report...')
    
    try {
      const finalMemory = process.memoryUsage()
      const totalTime = Date.now() - global.testPerformance.startTime
      
      const performanceReport = {
        summary: {
          totalTestTime: `${(totalTime / 1000).toFixed(2)}s`,
          initialMemory: `${Math.round(global.testPerformance.memoryUsage.initial.heapUsed / 1024 / 1024)}MB`,
          finalMemory: `${Math.round(finalMemory.heapUsed / 1024 / 1024)}MB`,
          memoryDelta: `${Math.round((finalMemory.heapUsed - global.testPerformance.memoryUsage.initial.heapUsed) / 1024 / 1024)}MB`,
          peakMemory: `${Math.round(finalMemory.heapTotal / 1024 / 1024)}MB`,
        },
        testTimes: global.testPerformance.testTimes,
        memorySnapshots: global.testPerformance.memoryUsage,
        recommendations: [],
      }

      // Add performance recommendations
      const memoryDelta = finalMemory.heapUsed - global.testPerformance.memoryUsage.initial.heapUsed
      if (memoryDelta > 50 * 1024 * 1024) { // 50MB
        performanceReport.recommendations.push('Consider optimizing memory usage - significant memory increase detected')
      }

      if (totalTime > 120000) { // 2 minutes
        performanceReport.recommendations.push('Test suite is taking longer than 2 minutes - consider optimizing slow tests')
      }

      // Write performance report
      const reportPath = path.join(__dirname, 'test-results', 'performance-report.json')
      fs.mkdirSync(path.dirname(reportPath), { recursive: true })
      fs.writeFileSync(reportPath, JSON.stringify(performanceReport, null, 2))
      
      console.log('✅ Performance report generated:', reportPath)
      console.log(`📈 Test suite completed in ${(totalTime / 1000).toFixed(2)}s`)
      console.log(`🧠 Memory delta: ${Math.round(memoryDelta / 1024 / 1024)}MB`)
      
      if (performanceReport.recommendations.length > 0) {
        console.log('💡 Performance recommendations:')
        performanceReport.recommendations.forEach(rec => console.log(`   - ${rec}`))
      }
    } catch (error) {
      console.error('❌ Failed to generate performance report:', error)
    }
  }

  // Generate coverage summary
  if (process.env.COLLECT_COVERAGE) {
    console.log('📋 Processing coverage data...')
    
    try {
      const coverageDir = path.join(__dirname, 'coverage')
      
      if (fs.existsSync(coverageDir)) {
        const lcovPath = path.join(coverageDir, 'lcov.info')
        
        if (fs.existsSync(lcovPath)) {
          // Parse LCOV file for summary stats
          const lcovContent = fs.readFileSync(lcovPath, 'utf8')
          const lines = lcovContent.split('\n')
          
          let totalLines = 0
          let coveredLines = 0
          let totalFunctions = 0
          let coveredFunctions = 0
          
          lines.forEach(line => {
            if (line.startsWith('LF:')) {
              totalLines += parseInt(line.split(':')[1])
            } else if (line.startsWith('LH:')) {
              coveredLines += parseInt(line.split(':')[1])
            } else if (line.startsWith('FNF:')) {
              totalFunctions += parseInt(line.split(':')[1])
            } else if (line.startsWith('FNH:')) {
              coveredFunctions += parseInt(line.split(':')[1])
            }
          })
          
          const lineCoverage = totalLines > 0 ? (coveredLines / totalLines * 100).toFixed(2) : 0
          const functionCoverage = totalFunctions > 0 ? (coveredFunctions / totalFunctions * 100).toFixed(2) : 0
          
          console.log('✅ Coverage summary:')
          console.log(`   Lines: ${lineCoverage}% (${coveredLines}/${totalLines})`)
          console.log(`   Functions: ${functionCoverage}% (${coveredFunctions}/${totalFunctions})`)
          
          // Check if coverage meets targets
          if (parseFloat(lineCoverage) < 95) {
            console.warn('⚠️ Warning: Line coverage below 95% target')
          }
          
          if (parseFloat(functionCoverage) < 95) {
            console.warn('⚠️ Warning: Function coverage below 95% target')
          }
        }
      }
    } catch (error) {
      console.error('❌ Failed to process coverage data:', error)
    }
  }

  // Clean up temporary test files
  console.log('🗂️ Cleaning up temporary files...')
  
  try {
    const tempDirs = [
      path.join(__dirname, 'tmp'),
      path.join(__dirname, '.tmp'),
      path.join(__dirname, 'node_modules/.cache/jest'),
    ]

    tempDirs.forEach(dir => {
      if (fs.existsSync(dir)) {
        try {
          fs.rmSync(dir, { recursive: true, force: true })
          console.log(`✅ Cleaned up: ${dir}`)
        } catch (error) {
          console.warn(`⚠️ Warning: Failed to clean up ${dir}:`, error.message)
        }
      }
    })
  } catch (error) {
    console.error('❌ Failed to clean up temporary files:', error)
  }

  // Generate test completion summary
  console.log('📝 Test completion summary...')
  
  try {
    const testResults = {
      timestamp: new Date().toISOString(),
      environment: {
        nodeVersion: process.version,
        platform: process.platform,
        arch: process.arch,
        ci: !!process.env.CI,
      },
      configuration: {
        testTimeout: 10000,
        coverageEnabled: !!process.env.COLLECT_COVERAGE,
        performanceMonitoring: !!process.env.MONITOR_PERFORMANCE,
      },
      cleanup: {
        serverStopped: !!global.__TEST_SERVER__,
        tempFilesCleared: true,
        memoryReleased: true,
      },
    }

    const summaryPath = path.join(__dirname, 'test-results', 'test-summary.json')
    fs.mkdirSync(path.dirname(summaryPath), { recursive: true })
    fs.writeFileSync(summaryPath, JSON.stringify(testResults, null, 2))
    
    console.log('✅ Test summary generated:', summaryPath)
  } catch (error) {
    console.error('❌ Failed to generate test summary:', error)
  }

  // Final memory cleanup
  if (global.testPerformance) {
    delete global.testPerformance
  }
  
  if (global.testFixtures) {
    delete global.testFixtures
  }
  
  if (global.mockServices) {
    delete global.mockServices
  }

  // Force garbage collection if available
  if (global.gc) {
    console.log('♻️ Running garbage collection...')
    global.gc()
    console.log('✅ Garbage collection completed')
  }

  console.log('🎉 Global test environment cleanup complete!')
  console.log('=' .repeat(60))
}