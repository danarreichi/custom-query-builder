#!/usr/bin/env node
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const __dirname = dirname(fileURLToPath(import.meta.url));
const REFERENCE_PATH = join(__dirname, "docs", "api-reference.json");

/** @type {{library: string, version: string, categories: Array, methods: Array}} */
const reference = JSON.parse(readFileSync(REFERENCE_PATH, "utf-8"));

const methodsByName = new Map(reference.methods.map((m) => [m.name, m]));
const categoriesById = new Map(reference.categories.map((c) => [c.id, c]));

function summarize(method) {
  return {
    name: method.name,
    category: method.category,
    signature: method.signature,
    description: method.description,
  };
}

function textResult(payload) {
  return {
    content: [{ type: "text", text: JSON.stringify(payload, null, 2) }],
  };
}

/** Standard Levenshtein edit distance, used for typo-tolerant "did you mean" matching. */
function levenshtein(a, b) {
  const dp = Array.from({ length: a.length + 1 }, (_, i) => [i, ...Array(b.length).fill(0)]);
  for (let j = 0; j <= b.length; j++) dp[0][j] = j;
  for (let i = 1; i <= a.length; i++) {
    for (let j = 1; j <= b.length; j++) {
      dp[i][j] =
        a[i - 1] === b[j - 1]
          ? dp[i - 1][j - 1]
          : 1 + Math.min(dp[i - 1][j - 1], dp[i - 1][j], dp[i][j - 1]);
    }
  }
  return dp[a.length][b.length];
}

const server = new McpServer({
  name: "custom-query-builder-docs",
  version: reference.version || "1.0.0",
  description:
    "Documentation/reference server for the CustomQueryBuilder PHP library (a CodeIgniter 3 query builder extension). Read-only: does not connect to any database or execute any query — every tool here only reads from a bundled API reference file.",
});

server.registerTool(
  "list_categories",
  {
    title: "List documentation categories",
    description:
      "List every feature category in CustomQueryBuilder's API reference (e.g. eager loading, aggregates, pagination, security). Use this first to see how the API is organized, then call list_methods with a category id to drill in.",
    inputSchema: {},
  },
  async () => {
    return textResult(
      reference.categories.map((c) => ({
        id: c.id,
        name: c.name,
        description: c.description,
        method_count: reference.methods.filter((m) => m.category === c.id).length,
      }))
    );
  }
);

server.registerTool(
  "list_methods",
  {
    title: "List CustomQueryBuilder methods",
    description:
      "List CustomQueryBuilder public methods with a one-line signature and description each. Optionally filter by category id (see list_categories). Call get_method with a specific name for full details (parameters, examples, notes).",
    inputSchema: {
      category: z
        .string()
        .optional()
        .describe("Category id to filter by (see list_categories for valid ids). Omit to list every method."),
    },
  },
  async ({ category }) => {
    let methods = reference.methods;
    if (category) {
      if (!categoriesById.has(category)) {
        return textResult({
          error: `Unknown category "${category}".`,
          valid_categories: reference.categories.map((c) => c.id),
        });
      }
      methods = methods.filter((m) => m.category === category);
    }
    return textResult(methods.map(summarize));
  }
);

server.registerTool(
  "get_method",
  {
    title: "Get full documentation for one method",
    description:
      "Get the complete documentation for one CustomQueryBuilder method by exact name: full signature, parameter descriptions, return value, usage examples, behavioral notes, and related methods (see_also). Use list_methods or search_methods first if you don't know the exact method name.",
    inputSchema: {
      name: z.string().describe('Exact method name, e.g. "with_many", "where_has", "paginate".'),
    },
  },
  async ({ name }) => {
    const method = methodsByName.get(name);
    if (!method) {
      // Close-match fallback so a slightly wrong name (typo, partial name,
      // wrong case) still helps: substring match OR small edit distance.
      const lower = name.toLowerCase();
      const maxDistance = lower.length <= 4 ? 1 : 2;
      const close = reference.methods
        .filter((m) => {
          const mLower = m.name.toLowerCase();
          return (
            mLower.includes(lower) ||
            lower.includes(mLower) ||
            levenshtein(lower, mLower) <= maxDistance
          );
        })
        .map((m) => m.name);
      return textResult({
        error: `No method named "${name}".`,
        did_you_mean: close.slice(0, 8),
      });
    }
    return textResult(method);
  }
);

server.registerTool(
  "search_methods",
  {
    title: "Search CustomQueryBuilder documentation",
    description:
      "Full-text search across every method's name, description, parameters, and notes. Use this when you know what you want to do (e.g. \"pagination\", \"prevent sql injection\", \"count related rows\") but not the exact method name.",
    inputSchema: {
      query: z.string().describe("Free-text search query, e.g. \"eager load\" or \"exists subquery\"."),
    },
  },
  async ({ query }) => {
    // Word-split, not whole-phrase: a query like "eager load" should still
    // match a description that says "eager-load" or has the words apart —
    // documentation prose rarely repeats the user's exact phrasing verbatim.
    // Stopwords + a length floor keep short filler words ("how", "do", "i")
    // from matching individual letters all over every description.
    const STOPWORDS = new Set([
      "how", "do", "does", "did", "i", "is", "are", "the", "a", "an", "to", "of",
      "in", "on", "for", "and", "or", "with", "can", "should", "would", "it",
      "this", "that", "me", "my", "you", "your",
    ]);
    const words = query
      .toLowerCase()
      .split(/\s+/)
      .filter((w) => w.length >= 3 && !STOPWORDS.has(w))
      .map((w) => w.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"));

    const scored = reference.methods
      .map((m) => {
        const nameLower = m.name.toLowerCase();
        const haystack = [
          m.name,
          m.description,
          ...(m.notes || []),
          ...(m.parameters || []).map((p) => `${p.name} ${p.description}`),
        ]
          .join(" ")
          .toLowerCase();

        let score = 0;
        for (const w of words) {
          // Also try a naive singular form (strip a trailing "s") so plural
          // query words ("conditions", "aggregates") still match descriptions
          // written in the singular — cheap stemming, not a real linguistic
          // stemmer, so it won't bridge every case (e.g. "relations" vs.
          // "related"), but it covers the common plural/singular mismatch.
          const variants = w.length > 4 && w.endsWith("s") ? [w, w.slice(0, -1)] : [w];
          for (const v of variants) {
            if (nameLower.includes(v)) score += 10;
            const hits = (haystack.match(new RegExp(v, "g")) || []).length;
            score += hits;
          }
        }
        return { method: m, score };
      })
      .filter((r) => r.score > 0)
      .sort((a, b) => b.score - a.score);

    if (scored.length === 0) {
      return textResult({
        message: `No matches for "${query}". Try list_categories or list_methods to browse instead.`,
      });
    }
    return textResult(scored.slice(0, 15).map((r) => summarize(r.method)));
  }
);

const transport = new StdioServerTransport();
await server.connect(transport);
