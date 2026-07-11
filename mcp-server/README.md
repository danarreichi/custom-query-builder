# CustomQueryBuilder Docs MCP Server

An [MCP](https://modelcontextprotocol.io) server that exposes CustomQueryBuilder's complete API reference to AI coding assistants (Claude Code, Claude Desktop, or any other MCP-compatible client).

**This is a documentation/reference server only.** It does **not** connect to a database, does **not** execute any query, and does **not** touch your application at all — every tool call just reads from the bundled `docs/api-reference.json` file and returns structured data about the PHP library's methods (signatures, parameters, return types, usage examples, notes). Think of it as "the README, but queryable by an AI assistant instead of requiring it to read the whole file."

## Why this exists

`CustomQueryBuilder`'s API surface is large (99 documented methods across 14 categories). Without this server, an AI assistant helping you write code against this library has to either already know the API from training data (which may be stale or simply not exist, since this is a private/internal library) or read the full `README.md`/`docs/USAGE.md` into context every time. With this server, it can instead ask targeted questions — "what's the signature of `paginate()`?", "what methods handle eager loading?", "how do I filter by a related row count?" — and get back exactly the relevant slice.

## Tools

| Tool | Purpose |
|---|---|
| `list_categories` | List the 14 feature categories (eager loading, aggregates, pagination, etc.), each with its method count. Good first call to understand how the API is organized. |
| `list_methods` | List methods with a one-line signature + description each. Optional `category` filter (use an id from `list_categories`). |
| `get_method` | Full documentation for one method by exact name: signature, every parameter, return type, usage examples, behavioral notes, related methods. If the name doesn't match, returns `did_you_mean` suggestions (typo-tolerant). |
| `search_methods` | Free-text search across every method's name/description/notes/parameters. Use when you know what you want to do but not the exact method name (e.g. `"count related rows"`, `"pagination"`, `"exists subquery"`). |

## Installation

```bash
cd mcp-server
npm install
```

## Running standalone (for testing)

```bash
npm start
```

This starts the server on stdio and blocks, waiting for an MCP client to connect — it's not meant to be run interactively in a terminal by a human. Use the smoke test below to verify it works without needing a real MCP client.

## Verifying it works

```bash
npm test
```

Spawns the server as a real child process, performs the MCP handshake, and calls all 4 tools with a battery of checks (including edge cases: unknown category, typo'd method name, multi-word search, no-match search). All checks should print `OK`.

## Registering with an MCP client

### Claude Code (project-scoped)

Add to `.mcp.json` in your project root (create it if it doesn't exist):

```json
{
  "mcpServers": {
    "custom-query-builder-docs": {
      "command": "node",
      "args": ["mcp-server/server.js"]
    }
  }
}
```

Paths in `args` are resolved relative to wherever Claude Code is launched from — use an absolute path if you invoke Claude Code from outside this repo.

### Claude Desktop

Add to your `claude_desktop_config.json` (find it via Claude Desktop's Settings → Developer → Edit Config):

```json
{
  "mcpServers": {
    "custom-query-builder-docs": {
      "command": "node",
      "args": ["/absolute/path/to/custom-query-builder/mcp-server/server.js"]
    }
  }
}
```

Restart Claude Desktop after editing the config. Absolute paths are required here (Claude Desktop doesn't run from your project directory).

### Any other MCP client

This server speaks standard MCP over stdio — point your client at `node /path/to/mcp-server/server.js` the same way.

## Keeping documentation in sync

`docs/api-reference.json` is the single source of truth — both this MCP server *and* the human-readable `docs/USAGE.md` in the repo root are generated from it. If you add or change a method's documentation:

1. Edit `mcp-server/docs/api-reference.json`.
2. Regenerate the human-readable guide: `node scripts/generate-usage-md.mjs` (run from inside `mcp-server/`).
3. Run `npm test` to make sure the MCP server still serves correctly.

Do not hand-edit `docs/USAGE.md` directly — it's overwritten by the generator script and any manual edits will be lost.

## Project structure

```
mcp-server/
├── server.js                    # MCP server entrypoint (stdio transport, 4 tools)
├── docs/
│   └── api-reference.json       # Single source of truth for all method docs
├── scripts/
│   └── generate-usage-md.mjs    # Regenerates ../docs/USAGE.md from api-reference.json
├── test/
│   ├── smoke-test.js            # npm test — end-to-end tool verification
│   └── manual-check.mjs         # Ad-hoc search-quality spot checks (not part of npm test)
└── package.json
```
