# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0]

First release.

### Added

- reCAPTCHA v2 checkbox, v2 invisible and v3, verified server-side against
  Google's `siteverify` before any core handler sees the submission.
- Protected forms: contact, account creation, newsletter and product reviews,
  each with its own switch, action name and score threshold.
- Refusal path that removes the submission trigger fields instead of letting the
  core handler run, while keeping what the visitor typed.
- Fail-closed / fail-open behaviour, applied to transport outages only: a missing
  token or an explicit refusal from Google is always refused.
- Single-use token handling, so a captured token cannot be replayed.
- Score, action and hostname checks for v3.
- Per-shop settings on multistore installs.
- Back-office configuration screen with a "Test the keys" diagnostic; the secret
  key is never sent back to the browser.
- GDPR consent integration through the `registerGDPRConsent` hook.
- Test-key detection, with a warning in the back office.
- A fallback PSR-4 autoloader: no Composer install on the merchant's server.
- Test suites: unit, integration (a fake of Google's endpoint), end-to-end over
  real HTTP against PrestaShop 8.2.8 and 9.2.0, a back-office rendering harness,
  and a browser test for the widget.

### Fixed

- Per-shop settings were never read: `Configuration::get()` takes the shop group
  before the shop, and the module passed the shop id in the wrong slot.
- Saving could silently do nothing. The row was deleted before
  `Configuration::updateValue()`, whose `hasKey()` answers from the cache built
  when the request booted, so PrestaShop took its UPDATE branch, matched no row,
  and reported success.
- On a shop without the multistore feature, settings were written with an
  `id_shop` and never read back; everything belongs to the global row there.
- The widget could stay invisible: a queued callback was invoked without the
  `grecaptcha` object, and the resulting error was swallowed by the "a broken
  third-party script must not take the page down" guard.