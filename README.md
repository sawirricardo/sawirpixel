# Sawirpixel

Sawirpixel is image generation for teams, built on the [Pixelforge](https://pixelforge.promptedit.dev)
API for the PromptEdit Senior Product Engineer assessment. Signed-in users write a prompt and see what
it will cost before they submit. They pay what the image actually cost, which is Pixelforge's cost plus
a 25% markup, or nothing if it fails. Operators get a profit report that reconciles against
Pixelforge's usage API.

> **This is a demo.** Credit purchases are simulated, so no real payments are taken.

## Submission

| | |
|---|---|
| **Live app** | https://sawirpixel.sawirstudio.com |
| **Pixelforge account** | sawir.ricardo@gmail.com |
| **Reviewer login** | Shared privately with the submission, because it can spend real Pixelforge credits. |

The live app has its own Pixelforge key, which nothing else uses, and a few generations made with it.
You can compare its numbers one-to-one with Pixelforge's records. Each generation's charge matches
Pixelforge's usage for it, and the profit report at `/admin/reports/profit` matches `GET /v1/usage`.

**Stack.** Laravel 13, PHP 8.5, Inertia v3 with Vue 3 and TypeScript, Tailwind v4, Redis with Horizon,
and Pest.

---

## Reviewer guide

### 1. Run it in about 5 minutes

You need PHP 8.5, Composer, pnpm and Redis. `docker run -d -p 6379:6379 redis` gives you Redis.

```bash
composer setup                  # install, .env, app key, SQLite database, migrations, front-end build
php artisan db:seed             # permissions, and test@example.com / password (verified)
```

Then set these in `.env`.

| Variable | Why |
|---|---|
| `PIXELFORGE_API_KEY` | Generating needs it. |
| `PIXELFORGE_WEBHOOK_SECRET` | The app verifies webhooks with it. You can skip it locally, because polling works without webhooks. |
| `APP_ADMINS=test@example.com` | These comma-separated emails can open `/admin/*`, which holds the profit report, the user list and Horizon. |

Start everything.

```bash
php artisan dev                 # web server, Horizon (queues), Vite, and logs
php artisan schedule:work       # second terminal: the 5-minute reconciliation job
```

Open http://localhost:8000. With the default `MAIL_MAILER=log`, emails for verification, password
resets and invitations go to `storage/logs/laravel.log`.

**Webhooks are optional for a local review.** The app also polls every submitted generation every 10
seconds, and a scheduled job rechecks anything stale every 5 minutes, so images finish either way. To
receive real webhooks, expose the app with a tunnel such as `cloudflared tunnel --url
http://localhost:8000`. Then set `https://<tunnel>/webhooks/pixelforge` as the endpoint in
Pixelforge's dashboard.

#### Or with Docker

`docker-compose.yml` runs the app, Horizon and the scheduler (`task`) from one image, plus Redis. The
image is `sawirstudio/sawirpixel:latest` for `linux/amd64`, built on
`serversideup/php:8.5-fpm-nginx-alpine`. Settings come from `.env`. The compose file points the
containers at Redis and keeps the SQLite database in a shared volume. The app container runs the
migrations when it starts.

```bash
docker compose up -d --build   # then open http://localhost:8080
docker compose exec app php artisan db:seed --force
```

### 2. A 10-minute walkthrough

Pixelforge's test directives make each scenario predictable. Put one at the start of a prompt.

| Directive | Effect |
|---|---|
| `[succeed]` | Never fails at random. |
| `[fail]` | Fails during processing. Pixelforge doesn't bill it. |
| `[blocked]` | Pixelforge rejects it at submission with `400 content_policy_violation`. |
| `[overloaded]` | Pixelforge rejects it at submission with `503 overloaded`, and the app retries. |
| `[slow]` | Takes 60 to 90 seconds instead of 5 to 30. |
| `[duplicate]` | Pixelforge delivers every webhook event twice. |

1. **Estimate before submitting.** As a guest, type a prompt on the home page. Change the size or
   quality, or add a reference image URL, and the price range updates as you go. Press send to open
   the log-in dialog. The app keeps your prompt.
2. **Onboarding.** Log in as `test@example.com` with the password `password`. Create an organization
   when asked, then buy simulated credits from the balance chip in the header.
3. **Generate.** Send `[succeed] A lighthouse at dusk`. The page polls until the image finishes. It
   then shows the image, its estimated range and the exact charge. Hover the charge to see every
   decimal.
4. **Failures cost nothing.** Try `[fail] …`, `[blocked] …` and `[overloaded] …`. Each ends with a
   clear message, and the app returns the credits it set aside. The failure modes table below lists
   every case.
5. **Threads.** On a finished image, describe a change such as "make it stormier". Use *Continue from
   here* on an older version to start a branch. Switch branches with `‹ 1/2 ›` or the versions map.
6. **History and credits.** The sidebar lists threads. `/generations` lists every image. `/credits` is
   the organization's ledger of purchases, amounts set aside and returned, and charges.
7. **Operators.** As an `APP_ADMINS` user, open **Profit report** at `/admin/reports/profit`. It shows
   revenue, Pixelforge cost and margin per day, reconciled against `GET /v1/usage`, with a CSV export.
   **Users** at `/admin/users` shows every account's spend and balances. Horizon is at
   `/admin/horizon`.
8. **Teams.** In organization settings you can invite members with roles, set spending and image
   limits, limit which qualities or sizes members can use, or delete the organization.
9. **API.** In your profile, generate a personal access token. You pick its organization, scopes and
   expiry, and the app shows the secret once. Then call the API with it. The docs are at `/docs/api`.

   ```bash
   curl -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/credits
   curl -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
        -d '{"prompt":"[succeed] A lighthouse at dusk","size":"1024x1024","quality":"low"}' \
        http://localhost:8000/api/generations          # 202, with a Location header to poll
   ```

---

## How the requirements are met

### Pricing

- Rates and token tables come from Pixelforge's `GET /v1/pricing`, cached for an hour.
- The estimate turns the prompt length, size, quality and reference images into a token range,
  prices it, and adds the markup. `PIXELFORGE_MARKUP` sets the markup and defaults to `1.25`. The code
  is in `app/Services/Pricing/GenerationCostEstimator.php`.
- The final charge uses the usage and rates Pixelforge reports for that generation, so it's exact.
- All money is `brick/math` `BigDecimal`. Provider costs keep 9 decimal places, as Pixelforge reports
  them, and charges keep 10. Money is never a float and never computed in SQL, where SQLite would use
  floats. The app rounds it only for display.

### Asynchronous generations

- Submitting creates the generation and holds its maximum cost in one transaction. It then queues
  `SubmitGeneration`, which sends the generation with a stored idempotency key. A retry therefore
  can't create a second generation or a second bill.
- Results arrive by **webhook** and by **polling**. The app verifies each webhook's HMAC signature
  against the raw body and rejects a replayed event id. `PollGeneration` checks every 10 seconds, and
  a scheduled `ReconcileGenerations` job catches anything that's gone quiet. All three go through one
  idempotent action, `ApplyPixelforgeGeneration`, so duplicate and out-of-order deliveries change
  nothing.

### Failure modes

| Situation | What happens |
|---|---|
| Content policy rejection | The generation fails at once with our own explanation, and the hold is returned. |
| Pixelforge overloaded or rate-limited (429, 503) | The app retries, honouring `Retry-After`, within a shared limit of 55 requests a minute. |
| Pixelforge's concurrency limit of 3 in flight | The generation waits for a free slot instead of failing. |
| Daily limit reached | The generation fails with an explanation, and the hold is returned. |
| Fails during processing | The app marks it failed when the result arrives and charges nothing. |
| Never accepted after an hour of retries | The generation fails, and the hold is returned. |
| Duplicate, late or out-of-order webhooks | The status machine ignores them, because it only moves forward. |
| Webhook missed entirely | Polling or the 5-minute reconciliation finds the result. |
| Not enough credits, or over an organization limit | The app refuses before submitting and shows the reason as you type. |

### History

The sidebar shows one entry per thread. `/generations` lists every image with its status, estimate,
actual charge and the time its image link expires. `/credits` is the full ledger.

### Admin profit report

`app/Services/Reporting/ProfitReport.php` sums revenue and our recorded Pixelforge cost per day. It
compares each day with Pixelforge's `GET /v1/usage` and flags any day that doesn't match.

### API

Users manage personal access tokens in their profile, the way GitHub does. Each token acts in one
organization and only within its scopes: `generations:read`, `generations:create` and `credits:read`.
Responses follow JSON:API. The API generates images through the same action as the web app, so
limits, holds and charges behave the same. Running out of credits returns `402 Payment Required`.
Only a logged-in session can manage tokens. A token can't manage tokens.

| Endpoint | Scope |
|---|---|
| `GET /api/user` | any |
| `POST /api/estimates` | `generations:read` |
| `GET /api/generations`, `GET /api/generations/{id}` | `generations:read` |
| `POST /api/generations` | `generations:create` |
| `GET /api/credits` | `credits:read` |

### For agents

- **MCP.** `/mcp` is a Model Context Protocol server over streamable HTTP. It uses the same tokens and
  scopes as the API and offers the tools `estimate_price`, `generate_image`, `get_generation`,
  `list_generations` and `get_credit_balance`. `/.well-known/mcp/server-card.json` describes it.
- **Discovery.** `/.well-known/api-catalog` follows RFC 9727 and points to the OpenAPI description at
  `/docs/api.json`. Public pages send `Link` headers to the catalog, the server card and the sitemap.
- **Markdown.** The home page, terms and privacy policy return Markdown for `Accept: text/markdown`.
  The Markdown comes from the same files in `resources/markdown` that the web pages render.
- **robots.txt.** It names the AI crawlers and sets Content Signals to
  `search=yes, ai-input=yes, ai-train=no`.

### Credits

Each organization has a balance and an append-only ledger in `credit_transactions`, with one row per
purchase, hold, hold release and charge. Unique indexes stop any step from happening twice for a
generation. The app changes the balance only while it holds a lock on the organization's row, in
`app/Services/Billing/CreditLedger.php`.

---

## Where to look

| Area | Files |
|---|---|
| Pixelforge client and webhooks | `app/Services/Pixelforge/`, `app/Actions/HandlePixelforgeWebhook.php` |
| Generation lifecycle | `app/Enums/GenerationStatus.php`, `app/Jobs/`, `app/Actions/ApplyPixelforgeGeneration.php` |
| Pricing and money | `app/Services/Pricing/` |
| Credits | `app/Services/Billing/` |
| Limits | `app/Services/Generations/GenerationLimiter.php` |
| Threads, to continue and branch | `app/Services/Generations/GenerationThread.php` |
| Roles and permissions | `app/Models/Concerns/HasRoles.php`, `app/Enums/Permission.php`, `app/Policies/` |
| API and tokens | `routes/api.php`, `app/Http/Controllers/Api/`, `app/Http/Resources/`, `app/Actions/IssuePersonalAccessToken.php` |
| MCP server | `routes/ai.php`, `app/Mcp/` |
| Deployment | `Dockerfile`, `docker-compose.yml`, `deploy.sh` |
| Reporting | `app/Services/Reporting/` |
| Front end | `resources/js/pages/`, `resources/js/lib/` |

### State machines

Each lifecycle that drives behaviour is explicit, with its diagram in a docblock.

- **Generation status** in `app/Enums/GenerationStatus.php`. Transitions only move forward. The model
  enforces them on save, and a test pins the full transition table. `resources/js/lib/generationStatus.ts`
  mirrors it for the front end.
- **Invitations** in `app/Enums/InvitationStatus.php`. An invitation is pending, accepted or expired.
  The app works the status out from `accepted_at` and `expires_at`, so it can't go stale.
- **What "send" does** in `resources/js/lib/composerState.ts`. It runs from "needs an account" to
  "ready". Both prompt boxes share it and handle every state with exhaustive switches.

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

Architecture tests in `tests/Unit/ArchTest.php` enforce Pest's strict and security presets. They
require final classes and strict types, forbid protected methods, and keep enums and exceptions in
their own namespaces.

## Design decisions

- **Images aren't stored.** They load from Pixelforge's signed links, which expire after 24 hours. The
  app shows when each one expires, and you can continue from a version only while its link is valid.
- **Hold the maximum, charge the actual.** Users never owe more than the estimate they saw.
- **Usage comes from the records.** Limits and the reconciliation read the generations and the ledger
  themselves, so a late failure or a replayed webhook can't leave a counter wrong.
- **Customers don't see the provider's internals.** The estimate endpoint returns only the price, and
  failure messages use our own wording. Operators still get the provider's error code.
