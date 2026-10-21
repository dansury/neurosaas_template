# Feedback dictation plan

Reuse ToolkitLLM and its configured fallback chain. Keep transcript protection
in a small deployment module with an injectable formatting callback. Return
text plus an optional warning to the existing feedback form. Do not introduce
dependencies or new credentials; PHP 7.4 syntax is required.
