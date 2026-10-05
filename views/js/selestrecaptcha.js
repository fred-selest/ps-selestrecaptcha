/**
 * Selest reCAPTCHA — front office.
 *
 * Contract with the module: window.selestRecaptchaConfig holds the site key,
 * the flavour and one entry per protected form. This file:
 *
 *   1. looks for the protected forms in the page;
 *   2. only then loads anything from Google;
 *   3. puts a `grecaptcha-response` value inside each protected form;
 *   4. shows the reason the server gave for turning a submission down.
 *
 * No jQuery, no build step, ES5 syntax: it has to run on a shop that has not
 * rebuilt its theme assets.
 */
(function (window, document) {
  'use strict';

  var config = window.selestRecaptchaConfig;

  if (!config || !config.siteKey || !config.targets) {
    return;
  }

  var TOKEN_FIELD = config.tokenField || 'grecaptcha-response';
  var SCRIPT_LOADED = false;
  var pendingCallbacks = [];
  var bypassNextSubmit = false;
  var shownFlash = false;

  /* ---------------------------------------------------------------- utils */

  function toArray(list) {
    return Array.prototype.slice.call(list || []);
  }

  /**
   * The marker identifies the form by what it contains, never by an id: ids
   * move between Classic and Hummingbird, field names do not.
   */
  function findForms(markerSelector) {
    var forms = [];

    toArray(document.querySelectorAll('form')).forEach(function (form) {
      if (form.querySelector(markerSelector)) {
        forms.push(form);
      }
    });

    return forms;
  }

  function submitButton(form) {
    return form.querySelector(
      'button[type="submit"], input[type="submit"], button:not([type])'
    );
  }

  function insertBeforeSubmit(form, node) {
    var anchor = submitButton(form);

    if (anchor && anchor.parentNode) {
      anchor.parentNode.insertBefore(node, anchor);
      return;
    }

    form.appendChild(node);
  }

  function ensureTokenInput(form) {
    var input = form.querySelector('input[name="' + TOKEN_FIELD + '"]');

    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = TOKEN_FIELD;
      input.value = '';
      form.appendChild(input);
    }

    return input;
  }

  function showError(form, message) {
    if (shownFlash) {
      return;
    }

    var box = document.createElement('div');
    box.className = 'selestrecaptcha-alert';
    box.setAttribute('role', 'alert');
    box.textContent = message;

    insertBeforeSubmit(form, box);
    shownFlash = true;
  }

  /* ------------------------------------------------------------ google I/O */

  function scriptQuery() {
    var query = config.version === 'v3'
      ? '?render=' + encodeURIComponent(config.siteKey)
      : '?onload=srRecaptchaReady&render=explicit';

    if (config.language) {
      query += '&hl=' + encodeURIComponent(config.language);
    }

    return query;
  }

  function loadGoogleScript() {
    if (SCRIPT_LOADED) {
      return;
    }

    SCRIPT_LOADED = true;

    var script = document.createElement('script');
    script.src = (config.script || 'https://www.google.com/recaptcha/api.js') + scriptQuery();
    script.async = true;
    script.defer = true;
    script.onerror = function () {
      // Google is unreachable or blocked (consent manager, ad blocker, DNS).
      // Let the next attempt retry, and leave no stale token behind.
      SCRIPT_LOADED = false;

      toArray(document.querySelectorAll('form')).forEach(function (form) {
        var input = form.querySelector('input[name="' + TOKEN_FIELD + '"]');

        if (input) {
          input.value = '';
        }
      });
    };

    document.head.appendChild(script);
  }

  window.srRecaptchaReady = function () {
    var callbacks = pendingCallbacks;
    pendingCallbacks = [];

    callbacks.forEach(function (callback) {
      runSafely(callback, window.grecaptcha);
    });
  };

  /**
   * Google hands out window.grecaptcha as soon as its script has parsed, but
   * its client is not usable yet: render() called in that window throws "No
   * reCAPTCHA clients exist yet". The call is retried once Google says it is
   * ready, because a widget that never appears looks exactly like a page where
   * everything works.
   */
  function runSafely(callback, argument) {
    try {
      callback(argument);
    } catch (error) {
      var grecaptcha = window.grecaptcha;

      if (grecaptcha && typeof grecaptcha.ready === 'function') {
        grecaptcha.ready(function () {
          callback(grecaptcha);
        });
        return;
      }

      /* A broken third-party script must not take the page down. */
    }
  }

  /**
   * Calls back with a window.grecaptcha that is actually ready to use, loading
   * the Google script if it is not there yet.
   *
   * The callback takes the object as its argument: a queued callback that is
   * later called with nothing leaves the code inside it reading a property of
   * undefined, which is a silent way to never render the widget.
   */
  function withGrecaptcha(callback) {
    var grecaptcha = window.grecaptcha;

    if (!grecaptcha) {
      pendingCallbacks.push(callback);
      loadGoogleScript();
      return;
    }

    if (typeof grecaptcha.ready === 'function') {
      grecaptcha.ready(function () {
        callback(grecaptcha);
      });
      return;
    }

    callback(grecaptcha);
  }

  function whenReady(form) {
    if (form.srReady) {
      return form.srReady;
    }

    form.srReady = new Promise(function (resolve) {
      withGrecaptcha(function (grecaptcha) {
        if (config.version !== 'v2_invisible') {
          // v3 needs no widget: execute() is enough.
          resolve();
          return;
        }

        // v2 invisible still needs a widget, because execute() takes its id.
        var container = document.createElement('div');
        container.className = 'selestrecaptcha-widget';
        form.appendChild(container);

        var widgetId = grecaptcha.render(container, {
          sitekey: config.siteKey,
          size: 'invisible',
          callback: function (token) {
            ensureTokenInput(form).value = token;
          },
          'expired-callback': function () {
            ensureTokenInput(form).value = '';
          },
          'error-callback': function () {
            ensureTokenInput(form).value = '';
          }
        });

        form.srWidgetId = widgetId;
        resolve();
      });
    });

    return form.srReady;
  }

  /* --------------------------------------------------------- v2 checkbox */

  function renderCheckbox(form) {
    var container = document.createElement('div');
    container.className = 'selestrecaptcha-widget';
    insertBeforeSubmit(form, container);

    withGrecaptcha(function (grecaptcha) {
      grecaptcha.render(container, {
        sitekey: config.siteKey,
        theme: 'light',
        callback: function (token) {
          // Google writes its own textarea inside this container, which lives in
          // the form: the token travels with the POST on its own.
          ensureTokenInput(form).value = token;
        },
        'expired-callback': function () {
          ensureTokenInput(form).value = '';
        },
        'error-callback': function () {
          ensureTokenInput(form).value = '';
        }
      });
    });
  }

  /* ---------------------------------------------- v2 invisible / v3 token */

  function tokenForSubmit(form, action) {
    return whenReady(form).then(function () {
      return new Promise(function (resolve, reject) {
        withGrecaptcha(function (grecaptcha) {
          var request = config.version === 'v3'
            ? grecaptcha.execute(config.siteKey, { action: action })
            : grecaptcha.execute(form.srWidgetId, { action: action });

          if (request && typeof request.then === 'function') {
            request.then(resolve, reject);
            return;
          }

          reject(new Error('grecaptcha.execute did not return a promise'));
        });
      });
    });
  }

  function resubmit(form, submitter) {
    bypassNextSubmit = true;

    // The submit button's name/value ("submitMessage", "submitCreate"…) is part
    // of the POST. A programmatic form.submit() would drop it and the shop would
    // read the submission as "not submitted"; requestSubmit keeps it.
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit(submitter || undefined);
      return;
    }

    if (submitter && submitter.name) {
      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = submitter.name;
      hidden.value = submitter.value;
      form.appendChild(hidden);
    }

    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  }

  function interceptSubmits(form, action) {
    // Capture phase on the document, so this runs before any handler bound to
    // the form itself — the review module posts with jQuery from a handler it
    // registered when its own script loaded.
    document.addEventListener(
      'submit',
      function (event) {
        var submitted = event.target;

        if (submitted !== form || bypassNextSubmit) {
          return;
        }

        event.preventDefault();
        event.stopPropagation();

        var submitter = event.submitter || submitButton(form);

        tokenForSubmit(form, action)
          .then(function (token) {
            ensureTokenInput(form).value = token;
            resubmit(form, submitter);
          })
          .catch(function () {
            showError(
              form,
              (config.messages && config.messages.unreachable) ||
                'The captcha could not be loaded. Please try again.'
            );
          });
      },
      true
    );
  }

  /* ------------------------------------------------------------- bootstrap */

  Object.keys(config.targets).forEach(function (name) {
    var target = config.targets[name];

    if (!target || !target.form) {
      return;
    }

    findForms(target.form).forEach(function (form) {
      if (form.dataset && form.dataset.srRecaptcha === '1') {
        return;
      }

      form.dataset.srRecaptcha = '1';

      if (config.version === 'v2_checkbox') {
        renderCheckbox(form);
      } else {
        interceptSubmits(form, target.action);
      }
    });
  });

  if (config.flash && !shownFlash) {
    var forms = document.querySelectorAll('form');

    if (forms.length) {
      showError(forms[0], config.flash);
    }
  }
})(window, document);