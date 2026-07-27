# Hostorio AI Chatbot

An AI support chatbot for hosting companies. Built in raw PHP so it installs on
ordinary cPanel shared hosting by upload alone — no Composer, no npm, no build
step, no VPS.

It answers customer questions using the hosting company's own knowledge:
WordPress articles, WHMCS account data, and manually written notes — and routes
each question to the cheapest model that can handle it.

---

## Status: Phase 1 complete — Foundation & Architecture

The skeleton is in place and verified. There is **no chat logic yet**; the chat
endpoint accepts, validates and rate-limits a message, then returns `501 Not
Implemented`. That is the intended Phase 1 deliverable.

| Phase | Scope | Status |
|-------|-------|--------|
| 1 | Foundation & architecture | **Done** |
| 2 | Multi-LLM routing engine (Claude / DeepSeek / GPT) | Not started |
| 3 | RAG — knowledge ingestion (WordPress + WHMCS + manual notes) | Not started |
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
