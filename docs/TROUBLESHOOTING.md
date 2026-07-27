# Troubleshooting

Start here:

```bash
php tools/healthcheck.php      # is everything connected?
php tools/security-check.php   # is anything unsafe or misconfigured?
```

No SSH? cPanel's **Terminal** works, and most problems below can be diagnosed
from the admin panel and `storage/logs/`.

---

## The widget does not appear

**Check the browser console** (F12 → Console).

| What you see | Cause | Fix |
|---|---|---|
| `404` on `widget.js` | Wrong URL in the script tag | Confirm `https://your-domain/widget/widget.js` loads directly |
| CORS error | The widget is on a different domain from the chatbot | Set `APP_URL` to the chatbot's address, and add the site's origin to allowed origins |
| Nothing at all | Script tag not on the page | View source and confirm it is there, before `</body>` |

If `widget.js` 404s but the site works, your host may not be honouring
`.htaccess`. Point the domain's document root at the `public/` folder instead.

---

## "Sorry — I could not generate an answer just now"

The chatbot reached the point of asking a model and could not get one.

1. **Admin → Dashboard → Spend by provider.** A failure count means the provider
   is rejecting calls.
2. **`storage/logs/api-*.log`** shows the actual response.

| In the log | Meaning | Fix |
|---|---|---|
| `401` / `authentication_error` | Key is wrong or revoked | Re-enter it in Settings |
| `429` / `rate_limit_error` | Too many requests to the provider | Wait; the chatbot already retries with backoff |
| `400` … `temperature` | Model rejects sampling parameters | Clear Temperature on the Routing page |
| `credit` / `billing` | No balance with the provider | Top up your account |
| `No provider is enabled` | No API key configured | Add one in Settings |
| `budget_exceeded` | Your spending limit was hit | Raise it, or set `COST_HARD_STOP=false` |

---

## Answers are generic, or it says it has no documentation

The knowledge base is empty or stale.

1. **Admin → Knowledge base.** If *Chunks indexed* is 0, nothing is indexed.
2. Check WordPress is connected in **Settings**.
3. Press **Re-index**.

If it stays at 0 after re-indexing:

- The WordPress database details are wrong — `healthcheck.php` will say
  `schema recognised: no`.
- Your articles are shorter than the minimum length (200 characters after HTML
  is stripped).
- Your documentation is in a custom post type. Add it to
  `knowledge.wordpress.post_types` in `config/knowledge.php`.

**Articles built with Elementor or Divi** often store almost nothing in
`post_content` — the layout lives in metadata. Those pages will index as empty.
Put the text in the excerpt, or write a note instead.

---

## It does not know a customer's account

Almost always identity, not data.

1. In a transcript, the assistant will say it cannot see account details.
2. `storage/logs/app-*.log` will show `Unverified customer_id claim ignored` or
   `Identity token rejected`.

**A `customer_id` sent by the browser is never trusted.** That is deliberate:
otherwise changing one number would expose another customer's billing data. You
must mint a signed token server-side:

```php
$token = Hostorio\Context\IdentityToken::issue((int) $client->id);
```

Common causes of a rejected token:

- `APP_KEY` changed — every existing token is invalidated
- The token expired (one hour by default; mint it per page load)
- WHMCS is not connected in Settings

---

## The admin panel will not let me in

**"Incorrect password"** — reset it by generating a new hash and putting it in
`.env` as `ADMIN_PASSWORD_HASH`:

```bash
php -r "echo password_hash('new-password', PASSWORD_DEFAULT);"
```

**"Too many failed attempts"** — five failures locks your IP for 15 minutes.
Wait, or clear it in phpMyAdmin:

```sql
DELETE FROM hoai_rate_limits;
```

**"That form has expired"** — your session was lost. Reload and try again. If it
persists, PHP cannot write sessions; ask your host to check the session save
path.

**Redirects to login in a loop** — cookies are being dropped. Usually a mixed
http/https address; make sure you are using `https://`.

---

## Everything is slow

Answers take 2–8 seconds; most of that is the model, not the chatbot.

To reduce it:

- **Routing → Complex → Extended thinking off.** The biggest single saving.
- **Lower the answer length limit.** Long answers take proportionally longer.
- **Settings → Conversation memory.** Fewer replayed turns means less to read.

If it is much slower than that, check `avg ms` per provider on the dashboard. A
consistently slow provider is a provider problem, not a chatbot one.

---

## Costs are higher than expected

**Dashboard → Spend by provider.** If the expensive model is handling most
calls:

- **Routing** — is `claude` first for simple questions? It should be last.
- **Most asked** — are people asking the same things repeatedly? Write articles;
  a better knowledge base means shorter, cheaper answers.
- **Settings → Conversation memory** — every replayed turn is re-billed on every
  message. Ten is generous; five is usually enough.
- **Settings → Context budget** — attaching more documentation costs more per
  answer.

Set `COST_DAILY_BUDGET` and `COST_HOURLY_BUDGET` so a spike alerts you.

**Behind Cloudflare?** Rate limiting keys on the visitor's IP. Behind a proxy
every visitor appears to come from the proxy, so they share one bucket — which
both throttles real users and lets an abuser blend in. Add your proxy's IPs to
`security.trusted_proxies` in `config/security.php`.

---

## After an update

**"Something went wrong loading that page"** — migrations may not have run:

```bash
php tools/update.php --migrate
```

**Something is clearly broken** — roll back:

```bash
php tools/update.php --rollback
```

Then tell your provider what happened. The rollback restores the code, but if
migrations already ran the older code may not match the schema.

---

## Reading the logs

`storage/logs/`, one file per channel per day, pruned after 30 days.

| File | Contains |
|---|---|
| `app-YYYY-MM-DD.log` | Requests, identity, errors |
| `api-YYYY-MM-DD.log` | Every model call with tokens and cost |

Each line carries a request id. To follow one request:

```bash
grep 'a1b2c3d4' storage/logs/*.log
```

**API keys and passwords are masked before anything is written.** If you see a
real key in a log, that is a bug worth reporting.

---

## Still stuck

Collect this before asking for help:

```bash
php tools/healthcheck.php > diagnosis.txt
php tools/security-check.php >> diagnosis.txt
php tools/update.php >> diagnosis.txt
tail -50 storage/logs/app-*.log >> diagnosis.txt
```

**Read it before sending it.** It contains database names and your site
structure — no secrets, but not for public posting either.
