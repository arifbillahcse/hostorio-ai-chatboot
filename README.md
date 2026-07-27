# Hostorio AI Chatbot

An AI support chatbot for hosting companies. Built in raw PHP so it installs on
ordinary cPanel shared hosting by upload alone — no Composer, no npm, no build
step, no VPS.

It answers customer questions using the hosting company's own knowledge:
WordPress articles, WHMCS account data, and manually written notes — and routes
each question to the cheapest model that can handle it.

---

## Status: Phase 3 complete — Knowledge Ingestion

The foundation, the model router, and the knowledge base are in place. The chat
*endpoint* is still `501` — joining retrieval to the router is Phase 4/5.

| Phase | Scope | Status |
|-------|-------|--------|
| 1 | Foundation & architecture | **Done** |
| 2 | Multi-LLM routing engine (Claude / DeepSeek / GPT) | **Done** |
| 3 | RAG — knowledge ingestion (WordPress + WHMCS + manual notes) | **Done** |
| 4 | RAG — retrieval & context building | Not started |
| 5 | Chat engine integration | Not started |
| 6 | Frontend widget | Not started |
| 7 | Admin panel | Not started |
| 8 | Testing & hardening | Not started |
| 9 | Packaging & distribution | Not started |

---

## Requirements

- PHP 8.1 or newer
- MySQL / MariaDB
- PHP extensions: `pdo_mysql`, `curl`, `mbstring` (standard on cPanel)

---

## Installation

**1. Upload the files** to your hosting account — either at the domain root, in
a subdirectory, or with the document root pointed at `public/`. All three work.

**2. Create the configuration file:**

```bash
cp .env.example .env
```

Fill in at minimum `DB_NAME`, `DB_USER`, `DB_PASS`, and generate an app key:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

> **Preferred location:** put the file one level *above* the web root and name
> it `.hoai.env`. Anything above `public_html` cannot be served over HTTP even
> if `.htaccess` protection fails. The app checks that location first.

**3. Install the database schema:**

```bash
php tools/install.php
```

No SSH access? Import `database/schema/install.sql` through phpMyAdmin instead,
adjusting the `hoai_` table prefix to match your `DB_PREFIX`.

**4. Verify the install:**

```bash
php tools/healthcheck.php
```

This reports every check with a clear pass/fail and exits non-zero if the
foundation is not ready.

---

## Connecting WordPress and WHMCS

Both are **optional** in Phase 1 and **read-only forever**. Leave the database
name blank in `.env` to disable a connector; the app boots normally and the
health check reports it as "not configured".

Create a **SELECT-only MySQL user** for each:

```sql
CREATE USER 'chatbot_ro'@'localhost' IDENTIFIED BY 'strong-password-here';
GRANT SELECT ON wordpress_db.* TO 'chatbot_ro'@'localhost';
GRANT SELECT ON whmcs_db.*     TO 'chatbot_ro'@'localhost';
FLUSH PRIVILEGES;
```

The application enforces read-only access in code as well, so a bug in a later
phase cannot write to a customer's live billing data even if the MySQL grants
are too permissive.

---

## The routing engine

Most questions in a hosting support inbox are repetitive and a cheap model
answers them perfectly well. Only a minority need an expensive one. Routing on
that split is what makes the chatbot affordable at volume.

Every question is classified, then sent to the cheapest provider that can
handle it:

| Class | Trigger | Providers, in order | Why |
|---|---|---|---|
| `action` | "reset my password", "restart my server" | Claude → OpenAI | Needs reliable tool calling. A fumbled password reset costs a support ticket — far more than the tokens saved. |
| `complex` | "why…", "not working", error codes, long messages | Claude → DeepSeek → OpenAI | Wrong diagnosis creates the ticket the chatbot existed to prevent. Extended thinking is on. |
| `simple` | everything else | DeepSeek → OpenAI → Claude | The bulk of the volume, on the cheap model. |

Classification is **rule-based, not model-based**: classifying with an LLM would
add a billed round trip to every single message — the exact cost this routing
exists to avoid — and would make routing non-deterministic and hard to audit.

See what it *would* do without spending anything:

```bash
php tools/ask.php --plan "why is my ssl certificate not working?"
```
```
  classified as        complex
  because              matched troubleshooting phrase "why"
  configured order     claude -> deepseek -> openai
  available now        claude -> deepseek
  would use            claude
  model                claude-opus-4-8
  thinking             on
```

Then ask for real (needs an API key):

```bash
php tools/ask.php "how much does a VPS cost?"
```

In code, one entry point covers it:

```php
$router   = new Hostorio\Llm\LlmRouter();
$response = $router->ask('why is my site slow?', $systemPrompt);

$response->text;      // the answer
$response->provider;  // which model served it
$response->costUsd;   // estimated spend, already recorded
```

**Fallback:** transient failures (429, 5xx, network) are retried against the
same provider with exponential backoff, honouring `Retry-After`. If a provider
still fails, it is abandoned for that request and the next one in the list is
tried. Providers that cannot call tools are skipped for requests carrying tool
definitions — otherwise the model would answer in prose and the action would
silently never happen.

Rules live in `config/routing.php` and are editable without touching code.

---

## The knowledge base

Three sources, all treated as first-class:

| Source | What it holds | How it is used |
|---|---|---|
| **WordPress** | Published posts, pages, custom post types | Synced, chunked, searchable |
| **Manual notes** | Whatever you type in — outages, promos, policy changes | Same path as WordPress, **higher priority** |
| **WHMCS** | Customer, services, tickets, invoices | Structured, fetched live per customer — **never embedded** |

Manual notes exist because the website is always behind. An outage started
twenty minutes ago, a promo runs until Friday, a policy changed this morning —
none of that is a published article, and all of it is what customers are asking
about right now. Notes default to priority 10 against an article's 0, so a note
saying "Frankfurt is down" outranks a guide saying everything is fine.

WHMCS data is deliberately **not** embedded. It is small, per-customer, and
changes by the minute; embedding it would be expensive, instantly stale, and
would leak one customer's details into another's search results.

### Managing it

```bash
php tools/kb.php index                     # sync WordPress, index what changed
php tools/kb.php status                    # what is indexed, what is pending
php tools/kb.php search "ssl not working"  # exactly what the chatbot would retrieve

php tools/kb.php note:add "Frankfurt outage" "DC3 is offline until 14:00 UTC." --expires="+6 hours"
php tools/kb.php note:list
php tools/kb.php note:edit 4 --body="Resolved at 13:20 UTC." --priority=20
php tools/kb.php note:expire 4             # reversible; drops out of search immediately
```

Re-indexing is driven by content hashes, so a sync that finds nothing edited
performs **no embedding calls at all**. Running it nightly from cron costs
nothing when nobody touched the docs.

### Embeddings are optional

**Anthropic has no embeddings endpoint**, so semantic search needs a key from a
provider that does. Rather than force you to sign up for another API, the
default is `EMBEDDING_DRIVER=none`: retrieval runs on MySQL full-text search
alone.

That is a real production mode, not a stub. A hosting FAQ is a small corpus
where customers use the same vocabulary as the docs — "SSL", "cPanel",
"nameserver" — which is exactly where keyword search performs well.

Set `EMBEDDING_DRIVER=openai` and an `EMBEDDING_API_KEY` to upgrade to hybrid
search (full-text selects candidates, vectors re-rank them). The schema and the
indexer are identical either way, so you can switch later without rebuilding.

Vectors are stored as packed float32 BLOBs — a 1536-dimension vector costs 6 KB
instead of ~20 KB as JSON, which matters against a shared-hosting disk quota.

---

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| `GET`  | `/health` | Liveness probe. Reveals nothing about the install. |
| `GET`  | `/api/health/diagnostics` | Full diagnostics. **Guarded** — see below. |
| `POST` | `/api/chat` | Chat endpoint. Returns `501` until Phase 5. |

Diagnostics are reachable only when `APP_DEBUG=true`, or with a bearer token
matching `ADMIN_PASSWORD_HASH`. With neither set it returns `404` — it names
your databases and enabled providers, which is exactly what an attacker wants.

```bash
curl -H "Authorization: Bearer your-admin-password" \
     https://example.com/api/health/diagnostics
```

All responses share one envelope, so the widget has a single shape to branch on:

```json
{ "ok": true,  "...": "..." }
{ "ok": false, "error": { "code": "rate_limited", "message": "…" }, "request_id": "…" }
```

---

## Project layout

```
bootstrap.php          Autoloader, config assembly, error handling. Every entry point includes this.
config/                Settings, assembled from .env. One file per concern.
core/                  Framework pieces: Env, Config, Logger, Security, RateLimiter, Router, Request, Response, CostTracker.
database/              Connection layer + schema.
  Connection.php         Base PDO wrapper. Enforces read-only where required.
  AppDatabase.php        The chatbot's own tables — the only writable connection.
  WordPressDatabase.php  READ-ONLY.
  WhmcsDatabase.php      READ-ONLY.
api/                   Endpoint controllers.
public/index.php       Front controller — the single entry point.
storage/logs/          Daily rotating logs, auto-pruned.
tools/                 install.php, healthcheck.php (CLI only).
```

---

## Security notes

These are deliberate design decisions, not incidental:

- **Credentials never enter `$_ENV` or `getenv()`.** The `.env` loader keeps
  values in a private store, so they cannot leak through `phpinfo()`, a var
  dump, or a child process on a shared account.
- **Logs are redacted at write time.** Any context key containing `api_key`,
  `password`, `token`, `secret`, `authorization`, etc. is masked before it
  reaches disk — verified by test, including nested arrays.
- **Errors never render to the browser.** `display_errors` is forced off;
  everything goes to the log. Stack traces appear in a response only when
  `APP_DEBUG=true`.
- **Writes to WordPress/WHMCS are blocked in code**, before a connection is even
  opened. Verified against 18 bypass attempts including comment prefixes
  (`/* SELECT */ UPDATE …`), case variation, and leading whitespace.
- **Prepared statements are real, not emulated** (`EMULATE_PREPARES => false`),
  which also blocks multi-statement injection at the driver level.
- **Rate limiting is atomic** — a MySQL upsert, not a read-modify-write, since
  PHP-FPM workers share no memory. It fails *open* if the database is down, and
  logs loudly when it does: a limiter outage must not take support offline.
- **CORS is an explicit allowlist**, defaulting to `APP_URL` only.
- **Application internals are unreachable over HTTP** via the root `.htaccess`
  plus a deny-all `.htaccess` in each internal directory.

---

## Development

Run locally:

```bash
php -S 127.0.0.1:8080 public/index.php
```

Check syntax across the project:

```bash
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;
```
