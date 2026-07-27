# Features and use cases

What this chatbot does, what it is for, and how to get the most out of it.

If you are installing it, start with [INSTALL.md](INSTALL.md). If you are running
it day to day, [ADMIN.md](ADMIN.md) is the operational guide. This document is
the "what is it for and is it worth it" one.

---

## Contents

- [What it is](#what-it-is)
- [What problem it solves](#what-problem-it-solves)
- [Feature reference](#feature-reference)
- [Use cases](#use-cases)
- [What it costs](#what-it-costs)
- [What it deliberately does not do](#what-it-deliberately-does-not-do)
- [Getting good answers](#getting-good-answers)
- [Security model](#security-model)
- [Deployment patterns](#deployment-patterns)

---

## What it is

An AI support assistant for web hosting companies. It sits on your website as a
chat bubble, answers questions from **your own documentation**, and — for
customers who are signed in — can see their actual account.

It runs on ordinary cPanel shared hosting. No VPS, no Composer, no Node.js, no
build step. Upload a zip, open the installer, done.

The design assumption throughout: **you are a hosting company, not a software
company.** Every decision favours "works on the hosting you already sell" over
"technically elegant".

---

## What problem it solves

Most hosting support volume is the same twenty questions. "How do I point my
nameservers?" "Why is my site showing a 500 error?" "How do I create an email
account?" "Why is my site suspended?"

Those are:

- **Repetitive** — the same answer, written again and again
- **Well documented** — you already have a knowledge base nobody reads
- **Time-sensitive** — a customer at 2am does not want to wait until morning
- **Cheap to get wrong in one direction, expensive in the other** — a vague
  answer wastes a minute; a confidently wrong answer about someone's live site
  costs a lot more

This chatbot answers them from your documentation, with the customer's real
account state attached, at roughly half a cent per answer.

**What it is not for:** replacing your support team. It is for removing the
repetitive tier-1 load so the team spends its time on the things that actually
need a human.

---

## Feature reference

### Answering from your own content

| Feature | What it means |
|---|---|
| **WordPress ingestion** | Pulls posts, pages and custom post types from your existing site. Your knowledge base becomes the chatbot's knowledge base. |
| **Manual notes** | Type things in directly — an outage, a promo, a policy change. Outranks published articles by default. |
| **Grounded answers** | The system prompt instructs the model to answer from your content and say so plainly when it cannot, rather than inventing cPanel steps that do not match your setup. |
| **Source citations** | Answers show which articles they came from, so a customer can read further and you can audit. |
| **Incremental re-indexing** | Driven by content hashes. A nightly sync where nothing changed costs nothing. |

### Knowing who is asking

| Feature | What it means |
|---|---|
| **WHMCS account context** | For a signed-in customer: their services, domains, unpaid invoices and recent tickets, live at question time. |
| **Signed identity tokens** | Identity is proved by an HMAC token minted on your server, never claimed by the browser. |
| **Anonymous mode** | Visitors who are not signed in get documentation answers and no account data. This is the default and it works fine. |
| **Ticket history awareness** | The model sees recent tickets, so it does not re-suggest a fix that already failed for that customer last week. |

### Cost control

| Feature | What it means |
|---|---|
| **Question classification** | Every question is sorted into simple / complex / action. Rule-based, so it costs nothing. |
| **Provider routing** | Each class goes to a different model. Ordinary questions on the cheap one, hard ones on the strong one. |
| **Automatic fallback** | If a provider fails or rate-limits, the next in the list takes over. |
| **Spend budgets** | Hourly and daily, with alerts at 80% and a dashboard banner. |
| **Per-answer cost tracking** | Every call recorded with tokens and cost, broken down by provider and by question type. |

### Taking action

| Feature | What it means |
|---|---|
| **Service status lookup** | The assistant can check whether a customer's service is active or suspended before speculating about why their site is down. |
| **Account actions** | Password resets and service restarts — **disabled by default**, and require you to implement the executor against your own WHM/cPanel API. |
| **Ownership verification** | Every action re-checks that the named domain belongs to the caller. |
| **Confirmation gate** | Destructive actions ask the customer first and proceed only on a clear yes. |
| **Full audit trail** | Every tool call recorded, including refusals. |

### The widget

| Feature | What it means |
|---|---|
| **One script tag** | Drops into any page. A WordPress plugin is included. |
| **Style isolation** | Renders in a shadow root, so your theme cannot break it and it cannot break your theme. |
| **Mobile** | Full-screen below 480px, because a 380px panel on a 360px phone is unusable. |
| **Branding** | Title, colours, position, welcome message, suggested questions — set centrally, overridable per page. |
| **Accessible** | Proper roles and labels, keyboard operation, respects reduced-motion. |

### Running it

| Feature | What it means |
|---|---|
| **Admin panel** | Dashboard, conversation transcripts, knowledge base, routing rules, settings. No code. |
| **Conversation export** | CSV, for attaching to a ticket or reviewing quality. |
| **Browser installer** | Two minutes, no SSH. |
| **Verified updates** | Checksum-verified, backed up, rollback available. |
| **Security self-check** | Audits an install and exits non-zero on anything critical. |

---

## Use cases

### 1. Deflecting the repetitive twenty

**The situation.** Half your ticket volume is questions your knowledge base
already answers, because customers do not read knowledge bases.

**How it works.** Index your existing WordPress documentation. The widget goes
on your site and your client area. Questions get answered from the same articles
a customer would have found themselves, in the format they actually wanted — a
direct answer rather than a page to read.

**What to watch.** The **Most asked** panel on the dashboard. A repeated theme
with no article behind it is your next article. Write it, re-index, and the
chatbot answers it from then on.

---

### 2. "Why is my site down?"

**The situation.** The single most common urgent question, and the answer is
usually mundane — a suspension over an unpaid invoice, an expired domain, a
misconfigured DNS record.

**How it works.** For a signed-in customer, the assistant already has their
service status and unpaid invoices in context. It can also look up the service
directly. So instead of "here are ten things that cause downtime", the answer is:

> Your Premium VPS on example.com is currently **suspended**. That is usually
> caused by an unpaid invoice — invoice #1234 for £49.00 was due on 1 July and is
> overdue. Paying it reactivates the service automatically, usually within ten
> minutes.

**Why it matters.** That question would otherwise become a ticket, a reply, and
a follow-up. Here it is resolved before anyone on your team sees it.

---

### 3. Out-of-hours cover

**The situation.** You do not staff support at 3am, but customers still have
problems at 3am.

**How it works.** The chatbot answers overnight from your documentation and their
account state. Customers who genuinely need a human still open a ticket, but
they open it having already ruled out the obvious causes — and often with the
answer in hand instead.

**Setting it up.** Nothing special. Consider a manual note explaining your
support hours and what happens to tickets raised overnight, so the assistant can
set expectations accurately.

---

### 4. Communicating an outage

**The situation.** A datacentre goes down at 14:00. For the next two hours,
every customer on those servers asks the same question, and your documentation
says everything is fine.

**How it works.** Add a manual note:

> **Frankfurt datacentre maintenance**
> DC3 is offline for emergency maintenance until approximately 16:00 UTC. Sites
> on web05–web09 are affected. No action is needed from customers; service will
> restore automatically.

Set it to expire in three hours. Re-index. Every affected customer now gets an
accurate, current answer — and because notes outrank articles, it beats any
guide saying the platform is healthy.

**Why this feature exists at all.** Your website is always behind reality. The
manual channel is how the chatbot learns something twenty minutes old.

---

### 5. Pre-sales questions

**The situation.** A visitor is deciding whether to buy. They want to know about
migrations, backups, SSL, refunds.

**How it works.** The widget runs for anonymous visitors too. Index your product
and policy pages and it answers pre-sales questions with your actual terms —
including the awkward ones people do not want to open a ticket to ask.

**Setting it up.** Set the suggested questions to pre-sales ones on your
marketing pages, and support ones in the client area. Suggestions are
per-page-overridable for exactly this.

---

### 6. Reseller or multi-brand

**The situation.** You run several brands, or resell to agencies who want their
own branding.

**How it works.** Each brand gets its own install — the package is designed to be
distributed. Each has its own database, knowledge base, branding and API keys, so
costs and content stay separate.

For one install serving several sites, per-page `data-` attributes override the
central branding, so the same backend can appear in different colours with
different welcome messages.

---

### 7. Reducing ticket handling time

**The situation.** Some tickets are unavoidable, but they arrive with no context
and the agent spends five minutes gathering it.

**How it works.** Export the conversation as CSV and attach it to the ticket. The
agent sees what the customer already tried, what the assistant told them, and
what account state it saw — before they start.

---

## What it costs

Two costs: the AI provider (per answer), and hosting (nothing extra — it runs on
the hosting you already have).

### Per-answer economics

An answer costs input tokens (system prompt + retrieved documentation + account
context + conversation history) plus output tokens.

A typical simple answer sends around 1,500 input and 200 output tokens. A complex
one with more documentation attached might be 2,500 in and 400 out.

Using the rates in `config/pricing.php`:

| Question type | Model | Approx. cost per answer |
|---|---|---|
| Simple | DeepSeek | **~$0.0005** |
| Complex | Claude Opus 4.8 | **~$0.023** |
| Complex | Claude Haiku 4.5 | **~$0.0045** |

### A worked example

1,000 questions a month, 80% simple and 20% complex, default routing:

```
800 simple  x $0.0005  =  $0.40
200 complex x $0.023   =  $4.50
                          -----
                          ~$4.90 / month
```

Roughly **five dollars per thousand answered questions**. If those questions
would otherwise have been tickets, the comparison is not close.

**Routing complex questions to Haiku instead** brings the same 1,000 questions to
about **$1.30**. Whether that is a good trade depends on your documentation
quality — try it and watch the transcripts.

These figures come from the rate table in `config/pricing.php`; verify them
against your providers' current pricing, and treat your own dashboard as the
authority once real traffic exists.

### What actually drives the bill

In order of impact:

1. **Conversation memory.** Every replayed turn is re-billed on every message. Ten
   turns is generous; five is usually enough.
2. **Context budget.** More attached documentation means more input tokens per
   answer.
3. **Routing.** Sending simple questions to an expensive model is the classic way
   to get a surprising invoice.
4. **Extended thinking** on complex questions — better diagnoses, noticeably more
   expensive.

Set `COST_DAILY_BUDGET` and `COST_HOURLY_BUDGET` before going live. The hourly
one is what catches abuse: daily spend looks normal right up until it does not.

---

## What it deliberately does not do

Being straight about the boundaries, because a chatbot that oversells itself
creates the tickets it was meant to prevent.

**It does not perform account actions out of the box.** Password resets and
service restarts require you to implement the executor against your own WHM or
cPanel API. Until you do, they refuse and say so plainly. A chatbot that
confidently reports a password reset that never happened costs more trust than
one admitting the feature is off.

**It does not stream token-by-token.** The reply arrives in one response and is
revealed progressively, which reads like live typing. Real streaming needs
server behaviour that shared hosts routinely break through output buffering.

**It does not invent answers when your documentation is thin.** By design. If
the knowledge base does not cover something, it says so and offers a ticket.
That means **the quality of your answers is the quality of your documentation** —
which is a feature, but it does mean an empty knowledge base gives a
disappointing chatbot.

**It does not read encrypted WHMCS fields.** WHMCS encrypts some client data with
its own key, unreadable over a raw SQL connection. Nothing here depends on those
fields.

**It is not a ticket system.** It answers questions and hands off. Conversations
are stored and exportable, but there is no assignment, SLA or queue.

**Semantic search is optional and off by default.** Anthropic offers no
embeddings endpoint, so semantic search needs a third API key. Without it,
retrieval uses full-text search — which handles a hosting FAQ well, because
customers use the same words as your documentation ("SSL", "cPanel",
"nameserver"). Turn it on if your articles are phrased differently from how
customers ask.

---

## Getting good answers

The single biggest lever is your documentation. Beyond that:

**Index the right post types.** Hosting companies usually keep real documentation
in a custom post type — `kb_article`, `docs`, `faq`. Add yours in
`config/knowledge.php` or nothing useful gets indexed.

**Watch the first week of transcripts.** Admin → Conversations. You will find
questions phrased in ways your articles do not match, and answers that are
technically right but unhelpful. Both are fixable by writing.

**Use notes for anything time-bound.** Outages, promos, migrations, price
changes. Set an expiry so they retire themselves.

**Write suggested questions that match the page.** A blank chat box gets far
fewer first messages than one showing three things it can answer.

**Keep articles focused.** Retrieval works in passages. One page covering ten
topics retrieves poorly for all ten; ten focused pages retrieve well.

**Re-index on a schedule.** A nightly cron costs nothing when nothing changed:

```
0 3 * * * /usr/local/bin/php /home/USER/public_html/tools/kb.php index
```

---

## Security model

Worth understanding before you connect it to live customer data.

**Customer identity is proved, never claimed.** A `customer_id` in a request is
ignored. Only an HMAC-signed token or a server-side session counts. Without this,
changing one number in a browser would expose another customer's billing data.

**WordPress and WHMCS are read-only.** Enforced in code before a query reaches
MySQL, and you should also use SELECT-only database users. The chatbot writes
only to its own database.

**Retrieved content is treated as untrusted.** Ticket subjects are written by
customers and articles by anyone with an editor account. Section delimiters are
neutralised so that text cannot forge prompt structure, and the system prompt
states that retrieved content is data rather than instructions.

**Model output is never rendered as markup.** The widget builds DOM nodes, so a
prompt injection cannot become stored XSS on your site.

**Actions have three gates.** Verified identity, proven ownership of the named
resource, and explicit customer confirmation. Anonymous visitors are offered no
tools at all — which means a prompt injection has nothing to invoke.

**Secrets stay out of logs and pages.** API keys are masked before anything is
written to disk, and the admin panel shows whether a key is set but never its
value.

Run `php tools/security-check.php` before handing an install to customers. It
exits non-zero on anything critical.

---

## Deployment patterns

### Single hosting company (most common)

One install on a subdomain like `support.example.com`, connected to your
WordPress and WHMCS. Widget on your marketing site and client area.

### Per-brand installs

One install per brand, each with its own database and keys. Costs and content
stay separate, and each brand can route to different models.

### Distributed to your customers

Package it and let your hosting customers install it on their own accounts for
their own sites. They bring their own API key, so the cost sits with them. The
installer, updater and security check exist for exactly this.

### Subdirectory install

Works at `example.com/chatbot` as well as at a domain root. Routes and the admin
panel adjust automatically.

---

## Where to go next

- **[INSTALL.md](INSTALL.md)** — getting it running
- **[ADMIN.md](ADMIN.md)** — day-to-day operation, routing, costs
- **[TROUBLESHOOTING.md](TROUBLESHOOTING.md)** — when something is wrong
- **[../README.md](../README.md)** — technical architecture and design decisions
