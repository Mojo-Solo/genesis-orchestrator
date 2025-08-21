"use client";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Button } from "@/components/ui/button";
import { useState } from "react";
import { journalSummarize } from "@/lib/ai";

export function JournalPanel({ className = "" }: { className?: string }) {
  const [prompt, setPrompt] = useState("How do I …");
  const [summary, setSummary] = useState("");
  return (
    <Card className={`rounded-2xl ${className}`}>
      <CardHeader><CardTitle>Cothink Journal</CardTitle></CardHeader>
      <CardContent className="space-y-3">
        <Input value={prompt} onChange={(e) => setPrompt(e.target.value)} />
        <Button onClick={async () => setSummary(await journalSummarize(prompt, []))}>Summarize</Button>
        <Textarea className="min-h-[420px]" value={summary} onChange={(e)=>setSummary(e.target.value)} />
      </CardContent>
    </Card>
  );
}