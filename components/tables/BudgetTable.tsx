"use client";
import { ScrollArea } from "@/components/ui/scroll-area";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { BudgetPlan, BudgetMonth } from "@/lib/types";
import { variance, profit } from "@/lib/util";

const months = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];

export function BudgetTable({ kind, data }: { kind: "plan" | "actual" | "variance"; data: BudgetPlan }) {
  const plan = data.plan; const actual = data.actual;
  const rowFor = (name: string, pick: (m: BudgetMonth) => number, vari = false) => (
    <TableRow>
      <TableCell className="font-medium">{name}</TableCell>
      {months.map((m, i) => {
        const v = vari ? pick(actual[i]) - pick(plan[i]) : pick(kind === "plan" ? plan[i] : actual[i]);
        const isNeg = vari && v < 0;
        return (
          <TableCell key={m} className={isNeg ? "text-red-600" : ""}>
            {v < 0 ? "-" : ""}${Math.abs(v).toLocaleString()}
          </TableCell>
        );
      })}
      <TableCell className="font-semibold">
        {(() => {
          const sum = months.reduce((acc, _, i) => acc + (vari ? pick(actual[i]) - pick(plan[i]) : pick(kind === "plan" ? plan[i] : actual[i])), 0);
        return `${sum < 0 ? "-" : ""}${Math.abs(sum).toLocaleString()}`; })()}
      </TableCell>
    </TableRow>
  );

  return (
    <ScrollArea className="w-full">
      <Table>
        <TableHeader>
          <TableRow className="bg-brand-brown text-white">
            <TableHead className="w-40">Category</TableHead>
            {months.map((m) => (<TableHead key={m}>{m}</TableHead>))}
            <TableHead className="w-28">TOTAL</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {rowFor("Revenue", (m) => m.revenue, kind === "variance")}
          {rowFor("Offering A", (m) => m.offerings[0] ?? 0, kind === "variance")}
          {rowFor("Offering B", (m) => m.offerings[1] ?? 0, kind === "variance")}
          {rowFor("Offering C", (m) => m.offerings[2] ?? 0, kind === "variance")}
          {rowFor("Expense", (m) => m.expense, kind === "variance")}
          {rowFor("Profit", (m) => profit(m), kind === "variance")}
        </TableBody>
      </Table>
    </ScrollArea>
  );
}