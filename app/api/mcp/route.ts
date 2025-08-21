// app/api/mcp/route.ts
import { NextRequest } from "next/server";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import { embed } from "@/lib/openai";
import { rpcMatch, fetchChunkById } from "@/lib/db";
import { env } from "@/lib/env";
import { pineconeQuery } from "@/lib/vector/pinecone";
import { rerankWithLLM } from "@/lib/rerank";

export const runtime = "nodejs";

// Simple bearer token guard
function unauthorized() { return new Response("Unauthorized", { status: 401 }); }
function checkAuth(req: Request) {
  if (!env.CONNECTOR_TOKEN) return null; // disabled
  const got = req.headers.get("x-connector-token") ?? req.headers.get("authorization")?.replace(/^Bearer\s+/i,"");
  if (got !== env.CONNECTOR_TOKEN) return unauthorized();
  return null;
}

// SSE Headers for ChatGPT Research compatibility
const sseHeaders = {
  'Content-Type': 'text/event-stream',
  'Cache-Control': 'no-cache',
  'Connection': 'keep-alive',
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
  'Access-Control-Allow-Headers': 'Content-Type, Authorization, x-connector-token',
};

// Create MCP Server instance
const mcpServer = new McpServer({
  name: "genesis-rag-server",
  version: "1.0.0",
  description: "Genesis RAG system with semantic search and content retrieval"
});

// Register semantic search tool
mcpServer.registerTool(
  "search",
  {
    title: "Semantic Vector Search",
    description: "Semantic vector search over your private corpus.",
    inputSchema: {
      query: z.string().min(2),
      k: z.number().min(1).max(20).default(5)
    }
  },
  async ({ query, k = 5 }) => {
    try {
      const [qv] = await embed([query]);
      let results: Array<{ id: string; title: string; text: string; url: string; score?: number }>;
      
      if (env.VECTOR_BACKEND === "pinecone") {
        const hits = await pineconeQuery(qv, k);
        results = hits.map(h => ({
          id: String(h.id),
          title: String(h.metadata?.title ?? `Doc ${h.metadata?.doc_id} #${h.metadata?.idx}`),
          text: String(h.metadata?.snippet ?? "").slice(0, 400),
          url: String(h.metadata?.uri ?? `internal://doc/${h.metadata?.doc_id}#${h.metadata?.idx}`),
          score: h.score ?? undefined
        }));
      } else {
        const hits = await rpcMatch(qv, k, 0.90);
        results = hits.map(h => ({
          id: String(h.id),
          title: h.title ?? `Doc ${h.doc_id} #${h.idx}`,
          text: h.content.slice(0, 400),
          url: h.uri ?? `internal://doc/${h.doc_id}#${h.idx}`,
          score: h.distance !== undefined ? 1 - Math.min(Math.max(h.distance, 0), 1) : undefined
        }));
      }

      // Optional LLM re‑rank for precision at K
      if (process.env.ENABLE_RERANK === "true") {
        results = await rerankWithLLM(query, results);
      }
      
      return {
        content: [
          {
            type: "text",
            text: JSON.stringify({ results })
          }
        ]
      };
    } catch (error) {
      console.error("Search tool error:", error);
      return {
        content: [
          {
            type: "text",
            text: JSON.stringify({ error: error instanceof Error ? error.message : "Search failed" })
          }
        ],
        isError: true
      };
    }
  }
);

// Register content fetch tool
mcpServer.registerTool(
  "fetch",
  {
    title: "Fetch Content by ID",
    description: "Fetch full chunk content by id.",
    inputSchema: {
      id: z.string().regex(/^\d+$/)
    }
  },
  async ({ id }) => {
    try {
      const row = await fetchChunkById(Number(id));
      const result = {
        id: String(row.id),
        title: row.title ?? `Doc ${row.doc_id} #${row.idx}`,
        text: row.content,
        url: row.uri ?? `internal://doc/${row.doc_id}#${row.idx}`,
        metadata: { doc_id: row.doc_id, chunk_index: row.idx }
      };
      
      return {
        content: [
          {
            type: "text", 
            text: JSON.stringify({ result })
          }
        ]
      };
    } catch (error) {
      console.error("Fetch tool error:", error);
      return {
        content: [
          {
            type: "text",
            text: JSON.stringify({ error: error instanceof Error ? error.message : "Fetch failed" })
          }
        ],
        isError: true
      };
    }
  }
);

// Helper function to handle MCP requests with HTTP transport
async function handleMcpRequest(request: any) {
  // For HTTP transport, we need to handle requests differently than stdio
  try {
    if (request.method === "tools/list") {
      return {
        jsonrpc: "2.0",
        id: request.id,
        result: {
          tools: [
            {
              name: "search",
              description: "Semantic vector search over your private corpus.",
              inputSchema: {
                type: "object",
                properties: {
                  query: { type: "string", minLength: 2 },
                  k: { type: "number", minimum: 1, maximum: 20, default: 5 }
                },
                required: ["query"]
              }
            },
            {
              name: "fetch",
              description: "Fetch full chunk content by id.",
              inputSchema: {
                type: "object",
                properties: {
                  id: { type: "string", pattern: "^\\d+$" }
                },
                required: ["id"]
              }
            }
          ]
        }
      };
    }

    if (request.method === "tools/call") {
      const { name, arguments: args } = request.params;
      
      if (name === "search") {
        const { query, k = 5 } = args;
        const [qv] = await embed([query]);
        let results: Array<{ id: string; title: string; text: string; url: string; score?: number }>;
        
        if (env.VECTOR_BACKEND === "pinecone") {
          const hits = await pineconeQuery(qv, k);
          results = hits.map(h => ({
            id: String(h.id),
            title: String(h.metadata?.title ?? `Doc ${h.metadata?.doc_id} #${h.metadata?.idx}`),
            text: String(h.metadata?.snippet ?? "").slice(0, 400),
            url: String(h.metadata?.uri ?? `internal://doc/${h.metadata?.doc_id}#${h.metadata?.idx}`),
            score: h.score ?? undefined
          }));
        } else {
          const hits = await rpcMatch(qv, k, 0.90);
          results = hits.map(h => ({
            id: String(h.id),
            title: h.title ?? `Doc ${h.doc_id} #${h.idx}`,
            text: h.content.slice(0, 400),
            url: h.uri ?? `internal://doc/${h.doc_id}#${h.idx}`,
            score: h.distance !== undefined ? 1 - Math.min(Math.max(h.distance, 0), 1) : undefined
          }));
        }

        if (process.env.ENABLE_RERANK === "true") {
          results = await rerankWithLLM(query, results);
        }
        
        return {
          jsonrpc: "2.0",
          id: request.id,
          result: {
            content: [
              {
                type: "text",
                text: JSON.stringify({ results })
              }
            ]
          }
        };
      }

      if (name === "fetch") {
        const { id } = args;
        const row = await fetchChunkById(Number(id));
        const result = {
          id: String(row.id),
          title: row.title ?? `Doc ${row.doc_id} #${row.idx}`,
          text: row.content,
          url: row.uri ?? `internal://doc/${row.doc_id}#${row.idx}`,
          metadata: { doc_id: row.doc_id, chunk_index: row.idx }
        };
        
        return {
          jsonrpc: "2.0",
          id: request.id,
          result: {
            content: [
              {
                type: "text", 
                text: JSON.stringify({ result })
              }
            ]
          }
        };
      }

      throw new Error(`Unknown tool: ${name}`);
    }

    throw new Error(`Unknown method: ${request.method}`);
  } catch (error) {
    console.error("MCP request error:", error);
    return {
      jsonrpc: "2.0",
      id: request.id || null,
      error: {
        code: -32603,
        message: error instanceof Error ? error.message : "Internal error"
      }
    };
  }
}

// Legacy REST API compatibility for backward compatibility
export async function GET(req: NextRequest) {
  const res = checkAuth(req);
  if (res) return res;
  
  // Return available tools for REST API clients
  return new Response(JSON.stringify({
    tools: [
      {
        name: "search",
        description: "Semantic vector search over your private corpus.",
        inputSchema: {
          type: "object",
          properties: {
            query: { type: "string", minLength: 2 },
            k: { type: "number", minimum: 1, maximum: 20, default: 5 }
          },
          required: ["query"]
        }
      },
      {
        name: "fetch",
        description: "Fetch full chunk content by id.",
        inputSchema: {
          type: "object",
          properties: {
            id: { type: "string", pattern: "^\\d+$" }
          },
          required: ["id"]
        }
      }
    ]
  }), {
    headers: { "Content-Type": "application/json" }
  });
}

export async function POST(req: NextRequest) {
  const res = checkAuth(req);
  if (res) return res;

  try {
    const body = await req.json();
    
    // Check if this is an MCP JSON-RPC request
    if (body.jsonrpc === "2.0" && body.method) {
      const response = await handleMcpRequest(body);
      
      // For SSE support, check if client accepts text/event-stream
      const acceptHeader = req.headers.get('accept');
      if (acceptHeader?.includes('text/event-stream')) {
        const encoder = new TextEncoder();
        const readable = new ReadableStream({
          start(controller) {
            // Send initial data
            controller.enqueue(encoder.encode(`data: ${JSON.stringify(response)}\n\n`));
            // Send end marker
            controller.enqueue(encoder.encode('data: [DONE]\n\n'));
            controller.close();
          }
        });
        
        return new Response(readable, { headers: sseHeaders });
      }
      
      return new Response(JSON.stringify(response), {
        headers: { "Content-Type": "application/json" }
      });
    }

    // Legacy API support for backward compatibility
    const { tool, query, k = 5, id } = body;

    if (tool === "search") {
      const [qv] = await embed([query]);
      let results: Array<{ id: string; title: string; text: string; url: string; score?: number }>;
      
      if (env.VECTOR_BACKEND === "pinecone") {
        const hits = await pineconeQuery(qv, k);
        results = hits.map(h => ({
          id: String(h.id),
          title: String(h.metadata?.title ?? `Doc ${h.metadata?.doc_id} #${h.metadata?.idx}`),
          text: String(h.metadata?.snippet ?? "").slice(0, 400),
          url: String(h.metadata?.uri ?? `internal://doc/${h.metadata?.doc_id}#${h.metadata?.idx}`),
          score: h.score ?? undefined
        }));
      } else {
        const hits = await rpcMatch(qv, k, 0.90);
        results = hits.map(h => ({
          id: String(h.id),
          title: h.title ?? `Doc ${h.doc_id} #${h.idx}`,
          text: h.content.slice(0, 400),
          url: h.uri ?? `internal://doc/${h.doc_id}#${h.idx}`,
          score: h.distance !== undefined ? 1 - Math.min(Math.max(h.distance, 0), 1) : undefined
        }));
      }

      // Optional LLM re‑rank for precision at K
      if (process.env.ENABLE_RERANK === "true") {
        results = await rerankWithLLM(query, results);
      }
      
      return new Response(JSON.stringify({ results }), {
        headers: { "Content-Type": "application/json" }
      });
    }

    if (tool === "fetch") {
      const row = await fetchChunkById(Number(id));
      const result = {
        id: String(row.id),
        title: row.title ?? `Doc ${row.doc_id} #${row.idx}`,
        text: row.content,
        url: row.uri ?? `internal://doc/${row.doc_id}#${row.idx}`,
        metadata: { doc_id: row.doc_id, chunk_index: row.idx }
      };
      
      return new Response(JSON.stringify({ result }), {
        headers: { "Content-Type": "application/json" }
      });
    }

    return new Response(JSON.stringify({ error: `Unknown tool: ${tool}` }), {
      status: 400,
      headers: { "Content-Type": "application/json" }
    });

  } catch (error) {
    console.error("MCP API Error:", error);
    return new Response(JSON.stringify({ error: "Internal server error" }), {
      status: 500,
      headers: { "Content-Type": "application/json" }
    });
  }
}

// OPTIONS handler for CORS preflight
export async function OPTIONS(req: NextRequest) {
  return new Response(null, {
    status: 200,
    headers: {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, Authorization, x-connector-token',
    }
  });
}