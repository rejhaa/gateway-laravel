# gateway-laravel

A Laravel application that sits between an agent (Claude) and the purchase orders in Odoo. The agent reads a purchasing mailbox and Odoo through the gateway's MCP tools and proposes changes one at a time. The gateway decides each proposal in code. A rule applies it to Odoo, or it waits for Riku Lindgren, the supply chain lead, to approve or reject it, or the policy denies it. Every operation goes into an append-only audit log.

`DESIGN.md` is the specification. It covers the decision rules, the data, the tools, the screens, the configuration and the tests.

## Contents


| Path                                | What it holds                                                                                          |
| ----------------------------------- | ------------------------------------------------------------------------------------------------------ |
| `DESIGN.md`                         | The design the code is built from                                                                      |
| `docs/`                             | The two diagrams in this file                                                                          |
| `app/Gateway/`                      | The core: proposals, decisions and the audit log. It knows no system.                                  |
| `app/Processes/InboundPurchasing/`  | The purchasing process: its rules, what the agent may read and write, and the facts it reads from Odoo |
| `app/Systems/`                      | The Odoo client and the mailbox                                                                        |
| `app/Mcp/`                          | The MCP server, its seven tools and the approval card                                                  |
| `app/Livewire/`, `resources/`       | The approval page, the history and the audit log                                                       |
| `routes/`                           | The MCP route with OAuth (`ai.php`), the card's endpoints (`card.php`) and the pages (`web.php`)       |
| `rules/`                            | The purchasing policy, the approval rules and the agent's access lists, as the agent reads them        |
| `skills/inbound-exceptions/`        | The skill the agent works by                                                                           |
| `odoo/`, `compose.yaml`, `bin/odoo` | The demo Odoo in Docker, its seed and the script that runs it                                          |
| `cases/`                            | The demo mailbox and the test cases the finished gateway is run against                                |
| `tests/`                            | The Pest suite, which fakes Odoo and the mailbox                                                       |
| `transcripts/`                      | The Claude Code sessions that built this project                                                       |


## Starting it up

These steps work on Linux and macOS.

### Requirements

- Docker with Compose, for Odoo
- PHP 8.4 or newer with the `sodium` extension, and Composer
- Node 22.12 or newer
- `pdftotext`, which reads the text layer of PDF attachments
- Tailscale, or another tunnel that gives a public HTTPS URL

On macOS, install Docker Desktop and Tailscale, then run `brew install php composer node poppler`. On Linux, use your package manager. `pdftotext` is in the package `poppler-utils` on Debian and Ubuntu and in `poppler` on Arch.

### Run it

```sh
composer setup                                      # dependencies, .env, database, OAuth keys, assets
bin/odoo up                                         # Odoo on http://localhost:8070
php artisan gateway:reset cases/demo/demo-mailbox   # loads the demo case, empties proposals and the audit log
composer dev                                        # the gateway on http://localhost:8000
```

The first `bin/odoo up` creates the database and loads the seed, which takes under a minute once the images are pulled. Sign in to Odoo as `admin` / `admin` or `riku` / `riku`.

The approval page is [http://localhost:8000](http://localhost:8000). It signs Riku in on the first visit and asks nothing, because this version has one user.

Run the tests with `composer test`. They need neither Docker nor the cases.

### Configuration

`composer setup` copies `.env.example` to `.env` and generates `APP_KEY`. The gateway starts with the values as they are, and `.env.example` explains each one. These are the ones you are likely to change:


| Key in `.env`           | Default                            | When to change it                                                |
| ----------------------- | ---------------------------------- | ---------------------------------------------------------------- |
| `APP_URL`               | `http://localhost:8000`            | Set it to the tunnel URL before you connect Claude               |
| `GATEWAY_MAILBOX_PATH`  | `cases/demo/demo-mailbox/messages` | Point it at the messages of the case that `gateway:reset` loaded |
| `GATEWAY_TODAY`         | empty, the real date               | Set a date for a case whose story happens on another day         |
| `GATEWAY_RULE_TIER`     | `true`                             | Set `false` to send every proposal to Riku                       |
| `ODOO_PORT`, `ODOO_URL` | `8070`, `http://localhost:8070`    | Change both together when port 8070 is taken                     |
| `MCP_ALLOWED_ORIGINS`   | empty                              | Add claude.ai's origin if its calls come back 403                |


Restart `composer dev` after a change to `.env`.

### Connecting Claude

Claude reaches the gateway over MCP at `/mcp`, with an OAuth token. claude.ai connects from Anthropic's servers, so the gateway needs a tunnel that gives it a public HTTPS URL. A Tailscale Funnel does that.

```sh
tailscale funnel 8000             # https://<machine>.<tailnet>.ts.net
```

Funnel is off in a new tailnet. The first run of the command prints a link to the admin console, where you turn on Funnel and HTTPS certificates.

Another tunnel works when it gives a public HTTPS URL on the standard port, keeps the same hostname between runs and passes the forwarded host and scheme headers. Cloudflare Tunnel and ngrok do that with a fixed hostname. Cloudflare's quick tunnel without an account gives a new hostname on every start, so `APP_URL` and the connector in Claude would change on each start. Only the Funnel has been run against this gateway.

Set `APP_URL` in `.env` to the Funnel URL and restart `composer dev`, because the OAuth discovery documents and the card's allowed domain come from it. Close the Funnel when you are done. The sign-in asks nothing, so anyone who reaches the URL can connect.

Then connect one of the clients:

- **claude.ai.** Go to Customize, Connectors, Add custom connector, and enter `https://<machine>.<tailnet>.ts.net/mcp`. Claude opens the authorization page, where you click Connect. Upload `skills/inbound-exceptions` as a zip under Customize, Skills.
- **Claude Code.** Run `claude mcp add --transport http gateway https://<machine>.<tailnet>.ts.net/mcp`, then `/mcp` to authorize. Link the skill with `ln -s ../../skills/inbound-exceptions .claude/skills/inbound-exceptions`. Claude Code shows the tool's text where claude.ai draws the approval card. Use it for development only, because an agent with a shell on this machine can reach the approval page itself (section 1 of `DESIGN.md`).

If claude.ai calls come back 403, add its origin to `MCP_ALLOWED_ORIGINS` in `.env`.

## Architecture

![Claude reaches the gateway's MCP server through a Tailscale Funnel and can only read and propose. Riku approves or rejects on the approval page or the chat card. ProposalService alone writes to Odoo and records every step in SQLite.](docs/architecture.svg)

Claude has no tool that approves, rejects or writes. The blue path is the only one that takes a decision, and it rejects Claude's OAuth token.

## Data model

![The proposals table is the centre. Each audit_log row may point at a proposal, a proposal may replace an earlier one, and a proposal points by id at an Odoo record and a mail message outside the database. The oauth tables point at the one user.](docs/data-model.svg)

The gateway's own data is the `proposals` and `audit_log` tables in SQLite. Database triggers refuse every update and delete on `audit_log`. `oauth_*` stands for Passport's five tables. Purchase orders and mail stay in Odoo and the mailbox (the dashed boxes), and a proposal points at them by id. Section 5 of `DESIGN.md` lists every column. 

## Yhteenveto

Yhteenveto sähköpostissa.