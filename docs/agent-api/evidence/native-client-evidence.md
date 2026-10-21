# Native client integration evidence

Tested on 20 October 2026 using installed **Codex CLI 0.153.4** and **Claude Code 2.1.281**, a disposable MySQL 8.4.11 database and PHP 8.5.7 HTTP server. Both clients connected directly to VoxelBooking's `/mcp` endpoint with temporary, scoped staff bearer credentials. Existing client login was used through the normal CLI; no account credentials were read or exported and no user client configuration was edited.

This verifies the **staff timeslot bearer workflow** below. It does not verify branded OAuth login, public/customer client workflows, resource/capacity/event workflows in these clients, hosted ChatGPT/Claude, VS Code or Cursor. Server protocol conformance and automated staff/customer OAuth tests are separate evidence. The actual protocol version negotiated by the branded clients was not captured.

## Observed results

| Step | Codex 0.153.4 | Claude Code 2.1.281 |
| --- | --- | --- |
| Initialize and discover native tools | Passed | Passed |
| Read bookings and search staff availability | Passed; UTC, available times returned | Passed; UTC, available times returned |
| Prepare create, approve in browser, commit | Confirmed booking and receipt | Confirmed booking and receipt |
| Prepare reschedule, approve, commit | Original rescheduled; replacement confirmed | Original rescheduled; replacement confirmed |
| Prepare cancellation, approve, commit | Replacement cancelled | Replacement cancelled |
| Read cancelled booking | Passed | Passed |
| Repeat cancellation with identical handle/key | Same receipt; no extra mutation | Same receipt; no extra mutation |
| Revoke through authenticated browser route | Protected MCP returns 401 | Protected MCP returns 401 |
| Reconnect using revoked credential | Native initialization failed with AuthRequired and resource metadata challenge | Server status failed; no MCP tools exposed |

Each connection has exactly three mutation receipts and six durable `booking.notification` intents (customer and business for each mutation). The retry created no additional receipt or intent. This proves queuing, not external notification delivery. Database assertions, non-secret synthetic record IDs and SHA-256 hashes of private client logs are in [native-clients/results.json](native-clients/results.json).

Browser approvals used the actual staff login, preview review GET and CSRF-protected review POST in the disposable HTTP harness. The CLI did not approve its own preview. The first Codex non-interactive commit was denied by Codex's client-side approval policy before the server ran it. The test then explicitly allowed only the three requested mutation tools for that invocation; VoxelBooking browser approval remained mandatory. This is an observed client setting requirement, not an application authorization bypass.

## Reproduce safely

Start `php scripts/test-isolated.php --keep --serve` from the app repository. It creates a private copied app, guarded disposable database and four-worker server. Record its printed run directory as `RUN_DIR`. Keep that process running. **The following preparation resets only that disposable fixture.** It refuses an ordinary database through the test bootstrap.

```sh
php docs/agent-api/evidence/native-clients/prepare.php "$RUN_DIR"
python3 docs/agent-api/evidence/native-clients/prepare-prompts.py "$RUN_DIR"
```

The scripts keep fresh credentials in private `clients/fixture.json` and `clients/claude-mcp.json` files. Do not publish those files, prompts containing review handles, or raw logs. `fixture_guard.py` validates the run manifest, loopback URL and server run identity before Python actions.

For each `CLIENT` (`codex`, then `claude`), run the read and preview phases:

```sh
python3 docs/agent-api/evidence/native-clients/run.py "$RUN_DIR" "$CLIENT" read "$RUN_DIR/clients/$CLIENT-read-prompt.txt"
python3 docs/agent-api/evidence/native-clients/run.py "$RUN_DIR" "$CLIENT" preview "$RUN_DIR/clients/$CLIENT-preview-prompt.txt"
python3 docs/agent-api/evidence/native-clients/approve-fixture-review.py "$RUN_DIR" preview "$CLIENT"
python3 docs/agent-api/evidence/native-clients/run.py "$RUN_DIR" "$CLIENT" create "$RUN_DIR/clients/$CLIENT-create-prompt.txt"
python3 docs/agent-api/evidence/native-clients/approve-fixture-review.py "$RUN_DIR" create "$CLIENT"
python3 docs/agent-api/evidence/native-clients/run.py "$RUN_DIR" "$CLIENT" reschedule "$RUN_DIR/clients/$CLIENT-reschedule-prompt.txt"
python3 docs/agent-api/evidence/native-clients/approve-fixture-review.py "$RUN_DIR" reschedule "$CLIENT"
python3 docs/agent-api/evidence/native-clients/run.py "$RUN_DIR" "$CLIENT" cancel "$RUN_DIR/clients/$CLIENT-cancel-prompt.txt"
```

The approval helper is restricted to the fixture. It signs in as its synthetic business owner and submits the real browser form with its session and CSRF token. It preserves the reviewed preview rather than reconstructing mutation arguments.

After both clients finish:

```sh
python3 docs/agent-api/evidence/native-clients/revoke-fixture.py "$RUN_DIR"
python3 docs/agent-api/evidence/native-clients/run.py "$RUN_DIR" codex revoked "$RUN_DIR/clients/codex-revoked-prompt.txt"
python3 docs/agent-api/evidence/native-clients/run.py "$RUN_DIR" claude revoked "$RUN_DIR/clients/claude-revoked-prompt.txt"
php docs/agent-api/evidence/native-clients/assert-outcomes.php "$RUN_DIR"
```

Inspect the client logs for actual tool calls and errors as well as the database assertions. Model narrative alone is not verification. Stop the isolated runner to remove its server and container. The retained directory contains expired/revoked test secrets; keep it private or delete it when no longer needed.

## Exact client command shapes

`run.py` executes argument arrays, supplies a fixture bearer environment variable for Codex, and captures JSONL in the private fixture directory. The prompt is passed as one argument.

```sh
codex exec --ignore-user-config --ephemeral --skip-git-repo-check \
  --sandbox read-only --color never --json -C "$RUN_DIR/clients" \
  -c 'mcp_servers.voxelbooking.url="<fixture URL>/mcp"' \
  -c 'mcp_servers.voxelbooking.bearer_token_env_var="VOXELBOOKING_CLIENT_TEST_TOKEN"' \
  -c 'mcp_servers.voxelbooking.startup_timeout_sec=30' \
  -c 'features.apps=false' \
  -c 'mcp_servers.voxelbooking.tools.booking_create.approval_mode="approve"' \
  -c 'mcp_servers.voxelbooking.tools.booking_reschedule.approval_mode="approve"' \
  -c 'mcp_servers.voxelbooking.tools.booking_status_set.approval_mode="approve"' \
  -o "<private final response file>" "<phase prompt>"

claude --print --restricted --setting-sources '' --strict-mcp-config \
  --mcp-config "$RUN_DIR/clients/claude-mcp.json" \
  --no-session-persistence --disable-slash-commands --no-chrome --tools '' \
  --allowedTools 'mcp__voxelbooking__*' --permission-mode dontAsk \
  --output-format stream-json --verbose "<phase prompt>"
```

The tested settings are isolated to these invocations. Codex's shell sandbox remained read-only; Claude had no shell/file tools enabled. Server role, business, scope and review checks apply independently.

Official setup/configuration references checked: [Codex MCP](https://developers.openai.com/codex/mcp/), [Codex configuration reference](https://learn.chatgpt.com/docs/config-file/config-reference), [Claude Code MCP](https://code.claude.com/docs/en/mcp). Availability in another version or hosted product requires its own check.
