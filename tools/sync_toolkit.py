"""Import an exact canonical Git revision without any reporting credential."""
import argparse
import io
import json
from pathlib import Path
import subprocess
import tarfile

ROOT = Path(__file__).resolve().parents[1]
FILES = {"llm.php", "parser.php", "mailer.php", "model_catalog.php", "settings_store.php",
         "diag_log.php", "auto_pull.php", "config.php", "setup.php", "module.json",
         "selfheal_admin.php", "selfheal_install.php", "selfheal_seal.php",
         "README.md", "AGENTS.md", "spec.md", ".env.example"}
DIRECTORIES = {"selfheal", "spec", "tests"}
EXCLUDED = {"selfheal/token.php", "selfheal/token.local.php"}

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", required=True, help="local canonical Git checkout")
    parser.add_argument("--revision", required=True, help="full immutable commit SHA")
    args = parser.parse_args()
    sha = subprocess.check_output(["git", "-C", args.source, "rev-parse", args.revision + "^{commit}"], text=True).strip()
    if sha != args.revision or len(sha) != 40:
        raise SystemExit("Use the full exact commit SHA")
    archive = subprocess.check_output(["git", "-C", args.source, "archive", sha])
    target = ROOT / "modules" / "site_yacloud_openrouter"
    target.mkdir(parents=True, exist_ok=True)
    imported = []
    with tarfile.open(fileobj=io.BytesIO(archive), mode="r:") as source:
        for item in source.getmembers():
            name = item.name
            parts = Path(name).parts
            if not item.isfile() or name in EXCLUDED or ".." in parts:
                continue
            if name not in FILES and (not parts or parts[0] not in DIRECTORIES):
                continue
            destination = target.joinpath(*parts)
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_bytes(source.extractfile(item).read())
            imported.append(name)
    (target / "source.json").write_text(json.dumps({
        "repository": "dansury/site_yacloud_openrouter", "commit": sha,
        "excluded": sorted(EXCLUDED), "files": sorted(imported)
    }, indent=2) + "\n", encoding="utf-8")
    print(f"Imported {len(imported)} canonical files at {sha}")

if __name__ == "__main__":
    main()

