# Running the chatbot

The admin panel is at `/admin`. Everything here can be done without touching
code.

---

## Dashboard

Answers the questions you will actually ask:

- **Spend (30 days)** and **cost per answer** — what it costs to run.
- **Spend by provider** — whether the routing rules are earning their keep. If
  most calls go to the expensive model, your routing needs a look.
- **Daily spend** — a bar per day. A spike here is worth investigating.
- **Where the questions go** — how traffic splits between simple, complex and
  action questions.
- **Most asked** — repeated questions. *A theme here with no article behind it
  is usually worth writing one for.*
- **Recent tool activity** — every lookup and action, including refusals.

A banner appears if you are approaching or over a spending budget.

---

## Conversations

Search across titles and message text, then open a transcript. Each assistant
message shows which provider answered, the token counts, and what it cost.

**Export CSV** downloads the transcript for a support ticket or a review.

Cells beginning with `=`, `+`, `-` or `@` are prefixed with an apostrophe so
spreadsheets do not execute them — a customer can type a formula into a chat.

---

## Knowledge base

### Re-index

Pulls the latest WordPress content and indexes anything that changed.
Unchanged documents are skipped, so running it when nothing has been edited
costs nothing.

Run it after:

- publishing or editing a WordPress article
- adding or editing a note
- changing the embeddings setting

Consider a nightly cron job:

```
0 3 * * * /usr/local/bin/php /home/USER/public_html/tools/kb.php index
```

### Notes

Notes are for **what the website has not caught up with** — an outage that
started twenty minutes ago, a promo that ends Friday, a policy that changed this
morning.

They outrank published articles by default (priority 10 vs 0), which is the
point: a note saying a datacentre is down must beat a guide saying everything
is fine.

- **Priority** — higher wins. Leave at 10 unless a note must beat another note.
- **Expires** — accepts `+6 hours`, `+7 days` or `2026-08-01 14:00`. Leave blank
  to keep it until you retire it.
- **Retire** — removes it from search immediately, keeps the text, reversible.
- **Delete** — permanent.

Notes must be re-indexed before they are searchable. The list shows **PENDING**
until then.

---

## Routing

This is the cost lever. Each question type has an ordered list of providers; the
first one that is enabled and capable answers, and if it fails the next takes
over.

| Type | Default | Why |
|---|---|---|
| **Action** | Claude → OpenAI | Needs reliable tool calling. A fumbled password reset costs a support ticket, worth far more than the tokens saved. |
| **Complex** | Claude → DeepSeek → OpenAI | A wrong diagnosis creates the ticket the chatbot existed to prevent. |
| **Simple** | DeepSeek → OpenAI → Claude | The bulk of volume, on the cheapest model. |

**To cut costs:** put `deepseek` first for complex questions and watch the
dashboard for a week. If answer quality holds, keep it.

**To improve answers:** put `claude` first for complex and enable extended
thinking.

Other settings:

- **Answer length limit** — answers cut off at this length are flagged
  incomplete in the transcript.
- **Temperature** — low keeps support answers consistent. Blank sends none.
- **Long-message threshold** — messages longer than this are treated as
  troubleshooting, because people write at length when something is wrong.

Saving a type with no providers is refused — that would take the chatbot offline
for those questions.

---

## Settings

### API keys

Write-only. The panel shows *whether* a key is set, never the value. Leaving a
field blank keeps the current key; it does not delete it.

### Embeddings

Optional. Off by default, and that is a reasonable place to leave it: without
embeddings the knowledge base uses full-text search, which handles a hosting FAQ
well because customers use the same words as your documentation.

Turning it on needs an OpenAI key (Anthropic does not offer embeddings) and
improves retrieval for questions phrased differently from your articles.
**Re-index after changing it.**

### Chat behaviour

- **Conversation memory** — how many previous turns are replayed. Every replayed
  turn is re-billed on each message, so this multiplies cost directly.
- **Context budget** — how much retrieved documentation is attached per answer.
- **Look things up** — lets the assistant check service status. Only ever
  offered to signed-in, verified customers.
- **Allow actions that change things** — password resets and restarts. See below.

### Widget appearance

Title, subtitle, welcome message, accent colour, position, and the suggested
questions shown on an empty chat. Suggestions matter more than they look: a
blank box gets far fewer first messages than one showing what it can answer.

---

## Letting customers act on their account

Password resets and service restarts ship **disabled**, and enabling the setting
is not enough on its own. You must also implement `ActionExecutorInterface`
against your own WHM/cPanel API — see `chat/Tools/ActionExecutorInterface.php`.

Until you do, the tools refuse and say so plainly. That is deliberate: a chatbot
that confidently reports a password reset that never happened costs more trust
than one admitting the feature is off.

When enabled, every action still passes three checks:

1. The customer must be **signed in and verified** — a token, not a claim.
2. The service must **belong to them** — re-checked against their own service
   list, because the model picks the domain from conversation text and can get
   it wrong.
3. The customer must **explicitly confirm** — the assistant asks first, and only
   proceeds on a clear yes.

Everything is recorded, refusals included.

---

## Watching your spend

Set budgets in `.env`:

```
COST_DAILY_BUDGET=5
COST_HOURLY_BUDGET=1
```

You get a dashboard banner and a log alert at 80% and again when exceeded.

`COST_HARD_STOP=true` refuses new requests once a budget is exceeded. It is off
by default because a hard stop turns a billing problem into an outage for every
customer — usually the worse of the two. Turn it on only if an unbounded bill is
genuinely worse than being offline.

---

## Signing customers in

By default the widget treats everyone as anonymous: it answers from your
documentation but knows nothing about their account.

To let it see a customer's own services and invoices, mint a signed token in a
page that has already authenticated them:

```php
$token = Hostorio\Context\IdentityToken::issue((int) $client->id);
```

Pass it to the widget as `data-token`. A `customer_id` sent by the browser is
**never** trusted — otherwise changing one number would expose another
customer's billing data.

---

## Keeping it updated

```bash
php tools/update.php --check     # is there a newer version?
php tools/update.php --apply     # download, verify, install, migrate
php tools/update.php --rollback  # undo the last update
```

Updates are verified against a checksum and backed up before anything is
replaced. Your `.env` and `storage/` are never touched.

After updating, run `php tools/security-check.php`.
