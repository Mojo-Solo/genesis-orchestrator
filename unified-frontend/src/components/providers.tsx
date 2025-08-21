'use client'

import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ReactQueryDevtools } from '@tanstack/react-query-devtools'
import { ThemeProvider } from 'next-themes'
import { useState, useEffect } from 'react'

// Initialize Query Client with optimized settings
function makeQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: {
        // Stale time for GENESIS data (5 minutes)
        staleTime: 5 * 60 * 1000,
        // Cache time for background updates (10 minutes)
        cacheTime: 10 * 60 * 1000,
        // Retry failed requests
        retry: (failureCount, error: any) => {
          // Don't retry on 4xx errors (client errors)
          if (error?.status >= 400 && error?.status < 500) {
            return false
          }
          // Retry up to 3 times for other errors
          return failureCount < 3
        },
        // Refetch on window focus for real-time data
        refetchOnWindowFocus: true,
        // Enable background updates
        refetchOnMount: true,
      },
      mutations: {
        // Retry mutations once
        retry: 1,
        // Default error handling
        onError: (error) => {
          console.error('Mutation error:', error)
        },
      },
    },
  })
}

let browserQueryClient: QueryClient | undefined = undefined

function getQueryClient() {
  if (typeof window === 'undefined') {
    // Server: always make a new query client
    return makeQueryClient()
  } else {
    // Browser: make a new query client if we don't already have one
    // This is very important so we don't re-make a new client if React
    // suspends during the initial render. This may not be needed if we
    // have a suspense boundary BELOW the creation of the query client
    if (!browserQueryClient) browserQueryClient = makeQueryClient()
    return browserQueryClient
  }
}

interface ProvidersProps {
  children: React.ReactNode
}

export function Providers({ children }: ProvidersProps) {
  // NOTE: Avoid useState when initializing the query client if you don't
  //       have a suspense boundary between this and the code that may suspend
  //       because React will throw away the client on the initial render if
  //       it suspends and there is no boundary
  const queryClient = getQueryClient()
  
  const [mounted, setMounted] = useState(false)

  useEffect(() => {
    setMounted(true)
  }, [])

  if (!mounted) {
    // Prevent hydration mismatch by not rendering theme-dependent content
    return (
      <QueryClientProvider client={queryClient}>
        <div className="min-h-screen bg-background">
          {children}
        </div>
      </QueryClientProvider>
    )
  }

  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider
        attribute="class"
        defaultTheme="system"
        enableSystem
        disableTransitionOnChange
        themes={['light', 'dark', 'system']}
      >
        {children}
        
        {/* Development tools */}
        {process.env.NODE_ENV === 'development' && (
          <ReactQueryDevtools
            initialIsOpen={false}
            position="bottom-right"
            toggleButtonProps={{
              style: {
                marginLeft: '5px',
                transform: 'none',
                width: '30px',
                height: '30px',
              },
            }}
          />
        )}
      </ThemeProvider>
    </QueryClientProvider>
  )
}