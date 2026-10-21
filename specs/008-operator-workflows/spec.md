# Operator workflows

## Repository connection
The token creation link precedes credential fields and requests Contents read,
Issues write and Pull requests read. Pasting a user token loads accessible
repositories, then branches, with main selected where present. Requests use POST,
CSRF and existing administration authentication; tokens never enter URLs or JSON
responses. Feedback issues are submitted directly with the operator's token.

## Installation and versions
New setup does not ask for an additional installation password. Pending configuration is bound to the initiating browser session until account enrollment. Existing
verified administration authentication remains mandatory. ZIP upload and GitHub
updates share preservation rules, locking, atomic writes and rollback history.
Archive paths and symlinks are validated before extraction or mutation. Version
summaries include associated PR titles when GitHub provides them. Rollback keeps
auto updates paused and offers an exact prompt to restore that version through a
new commit/PR into main; changing the default branch is not suggested.

The standalone installer reports PHP/extension/write requirements and detected
server information before attempting setup. PHP 7.4+ syntax remains supported;
toolkit requirements are stated separately. No Apache version is fabricated when
the server does not disclose it. Installation progress is visible in the UI.

## Provider and feedback interface
New installations default to GenRouter, then OpenRouter, then Yandex. Secondary
providers are optional disclosures. Only OpenRouter exposes a proxy field.
Toolkit config, model catalogs, vision and feedback transcription use GenRouter
credentials. Models are selected from provider-reported capabilities; unknown
audio/vision support is never presented as verified. Existing saved credentials
and choices survive upgrades. Cache reset is a small cross next to Refresh.

A top-right change-request control opens a movable dialog with a dedicated drag
handle, screenshot uploads/paste and recording when an audio model is configured.
Nothing is submitted until the operator presses Send. Optional diagnostics retain
their existing explicit consent and frozen preview. The obsolete embedding-tip
screen and dashboard slogan are removed. The interface uses black accent ink.

SMTP is optional for application mail; explain its purpose and show host-specific
application-password help for Yandex, Mail.ru and Gmail without a Yandex default.
README begins with the owner's requested consultation links and retains original
template attribution.

## Computer updater
Root pull.py is a stdlib CLI sibling of pull.php. It offers a prefilled token URL,
repository/branch choices, exact-commit downloads, preserved local settings and
data, dependency setup and one-minute foreground polling. Python download guidance
is in its header and README, since a script cannot execute without its interpreter.

## Verification
Use offline HTTP and provider fixtures, malicious ZIP and rollback tests, Python
updater fixtures and desktop/mobile browser checks. Never send real feedback,
email, paid inference or screenshots during tests. Live capabilities that cannot
be verified remain explicitly tracked rather than inferred from saving a key.
