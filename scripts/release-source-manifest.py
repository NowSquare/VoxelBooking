#!/usr/bin/env python3
"""Fingerprint release sources without reading runtime data or credentials."""

import argparse
import hashlib
import json
import subprocess
from pathlib import Path

SCOPES = {
    "application": [
        "app", "config", "lang", "resources", "templates", "tests", "scripts",
        "public/assets", "public/index.php", "public/.htaccess", ".github",
        ".htaccess", ".gitignore", "demo-seed.php", "storage/demo/demo.db",
        ".env.example", "composer.json", "composer.lock", "package.json",
        "package-lock.json", "phpunit.xml", "routes.php", "VERSION", "vite.config.js",
        "README.md",
    ],
    "site": [
        "app", "bootstrap/app.php", "bootstrap/providers.php", "config", "content",
        "Modules", "resources", "routes",
        "tests", "public/assets/agent-api", "public/build", "public/llms.txt", "composer.json",
        "composer.lock", "package.json", "package-lock.json", "vite.config.js", "README.md",
    ],
}


def snapshot(root: Path, scopes: list[str]) -> dict:
    files = {}
    for scope in scopes:
        target = root / scope
        candidates = target.rglob("*") if target.is_dir() else [target]
        for path in candidates:
            if path.is_file() and not path.is_symlink():
                relative = path.relative_to(root).as_posix()
                # Only repository sources and compiled assets are in SCOPES.
                # Keep transient caches and Python bytecode out of the record.
                if ("__pycache__" in path.parts or path.suffix == ".pyc"
                        or path.name == ".DS_Store" or path.name.startswith("._")):
                    continue
                files[relative] = hashlib.sha256(path.read_bytes()).hexdigest()
    files = dict(sorted(files.items()))
    canonical = "".join(f"{path}\0{digest}\n" for path, digest in files.items())
    base = subprocess.check_output(
        ["git", "-C", str(root), "rev-parse", "HEAD"], text=True
    ).strip()
    return {
        "base_commit": base,
        "state": "working-tree source and compiled assets; not a release commit",
        "scopes": scopes,
        "file_count": len(files),
        "sha256": hashlib.sha256(canonical.encode("utf-8")).hexdigest(),
        "files": files,
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--site", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    arguments = parser.parse_args()
    application = Path(__file__).resolve().parents[1]
    document = {
        "version": 1,
        "algorithm": "SHA-256 of sorted UTF-8 path + NUL + file SHA-256 + newline",
        "exclusions": "Runtime data except the explicit fictional demo fixture; .env, vendor, node_modules, and evidence reports",
        "application": snapshot(application, SCOPES["application"]),
        "site": snapshot(arguments.site.resolve(), SCOPES["site"]),
    }
    arguments.output.write_text(json.dumps(document, indent=2) + "\n")
    for name in SCOPES:
        item = document[name]
        print(f"{name}: {item['file_count']} files; SHA-256 {item['sha256']}")


if __name__ == "__main__":
    main()
