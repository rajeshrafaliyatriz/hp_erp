# Signals Data Connectors: administrator setup and verification

Audience: the person who deploys G2G and owns its developer accounts with Google, LinkedIn and Meta.
Ordinary G2G users never do any of this. They click **Connect Account** and sign in with the platform.

This guide is derived from the code, not from memory of the providers' consoles. Provider consoles, review
rules and API versions change; every step marked **(verify in console)** is something to check on the provider's
current screen or documentation before relying on it.

## 0. How the pieces fit

| Piece | Where | Notes |
|---|---|---|
| Provider list, scopes, required settings | `app/Domain/Signals/Connectors/ConnectorProviderCatalog.php` | single source |
| Readiness check (setting names only, never values) | `ConnectorProviderReadiness.php` | feeds the UI |
| OAuth start / callback / account selection | `Http/Controllers/Api/Signals/DataConnectorOAuthController.php` | |
| Token refresh | `SocialConnectorTokenManager.php` | Google and LinkedIn refresh; Meta does not |
| Settings | `config/signals_connectors.php` | read from environment |
| Page the browser returns to | frontend route `/ingestion` | `g2gf/app/ingestion/page.tsx` |

## 1. Redirect URIs (exact)

The OAuth callback route is `GET /api/signals/ingestion/connectors/oauth/{provider}/callback`
(`routes/api.php`, provider is one of `youtube`, `linkedin`, `meta`). Unless overridden, the redirect URI is
`APP_URL` + that path (`config/signals_connectors.php`).

| Provider | Redirect URI to register | Override variable |
|---|---|---|
| YouTube (Google) | `<APP_URL>/api/signals/ingestion/connectors/oauth/youtube/callback` | `YOUTUBE_OAUTH_REDIRECT_URI` |
| LinkedIn | `<APP_URL>/api/signals/ingestion/connectors/oauth/linkedin/callback` | `LINKEDIN_OAUTH_REDIRECT_URI` |
| Facebook and Instagram (Meta) | `<APP_URL>/api/signals/ingestion/connectors/oauth/meta/callback` | `META_OAUTH_REDIRECT_URI` |

`<APP_URL>` is the **public address of the Laravel API**, not the frontend. Example for an API served at
`https://api.example.com`: `https://api.example.com/api/signals/ingestion/connectors/oauth/youtube/callback`.

Rules that cause most "redirect mismatch" failures:

- The value registered at the provider must equal what G2G sends, character for character (scheme, host, port, path, no trailing slash).
- `localhost` and `127.0.0.1` are different hosts to every provider. Register the one G2G actually uses.
- The same URI string is shown to administrators in the **Platform setup required** notice on the Data Connectors screen. Copy it from there.
- If the API sits behind a proxy or load balancer, `APP_URL` must still be the public HTTPS address.
- The callback route allows 20 requests/minute per client.

## 2. Settings summary

| Setting | Used for | Required for |
|---|---|---|
| `APP_URL` | builds all three redirect URIs | every OAuth provider |
| `SIGNALS_CONNECTORS_FRONTEND_URL` | full URL the browser returns to after sign-in; wins if set | recommended in production |
| `FRONTEND_URL` | the site **origin**; when `SIGNALS_CONNECTORS_FRONTEND_URL` is empty, `/ingestion` is appended to it | fallback |
| `YOUTUBE_OAUTH_CLIENT_ID`, `YOUTUBE_OAUTH_CLIENT_SECRET` | Google OAuth app | YouTube |
| `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET` | LinkedIn app | LinkedIn |
| `META_APP_ID`, `META_APP_SECRET` | Meta app | Facebook and Instagram |
| `LINKEDIN_API_VERSION` | `Linkedin-Version` header, format `YYYYMM` (default `202610`) | LinkedIn |
| `META_GRAPH_API_VERSION` | Graph version (default `v26.0`) | Facebook and Instagram |
| `SIGNALS_CONNECTOR_SCHEDULE_ENABLED` | master switch for scheduled syncs (default on) | scheduled sync |
| `SIGNALS_CONNECTOR_MAX_ITEMS` | items fetched per sync (default 25) | all social |

Return URL behaviour (`config/signals_connectors.php`): `https://app.example.com` becomes
`https://app.example.com/ingestion`; a value that already has a path is used as-is; an empty value falls back to
`http://localhost:3000/ingestion`, which is wrong in production and triggers a readiness warning.

Every variable above is listed, with comments, in `.env.example` (a documented block titled "Signals: Data Connectors"; credentials are blank, optional settings are commented out).

Never put these secrets in frontend code or commit them. Set them in the server environment, then clear cached
config (`php artisan config:clear`, or re-run `config:cache`) and restart queue workers.

## 3. YouTube (Google Cloud Console)

Credentials: OAuth client ID and secret. Scope requested: `https://www.googleapis.com/auth/youtube.readonly`.
G2G asks for `access_type=offline` and `prompt=consent` so Google issues a refresh token.

1. Create or choose a Google Cloud project.
2. Enable **YouTube Data API v3**.
3. **OAuth consent screen**: user type External; app name, support email, authorised domain; add the scope `.../auth/youtube.readonly`.
4. **Credentials → Create credentials → OAuth client ID → Web application**. Under **Authorized redirect URIs** add the YouTube URI from section 1.
5. Put the client ID and secret into `YOUTUBE_OAUTH_CLIENT_ID` / `YOUTUBE_OAUTH_CLIENT_SECRET`.

Review and limits (verify in console):

- While the consent screen is in **Testing**, only listed test users can sign in and refresh tokens typically expire after 7 days, so a connector will need reconnecting weekly. For real use, publish the app to **Production**.
- `youtube.readonly` is a sensitive scope; Google verification of the app is normally required for use beyond test users.
- The default API quota is small but a G2G sync is cheap (a handful of read requests). Quota errors surface as "rate limited".
- The signed-in Google account must own or manage the channel; G2G discovers the channel with `channels?mine=true` and stores that channel.

## 4. LinkedIn (LinkedIn Developer Portal)

Credentials: client ID and client secret. Scope requested: `r_organization_social`.

1. Create an app at the LinkedIn Developer Portal and associate it with your company Page.
2. **Products**: request **Community Management API** (the product that grants `r_organization_social`). **(verify in console)** LinkedIn has required this product to be approved and may require a dedicated app; approval is manual and not instant.
3. **Auth tab → Authorized redirect URLs for your app**: add the LinkedIn URI from section 1.
4. Copy the client ID and secret into `LINKEDIN_CLIENT_ID` / `LINKEDIN_CLIENT_SECRET`.
5. Confirm `LINKEDIN_API_VERSION` is a version LinkedIn currently accepts. The default (`202610`) was **not verified** against LinkedIn; LinkedIn retires old versions, so update it if posts calls return a version error.

Behaviour to expect:

- The user who connects must hold an admin role on the organisation Page. Each connector needs the **numeric organisation ID** (found in the Page admin URL). G2G asks for it in the form because LinkedIn offers no account-picker for this scope.
- Programmatic refresh tokens are issued only to LinkedIn-approved apps. Without one, the connection expires (about 60 days) and the user must **Reconnect**.
- Without Community Management API approval the sign-in may succeed but the probe call fails; G2G then shows **Permission Missing** rather than Connected.

## 5. Facebook Pages and Instagram (Meta for Developers)

Credentials: App ID and App Secret (one Meta app serves both connectors).
Scopes requested: Facebook Page: `pages_show_list`, `pages_read_engagement`.
Instagram: `instagram_basic`, `pages_show_list`, `pages_read_engagement`.

1. Create an app at Meta for Developers (type Business) and add the **Facebook Login** product.
2. **Facebook Login → Settings → Valid OAuth Redirect URIs**: add the Meta URI from section 1. Keep client and web OAuth login enabled **(verify in console)**.
3. Put App ID / App Secret into `META_APP_ID` / `META_APP_SECRET`. Set `META_GRAPH_API_VERSION` to a version your app can use **(verify; default `v26.0` not verified)**.
4. **App Review** **(verify in console)**: in Development mode only people with a role on the app (admins, developers, testers) can connect. For other users, the app must be switched to Live and have **Advanced Access** approved for `pages_show_list`, `pages_read_engagement` and, for Instagram, `instagram_basic`. Business Verification is typically required for that access.

Behaviour to expect:

- After sign-in G2G lists the Pages (and linked Instagram professional accounts) the user granted, and the user picks one in the connector card (**Choose Account**). Until they do, the connector is **not** shown as Connected.
- Meta's permission dialog lets people untick individual permissions. G2G checks `/me/permissions`; a declined permission gives **Permission Missing**, not a half-working connection.
- Instagram needs an Instagram **professional** (business or creator) account linked to a Facebook Page the user manages. Personal accounts will not appear.
- Meta has no refresh-token grant. Page tokens derived from a long-lived user token normally do not expire, but are invalidated by password changes, removal from the Page or revoked app access. The user then sees **Token Expired** and uses **Reconnect**.

## 6. Production deployment checklist

- [ ] `APP_URL` is the public HTTPS API address. Registered redirect URIs match section 1 exactly.
- [ ] `SIGNALS_CONNECTORS_FRONTEND_URL` (or `FRONTEND_URL`) is the public app address; opening `<that>/ingestion` shows Data Connectors.
- [ ] Provider settings above are present (the UI lists any missing setting **names**; values are never displayed).
- [ ] (Exact commands, status meanings, what happens with no worker or a dead worker: `docs/agentic-crm/OPS-SCHEDULER.md`, section "The `signals` queue worker, and data connector syncs"; verification status: `docs/product-driven-signals.md`, section "Data connector operations".) The worker command must name the queue: `php artisan queue:work --queue=signals --tries=4 --timeout=900`; a bare `queue:work` ignores it.
- [ ] The queue worker is running for queue `signals` (`SyncDataConnectorJob` is queued there; `signals.queue` config). Without a worker, syncs stay "queued".
- [ ] The scheduler runs every minute (`php artisan schedule:run` via cron/Task Scheduler). `signals:connectors:sync-scheduled` is registered in `routes/console.php`. `docs/agentic-crm/OPS-SCHEDULER.md` records that `schedule:run` was **not** active on live at the time it was written; confirm it now, otherwise scheduled syncs never fire (manual **Sync Now** still works).
- [ ] The cache store supports locks (sync and refresh locks use `Cache::lock`; the `database`, `redis` and `memcached` stores do, `file`/`array` are fine for one server only).
- [ ] The connector migrations are applied (`2026_11_04_120000`, `2026_11_04_140000`). Otherwise the screen says storage is not set up.
- [ ] Config cache cleared and workers restarted after changing settings.

## 7. How to confirm provider readiness in G2G

1. Open **Signals → Ingestion Engine → Data Connectors**.
2. If a platform is not configured, a **Platform setup required** card lists the missing setting names, the redirect URI to register, and the provider's requirements. When nothing is listed, every platform application is **configured**.
3. API view: `GET /api/signals/ingestion/connectors` returns `available[]` with `ready`, `readiness_status`, `missing_config`, `redirect_uri`, `warnings`.
4. "Configured" is not "approved": readiness only checks that the settings exist. Approval problems appear during a real connection as **Permission Missing**.

Last checked locally (setting names and presence only, no values): environment `local`; Google and Meta settings missing,
LinkedIn client settings present; redirect hosts differ between providers (`127.0.0.1` vs `localhost`), so use one host consistently when registering.

## 8. Real-provider staging verification

Do these only in staging, with your own test accounts, after the settings above are in place. Do not run them against
production data. Nothing in this repository has been run against a real provider.

Evidence labels: **Code** = reviewed in code; **Mock** = passes automated test with `Http::fake` (proves our handling of provider responses, not that the provider accepts the app); **Real** = confirmed with a live provider; **Approval** = needs provider approval first; **Not tested**.

| # | Check | Steps | Pass looks like | Today |
|---|---|---|---|---|
| 1 | YouTube sign-in and channel | Add YouTube connector, click Connect Account, sign in with a test user who owns a channel | returns to `/ingestion` with "Account connected"; card shows channel name; status Connected | Code, Mock. Real: **Not tested**. Approval: consent screen Production and verification for non-test users |
| 2 | LinkedIn organisation | Enter numeric org ID, Connect Account with a Page admin | Connected, or **Permission Missing** if product not approved (both are correct, honest outcomes) | Code, Mock. Real: **Not tested**. Approval: **Community Management API required** |
| 3 | Facebook Page discovery | Connect Account, grant both permissions, choose a Page, Use this account | Page name shown, status Connected | Code, Mock. Real: **Not tested**. Approval: App Review/Advanced Access for non-role users |
| 4 | Instagram discovery | Same, with a Page linked to an Instagram professional account | only the linked Instagram account is offered; Connected after choosing | Code, Mock. Real: **Not tested**. Approval as for Facebook plus `instagram_basic` |
| 5 | Website manual sync | Add a public URL, Enable, Sync Now | history row `completed`, one source stored, signals created for matching content | Code, Mock (pipeline test), Real web fetch **Not tested** |
| 6 | Scheduled sync | Set a 15-minute schedule on a connected connector; ensure worker and cron run | a new history row appears about every 15 minutes; unchanged website pages show `skipped`, "content has not changed" | Code, Mock (scheduler tests). Real: **Not tested** |
| 7 | Token expiry and reconnect | Revoke the app in the provider account settings (or wait for expiry), then Sync Now | status becomes **Token Expired**, scheduled syncs stop, message says Reconnect; reconnecting restores Connected | Code, Mock (refresh, revoked and outage cases). Real: **Not tested** |
| 8 | Evidence and opportunities | After a successful sync open All Signals / Company Opportunities for the product | new signals carry the connector's product, sub-product, customer model and region, and link to the source URL | Code, Mock (YouTube, LinkedIn, Facebook, Instagram, website). Real: **Not tested** |

Two operational checks belong with check 6 and need staging infrastructure, not just the code: (a) press Sync Now with the worker stopped and confirm the sync shows `queued`, and after 10 minutes is closed as failed with the worker command in its message; (b) stop the worker during a running sync and confirm that after about 17 minutes it is closed with the "stopped responding" message, the connector is not blocked, and Sync Now works again once the worker is back. Both behaviours are proven in code and mocked tests only.

Record, for each real check, the date, who ran it and the observed result. Only then change a **Not tested** to **Real**.
