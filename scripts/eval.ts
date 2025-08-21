#!/usr/bin/env tsx
import "dotenv/config";
import fs from "node:fs";
import readline from "node:readline";
import { embed } from "../lib/openai";
import { env } from "../lib/env";
import { rpcMatch } from "../lib/db";
import { pineconeQuery } from "../lib/vector/pinecone";

type Q = { query: string; relevant_ids: string[] };

function metrics(ranked: string[], gold: Set<string>) {
  // recall@k for k in [1,3,5]
  const ks = [1,3,5];
  const recall = ks.map(k => ranked.slice(0,k).some(id => gold.has(id)) ? 1 : 0);
  // MRR
  let rr = 0;
  for (let i = 0; i < ranked.length; i++) if (gold.has(ranked[i])) { rr = 1/(i+1); break; }
  // nDCG@k (binary gain)
  const ndcg = ks.map(k => {
    let dcg = 0; for (let i = 0; i < k && i < ranked.length; i++) if (gold.has(ranked[i])) dcg += 1/Math.log2(i+2);
    // ideal DCG (at least one relevant)
    const idcg = 1; // with binary & single-hit, idcg@k = 1
    return dcg / idcg;
  });
  return { recall, mrr: rr, ndcg };
}

async function searchIds(qv: number[], k: number) {
  if (env.VECTOR_BACKEND === "pinecone") {
    const hits = await pineconeQuery(qv, k);
    return hits.map(h => String(h.id));
  } else {
    const hits = await rpcMatch(qv, k, 1.0);
    return hits.map(h => String(h.id));
  }
}

async function main() {
  const file = process.argv[2] ?? "eval/queries.jsonl";
  const rl = readline.createInterface({ input: fs.createReadStream(file), crlfDelay: Infinity });
  const rows: Q[] = [];
  for await (const line of rl) if (line.trim()) rows.push(JSON.parse(line));
  let agg = { r1: 0, r3: 0, r5: 0, mrr: 0, ndcg1: 0, ndcg3: 0, ndcg5: 0 };

  for (const row of rows) {
    const [qv] = await embed([row.query]);
    const ids = await searchIds(qv, 5);
    const g = new Set(row.relevant_ids.map(String));
    const { recall, mrr, ndcg } = metrics(ids, g);
    agg.r1 += recall[0]; agg.r3 += recall[1]; agg.r5 += recall[2];
    agg.mrr += mrr; agg.ndcg1 += ndcg[0]; agg.ndcg3 += ndcg[1]; agg.ndcg5 += ndcg[2];
    console.log(`Q: ${row.query}\n  top5: [${ids.join(", ")}]\n  R@1=${recall[0]} R@3=${recall[1]} R@5=${recall[2]} MRR=${mrr.toFixed(3)} nDCG@5=${ndcg[2].toFixed(3)}\n`);
  }
  const n = rows.length || 1;
  console.log("=== Aggregate ===");
  console.log(`R@1=${(agg.r1/n).toFixed(3)} R@3=${(agg.r3/n).toFixed(3)} R@5=${(agg.r5/n).toFixed(3)}  MRR=${(agg.mrr/n).toFixed(3)}  nDCG@1=${(agg.ndcg1/n).toFixed(3)} nDCG@3=${(agg.ndcg3/n).toFixed(3)} nDCG@5=${(agg.ndcg5/n).toFixed(3)}`);
}

main().catch(e => { console.error(e); process.exit(1); });