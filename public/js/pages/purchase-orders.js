/*
 * Purchase orders list (/purchase-orders). Reads E16 only.
 * Shape: load the first page, lazy-load the rest (U2), poll every 10 s (U3), filter by status tab.
 */
document.addEventListener('alpine:init', function () {
  var P = Patty.purchasing;
  var TABS = [
    { value: '', label: 'All' },
    { value: 'draft', label: 'Draft' },
    { value: 'sent', label: 'Sent' },
    { value: 'received', label: 'Partially received' },
    { value: 'closed', label: 'Closed' },
    { value: 'open', label: 'Open' },
  ];
  var KNOWN = TABS.map(function (tab) { return tab.value; });

  Alpine.data('purchaseOrders', function () {
    return {
      tabs: TABS,
      status: '',
      items: [],
      page: 0,
      hasMore: false,
      loading: true,
      loadingMore: false,
      refreshing: false,
      pendingRefresh: false,
      error: null,
      // Bumped on every tab change, so a slow response for the old tab cannot overwrite the new one.
      seq: 0,
      observer: null,

      init: function () {
        var self = this;
        var fromUrl = new URLSearchParams(location.search).get('status') || '';
        this.status = KNOWN.indexOf(fromUrl) === -1 ? '' : fromUrl;
        this.load();

        // Lazy loading: the sentinel under the table asks for the next page when it scrolls near (U2).
        this.observer = new IntersectionObserver(function (entries) {
          if (entries[0].isIntersecting) self.loadMore();
        }, { rootMargin: '300px' });
        this.$nextTick(function () { self.observer.observe(self.$refs.sentinel); });

        Patty.api.poll(function () { return self.refresh(); }, 10000);
      },

      query: function (page) {
        return { status: this.status, page: page, per_page: 25 };
      },

      load: function () {
        var self = this;
        var seq = ++this.seq;
        this.loading = true;
        this.error = null;
        this.items = [];
        this.hasMore = false;
        return Patty.api.get('/purchase-orders', { query: this.query(1), silent: true }).then(function (result) {
          if (seq !== self.seq) return;
          self.items = result.data;
          self.page = 1;
          self.hasMore = Boolean(result.meta.pagination && result.meta.pagination.has_more);
          self.loading = false;
          Patty.markFresh();
          self.recheckSentinel();
        }).catch(function (e) {
          if (seq !== self.seq) return;
          self.loading = false;
          self.error = P.failure(e);
        });
      },

      loadMore: function () {
        var self = this;
        if (!this.hasMore || this.loadingMore || this.loading || this.refreshing) return;
        var seq = this.seq;
        this.loadingMore = true;
        return Patty.api.get('/purchase-orders', { query: this.query(this.page + 1), silent: true }).then(function (result) {
          if (seq !== self.seq) return;
          // A row created between two page requests shifts the offsets, so a page can repeat a row already shown.
          self.items = P.mergeById(self.items, result.data);
          self.page += 1;
          self.hasMore = Boolean(result.meta.pagination && result.meta.pagination.has_more);
          self.loadingMore = false;
          self.recheckSentinel();
        }).catch(function (e) {
          self.loadingMore = false;
          Patty.notify({ tone: 'error', message: (e && e.message) || 'Could not load more orders.', requestId: e && e.requestId });
        }).then(function () {
          self.runPendingRefresh();
        });
      },

      // A poll that arrives while a request is in flight is remembered, not dropped, and runs when that request ends.
      runPendingRefresh: function () {
        if (this.pendingRefresh && !this.loading && !this.loadingMore && !this.refreshing) this.refresh();
      },

      // An observer only fires on a change, so after new rows land we look again: a tall screen may still show the sentinel.
      recheckSentinel: function () {
        var self = this;
        this.$nextTick(function () {
          self.observer.unobserve(self.$refs.sentinel);
          self.observer.observe(self.$refs.sentinel);
        });
      },

      // The poll refetches every page already loaded and swaps the rows in place. The rows are keyed by id,
      // so the browser keeps the scroll position and only the numbers that changed update.
      refresh: function () {
        var self = this;
        if (this.loading || this.loadingMore || this.refreshing) {
          this.pendingRefresh = true;
          return Promise.resolve();
        }
        this.pendingRefresh = false;
        if (this.error) return this.load();
        this.refreshing = true;
        var seq = this.seq;
        var pages = this.page;
        var fresh = [];
        var last = null;

        function next(page) {
          return Patty.api.get('/purchase-orders', { query: self.query(page), silent: true }).then(function (result) {
            fresh = P.mergeById(fresh, result.data);
            last = result;
            return page < pages ? next(page + 1) : null;
          });
        }

        function done() {
          self.refreshing = false;
          self.runPendingRefresh();
        }

        return next(1).then(function () {
          if (seq === self.seq) {
            self.items = fresh;
            self.hasMore = Boolean(last.meta.pagination && last.meta.pagination.has_more);
            self.recheckSentinel();
          }
          done();
        }, function (e) {
          done();
          throw e;
        });
      },

      setStatus: function (value) {
        if (value === this.status) return;
        this.status = value;
        var url = new URL(location.href);
        if (value) url.searchParams.set('status', value); else url.searchParams.delete('status');
        history.replaceState(null, '', url);
        this.load();
      },

      emptyFiltered: function () {
        return this.status !== '';
      },

      label: P.poLabel,
      tone: P.poTone,
      ago: function (iso) { return Patty.time.ago(iso); },
      when: function (iso) { return Patty.time.local(iso); },
    };
  });
});
