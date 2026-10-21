# Final application compatibility matrix

Both complete runs of the 1.3.0 source pass **1,973 tests and 29,223 assertions**, with zero failures, errors, skips, warnings or deprecations. Each contains 1,088 unit tests and 885 integration tests, including HTTP v2 and native MCP success tests for all 132 operations.

| Runtime | Console | Complete JUnit |
| --- | --- | --- |
| PHP 8.3.32 / MySQL 8.0.46 | [Log](php83-mysql80.log) | SHA-256 in the manifest |
| PHP 8.5.10 / MySQL 8.4.11 | [Log](php85-mysql84.log) | SHA-256 in the manifest |

The complete JUnit reports (about 500 KB each) stay out of the repository; CI keeps them as artifacts. [results.json](results.json) records the Git tree of every tested source directory, the exact versions, Docker image digests, suite totals, executable commands and SHA-256 of every retained report. Absolute private temporary paths are replaced by `isolated-run`. No database configuration, credentials, customer data or mail captures are included.

Each run creates a separate MySQL container, restricted database account, private application copy and four local HTTP workers. The runner never loads the ordinary application's `.env`, storage or uploads. Both final runs stopped their databases and HTTP workers successfully.

Run from the repository root with the indicated PHP runtime:

```sh
php scripts/test-isolated.php --mysql-image=mysql:8.0 --display-skipped --display-deprecations --display-warnings
php scripts/test-isolated.php --mysql-image=mysql:8.4 --display-skipped --display-deprecations --display-warnings
```

Use PHP 8.3 for the first command and PHP 8.5 for the second. The full commands with JUnit output paths are in the manifest.

The real version-28 upgrade, interrupted-DDL repair and backup-restore rehearsals also pass:

- [PHP 8.3 / MySQL 8.0](upgrade-php83-mysql80.log)
- [PHP 8.3 / MySQL 8.4](upgrade-php83-mysql84.log)
- [PHP 8.5 / MySQL 8.4](upgrade-php85-mysql84.log)

Reproduce these with `php scripts/test-isolated.php --upgrade-check --mysql-image=mysql:8.0` or `mysql:8.4`. They exercise the same migration engine and migration files. The final full matrix additionally covers retrying a migration after code is already current, retaining the updater lock inode, and displaying the native recovery form with CSRF protection.

The first 1.3.0 matrix found two defects, both fixed before these runs. Calendar entries stamped `DTSTAMP` with the current clock, so two reads of one booking differed when they crossed a second; the stamp now comes from the booking's ULID. The upgrade rehearsal's failing probe migration was numbered 042, which the real review-secret migration now uses, so the migrator skipped it; the probe now takes the number after the newest migration.

To verify report checksums from the repository root (a JUnit report is checked when you place the CI artifact beside the logs):

```sh
python3 - <<'PY'
from pathlib import Path
import hashlib, json
root = Path("docs/agent-api/evidence/matrix")
manifest = json.loads((root / "results.json").read_text())
checks = [run[key] for run in manifest["reports"] for key in ("console", "junit")]
checks += [{"path": run["log"], "sha256": run["sha256"]} for run in manifest["upgrade_rehearsals"]]
for report in checks:
    path = root / report["path"]
    if path.exists():
        assert hashlib.sha256(path.read_bytes()).hexdigest() == report["sha256"], report["path"]
print("All present report checksums match.")
PY
```
