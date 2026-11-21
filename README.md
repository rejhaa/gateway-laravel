# gateway-laravel

A Laravel rebuild of the agent gateway's inbound exceptions demo. An agent in Claude works through a purchasing mailbox against the purchase orders in Odoo and proposes what the records should say. The gateway writes a change itself when one of the stated rules covers it, and otherwise holds it for Riku Lindgren, the supply chain lead, who approves or rejects it on a card in the chat or on the approval page. Every step lands in an append-only audit log.

`DESIGN.md` is the design. It covers the decision rules, the data, the screens and the tests.

## Requirements

- Docker with Compose, for Odoo.
- PHP 8.4 or newer, Composer, and Node 22.12 or newer (or 20.19 or newer on the 20 line), for the Laravel application. Laravel Herd has all three.
- PHP's `sodium` extension, which Laravel Passport needs for the OAuth keys.
- `pdftotext`, which reads the text layer of PDF attachments. `soffice` and `qpdf` are needed only to regenerate the test cases.

## Odoo

```sh
bin/odoo up
```

The first run creates the `inbound` database, installs the modules, loads the base seed and checks every planted fact, which takes under a minute once the images are pulled. Odoo is then on http://localhost:8070. Sign in as `admin` / `admin`, or as one of the six people, whose password is their login: `riku` (supply chain lead), `elina` (purchasing assistant), `mikko` (buyer), `johanna` (finance), `tuomas` (production planner), `pekka` (warehouse).

Set `ODOO_PORT` in `.env` to publish Odoo on another port. Postgres publishes no port.

| Command | What it does |
|---|---|
| `bin/odoo up` | Starts Postgres and Odoo. A fresh volume gets the database and the base seed. |
| `bin/odoo seed` | Loads the base seed again, which undoes a case and every change made since. |
| `bin/odoo seed cases/demo/demo-mailbox` | Loads the base seed and that case's records on top. Takes about 6 seconds. |
| `bin/odoo down` | Stops the containers and keeps the database. |
| `bin/odoo reset` | Stops the containers and deletes the database, so the next `up` starts fresh. |

Every seed recreates the users, so their Odoo sessions and API keys end. `bin/odoo` copies the new keys from the container to `odoo/data/`, which is not in git:

- `inbound-users.json` has an API key each for `riku` and `elina`, for Odoo's JSON-2 API.
- `inbound-admin-key.txt` has an admin key.
- `inbound-case-map.json` has the Odoo ids of the loaded case's records, by the case's own references.

```sh
KEY=$(jq -r .riku.api_key odoo/data/inbound-users.json)
curl -s http://localhost:8070/json/2/purchase.order/search_read \
  -H "Authorization: bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"domain": [["state", "=", "purchase"]], "fields": ["name", "partner_id"]}'
```

The customizations of this Odoo are part of the seed: the confirmed quantity and date on a purchase order line, the dispute fields on a vendor bill, the views that show them, and the purchasing assistant's group with its access restriction. `odoo/INBOUND-SEED.md` is the answer key for everything the seed loads.

## The gateway

```sh
composer setup
bin/odoo up
php artisan gateway:reset cases/demo/demo-mailbox
composer dev
```

`composer setup` installs the dependencies, creates `.env` and the SQLite database, generates Passport's OAuth keys in `storage/` when they are missing, and builds the assets. `gateway:reset` loads the case into Odoo and empties the gateway's proposals and audit log. `composer dev` serves the gateway on http://localhost:8000 with four PHP workers, so a slow Odoo call does not hold up the other requests, and runs a queue worker, `pail` and Vite beside it.

The approval page is http://localhost:8000. It signs Riku in on the first visit and asks nothing, because this version has one user. It has three pages:

- Approvals shows what waits, grouped by mail, and refreshes every 5 seconds.
- History shows every proposal, newest first, with its audit trail.
- Audit log shows every row of the audit log, with filters.

### Connecting Claude

Claude reaches the gateway over MCP at `/mcp`, with an OAuth token from Laravel Passport. claude.ai needs a public HTTPS URL, which a Tailscale Funnel gives:

```sh
tailscale funnel 8000             # https://<machine>.<tailnet>.ts.net
```

Set `APP_URL` to that URL, because the OAuth discovery documents and the card's allowed domain come from it.

- claude.ai: Customize, Connectors, Add custom connector, with the URL `https://<machine>.<tailnet>.ts.net/mcp`. Claude opens the authorization page, where Riku clicks Connect. Upload `skills/inbound-exceptions` as a zip under Customize, Skills.
- Claude Code: `claude mcp add --transport http gateway https://<machine>.<tailnet>.ts.net/mcp`, then `/mcp` to authorize. Link the skill with `ln -s ../../skills/inbound-exceptions .claude/skills/inbound-exceptions`.

If claude.ai calls come back 403, add its origin to `MCP_ALLOWED_ORIGINS`.

### The tools

| Tool | What it does |
|---|---|
| `whoami` | Names the person the agent works for, their role, their Odoo login and today's date |
| `get_policy` | Returns the purchasing policy, the approval rules and the agent access rules as markdown |
| `mail_list_messages` | Lists the messages in the mailbox |
| `mail_read_message` | Reads one message with the text of its attachments, numbered by page and line |
| `odoo_search_read` | Reads allowed models and fields from Odoo as Riku, with the proposals already made on each line |
| `propose` | Proposes a write to one record with a quote from the mail as evidence. The gateway applies it by rule, holds it for Riku, or denies it under the policy. |
| `list_proposals` | Lists the proposals and their decisions, and shows the approval card in the chat |

No tool approves or rejects. Only the approval page and the card do, with Riku's session or the card's own token.

### Resetting between runs

```sh
php artisan gateway:reset                          # the base seed only
php artisan gateway:reset cases/demo/demo-mailbox  # the base seed and a case
```

A reseed gives every Odoo record a new id, so old proposals would point at other records. `gateway:reset` runs `bin/odoo seed` with the case, then drops and recreates the proposals and audit log tables. It keeps the user and the OAuth tables, so a connected Claude stays authorized. It runs only when `APP_ENV` is `local`, and when the seed fails it leaves the tables alone. Point `GATEWAY_MAILBOX_PATH` at the same case's messages.

### Configuration

| Key in `.env` | Default | What it sets |
|---|---|---|
| `GATEWAY_PROCESS` | `inbound-purchasing` | The business process `propose` uses |
| `GATEWAY_RULE_TIER` | `true` | Whether rules may apply a change. With `false`, every change goes to Riku. |
| `GATEWAY_TODAY` | none | A fixed date for a case whose story happens on another day |
| `GATEWAY_TIMEZONE` | `Europe/Helsinki` | The zone of today and of the times on the screens |
| `GATEWAY_USER_NAME` | `Riku Lindgren` | The person the gateway acts for |
| `GATEWAY_USER_ROLE` | `supply chain lead` | Their role, shown in the top bar |
| `GATEWAY_USER_ODOO_LOGIN` | `riku` | Their Odoo login, whose API key every Odoo call uses |
| `ODOO_URL` | `http://localhost:8070` | Odoo's address |
| `ODOO_KEYS_FILE` | `odoo/data/inbound-users.json` | The API keys `bin/odoo` writes after each seed |
| `GATEWAY_MAILBOX_PATH` | `cases/demo/demo-mailbox/messages` | The directory of `.eml` files the agent reads |
| `GATEWAY_CARD_TOKEN_HOURS` | `12` | How long a card in the chat may decide |
| `MCP_ALLOWED_ORIGINS` | empty | Comma-separated origins allowed to call `/mcp` |

### Tests

```sh
composer test
```

The tests fake Odoo and the mailbox, so they need neither Docker nor the cases.

## Layout

| Path | What it holds |
|---|---|
| `compose.yaml`, `bin/odoo` | The Odoo and Postgres containers and the script that runs them |
| `odoo/` | Odoo's config, the container scripts `init.sh` and `seed.sh`, the base seed `inbound-seed.py`, the case loader `inbound-case.py` and the answer key `INBOUND-SEED.md` |
| `cases/` | The test cases and their format, `CASE-FORMAT.md`. `demo/` is the mailbox of the demo walkthrough, `spike/` has nine cases and `hard/` seven. Each set has its generator in `_tools/`. |
| `skills/inbound-exceptions/` | The skill the agent works by |
| `rules/` | The purchasing policy, the rules that decide who approves a change, and what the agent may read and propose in Odoo |
| `app/Gateway/` | The core: proposals, decisions, the audit log and the words on the screens. It knows no system. |
| `app/Processes/InboundPurchasing/` | The purchasing process: its rules, what the agent may read and write, and the facts it reads from Odoo |
| `app/Systems/` | The Odoo client and the mailbox |
| `app/Mcp/` | The MCP server, its seven tools and the approval card |
| `app/Livewire/`, `resources/views/` | The approval page, the history and the audit log |
| `resources/css/proposals.css`, `resources/js/proposals-card.js` | The look of a proposal, shared by the pages and the card, and the card's script |
| `routes/ai.php`, `routes/card.php`, `routes/web.php` | The MCP route with OAuth, the card's endpoints, and the pages |
| `transcripts/`, `bin/transcripts` | The prompts and Claude's answers from the Claude Code sessions that built this project, one file per session, and the script that writes them from the local session logs |
