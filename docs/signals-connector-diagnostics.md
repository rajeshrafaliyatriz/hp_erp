# Data connectors: what the Ingestion Engine screen is really saying (16 Nov 2026)

Written for the administrator and engineer who need to turn the connector cards from "setup required" into working connections. It was produced by reading the source behind each message and the providers' own documentation. Nothing was run against a real provider or the live server, so every "observed" item below is what the code does with the state the screenshot shows, not a live check.

## 1. Where each message on the screen comes from

| What the screen shows | Produced by | Real blocker | Is it a code defect? |
|---|---|---|---|
| YouTube: "Missing server settings: YOUTUBE_OAUTH_CLIENT_ID, YOUTUBE_OAUTH_CLIENT_SECRET" | `ConnectorProviderReadiness::check()`: those config values are empty on the server that answered | **Missing server-side credentials** (the platform application is not configured) | No. Set the two variables on that server and register the redirect URI shown |
| Facebook Page / Instagram: "Missing server settings: META_APP_ID, META_APP_SECRET" | same check for the shared Meta application | **Missing server-side credentials** | No. Same fix |
| LinkedIn card "Authorization Required" and "Click Connect Account to sign in" | the connector row has `connection_status = not_configured`: no OAuth has completed. LinkedIn is **not** in the setup panel, so its two settings are present (whether they are correct is only known by trying) | **OAuth not completed**, and probably **missing LinkedIn approval** (see 3) | No. It is the honest state before sign-in |
| "Active & Synced 0", "Signals Ingested 0" | counts of connectors that are connected and of signals created by syncs | Follows from the above: nothing is connected, so nothing has synced | No |
| "Total connectors 2" | both are LinkedIn rows (the filter shows "LinkedIn Org (2)") | n/a | No |

The screen was not mis-displaying anything: each state is derived from the server's own readiness and connection fields. What was misleading before this change: a card that has simply never been signed in showed "Authorization Required", which sounds like a failure. It now shows **Ready to Connect**. "Authorization Required" is kept for a signed-in connector that still needs its account chosen.

## 2. What changed in this pass

- **Readiness checks structure, not just presence.** A value that is set but cannot be right (a Google client id that is not `...apps.googleusercontent.com`, a Meta app id that is not digits, a Meta app secret that is not 32 hex characters, a value that is too short) makes the provider `configuration_invalid`, not ready. Only the setting name is returned, never the value. It cannot detect a wrong-but-well-formed or revoked credential: only the provider can.
- **Approval requirements are shown per provider** in the setup panel (below).
- **Disconnect revokes at the provider where it can** (Google, LinkedIn), always deletes the stored token locally whatever the provider answers, and says which happened: `revoked`, `failed`, `not_supported` (Meta) or `not_attempted`.
- **Stages on the card:** Setup Required, Ready to Connect, Connecting, Authorization Required, Connected, Sync Queued, Syncing, Synced, Partial, Failed, Reconnect Required (plus Rate Limited and Disconnected), from the server's state only. A connected account says **Connected** until a sync has run; **Synced** appears only when the newest sync finished.
- **Three separate numbers** under each card: fetched from the platform, analysed, signals created. Zero signals from a finished sync is shown as zero, not as a failure.

## 3. Provider by provider

| Provider | Server settings | Redirect URI to register | Scope used | Approval / eligibility (official docs, checked this pass) | Account selection |
|---|---|---|---|---|---|
| YouTube | `YOUTUBE_OAUTH_CLIENT_ID`, `YOUTUBE_OAUTH_CLIENT_SECRET` | `<APP_URL>/api/signals/ingestion/connectors/oauth/youtube/callback` | `youtube.readonly` (read only; listed on Google's scopes page). Its sensitivity level was **not** confirmed from the page: check the Cloud Console consent screen | YouTube Data API v3 enabled in the Google Cloud project. Consent-screen verification may be needed for accounts outside your organisation | the user's channel(s) are discovered (`channels.list` with `mine=true`) and one is selected. Listing and paging cost 1 quota unit per call, 50 items per page |
| LinkedIn | `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET` | `<APP_URL>/api/signals/ingestion/connectors/oauth/linkedin/callback` | `r_organization_social` (reads an organisation's posts) | **Developer approval required.** Every app starts on the Community Management API **Development** tier: 500 API calls per app and 100 per member per 24 hours, no batch calls. Standard tier is reviewed case by case with no fixed timeline, and LinkedIn may decline. The member needs an administrator role on the organisation page | **Not discovered.** Listing the organisations a member administers needs `rw_organization_admin`, which this app does not request. The organisation's numeric ID is entered by hand (the card shows it) |
| Facebook Page | `META_APP_ID`, `META_APP_SECRET` | `<APP_URL>/api/signals/ingestion/connectors/oauth/meta/callback` | `pages_show_list`, `pages_read_engagement` | Standard Access works **only for people with a role on the Meta app** (admin, developer, tester). Everyone else needs Advanced Access, which requires **Business Verification** and may need App Review | Pages the person manages are listed and one is selected. A Page access token is used |
| Instagram | `META_APP_ID`, `META_APP_SECRET` (same app) | same Meta callback | `instagram_basic`, `pages_show_list`, `pages_read_engagement` | Same Meta access levels. Only **Instagram Business or Creator** accounts work: Meta states consumer accounts cannot be read through this API. The account must be reachable through a Facebook Page | accounts linked to the person's Pages are listed and one is selected. Meta caps media listing at the 10,000 most recent items |
| Website / article | none | none | none | none. Public pages only | the URL |
| Uploaded document | none | none | none | none | none |

Meta also documents `instagram_business_basic` as a newer permission. This app asks for `instagram_basic`, which Meta's own permissions table still lists with a description and no deprecation notice, so it was not changed. Confirm in your Meta app dashboard which one your app is offered.

## 4. What an administrator must do (nothing here can be done from the repository)

1. **YouTube:** in a Google Cloud project, enable YouTube Data API v3, create an OAuth client (Web), register the redirect URI above, and set the two variables on the server that serves `api/signals`. Complete the consent screen. Test with an account that is a listed test user.
2. **Meta (Facebook and Instagram):** create a Meta app, add Facebook Login, register the Meta redirect URI, set `META_APP_ID` and `META_APP_SECRET`. For a first test, authorise with a user who has a role on the app. Moving beyond app-role users needs Business Verification and Advanced Access.
3. **LinkedIn:** apply for Community Management API access for the app (this is LinkedIn's decision and has no fixed timeline). Until it is approved the sign-in or the first sync will be refused by LinkedIn, and the card will show the real reason. Then complete sign-in and enter the organisation's numeric ID.
4. Restart the PHP workers and the queue worker so the new settings are read, then press Refresh. A configured provider changes from "Setup Required" to "Ready to Connect".
5. Run the staging checks in `docs/signals-connector-staging-guide.md`. No provider has been authorised or synced against a real account in this repository's tests.

## 5. What is and is not verified

- **Verified by automated tests (mocked provider traffic, in-memory database):** readiness for missing, malformed and valid settings; no secret in any response; OAuth state, scope and callback handling; token storage encrypted and refresh; account selection; tenant isolation; rate limits, pagination bounds and provider errors; sync lifecycle, recovery and retries; website fetching with a local misbehaving server; revocation requests and their outcomes.
- **Not verified:** any real OAuth round trip, any real provider API response, LinkedIn's actual access tier for your app, Meta app review status, the live server's queue worker and scheduler, and the production database. A connector is genuinely working only when a real account has been authorised, a real sync has fetched records, and they were analysed.
