# Local setup guide (Windows + Laragon) — for beginners

This is a complete, from-scratch walkthrough for running Hostorio AI Chatbot on
your own Windows computer using **Laragon**, a free local server environment.
No prior PHP or MySQL experience assumed. Follow every step in order.

Using XAMPP instead? See [XAMPP_SETUP.md](XAMPP_SETUP.md) — same steps,
XAMPP's tools (phpMyAdmin, Control Panel).

If you get stuck, jump to [Troubleshooting](#troubleshooting) at the bottom —
it covers every problem people commonly hit during this setup.

---

## Contents

1. [What you need before starting](#1-what-you-need-before-starting)
2. [Install Laragon](#2-install-laragon)
3. [Get the project files](#3-get-the-project-files)
4. [Start Laragon and check PHP version](#4-start-laragon-and-check-php-version)
5. [Create the database](#5-create-the-database)
6. [Create your .env configuration file](#6-create-your-env-configuration-file)
7. [Generate your secret keys](#7-generate-your-secret-keys)
8. [Install the database schema (tables)](#8-install-the-database-schema-tables)
9. [Run the health check](#9-run-the-health-check)
10. [Open the app in your browser](#10-open-the-app-in-your-browser)
11. [Log into the admin panel](#11-log-into-the-admin-panel)
12. [Add an AI provider API key](#12-add-an-ai-provider-api-key)
13. [Add your first knowledge base article](#13-add-your-first-knowledge-base-article)
14. [Test the chat widget](#14-test-the-chat-widget)
15. [Troubleshooting](#troubleshooting)

---

## 1. What you need before starting

- A Windows computer (10 or 11)
- About 500 MB of free disk space
- An internet connection
- (Later, optional) An API key from Anthropic, DeepSeek, or OpenAI — you can
  install and explore everything first and add this at step 12

You do **not** need to install PHP, MySQL, or Apache separately — Laragon
bundles all of them.

---

## 2. Install Laragon

1. Go to https://laragon.org/download/ and download **Laragon Full** (the
   full edition bundles PHP, MySQL and Apache together, so you do not have to
   install anything separately)
2. Run the installer, keep all default options, and let it install to
   `C:\laragon`
3. Launch Laragon once installation finishes. You'll see a small control
   panel with buttons like **Start All**, **Stop All**, **Terminal**,
   **Database**

Leave Laragon closed for now — you'll start it properly in step 4.

---

## 3. Get the project files

You need the project's code sitting inside Laragon's web folder, which is
`C:\laragon\www`.

### Option A — You already have a .zip of the project

1. Extract the zip
2. Rename the extracted folder to `hostorio-ai-chatboot` (no spaces)
3. Move the whole folder into `C:\laragon\www\`

You should end up with a folder at:
```
C:\laragon\www\hostorio-ai-chatboot\
```
and inside it you should see folders like `admin`, `api`, `config`, `core`,
`public`, `docs`, and a file called `bootstrap.php`.

### Option B — You have the project on GitHub

1. Open Laragon, click **Terminal** (this opens a command prompt already
   pointed at `C:\laragon\www`)
2. Run:
   ```bash
   git clone <the-repository-url> hostorio-ai-chatboot
   ```
3. This creates the same folder structure as Option A

**Either way, confirm the layout before continuing:**
```bash
cd C:\laragon\www\hostorio-ai-chatboot
dir
```
You should see `bootstrap.php`, `.env.example`, `admin`, `api`, `config`,
`core`, `database`, `docs`, `public`, `tools`.

---

## 4. Start Laragon and check PHP version

1. Open the Laragon app and click **Start All**. Two icons (Apache and MySQL)
   should turn green — that means both servers are running
2. Open Laragon's terminal (the **Terminal** button) and run:
   ```bash
   php -v
   ```
3. You need **PHP 8.1 or newer**. If Laragon shows an older version:
   - Right-click the Laragon tray icon → **PHP** → pick a version 8.1+
     from the list (Laragon ships several versions; if 8.1+ isn't listed,
     use Laragon's **Quick app** / **Tools → Quick add** menu to download one)
   - Click **Start All** again after switching

Also confirm these PHP extensions are enabled (they almost always are, by
default, in Laragon):
```bash
php -m | findstr /i "pdo_mysql curl mbstring json"
```
You should see all four listed. If one is missing, right-click the Laragon
tray icon → **PHP** → **Extensions** and enable it, then restart Laragon.

---

## 5. Create the database

Use **HeidiSQL**, which comes bundled with Laragon.

1. In the Laragon control panel, click **Database**. This opens HeidiSQL and
   connects automatically to your local MySQL server as `root` with no
   password
2. In the left-hand panel, right-click on **localhost** (the top-level tree
   item) → **Create new** → **Database**
3. In the dialog:
   - **Name:** `hostorio_ai_chatbot` (use underscores, not hyphens — MySQL
     database names should not contain hyphens without extra quoting, and it
     avoids a class of subtle errors later)
   - **Collation:** `utf8mb4_unicode_ci`
4. Click **OK**

You should now see `hostorio_ai_chatbot` appear in the left-hand tree,
empty (no tables yet — that comes in step 8).

> **Note:** if you already created a database with hyphens in the name (like
> `hostorio-ai-chatbootdb`), that's fine too, just be consistent about the
> name you put in `.env` in the next step.

---

## 6. Create your .env configuration file

The app reads all of its configuration from a file called `.env`, which does
not exist yet — you copy it from a template and fill in your own values.

1. In `C:\laragon\www\hostorio-ai-chatboot\`, find `.env.example`
2. Copy it and rename the copy to `.env` (exactly that name, with the leading
   dot — Windows Explorer may fight you on this; the easiest way is via the
   Laragon terminal):
   ```bash
   cd C:\laragon\www\hostorio-ai-chatboot
   copy .env.example .env
   ```
3. Open `.env` in any text editor (Notepad, VS Code, Notepad++)
4. Find this block and fill in your database details from step 5:
   ```
   DB_HOST=localhost
   DB_PORT=3306
   DB_NAME=hostorio_ai_chatbot
   DB_USER=root
   DB_PASS=
   DB_CHARSET=utf8mb4
   DB_PREFIX=hoai_
   ```
   Laragon's default MySQL root user has **no password**, so leave `DB_PASS`
   blank, exactly as shown.
5. Set the basic app settings near the top:
   ```
   APP_NAME="Hostorio AI Chatbot"
   APP_ENV=development
   APP_DEBUG=true
   APP_URL=http://localhost/hostorio-ai-chatboot
   APP_TIMEZONE=UTC
   ```
   `APP_DEBUG=true` is fine for local testing — never do this on a real,
   public website.
6. Leave everything else as-is for now (`WP_DB_*`, `WHMCS_DB_*`, provider API
   keys) — you'll come back to some of these later. **Save the file.**

---

## 7. Generate your secret keys

Two values must be generated, not typed by hand: `APP_KEY` and
`ADMIN_PASSWORD_HASH`.

### APP_KEY

This is a random secret the app uses to sign identity tokens. In the Laragon
terminal:
```bash
cd C:\laragon\www\hostorio-ai-chatboot
php -r "echo bin2hex(random_bytes(32));"
```
This prints a long string of letters and numbers. Copy it, then open `.env`
and set:
```
APP_KEY=paste-the-string-here
```

### ADMIN_PASSWORD_HASH

This is how you log into `/admin` later. Pick a password (write it down —
you'll need it in step 11), then run, replacing `YourPassword123` with your
own choice:
```bash
php -r "echo password_hash('YourPassword123', PASSWORD_DEFAULT);"
```
This prints something starting with `$2y$...`. Copy the **entire** string
and paste it into `.env`:
```
ADMIN_PASSWORD_HASH=$2y$10$....................................................
```

> **Careful:** the hash contains `$` characters. Paste it directly into the
> `.env` file with a text editor — don't retype it, and don't run it through
> anything that might interpret the `$` signs.

Save `.env` again.

---

## 8. Install the database schema (tables)

This step creates all the tables the app needs inside the database you made
in step 5.

```bash
cd C:\laragon\www\hostorio-ai-chatboot
php tools/install.php
```

Expected output:
```
Installing schema into `hostorio_ai_chatbot` with prefix `hoai_`…
  applied install.sql
  applied phase3.sql
  applied phase5.sql
Applied NN statement(s).
Schema installed successfully.
```

If it stops with an error partway through, see
[Troubleshooting → Schema install fails](#schema-install-fails-part-way-through).

You can re-run this command safely at any time — it only creates tables that
don't already exist, it never deletes data.

---

## 9. Run the health check

This checks everything at once — PHP version, extensions, database
connection, storage folders, and whether an AI provider key is configured:

```bash
php tools/healthcheck.php
```

At this point (before adding an API key), it's normal to see:
```
[ - ] At least one enabled     no — add a key before Phase 2
```
Everything else should show `[ + ]`. If anything else shows `[ - ]`, fix that
item before moving on — the messages describe what's wrong.

---

## 10. Open the app in your browser

With Apache running (Laragon's **Start All**), open:

```
http://localhost/hostorio-ai-chatboot/api/health
```

You should get a small JSON response like:
```json
{"ok":true,"data":{"status":"pass"},...}
```

If you instead see a **403 Forbidden** page, see
[Troubleshooting → 403 Forbidden](#403-forbidden-on-apihealth-or-admin).

---

## 11. Log into the admin panel

Open:
```
http://localhost/hostorio-ai-chatboot/admin
```

Log in with:
- **Username:** none required — just the password
- **Password:** the plain-text password you chose in step 7 (not the hash —
  the hash is what's stored, you type the real password to log in)

You should land on the **Dashboard**, showing conversation counts (all zero,
since nothing has talked to it yet) and a cost summary.

---

## 12. Add an AI provider API key

The chatbot needs at least one AI provider to actually answer questions.
**DeepSeek** is the cheapest option to start with.

1. Sign up at https://platform.deepseek.com and create an API key (or use
   Anthropic/OpenAI if you already have a key there — the same steps apply)
2. In the admin panel, go to **Settings**
3. Under **Provider API keys → DeepSeek**, paste your key into the
   **API key** field
4. Click **Save settings** at the bottom

Re-run the health check to confirm it picked up the key:
```bash
php tools/healthcheck.php
```
The provider line should now show `[ + ] key set`.

---

## 13. Add your first knowledge base article

Without any content, the chatbot has nothing to answer from. The fastest way
to test it is a manual note:

1. In the admin panel, go to **Knowledge base**
2. Click **Add note** (or similar — the exact wording is in the UI)
3. Title: `Test article`
4. Body: `Our support hours are 9am to 5pm UTC, Monday to Friday.`
5. Save it, then click **Re-index** (or run from the terminal):
   ```bash
   php tools/kb.php index
   ```

---

## 14. Test the chat widget

Open the demo page that ships with the widget:
```
http://localhost/hostorio-ai-chatboot/widget/demo.html
```

Click the chat bubble, type: **"What are your support hours?"**

You should get an answer back referencing the note you just added. If the
answer is generic or says it doesn't know, double check step 13 was
completed and re-indexed.

**You now have a fully working local install.** From here you can explore
**Routing** (which model handles which question type), **Conversations**
(transcripts), and the rest of the [ADMIN.md](ADMIN.md) guide.

---

## Troubleshooting

### 403 Forbidden on /api/health or /admin

This happens when Apache's `.htaccess` rules block the folder before the
rewrite to `public/index.php` can run. Make sure your copy of the project has
the fix applied: open `api/.htaccess` and `admin/.htaccess` and confirm they
look like this (deny only `.php` files, not everything):
```apache
<FilesMatch "\.php$">
    Require all denied
</FilesMatch>
```
If they instead say plain `Require all denied` with no `<FilesMatch>` wrapper,
replace them with the block above, then reload the page.

Also confirm Apache's rewrite module is enabled: right-click the Laragon tray
icon → **Apache** → **httpd-modules** → make sure `rewrite_module` has a
checkmark.

### Schema install fails part way through

If `php tools/install.php` fails with something like:
```
Failed in phase3.sql: SQLSTATE[42S02]: Base table or view not found:
1146 Table '...hoai_settings' doesn't exist
```
this means `install.sql` didn't fully apply before `phase3.sql` started (most
often because of a database charset/collation mismatch, or a stale
half-created database from an earlier failed attempt).

**Fix — start the database clean:**
1. Open HeidiSQL (Laragon → **Database**)
2. Right-click your database → **Drop** (delete it entirely)
3. Recreate it exactly as in [step 5](#5-create-the-database), double-checking
   the collation is `utf8mb4_unicode_ci`
4. Run `php tools/install.php` again

If it still fails on the same table, run the three schema files manually
in HeidiSQL instead (this bypasses whatever is going wrong with the PHP
statement splitter):
1. Open HeidiSQL, select your database from the left panel
2. Click the **Query** tab
3. Open `database/schema/install.sql` in a text editor, copy everything,
   paste into the query tab, click the red **Execute** (▶) button, or press
   <kbd>F9</kbd>
4. Repeat for `database/schema/phase3.sql`, then `database/schema/phase5.sql`,
   **in that order**
5. Confirm with:
   ```bash
   php tools/healthcheck.php
   ```

### "Failed to connect to server" / MySQL not running

Laragon's MySQL icon must be green. Click **Start All** again. If MySQL
refuses to start, another program (often a previously installed XAMPP or a
Windows service) may already be using port 3306. Right-click the Laragon
tray icon → **MySQL** → check the port, or close the conflicting program.

### "could not generate answer" in the widget

Usually means no AI provider key is configured yet, or the key is invalid.
Re-check [step 12](#12-add-an-ai-provider-api-key), then look at the latest
log file for the real error:
```
storage/logs/app-YYYY-MM-DD.log
```
(open the most recent date in a text editor — errors from the provider API
show up there, e.g. an invalid key or an expired trial).

### Answers are generic / don't mention my content

Your knowledge base is empty or hasn't been re-indexed. Repeat
[step 13](#13-add-your-first-knowledge-base-article) and confirm
`php tools/kb.php index` reports at least one document indexed.

### "This installer must be run from the command line."

You opened `tools/install.php` in a browser instead of running it with
`php tools/install.php` in a terminal. These `tools/*.php` scripts are
CLI-only by design (they touch the database directly and are not meant to be
web-reachable). Use the Laragon terminal instead.

### Page shows PHP source code instead of running it

Apache isn't processing PHP files, which means Apache isn't actually running
or isn't pointed at the right PHP version. Confirm both lights are green in
Laragon (**Start All**), and that you're browsing to
`http://localhost/...` (not opening the file directly from Explorer with
`file://`).

### Still stuck

Run the full diagnostics and read every line — the messages are written to
say exactly what's wrong and how to fix it:
```bash
php tools/healthcheck.php
php tools/security-check.php
```

---

## Where to go next

- **[ADMIN.md](ADMIN.md)** — day-to-day operation once it's running
- **[FEATURES.md](FEATURES.md)** — what the chatbot can do and how to use it well
- **[TROUBLESHOOTING.md](TROUBLESHOOTING.md)** — problems that come up after
  you're live, not just during setup
- **[INSTALL.md](INSTALL.md)** — the equivalent guide for installing on real
  cPanel hosting, once you're ready to go live
