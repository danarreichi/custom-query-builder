#!/usr/bin/env node
// End-to-end smoke test: spawns server.js as a real child process over stdio,
// performs the MCP handshake via the official Client SDK, and calls every tool.
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StdioClientTransport } from "@modelcontextprotocol/sdk/client/stdio.js";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const __dirname = dirname(fileURLToPath(import.meta.url));
const serverPath = join(__dirname, "..", "server.js");

let failures = 0;
function check(label, cond) {
  console.log((cond ? "OK  " : "FAIL") + " - " + label);
  if (!cond) failures++;
}

function getText(result) {
  return JSON.parse(result.content[0].text);
}

const transport = new StdioClientTransport({ command: process.execPath, args: [serverPath] });
const client = new Client({ name: "smoke-test-client", version: "1.0.0" });

await client.connect(transport);

const tools = await client.listTools();
check("server exposes 4 tools", tools.tools.length === 4);
check("tool names include get_method", tools.tools.some((t) => t.name === "get_method"));

const categories = getText(await client.callTool({ name: "list_categories", arguments: {} }));
check("list_categories returns 14 categories", categories.length === 14);
check("categories have method_count", typeof categories[0].method_count === "number");

const allMethods = getText(await client.callTool({ name: "list_methods", arguments: {} }));
check("list_methods (no filter) returns 99 methods", allMethods.length === 99);

const paginationMethods = getText(await client.callTool({ name: "list_methods", arguments: { category: "pagination" } }));
check("list_methods(category=pagination) returns 3", paginationMethods.length === 3);
check("pagination methods include paginate", paginationMethods.some((m) => m.name === "paginate"));

const badCategory = getText(await client.callTool({ name: "list_methods", arguments: { category: "nonexistent" } }));
check("list_methods with bad category returns an error + valid_categories", !!badCategory.error && Array.isArray(badCategory.valid_categories));

const paginateDoc = getText(await client.callTool({ name: "get_method", arguments: { name: "paginate" } }));
check("get_method(paginate) returns full signature", paginateDoc.signature === "paginate($per_page = 15, $page = 1)");
check("get_method(paginate) has examples", Array.isArray(paginateDoc.examples) && paginateDoc.examples.length > 0);
check("get_method(paginate) has parameters", paginateDoc.parameters.length === 2);

const missingMethod = getText(await client.callTool({ name: "get_method", arguments: { name: "pagiante" } }));
check("get_method with typo suggests did_you_mean", Array.isArray(missingMethod.did_you_mean) && missingMethod.did_you_mean.includes("paginate"));

const searchResults = getText(await client.callTool({ name: "search_methods", arguments: { query: "eager load" } }));
check("search_methods('eager load') finds with_one/with_many", searchResults.some((m) => m.name === "with_one") && searchResults.some((m) => m.name === "with_many"));

const searchInjection = getText(await client.callTool({ name: "search_methods", arguments: { query: "sql injection" } }));
check(
  "search_methods handles multi-word query without crashing",
  Array.isArray(searchInjection) || typeof searchInjection.message === "string"
);

const searchNoMatch = getText(await client.callTool({ name: "search_methods", arguments: { query: "xyznonexistentterm" } }));
check("search_methods with no matches returns a message, not an error", typeof searchNoMatch.message === "string");

await client.close();

console.log("");
if (failures === 0) {
  console.log(`All checks passed (${tools.tools.length} tools verified).`);
  process.exit(0);
} else {
  console.log(`${failures} check(s) FAILED.`);
  process.exit(1);
}
