# Sawirpixel

Image generation for teams, built on the [Pixelforge](https://pixelforge.promptedit.dev) API for the
PromptEdit Senior Product Engineer assessment. Signed-in users write a prompt, see what it will cost
before they submit, and get charged what it actually cost (Pixelforge's cost plus a 25% markup), or
nothing if it fails. Operators get a profit report reconciled against Pixelforge's usage API.

> **This is a demo.** Credit purchases are simulated; no real payments are taken.

## Submission

| | |
|---|---|
| **Live app** | https://sawirpixel.sawirstudio.com |
| **Pixelforge account** | sawir.ricardo@gmail.com |
| **Reviewer login** | shared privately with the submission (it can spend real Pixelforge credits) |

The live app has its own Pixelforge key, used by nothing else, and a few generations made with it,
so its numbers can be compared one-to-one with Pixelforge's records: each generation's charge with
Pixelforge's usage for it, and the profit report (`/admin/reports/profit`) with `GET /v1/usage`.

**Stack:** Laravel 13 · PHP 8.5 · Inertia v3 + Vue 3 (TypeScript) · Tailwind v4 · Redis + Horizon · Pest

---

## Reviewer guide

### 1. Run it (about 5 minutes)

Requirements: PHP 8.5, Composer, pnpm, and Redis (`docker run -d -p 6379:6379 redis` works).

```bash
composer setup                  # install, .env, app key, SQLite database, migrations, front-end build
php artisan db:seed             # permissions, and test@example.com / password (verified)
```

Then set these in `.env`:

| Variable | Why |
|---|---|
| `PIXELFORGE_API_KEY` | Required to generate. |
| `PIXELFORGE_WEBHOOK_SECRET` | Verifies webhooks. Optional locally: polling works without them (see below). |
| `APP_ADMINS=test@example.com` | Comma-separated emails that can open `/admin/*` (profit report, users, Horizon). |

Start everything:

```bash
php artisan dev                 # web server, Horizon (queues), Vite, and logs
php artisan schedule:work       # second terminal: the 5-minute reconciliation job
```

Open http://localhost:8000. Emails (verification, password reset, invitations) go to
`storage/logs/laravel.log` with the default `MAIL_MAILER=log`.

**Webhooks are optional for a local review.** Every submitted generation is also polled every 10
seconds, and a scheduled job re-checks anything stale every 5 minutes, so images finish either way.
To receive real webhooks, expose the app (for example `cloudflared tunnel --url http://localhost:8000`)
and set `https://<tunnel>/webhooks/pixelforge` as the endpoint in Pixelforge's dashboard.

#### Or with Docker

`docker-compose.yml` runs the app, Horizon and the scheduler (`task`) from one image
(`sawirstudio/sawirpixel:latest`, `linux/amd64`, built on `serversideup/php:8.5-fpm-nginx-alpine`), plus
Redis. Settings come from `.env`; the compose file points the containers at Redis and keeps the SQLite
database in a shared volume. The app container runs the migrations on start.

```bash
docker compose up -d --build   # then open http://localhost:8080
docker compose exec app php artisan db:seed --force
```

### 2. A 10-minute walkthrough

Pixelforge's test directives make each scenario deterministic: put one at the start of a prompt.

| Directive | Effect |
|---|---|
| `[succeed]` | Never fails at random |
| `[fail]` | Fails during processing (not billed) |
| `[blocked]` | Rejected at submission: `400 content_policy_violation` |
| `[overloaded]` | Rejected at submission: `503 overloaded` (retried) |
| `[slow]` | Takes 60–90 seconds instead of 5–30 |
| `[duplicate]` | Every webhook event is delivered twice |

1. **Estimate before submitting.** As a guest, type a prompt on the home page and change size,
   quality, or add a reference image URL: the price range updates live. Press send to get the log-in
   dialog; your prompt is kept.
2. **Onboarding.** Log in as `test@example.com` / `password`, create an organization when asked, and
   buy credits from the balance chip in the header (simulated).
3. **Generate.** Send `[succeed] A lighthouse at dusk`. The page polls until it finishes, then shows
   the image, its estimated range, and the exact charge (hover the charge for full precision).
4. **Failures cost nothing.** Try `[fail] …`, `[blocked] …` and `[overloaded] …`. Each ends with a
   clear message and the credits set aside for it are returned. See *Failure modes* below.
5. **Threads.** On a finished image, describe a change ("make it stormier"). Use *Continue from here*
   on an older version to branch, then switch branches with `‹ 1/2 ›` or the versions map.
6. **History and credits.** The sidebar lists threads; `/generations` lists everything; `/credits` is
   the organization's ledger (purchases, amounts set aside and returned, charges).
7. **Operators.** As an `APP_ADMINS` user, open **Profit report** (`/admin/reports/profit`): revenue,
   Pixelforge cost and margin per day, reconciled against `GET /v1/usage`, with a CSV export. **Users**
   (`/admin/users`) shows every account's spend and balances. Horizon is at `/admin/horizon`.
8. **Teams.** In organization settings: invite members with roles, set spending and image limits,
   restrict qualities or sizes for members, or delete the organization.
9. **API.** In your profile, generate a personal access token (pick its organization, scopes and
   expiry; the secret is shown once), then call the API with it. The docs are at `/docs/api`.

   ```bash
   curl -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/credits
   curl -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
        -d '{"prompt":"[succeed] A lighthouse at dusk","size":"1024x1024","quality":"low"}' \
        http://localhost:8000/api/generations          # 202, with a Location header to poll
   ```

---

## How the requirements are met

### Pricing

- Rates and token tables come from Pixelforge's `GET /v1/pricing` (cached for an hour), not hard-coded.
- The estimate turns the prompt length, size, quality and reference images into a token range, prices
  it, and adds the markup (`PIXELFORGE_MARKUP`, default `1.25`). See
  `app/Services/Pricing/GenerationCostEstimator.php`.
- The final charge uses the usage and rates Pixelforge reports for that generation, so it's exact.
- All money is `brick/math` `BigDecimal`: provider costs to 9 decimal places (as Pixelforge reports
  them), charges to 10. It's never a float, and never SQL arithmetic (SQLite would use floats). It's
  rounded only for display.

### Asynchronous generations

- Submitting creates the generation and holds its maximum cost in one transaction, then queues
  `SubmitGeneration`, which sends it with a stored idempotency key so a retry can't create a second
  generation or a second bill.
- Results arrive by **webhook** (HMAC-verified against the raw body, with replay protection by event
  id) and by **polling** (`PollGeneration`, every 10 seconds). A scheduled `ReconcileGenerations` job
  catches anything that's gone quiet. All three go through one idempotent action,
  `ApplyPixelforgeGeneration`, so duplicates and out-of-order deliveries are harmless.

### Failure modes

| Situation | What happens |
|---|---|
| Content policy rejection | Failed at once with our own explanation; hold returned |
| Pixelforge overloaded / rate-limited (429, 503) | Retried, honouring `Retry-After`, within a shared 55 requests/minute limiter |
| At Pixelforge's concurrency limit (3 in flight) | Waits for a free slot instead of failing |
| Daily limit reached | Failed with an explanation; hold returned |
| Fails during processing | Marked failed when the result arrives; not charged |
| Never accepted after an hour of retries | Failed; hold returned |
| Duplicate, late or out-of-order webhooks | Ignored by the status machine (only forward moves apply) |
| Webhook missed entirely | Found by polling or the 5-minute reconciliation |
| Not enough credits, or over an organization limit | Refused before submitting, with the reason shown as you type |

### History

The sidebar shows one entry per thread. `/generations` has every image with its status, estimate,
actual charge and when its image link expires. `/credits` is the full ledger.

### Admin profit report

`app/Services/Reporting/ProfitReport.php` sums revenue and our recorded Pixelforge cost per day, and
compares each day with Pixelforge's `GET /v1/usage`, flagging any day that doesn't match.

### API

Personal access tokens (Sanctum) are managed GitHub-style in the profile: each is bound to one
organization and limited to its scopes (`generations:read`, `generations:create`, `credits:read`).
Responses follow JSON:API. Generating through the API uses the same action as the web app, so limits,
holds and charges behave identically; running out of credits answers `402 Payment Required`. Tokens
can only be managed from a logged-in session, never with a token.

| Endpoint | Scope |
|---|---|
| `GET /api/user` | any |
| `POST /api/estimates` | `generations:read` |
| `GET /api/generations`, `GET /api/generations/{id}` | `generations:read` |
| `POST /api/generations` | `generations:create` |
| `GET /api/credits` | `credits:read` |

### For agents

- **MCP:** `/mcp` is a Model Context Protocol server (streamable HTTP) using the same tokens and scopes,
  with the tools `estimate_price`, `generate_image`, `get_generation`, `list_generations` and
  `get_credit_balance`. It's described at `/.well-known/mcp/server-card.json`.
- **Discovery:** `/.well-known/api-catalog` (RFC 9727) points to the OpenAPI description at
  `/docs/api.json`, and public pages send `Link` headers to it, the server card and the sitemap.
- **Markdown:** the home page, terms and privacy policy answer `Accept: text/markdown` with Markdown,
  from the same files (`resources/markdown`) the web pages render.
- **robots.txt:** names the AI crawlers and sets Content Signals: `search=yes, ai-input=yes, ai-train=no`.

### Credits

Each organization has a balance and an append-only ledger (`credit_transactions`): purchase, hold,
hold release, charge. Unique indexes make each step happen at most once per generation, and the
balance is only changed with the organization's row locked (`app/Services/Billing/CreditLedger.php`).

---

## Where to look

| Area | Files |
|---|---|
| Pixelforge client and webhooks | `app/Services/Pixelforge/`, `app/Actions/HandlePixelforgeWebhook.php` |
| Generation lifecycle | `app/Enums/GenerationStatus.php`, `app/Jobs/`, `app/Actions/ApplyPixelforgeGeneration.php` |
| Pricing and money | `app/Services/Pricing/` |
| Credits | `app/Services/Billing/` |
| Limits | `app/Services/Generations/GenerationLimiter.php` |
| Threads (continue and branch) | `app/Services/Generations/GenerationThread.php` |
| Roles and permissions | `app/Models/Concerns/HasRoles.php`, `app/Enums/Permission.php`, `app/Policies/` |
| API and tokens | `routes/api.php`, `app/Http/Controllers/Api/`, `app/Http/Resources/`, `app/Actions/IssuePersonalAccessToken.php` |
| MCP server | `routes/ai.php`, `app/Mcp/` |
| Deployment | `Dockerfile`, `docker-compose.yml`, `deploy.sh` |
| Reporting | `app/Services/Reporting/` |
| Front end | `resources/js/pages/`, `resources/js/lib/` |

### State machines

The lifecycles that drive behaviour are explicit, each with its diagram in a docblock:

- **Generation status** (`app/Enums/GenerationStatus.php`): forward-only transitions, enforced on
  save, with a test pinning the full transition table. Mirrored for the front end in
  `resources/js/lib/generationStatus.ts`.
- **Invitations** (`app/Enums/InvitationStatus.php`): pending, accepted or expired, derived from
  `accepted_at` and `expires_at` so it can't go stale.
- **What "send" does** (`resources/js/lib/composerState.ts`): from "needs an account" to "ready",
  shared by both prompt boxes and handled with exhaustive switches.

---

## Tests and quality gates

```bash
php artisan test --compact      # 435 tests, on SQLite; all pass on Postgres too
composer test                   # everything below, then the suite
composer test:types             # PHPStan (Larastan, level max)
composer test:type-coverage     # 100% type coverage
composer test:lint              # Pint, Rector, vp fmt and vue-tsc
composer test:unit              # the suite with a 100% line coverage gate
```

Architecture tests (`tests/Unit/ArchTest.php`) enforce Pest's strict and security presets: final
classes, strict types, no protected methods, enums and exceptions in their own namespaces.

## Design decisions

- **Images aren't stored.** They load from Pixelforge's signed links, which expire after 24 hours;
  the app shows when each one expires, and a version can only be continued while its link is valid.
- **Hold the maximum, charge the actual.** Users never owe more than the estimate they saw.
- **Usage is derived, not counted.** Limits and the reconciliation read the generations and the
  ledger themselves, so a late failure or a replayed webhook can't leave a counter wrong.
- **Customers don't see the provider's internals.** The estimate endpoint returns only the price, and
  failure messages are our own wording. The provider's error code is kept for operators.
