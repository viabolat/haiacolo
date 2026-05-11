# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Inherits global behavioral rules from `~/.claude/CLAUDE.md` (token discipline, self-annealing, response calibration). This file covers only project-specific context.

---

## Project Overview

HaiAcolo is a Romanian local-business loyalty and deals platform targeting Cluj. Users sign up to receive deal codes from local merchants; merchants get a QR-punch loyalty card system. The live site is at `haiacolo.ro`.

**Stack:** Vanilla HTML + Tailwind CDN frontend · PHP 8.3 backend (no framework) · Supabase (PostgreSQL + Edge Functions) · Resend (email) · Google reCAPTCHA v2 · Apache/cPanel hosting

---

## Key Commands

### Supabase / Database
```bash
# All schema changes must go through migrations — never edit directly in Supabase SQL Editor
supabase migrations new <description>   # create migration file
supabase db push                         # apply pending migrations to remote DB
supabase db remote changes               # diff remote vs local migrations
supabase functions deploy <name>         # deploy a specific Edge Function
supabase secrets set KEY=VALUE           # set a remote secret
supabase secrets list                    # list secret names (not values)
```

### Python Utilities
```bash
source .venv/bin/activate
python deal_matcher.py        # match deals to customers
python whatsapp_sender.py     # send WhatsApp notifications
python update_sitemap.py      # regenerate sitemap.xml
```

---

## Architecture

### Two PHP API Layers (both active, different patterns)

**1. Root `/api.php`** — legacy monolithic API. All routes are `if` blocks in one file. Functions use unnamespaced names (`supabase_insert`, `execute_curl_request`). Handles: user signup, business signup, deal codes, push subscriptions, outreach emails.

**2. `/loyalty/api.php`** — standalone loyalty API with the same monolithic pattern. Handles loyalty-specific routes directly.

**3. `/api/` directory** — newer modular API (migration in progress). Uses `ha_` function prefix, a `lib/` layer, and a router. New features should be added here.
- `api/lib/bootstrap.php` — loads `secrets.php`, validates cURL, provides `ha_require_secrets()`
- `api/lib/router.php` — `ha_dispatch_routes()` dispatcher
- `api/lib/http.php` — `ha_json_response()`, `ha_read_json_body()`, `ha_execute_curl_request()`
- `api/lib/supabase.php` — `ha_supabase_insert/select/update/rpc()` + email helpers
- `api/lib/validation.php` — `ha_validate_email()`, `ha_validate_phone()`
- `api/routes/` — one file per domain (loyalty, merchants, user, business, push, outreach, redemptions, config)

### Secrets
`secrets.php` lives **one directory above the web root** (`../secrets.php` relative to any PHP file). It is never committed. Required globals: `$supabaseUrl`, `$supabaseAnonKey`, `$supabaseKey` (service role), `$recaptchaSecretKey`, `$resendApiKey`, `$appBaseUrl`.

### Database Schema (core tables)
- `merchants` — businesses; `subscription_status` lifecycle: `pending_approval → active`; `is_welcome_offer` flags the merchant that provides the signup gift code
- `customers` — users identified by `phone_number` (unique); upserted on signup
- `loyalty_programs` — one per merchant; `type`, `punches_required`, `reward_description`
- `loyalty_cards` — join table between `customers` and `loyalty_programs`; `current_punches` incremented by `punch_loyalty_card` RPC
- `codes` — deal codes with `status: issued → used`; `code_string` is human-readable (e.g. `URANIA-ABC123`)
- `deal_history` — per-merchant redemption records
- `sent_outreach_emails` — dedup table with UNIQUE on `recipient_email`; 409 = already sent
- `push_subscriptions` — Web Push endpoint + keys

### Supabase RPC Functions (called from PHP)
- `create_customer_and_loyalty_card(p_merchant_id, p_full_name, p_phone_number)` — atomic upsert
- `punch_loyalty_card(p_loyalty_card_id)` — increments punches, returns result
- `process_code_redemption(p_code_string)` — marks loyalty reward code as used

### Edge Functions (TypeScript/Deno in `/supabase/functions/`)
- `user-signup-alert` — notified by PHP after new customer signup
- `merchant-application-alert` — notified by PHP after new merchant applies
- `handle-merchant-approval` — triggered on merchant approval flow

### Frontend
Static `.html` pages served by Apache with `.htaccess` clean URL rewriting (strips `.html` extension). Shared styles in `includes/styles.css` (Poppins font, gradient utilities). HTML pages include `includes/header.html` / `includes/footer.html` via JS in `includes/loader.js`.

Key pages: `index.html` (landing), `user.html` (signup), `business.html` (merchant apply), `loyalty/` (full loyalty card flow for end users), `merchant_dashboard.html` (merchant admin), `verify.html` / `offer.html` (code redemption).

### URL Routing
Routes are passed as `?route=/api/...` query strings to the PHP files. `.htaccess` rewrites clean paths; PHP reads `$_GET['route']`.

---

## Frontend Verification Protocol

After any HTML/CSS/JS change, before reporting done:
1. **Fetch the live site** (`https://haiacolo.ro`) using `WebFetch` to get a rendered snapshot.
2. **Check key pages** affected by the change (e.g. index, user, business, loyalty/).
3. **Verify**: target section is present, no orphaned text visible, no obvious layout breaks.
4. Self-anneal any issue found before committing. Do not commit broken HTML.

> Rationale: WSL2 cannot reach Windows localhost, so live `haiacolo.ro` is the only reliable rendering target for verification.

---

## Database Migration Rules

See `supabase.md` for the full workflow. The critical rule: **`supabase/migrations/` is the sole source of truth for schema**. Never use the Supabase SQL Editor for schema changes.

Migration naming convention: `supabase migrations new <snake_case_description>`
