"""Копирует модуль из указанной версии локального репозитория.
Запускается разработчиком на компьютере, сайт эту утилиту не вызывает.
Настройки и данные установленного сайта сюда не копируются.
"""
import argparse
import io
from pathlib import Path
import subprocess
import tarfile

ROOT = Path(__file__).resolve().parents[1]
PREFIX = "admin/llm_connector/"
PROTECTED = {"data", ".env", "selfheal/token.php", "selfheal/token.local.php"}

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", required=True, help="папка локального Git-репозитория с модулем")
    parser.add_argument("--revision", required=True, help="полный хеш нужного коммита (40 символов)")
    args = parser.parse_args()
    # Проверяем точную версию до чтения файлов: подвижная ветка не подходит.
    sha = subprocess.check_output(
        ["git", "-C", args.source, "rev-parse", args.revision + "^{commit}"], text=True
    ).strip()
    if sha != args.revision or len(sha) != 40:
        raise SystemExit("Укажите полный хеш коммита, а не имя ветки")
    archive = subprocess.check_output(["git", "-C", args.source, "archive", sha, "admin/llm_connector", "specs/llm_connector"])
    imported = 0
    with tarfile.open(fileobj=io.BytesIO(archive), mode="r:") as source:
        for item in source.getmembers():
            # Принимаем только обычные файлы внутри двух разрешённых папок.
            name = item.name
            if not item.isfile() or ".." in Path(name).parts:
                continue
            if name.startswith(PREFIX):
                relative = name[len(PREFIX):]
                if any(relative == p or relative.startswith(p + "/") for p in PROTECTED):
                    continue
            elif not name.startswith("specs/llm_connector/"):
                continue
            destination = ROOT / name
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_bytes(source.extractfile(item).read())
            imported += 1
    print(f"Скопировано файлов: {imported}. Проверьте изменения и запустите bash tests/run_all.sh.")

if __name__ == "__main__":
    main()
