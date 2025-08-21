// lib/vector/pinecone.ts
import { Pinecone } from "@pinecone-database/pinecone";
import { env } from "@/lib/env";

let index: ReturnType<Pinecone["index"]> | null = null;

function getIndex() {
  if (!env.PINECONE_API_KEY || !env.PINECONE_INDEX) {
    throw new Error("Pinecone not configured.");
  }
  if (!index) {
    const pc = new Pinecone({ apiKey: env.PINECONE_API_KEY });
    index = pc.index(env.PINECONE_INDEX);
  }
  return index!;
}

export async function pineconeUpsert(items: Array<{ id: string; values: number[]; metadata?: Record<string, any> }>) {
  const idx = getIndex();
  await idx.upsert(items);
}

export async function pineconeQuery(vector: number[], topK: number) {
  const idx = getIndex();
  const res = await idx.query({ vector, topK, includeMetadata: true });
  return res.matches ?? [];
}