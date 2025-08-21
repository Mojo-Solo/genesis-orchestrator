import { clsx, type ClassValue } from "clsx"
import { twMerge } from "tailwind-merge"
import { BudgetMonth, Status } from "./types"

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

// Profit calculation helper
export const profit = (m: BudgetMonth) => 
  m.revenue - m.expense - m.offerings.reduce((a, b) => a + b, 0);

// Variance calculation helper
export const variance = (plan: BudgetMonth, actual: BudgetMonth) => ({
  revenue: actual.revenue - plan.revenue,
  offerings: actual.offerings.map((v, i) => v - (plan.offerings[i] ?? 0)),
  expense: actual.expense - plan.expense,
  profit: profit(actual) - profit(plan)
});

// Status color mapping
export const getStatusColor = (status: Status): string => {
  switch (status) {
    case 'On Target':
      return 'bg-green-500 text-white';
    case 'At Risk':
      return 'bg-amber-500 text-white';
    case 'Off Track':
    case 'Blocked':
      return 'bg-red-500 text-white';
    case 'Not Started':
      return 'bg-gray-500 text-white';
    case 'Done':
      return 'bg-blue-500 text-white';
    default:
      return 'bg-gray-500 text-white';
  }
};

// Format currency
export const formatCurrency = (amount: number): string => {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(amount);
};

// Format percentage
export const formatPercent = (value: number): string => {
  return `${value.toFixed(1)}%`;
};

// Quarter utilities
export const getQuarterMonths = (quarter: string): string[] => {
  switch (quarter) {
    case 'Q1': return ['Jan', 'Feb', 'Mar'];
    case 'Q2': return ['Apr', 'May', 'Jun'];
    case 'Q3': return ['Jul', 'Aug', 'Sep'];
    case 'Q4': return ['Oct', 'Nov', 'Dec'];
    default: return [];
  }
};

export const getAllMonths = (): string[] => [
  'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'
];