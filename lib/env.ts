import { z } from "zod";

const Env = z.object({
  OPENAI_API_KEY: z.string().min(10),
  SUPABASE_URL: z.string().url(),
  SUPABASE_SERVICE_ROLE_KEY: z.string().min(10).optional(),
  OPENAI_ORG_ID: z.string().optional(),
  OPENAI_PROJECT_ID: z.string().optional(),
  CONNECTOR_TOKEN: z.string().optional(),
  VECTOR_BACKEND: z.enum(["pgvector","pinecone"]).default("pgvector"),
  PINECONE_API_KEY: z.string().optional(),
  PINECONE_INDEX: z.string().optional()
});

export const env = Env.parse({
  OPENAI_API_KEY: process.env.OPENAI_API_KEY,
  SUPABASE_URL: process.env.SUPABASE_URL,
  SUPABASE_SERVICE_ROLE_KEY: process.env.SUPABASE_SERVICE_ROLE_KEY,
  OPENAI_ORG_ID: process.env.OPENAI_ORG_ID,
  OPENAI_PROJECT_ID: process.env.OPENAI_PROJECT_ID,
  CONNECTOR_TOKEN: process.env.CONNECTOR_TOKEN,
  VECTOR_BACKEND: process.env.VECTOR_BACKEND ?? "pgvector",
  PINECONE_API_KEY: process.env.PINECONE_API_KEY,
  PINECONE_INDEX: process.env.PINECONE_INDEX
});