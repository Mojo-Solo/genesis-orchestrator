// lib/openai.ts
import OpenAI from "openai";
import { env } from "@/lib/env";

export const openai = new OpenAI({
  apiKey: env.OPENAI_API_KEY,
  organization: env.OPENAI_ORG_ID,
  project: env.OPENAI_PROJECT_ID,
});

export async function embed(texts: string[]): Promise<number[][]> {
  const response = await openai.embeddings.create({
    model: "text-embedding-3-small",
    input: texts,
  });
  
  return response.data.map(item => item.embedding);
}