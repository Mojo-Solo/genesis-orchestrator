import { Metadata } from 'next'
import { redirect } from 'next/navigation'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { ArrowRight, Brain, Zap, Shield, BarChart3, Users, Workflow } from 'lucide-react'
import Link from 'next/link'

export const metadata: Metadata = {
  title: 'GENESIS Eval Spec - Welcome',
  description: 'Welcome to the unified GENESIS Evaluation Specification platform featuring LAG + RCR capabilities.',
}

const features = [
  {
    icon: Brain,
    title: 'LAG Engine',
    description: 'Logical Answer Generation with Cartesian decomposition and intelligent termination',
    badge: '≥98.6% Stability',
    color: 'text-blue-600',
  },
  {
    icon: Zap,
    title: 'RCR Router',
    description: 'Role-aware Context Routing with token budget management and importance scoring',
    badge: '20%+ Token Reduction',
    color: 'text-amber-600',
  },
  {
    icon: Shield,
    title: 'Security First',
    description: 'Comprehensive authentication, authorization, and audit logging',
    badge: 'Production Ready',
    color: 'text-green-600',
  },
  {
    icon: BarChart3,
    title: 'Business Intelligence',
    description: 'Advanced analytics, charting, and business planning capabilities',
    badge: 'AI-Powered',
    color: 'text-purple-600',
  },
  {
    icon: Users,
    title: 'Multi-Tenant',
    description: 'Isolated tenant architecture with comprehensive resource management',
    badge: 'Enterprise Scale',
    color: 'text-indigo-600',
  },
  {
    icon: Workflow,
    title: 'Orchestration',
    description: 'Unified agent coordination with monitoring and performance optimization',
    badge: 'Auto-Scaling',
    color: 'text-teal-600',
  },
]

const stats = [
  { label: 'Code Reduction', value: '67%', description: 'Eliminated duplicate services' },
  { label: 'Test Coverage', value: '95%+', description: 'Comprehensive testing suite' },
  { label: 'Performance', value: '≤200ms', description: 'API response time target' },
  { label: 'Stability', value: '98.6%', description: 'Evaluation requirement' },
]

export default function HomePage() {
  return (
    <div className="relative">
      {/* Hero Section */}
      <section className="relative overflow-hidden bg-gradient-to-br from-genesis-50 via-white to-genesis-100 dark:from-genesis-950 dark:via-background dark:to-genesis-900">
        <div className="absolute inset-0 bg-grid-pattern opacity-5" />
        <div className="relative">
          <div className="mx-auto max-w-7xl px-6 pb-32 pt-36 sm:pt-60 lg:px-8 lg:pt-32">
            <div className="mx-auto max-w-2xl gap-x-14 lg:mx-0 lg:flex lg:max-w-none lg:items-center">
              <div className="w-full max-w-xl lg:shrink-0 xl:max-w-2xl">
                <h1 className="text-4xl font-bold tracking-tight text-foreground sm:text-6xl">
                  GENESIS Eval Spec
                  <span className="block text-gradient">Unified Platform</span>
                </h1>
                <p className="relative mt-6 text-lg leading-8 text-muted-foreground sm:max-w-md lg:max-w-none">
                  Advanced business intelligence and strategic planning platform featuring LAG (Logical Answer Generation) 
                  and RCR (Role-aware Context Routing) capabilities with comprehensive AI-powered insights.
                </p>
                <div className="mt-10 flex items-center gap-x-6">
                  <Button asChild size="lg" className="bg-gradient-genesis">
                    <Link href="/dashboard">
                      Get Started <ArrowRight className="ml-2 h-4 w-4" />
                    </Link>
                  </Button>
                  <Button variant="outline" size="lg" asChild>
                    <Link href="/docs">Documentation</Link>
                  </Button>
                </div>
              </div>
              <div className="mt-14 flex justify-end gap-8 sm:-mt-44 sm:justify-start sm:pl-20 lg:mt-0 lg:pl-0">
                <div className="ml-auto w-44 flex-none space-y-8 pt-32 sm:ml-0 sm:pt-80 lg:order-last lg:pt-36 xl:order-none xl:pt-80">
                  <div className="relative">
                    <div className="glass aspect-[2/3] w-full rounded-xl bg-genesis-500/10 object-cover shadow-lg" />
                    <div className="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-inset ring-genesis-500/20" />
                  </div>
                </div>
                <div className="mr-auto w-44 flex-none space-y-8 sm:mr-0 sm:pt-52 lg:pt-36">
                  <div className="relative">
                    <div className="glass aspect-[2/3] w-full rounded-xl bg-genesis-600/10 object-cover shadow-lg" />
                    <div className="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-inset ring-genesis-600/20" />
                  </div>
                  <div className="relative">
                    <div className="glass aspect-[2/3] w-full rounded-xl bg-genesis-700/10 object-cover shadow-lg" />
                    <div className="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-inset ring-genesis-700/20" />
                  </div>
                </div>
                <div className="w-44 flex-none space-y-8 pt-32 sm:pt-0">
                  <div className="relative">
                    <div className="glass aspect-[2/3] w-full rounded-xl bg-genesis-800/10 object-cover shadow-lg" />
                    <div className="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-inset ring-genesis-800/20" />
                  </div>
                  <div className="relative">
                    <div className="glass aspect-[2/3] w-full rounded-xl bg-genesis-900/10 object-cover shadow-lg" />
                    <div className="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-inset ring-genesis-900/20" />
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Stats Section */}
      <section className="py-24 sm:py-32">
        <div className="mx-auto max-w-7xl px-6 lg:px-8">
          <div className="mx-auto max-w-2xl lg:max-w-none">
            <div className="text-center">
              <h2 className="text-3xl font-bold tracking-tight text-foreground sm:text-4xl">
                Platform Performance
              </h2>
              <p className="mt-4 text-lg leading-8 text-muted-foreground">
                Built for evaluation excellence with measurable performance metrics
              </p>
            </div>
            <dl className="mt-16 grid grid-cols-1 gap-0.5 overflow-hidden rounded-2xl text-center sm:grid-cols-2 lg:grid-cols-4">
              {stats.map((stat) => (
                <div key={stat.label} className="flex flex-col bg-muted/50 p-8">
                  <dt className="text-sm font-semibold leading-6 text-muted-foreground">{stat.label}</dt>
                  <dd className="order-first text-3xl font-bold tracking-tight text-foreground">{stat.value}</dd>
                  <dd className="mt-1 text-xs text-muted-foreground">{stat.description}</dd>
                </div>
              ))}
            </dl>
          </div>
        </div>
      </section>

      {/* Features Section */}
      <section className="py-24 sm:py-32 bg-muted/30">
        <div className="mx-auto max-w-7xl px-6 lg:px-8">
          <div className="mx-auto max-w-2xl text-center">
            <h2 className="text-3xl font-bold tracking-tight text-foreground sm:text-4xl">
              Core Capabilities
            </h2>
            <p className="mt-6 text-lg leading-8 text-muted-foreground">
              Comprehensive platform features designed for evaluation excellence and business intelligence
            </p>
          </div>
          <div className="mx-auto mt-16 max-w-2xl sm:mt-20 lg:mt-24 lg:max-w-none">
            <dl className="grid max-w-xl grid-cols-1 gap-x-8 gap-y-16 lg:max-w-none lg:grid-cols-3">
              {features.map((feature) => (
                <div key={feature.title} className="flex flex-col">
                  <Card className="h-full">
                    <CardHeader>
                      <div className="flex items-center gap-4">
                        <feature.icon className={`h-8 w-8 ${feature.color}`} />
                        <div className="flex-1">
                          <CardTitle className="text-lg">{feature.title}</CardTitle>
                          <Badge variant="secondary" className="mt-1 text-xs">
                            {feature.badge}
                          </Badge>
                        </div>
                      </div>
                    </CardHeader>
                    <CardContent>
                      <CardDescription className="text-sm leading-6">
                        {feature.description}
                      </CardDescription>
                    </CardContent>
                  </Card>
                </div>
              ))}
            </dl>
          </div>
        </div>
      </section>

      {/* CTA Section */}
      <section className="relative isolate -z-10 mt-32 sm:mt-48">
        <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
          <div className="mx-auto flex max-w-2xl flex-col gap-16 bg-white/5 px-6 py-16 ring-1 ring-white/10 sm:rounded-3xl sm:p-8 lg:mx-0 lg:max-w-none lg:flex-row lg:items-center lg:py-20 xl:gap-x-20 xl:px-20">
            <div className="w-full flex-auto">
              <h2 className="text-3xl font-bold tracking-tight text-foreground sm:text-4xl">
                Ready to Experience GENESIS?
              </h2>
              <p className="mt-6 text-lg leading-8 text-muted-foreground">
                Explore the unified platform featuring advanced AI capabilities, comprehensive business intelligence, 
                and evaluation-ready architecture with ≥98.6% stability guarantee.
              </p>
              <div className="mt-10 flex">
                <Button asChild size="lg" className="bg-gradient-genesis">
                  <Link href="/dashboard">
                    Launch Dashboard <ArrowRight className="ml-2 h-4 w-4" />
                  </Link>
                </Button>
              </div>
            </div>
            <div className="relative h-64 w-full lg:h-auto lg:w-96">
              <div className="absolute inset-0 rounded-xl bg-gradient-to-r from-genesis-400 via-genesis-500 to-genesis-600 opacity-20" />
              <div className="glass absolute inset-0 rounded-xl ring-1 ring-genesis-500/20" />
            </div>
          </div>
        </div>
        <div
          className="absolute inset-x-0 -top-16 -z-10 flex transform-gpu justify-center overflow-hidden blur-3xl"
          aria-hidden="true"
        >
          <div
            className="aspect-[1318/752] w-[82.375rem] flex-none bg-gradient-to-r from-genesis-400 to-genesis-600 opacity-25"
            style={{
              clipPath:
                'polygon(73.6% 51.7%, 91.7% 11.8%, 100% 46.4%, 97.4% 82.2%, 92.5% 84.9%, 75.7% 64%, 55.3% 47.5%, 46.5% 49.4%, 45% 62.9%, 50.3% 87.2%, 21.3% 64.1%, 0.1% 100%, 5.4% 51.1%, 21.4% 63.9%, 58.9% 0.2%, 73.6% 51.7%)',
            }}
          />
        </div>
      </section>
    </div>
  )
}