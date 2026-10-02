/*
 * Dashboard (PTY-12): KPIs (E28), stock with incoming (E27), open orders (E16 status=open) and the guided tour.
 * One poll refreshes all of it every 10 s, so the page answers "is this up to date?" without being asked (U3).
 * API data reaches the page only through x-text and attribute bindings (U12).
 */
(function (root) {
  'use strict';

  var Patty = root.Patty;
  var units = Patty.units;
  var POLL_MS = 10000;

  function noop() {}

  function storageGet(key) {
    try { return root.localStorage.getItem(key); } catch (e) { return null; }
  }
  function storageSet(key, value) {
    try { root.localStorage.setItem(key, value); } catch (e) { /* the tour just is not remembered */ }
  }
  function storageRemove(key) {
    try { root.localStorage.removeItem(key); } catch (e) { /* nothing to forget */ }
  }

  /* ---------- the guided "Try it" card (U10, D-027) ---------- */

  /*
   * Where each step ticks from. All of it is read, never written, except step 5:
   *   1 Send an order      an E29 `purchase_order.sent` event after the tour started
   *   2 Receive a delivery an E29 `delivery.recorded` event after the tour started
   *   3 Sell in the POS    an E29 `sale.recorded` event, channel `pos`, after the tour started
   *   4 Watch stock change this dashboard saw an On hand or Incoming number change between two polls
   *   5 See what happened  the Activity page was opened (it sets a localStorage flag)
   * "After the tour started" uses the server clock (E27 meta.generated_at) so a wrong laptop clock cannot break it.
   * Only the newest 100 events are read, which is plenty for a demo and keeps this to one cheap request.
   */
  var TOUR = {
    dismissed: 'patty-tour-dismissed',
    started: 'patty-tour-started',
    watched: 'patty-tour-watched',
    activity: 'patty-tour-activity',
  };

  document.addEventListener('alpine:init', function () {
    var Alpine = root.Alpine;

    Alpine.store('tour', {
      dismissed: storageGet(TOUR.dismissed) === '1',
      startedAt: storageGet(TOUR.started),
      watched: storageGet(TOUR.watched) === '1',
      visitedActivity: storageGet(TOUR.activity) === '1',
      seen: { sent: false, received: false, sold: false },
      draft: null, // the first draft order, for step 1's link

      /** Fix the starting line once. Later visits keep it, so steps done before a reload stay done. */
      begin: function (serverNow) {
        if (this.startedAt) return;
        this.startedAt = serverNow || new Date().toISOString();
        storageSet(TOUR.started, this.startedAt);
      },

      hide: function () {
        this.dismissed = true;
        storageSet(TOUR.dismissed, '1');
      },

      show: function () {
        this.dismissed = false;
        storageRemove(TOUR.dismissed);
      },

      /** Start over: forget progress and begin counting from now. */
      restart: function () {
        this.startedAt = null;
        this.watched = false;
        this.visitedActivity = false;
        this.seen = { sent: false, received: false, sold: false };
        [TOUR.started, TOUR.watched, TOUR.activity].forEach(storageRemove);
        this.begin(null);
        this.show();
        this.refresh();
      },

      markWatched: function () {
        if (this.watched) return;
        this.watched = true;
        storageSet(TOUR.watched, '1');
      },

      /** Reads the draft order and the recent events. Failures are ignored: the card just stays unticked. */
      refresh: function () {
        var self = this;
        if (this.dismissed || !this.startedAt) return Promise.resolve();
        var since = new Date(this.startedAt).getTime();

        var drafts = Patty.api.get('/purchase-orders', { query: { status: 'draft', per_page: 1 }, silent: true })
          .then(function (res) {
            var first = res.data[0];
            self.draft = first ? { id: first.id, number: first.number } : null;
          }, noop);

        var events = Patty.api.get('/activity', { query: { per_page: 100 }, silent: true })
          .then(function (res) {
            var seen = { sent: false, received: false, sold: false };
            res.data.forEach(function (entry) {
              if (new Date(entry.created_at).getTime() < since) return;
              if (entry.event === 'purchase_order.sent') seen.sent = true;
              if (entry.event === 'delivery.recorded') seen.received = true;
              if (entry.event === 'sale.recorded' && entry.channel === 'pos') seen.sold = true;
            });
            // Once ticked, always ticked, even if the event later scrolls out of the newest 100.
            self.seen = {
              sent: self.seen.sent || seen.sent,
              received: self.seen.received || seen.received,
              sold: self.seen.sold || seen.sold,
            };
          }, noop);

        return Promise.all([drafts, events]);
      },

      /** The five steps. `openOrders` are the dashboard's loaded open orders, for step 2's link. */
      steps: function (openOrders) {
        var firstOpen = openOrders && openOrders[0];
        return [
          {
            n: 1,
            done: this.seen.sent,
            title: 'Send an order',
            hint: this.draft ? 'Open ' + this.draft.number + ' and send it to the supplier.' : 'Draft an order and send it to the supplier.',
            href: this.draft ? '/purchase-orders/' + encodeURIComponent(this.draft.id) : '/purchase-orders/new',
          },
          {
            n: 2,
            done: this.seen.received,
            title: 'Receive part of an open order',
            hint: 'Record a delivery for less than the full quantity.',
            href: firstOpen ? '/purchase-orders/' + encodeURIComponent(firstOpen.id) : '/purchase-orders',
          },
          {
            n: 3,
            done: this.seen.sold,
            title: 'Sell 3 Classic Burgers',
            hint: 'Use the POS Simulator, as if the till were calling.',
            href: '/pos',
          },
          {
            n: 4,
            done: this.watched,
            title: 'Watch stock and Incoming change here',
            hint: 'Stay on this page: the numbers flash when they move.',
            href: '#stock-panel',
          },
          {
            n: 5,
            done: this.visitedActivity,
            title: 'See what happened',
            hint: 'Every step above is in the activity trail.',
            href: '/activity',
          },
        ];
      },

      doneCount: function () {
        return this.steps(null).filter(function (step) { return step.done; }).length;
      },
    });

    /* ---------- the page ---------- */

    Alpine.data('dashboard', function () {
      return {
        kpis: null,
        kpiError: null,
        inflight: null,
        again: false,

        // E27 comes back ordered by name. Negative rows are red, tagged and counted in the KPI row,
        // so they stand out without breaking the paging (a client-side "negatives first" would only
        // sort the rows loaded so far).
        stock: Patty.liveList({
          path: '/stock',
          keyOf: function (row) { return row.ingredient.id; },
          // A number that moved between two polls is what step 4 of the tour waits for.
          changed: function (before, after) {
            var old = {};
            before.forEach(function (row) { old[row.ingredient.id] = row.on_hand + ':' + row.incoming; });
            return after.some(function (row) {
              var key = row.ingredient.id;
              return old[key] !== undefined && old[key] !== row.on_hand + ':' + row.incoming;
            });
          },
          onChange: function () { Alpine.store('tour').markWatched(); },
        }),

        orders: Patty.liveList({
          path: '/purchase-orders',
          query: { status: 'open' },
          keyOf: function (order) { return order.id; },
        }),

        init: function () {
          var self = this;
          var firstLoad = [this.loadKpis(), this.stock.load()];
          Promise.allSettled(firstLoad).then(function (results) {
            if (results.every(function (r) { return r.status === 'fulfilled'; })) Patty.markFresh();
            Alpine.store('tour').begin(self.stock.meta.generated_at);
            Alpine.store('tour').refresh();
          });
          // Pauses when the tab is hidden and refetches on focus and on bfcache return (api.js).
          Patty.api.poll(function () { return self.refreshAll(); }, POLL_MS);
        },

        /* ---- loading ---- */

        loadKpis: function () {
          var self = this;
          return Patty.api.get('/dashboard', { silent: true }).then(
            function (res) {
              self.kpis = res.data;
              self.kpiError = null;
            },
            function (e) {
              self.kpiError = { message: e.message, requestId: e.requestId || null };
              throw e;
            }
          );
        },

        /** The orders panel is below the fold on small screens, so it loads when scrolled near (U2). */
        watchOrders: function (el) {
          var self = this;
          Patty.whenVisible(el, function () { self.orders.load().catch(noop); });
        },

        /**
         * Everything on screen, once. A call that arrives while one is running is remembered and run
         * right after, not dropped: the user's Retry must never be swallowed by a poll.
         */
        refreshAll: function () {
          var self = this;
          if (this.inflight) {
            this.again = true;
            return this.inflight;
          }
          this.inflight = Promise.allSettled([
            this.loadKpis(),
            this.stock.refresh(),
            this.orders.refresh(),
            Alpine.store('tour').refresh(),
          ]).then(function (results) {
            var failed = results.find(function (r) { return r.status === 'rejected'; });
            if (failed) throw failed.reason; // so poll() does not stamp "Updated just now" over stale data
          }).finally(function () {
            self.inflight = null;
            if (self.again) {
              self.again = false;
              self.refreshAll().catch(noop);
            }
          });
          return this.inflight;
        },

        // A list that already has rows is refreshed with the rest; an empty one starts over.
        retry: function (list) {
          var run = list.rows.length === 0 ? list.load() : this.refreshAll();
          run.then(function () { Patty.markFresh(); }, noop);
        },

        retryKpis: function () {
          this.loadKpis().then(function () { Patty.markFresh(); }, noop);
        },

        /* ---- presentation ---- */

        qty: function (base, unit) { return units.display(base, unit); },

        stockStatus: function (row) {
          if (row.is_negative) return Patty.statusMeta('negative');
          if (row.on_hand === 0) return { label: 'Out of stock', tone: 'neutral' };
          return { label: 'In stock', tone: 'ok' };
        },

        meta: function (order) { return Patty.statusMeta(order.status, order.short_closed); },

        pct: function (order) {
          var value = Number(order.progress_percent);
          return isNaN(value) ? 0 : Math.max(0, Math.min(100, value));
        },

        orderHref: function (order) { return '/purchase-orders/' + encodeURIComponent(order.id); },

        /** "Beef 400 g, Buns 10 pcs outstanding", at most three lines, then "+2 more". */
        outstanding: function (order) {
          var open = order.lines.filter(function (line) { return line.quantity_outstanding > 0; });
          if (open.length === 0) return 'Nothing outstanding';
          var parts = open.slice(0, 3).map(function (line) {
            return line.ingredient.name + ' ' + units.display(line.quantity_outstanding, line.ingredient.unit).text;
          });
          if (open.length > 3) parts.push('+' + (open.length - 3) + ' more');
          return parts.join(', ') + ' outstanding';
        },

        steps: function () { return Alpine.store('tour').steps(this.orders.rows); },
      };
    });
  });
})(window);
