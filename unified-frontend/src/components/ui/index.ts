// GENESIS Unified Component Library
// Consolidated from frontend/, cothinkr/, and app/ implementations
// Enhanced with best features from each source

// Core UI Components (from /frontend/ - most comprehensive)
export { Button } from './button'
export { Input } from './input'
export { Label } from './label'
export { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from './card'
export { Badge } from './badge'
export { Avatar, AvatarImage, AvatarFallback } from './avatar'
export { Separator } from './separator'
export { Progress } from './progress'
export { Skeleton } from './skeleton'

// Layout Components
export { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle, SheetTrigger } from './sheet'
export { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from './dialog'
export { Drawer, DrawerContent, DrawerDescription, DrawerFooter, DrawerHeader, DrawerTitle, DrawerTrigger } from './drawer'
export { Popover, PopoverContent, PopoverTrigger } from './popover'
export { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from './tooltip'

// Navigation Components  
export { NavigationMenu, NavigationMenuContent, NavigationMenuItem, NavigationMenuLink, NavigationMenuList, NavigationMenuTrigger } from './navigation-menu'
export { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from './breadcrumb'
export { Tabs, TabsContent, TabsList, TabsTrigger } from './tabs'

// Form Components
export { Form, FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from './form'
export { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './select'
export { Textarea } from './textarea'
export { Checkbox } from './checkbox'
export { RadioGroup, RadioGroupItem } from './radio-group'
export { Switch } from './switch'
export { Slider } from './slider'

// Data Display Components
export { Table, TableBody, TableCell, TableHead, TableHeader, TableRow, TableCaption } from './table'
export { DataTable } from './data-table'
export { ScrollArea, ScrollBar } from './scroll-area'
export { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from './accordion'
export { Collapsible, CollapsibleContent, CollapsibleTrigger } from './collapsible'

// Feedback Components
export { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger } from './alert-dialog'
export { Alert, AlertDescription, AlertTitle } from './alert'
export { Toaster } from './sonner'
export { toast } from './use-toast'

// Menu Components
export { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger, DropdownMenuCheckboxItem, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuShortcut, DropdownMenuSub, DropdownMenuSubContent, DropdownMenuSubTrigger } from './dropdown-menu'
export { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuLabel, ContextMenuSeparator, ContextMenuTrigger, ContextMenuCheckboxItem, ContextMenuRadioGroup, ContextMenuRadioItem, ContextMenuShortcut, ContextMenuSub, ContextMenuSubContent, ContextMenuSubTrigger } from './context-menu'
export { Menubar, MenubarContent, MenubarItem, MenubarMenu, MenubarSeparator, MenubarShortcut, MenubarTrigger } from './menubar'

// Utility Components
export { Spinner } from './spinner'
export { LoadingSpinner } from './loading-spinner'
export { EmptyState } from './empty-state'
export { ErrorBoundary } from './error-boundary'

// Enhanced Components (from cothinkr integration)
export { CommandDialog } from './command-dialog'
export { Calendar } from './calendar'
export { DatePicker } from './date-picker'

// Chart Components (consolidated)
export { ChartContainer, ChartTooltip, ChartTooltipContent } from './chart'

// Layout Utilities
export { Container } from './container'
export { Stack } from './stack'
export { Grid } from './grid'
export { Flex } from './flex'

// Type Definitions
export type {
  ButtonProps,
  InputProps,
  CardProps,
  DialogProps,
  AlertProps,
  TableProps,
  FormProps,
  ChartProps,
} from './types'