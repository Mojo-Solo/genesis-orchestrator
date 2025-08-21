"use client";
import { BudgetTable } from "@/components/tables/BudgetTable";
import { Card, CardHeader, CardTitle, CardContent } from "@/components/ui/card";
import { useAppStore } from "@/lib/store";

export default function BudgetPage() {
  const budget = useAppStore((s) => s.budget);
  return (
    <div className="space-y-6">
      <Card className="rounded-2xl">
        <CardHeader><CardTitle>BUDGET</CardTitle></CardHeader>
        <CardContent><BudgetTable kind="plan" data={budget} /></CardContent>
      </Card>
      <Card className="rounded-2xl">
        <CardHeader><CardTitle>ACTUAL</CardTitle></CardHeader>
        <CardContent><BudgetTable kind="actual" data={budget} /></CardContent>
      </Card>
      <Card className="rounded-2xl">
        <CardHeader><CardTitle>VARIANCE</CardTitle></CardHeader>
        <CardContent><BudgetTable kind="variance" data={budget} /></CardContent>
      </Card>
    </div>
  );
}