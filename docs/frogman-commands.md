# Frogman Command Reference

## Quick Start
- Type `status` for a system dashboard
- Type `help` for the full command list
- Type any number (e.g., `101`) to see extension details
- Use up/down arrows for command history

## Natural Language
Frogman understands variations:
- `make extension 200 for Bob` = `create extension 200 for Bob`
- `show me the phones` = `list extensions`
- `who is Miles` = search for "Miles"
- `what are my trunks` = `list trunks`

## Combo Commands
Chain actions in one command:
- `create extension 1010 and voicemail for John`
- `create extension 1010 for John and forward to 5551234`

## Confirmations
Write operations show a preview first:
- Click ✅ Yes or ❌ No (or type yes/no)
- Destructive actions show ⚠️ warning
- After changes, Frogman offers to apply (reload)

## Clickable Results
Many results are clickable:
- Extension numbers → show details
- Module names → show module status
- Notifications → expand details
- Upgrade buttons → upgrade that module

## Search
```
search Miles        → find across all PBX objects
who is Bob          → same
find Sales          → finds ring groups, extensions, etc.
where is 600        → find by number
```

## Dashboard
```
status              → system overview
```
Shows: calls, extensions, trunks, notifications, uptime, reload status.

## SIP Troubleshooting
```
diagnose ext 101    → full diagnostic
troubleshoot 101    → same
endpoint details 101 → deep PJSIP info
ping 101            → qualify endpoint
sip channels        → active SIP channels
start sip trace     → capture SIP traffic
stop trace          → stop and show results
```

## Access Methods
- **Web Console** — browser-based chat at Admin > Frogman
- **CLI** — `fwconsole frogman:chat` for interactive session
- **MCP** — Claude Desktop / Claude Code via SSH
- **HTTP API** — curl to ajax endpoints
- **Discord** — bot commands
