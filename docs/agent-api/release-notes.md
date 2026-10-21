# Agent API and native MCP release notes

Implementation record: 20 October 2026. These notes describe the working-tree implementation and its migration requirements; they do not assign a new product version. Exact source revisions, the source manifest, acceptance checks and client limits are recorded in [release evidence](release-evidence.md).

## What people can do

Customers can discover businesses and available choices, make bookings, verify access to an existing booking, reschedule or cancel when policy permits, and use separate verification for personal-data access and deletion requests. Timeslot, resource, capacity and event bookings use the same inventory and policy rules as the human interface.

Business owners and managers can use the actions available to their current role: booking work, customer records, catalog and schedules, settings, branding, team access and reporting. Operators can work across explicitly granted businesses and use appropriate application, deletion-queue and maintenance operations. Passwords, credential setup and other sensitive actions open bounded browser handoffs.

AI connections show the responsible person, selected businesses, permissions and expiry. People can review access, revoke a connection and view its saved operation activity, including their own expired or revoked connections. Business filters and pagination keep this history bounded.

The versioned HTTP API and native PHP MCP server use one operation registry with generated contracts. Changes that need review produce an expiring proposal and a browser approval page. Committing a proposal uses an idempotency key and returns a durable receipt; recovery distinguishes a committed command from a new attempt. Read discovery follows current permissions, while role changes, archival and revocation take effect on subsequent use.

Queued notifications and signed webhooks survive request and provider failures. Retries preserve logical event and delivery identities. Delivery to an external provider is not an exactly-once guarantee; receivers must deduplicate webhook event IDs.

## Security and migration changes

- Booking and customer IDs no longer authorize public management or privacy access. Existing links enter recovery or verification. The customer proves email access before receiving purpose-bound authority. Do not restore ID-as-secret behavior to preserve an old integration.
- Legacy v1 keys retain their effective read-only ceiling, including keys containing dormant write-scope names. v2 mutations require a new delegated connection. v1 and v2 paths, pagination and envelopes differ; changing only the version in a URL is insufficient.
- Connections bind identity, current role, business boundaries, audience, scopes and expiry. OAuth uses authorization code with S256 PKCE, exact registered redirects and resource binding; refresh-token reuse revokes its connection.
- Inventory writes and restorations recheck current availability under transactional locks. Approved proposals become invalid when material price, policy, eligibility or record state changes. Repeated commands cannot silently reserve or release inventory twice.
- Migration 41 is the current schema checkpoint. The migration sequence includes the corrected per-key limiter, delegated credentials, customer capabilities, reviews, receipts, durable effects and supporting stores. A failed migration is reported as a failure rather than a successful code update.

Follow the [v1 migration guide](https://voxelbooking.com/docs/agent-api/migration-from-v1) for route mapping and credential changes. The [parity ledger](parity-ledger.md) records 42 capability groups, 190 human route registrations and 129 application operations; these are separate inventories, not a target tool count.

## Hosting requirements

Ordinary PHP hosting remains supported: PHP 8.3 or newer, MySQL 8.0 or newer, a domain or subdomain served from `public/` or through the provided protected root rewrite configuration, and the bundled dependencies/assets. Production does not require Node.js, Docker, a persistent MCP daemon or an AI-provider account.

Enable `pdo`, `pdo_mysql`, `curl`, `mbstring`, `fileinfo`, `openssl`, `gd`, `sodium` and `zip`; JSON is part of the supported PHP runtime. Sodium protects retained payloads. The installer checks required extensions. Keep `storage/keys/`, `storage/media-journal/`, cache/session/log storage and uploads writable by the intended PHP process, with protected storage inaccessible from the web under either supported hosting layout. Preserve keys with the data they protect.

Use canonical HTTPS URLs. For proxy deployments, configure `AGENT_BASE_URL` and forward Authorization and MCP headers correctly. Optional browser origins must be explicitly listed in `AGENT_ALLOWED_ORIGINS`; credentials do not permit wildcard origin access. See [hosting and troubleshooting](https://voxelbooking.com/docs/agent-api/hosting-and-troubleshooting).

Run the private cron command from **Admin → Settings → Cron** every five minutes. It drives durable delivery, retry, upload reconciliation and queued maintenance. Monitor delivery state separately from the committed booking result.

## Upgrade and recovery procedure

1. Rehearse on an isolated copy. The repository provides `php scripts/test-isolated.php --upgrade-check --mysql-image=mysql:8.0`, which creates an actual version-28 schema and tests upgrade, partial DDL resume, injected failure and backup restoration. Docker is used only by this disposable verification harness.
2. Before the real upgrade, restrict application traffic and stop scheduled cron invocations. Record the deployed code revision and schema checkpoint. Make a coherent backup of the database, deployed code/dependencies/assets, `.env`, `storage/keys/`, `storage/media-journal/` and `public/uploads/`. Verify that it can be restored.
3. Use **Admin → Updates**. Git installations require a clean checkout with a configured upstream; the updater applies code and then pending migrations. Shared hosting can use the release archive flow. Preserve the backup until post-upgrade verification succeeds.
4. If migration fails after code installation, keep traffic restricted, inspect the reported failure and correct the underlying problem. When Git files are current but schema work remains, the page offers **Retry database update** under the same operator and CSRF controls. It resumes pending checkpoints without resetting already-current files. Archive installations can reapply the same compatible archive. Do not decrement `db_version`, rerun installation or drop partially created tables to force progress.
5. Confirm the migration completed. A read-only database check is ``SELECT value FROM settings WHERE `key` = 'db_version'``; this implementation expects `41`. Check ordinary sign-in, one appropriate read, reviewed mutation/retry, revocation and cron delivery in the upgraded environment. Then restore traffic and scheduled cron.

The Git updater retains one lock file so simultaneous retry processes cannot acquire different lock inodes. Both migration failure and retry are covered by local-repository regression tests; the native retry form is exercised against the disposable application.

There is no automatic schema rollback. If recovery requires restoration, keep the service offline and restore the entire matched backup set, not only old PHP files over a new database. A backup from before this security change must stay offline until the independent customer-credential boundary has been restored through a compatible fix or forward upgrade. Reconcile connections and revocations created after the backup before reopening access. Preserve failed-upgrade diagnostics without publishing credentials or personal data.

Do not delete an uncertain upload journal as a workaround. Reconciliation checks durable database state under the business lock, preserves committed or attached files and removes only uncommitted files. Database unavailability defers that decision.

## Verified clients and boundaries

Codex CLI **0.153.4** and Claude Code **2.1.281** passed scoped staff bearer connection, read, timeslot creation, reschedule, cancellation, identical retry and revocation against the disposable application, with real browser review approval. Their negotiated protocol revisions were not captured. The [client evidence](evidence/native-client-evidence.md) records exact commands and sanitized outcomes.

The adapter has executable coverage for MCP revisions **2026-07-28** and **2025-11-25**. Protocol tests are distinct from branded-client integration: hosted clients, branded OAuth login, other booking patterns in these two clients, and public/customer client workflows remain unverified. No compatibility claim is made for them.

Controlled sinks and failure injection verify durable email and webhook behavior; live production provider delivery and a live external webhook receiver remain external checks. Staff/site rendering, keyboard, mobile, 200% zoom and reduced motion were inspected. The customer manage/privacy visual inspection remains blocked by an automatic tool approval restriction pending user approval. See [release evidence](release-evidence.md) for the full 52-scenario matrix and precise limits.
