# Connector Settings: provider credentials entered in the app

Written for the administrator who configures YouTube, LinkedIn, Facebook and Instagram, and the engineer who deploys it. It has not been deployed anywhere: it was built and tested locally (in-memory database, mocked provider traffic).

## What it does

An administrator opens **Data Connectors > Connector Settings**, enters the provider application's client ID and secret, saves, tests, and then uses the existing Connect Account flow. No `.env` edit and no restart is needed for credentials entered this way. Facebook and Instagram share one Meta application.

## Where credentials live and which wins

| Source | Used when |
|---|---|
| 1. Saved in the app for this organisation (`g2g_connector_provider_settings`, encrypted) | both the client ID and the secret are saved |
| 2. Server environment (`YOUTUBE_OAUTH_*`, `LINKEDIN_*`, `META_*`, as before) | no complete saved pair |
| 3. None | the provider shows Setup Required |

One class, `ConnectorCredentialResolver`, answers this for readiness, OAuth start, the code exchange, token refresh and revocation, so they can never disagree. A half-saved row is never mixed with the environment. The `.env` file is never written by a request. There is no cache across requests (only a per-request memo that every save clears), so a saved change applies on the next call and no secret sits in a shared cache.

Credentials are **per organisation**: one organisation cannot read, change, remove or use another's. Server-environment credentials are deployment-wide, are never echoed to the browser, and cannot be removed from the screen.

## Security

- **Admin only.** The four routes under `/api/signals/ingestion/connector-settings` use the `profile:admin` middleware (the same role check the rest of the application uses). The Data Connectors list only tells the screen whether to show the entry point (`can_manage_settings`).
- **Encrypted at rest** with Laravel's `encrypted` cast (AES, keyed by `APP_KEY`). The model hides both columns from serialisation.
- **Never returned.** Responses carry whether a secret exists and a fixed mask, the client ID of credentials saved here, the update time, and missing or malformed settings by name. Never a secret, never a server credential, never a provider's raw answer. Exception messages from provider calls are not returned.
- **Never logged.** Logs record organisation, provider, which fields changed and who, not values. An audit row (`g2g_connector_setting_audits`) records the same: created, updated or removed, and field names.
- **Empty means keep.** An edit that leaves the secret empty keeps the saved one; the mask is never accepted as a new secret; a first save needs both values.
- **Validated shape.** A client ID or secret that cannot be right (for example a Meta App Secret that is not 32 hex characters) is refused with a reason before anything is stored. Only the provider can tell a wrong-but-well-formed credential.

## Test Configuration

Reports two separate answers: whether this app's settings are present and valid, and whether the provider recognises the application. The provider check is harmless: Google and LinkedIn receive a deliberately invalid authorization code (an unknown client is told apart from a known one by the error), Meta receives an app-token request that creates nothing and whose token is discarded. "Accepted" means the provider knows the application; it does not mean the app is approved or any account can authorise. LinkedIn often cannot confirm credentials without a real sign-in and says so. A connection exists only after a completed sign-in, so the test reports Connected only when an account really is connected.

## Rotating credentials

Changing only the **secret** does not disconnect anyone: stored tokens belong to the provider application, and refresh uses the new secret. Changing the **client ID** points at a different application, so accounts connected through the old one may need to reconnect (the screen warns when any exist). Removing saved credentials falls back to the server environment; with none there, connected accounts keep their data but cannot refresh their authorization.

## Deployment checklist (nothing here has been done)

1. **Migration.** `2026_12_10_100000_create_g2g_connector_provider_settings_tables.php` creates two new tables, additive only, no existing table touched. Run it through your normal deployment process, never assumed from local. It uses `SHOW TABLES` on MySQL/MariaDB so it works on the live MariaDB 10.1. Until it runs, the screen says saving is not available and the server environment still works.
2. **`APP_KEY` must be stable and backed up.** Saved credentials are encrypted with it. If the key is changed or lost they cannot be read: the application then treats them as absent (falls back to the environment) and the administrator must enter them again. Do not rotate `APP_KEY` without a plan.
3. **Backup and rollback.** Back up the database before migrating. Rollback is redeploying the previous release; the two tables are left in place (their `down()` deliberately does not drop saved credentials).
4. **Deploy order.** Backend first. An older frontend works against the new backend (the settings routes are simply unused). A new frontend against an old backend shows the Connector Settings button only if the list endpoint says the caller is an administrator, and the panel then reports that settings could not be loaded.
5. **After deploying:** as an administrator, open Connector Settings, save one provider with a test application, run Test Configuration, and confirm the card reads Ready to Connect, not Connected, until an account is actually authorised. Existing connectors and syncs need no change.
6. **Provider approvals are separate and still required** (see `docs/signals-connector-diagnostics.md`): LinkedIn Community Management API access, Meta Advanced Access and Business Verification for non-role users, Google consent-screen verification where applicable.
