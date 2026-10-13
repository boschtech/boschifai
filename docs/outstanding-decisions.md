# Outstanding decisions

Decisions the code is waiting on, who owns them and what each one blocks. Engineering keeps
working around them (behind flags, with configurable values or a sensible default) — nothing
here is decided by the code itself. When one is made, record the answer and the date under
**Decided** and remove it from the open list.

Owner = who has to make the call, not who implements it.

## Open

### Blocking customer billing

| # | Decision | Owner | Blocks | Current state in code |
|---|---|---|---|---|
| D6 | **Compliance for prepaid credits — still open:** (1) is VAT due when credits are bought or when used (VAT Act s10(18) vs s10(19); the *MTN* appeal outcome)? (2) is the R2.3m compulsory VAT threshold law yet — register voluntarily? (3) zero-rating foreign customers whose staff use the platform in SA (s11(2)(l)(iii)); (4) is ECB rate + 8% acceptable for the rand amount on invoices (SARS BGR 11)? (5) may credits be forfeited at 36 months, or must they be refunded (CPA s63)? (6) do USD-quoted, rand-settled sales need authorised-dealer confirmation? (7) invoice format and record keeping (s20, Tax Administration Act). Also not built yet: refunds (7-day cooling-off, billing errors) and invoices. | Tax practitioner / attorney | Charging customers at scale | Expiry is built (see Decided); the company isn't VAT-registered, so no VAT is charged. |
| D7 | **Per-run spend cap** — a dollar figure for `BOSCHIFAI_MAX_COST_PER_RUN_USD`. | Finance / engineering | Per-run cap feature | Config key exists, unused. |

### Product behaviour

| # | Decision | Owner | Blocks | Current state in code |
|---|---|---|---|---|
| D8 | **A step with no hold while billing is enforced** — run it uncharged and report it (fail-open), or refuse it (fail-closed)? | Product | — | Fail-open + error report. One-line change to flip. |
| D9 | **Deleting a run deletes its usage history** (`claude_invocations`), so usage reports lose those numbers. Options: archive instead of delete, or keep usage rows when a run is deleted. | Product / engineering | Accurate usage reporting once customers are billed | Charge records and ledger entries already survive a deleted run. |
| D10 | **When to open self-registration**, and whether to add **GitHub sign-in**. | Product | Self-serve sign-up | Invitation-only; new workspaces via `boschifai:create-user`. |
| D16 | **Should members see the credit balance?** Today only owners and admins see Billing; members only find out credits are low when a run pauses (they're told whom to ask). A read-only balance in the header is an option. | Product | — | Owners/admins only. |

### Security, privacy and operations

| # | Decision | Owner | Blocks | Current state in code |
|---|---|---|---|---|
| D11 | **Agent isolation (remaining Phase 0 gap)** — the agent can still read files the worker can read, including `.env`. Options for the team: run `claude` in a separate credential-less container, or put an API proxy in front that holds the key. Decide before platform credits go GA. | Engineering leads | Production multi-tenant use | Env allowlist, settings pinning and output redaction are in place. Note: with more than one worker on a machine, one workspace's agent could also read another workspace's Vertex key file while that step runs (`storage/app/private/ai-credentials`) — the same fix (isolated runner) covers it. |
| D12 | **`BOSCHIFAI_CLAUDE_RESTRICTED_MODE`** — switch on? It confines file tools to the checkout and disables Bash, which changes what every step can do; needs a full pipeline run first. | Engineering | — | Off. |
| D13 | **SES region** — a South African region keeps invitees' addresses in the country (POPIA s72), if SES is offered there for the account; otherwise confirm the cross-border transfer is acceptable. | Compliance / ops | Production email | `SES_REGION` configurable. |
| D14 | **Data retention periods** — expired/accepted invitations (invitee emails), audit-log IP addresses, run transcripts (contain customer source), payment webhook payloads. | Compliance | Retention clean-up jobs | Everything kept indefinitely. |
| D19 | **How widely BoschifAI's AWS identity may assume roles** — `sts:AssumeRole` on any role (`arn:aws:iam::*:role/*`, relying on each customer's trust policy + external ID), or only roles with an agreed name prefix (e.g. `arn:aws:iam::*:role/BoschifAI*`, so customers must name their role that way). | Engineering leads / security | Production Bedrock | Not enforced in code; the IAM policy on `BOSCHIFAI_BEDROCK_PRINCIPAL_ARN` decides. Setup page suggests the name `BoschifAIBedrock`. |
| D20 | **Production hosting for the first install** — a single VPS (`docs/deploy-vps.md`) means secrets live in a server-side `.env` rather than AWS SSM, PHP's built-in server fronts the app, there's one worker and no failover. Accept for a pilot, or choose a target (nginx + php-fpm; managed hosting such as ECS + EFS + RDS) before GA. Also: which data-centre region (POPIA s72). | Engineering leads / compliance | Production go-live | `docker-compose.prod.yml` + runbook for a VPS; nothing provisioned. |

## Decided

| # | Decision | Answer | Date |
|---|---|---|---|
| — | Sign-in method | Email + password (Fortify); GitHub sign-in optional later (see D10) | 2026-10-12 |
| — | Credit currency | USD (1 credit = US$1) | 2026-10-12 |
| — | Payment providers | Support Stripe, Paystack and PayFast behind one interface (order: D1) | 2026-10-12 |
| D1 | First payment provider | **Paystack.** Stripe isn't available to an SA-registered company (its SA signup routes to Paystack); PayFast stays a possible second provider (SnapScan/Zapper, USD display) | 2026-10-13 |
| D15 | How scheduled jobs run | Laravel's scheduler in a dedicated `scheduler` container (`php artisan schedule:work`); `refresh-exchange-rates` and `release-stale-holds` run every hour (at :30) | 2026-10-13 |
| D3 | Exchange rate for non-USD payments | The ECB's daily reference rate (cross rate per US dollar), refreshed daily by `boschifai:refresh-exchange-rates`, plus an **8% buffer** (`PAYMENTS_FX_ZAR_BUFFER`); stale (>4 days) or implausible (>5%/day) rates aren't used; an admin override exists. Set to 8% on 2026-10-13 (first set at 15%) — above typical card currency-conversion margins of a few percent, leaving room for bank fees and rand moves | 2026-10-13 |
| D2 | Can providers charge/settle in USD for an SA entity? | No (as of 2026-10-13): Paystack SA charges and settles in ZAR only (USD only for Kenya/Nigeria businesses); PayFast settles in ZAR (its multi-currency pricing only lets buyers pay in USD/EUR/GBP/AUD with a markup). Recheck if Paystack enables USD for SA | 2026-10-13 |
| — | How customers pay | Packs are priced in USD; the buyer chooses the currency to pay in from those the provider can charge (configurable per provider — ZAR for Paystack today) and sees the exact amount before paying | 2026-10-13 |
| — | Laravel version | Upgraded to 12 (fixes the email-rule CRLF advisory) | 2026-10-12 |
| D17 | Encryption of stored provider keys | A dedicated key, `BOSCHIFAI_CREDENTIALS_KEY` (AES-256-GCM), with previous-key support and `boschifai:rotate-credentials-key` | 2026-10-13 |
| — | Vertex authentication | Service-account key uploaded by the customer, stored encrypted (D17), written per step to an owner-only temp file; a token proxy with no stored key was considered and set aside | 2026-10-13 |
| D18 | Platform fee for runs on a workspace's own key | No fee — own-key runs aren't charged | 2026-10-13 |
| D5 | Credit packs | Defaults following the common prepaid-credit pattern (1 credit = US$1): **Starter** US$10 → 10 credits · **Team** US$50 → 55 (+10%) · **Business** US$100 → 115 (+15%) · **Scale** US$250 → 300 (+20%). Bonus credits come out of margin — revisit with D4 (markup). `BILLING_PACKS` overrides them | 2026-10-13 |
| D4 | Credit pricing | Defaults: **markup 2.0×** API cost (50% gross margin on usage — reported AI-product margins are ~50–60%; infrastructure gateways charge ~5% on top-ups, e.g. OpenRouter 5.5%, Cloudflare 5%), **minimum US$0.02** per step (no provider publishes one; Copilot charges US$0.04 per extra request), and **per-step holds** = highest cost seen × 2.0 rounded up (gap $2, plan $3, cases $2, code $9, fix $3, push $1, skills $2, knowledge base $4 — from 1–11 local runs per step; recalibrate with `boschifai:billing-report`). Each overridable in `.env` | 2026-10-13 |
| D6a | Credit expiry | **36 months** from purchase for everyone (CPA s63 minimum for consumers and businesses under R2m; US peers' 12 months would breach it), oldest credits used first, reminders 60 and 14 days ahead, held credits never expired mid-step | 2026-10-13 |
| D6b | VAT registration | The selling company is **not VAT-registered** yet: no VAT charged, plain invoices (no "Tax Invoice" wording) once invoices exist; switch on when registered (compulsory above R2.3m of taxable sales per 12 months from 1 Apr 2026 — confirm it's enacted) | 2026-10-13 |
