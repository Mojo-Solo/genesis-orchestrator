"use client";
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import type { BudgetPlan } from "@/lib/types";
import { profit } from "@/lib/util";

export function BarBudget({ plan, actual }: { plan: BudgetPlan["plan"]; actual: BudgetPlan["actual"]; }) {
  const sum = (arr: number[]) => arr.reduce((a, b) => a + b, 0);
  const p = {
    revenue: plan.reduce((a, m) => a + m.revenue, 0),
    cogs: plan.reduce((a, m) => a + sum(m.offerings), 0),
    expense: plan.reduce((a, m) => a + m.expense, 0),
    profit: plan.reduce((a, m) => a + profit(m), 0),
  };
  const a = {
    revenue: actual.reduce((a, m) => a + m.revenue, 0),
    cogs: actual.reduce((a, m) => a + sum(m.offerings), 0),
    expense: actual.reduce((a, m) => a + m.expense, 0),
    profit: actual.reduce((a, m) => a + profit(m), 0),
  };
  const data = [
    { name: "REVENUE", Budget: p.revenue, Actual: a.revenue },
    { name: "COST OF GOODS SOLD", Budget: p.cogs, Actual: a.cogs },
    { name: "TOTAL EXPENSES", Budget: p.expense, Actual: a.expense },
    { name: "NET PROFIT / LOSS", Budget: p.profit, Actual: a.profit },
  ];
  return (
    <div className="h-[320px]">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={data}>
          <CartesianGrid strokeDasharray="3 3" />
          <XAxis dataKey="name" tick={{ fontSize: 12 }} />
          <YAxis />
          <Tooltip formatter={(v: number)=>`${v.toLocaleString()}`} />
          <Legend />
          <Bar dataKey="Budget" fill="#8B5E3C" />
          <Bar dataKey="Actual" fill="#9CA3AF" />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}