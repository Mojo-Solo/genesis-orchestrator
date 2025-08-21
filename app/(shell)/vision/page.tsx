"use client";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Textarea } from "@/components/ui/textarea";
import { Button } from "@/components/ui/button";
import { useAppStore } from "@/lib/store";

const fields = [
  { key: "people", label: "PEOPLE" },
  { key: "sales", label: "SALES & MARKETING" },
  { key: "geo", label: "GEOGRAPHY / LOCATIONS" },
  { key: "offerings", label: "OFFERINGS" },
  { key: "impact", label: "IMPACT" },
] as const;

export default function VisionPage() {
  const vision = useAppStore((s) => s.vision);
  const setVision = useAppStore((s) => s.setVision);
  return (
    <div className="space-y-6">
      {fields.map((f) => (
        <Card key={f.key} className="rounded-2xl">
          <CardHeader><CardTitle>{f.label}</CardTitle></CardHeader>
          <CardContent className="space-y-3">
            <Textarea
              className="min-h-[160px]"
              value={(vision.sections as any)[f.key] ?? ""}
              onChange={(e) => setVision(f.key as any, e.target.value)}
            />
            <Button variant="default">Save</Button>
          </CardContent>
        </Card>
      ))}
    </div>
  );
}