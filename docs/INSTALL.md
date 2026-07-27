# Installation

For a hosting customer installing on their own cPanel account. No SSH needed.

**Time:** about 10 minutes, most of it waiting for an upload.

---

## Before you start

You need:

- A cPanel account with **PHP 8.1 or newer**
- One **API key** from an AI provider (see below)
- About 5 MB of disk space

You do **not** need SSH, Composer, Node.js, or a VPS.

### Choosing a provider

You only need one key to start.

| Provider | Roughly | Good for |
|---|---|---|
| **DeepSeek** | cheapest | Ordinary support questions — most of your volume |
| **Claude** | mid | Account actions and tricky troubleshooting |
| **OpenAI** | mid | An alternative if you already have an account |

Start with DeepSeek. You can add Claude later from the admin panel; the chatbot
will then send routine questions to the cheap model and anything that needs an
account action to Claude automatically.

---

## Step 1 — Create a database

In cPanel, open **MySQL® Databases**.

1. Under *Create New Database*, enter `chatbot` and press **Create Database**.
   cPanel will prefix it with your account name, e.g. `myaccount_chatbot`.
2. Under *Add New User*, create a user and a strong password. **Write the
   password down** — cPanel will not show it again.
3. Under *Add User To Database*, select the user and the database, press **Add**,
   tick **ALL PRIVILEGES**, and press **Make Changes**.

You now have three values the installer needs: database name, username, password.

---

## Step 2 — Upload the files

1. In cPanel, open **File Manager**.
2. Go to the folder you want the chatbot to live in:
   - **Whole domain:** `public_html`
   - **Subdomain** (recommended, e.g. `support.example.com`): the folder that
     subdomain points at
   - **Subfolder** (e.g. `example.com/chatbot`): create `public_html/chatbot`
3. Press **Upload** and select the zip file.
4. Back in File Manager, right-click the uploaded zip and choose **Extract**.
5. The zip contains one folder. Open it, select everything inside, and **Move**
   it up one level so the files sit directly in your chosen folder — not nested
   inside an extra directory.

You should end up with `index.php`, `admin/`, `public/` and the rest directly in
the folder.

---

## Step 3 — Run the installer

Open this in your browser:

```
https://your-domain.com/install.php
```

If you installed into a subfolder, include it:
`https://your-domain.com/chatbot/install.php`

The installer checks the server, then asks for:

- **Database** — the three values from Step 1. Host is almost always `localhost`.
- **AI provider and API key** — paste the key you obtained.
- **Website address** — the address you are installing at.
- **Admin password** — you will use this to sign in at `/admin`.

Press **Install**. It creates the database tables and writes your configuration.

---

## Step 4 — Delete the installer

**This matters.** The installer can rewrite your configuration and create
database tables. Leaving it in place is a security risk.

In File Manager, delete `public/install.php`.

The chatbot's own security check will flag it as critical until you do.

---

## Step 5 — Connect your content

Sign in at `https://your-domain.com/admin`.

### WordPress (optional but recommended)

This is what lets the chatbot answer from *your* documentation rather than
general knowledge about web hosting.

Go to **Settings** and add your WordPress database details. For safety, create a
**read-only** MySQL user for it — in phpMyAdmin, run:

```sql
CREATE USER 'chatbot_ro'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT SELECT ON your_wordpress_db.* TO 'chatbot_ro'@'localhost';
FLUSH PRIVILEGES;
```

The chatbot refuses to write to this database in code as well, but a read-only
grant is the real safety net.

Then open **Knowledge base** and press **Re-index**.

### WHMCS (optional)

Add your WHMCS database details the same way, with the same read-only user
approach. This lets the chatbot see a signed-in customer's own services,
invoices and tickets — so "why is my site down?" can be answered with "your
service is suspended because invoice #1234 is overdue".

---

## Step 6 — Add the widget to your site

Paste this before `</body>` on any page:

```html
<script src="https://your-domain.com/widget/widget.js"
        data-endpoint="https://your-domain.com/api/chat"
        defer></script>
```

### WordPress

Easier: copy `integrations/wordpress/hostorio-chatbot.php` into
`wp-content/plugins/`, activate it, and set your chatbot URL under
**Settings → Hostorio Chatbot**.

---

## Step 7 — Check it over

If you have SSH or cPanel's Terminal:

```bash
php tools/security-check.php
```

It reports anything unsafe about this particular install — debug left on, a
weak key, the installer still present, a database user with more privileges than
it needs. Fix anything marked CRITICAL before you send customers to it.

---

## Where things are

| Path | What it is |
|---|---|
| `/admin` | Admin panel |
| `/api/chat` | The chat endpoint the widget calls |
| `/widget/widget.js` | The widget |
| `.env` | Your configuration and secrets — never share it |
| `storage/logs/` | Logs, pruned automatically after 30 days |

---

## Common problems

**"Could not connect to that database"** — on cPanel the database and username
are both prefixed with your account name. If you typed `chatbot`, the real name
is probably `myaccount_chatbot`.

**Blank page** — usually PHP is older than 8.1. Change it in cPanel under
**MultiPHP Manager**.

**"The application directory is not writable"** — in File Manager, right-click
the folder, choose **Change Permissions**, and set it to 755.

More in [TROUBLESHOOTING.md](TROUBLESHOOTING.md).
