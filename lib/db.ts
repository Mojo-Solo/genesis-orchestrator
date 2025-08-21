// lib/db.ts
import { createClient } from "@supabase/supabase-js";
import { env } from "@/lib/env";

export const supabase = createClient(
  env.SUPABASE_URL,
  env.SUPABASE_SERVICE_ROLE_KEY ?? ""
);

export type Document = {
  id: number;
  title: string;
  uri: string;
  created_at: string;
};

export type Chunk = {
  id: number;
  doc_id: number;
  idx: number;
  content: string;
  embedding: number[] | null;
  title?: string;
  uri?: string;
  distance?: number;
};

export async function insertDocument(title: string, uri: string): Promise<number> {
  const { data, error } = await supabase
    .from("documents")
    .insert({ title, uri })
    .select("id")
    .single();
  if (error) throw error;
  return data.id;
}

export async function upsertChunk(docId: number, idx: number, content: string, embedding: number[]) {
  const { error } = await supabase
    .from("chunks")
    .upsert({ doc_id: docId, idx, content, embedding }, { onConflict: "doc_id,idx" });
  if (error) throw error;
}

export async function insertChunkMetadata(docId: number, idx: number, content: string) {
  const { data, error } = await supabase
    .from("chunks")
    .insert({ doc_id: docId, idx, content })   // embedding may be null if Pinecone
    .select("id")
    .single();
  if (error) throw error;
  return data.id as number;
}

export async function updateChunkEmbedding(id: number, embedding: number[]) {
  const { error } = await supabase.from("chunks").update({ embedding }).eq("id", id);
  if (error) throw error;
}

export async function rpcMatch(queryEmbedding: number[], matchCount: number, matchThreshold: number): Promise<Chunk[]> {
  const { data, error } = await supabase.rpc("match_chunks", {
    query_embedding: queryEmbedding,
    match_count: matchCount,
    match_threshold: matchThreshold
  });
  if (error) throw error;
  return data;
}

export async function fetchChunkById(id: number): Promise<Chunk> {
  const { data, error } = await supabase
    .from("chunks")
    .select("*")
    .eq("id", id)
    .single();
  if (error) throw error;
  return data;
}