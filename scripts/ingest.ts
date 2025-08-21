#!/usr/bin/env tsx
import "dotenv/config";
import fs from "node:fs/promises";
import path from "node:path";
import pdfParse from "pdf-parse";
import mammoth from "mammoth";
import { insertDocument, upsertChunk, insertChunkMetadata, updateChunkEmbedding } from "../lib/db";
import { embed } from "../lib/openai";
import { pineconeUpsert } from "../lib/vector/pinecone";
import { splitSmart } from "../lib/chunker";
import { env } from "../lib/env";

const DATA_DIR = process.argv[2] ?? "data";

async function loadFile(abs: string) {
  const ext = path.extname(abs).toLowerCase();
  const buf = await fs.readFile(abs);
  if (ext === ".pdf") return (await pdfParse(buf)).text;
  if (ext === ".docx") return (await mammoth.extractRawText({ buffer: buf })).value;
  return buf.toString("utf8");
}

async function main() {
  const entries = await fs.readdir(DATA_DIR, { withFileTypes: true });
  for (const e of entries) {
    if (!e.isFile()) continue;
    const abs = path.join(DATA_DIR, e.name);
    const title = e.name;
    const uri = `internal://${title}`;

    const docId = await insertDocument(title, uri);
    const text = (await loadFile(abs)).replace(/\s+\n/g, "\n").trim();
    const chunks = splitSmart(text, { max: 1400, overlap: 200 });

    const BATCH = 64;
    for (let i = 0; i < chunks.length; i += BATCH) {
      const batch = chunks.slice(i, i + BATCH);
      const vectors = await embed(batch);

      if (env.VECTOR_BACKEND === "pinecone") {
        const metaIds: number[] = [];
        for (let j = 0; j < batch.length; j++) {
          const id = await insertChunkMetadata(docId, i + j, batch[j]);
          metaIds.push(id);
        }
        const items = vectors.map((v, j) => ({
          id: String(metaIds[j]),
          values: v,
          metadata: {
            doc_id: docId, idx: i + j, title, uri,
            snippet: batch[j].slice(0, 400)
          }
        }));
        await pineconeUpsert(items);
      } else {
        for (let j = 0; j < batch.length; j++) {
          await upsertChunk(docId, i + j, batch[j], vectors[j]);
        }
      }
      process.stdout.write(`Ingested ${Math.min(i + BATCH, chunks.length)}/${chunks.length} of ${title}\r`);
    }
    process.stdout.write("\n");
  }
  console.log("✅ Ingestion complete.");
}

main().catch(err => { console.error(err); process.exit(1); });