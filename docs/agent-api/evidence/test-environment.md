# Disposable test environment evidence

Final status: both complete runtime matrices of the 1.3.0 source pass **1,973 tests and 29,224 assertions**, with no failures, errors, skips, warnings or deprecations. See the [retained final reports](matrix/README.md). The dated progression below preserves intermediate red and green evidence; earlier totals are historical snapshots.

The test runner uses a fresh MySQL Docker container and a private application copy for every invocation. It omits source `.env`, storage, uploads and repository metadata. A manifest binds PHP, the HTTP server and fixtures to the run's loopback database address and restricted account. Installer tests can write only the copy's `.env`. The database guard rejects changed credentials before PDO connects.

Run from the application checkout:

```sh
php scripts/test-isolated.php
php scripts/test-isolated.php --filter TestIsolationTest
php scripts/test-isolated.php --keep --serve
```

Requirements: Docker and PHP with `pdo_mysql`, `pcntl` and `posix`. The default image is `mysql:8.4`; pass `--mysql-image=mysql:8.0` or an explicit MariaDB tag for a compatibility run. The runner prints a private `run-tests.sh` command and an HTTP URL. `--serve` keeps that run active until interruption. Separate runner invocations use separate fixtures. The runner stops all four PHP workers and removes its container, including volumes, on normal exit and SIGINT/SIGTERM. `--keep` preserves files after shutdown for inspection.

Tests capture mail in `mail.jsonl`; database `email_log` records the result. Configuration failures remain failures. `TestEnvironment::failMail('reason')` simulates delivery failure across HTTP workers; reset with `failMail(null)`. No SMTP or Resend request leaves a test process. A private `/__test/webhook` endpoint captures webhook bodies and headers when supplied the run ID in `X-Test-Run`.

Default PHPUnit refuses execution without the manifest. Memory-only unit tests may use an explicit `--no-configuration --bootstrap vendor/autoload.php` invocation when they do not open MySQL or make HTTP requests. Forty-four existing test files now load test configuration through `TestEnvironment`; two direct MySQL PDO paths assert the configuration before connecting.

## Evidence

- First disposable guard run: MySQL 8.4.11, PHP 8.5.7, PHPUnit 11.5.55; 6 tests and 10 assertions passed. The HTTP port closed after exit.
- Direct configured PHPUnit without a manifest stops in bootstrap with the runner instruction. Bootstrap contains no configuration-file write or database operation.
- Expanded isolation and mail recovery run: 18 tests, 50 assertions, no failures. PHP 8.5 reports two existing `curl_close` deprecations. Evidence: `/tmp/voxelbooking-agent-w1-safety-final.log`.
- The same 18 tests and 50 assertions pass without warnings or deprecations on PHP 8.3.32 with MySQL 8.4.11. Evidence: `/tmp/voxelbooking-agent-w1-php83.log`.
- Full baseline during parallel implementation: 1,450 tests, 20,639 assertions; 2 errors, 71 failures, 10 warnings, 66 deprecations and 8 skips. Evidence: `/tmp/voxelbooking-agent-w1-baseline.xml` and `/tmp/voxelbooking-agent-w1-baseline.log`. This snapshot is not a release pass.

Most baseline failures exercise former ID-only management/privacy authority after the capability implementation began. They include `PublicBookingMetadataTest`, `RescheduleAvailabilityTest`, `PrivacyEndpointTest`, `PublicBookingUtcContractTest`, `SelfServiceCancelTest`, `SelfServiceRescheduleTest` and manage-link assertions. Their owning work package must update fixtures to explicit purpose-bound grants while retaining denied-access coverage. Two MCP failures captured tests during their red step; the protocol owner reports subsequent focused verification. The initial mail sink altered missing-configuration/failure expectations; the expanded isolation run above verifies the corrected behavior.

The MySQL account has `USAGE` on the server and privileges only on its random test schema. Integration coverage verifies that it cannot read `mysql.user`, that changed database destinations fail before PDO, that HTTP and test processes share one run ID, and that transport changes still reach the capture sink.

The runner does not claim client interoperability, concurrency correctness, complete migrations across supported versions, or product release readiness. Those require the action and protocol suites.

## Upgrade and recovery rehearsal (A52)

`php scripts/test-isolated.php --upgrade-check --mysql-image=mysql:8.0` runs before the private HTTP server on an asserted-empty database. It creates the actual migration001–028 schema and fictional fixture data, upgrades through041, verifies existing booking/customer/key records and the effective legacy read-only ceiling, and exercises the repaired per-key limiter against the original IP-only rate_limits table. It resets the checkpoint to reproduce committed DDL replay, removes a subset of migration034 columns to exercise partial completion, injects a failed042 migration and resumes it without incorrectly advancing the checkpoint, then restores the complete synthetic v28 schema/data backup and upgrades again.

The red rehearsal failed with duplicate customer_name_snapshot in034, demonstrating unsafe replay after MySQL committed its ALTER. Migration034 now conditionally adds each missing column; no existing column or data is overwritten. Both full and partial replay pass.

- PHP8.3.32 / MySQL8.4.11: all rehearsal checks passed. `/tmp/voxelbooking-agent-upgrade-green.log`.
- PHP8.3.32 / MySQL8.0.46: all rehearsal checks passed. `/tmp/voxelbooking-agent-upgrade-mysql80.log`.
- CI runs the same upgrade rehearsal before the full disposable-database suite in each PHP/MySQL matrix entry.

The backup contains only synthetic data and remains in the private disposable run directory when --keep is requested. The rehearsal never opens or backs up an ordinary installation database. The retained JSON evidence records exact versions and each recovery outcome.

The second CI runtime was reproduced locally: PHP8.5.10/MySQL8.4.11 upgrade and recovery rehearsal passed, `/tmp/voxelbooking-agent-upgrade-php85.log`. Its retained evidence records all checks as true.

## Complete compatibility matrix

The settled application snapshot, including the final booking-policy review, multipart catalog uploads, native connection activity and A20–A23 acceptance additions, passed on both supported CI pairs:

| PHP | MySQL | Tests | Assertions | Failures / errors / skips | Warnings / deprecations |
| --- | --- | ---: | ---: | --- | --- |
| 8.3.32 | 8.0.46 | 1,694 | 24,599 | 0 / 0 / 0 | 0 / 0 |
| 8.5.10 | 8.4.11 | 1,694 | 24,599 | 0 / 0 / 0 | 0 / 0 |

Both runner processes exited zero and stopped their database containers and four HTTP workers. JUnit totals independently match the console totals. No ordinary application database or uploaded content was used.

Evidence: `/tmp/voxelbooking-agent-final2-php83-mysql80.log`, `/tmp/voxelbooking-agent-final2-php83-mysql80.xml`, `/tmp/voxelbooking-agent-final2-php85-mysql84.log`, `/tmp/voxelbooking-agent-final2-php85-mysql84.xml`.

Reproduce with `php scripts/test-isolated.php --mysql-image=mysql:8.0 --display-skipped --display-deprecations --display-warnings` under PHP 8.3, and the same command with `mysql:8.4` under PHP 8.5. The snapshots contain 190 human routes, 129 application operations and 42 capability groups; the parity checker passed immediately before copying them.

A subsequent narrow GitUpdater recovery finding was then corrected: when code was already updated but a migration failed, a retry could return `up_to_date` without rerunning the migration callback. The complete matrix above predates that correction; its independent review and focused recovery verification are recorded separately.

## Final verification after updater recovery

The source before the 1.3.0 review fixes, including updater migration retry, the persistent lock inode, the native recovery form and stabilized grant-expiry fixtures, passes **1,698 tests and 24,683 assertions per runtime** on PHP 8.3.32 / MySQL 8.0.46 and PHP 8.5.10 / MySQL 8.4.11. Both report zero failures, errors, skips, warnings and deprecations, and exit zero. Unit totals are 965 tests / 19,111 assertions; integration totals are 733 tests / 5,572 assertions.

The reports are retained outside ignored local planning documents in `docs/agent-api/evidence/matrix/`: complete sanitized JUnit XML, console logs, upgrade rehearsal logs, reproducible commands, image digests and a checksummed `results.json`. The checksum verifier and XML totals pass. The upgrade rehearsals' Migrator, migration files and rehearsal script were compared byte-for-byte with the current checkout and match.

An intermediate PHP 8.5 run correctly rejected a fixture credential whose separately recomputed expiry exceeded its grant by one second. SharedBookingOperationsTest and DeletionApplicationTest now use the returned connection expiry. Both final runs include this test-only correction; production expiry enforcement was not weakened.

Final parity verification passes 190 human routes, 129 operations and all 42 capability groups. `git diff --check` is clean.
