"""Protect source attribution when the starter README is rewritten or rebranded."""

from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parent.parent
ORIGINAL = "https://github.com/dansury/neurosaas_template/"


class TemplateAttributionTests(unittest.TestCase):
    def test_readme_has_visible_markdown_link_to_original_template(self):
        prose = (ROOT / "README.md").read_text(encoding="utf-8")
        prose = re.sub(r"<!--.*?-->", "", prose, flags=re.S)
        prose = re.sub(r"(?m)^\s*(`{3,}|~{3,})[^\n]*\n.*?^\s*\1\s*$",
                       "", prose, flags=re.S)
        prose = re.sub(r"`+[^`\n]*`+", "", prose)
        link = r"(?<!!)\[[^\]\n]+\]\(" + re.escape(ORIGINAL) + r"\)"
        self.assertRegex(prose, link,
                         "Keep a visible Markdown link to the original template; "
                         "a /generate link does not replace source attribution.")

    def test_attribution_remains_in_specification_and_agent_contracts(self):
        for path in ("specs/006-clean-starter/spec.md", "CLAUDE.MD",
                     ".specify/memory/constitution.md"):
            with self.subTest(path=path):
                self.assertIn(ORIGINAL, (ROOT / path).read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
