"use client";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from "@/components/ui/select";
import { useState } from "react";
import { useAppStore } from "@/lib/store";

export function ProjectComposer() {
  const initiatives = useAppStore((s) => s.initiatives);
  const addProject = useAppStore((s) => s.addProject);
  const [year, setYear] = useState(2025);
  const [quarter, setQuarter] = useState<"Q1"|"Q2"|"Q3"|"Q4">("Q1");
  const [owner, setOwner] = useState("Kyle");
  const [title, setTitle] = useState("");
  const [initiativeId, setInitiativeId] = useState(initiatives[0]?.id);

  return (
    <Card className="rounded-2xl">
      <CardHeader><CardTitle>Approved Projects</CardTitle></CardHeader>
      <CardContent className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        <div className="grid gap-2"><label>YEAR</label><Input type="number" value={year} onChange={(e)=>setYear(Number(e.target.value))} /></div>
        <div className="grid gap-2"><label>Quarter</label>
          <Select value={quarter} onValueChange={(v:any)=>setQuarter(v)}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>{["Q1","Q2","Q3","Q4"].map(q=> <SelectItem key={q} value={q}>{q}</SelectItem>)}</SelectContent>
          </Select>
        </div>
        <div className="grid gap-2"><label>Initiative</label>
          <Select value={initiativeId} onValueChange={(v)=>setInitiativeId(v)}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>{initiatives.map(i=><SelectItem key={i.id} value={i.id}>{i.title || `Initiative ${i.idx}`}</SelectItem>)}</SelectContent>
          </Select>
        </div>
        <div className="grid gap-2"><label>Owner</label><Input value={owner} onChange={(e)=>setOwner(e.target.value)} /></div>
        <div className="md:col-span-2 xl:col-span-4 grid gap-2"><label>PROJECT TITLE — Draft</label><Input value={title} onChange={(e)=>setTitle(e.target.value)} /></div>
        <div className="xl:col-span-4"><Button onClick={()=> addProject({ year, quarter, initiativeId: initiativeId!, owner, title })}>Accept</Button></div>
      </CardContent>
    </Card>
  );
}