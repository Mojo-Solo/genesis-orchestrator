"use client";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ProjectStatusTable } from "@/components/tables/ProjectStatusTable";

export default function ProjectsPage() {
  return (
    <Card className="rounded-2xl">
      <CardHeader>
        <CardTitle>Strategic Project Status — 2025</CardTitle>
      </CardHeader>
      <CardContent>
        <ProjectStatusTable />
      </CardContent>
    </Card>
  );
}