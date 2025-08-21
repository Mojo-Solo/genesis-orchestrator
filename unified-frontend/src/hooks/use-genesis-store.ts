import { create } from 'zustand'
import { devtools, persist } from 'zustand/middleware'
import { immer } from 'zustand/middleware/immer'

// GENESIS Application State Types
export interface User {
  id: string
  name: string
  email: string
  role: 'admin' | 'manager' | 'member' | 'viewer'
  avatar?: string
  tenantId: string
}

export interface Project {
  id: string
  name: string
  description: string
  status: 'active' | 'completed' | 'paused' | 'archived'
  progress: number
  owner: string
  createdAt: Date
  updatedAt: Date
}

export interface Assessment {
  id: string
  type: 'exit_planning' | 'business_transition' | 'succession_planning'
  name: string
  personalReadiness: number
  financialReadiness: number
  businessReadiness: number
  overallScore: number
  readinessLevel: string
  completedAt: Date
  projectId?: string
}

export interface SystemMetrics {
  stability: number
  tokenEfficiency: number
  responseTime: number
  activeUsers: number
  lagPerformance: {
    stability: number
    avgExecutionTime: number
    totalExecutions: number
    successRate: number
  }
  rcrPerformance: {
    tokenReduction: number
    avgResponseTime: number
    routingAccuracy: number
    totalRoutes: number
  }
  lastUpdated: Date
}

export interface Notification {
  id: string
  title: string
  message: string
  type: 'info' | 'success' | 'warning' | 'error'
  read: boolean
  createdAt: Date
  actionUrl?: string
}

export interface UIPreferences {
  theme: 'light' | 'dark' | 'system'
  sidebarCollapsed: boolean
  defaultView: 'grid' | 'list' | 'kanban'
  notificationsEnabled: boolean
  autoRefresh: boolean
  refreshInterval: number // in seconds
}

// Main Store Interface
interface GenesisStore {
  // User & Authentication
  user: User | null
  isAuthenticated: boolean
  isLoading: boolean
  
  // Projects & Assessments
  projects: Project[]
  assessments: Assessment[]
  currentProject: Project | null
  
  // System Metrics
  metrics: SystemMetrics | null
  
  // UI State
  notifications: Notification[]
  preferences: UIPreferences
  
  // Actions - Authentication
  setUser: (user: User | null) => void
  login: (email: string, password: string) => Promise<void>
  logout: () => void
  
  // Actions - Projects
  addProject: (project: Omit<Project, 'id' | 'createdAt' | 'updatedAt'>) => void
  updateProject: (id: string, updates: Partial<Project>) => void
  deleteProject: (id: string) => void
  setCurrentProject: (project: Project | null) => void
  
  // Actions - Assessments  
  addAssessment: (assessment: Omit<Assessment, 'id' | 'completedAt'>) => void
  updateAssessment: (id: string, updates: Partial<Assessment>) => void
  deleteAssessment: (id: string) => void
  
  // Actions - Metrics
  updateMetrics: (metrics: Partial<SystemMetrics>) => void
  
  // Actions - Notifications
  addNotification: (notification: Omit<Notification, 'id' | 'createdAt'>) => void
  markNotificationRead: (id: string) => void
  clearNotifications: () => void
  
  // Actions - UI Preferences
  updatePreferences: (preferences: Partial<UIPreferences>) => void
  toggleSidebar: () => void
  
  // Actions - Data Management
  reset: () => void
  hydrate: () => void
}

// Initial State
const initialState = {
  user: null,
  isAuthenticated: false,
  isLoading: false,
  projects: [],
  assessments: [],
  currentProject: null,
  metrics: null,
  notifications: [],
  preferences: {
    theme: 'system' as const,
    sidebarCollapsed: false,
    defaultView: 'grid' as const,
    notificationsEnabled: true,
    autoRefresh: true,
    refreshInterval: 30,
  },
}

// Create the store
export const useGenesisStore = create<GenesisStore>()(
  devtools(
    persist(
      immer((set, get) => ({
        ...initialState,
        
        // Authentication Actions
        setUser: (user) =>
          set((state) => {
            state.user = user
            state.isAuthenticated = !!user
            state.isLoading = false
          }),
          
        login: async (email, password) => {
          set((state) => {
            state.isLoading = true
          })
          
          try {
            // Simulate API call
            await new Promise(resolve => setTimeout(resolve, 1000))
            
            // Mock successful login
            const mockUser: User = {
              id: '1',
              name: 'John Doe',
              email,
              role: 'admin',
              tenantId: 'tenant-1',
              avatar: '/avatars/john-doe.png',
            }
            
            set((state) => {
              state.user = mockUser
              state.isAuthenticated = true
              state.isLoading = false
            })
          } catch (error) {
            set((state) => {
              state.isLoading = false
            })
            throw error
          }
        },
        
        logout: () =>
          set((state) => {
            state.user = null
            state.isAuthenticated = false
            state.currentProject = null
            // Clear sensitive data but keep preferences
          }),
          
        // Project Actions
        addProject: (projectData) =>
          set((state) => {
            const newProject: Project = {
              ...projectData,
              id: `project-${Date.now()}`,
              createdAt: new Date(),
              updatedAt: new Date(),
            }
            state.projects.push(newProject)
          }),
          
        updateProject: (id, updates) =>
          set((state) => {
            const projectIndex = state.projects.findIndex(p => p.id === id)
            if (projectIndex !== -1) {
              state.projects[projectIndex] = {
                ...state.projects[projectIndex],
                ...updates,
                updatedAt: new Date(),
              }
            }
          }),
          
        deleteProject: (id) =>
          set((state) => {
            state.projects = state.projects.filter(p => p.id !== id)
            if (state.currentProject?.id === id) {
              state.currentProject = null
            }
          }),
          
        setCurrentProject: (project) =>
          set((state) => {
            state.currentProject = project
          }),
          
        // Assessment Actions
        addAssessment: (assessmentData) =>
          set((state) => {
            const newAssessment: Assessment = {
              ...assessmentData,
              id: `assessment-${Date.now()}`,
              completedAt: new Date(),
            }
            state.assessments.push(newAssessment)
          }),
          
        updateAssessment: (id, updates) =>
          set((state) => {
            const assessmentIndex = state.assessments.findIndex(a => a.id === id)
            if (assessmentIndex !== -1) {
              state.assessments[assessmentIndex] = {
                ...state.assessments[assessmentIndex],
                ...updates,
              }
            }
          }),
          
        deleteAssessment: (id) =>
          set((state) => {
            state.assessments = state.assessments.filter(a => a.id !== id)
          }),
          
        // Metrics Actions
        updateMetrics: (metricsUpdate) =>
          set((state) => {
            state.metrics = {
              ...state.metrics,
              ...metricsUpdate,
              lastUpdated: new Date(),
            } as SystemMetrics
          }),
          
        // Notification Actions
        addNotification: (notificationData) =>
          set((state) => {
            const newNotification: Notification = {
              ...notificationData,
              id: `notification-${Date.now()}`,
              createdAt: new Date(),
            }
            state.notifications.unshift(newNotification)
            
            // Keep only last 50 notifications
            if (state.notifications.length > 50) {
              state.notifications = state.notifications.slice(0, 50)
            }
          }),
          
        markNotificationRead: (id) =>
          set((state) => {
            const notification = state.notifications.find(n => n.id === id)
            if (notification) {
              notification.read = true
            }
          }),
          
        clearNotifications: () =>
          set((state) => {
            state.notifications = []
          }),
          
        // UI Preferences Actions
        updatePreferences: (preferencesUpdate) =>
          set((state) => {
            state.preferences = {
              ...state.preferences,
              ...preferencesUpdate,
            }
          }),
          
        toggleSidebar: () =>
          set((state) => {
            state.preferences.sidebarCollapsed = !state.preferences.sidebarCollapsed
          }),
          
        // Data Management Actions
        reset: () =>
          set((state) => {
            Object.assign(state, initialState)
          }),
          
        hydrate: () => {
          // This will be called after the persisted state is restored
          // You can add any hydration logic here
        },
      })),
      {
        name: 'genesis-store',
        version: 1,
        // Only persist certain parts of the state
        partialize: (state) => ({
          user: state.user,
          preferences: state.preferences,
          projects: state.projects,
          assessments: state.assessments,
        }),
        // Custom storage options
        storage: {
          getItem: (name) => {
            const str = localStorage.getItem(name)
            if (!str) return null
            try {
              return JSON.parse(str)
            } catch {
              return null
            }
          },
          setItem: (name, value) => {
            localStorage.setItem(name, JSON.stringify(value))
          },
          removeItem: (name) => localStorage.removeItem(name),
        },
        // Handle migrations for version changes
        migrate: (persistedState: any, version) => {
          if (version === 0) {
            // Migration logic from version 0 to 1
            return {
              ...persistedState,
              preferences: {
                ...initialState.preferences,
                ...persistedState.preferences,
              },
            }
          }
          return persistedState
        },
      }
    ),
    {
      name: 'genesis-store',
      enabled: process.env.NODE_ENV === 'development',
    }
  )
)

// Selectors for optimized component subscriptions
export const useUser = () => useGenesisStore(state => state.user)
export const useProjects = () => useGenesisStore(state => state.projects)
export const useCurrentProject = () => useGenesisStore(state => state.currentProject)
export const useAssessments = () => useGenesisStore(state => state.assessments)
export const useMetrics = () => useGenesisStore(state => state.metrics)
export const useNotifications = () => useGenesisStore(state => state.notifications)
export const usePreferences = () => useGenesisStore(state => state.preferences)
export const useIsAuthenticated = () => useGenesisStore(state => state.isAuthenticated)

// Computed selectors
export const useUnreadNotifications = () => 
  useGenesisStore(state => state.notifications.filter(n => !n.read))

export const useProjectsByStatus = (status: Project['status']) =>
  useGenesisStore(state => state.projects.filter(p => p.status === status))

export const useAssessmentsByProject = (projectId: string) =>
  useGenesisStore(state => state.assessments.filter(a => a.projectId === projectId))

// Action hooks for convenience
export const useGenesisActions = () => {
  const {
    setUser,
    login,
    logout,
    addProject,
    updateProject,
    deleteProject,
    setCurrentProject,
    addAssessment,
    updateAssessment,
    deleteAssessment,
    updateMetrics,
    addNotification,
    markNotificationRead,
    clearNotifications,
    updatePreferences,
    toggleSidebar,
    reset,
  } = useGenesisStore()
  
  return {
    setUser,
    login,
    logout,
    addProject,
    updateProject,
    deleteProject,
    setCurrentProject,
    addAssessment,
    updateAssessment,
    deleteAssessment,
    updateMetrics,
    addNotification,
    markNotificationRead,
    clearNotifications,
    updatePreferences,
    toggleSidebar,
    reset,
  }
}