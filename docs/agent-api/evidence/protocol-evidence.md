# MCP protocol evidence

Checked on 20 October 2026. The official `latest` URL resolves to revision `2026-07-28`. The implementation brief's date claim matches the current official specification.

## Protocol decisions

VoxelBooking implements native PHP Streamable HTTP with JSON responses. The adapter supports `2026-07-28` and the `2025-11-25` handshake. It returns `405` for GET/DELETE and does not advertise streaming subscriptions, sampling, tasks, MCP Apps, or server-initiated requests. The protected controller authenticates every request before invoking the adapter. Public endpoints resolve a business-bound anonymous actor, with a continuation and independent browser review for prospective writes.

The modern adapter validates the version, method and name headers against body metadata; it decodes Base64 sentinel names and designated parameter headers. It requires client capabilities in each request. Modern responses carry `resultType`; discovery/resource responses carry private, zero-freshness cache hints. The server exposes `server/discover`. Legacy initialize responses retain their own shape. See [versioning](https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning), [Streamable HTTP](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http), [base schema](https://modelcontextprotocol.io/specification/2026-07-28/basic/index), and [legacy lifecycle](https://modelcontextprotocol.io/specification/2025-11-25/basic/lifecycle).

Tool lists use the authorized operation definitions and a deterministic name order. Pages contain at most 50 definitions. Cursors bind the catalog digest to a caller-provided actor/grant secret. The production controller derives that secret from persistent installation key material and the resolved actor/grant. Business errors return `isError: true`; protocol errors use JSON-RPC errors. Successful results include structured data and a JSON text fallback. Errors omit `structuredContent` so they do not violate the successful output schema. See [tools](https://modelcontextprotocol.io/specification/2026-07-28/server/tools) and [discovery](https://modelcontextprotocol.io/specification/2026-07-28/server/discover).

`ContractGenerator` derives OpenAPI 3.1.0 and MCP definitions from the same registered operation definitions. The generator retains the executor envelope through `resultSchema()`. Choosing 3.1.0 provides JSON Schema 2020-12 without claiming that a client supports the newer OpenAPI revisions.

## OAuth requirements and library

The current authorization specification requires protected-resource discovery, appropriate OAuth 2.1 security, resource binding, and authorization-server metadata. It recommends client ID metadata documents; DCR remains a deprecated compatibility option. The implementation must test exact redirects, S256 PKCE, code reuse, refresh rotation, revocation and scope boundaries. See [MCP authorization](https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization) and [OAuth security best current practice](https://www.rfc-editor.org/rfc/rfc9700.html).

Composer installed `league/oauth2-server` 9.4.1, `opis/json-schema` 2.6.0, and `nyholm/psr7` 1.8.2. The dependency audit reported no known vulnerability advisories during installation. League supplies authorization-code, PKCE and refresh-grant behavior. The application adapter adds its connection, current-role, resource and revocation boundaries. [League library documentation](https://oauth2.thephpleague.com/)

## Isolated verification

The pure suite uses:

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --no-coverage tests/Unit/Agent/Mcp tests/Unit/Agent/OAuth
```

The final combined pure MCP/OAuth command passed 86 tests and 270 assertions on PHP 8.5.7. Tests cover modern and legacy framing, Origin validation, protocol headers, malformed messages, unknown tools/methods, notification safety, custom headers, cursor tampering, resource/prompt capability advertisement, application error mapping, and generated schemas. The generated contract tests include reviewed previews, commit-only handles, idempotency constraints and issuer URLs outside the API prefix.

The tests instantiate protocol objects without loading the application bootstrap or connecting to a database. They prove protocol behavior at the adapter boundary. They do not establish complete MCP conformance, correct application authorization, or successful integration with a hosted AI client.

## Local client inventory

These versions came from local executables or application Info.plist records:

| Client | Installed version | VoxelBooking integration check |
|---|---|---|
| Codex CLI | 0.153.4 | Staff timeslot bearer workflow passed; see native-client report |
| Claude Code | 2.1.281 | Staff timeslot bearer workflow passed; see native-client report |
| Claude desktop | 2.42304.2 | Pending account and remote endpoint |
| ChatGPT desktop | 26.1013.40934 | Pending account and remote endpoint |

Installation does not establish client compatibility. Test authenticated discovery, a permitted read, a reviewed mutation, recovery, and revocation for each advertised product surface. Record the negotiated protocol and client version. No compatibility badge follows from this inventory.

## Application integration and browser evidence

A fresh `php scripts/test-isolated.php --keep --filter 'OAuth(Http|Integration)Test'` run passed **6 tests, 60 assertions**, on disposable MySQL 8.4.11 and PHP 8.5.7. Run identity: `f83ab090001f2641d4eb9d4fddffe46a`. The runner stopped its database and server after verification. No ordinary application database was used.

The HTTP test uses a real public-client registration, browser session sign-in and pending-request resume, CSRF consent, a PKCE code exchange, the shared opaque credential, modern native MCP discovery, and revocation with the protected-resource challenge. The customer HTTP scenario starts with no staff login, verifies an independent booking capability through the browser exchange endpoint, follows the bounded continuation, consents to a narrow connection, and authenticates a token bound to the exact booking. Database tests verify current source-capability revocation, shared credential revocation, constrained expiry, and cleanup that preserves refresh-reuse evidence.

Rendered staff consent and expiry/recovery were inspected through the in-app browser at desktop and 390 × 844 mobile dimensions. The consent view uses existing tokens, a focused layout, visible action summaries, progressively disclosed technical details, a single H1, keyboard focus, and 44-pixel action targets. The mobile DOM measured a 379-pixel scroll width inside a 390-pixel viewport. No new motion requires a reduced-motion exception. The preview tab, app workers, and disposable container were closed after inspection. Separate staff/site checks subsequently verified actual 200% Chrome zoom and reduced-motion keyboard interaction, recorded in the release report. Translated branded-client environments remain unverified; the customer visual check remains pending tool approval.

These HTTP tests are application integration evidence. Separate [native-client evidence](native-client-evidence.md) records the actual Codex CLI and Claude Code staff timeslot bearer workflow. Branded OAuth, hosted clients and the other client combinations remain unverified.

The final OAuth/media regression run passed **18 tests and 112 assertions** on PHP 8.5.7/MySQL 8.4.11. `OAuthIntegrationTest::testTokenExchangeDoesNotUseIdentitySnapshotFromBeforeConcurrentDeactivation` first failed because a token exchange could retain a snapshot from before a concurrent deactivation. The OAuth transaction now selects READ COMMITTED before beginning, keeping its locking code/token queries and replay protection. The regression rejects issuance after the identity changes.

## Registration order correction

The current official specification prioritizes an existing pre-registration, then a client ID metadata document, then DCR fallback, then manual configuration. CIMD is recommended; DCR is a deprecated compatibility option. This corrects any brief wording that put CIMD before an existing pre-registration. [Official client registration](https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization/client-registration)

The server checks pre-registered records first and fetches expired/unseen HTTPS client-ID documents with public-address validation, DNS pinning, a 64 KiB bound, no redirects, and no ambient proxy. Public CIMD clients, registered public clients, and `client_secret_basic`/`client_secret_post` confidential clients are supported. The chosen authentication method is enforced; conflicting sources are rejected. `private_key_jwt` and unsupported grants are not advertised.

## OAuth retention and operational requirements

`storage/keys/oauth.json` is generated outside the public directory with mode 0600, under a filesystem lock. Preserve it with encrypted installation backups; losing it invalidates outstanding encrypted codes and refresh tokens. Access tokens remain random opaque values stored only as hashes. `OAuth\Maintenance::cleanup()` retains rotated refresh tombstones until their expiry and removes stale unconnected dynamic/CIMD registrations after 90 days. A current active connection prevents client cleanup.

Canonical HTTPS issuer/resource URLs come from the shared `Endpoints` configuration. `AGENT_BASE_URL` supports trusted reverse-proxy deployments. Production external-client verification needs a reachable HTTPS installation, configured delivery for customer verification, and client account/configuration access. No native-MCP or OAuth library requires an AI-provider key or a persistent worker process.
