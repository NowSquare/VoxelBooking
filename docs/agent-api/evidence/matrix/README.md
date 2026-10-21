# Final application compatibility matrix

Both complete runs pass **1,698 tests and 24,683 assertions**, with zero failures, errors, skips, warnings or deprecations. Each contains 965 unit tests and 733 integration tests. The final source includes the Git updater recovery correction and stable grant-expiry fixtures.

| Runtime | Console | Complete JUnit |
| --- | --- | --- |
| PHP 8.3.32 / MySQL 8.0.46 | [Log](php83-mysql80.log) | [XML](php83-mysql80.xml) |
| PHP 8.5.10 / MySQL 8.4.11 | [Log](php85-mysql84.log) | [XML](php85-mysql84.xml) |

[results.json](results.json) records the exact versions, Docker image digests, suite totals, executable commands and SHA-256 of every retained report. Absolute private temporary paths are replaced by `isolated-run`. No database configuration, credentials, customer data or mail captures are included.

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

An earlier post-updater PHP 8.5 attempt found a fixture that calculated the same intended expiry twice. Crossing a second caused the credential to exceed its grant, which production correctly rejected. The booking and deletion fixtures now reuse the returned grant expiry. Both retained full runs include that correction.

To verify report checksums from the repository root:

```sh
python3 - <<'PY'
from pathlib import Path
import hashlib, json
root = Path("docs/agent-api/evidence/matrix")
manifest = json.loads((root / "results.json").read_text())
for run in manifest["reports"] + manifest["upgrade_rehearsals"]:
    for key in ("console", "junit"):
        if key in run:
            report = run[key]
            actual = hashlib.sha256((root / report["path"]).read_bytes()).hexdigest()
            assert actual == report["sha256"], report["path"]
print("All report checksums match.")
PY
```
