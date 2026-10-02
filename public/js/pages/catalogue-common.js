/*
 * Shared by the three catalogue pages (ingredients, suppliers, menu) and the history drawer:
 * one paged, lazy-loaded list. Each page spreads this into its own Alpine component, so a page
 * file only holds what is special about that page.
 *
 *   status:  loading (skeleton) | empty | error | loaded     (U4: the four designed states)
 *   load():      first page, used on start and by "Try again"
 *   loadMore():  next page, called by the scroll sentinel and by the "Load more" button (U2)
 *   refresh(id): refetch every page already on screen after a change, and flash row `id`
 *
 * Every list request is silent: api.js would show a toast, but a list shows its own inline error
 * with the request id, so the user is told exactly once.
 */
(function (root) {
  'use strict';

  var PER_PAGE = 25; // U2

  function pagedList(path, options) {
    // keyOf(item) names a row. Without it nothing is de-duplicated: E6 movements have no id,
    // and a made-up key would silently drop real rows.
    var keyOf = (options && options.keyOf) || null;
    var queued = null; // { id } for the one refresh that waits while another runs
    var observer = null;
    var sentinel = null;
    var flashTimer = null;

    function fetchPage(page) {
      return root.Patty.api.get(path, { query: { page: page, per_page: PER_PAGE }, silent: true });
    }

    return {
      items: [],
      status: 'loading',
      error: null, // { message, requestId, status } while status is 'error'
      page: 0,
      hasMore: false,
      loadingMore: false,
      moreError: '',
      refreshing: false,
      changedId: null,

      load: function () {
        var self = this;
        this.status = 'loading';
        this.error = null;
        return fetchPage(1).then(
          function (result) {
            self.items = result.data;
            self.afterPage(1, result);
            root.Patty.markFresh();
          },
          function (e) { self.fail(e); }
        );
      },

      loadMore: function () {
        var self = this;
        if (!this.hasMore || this.loadingMore || this.status !== 'loaded') return Promise.resolve();
        this.loadingMore = true;
        this.moreError = '';
        return fetchPage(this.page + 1).then(
          function (result) {
            var fresh = result.data;
            if (keyOf) {
              // A row can move between pages while we scroll, so never show the same row twice.
              var known = {};
              self.items.forEach(function (item) { known[keyOf(item)] = true; });
              fresh = fresh.filter(function (item) { return !known[keyOf(item)]; });
            }
            self.items = self.items.concat(fresh);
            self.afterPage(self.page + 1, result);
          },
          function (e) { self.moreError = (e && e.message) || 'Could not load more.'; }
        ).then(function () { self.loadingMore = false; self.rewatch(); self.runQueued(); });
      },

      // Refetches pages 1..page so the list keeps its length and the user keeps their scroll position.
      refresh: function (changedId) {
        var self = this;
        if (this.refreshing || this.loadingMore) {
          // Exactly one follow-up: a refresh that started before a save committed may have returned the old list.
          queued = { id: changedId || (queued && queued.id) || null };
          return Promise.resolve();
        }
        if (this.status === 'loading') return Promise.resolve();
        this.refreshing = true;
        var pages = Math.max(this.page, 1);
        var all = [];
        var last = null;
        var chain = Promise.resolve();
        for (var p = 1; p <= pages; p++) {
          (function (n) {
            chain = chain.then(function () {
              return fetchPage(n).then(function (result) { all = all.concat(result.data); last = result; });
            });
          })(p);
        }
        return chain.then(
          function () {
            self.items = all;
            self.afterPage(pages, last);
            root.Patty.markFresh();
            if (changedId) self.markChanged(changedId);
          },
          function (e) {
            // A failed background refresh keeps the data on screen; only an empty page shows the error.
            if (self.status !== 'loaded') self.fail(e);
          }
        ).then(function () { self.refreshing = false; self.runQueued(); });
      },

      runQueued: function () {
        if (!queued) return;
        var next = queued;
        queued = null;
        this.refresh(next.id);
      },

      afterPage: function (page, result) {
        var pagination = (result.meta && result.meta.pagination) || {};
        this.page = page;
        this.hasMore = Boolean(pagination.has_more);
        this.status = this.items.length ? 'loaded' : 'empty';
      },

      fail: function (e) {
        this.error = {
          message: (e && e.message) || 'Something went wrong. Please try again.',
          requestId: (e && e.requestId) || '',
          status: (e && e.status) || 0,
        };
        this.status = 'error';
      },

      // The changed row gets a short highlight (design.md: flash on change).
      markChanged: function (id) {
        var self = this;
        this.changedId = id;
        root.clearTimeout(flashTimer);
        flashTimer = root.setTimeout(function () { self.changedId = null; }, 1800);
      },

      // x-init="watchEnd($el)" on an element after the list. When it scrolls near the viewport, the next page loads.
      watchEnd: function (el) {
        var self = this;
        sentinel = el;
        if (!('IntersectionObserver' in root)) return; // the "Load more" button still works
        observer = new IntersectionObserver(function (entries) {
          if (entries.some(function (entry) { return entry.isIntersecting; })) self.loadMore();
        }, { rootMargin: '200px' });
        observer.observe(el);
      },

      // The observer only fires when visibility changes. After a page lands the sentinel may still be
      // in view, so look again, otherwise a tall screen would stop after one page.
      rewatch: function () {
        if (!observer || !sentinel) return;
        observer.unobserve(sentinel);
        observer.observe(sentinel);
      },
    };
  }

  // The usual key: rows that carry an id (ingredients, suppliers, menu items).
  pagedList.byId = function (item) { return item.id; };

  root.Patty = root.Patty || {};
  root.Patty.pagedList = pagedList;
})(window);
