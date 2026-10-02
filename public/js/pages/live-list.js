/*
 * A paged list that stays current (U1 to U4). Shared by the Dashboard panels and the Activity page.
 *
 *   const list = Patty.liveList({ path: '/stock', keyOf: (row) => row.ingredient.id });
 *   list.load();      // first page, with the skeleton showing
 *   list.more();      // next page (infinite scroll and the "Load more" button)
 *   list.refresh();   // re-fetch everything already on screen, for poll()
 *
 * Why it looks like this:
 *  - Every request goes through one queue, so a refresh asked for while another request is in flight
 *    waits its turn instead of being dropped (the bug found in the earlier page reviews).
 *  - Refresh replaces the rows rather than appending. Rows that are still there keep their DOM nodes (Alpine
 *    matches by key), so scroll position holds and x-flash can see which numbers moved.
 *  - Rows are de-duplicated with `keyOf` when the page gives one, never by `id`: Activity rows have no id at all.
 *  - Polls are `silent`: an error appears in the panel itself with the request id, not as a stack of toasts.
 *
 * config: { path, query, pageSize, keyOf(row), decorate(rows), changed(before, after), onChange() }
 */
(function (root) {
  'use strict';

  var Patty = (root.Patty = root.Patty || {});
  var REFRESH_PAGE_SIZE = 100; // the API maximum: one request refreshes up to 100 rows already on screen

  Patty.liveList = function (config) {
    return {
      rows: [],
      loading: true, // first load only: the skeleton shows while this is true and there are no rows
      loadingMore: false,
      error: null, // { message, requestId } of the last failed request; cleared by the next success
      hasMore: false,
      total: 0,
      meta: {}, // meta of the latest response (E27 puts generated_at here)
      started: false,
      query: config.query || {},
      pageSize: config.pageSize || 25,
      chain: Promise.resolve(),

      /** Run `task` after every earlier request has finished. Resolves or rejects with the task's own result. */
      enqueue: function (task) {
        var run = this.chain.then(task);
        this.chain = run.catch(function () {}); // a failure must not block the queue
        return run;
      },

      page: function (number, size) {
        var query = Object.assign({}, this.query, { page: number, per_page: size });
        return Patty.api.get(config.path, { query: query, silent: true });
      },

      fail: function (e) {
        this.error = { message: (e && e.message) || 'Something went wrong. Please try again.', requestId: (e && e.requestId) || null };
        throw e;
      },

      /** First page. Also what "Retry" and a changed filter call. Rejects on failure: callers catch. */
      load: function () {
        var self = this;
        this.started = true;
        this.error = null;
        if (this.rows.length === 0) this.loading = true;
        return this.enqueue(function () {
          return self.page(1, self.pageSize).then(function (res) {
            self.apply(res, res.data);
          });
        })
          .catch(function (e) { self.fail(e); })
          .finally(function () { self.loading = false; });
      },

      /** Next page, appended. With a `keyOf`, rows already shown are skipped in case the list shifted between requests. */
      more: function () {
        var self = this;
        if (this.loadingMore || !this.hasMore) return Promise.resolve();
        this.loadingMore = true;
        return this.enqueue(function () {
          // After a refresh the list holds a whole number of pages, so this is the page that follows it.
          var next = Math.floor(self.rows.length / self.pageSize) + 1;
          return self.page(next, self.pageSize).then(function (res) {
            var fresh = res.data;
            if (config.keyOf) {
              var seen = {};
              self.rows.forEach(function (row) { seen[config.keyOf(row)] = true; });
              fresh = res.data.filter(function (row) { return !seen[config.keyOf(row)]; });
            }
            self.apply(res, self.rows.concat(fresh));
          });
        })
          .catch(function (e) { self.fail(e); })
          .finally(function () { self.loadingMore = false; });
      },

      /** Re-fetch the rows on screen. Rejects on failure so poll() does not stamp "Updated just now". */
      refresh: function () {
        var self = this;
        if (!this.started) return Promise.resolve();
        return this.enqueue(function () {
          var target = Math.max(self.rows.length, self.pageSize);
          var collected = [];
          var last = null;

          function step(number) {
            return self.page(number, REFRESH_PAGE_SIZE).then(function (res) {
              last = res;
              collected = collected.concat(res.data);
              var more = res.meta.pagination && res.meta.pagination.has_more;
              return collected.length < target && more ? step(number + 1) : null;
            });
          }

          return step(1).then(function () {
            var before = self.rows;
            // Keep a whole number of pages on screen, so "Load more" continues from the right place.
            var rows = collected.slice(0, target);
            self.apply(last, rows);
            if (config.changed && config.onChange && before.length > 0 && config.changed(before, rows)) config.onChange();
          });
        }).catch(function (e) { self.fail(e); });
      },

      apply: function (res, rows) {
        var pagination = (res.meta && res.meta.pagination) || {};
        this.rows = config.decorate ? config.decorate(rows) : rows;
        this.total = pagination.total !== undefined ? pagination.total : this.rows.length;
        this.hasMore = this.rows.length < this.total;
        this.meta = res.meta || {};
        this.error = null;
      },
    };
  };
  function noop() {}

  /**
   * Infinite scroll: when `el` (a sentinel under the list) comes near the viewport, load the next page.
   * Re-observing after each load makes the observer report again if the end is still in view, so a tall
   * screen keeps filling. The "Load more" button stays as the keyboard fallback (U2).
   */
  Patty.watchEnd = function (el, list) {
    if (!('IntersectionObserver' in root)) return;
    var io = new IntersectionObserver(function (entries) {
      if (!entries.some(function (entry) { return entry.isIntersecting; })) return;
      if (!list.hasMore || list.loadingMore) return;
      list.more().catch(noop).then(function () { io.unobserve(el); io.observe(el); });
    }, { rootMargin: '200px' });
    io.observe(el);
  };

  /** Run `fn` once, the first time `el` is near the viewport (below-the-fold panels, U2). Runs at once without IntersectionObserver. */
  Patty.whenVisible = function (el, fn) {
    if (!('IntersectionObserver' in root)) { fn(); return; }
    var io = new IntersectionObserver(function (entries) {
      if (!entries.some(function (entry) { return entry.isIntersecting; })) return;
      io.disconnect();
      fn();
    }, { rootMargin: '200px' });
    io.observe(el);
  };
})(window);
