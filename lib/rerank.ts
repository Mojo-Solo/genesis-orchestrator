// lib/rerank.ts
import { openai } from "@/lib/openai";

type Hit = { id: string; title: string; text: string; url: string; score?: number };

export async function rerankWithLLM(query: string, candidates: Hit[], model = process.env.RERANK_MODEL ?? "gpt-4o-mini"): Promise<Hit[]> {
  const system = "You re-rank passages for relevance to a user query. Return strictly JSON: [{id:string, score:number}] with scores in [0,1].";
  const user = JSON.stringify({ query, candidates: candidates.map(c => ({ id: c.id, title: c.title, text: c.text })) });

  const resp = await openai.chat.completions.create({
    model,
    temperature: 0,
    response_format: { type: "json_object" },
    messages: [
      { role: "system", content: system },
      { role: "user", content: user }
    ]
  });

  const content = resp.choices[0]?.message?.content ?? "{}";
  let scores: Array<{ id: string; score: number }> = [];
  try { scores = JSON.parse(content).map ?? JSON.parse(content).results ?? JSON.parse(content); } catch {}

  const byId = new Map(scores.map(s => [s.id, s.score]));
  return [...candidates]
    .map(c => ({ ...c, score: byId.get(c.id) ?? c.score ?? 0 }))
    .sort((a, b) => (b.score ?? 0) - (a.score ?? 0));
}