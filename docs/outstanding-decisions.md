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
| D1 | **Which payment provider goes first** — Stripe, Paystack or PayFast. Suggest whichever Sinov8 already has a merchant account with. | Product / finance | First real provider adapter | Payments core (checkout, signed + confirmed webhooks, credits granted once) is provider-agnostic and tested against a fake provider. `PAYMENTS_ENABLED` lists the providers to offer. |
| D2 | **Per provider: can it charge and settle in USD for a South African entity?** PayFast is expected to be ZAR-only. Each provider's webhook signature scheme and server-to-server confirm endpoint must be checked against its current docs. | Finance + engineering | Each provider adapter | Nothing assumed; each adapter declares its charge currency. |
| D3 | **FX for ZAR-charging providers** — which exchange-rate source, how often it's refreshed, and the buffer added on top. | Finance | Any ZAR checkout | Admin-set `PAYMENTS_FX_ZAR_RATE` + `PAYMENTS_FX_ZAR_BUFFER`, quoted and stored per payment; no rate = ZAR checkout unavailable. |
| D4 | **Credit pricing** — markup, minimum charge per step, and each step's hold (`BILLING_MARKUP`, `BILLING_MIN_CHARGE_USD`, `BILLING_HOLD_*_USD`). Calibrate with `php artisan boschifai:billing-report`. | Finance | Switching on `BILLING_ENFORCE` | Unset: steps priced at cost, shadow mode. |
| D5 | **Credit packs** — sizes and prices. | Product / finance | Checkout | `BILLING_PACKS` (e.g. `starter:10:10,team:50:45`); none set = nothing can be bought. |
| D6 | **Compliance for prepaid credits** — VAT and tax invoices; SARB exchange-control rules for USD-priced sales; consumer-protection rules on credit expiry and refunds. | Compliance / finance | Charging any customer | Credits never expire. Refund and chargeback webhooks aren't handled yet (planned: a ledger reversal) — today they'd be recorded and ignored. |
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
| D11 | **Agent isolation (remaining Phase 0 gap)** — the agent can still read files the worker can read, including `.env`. Options for the team: run `claude` in a separate credential-less container, or put an API proxy in front that holds the key. Decide before platform credits go GA. | Engineering leads | Production multi-tenant use | Env allowlist, settings pinning and output redaction are in place. |
| D12 | **`BOSCHIFAI_CLAUDE_RESTRICTED_MODE`** — switch on? It confines file tools to the checkout and disables Bash, which changes what every step can do; needs a full pipeline run first. | Engineering | — | Off. |
| D13 | **SES region** — a South African region keeps invitees' addresses in the country (POPIA s72), if SES is offered there for the account; otherwise confirm the cross-border transfer is acceptable. | Compliance / ops | Production email | `SES_REGION` configurable. |
| D14 | **Data retention periods** — expired/accepted invitations (invitee emails), audit-log IP addresses, run transcripts (contain customer source), payment webhook payloads. | Compliance | Retention clean-up jobs | Everything kept indefinitely. |
| D19 | **How widely BoschifAI's AWS identity may assume roles** — `sts:AssumeRole` on any role (`arn:aws:iam::*:role/*`, relying on each customer's trust policy + external ID), or only roles with an agreed name prefix (e.g. `arn:aws:iam::*:role/BoschifAI*`, so customers must name their role that way). | Engineering leads / security | Production Bedrock | Not enforced in code; the IAM policy on `BOSCHIFAI_BEDROCK_PRINCIPAL_ARN` decides. Setup page suggests the name `BoschifAIBedrock`. |
| D15 | **How scheduled jobs run** — there's no scheduler. `boschifai:release-stale-holds` (and later reconciliation) need one: an ECS scheduled task, a `schedule:work` container, or cron. | Ops / engineering | Enforcement in production | Commands exist; run manually or from cron. |

## Decided

| # | Decision | Answer | Date |
|---|---|---|---|
| — | Sign-in method | Email + password (Fortify); GitHub sign-in optional later (see D10) | 2026-10-12 |
| — | Credit currency | USD (1 credit = US$1) | 2026-10-12 |
| — | Payment providers | Support Stripe, Paystack and PayFast behind one interface (order: D1) | 2026-10-12 |
| — | Laravel version | Upgraded to 12 (fixes the email-rule CRLF advisory) | 2026-10-12 |
| D17 | Encryption of stored provider keys | A dedicated key, `BOSCHIFAI_CREDENTIALS_KEY` (AES-256-GCM), with previous-key support and `boschifai:rotate-credentials-key` | 2026-10-13 |
| D18 | Platform fee for runs on a workspace's own key | No fee — own-key runs aren't charged | 2026-10-13 |
