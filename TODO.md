# Live verification

- Issue #20: use the operator's configured GenRouter account to confirm the live
  catalog's actual vision/audio models and one explicit transcription. The public
  unauthenticated /v1/models endpoint returned Missing API key on 2026-10-21.
  No live key or hosting session was available in this run. The implementation
  chooses defaults from provider-reported capabilities and does not guess IDs.
- Compare installation progress with the operator's actual hosting. PHP 8.1/8.3
  and offline provider/HTTP/browser suites establish code behavior, not external
  account permissions, SMTP delivery or a particular hosting configuration.
