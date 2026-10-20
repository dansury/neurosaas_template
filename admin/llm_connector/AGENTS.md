# LLM connector integration instructions

Read ../../CLAUDE.MD, ../../specs/index.md and ../../specs/llm_connector/index.md before editing. Open only the specification matching the changed file.

Use selfheal/bootstrap.php and the public Selfheal\\SelfHeal API. Keep reporting in ask/local mode until the operator explicitly opts in through administration. Do not copy a developer reporting credential into this template. Never send test reports or mail during verification. Preserve data, .env, selfheal/token.local.php and reporting consent. Maintain host isolation, API compatibility, chained handlers, private state and capability checks. Tests must not update a live module tree. Each release increments module.json version; public API changes use semantic versioning.
