import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StdioClientTransport } from "@modelcontextprotocol/sdk/client/stdio.js";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const __dirname = dirname(fileURLToPath(import.meta.url));
const transport = new StdioClientTransport({ command: process.execPath, args: [join(__dirname, "..", "server.js")] });
const client = new Client({ name: "manual-check", version: "1.0.0" });
await client.connect(transport);

function getText(r) { return JSON.parse(r.content[0].text); }

for (const q of ["count related rows", "prevent sql injection", "how do I paginate", "next page url", "load relations"]) {
  const r = getText(await client.callTool({ name: "search_methods", arguments: { query: q } }));
  console.log(`\n=== "${q}" ===`);
  if (Array.isArray(r)) r.slice(0, 5).forEach((m) => console.log(`  ${m.name} — ${m.description.slice(0, 70)}`));
  else console.log("  " + r.message);
}

await client.close();
