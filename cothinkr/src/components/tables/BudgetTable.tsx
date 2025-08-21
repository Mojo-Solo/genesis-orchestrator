'use client';

import React from 'react';
import { Card, CardHeader, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ScrollArea } from '@/components/ui/scroll-area';
import { useAppStore } from '@/lib/store';
import { formatCurrency, getAllMonths, getQuarterMonths, profit, variance } from '@/lib/utils';
import { BudgetMonth } from '@/lib/types';

interface BudgetTableProps {
  type: 'plan' | 'actual' | 'variance';
  title: string;
  className?: string;
}

const BudgetTable: React.FC<BudgetTableProps> = ({ type, title, className = '' }) => {
  const { budget } = useAppStore();
  
  const months = getAllMonths();
  const quarters = ['Q1', 'Q2', 'Q3', 'Q4'];
  
  const getData = () => {
    if (type === 'variance') {
      return budget.plan.map((planMonth, index) => {
        const actualMonth = budget.actual[index];
        return variance(planMonth, actualMonth);
      });
    }
    return budget[type];
  };

  const data = getData();
  
  const calculateQuarterTotal = (startMonth: number, field: string) => {
    const quarterData = data.slice(startMonth, startMonth + 3);
    if (type === 'variance') {
      return quarterData.reduce((sum, month) => sum + ((month as any)[field] || 0), 0);
    } else {
      if (field === 'offerings') {
        return quarterData.reduce((sum, month) => 
          sum + (month as BudgetMonth).offerings.reduce((a, b) => a + b, 0), 0
        );
      }
      return quarterData.reduce((sum, month) => sum + ((month as any)[field] || 0), 0);
    }
  };

  const calculateYearTotal = (field: string) => {
    if (type === 'variance') {
      return data.reduce((sum, month) => sum + ((month as any)[field] || 0), 0);
    } else {
      if (field === 'offerings') {
        return data.reduce((sum, month) => 
          sum + (month as BudgetMonth).offerings.reduce((a, b) => a + b, 0), 0
        );
      }
      return data.reduce((sum, month) => sum + ((month as any)[field] || 0), 0);
    }
  };

  const formatValue = (value: number, isVariance: boolean = false) => {
    if (isVariance && value < 0) {
      return (
        <span className="text-red-600">
          −{formatCurrency(Math.abs(value))}
        </span>
      );
    }
    return formatCurrency(value);
  };

  const getCellValue = (monthData: any, field: string) => {
    if (type === 'variance') {
      return (monthData as any)[field] || 0;
    } else {
      if (field === 'offerings') {
        return (monthData as BudgetMonth).offerings.reduce((a, b) => a + b, 0);
      }
      if (field === 'profit') {
        return profit(monthData as BudgetMonth);
      }
      return (monthData as any)[field] || 0;
    }
  };

  return (
    <Card className={className}>
      <CardHeader className="bg-brand-brown text-white">
        <h3 className="text-lg font-semibold">{title}</h3>
      </CardHeader>
      <CardContent className="p-0">
        <ScrollArea className="w-full">
          <div className="min-w-[1200px]">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-32 sticky left-0 bg-white border-r font-semibold">
                    Category
                  </TableHead>
                  {quarters.map((quarter, qIndex) => (
                    <React.Fragment key={quarter}>
                      <TableHead 
                        colSpan={3} 
                        className="text-center bg-brand-sand font-semibold text-brand-ink border-l border-r"
                      >
                        {quarter}
                      </TableHead>
                    </React.Fragment>
                  ))}
                  <TableHead className="text-center bg-gray-100 font-semibold border-l">
                    TOTAL
                  </TableHead>
                </TableRow>
                <TableRow>
                  <TableHead className="sticky left-0 bg-white border-r"></TableHead>
                  {quarters.map((quarter, qIndex) => 
                    getQuarterMonths(quarter).map((month) => (
                      <TableHead key={`${quarter}-${month}`} className="text-center text-xs">
                        {month}
                      </TableHead>
                    ))
                  )}
                  <TableHead className="text-center text-xs border-l">Year</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {/* Revenue Row */}
                <TableRow>
                  <TableCell className="font-medium sticky left-0 bg-white border-r">
                    Revenue
                  </TableCell>
                  {months.map((month, index) => (
                    <TableCell key={`revenue-${index}`} className="text-right">
                      {formatValue(getCellValue(data[index], 'revenue'), type === 'variance')}
                    </TableCell>
                  ))}
                  <TableCell className="text-right font-medium border-l">
                    {formatValue(calculateYearTotal('revenue'), type === 'variance')}
                  </TableCell>
                </TableRow>

                {/* COGS/Offerings Row */}
                <TableRow>
                  <TableCell className="font-medium sticky left-0 bg-white border-r">
                    COGS
                  </TableCell>
                  {months.map((month, index) => (
                    <TableCell key={`cogs-${index}`} className="text-right">
                      {formatValue(getCellValue(data[index], 'offerings'), type === 'variance')}
                    </TableCell>
                  ))}
                  <TableCell className="text-right font-medium border-l">
                    {formatValue(calculateYearTotal('offerings'), type === 'variance')}
                  </TableCell>
                </TableRow>

                {/* Expenses Row */}
                <TableRow>
                  <TableCell className="font-medium sticky left-0 bg-white border-r">
                    Total Expenses
                  </TableCell>
                  {months.map((month, index) => (
                    <TableCell key={`expense-${index}`} className="text-right">
                      {formatValue(getCellValue(data[index], 'expense'), type === 'variance')}
                    </TableCell>
                  ))}
                  <TableCell className="text-right font-medium border-l">
                    {formatValue(calculateYearTotal('expense'), type === 'variance')}
                  </TableCell>
                </TableRow>

                {/* Profit Row */}
                <TableRow className="border-t-2">
                  <TableCell className="font-semibold sticky left-0 bg-white border-r">
                    Net Profit/Loss
                  </TableCell>
                  {months.map((month, index) => (
                    <TableCell key={`profit-${index}`} className="text-right font-medium">
                      {formatValue(getCellValue(data[index], 'profit'), type === 'variance')}
                    </TableCell>
                  ))}
                  <TableCell className="text-right font-semibold border-l">
                    {formatValue(calculateYearTotal('profit'), type === 'variance')}
                  </TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </div>
        </ScrollArea>
      </CardContent>
    </Card>
  );
};

export default BudgetTable;