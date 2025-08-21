"use client";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { useAppStore } from "@/lib/store";
import { smartifyInitiative } from "@/lib/ai";
import { useState } from "react";

export function InitiativeCard({ initiativeId }: { initiativeId: string }) {
  const initiative = useAppStore((s) => s.initiatives.find((i) => i.id === initiativeId)!);
  const update = useAppStore((s) => s.updateInitiative);
  const [draftTitle, setDraftTitle] = useState("");
  const [draftDesc, setDraftDesc] = useState("");
  const [suggestion, setSuggestion] = useState<{ title: string; description: string } | null>(null);

  return (
    <Card className="rounded-2xl">
      <CardHeader>
        <CardTitle>Initiative {initiative.idx}</CardTitle>
        <div className="text-sm text-muted-foreground">{initiative.title || "(no title)"}</div>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="grid grid-cols-1 gap-2">
          <label className="text-sm font-medium">YEAR</label>
          <Input type="number" value={initiative.year} onChange={(e)=>update(initiativeId,{year: Number(e.target.value)})} />
        </div>
        <div className="grid grid-cols-1 gap-2">
          <label className="text-sm font-medium">TITLE — Draft</label>
          <Input value={draftTitle} onChange={(e)=>setDraftTitle(e.target.value)} placeholder="Draft" />
          <Button onClick={async()=>setSuggestion(await smartifyInitiative({ title: draftTitle, description: draftDesc }))}>Submit</Button>
          <div className="grid grid-cols-1 gap-2">
            <label className="text-sm font-medium">Suggestion</label>
            <Textarea readOnly value={suggestion?.title || ""} />
            <Button variant="secondary" onClick={() => suggestion && update(initiativeId, { title: suggestion.title })}>ACCEPT</Button>
          </div>
        </div>
        <div className="grid grid-cols-1 gap-2">
          <label className="text-sm font-medium">DESCRIPTION — Draft</label>
          <Textarea value={draftDesc} onChange={(e)=>setDraftDesc(e.target.value)} placeholder="Draft" />
          <div className="grid grid-cols-1 gap-2">
            <label className="text-sm font-medium">Suggestion</label>
            <Textarea readOnly value={suggestion?.description || ""} />
            <Button variant="secondary" onClick={() => suggestion && update(initiativeId, { description: suggestion.description, approved: true })}>ACCEPT</Button>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}