#!/usr/bin/env node
// Generates ../docs/USAGE.md and the repo-root docs/USAGE.md from api-reference.json
// so the human-readable guide can never drift from the MCP server's data.
import { readFileSync, writeFileSync, mkdirSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const __dirname = dirname(fileURLToPath(import.meta.url));
const reference = JSON.parse(readFileSync(join(__dirname, "..", "docs", "api-reference.json"), "utf-8"));

function slug(s) {
  return s.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/(^-|-$)/g, "");
}

let out = [];
out.push(`# ${reference.library} — Complete API Reference`);
out.push("");
out.push(reference.description);
out.push("");
out.push("> Generated from `mcp-server/docs/api-reference.json` — the same data an AI assistant sees through the companion MCP server (see `mcp-server/README.md`). Do not hand-edit this file; edit the JSON and regenerate with `node mcp-server/scripts/generate-usage-md.mjs`.");
out.push("");
out.push("## Table of Contents");
out.push("");
for (const cat of reference.categories) {
  out.push(`- [${cat.name}](#${slug(cat.name)})`);
}
out.push("");
out.push("---");
out.push("");

for (const cat of reference.categories) {
  const methods = reference.methods.filter((m) => m.category === cat.id);
  out.push(`## ${cat.name}`);
  out.push("");
  out.push(cat.description);
  out.push("");
  for (const m of methods) {
    out.push(`### \`${m.signature}\``);
    out.push("");
    out.push(m.description);
    out.push("");
    if (m.parameters && m.parameters.length) {
      out.push("**Parameters:**");
      out.push("");
      for (const p of m.parameters) {
        const def = p.default !== null && p.default !== undefined ? ` (default: \`${p.default}\`)` : "";
        out.push(`- \`${p.name}\` (${p.type})${def} — ${p.description}`);
      }
      out.push("");
    }
    if (m.returns) {
      out.push(`**Returns:** \`${m.returns.type}\`${m.returns.description ? " — " + m.returns.description : ""}`);
      out.push("");
    }
    if (m.examples && m.examples.length) {
      for (const ex of m.examples) {
        if (ex.description) out.push(`_${ex.description}_`);
        out.push("```php");
        out.push(ex.code);
        out.push("```");
        out.push("");
      }
    }
    if (m.notes && m.notes.length) {
      for (const n of m.notes) {
        out.push(`> **Note:** ${n}`);
      }
      out.push("");
    }
    if (m.see_also && m.see_also.length) {
      out.push(`**See also:** ${m.see_also.map((s) => `\`${s}\``).join(", ")}`);
      out.push("");
    }
  }
  out.push("---");
  out.push("");
}

const markdown = out.join("\n");

mkdirSync(join(__dirname, "..", "..", "docs"), { recursive: true });
writeFileSync(join(__dirname, "..", "..", "docs", "USAGE.md"), markdown);
console.log("Written docs/USAGE.md (" + markdown.length + " bytes, " + reference.methods.length + " methods)");
