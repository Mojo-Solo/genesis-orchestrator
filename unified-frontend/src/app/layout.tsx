import type { Metadata } from 'next'
import { Inter, JetBrains_Mono } from 'next/font/google'
import { cn } from '@/lib/utils'
import { Providers } from '@/components/providers'
import { Toaster } from '@/components/ui/sonner'
import { TooltipProvider } from '@/components/ui/tooltip'
import '@/styles/globals.css'

const inter = Inter({
  subsets: ['latin'],
  variable: '--font-sans',
  display: 'swap',
})

const jetbrainsMono = JetBrains_Mono({
  subsets: ['latin'],
  variable: '--font-mono',
  display: 'swap',
})

export const metadata: Metadata = {
  title: {
    default: 'GENESIS Eval Spec - Unified Platform',
    template: '%s | GENESIS Eval Spec'
  },
  description: 'Unified GENESIS Evaluation Specification platform with LAG + RCR capabilities for business intelligence and strategic planning.',
  keywords: ['GENESIS', 'LAG', 'RCR', 'Business Intelligence', 'Strategic Planning', 'AI Assistant'],
  authors: [
    {
      name: 'GENESIS Team',
      url: 'https://genesis-eval-spec.com',
    },
  ],
  creator: 'GENESIS Orchestrator',
  publisher: 'GENESIS Team',
  applicationName: 'GENESIS Eval Spec',
  generator: 'Next.js',
  referrer: 'origin-when-cross-origin',
  colorScheme: 'light dark',
  themeColor: [
    { media: '(prefers-color-scheme: light)', color: '#0ea5e9' },
    { media: '(prefers-color-scheme: dark)', color: '#0c4a6e' },
  ],
  viewport: {
    width: 'device-width',
    initialScale: 1,
    maximumScale: 5,
    userScalable: true,
  },
  robots: {
    index: true,
    follow: true,
    nocache: false,
    googleBot: {
      index: true,
      follow: true,
      noimageindex: false,
      'max-video-preview': 'large',
      'max-image-preview': 'large',
      'max-snippet': 160,
    },
  },
  openGraph: {
    type: 'website',
    locale: 'en_US',
    url: 'https://genesis-eval-spec.com',
    siteName: 'GENESIS Eval Spec',
    title: 'GENESIS Eval Spec - Unified Platform',
    description: 'Advanced business intelligence and strategic planning platform with AI-powered insights.',
    images: [
      {
        url: '/og-image.png',
        width: 1200,
        height: 630,
        alt: 'GENESIS Eval Spec Platform',
      },
    ],
  },
  twitter: {
    card: 'summary_large_image',
    title: 'GENESIS Eval Spec - Unified Platform',
    description: 'Advanced business intelligence and strategic planning platform with AI-powered insights.',
    images: ['/twitter-image.png'],
  },
  icons: {
    icon: [
      { url: '/favicon.ico', sizes: '16x16' },
      { url: '/favicon-32x32.png', type: 'image/png', sizes: '32x32' },
      { url: '/favicon-16x16.png', type: 'image/png', sizes: '16x16' },
    ],
    shortcut: '/favicon.ico',
    apple: [
      { url: '/apple-touch-icon.png', sizes: '180x180' },
    ],
  },
  manifest: '/manifest.json',
  other: {
    'mobile-web-app-capable': 'yes',
    'apple-mobile-web-app-capable': 'yes',
    'apple-mobile-web-app-status-bar-style': 'default',
    'format-detection': 'telephone=no',
  },
}

interface RootLayoutProps {
  children: React.ReactNode
}

export default function RootLayout({ children }: RootLayoutProps) {
  return (
    <html lang="en" suppressHydrationWarning>
      <head />
      <body
        className={cn(
          'min-h-screen bg-background font-sans antialiased',
          'selection:bg-primary/20 selection:text-primary-foreground',
          inter.variable,
          jetbrainsMono.variable
        )}
        suppressHydrationWarning
      >
        <Providers>
          <TooltipProvider delayDuration={300}>
            <div className="relative flex min-h-screen flex-col">
              <div className="flex-1">
                {children}
              </div>
            </div>
            <Toaster 
              position="top-right"
              expand={false}
              richColors
              closeButton
            />
          </TooltipProvider>
        </Providers>
        
        {/* Performance Monitoring Script */}
        {process.env.NODE_ENV === 'production' && (
          <script
            dangerouslySetInnerHTML={{
              __html: `
                // Basic performance monitoring
                window.addEventListener('load', function() {
                  const perfData = performance.getEntriesByType('navigation')[0];
                  if (perfData && perfData.loadEventEnd - perfData.fetchStart > 3000) {
                    console.warn('Page load time exceeded 3 seconds:', perfData.loadEventEnd - perfData.fetchStart);
                  }
                });
              `,
            }}
          />
        )}
        
        {/* Accessibility Announcements */}
        <div
          id="a11y-status-message"
          aria-live="polite"
          aria-atomic="true"
          className="sr-only"
        />
        
        {/* Skip to Content Link */}
        <a
          href="#main-content"
          className={cn(
            'absolute left-4 top-4 z-50 -translate-y-16 transform',
            'rounded-md bg-primary px-4 py-2 text-primary-foreground',
            'transition-transform focus:translate-y-0',
            'focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2'
          )}
        >
          Skip to main content
        </a>
      </body>
    </html>
  )
}