# gateway-laravel

A Laravel rebuild of the agent gateway's inbound exceptions demo. An agent works through a purchasing mailbox against the purchase orders in Odoo, proposes what the records should say, and a person approves every change the stated rules do not cover.

The repository holds the demo's world and an empty application:

- An Odoo 20 Community with the fictional Vuoksi Golf Oy in it, in Docker.
- The purchasing mailbox as test cases, each a set of emails with their attachments and the records they are about.
- The agent's skill and the rules the gateway applies.
- A Laravel 13 application with Livewire 4 and Laravel MCP installed and nothing built yet.

## Requirements

- Docker with Compose, for Odoo.
- PHP 8.3 or newer, Composer and Node, for the Laravel application.
- `soffice`, `qpdf` and `pdftotext`, only to regenerate the test cases.

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

## The Laravel application

```sh
composer setup
composer dev
```

`composer setup` installs the dependencies, creates `.env` and the SQLite database, and builds the assets. `composer dev` serves the application on http://localhost:8000.

MCP servers are registered in `routes/ai.php`, which is empty. Create one with `php artisan make:mcp-server` and its tools with `php artisan make:mcp-tool`. Create a Livewire component with `php artisan make:livewire`.

```sh
composer test
```

## Layout

| Path | What it holds |
|---|---|
| `compose.yaml`, `bin/odoo` | The Odoo and Postgres containers and the script that runs them |
| `odoo/` | Odoo's config, the container scripts `init.sh` and `seed.sh`, the base seed `inbound-seed.py`, the case loader `inbound-case.py` and the answer key `INBOUND-SEED.md` |
| `cases/` | The test cases and their format, `CASE-FORMAT.md`. `demo/` is the mailbox of the demo walkthrough, `spike/` has nine cases and `hard/` seven. Each set has its generator in `_tools/`. |
| `skills/inbound-exceptions/` | The skill the agent works by. It names the gateway's MCP tools, which this application does not have yet. |
| `rules/` | The purchasing policy, the rules that decide who approves a change, and what the agent may read and propose in Odoo |
| `app/`, `routes/`, `resources/` and the rest | The Laravel application |

## Where it comes from

Odoo's seed, the cases, the skill and the rules come from the `agent-gateway` project, where the gateway is written in TypeScript and Odoo runs from source. `rules/approval-rules.md` and `rules/agent-access.md` describe what that gateway's code does (`src/gateway/inbound-compose.ts` and `src/gateway/inbound-access.ts` there), as the specification for the same checks here.
