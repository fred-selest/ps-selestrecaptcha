<p align="center">
  <img src="docs/assets/selestrecaptcha-logo.svg" width="128" alt="Selest reCAPTCHA">
</p>

<h1 align="center">Selest reCAPTCHA</h1>

<p align="center">Google reCAPTCHA for the PrestaShop forms that collect the most spam.</p>

<p align="center">
  <img src="https://img.shields.io/badge/PrestaShop-8.2%20LTS%20%7C%209.x-8f5ba2?logo=prestashop&logoColor=white" alt="PrestaShop 8.2 LTS and 9.x">
  <img src="https://img.shields.io/badge/license-AFL--3.0-1f6feb" alt="AFL-3.0">
  <img src="https://github.com/fred-selest/ps-selestrecaptcha/actions/workflows/tests.yml/badge.svg" alt="Tests">
</p>

---

Spam does not arrive through your storefront, it arrives through your forms: the
contact form, account creation, the newsletter block, product reviews. This
module puts Google's reCAPTCHA on those four, and **verifies every token
server-side before the shop acts on the submission**. A missing or refused token
means the core handler never sees the POST.

### The contact form, protected

![Contact form with the reCAPTCHA checkbox](docs/screenshots/contact-form.png)

*The screenshot was taken on a test shop using Google's official test keys —
hence the red "testing purposes only" banner Google adds to them.*

### The back office

![Back-office configuration screen](docs/screenshots/admin-settings.png)

Every field is in one screen: which versions to protect, per-form switches, the
score threshold, what to do when Google is unreachable, and the messages the
visitor sees. **Test the keys** calls Google from the server and tells you
whether the pair works, is refused, or simply cannot be reached.

![The module reporting that Google cannot be reached](docs/screenshots/admin-selftest.png)

---

## What it does

- **Three flavours**: reCAPTCHA v2 checkbox, v2 invisible, and v3 (invisible,
  scored). v3 additionally checks the **score**, the **action** and, optionally,
  the **hostname** Google reports.
- **Four protected forms**, each with its own switch, its own action name and its
  own score threshold: contact, account creation, newsletter, product reviews.
- **A submission that fails never has side effects.** The module removes the
  trigger fields (`contactform`, `ps_emailsubscription`, `productcomments`, …)
  before the core handler runs, so nothing is stored, no customer is created and
  no mail is sent. What the visitor typed is kept.
- **Replay protection.** A token is accepted once, like Google itself does.
- **Explicit failure modes.** A missing token or a refusal from Google is always
  a refusal, whatever you configured. "Accept when Google cannot be reached"
  only applies to transport outages — that is a business decision, not a
  technical detail, so it is one switch and the warning is right above it.
- **Multistore aware**: one settings row per shop.
- **GDPR**: the module registers with the `registerGDPRConsent` hook, so a
  consent manager can stop the widget from loading at all.
- **No Composer, no build step.** The module ships its own PSR-4 autoloader.

## Requirements

- PrestaShop **8.2 LTS** or **9.x** (developed and tested against 8.2.8 and 9.2.0)
- PHP 8.1+
- A reCAPTCHA key pair from <https://www.google.com/recaptcha/admin>

## Install

Download the zip from the [Releases](../../releases) page (built from the tag,
byte for byte the commit you see here), then in PrestaShop go
to **Modules → Add a new module → Upload a module**, and install and configure
it.

From a shell:

```bash
unzip selestrecaptcha.zip -d modules/
php modules/selestrecaptcha/tools/install-module.php
```

Or straight from GitHub:

```bash
git clone https://github.com/fred-selest/ps-selestrecaptcha.git modules/selestrecaptcha
php modules/selestrecaptcha/tools/install-module.php
```

## Configure

1. Create a key pair for your domain in the reCAPTCHA console, then paste the
   **site key** and the **secret key** here. **Test the keys** tells you
   immediately whether the pair is accepted.
2. Pick a version. Start with the v2 checkbox if you are not sure: it is the one
   that works everywhere and the least surprising for customers.
3. Leave **Refuse the submission** as the answer to "when Google cannot be
   reached". You lose the odd customer during a Google outage, you never lose a
   spam message. Switch it only if a lost lead is worse than a lost message.
4. For v3, leave the threshold at `0.5` until you have real traffic, then watch
   the `selestrecaptcha` log channel and tune it.

The secret key is never sent back to the browser, and an empty secret field on
save means "keep the stored one".

## Privacy

Google receives the visitor's IP address and the page URL when the widget runs.
Say so in your privacy policy — Google's
[data processing terms](https://policies.google.com/terms) apply. With **Log
every decision** on, the visitor's IP is written to the PrestaShop channel named
`selestrecaptcha`; tokens and secrets are never logged.

## Tests

```bash
composer install
./vendor/bin/phpunit
```

End-to-end against a real shop, with a fake of Google's verification endpoint:

```bash
php tests/e2e/e2e.php http://127.0.0.1 /path/to/prestashop ps828
```

Back-office page, save round-trip and key self test:

```bash
php modules/selestrecaptcha/tools/render-admin.php --save --test
```

The JavaScript is tested in a real browser (Chromium via Playwright), because
the widget is exactly the part an HTTP-only test never executes:

```bash
pip install playwright && playwright install chromium
python tests/e2e/browser.py http://127.0.0.1
```

## Notes

- The verification endpoint is configurable so shops behind a firewall can point
  at a local proxy. Plain HTTP is accepted for loopback addresses only; anything
  else must be HTTPS.
- This is not a rate limiter and not a firewall. Pair it with one if you are
  under a real attack.

## Support

Issues and ideas: <https://github.com/fred-selest/ps-selestrecaptcha/issues>

## License

AFL-3.0 — see [LICENSE](LICENSE).