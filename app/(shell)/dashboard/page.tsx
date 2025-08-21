"use client";
import { GaugeDial } from "@/components/charts/GaugeDial";
import { BarBudget } from "@/components/charts/BarBudget";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { JournalPanel } from "@/components/JournalPanel";
import { useAppStore } from "@/lib/store";

export default function DashboardPage() {
  const vision = useAppStore((s) => s.vision);
  const budget = useAppStore((s) => s.budget);
  return (
    <div className="grid grid-cols-1 xl:grid-cols-3 gap-6">
      <div className="xl:col-span-2 space-y-6">
        <Card className="rounded-2xl">
          <CardHeader>
            <CardTitle>Vision</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
              <GaugeDial label="People" value={vision.peopleScore} />
              <GaugeDial label="Sales & Mktg" value={vision.salesScore} />
              <GaugeDial label="Geo & Locs" value={vision.geoScore} />
              <GaugeDial label="Offerings" value={vision.offeringsScore} />
              <GaugeDial label="Impact" value={vision.impactScore} />
            </div>
          </CardContent>
        </Card>

        <Card className="rounded-2xl">
          <CardHeader>
            <CardTitle>Budget vs Actual — High Level</CardTitle>
          </CardHeader>
          <CardContent>
            <BarBudget plan={budget.plan} actual={budget.actual} />
          </CardContent>
        </Card>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          <Card className="rounded-2xl">
            <CardHeader><CardTitle>Initiatives</CardTitle></CardHeader>
            <CardContent>
              <div className="space-y-3">
                {vision.initiativesSpectrum.map((row) => (
                  <div key={row.label} className="space-y-1">
                    <div className="text-sm text-muted-foreground">{row.label}</div>
                    <div className="h-2 bg-neutral-200 dark:bg-neutral-800 rounded-full overflow-hidden">
                      <div className="h-full bg-amber-500" style={{ width: `${row.fill}%` }} />
                    </div>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
          <Card className="rounded-2xl">
            <CardHeader><CardTitle>Projects</CardTitle></CardHeader>
            <CardContent>
              <div className="space-y-3">
                {vision.projectsSpectrum.map((row) => (
                  <div key={row.label} className="space-y-1">
                    <div className="text-sm text-muted-foreground">{row.label}</div>
                    <div className="h-2 bg-neutral-200 dark:bg-neutral-800 rounded-full overflow-hidden">
                      <div className="h-full bg-emerald-500" style={{ width: `${row.fill}%` }} />
                    </div>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        </div>
      </div>

      <JournalPanel className="xl:col-span-1" />
    </div>
  );
}