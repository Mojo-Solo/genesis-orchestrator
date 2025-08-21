'use client';

import React from 'react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import { useBudgetSummary } from '@/lib/store';
import { formatCurrency } from '@/lib/utils';

interface BarBudgetProps {
  className?: string;
}

const BarBudget: React.FC<BarBudgetProps> = ({ className = '' }) => {
  const budgetSummary = useBudgetSummary();
  
  const data = [
    {
      category: 'Revenue',
      Budget: budgetSummary.plan.revenue,
      Actual: budgetSummary.actual.revenue
    },
    {
      category: 'COGS',
      Budget: budgetSummary.plan.offerings,
      Actual: budgetSummary.actual.offerings
    },
    {
      category: 'Expenses',
      Budget: budgetSummary.plan.expense,
      Actual: budgetSummary.actual.expense
    },
    {
      category: 'Net Profit',
      Budget: budgetSummary.plan.revenue - budgetSummary.plan.offerings - budgetSummary.plan.expense,
      Actual: budgetSummary.actual.revenue - budgetSummary.actual.offerings - budgetSummary.actual.expense
    }
  ];

  const CustomTooltip = ({ active, payload, label }: any) => {
    if (active && payload && payload.length) {
      return (
        <div className="bg-white p-3 border border-gray-200 rounded-lg shadow-lg">
          <p className="font-medium">{label}</p>
          {payload.map((entry: any, index: number) => (
            <p key={index} style={{ color: entry.color }} className="text-sm">
              {entry.dataKey}: {formatCurrency(entry.value)}
            </p>
          ))}
        </div>
      );
    }
    return null;
  };

  return (
    <div className={`w-full h-80 ${className}`}>
      <div className="mb-4">
        <h3 className="text-lg font-semibold text-gray-900">Budget vs Actual</h3>
        <p className="text-sm text-gray-600">2024 Financial Performance</p>
      </div>
      
      <ResponsiveContainer width="100%" height="100%">
        <BarChart
          data={data}
          margin={{
            top: 20,
            right: 30,
            left: 20,
            bottom: 5,
          }}
          barCategoryGap="20%"
        >
          <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
          <XAxis 
            dataKey="category" 
            tick={{ fontSize: 12 }}
            tickLine={{ stroke: '#e2e8f0' }}
          />
          <YAxis 
            tick={{ fontSize: 12 }}
            tickLine={{ stroke: '#e2e8f0' }}
            tickFormatter={(value) => formatCurrency(value)}
          />
          <Tooltip content={<CustomTooltip />} />
          <Legend 
            wrapperStyle={{ fontSize: '14px' }}
          />
          <Bar 
            dataKey="Budget" 
            fill="#8B5E3C" 
            name="2024 Budget"
            radius={[2, 2, 0, 0]}
          />
          <Bar 
            dataKey="Actual" 
            fill="#E6D8C7" 
            name="2024 Actual"
            radius={[2, 2, 0, 0]}
          />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
};

export default BarBudget;