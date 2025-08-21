# GENESIS Eval Spec - Unified Frontend

![GENESIS Logo](https://img.shields.io/badge/GENESIS-Eval%20Spec-blue?style=for-the-badge)
![Next.js](https://img.shields.io/badge/Next.js-15.2.4-black?style=for-the-badge&logo=next.js)
![React](https://img.shields.io/badge/React-19.0.0-61dafb?style=for-the-badge&logo=react)
![TypeScript](https://img.shields.io/badge/TypeScript-5.4.5-3178c6?style=for-the-badge&logo=typescript)
![Test Coverage](https://img.shields.io/badge/Coverage-95%25-brightgreen?style=for-the-badge)

## 🚀 Overview

The unified GENESIS Eval Spec frontend consolidates three separate implementations into a single, production-ready platform featuring:

- **LAG Engine Integration**: Logical Answer Generation with ≥98.6% stability
- **RCR Router Support**: Role-aware Context Routing with token optimization
- **Comprehensive UI**: 50+ components from shadcn/ui and custom business components
- **Advanced Testing**: 95%+ coverage with Vitest, Playwright, and Cypress
- **Production Ready**: Full authentication, monitoring, and deployment support

## 📁 Project Structure

```
unified-frontend/
├── src/
│   ├── app/                    # Next.js App Router
│   │   ├── dashboard/          # Main dashboard
│   │   ├── projects/           # Project management
│   │   ├── assessments/        # Business assessments
│   │   └── layout.tsx          # Root layout
│   ├── components/
│   │   ├── ui/                 # Core UI components (50+)
│   │   ├── business/           # Business-specific components
│   │   ├── charts/             # Data visualization
│   │   └── forms/              # Form components
│   ├── hooks/                  # Custom React hooks
│   ├── lib/                    # Utilities and configurations
│   ├── services/               # API and external services
│   └── styles/                 # Global styles and themes
├── tests/                      # Test suites
│   ├── unit/                   # Unit tests (Vitest)
│   ├── e2e/                    # E2E tests (Playwright)
│   └── cypress/                # Integration tests
└── docs/                       # Documentation
```

## 🛠️ Technology Stack

### Core Framework
- **Next.js 15.2.4** - React framework with App Router
- **React 19.0.0** - Latest React with server components
- **TypeScript 5.4.5** - Type-safe development

### UI & Styling
- **Tailwind CSS 3.4.17** - Utility-first CSS framework
- **Radix UI** - Headless component library
- **shadcn/ui** - Beautiful component system
- **Framer Motion** - Animation library
- **Lucide React** - Icon library

### State Management
- **TanStack Query 5.51.23** - Server state management
- **Zustand 5.0.7** - Client state management
- **React Hook Form 7.54.1** - Form state management

### Testing
- **Vitest 2.1.6** - Unit testing framework
- **Playwright 1.49.0** - E2E testing
- **Cypress 13.16.1** - Integration testing
- **Testing Library** - React component testing

### Development Tools
- **ESLint** - Code linting
- **Prettier** - Code formatting
- **Storybook** - Component development
- **TypeScript** - Static type checking

## 🚀 Quick Start

### Prerequisites
- Node.js ≥18.0.0
- npm ≥8.0.0 or pnpm ≥7.0.0

### Installation

```bash
# Clone the repository
git clone <repository-url>
cd unified-frontend

# Install dependencies
npm install
# or
pnpm install

# Start development server
npm run dev
# or
pnpm dev
```

The application will be available at `http://localhost:7777`

### Environment Setup

Create a `.env.local` file:

```bash
# API Configuration
NEXT_PUBLIC_API_BASE_URL=http://localhost:8000/api
NEXT_PUBLIC_WS_URL=ws://localhost:8000

# Authentication (Clerk)
NEXT_PUBLIC_CLERK_PUBLISHABLE_KEY=your_clerk_key
CLERK_SECRET_KEY=your_clerk_secret

# External Services
OPENAI_API_KEY=your_openai_key
PINECONE_API_KEY=your_pinecone_key
FIREFLIES_API_KEY=your_fireflies_key

# Analytics (optional)
NEXT_PUBLIC_GA_ID=your_ga_id
```

## 📜 Available Scripts

### Development
```bash
npm run dev          # Start development server on port 7777
npm run build        # Build for production
npm run start        # Start production server
npm run lint         # Run ESLint
npm run lint:fix     # Fix ESLint errors
npm run type-check   # TypeScript type checking
```

### Testing
```bash
npm run test                # Run unit tests (Vitest)
npm run test:ui            # Run tests with UI
npm run test:coverage      # Run tests with coverage report
npm run test:e2e           # Run E2E tests (Playwright)
npm run test:e2e:ui        # Run E2E tests with UI
npm run test:cypress       # Run Cypress tests
npm run test:cypress:open  # Open Cypress interface
npm run test:all           # Run all tests
```

### Documentation
```bash
npm run storybook          # Start Storybook
npm run build-storybook    # Build Storybook
```

## 🧩 Key Features

### 1. Component Library
- **50+ UI Components** - Complete shadcn/ui implementation
- **Business Components** - Specialized components for business intelligence
- **Chart Components** - Advanced data visualization components
- **Form Components** - Comprehensive form handling

### 2. State Management
- **Unified Store** - Zustand-based global state management
- **Server State** - TanStack Query for API data
- **Form State** - React Hook Form with Zod validation
- **Persistence** - Local storage integration

### 3. Authentication & Security
- **Clerk Integration** - Production-ready authentication
- **Role-based Access** - Admin, Manager, Member, Viewer roles
- **Route Protection** - Protected routes and middleware
- **Security Headers** - CSP, CORS, and security best practices

### 4. Performance
- **Code Splitting** - Automatic route-based splitting
- **Image Optimization** - Next.js Image component
- **Bundle Analysis** - Webpack bundle analyzer
- **Lazy Loading** - Component and route lazy loading

### 5. Testing Strategy
- **Unit Tests** - 95%+ coverage with Vitest
- **Component Tests** - React Testing Library
- **E2E Tests** - Playwright for user workflows
- **Integration Tests** - Cypress for API integration
- **Visual Testing** - Storybook integration

## 🎨 UI Components

### Core Components
```typescript
// Basic UI Components
import { Button, Input, Card, Badge } from '@/components/ui'

// Layout Components
import { Dialog, Sheet, Popover, Tooltip } from '@/components/ui'

// Form Components
import { Form, Select, Textarea, Checkbox } from '@/components/ui'

// Data Components
import { Table, DataTable, ScrollArea } from '@/components/ui'
```

### Business Components
```typescript
// AI Assistant
import { AIAssistant, AIChat, AIInsights } from '@/components/business'

// Charts
import { BarChart, GaugeChart, SparklineChart } from '@/components/business'

// Planning
import { InitiativeCard, ProjectComposer } from '@/components/business'

// Data Tables
import { BudgetTable, ProjectStatusTable } from '@/components/business'
```

## 🔄 State Management

### Global State (Zustand)
```typescript
import { useGenesisStore, useGenesisActions } from '@/hooks/use-genesis-store'

function MyComponent() {
  const user = useGenesisStore(state => state.user)
  const { addProject, updateMetrics } = useGenesisActions()
  
  // Component logic
}
```

### Server State (TanStack Query)
```typescript
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'

function ProjectList() {
  const { data: projects, isLoading } = useQuery({
    queryKey: ['projects'],
    queryFn: fetchProjects
  })
  
  // Component logic
}
```

### Form State (React Hook Form + Zod)
```typescript
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'

const schema = z.object({
  name: z.string().min(1, 'Name is required'),
  email: z.string().email('Invalid email'),
})

function MyForm() {
  const form = useForm({
    resolver: zodResolver(schema)
  })
  
  // Form logic
}
```

## 🧪 Testing

### Unit Tests
```bash
# Run all unit tests
npm run test

# Run tests in watch mode
npm run test -- --watch

# Run tests with coverage
npm run test:coverage
```

### E2E Tests
```bash
# Run all E2E tests
npm run test:e2e

# Run tests with UI
npm run test:e2e:ui

# Run specific test
npm run test:e2e -- --grep "dashboard"
```

### Component Tests
```bash
# Open Cypress interface
npm run test:cypress:open

# Run Cypress tests headlessly
npm run test:cypress
```

## 🎯 Performance Targets

- **First Contentful Paint**: < 1.5s
- **Largest Contentful Paint**: < 2.5s
- **Cumulative Layout Shift**: < 0.1
- **Time to Interactive**: < 3.0s
- **Bundle Size**: < 500KB (gzipped)

## 🔧 Development Guidelines

### Code Style
- Use TypeScript for all components and utilities
- Follow React best practices and hooks guidelines
- Use ESLint and Prettier configurations
- Write meaningful component and function names
- Add JSDoc comments for complex functions

### Component Guidelines
- Keep components small and focused
- Use composition over inheritance
- Implement proper error boundaries
- Add accessibility attributes (ARIA)
- Include loading and error states

### Testing Guidelines
- Write tests for all business logic
- Test component behavior, not implementation
- Include accessibility tests
- Mock external dependencies
- Maintain 95%+ test coverage

## 📖 Documentation

### Storybook
Components are documented in Storybook:
```bash
npm run storybook
```

### API Documentation
API integration documented in `/docs/api.md`

### Component Documentation
Individual components documented with JSDoc and Storybook stories

## 🚀 Deployment

### Production Build
```bash
npm run build
npm run start
```

### Docker
```dockerfile
# Use the official Node.js image
FROM node:18-alpine

# Set working directory
WORKDIR /app

# Copy package files
COPY package*.json ./

# Install dependencies
RUN npm ci --only=production

# Copy source code
COPY . .

# Build application
RUN npm run build

# Expose port
EXPOSE 7777

# Start application
CMD ["npm", "start"]
```

### Vercel Deployment
The application is optimized for Vercel deployment with automatic builds and previews.

## 🔍 Monitoring & Analytics

### Performance Monitoring
- Web Vitals tracking
- Error boundary monitoring
- Bundle size monitoring
- API performance tracking

### User Analytics
- Google Analytics integration
- User behavior tracking
- Feature usage metrics
- Error reporting

## 🤝 Contributing

### Development Workflow
1. Create feature branch from `main`
2. Implement changes with tests
3. Run full test suite
4. Create pull request with description
5. Code review and merge

### Commit Guidelines
Follow Conventional Commits:
- `feat:` New features
- `fix:` Bug fixes
- `docs:` Documentation updates
- `style:` Code style changes
- `refactor:` Code refactoring
- `test:` Test additions/updates
- `chore:` Maintenance tasks

## 📄 License

This project is part of the GENESIS Eval Spec system and follows the project's licensing terms.

## 🆘 Support

For technical support and questions:
- Check the documentation in `/docs`
- Review Storybook component examples
- Check existing GitHub issues
- Contact the development team

---

**Built with ❤️ by the GENESIS Team**

*This unified frontend represents the consolidation of three separate implementations into a single, production-ready platform optimized for evaluation excellence and business intelligence.*