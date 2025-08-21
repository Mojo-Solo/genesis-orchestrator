// lib/chunker.ts
export type ChunkerOpts = { max: number; overlap: number };
const DEFAULT: ChunkerOpts = { max: 1400, overlap: 200 };

// naive sentence split + heading preservation
export function splitSmart(raw: string, opts: ChunkerOpts = DEFAULT): string[] {
  const text = raw.replace(/\r/g, "").trim();
  const lines = text.split(/\n+/);
  const blocks: string[] = [];
  let cur: string[] = [];

  const push = () => {
    if (!cur.length) return;
    blocks.push(cur.join("\n").trim());
    cur = [];
  };

  for (const line of lines) {
    const isHeading = /^(#+\s|\d+\.\s+|[A-Z][\w ]+:)$/.test(line) || line.length < 80 && /^[A-Z0-9][A-Za-z0-9 \-:&]+$/.test(line);
    if (isHeading && cur.join("\n").length > 0) push();
    cur.push(line);
  }
  push();

  // sentence-aware reflow into size-constrained chunks
  const chunks: string[] = [];
  const add = (s: string) => {
    if (!s.trim()) return;
    if (!chunks.length) { chunks.push(s); return; }
    const last = chunks[chunks.length - 1];
    if ((last.length + 1 + s.length) <= opts.max) {
      chunks[chunks.length - 1] = `${last}\n${s}`;
    } else {
      // overlap
      const overlap = last.slice(Math.max(0, last.length - opts.overlap));
      chunks.push(overlap + (overlap ? "\n" : "") + s);
    }
  };

  for (const b of blocks) {
    const sents = b.split(/(?<=[\.!?])\s+/);
    for (const s of sents) add(s);
  }
  return chunks.map(c => c.trim()).filter(Boolean);
}