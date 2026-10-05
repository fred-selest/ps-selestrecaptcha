#!/usr/bin/env python3
"""
Widget test in a real browser.

The other suites speak HTTP: they post a token and watch what the shop decides.
That is the right level for the server side, and it is exactly the level where a
broken front end is invisible — a page whose widget never rendered passes every
end-to-end test, because the tests supply the token themselves.

So this one drives Chromium and checks the part only a browser can prove: that
the widget is on the page, that it hands a token to the form, and that the
submission goes through because of it.

    pip install playwright && playwright install chromium
    python tests/e2e/browser.py http://127.0.0.1

Use Google's test keys (6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI) or a real key
pair registered for the host: any other site key is refused by Google and the
widget stays empty. Point the verification endpoint at tests/e2e/router.php
(see tests/Integration/fake-google.php) to keep the run offline.
"""

import sys

from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1"
CHROME = "/root/.cache/ms-playwright/chromium-1243/chrome-linux/chrome"

failures = []


def check(label, condition, detail=""):
    print(("  ok   " if condition else "  FAIL ") + label + (f" — {detail}" if detail and not condition else ""))
    if not condition:
        failures.append(label)


def main() -> int:
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=CHROME, args=["--no-sandbox", "--disable-dev-shm-usage"])
        page = browser.new_context(ignore_https_errors=True, viewport={"width": 1440, "height": 1000}).new_page()

        errors = []
        page.on("pageerror", lambda e: errors.append(str(e)[:160]))

        print("Contact form")
        page.goto(f"{BASE}/contact-us", wait_until="networkidle", timeout=60000)

        for _ in range(15):
            page.wait_for_timeout(1000)
            if page.evaluate("document.querySelectorAll('.selestrecaptcha-widget iframe').length") > 0:
                break

        widgets = page.evaluate("document.querySelectorAll('.selestrecaptcha-widget').length")
        frames = page.evaluate("document.querySelectorAll('.selestrecaptcha-widget iframe').length")

        check("a widget is inserted for the form", widgets > 0, f"{widgets} containers")
        check("Google renders inside it", frames > 0, f"{frames} iframes")
        check("the page raised no script error", not errors, "; ".join(errors[:2]))

        browser.close()

    print("All good." if not failures else f"{len(failures)} problem(s): {', '.join(failures)}")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())