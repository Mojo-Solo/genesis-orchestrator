"use client";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { Card, CardHeader, CardTitle, CardContent } from "@/components/ui/card";
import { InitiativeCard } from "@/components/plan/InitiativeCard";
import { useAppStore } from "@/lib/store";
import { ProjectComposer } from "@/components/plan/ProjectComposer";

export default function StrategicPlanPage() {
  const initiatives = useAppStore((s) => s.initiatives);
  return (
    <Tabs defaultValue="initiatives">
      <TabsList className="mb-6"><TabsTrigger value="initiatives">INITIATIVES</TabsTrigger><TabsTrigger value="projects">PROJECTS</TabsTrigger></TabsList>
      <TabsContent value="initiatives">
        <Card className="rounded-2xl">
          <CardHeader><CardTitle>Approved Initiatives</CardTitle></CardHeader>
          <CardContent className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            {initiatives.map((it) => (<InitiativeCard key={it.id} initiativeId={it.id} />))}
          </CardContent>
        </Card>
      </TabsContent>
      <TabsContent value="projects">
        <ProjectComposer />
      </TabsContent>
    </Tabs>
  );
}