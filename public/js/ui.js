/*
 * Small UI helpers shared by every page: toasts, the confirm dialog, theme, freshness,
 * the quantity input, time formatting and status labels.
 *
 * Loaded before Alpine, so everything registers on `alpine:init`.
 * S5 / U12: text is always set with x-text or textContent. Nothing here builds HTML from data.
 */
(function (root) {
  'use strict';

  var Patty = (root.Patty = root.Patty || {});
  var units = Patty.units;

  /* ---------- storage that never throws (private windows, blocked storage) ---------- */

  function storageGet(key) {
    try { return root.localStorage.getItem(key); } catch (e) { return null; }
  }
  function storageSet(key, value) {
    try { root.localStorage.setItem(key, value); } catch (e) { /* the preference just is not remembered */ }
  }

  /* ---------- status labels (design.md: Draft and Short neutral, Sent info, Partially received warn, Closed ok) ---------- */

  var STATUS = {
    draft: { label: 'Draft', tone: 'neutral' },
    sent: { label: 'Sent', tone: 'info' },
    received: { label: 'Partially received', tone: 'warn' }, // the API status `received` means some, not all, of the order has arrived
    closed: { label: 'Closed', tone: 'ok' },
    short: { label: 'Short', tone: 'neutral' },
    negative: { label: 'Negative', tone: 'danger' },
  };

  function statusMeta(status, shortClosed) {
    if (status === 'closed' && shortClosed) status = 'short';
    return STATUS[status] || { label: String(status), tone: 'neutral' };
  }

  /* ---------- time (U8): the viewer's timezone, relative where useful, exact on hover ---------- */

  var absoluteFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });
  var relativeFormat = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

  function localTime(iso) {
    var date = new Date(iso);
    return isNaN(date) ? '' : absoluteFormat.format(date);
  }

  function timeAgo(iso) {
    var date = new Date(iso);
    if (isNaN(date)) return '';
    var seconds = Math.round((date.getTime() - Date.now()) / 1000);
    var abs = Math.abs(seconds);
    if (abs < 60) return relativeFormat.format(seconds, 'second');
    if (abs < 3600) return relativeFormat.format(Math.round(seconds / 60), 'minute');
    if (abs < 86400) return relativeFormat.format(Math.round(seconds / 3600), 'hour');
    return relativeFormat.format(Math.round(seconds / 86400), 'day');
  }

  /* ---------- toasts ---------- */

  var pendingToasts = [];
  var toastSeq = 0;
  var TOAST_MS = { success: 4000, info: 5000, warn: 7000, error: 9000 };

  /** notify({ tone: 'success' | 'info' | 'warn' | 'error', message, requestId }) */
  function notify(toast) {
    if (root.Alpine && root.Alpine.store('toasts')) {
      root.Alpine.store('toasts').push(toast);
    } else {
      pendingToasts.push(toast); // Alpine has not started yet; flushed on alpine:init
    }
  }

  /* ---------- freshness helper for pages that load data without poll() ---------- */

  function markFresh() {
    root.dispatchEvent(new CustomEvent('patty:refreshed', { detail: { at: Date.now() } }));
  }

  /* ---------- Alpine registrations ---------- */

  document.addEventListener('alpine:init', function () {
    var Alpine = root.Alpine;

    Alpine.store('toasts', {
      items: [],
      push: function (toast) {
        var item = {
          id: ++toastSeq,
          tone: toast.tone || 'info',
          message: String(toast.message || ''),
          requestId: toast.requestId || '',
        };
        this.items.push(item);
        var self = this;
        root.setTimeout(function () { self.dismiss(item.id); }, TOAST_MS[item.tone] || TOAST_MS.info);
      },
      dismiss: function (id) {
        this.items = this.items.filter(function (item) { return item.id !== id; });
      },
    });
    pendingToasts.splice(0).forEach(function (toast) { Alpine.store('toasts').push(toast); });

    /*
     * Confirm dialog (U6). One shared <dialog id="confirm-dialog"> lives in the layout.
     * confirm({ title, message, lines, confirmLabel, tone, run }) resolves true or false.
     * With `run`, the dialog stays open and the button shows the busy state until the request ends,
     * and a failure is shown inside the dialog. That is what stops a double-click recording twice (rule 1).
     */
    Alpine.store('confirm', {
      title: '',
      message: '',
      lines: [],
      confirmLabel: 'Confirm',
      tone: 'primary',
      busy: false,
      error: '',
      run: null,
      resolve: null,

      open: function (options) {
        var dialog = document.getElementById('confirm-dialog');
        this.title = options.title || 'Are you sure?';
        this.message = options.message || '';
        this.lines = options.lines || [];
        this.confirmLabel = options.confirmLabel || 'Confirm';
        this.tone = options.tone || 'primary';
        this.run = options.run || null;
        this.busy = false;
        this.error = '';
        var self = this;
        return new Promise(function (resolve) {
          self.resolve = resolve;
          dialog.showModal();
        });
      },

      accept: function () {
        if (this.busy) return;
        var self = this;
        var dialog = document.getElementById('confirm-dialog');
        if (!this.run) {
          this.settle(true);
          dialog.close();
          return;
        }
        this.busy = true;
        this.error = '';
        Promise.resolve()
          .then(this.run)
          .then(function () {
            self.busy = false;
            self.settle(true);
            dialog.close();
          })
          .catch(function (e) {
            self.busy = false;
            self.error = (e && e.message) || 'That did not work. Please try again.';
          });
      },

      cancel: function () {
        if (this.busy) return;
        document.getElementById('confirm-dialog').close();
      },

      settle: function (value) {
        if (this.resolve) {
          var resolve = this.resolve;
          this.resolve = null;
          resolve(value);
        }
      },

      // Escape, the backdrop or cancel() end up here: anything not accepted counts as "no".
      closed: function () {
        this.settle(false);
      },
    });

    /* Theme menu in the identity chip. 'system' removes the attribute, so the OS preference applies. */
    Alpine.data('themeMenu', function () {
      return {
        open: false,
        pref: 'system',
        init: function () {
          var saved = storageGet('patty-theme');
          this.pref = saved === 'light' || saved === 'dark' ? saved : 'system';
        },
        set: function (pref) {
          this.pref = pref;
          if (pref === 'system') {
            document.documentElement.removeAttribute('data-theme');
          } else {
            document.documentElement.setAttribute('data-theme', pref);
          }
          storageSet('patty-theme', pref);
        },
      };
    });

    /* "Updated N s ago" (principle 4). Listens for patty:refreshed, fired by poll() and markFresh(). */
    Alpine.data('freshness', function () {
      return {
        at: null,
        label: 'Waiting for data',
        stale: false,
        pulsing: false,
        init: function () {
          var self = this;
          root.setInterval(function () { self.render(); }, 1000);
        },
        touch: function () {
          var self = this;
          this.at = Date.now();
          this.render();
          this.pulsing = false;
          // Re-adding the class on the next frame restarts the pulse animation.
          root.requestAnimationFrame(function () { self.pulsing = true; });
          root.setTimeout(function () { self.pulsing = false; }, 800);
        },
        render: function () {
          if (this.at === null) return;
          var seconds = Math.max(0, Math.round((Date.now() - this.at) / 1000));
          this.stale = seconds > 30;
          this.label = seconds < 60 ? 'Updated ' + seconds + ' s ago' : 'Updated ' + Math.floor(seconds / 60) + ' min ago';
        },
      };
    });

    /* First-visit banner, dismissal remembered in localStorage. */
    Alpine.data('introBanner', function () {
      return {
        visible: false,
        init: function () { this.visible = storageGet('patty-intro-dismissed') !== '1'; },
        dismiss: function () {
          this.visible = false;
          storageSet('patty-intro-dismissed', '1');
        },
      };
    });

    /*
     * Quantity input (D-038). The parent binds the integer base value with x-model:
     *   <x-quantity-input unit="g" context="purchase" x-model="line.quantity" />
     * `base` is the integer in g, ml or piece, or null while the text is empty or invalid.
     * Switching g/kg re-expresses the same quantity, it never silently changes it.
     */
    Alpine.data('quantityInput', function (config) {
      return {
        unit: config.unit,
        modes: units.modesFor(config.unit),
        mode: units.defaultMode(config.unit, config.context),
        text: '',
        base: null,
        error: '',
        lastBase: null,

        init: function () {
          var self = this;
          if (config.value !== null && config.value !== undefined) this.base = config.value;
          if (this.base !== null) {
            this.lastBase = this.base;
            this.text = units.toInputText(this.base, this.mode);
          }
          // The parent can set the value from outside (a prefilled delivery line). Our own writes are skipped.
          this.$watch('base', function (value) {
            if (value === self.lastBase) return;
            self.lastBase = value;
            self.error = '';
            self.text = value === null || value === undefined ? '' : units.toInputText(value, self.mode);
          });
          // x-modelable may deliver the parent's starting value after init(), so check once more when the tree is ready.
          this.$nextTick(function () {
            if (self.base !== null && self.base !== undefined && self.text === '') {
              self.lastBase = self.base;
              self.text = units.toInputText(self.base, self.mode);
            }
          });
        },

        label: function (mode) {
          return mode === 'piece' ? 'pcs' : mode;
        },

        // A server message in the unit the user typed: "1100 g above the 1050 g limit" becomes "1.1 kg ... 1.05 kg".
        rewrite: function (message) {
          return units.rewriteError(message, this.mode);
        },

        publish: function (base, error) {
          this.error = error;
          this.lastBase = base;
          this.base = base;
          // The precision error is available before submit, to the page as well (design.md rule 4).
          // `mode` is the unit the user is typing in (g, kg, ml, L, piece), so the page can re-express a server 422 in it (D-038).
          this.$dispatch('quantity-change', { base: base, error: error, mode: this.mode });
        },

        onInput: function () {
          if (this.text.trim() === '') {
            this.publish(null, '');
            return;
          }
          var result = units.parseInput(this.text, this.mode, { allowZero: Boolean(config.allowZero) });
          if (result.ok) this.publish(result.base, '');
          else this.publish(null, result.message);
        },

        setMode: function (mode) {
          if (mode === this.mode) return;
          var before = units.parseInput(this.text, this.mode, { allowZero: true });
          this.mode = mode;
          if (before.ok && this.text.trim() !== '') {
            this.text = units.toInputText(before.base, mode);
          }
          this.onInput();
        },
      };
    });

    /* x-flash="stock.on_hand": the element flashes when the number changes after first render (design.md Motion). */
    Alpine.directive('flash', function (el, directive, utilities) {
      var read = utilities.evaluateLater(directive.expression);
      var previous = null;
      var timer = null;

      utilities.effect(function () {
        read(function (raw) {
          var value = Number(raw);
          if (previous !== null && !isNaN(value) && value !== previous) {
            var tone = value > previous ? 'flash-up' : 'flash-down';
            el.classList.remove('flash-up', 'flash-down');
            void el.offsetWidth; // reflow so the animation restarts when values change twice in a row
            el.classList.add(tone);
            root.clearTimeout(timer);
            timer = root.setTimeout(function () { el.classList.remove(tone); }, 1200);
          }
          previous = isNaN(value) ? previous : value;
        });
      });
    });
  });

  /* ---------- exports ---------- */

  /*
   * Re-expresses the messages of a 422 `errors` object in the unit each field was typed in.
   *   catch (e) { if (e.status === 422) this.errors = Patty.fieldErrors(e.errors, { quantity: this.mode }); }
   * `modes` maps a field name to g, kg, ml, L or piece; a plain string applies one unit to every field.
   * Fields without a mode keep the server's wording.
   */
  function fieldErrors(errors, modes) {
    var out = {};
    Object.keys(errors || {}).forEach(function (field) {
      var mode = typeof modes === 'string' ? modes : modes && modes[field];
      out[field] = [].concat(errors[field]).map(function (message) {
        return mode ? units.rewriteError(message, mode) : message;
      });
    });
    return out;
  }

  Patty.fieldErrors = fieldErrors;
  Patty.notify = notify;
  Patty.markFresh = markFresh;
  Patty.statusMeta = statusMeta;
  Patty.time = { local: localTime, ago: timeAgo };
  Patty.confirm = function (options) { return root.Alpine.store('confirm').open(options); };
})(window);
