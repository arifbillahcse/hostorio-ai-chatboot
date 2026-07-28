# Local setup guide (Windows + XAMPP) — for beginners

This is a complete, from-scratch walkthrough for running Hostorio AI Chatbot on
your own Windows computer using **XAMPP**. No prior PHP or MySQL experience
assumed. Follow every step in order.

Using Laragon instead? See [LOCAL_SETUP.md](LOCAL_SETUP.md) — same steps,
Laragon's tools (HeidiSQL, one-click terminal). Use whichever one you have
installed — don't run both at once, they'll fight over the same ports.

If you get stuck, jump to [Troubleshooting](#troubleshooting) at the bottom.

---

## Contents

1. [What you need before starting](#1-what-you-need-before-starting)
2. [Install XAMPP](#2-install-xampp)
3. [Get the project files](#3-get-the-project-files)
4. [Start Apache and MySQL](#4-start-apache-and-mysql)
5. [Check your PHP version](#5-check-your-php-version)
6. [Create the database](#6-create-the-database)
7. [Create your .env configuration file](#7-create-your-env-configuration-file)
8. [Generate your secret keys](#8-generate-your-secret-keys)
9. [Install the database schema (tables)](#9-install-the-database-schema-tables)
10. [Run the health check](#10-run-the-health-check)
11. [Open the app in your browser](#11-open-the-app-in-your-browser)
12. [Log into the admin panel](#12-log-into-the-admin-panel)
13. [Add an AI provider API key](#13-add-an-ai-provider-api-key)
14. [Add your first knowledge base article](#14-add-your-first-knowledge-base-article)
15. [Test the chat widget](#15-test-the-chat-widget)
16. [Troubleshooting](#troubleshooting)

---

## 1. What you need before starting

- A Windows computer (10 or 11)
- About 500 MB of free disk space
- An internet connection
- (Later, optional) An API key from Anthropic, DeepSeek, or OpenAI — you can
  install and explore everything first and add this at step 13

XAMPP bundles PHP, MySQL (as MariaDB) and Apache together, so nothing else
needs installing separately.

---

## 2. Install XAMPP

1. Go to https://www.apachefriends.org and download XAMPP for Windows.
   **Pick a version that bundles PHP 8.1 or newer** — the download page shows
   the PHP version next to each XAMPP version; if you're not sure, download
   the latest one
2. Run the installer. If Windows shows a warning about antivirus or User
   Account Control, that's normal for XAMPP — click through it
3. Keep the default install location: `C:\xampp`
4. On the component selection screen, make sure **Apache**, **MySQL**, and
   **PHP** are checked (they are by default) — you can leave the rest
   unchecked (FileZilla, Mercury, Tomcat aren't needed)
5. Finish the install. When it asks to launch the Control Panel, let it

---

## 3. Get the project files

XAMPP's web folder is `C:\xampp\htdocs` (this is the XAMPP equivalent of
Laragon's `www` folder).

### Option A — You already have a .zip of the project

1. Extract the zip
2. Rename the extracted folder to `hostorio-ai-chatboot` (no spaces)
3. Move the whole folder into `C:\xampp\htdocs\`

You should end up with:
```
C:\xampp\htdocs\hostorio-ai-chatboot\
```
and inside it, folders like `admin`, `api`, `config`, `core`, `public`,
`docs`, and a file called `bootstrap.php`.

### Option B — You have the project on GitHub

1. Open a Command Prompt (search "cmd" in the Start menu)
2. Run:
   ```bash
   cd C:\xampp\htdocs
   git clone <the-repository-url> hostorio-ai-chatboot
   ```
   (If `git` isn't recognized, install [Git for Windows](https://git-scm.com/download/win)
   first, then try again.)

**Either way, confirm the layout before continuing:**
```bash
cd C:\xampp\htdocs\hostorio-ai-chatboot
dir
```
You should see `bootstrap.php`, `.env.example`, `admin`, `api`, `config`,
`core`, `database`, `docs`, `public`, `tools`.

---

## 4. Start Apache and MySQL

1. Open the **XAMPP Control Panel** (search "XAMPP Control Panel" in the
   Start menu, or find it at `C:\xampp\xampp-control.exe`)
2. Click **Start** next to **Apache**
3. Click **Start** next to **MySQL**

Both rows should turn green and show a process ID (PID) and port numbers.
Apache normally shows ports `80, 443`; MySQL shows `3306`.

If either fails to start, see
[Troubleshooting → Apache/MySQL won't start](#apache-or-mysql-wont-start-port-conflict).

---

## 5. Check your PHP version

XAMPP does not have a built-in terminal button like Laragon — you use the
regular Windows Command Prompt, and XAMPP's PHP needs to be reachable from it.

Open Command Prompt and try:
```bash
php -v
```

**If you get `'php' is not recognized...`**, PHP isn't on your system PATH yet.
Either:
- Use the full path every time: `C:\xampp\php\php.exe -v`, **or**
- Add it to PATH permanently (recommended, saves retyping the full path for
  every command in this guide):
  1. Search "Environment Variables" in the Start menu → **Edit the system
     environment variables** → **Environment Variables...**
  2. Under **System variables**, select **Path** → **Edit** → **New**
  3. Add `C:\xampp\php`
  4. Click OK on every dialog, then **close and reopen** Command Prompt
  5. Try `php -v` again

You need **PHP 8.1 or newer**. If your bundled XAMPP version is older,
download a newer XAMPP installer from apachefriends.org (matching the PHP
version you need) rather than trying to upgrade PHP inside an existing XAMPP
install — mixing PHP versions inside one XAMPP install is more trouble than
it's worth for a local setup.

Confirm the required extensions are present:
```bash
php -m | findstr /i "pdo_mysql curl mbstring json"
```
You should see all four. XAMPP enables these by default, so this almost
always passes without changes. If one is missing, open
`C:\xampp\php\php.ini` in a text editor, find the matching `;extension=...`
line, remove the leading `;`, save, and restart Apache from the Control
Panel.

---

## 6. Create the database

XAMPP bundles **phpMyAdmin**, a browser-based database tool (no separate app
to open, unlike Laragon's HeidiSQL).

1. With Apache and MySQL running, open your browser to:
   ```
   http://localhost/phpmyadmin
   ```
2. Click **New** in the left-hand sidebar
3. Under **Database name**, type: `hostorio_ai_chatbot` (use underscores,
   not hyphens — it avoids a class of subtle errors later)
4. Set **Collation** to `utf8mb4_unicode_ci` from the dropdown
5. Click **Create**

You should now see `hostorio_ai_chatbot` in the left-hand list, empty (no
tables yet — that comes in step 9).

> **Note:** if you already created a database with hyphens in the name (like
> `hostorio-ai-chatbootdb`), that's fine too, just be consistent about the
> name you put in `.env` in the next step.

---

## 7. Create your .env configuration file

The app reads all of its configuration from a file called `.env`, which does
not exist yet — you copy it from a template and fill in your own values.

1. In `C:\xampp\htdocs\hostorio-ai-chatboot\`, find `.env.example`
2. Copy it and rename the copy to `.env` (exactly that name, with the leading
   dot). Easiest via Command Prompt:
   ```bash
   cd C:\xampp\htdocs\hostorio-ai-chatboot
   copy .env.example .env
   ```
3. Open `.env` in any text editor (Notepad, VS Code, Notepad++)
4. Find this block and fill in your database details from step 6:
   ```
   DB_HOST=localhost
   DB_PORT=3306
   DB_NAME=hostorio_ai_chatbot
   DB_USER=root
   DB_PASS=
   DB_CHARSET=utf8mb4
   DB_PREFIX=hoai_
   ```
   XAMPP's default MySQL root user has **no password**, so leave `DB_PASS`
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

## 8. Generate your secret keys

Two values must be generated, not typed by hand: `APP_KEY` and
`ADMIN_PASSWORD_HASH`.

### APP_KEY

This is a random secret the app uses to sign identity tokens. In Command
Prompt:
```bash
cd C:\xampp\htdocs\hostorio-ai-chatboot
php -r "echo bin2hex(random_bytes(32));"
```
This prints a long string of letters and numbers. Copy it, then open `.env`
and set:
```
APP_KEY=paste-the-string-here
```

### ADMIN_PASSWORD_HASH

This is how you log into `/admin` later. Pick a password (write it down —
you'll need it in step 12), then run, replacing `YourPassword123` with your
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
> `.env` file with a text editor — don't retype it.

Save `.env` again.

---

## 9. Install the database schema (tables)

This step creates all the tables the app needs inside the database you made
in step 6.

```bash
cd C:\xampp\htdocs\hostorio-ai-chatboot
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

## 10. Run the health check

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

## 11. Open the app in your browser

With Apache running, open:

```
http://localhost/hostorio-ai-chatboot/api/health
```

You should get a small JSON response like:
```json
{"ok":true,"data":{"status":"pass"},...}
```

If you instead see a **403 Forbidden** page, see
[Troubleshooting → 403 Forbidden](#403-forbidden-on-apihealth-or-admin) —
this is the single most common XAMPP-specific problem, because XAMPP's
default Apache config does not always allow `.htaccess` overrides.

---

## 12. Log into the admin panel

Open:
```
http://localhost/hostorio-ai-chatboot/admin
```

Log in with:
- **Username:** none required — just the password
- **Password:** the plain-text password you chose in step 8 (not the hash —
  the hash is what's stored, you type the real password to log in)

You should land on the **Dashboard**, showing conversation counts (all zero,
since nothing has talked to it yet) and a cost summary.

---

## 13. Add an AI provider API key

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

## 14. Add your first knowledge base article

Without any content, the chatbot has nothing to answer from. The fastest way
to test it is a manual note:

1. In the admin panel, go to **Knowledge base**
2. Click **Add note** (or similar — the exact wording is in the UI)
3. Title: `Test article`
4. Body: `Our support hours are 9am to 5pm UTC, Monday to Friday.`
5. Save it, then click **Re-index** (or run from Command Prompt):
   ```bash
   php tools/kb.php index
   ```

---

## 15. Test the chat widget

Open the demo page that ships with the widget:
```
http://localhost/hostorio-ai-chatboot/widget/demo.html
```

Click the chat bubble, type: **"What are your support hours?"**

You should get an answer back referencing the note you just added. If the
answer is generic or says it doesn't know, double check step 14 was
completed and re-indexed.

**You now have a fully working local install.** From here you can explore
**Routing** (which model handles which question type), **Conversations**
(transcripts), and the rest of the [ADMIN.md](ADMIN.md) guide.

---

## Troubleshooting

### 403 Forbidden on /api/health or /admin

This is the most common XAMPP problem, and it usually has **two causes
stacked together**:

**Cause 1 — `.htaccess` rules too broad.** Make sure your copy of the
project has the fix applied: open `api/.htaccess` and `admin/.htaccess` and
confirm they look like this (deny only `.php` files, not everything):
```apache
<FilesMatch "\.php$">
    Require all denied
</FilesMatch>
```
If they instead say plain `Require all denied` with no `<FilesMatch>`
wrapper, replace them with the block above.

**Cause 2 — XAMPP's Apache doesn't allow `.htaccess` overrides by default
in some versions.** Check `C:\xampp\apache\conf\httpd.conf`:

1. Open it in a text editor
2. Find the `<Directory "C:/xampp/htdocs">` block (search for `htdocs`)
3. Confirm it contains `AllowOverride All` — if it says `AllowOverride None`,
   change it to `AllowOverride All`
4. Save the file
5. Also confirm the rewrite module is enabled: search the same file for
   `LoadModule rewrite_module` — the line must **not** start with a `#`. If
   it does, remove the `#` and save
6. Restart Apache from the XAMPP Control Panel (**Stop**, then **Start**)

### Apache or MySQL won't start (port conflict)

XAMPP's Apache uses ports 80 and 443, which are also used by **Skype**,
**IIS**, and the Windows **"World Wide Web Publishing Service"** — a very
common conflict on Windows.

- If Apache's log (click **Logs** next to Apache in the Control Panel) shows
  `Port 80 in use`, either:
  - Close the other program (Skype's newer versions have a setting to stop
    using ports 80/443 under Advanced settings), **or**
  - Change XAMPP's Apache port: Control Panel → **Config** (next to Apache)
    → **httpd.conf** → change `Listen 80` to `Listen 8080`, and change
    `ServerName localhost:80` to `ServerName localhost:8080`. Then every URL
    in this guide becomes `http://localhost:8080/...` instead of
    `http://localhost/...`
- MySQL conflicts (port 3306) are usually a previously installed MySQL
  service, or Laragon still running in the background — stop the other one
  first, or change XAMPP's MySQL port under **Config** → `my.ini`.

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
1. Open phpMyAdmin (`http://localhost/phpmyadmin`)
2. Click your database in the left panel → **Operations** tab →
   **Drop the database** (at the bottom), or select it and use the
   **Drop** link from the database list
3. Recreate it exactly as in [step 6](#6-create-the-database), double
   checking the collation is `utf8mb4_unicode_ci`
4. Run `php tools/install.php` again

If it still fails on the same table, run the three schema files manually in
phpMyAdmin instead (this bypasses whatever is going wrong with the PHP
statement splitter):
1. Open phpMyAdmin, click your database in the left panel
2. Click the **SQL** tab
3. Open `database/schema/install.sql` in a text editor, copy everything,
   paste into the SQL box, click **Go**
4. Repeat for `database/schema/phase3.sql`, then `database/schema/phase5.sql`,
   **in that order**
5. Confirm with:
   ```bash
   php tools/healthcheck.php
   ```

### "'php' is not recognized as an internal or external command"

PHP isn't on your PATH. See [step 5](#5-check-your-php-version) for how to
add `C:\xampp\php` to it, or prefix every command in this guide with the
full path: `C:\xampp\php\php.exe tools/install.php`.

### "could not generate answer" in the widget

Usually means no AI provider key is configured yet, or the key is invalid.
Re-check [step 13](#13-add-an-ai-provider-api-key), then look at the latest
log file for the real error:
```
storage/logs/app-YYYY-MM-DD.log
```
(open the most recent date in a text editor — errors from the provider API
show up there, e.g. an invalid key or an expired trial).

### Answers are generic / don't mention my content

Your knowledge base is empty or hasn't been re-indexed. Repeat
[step 14](#14-add-your-first-knowledge-base-article) and confirm
`php tools/kb.php index` reports at least one document indexed.

### "This installer must be run from the command line."

You opened `tools/install.php` in a browser instead of running it with
`php tools/install.php` in Command Prompt. These `tools/*.php` scripts are
CLI-only by design (they touch the database directly and are not meant to be
web-reachable).

### Page shows PHP source code instead of running it

Apache isn't processing PHP files, which means Apache isn't running, or
you're browsing to the file directly (`file://...`) instead of through the
web server (`http://localhost/...`). Confirm Apache is green in the XAMPP
Control Panel.

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
