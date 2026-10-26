# Russian onboarding and improvement ideas

## Scope

This specification applies to `pull.php`, its feedback endpoint, and the uploadable `index.php` starter interface. It does not alter the optional toolkit contract.

## Russian-first setup

The first-run and control-panel UI defaults to Russian. English remains an explicit, remembered manual choice. The GitHub key helper generates a fine-grained PAT link scoped to the selected repository and asks for `Contents: Read` and `Issues: Read and write`; it asks for `Pull requests: Read` only when the administrator enables pull-request preview. A token-related 401/404 deployment or feedback failure repeats the helper link and states that an expired, revoked, or insufficiently scoped key must be replaced.

The configured panel provides a blank-by-default password replacement field. The previous password and password hash are never rendered, logged, or included in status output.

## Ideas for improvement

The feedback action and the starter-interface link are labelled `Идеи для доработки`. Its title is optional; an omitted title receives a dated Russian default, while the description remains mandatory. The form links to the configured repository issue list and never writes credentials or private customer information to GitHub.

After a first successful submission in a browser session, the UI displays one congratulation exactly once. It offers two next actions: submit another idea or open the connected issue list with a ready-to-copy `resolve <issues URL>` prompt.

## AI provider cards

Provider settings stay collapsed by default, with GenRouter first and expanded initially. Its card links directly to its API-key page and explains the key, model, and fallback order. All cards distinguish an API used by an already deployed service from the separate interactive coding subscription required to build that service. Saved provider order is validated server-side and disabled providers have no execution path.

## Pull-request preview help

The optional pull-request number field explains that a PR proposes changes which have not yet been merged into the main branch. Operators can find its number in the repository's Pull requests tab or at the end of a URL such as `https://github.com/owner/repository/pull/42`. Entering `42` installs the current changes from that PR in the selected repository instead of the branch. Use a separate test installation: preview is an actual deployment, can contain unfinished code, and changes files in this installation. Ordinary installation and updates leave the field empty. Private repositories need Pull requests: Read permission. After testing, clear `pr_number` in `pull-config.php` to return to branch updates, including after merge or closure.

## Verification

Offline HTTP tests cover Russian default language, least-privilege PAT URL construction, password replacement, optional feedback title, one-time congratulations, and GenRouter-first rendering. No test creates an issue, sends mail, or performs a billed AI request.
