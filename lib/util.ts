import type { BudgetMonth } from "@/lib/types";
export const profit = (m: BudgetMonth) => m.revenue - m.expense - (m.offerings?.reduce((a,b)=>a+b,0) ?? 0);
export const variance = (plan: BudgetMonth, act: BudgetMonth) => ({
  revenue: act.revenue - plan.revenue,
  expense: act.expense - plan.expense,
  profit: (act.revenue - act.expense) - (plan.revenue - plan.expense),
});