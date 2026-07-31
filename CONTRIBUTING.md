# Contributing to Frogman

Thanks for thinking about contributing. Frogman is open source (AGPLv3+) and built to be extended — the tool surface lives in `Tools/*.php` and is meant to grow.

This doc covers what Frogman is allowed to do, what it isn't, and how to ship a change. If you just want the per-tool mechanics, [DEVELOPMENT.md](./DEVELOPMENT.md) in the repo root is the working contributor doc.

## Read these first

- [README.md](./README.md) — what Frogman is and how it installs.
- [DEVELOPMENT.md](./DEVELOPMENT.md) — the hard rules and the mechanics of adding a tool.

## What Frogman is allowed to do

Frogman talks to FreePBX through FreePBX's documented public surfaces. Tools route through them in this order — pick the highest one that does the job:

1. **BMO** (`\FreePBX::ModuleName()->method()`) — first choice for managing any FreePBX object (extensions, trunks, routes, voicemail, etc.). In-process, runs in the same PHP the admin UI runs, and the method signatures are the most stable contract FreePBX offers. Bonus: it hooks FreePBX's own permission and audit wiring, so we get those for free.
2. **AMI** (`\FreePBX::astman`) — for live Asterisk runtime state BMO doesn't surface: active channels, qualify, NOTIFY-driven actions, anything that lives in the running PBX rather than the config DB.
3. **GraphQL named operations** — when crossing module boundaries is required and no single BMO exposes the operation cleanly. Use named ops, not ad-hoc queries.
4. **Direct DB reads** — only when no BMO method returns the data efficiently (bulk inventory, reporting joins). Reads only. Never writes.
5. **fwconsole** — system ops only (chown, reload, module admin), wrapped through a fixed allowlist.

Frogman also owns a small set of its own tables (prefixed `oc_*`) for audit log, sessions, API tokens, etc. Those we control end-to-end.

## What Frogman doesn't do

These are non-negotiable. They're the walls.

- **No edits to other modules' code.** Frogman ships entirely in `/var/www/html/admin/modules/frogman/`. We don't patch core, we don't modify other modules' files, we don't drop monkey-patches in shared paths.
- **No direct writes to other modules' DB tables.** Reads are a last resort. Writes always go through BMO or GraphQL.
- **No arbitrary code execution.** The tool surface is a fixed allowlist of methods. No user-supplied PHP, SQL, or shell.
- **No bypassing FreePBX's audit/permission wiring.** If a path exists through BMO, use it — even if a direct DB query would be shorter.
- **No wrapping commercial-module internals without per-module signoff.** See below.

## Why we live in the walls

These rules look strict on first read. They exist because every one of them was paid for.

**Survival across FreePBX updates.** BMO method signatures and GraphQL schemas are the stable contract FreePBX offers. Internal class implementations, table layouts, and private methods are not. The instant we reach around a wall — direct DB writes, file patches, reflection on private methods — we break the next time someone updates FreePBX. Frogman becomes a tax on the admin instead of a tool.

**Module hygiene.** Frogman is *a FreePBX module*, not a special agent that lives above FreePBX. It plays by the same rules every other module plays by. The instant it doesn't, it becomes the rogue module that breaks other modules' invariants. That forfeits the right to ship it on real systems.

**Audit and permissions for free.** BMO already hooks FreePBX's permission system and its own audit trail. Going through BMO means Frogman's audit log *adds* visibility on top of FreePBX's. Bypassing BMO means re-implementing what's already there, badly, for every tool.

**Customer trust.** Every PBX Frogman touches is in production. Staying inside the walls bounds the blast radius to what FreePBX already audits. If something goes wrong inside BMO, it's a known failure mode FreePBX handles. If something goes wrong because we patched a private class, it's our problem in a way the user can't reason about.

**Commercial-module IP boundary.** Commercial FreePBX modules, Sangoma's and any third-party vendor's, ship as encoded or licensed surface with different guarantees than open-source modules. Wrapping that surface in agent-callable tools is out of scope for Frogman. See the next section.

## The one principled stretch

Direct DB reads of other modules' tables, when no BMO method returns the data efficiently. The rule allows it — *reads only*, never writes. Every time we use it, we should be able to answer the question "is there a BMO method I missed?" with confidence. If the answer is hand-wavy, the BMO method probably exists and we haven't read the class file carefully enough.

## Commercial modules

Frogman integrates with the open-source FreePBX framework only. Tools that touch commercial modules, Sangoma's (Endpoint Manager, DPMA, Sangoma Connect, Sangoma Talk, etc.) or any third-party vendor's, are out of scope. There isn't a per-module signoff path.

Keeping the tool surface inside the open-source boundary means Frogman lines up with what any FreePBX install has out of the box, without commercial dependencies. It also bounds the maintenance surface to code we can read and reason about, and keeps commercial-module workflows out of an AI-agent tool catalog we ship for anyone to run.

If you have an idea that hinges on a commercial-module surface, open an issue anyway. Often there's an open-source path to the same outcome.

## How to contribute

1. **Open an issue first for new tools or behavior changes.** Typo fixes, doc tweaks, and obvious bug fixes can go direct as PRs. For anything that adds a tool or changes how a tool routes, an issue first is faster than reworking a PR — we'll sanity-check routing, naming, and scope before code lands.
2. **Fork the repo.** Standard fork-and-PR flow.
3. **Branch from `main`.** One feature per branch.
4. **Test on a real FreePBX 17 box.** See the [README](./README.md#installation) for manual-install steps. Tools that look right in a code review still surprise on a real system.
5. **Open a PR against `main`.** Include the test commands you ran (`fwconsole frogman:tool <name> '{}'` is enough for read tools; write tools need a dry-run output too). Don't bump version numbers in PRs — versions get bumped at release time.

## Commit messages

Keep commit trailers to accountable humans. If you used Claude, Copilot, or any other AI assistant while writing the change, that's fine, but please don't add `Co-Authored-By: Claude ...`, `Claude-Session:` URLs, or "🤖 Generated with ..." lines to your commit messages. Mention tool use in the PR description if you want the transparency; the commit graph itself stays humans-only.

`Co-Authored-By:` is git's convention for people who can answer for the code they touched. AI assistants don't fit that shape, so we keep them out of the trailer to keep the accountability trail coherent.

## Adding a new tool

Mechanics live in [DEVELOPMENT.md](./DEVELOPMENT.md). The short version:

- Create `Tools/MyTool.php` extending `AbstractTool`.
- Implement `name()`, `description()`, `validate()`, `requiredPermission()`, `execute()`.
- It auto-registers on next load.
- If the tool should be chat-callable, add a regex anchor in `ChatParser::parse()` and a canonical bracket-placeholder form in `ChatParser::helpText()` so typeahead picks it up. Run `fwconsole frogman:tool fm_lint_typeahead '{}'` to verify there are no gaps.

### Naming — brand/vendor scope must be explicit

A generic name (`fm_list_phones`, `fm_diagnose_phone`) must work across every brand of phone the system supports — Sangoma, Yealink, Polycom, Cisco, Grandstream, Algo, etc. If a tool only works on one vendor or protocol, the vendor goes in the name and in the chat alias.

- Cross-brand (works regardless of vendor): `fm_list_phones`, `fm_phone_rebuild_config`. Chat: `list phones`, `rebuild phone configs`.
- Vendor-specific: `fm_list_<vendor>_phones`, `fm_diagnose_<vendor>_phone`. Chat: `list <vendor> phones`, `diagnose <vendor> 1005`.

Decision rule: ask "does this work for a Yealink phone too?" If no → vendor in the name.

## What we probably won't take

- Tools that monkey-patch other modules or FreePBX core.
- Tools that write directly to another module's tables.
- Tools that wrap commercial-module internals.
- Tools that execute user-supplied PHP, SQL, or shell.
- Tools that duplicate existing ones with slightly different parameters — extend the existing tool instead.
- Tools that don't earn their slot in the catalog. If the workflow can be expressed as a chat phrase routed to an existing tool, that's almost always better than a new tool.

## Questions

Open a GitHub issue or jump into the conversation on the FreePBX forum's AI section. Friendly fire welcome — we'd rather have the routing argument before the code than after.

🐸 Tango handles complaints.
