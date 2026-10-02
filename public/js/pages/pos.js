/*
 * POS Simulator (/pos). Plays the till: menu items (E11), sells with E25 as channel "pos", lists recent sales (E26).
 *
 * The point of the page is to show two guarantees of the sales endpoint:
 *   - a sale is never refused for lack of stock: it goes through and the ingredient shows as Negative (Q-001);
 *   - a retry is safe: the same pos_reference with the same payload is a replay (200, stock not moved again),
 *     and the same reference with a different payload is refused (409, nothing moves).
 */
document.addEventListener('alpine:init', function () {
  var P = Patty.purchasing;

  /** A reference no earlier sale shares, in the characters the API accepts: sim-<timestamp>-<random>. */
  function newReference() {
    return 'sim-' + Date.now() + '-' + Math.random().toString(36).slice(2, 7);
  }

  Alpine.data('posSimulator', function () {
    return {
      items: [],
      loading: true,
      loadError: null,

      menuItemId: '',
      quantity: 1,
      busy: false,

      // The last request the till sent, kept so it can be sent again unchanged.
      last: null,
      result: null, // the Sale shown on screen
      notice: null, // { tone, title, text } for 401, 429 and a 409 conflict
      quantityError: '',

      recent: [],
      recentPage: 0,
      recentHasMore: false,
      recentLoading: true,
      recentLoadingMore: false,
      recentError: null,
      observer: null,

      init: function () {
        var self = this;
        this.loadItems();
        this.loadRecent();

        this.observer = new IntersectionObserver(function (entries) {
          if (entries[0].isIntersecting) self.loadMoreRecent();
        }, { rootMargin: '300px' });
        this.$nextTick(function () { self.observer.observe(self.$refs.sentinel); });

        Patty.api.poll(function () { return self.refreshRecent(); }, 10000);
      },

      /* ---------- menu items ---------- */

      loadItems: function () {
        var self = this;
        this.loading = true;
        this.loadError = null;
        return P.fetchAll('/menu-items').then(function (items) {
          self.items = items;
          self.loading = false;
          var firstSellable = items.find(function (item) { return item.is_sellable; });
          if (!self.menuItemId && firstSellable) self.menuItemId = firstSellable.id;
        }).catch(function (e) {
          self.loading = false;
          self.loadError = P.failure(e);
        });
      },

      selected: function () {
        var id = this.menuItemId;
        return this.items.find(function (item) { return item.id === id; }) || null;
      },

      // What this sale will take out of stock, so the manager can predict the result before pressing Sell.
      preview: function () {
        var item = this.selected();
        var qty = Number(this.quantity);
        if (!item || !item.is_sellable || !Number.isInteger(qty) || qty < 1) return [];
        return item.recipe.map(function (line) {
          return line.ingredient.name + ': ' + P.qty(line.quantity * qty, line.ingredient.unit);
        });
      },

      /* ---------- selling ---------- */

      validate: function () {
        this.quantityError = '';
        var qty = Number(this.quantity);
        if (!this.selected()) { this.quantityError = 'Choose a menu item.'; return false; }
        if (!Number.isInteger(qty) || qty < 1 || qty > 1000) {
          this.quantityError = 'Quantity must be a whole number from 1 to 1,000.';
          return false;
        }
        return true;
      },

      sell: function () {
        if (this.busy || !this.validate()) return;
        this.send({ menu_item_id: this.menuItemId, quantity: Number(this.quantity), pos_reference: newReference() });
      },

      // The same reference and payload again: the server answers 200 with replayed: true and moves nothing.
      resend: function () {
        if (this.busy || !this.last) return;
        this.send(Object.assign({}, this.last));
      },

      // Same reference, one more burger: a different payload under a used reference, which the server must refuse.
      resendChanged: function () {
        if (this.busy || !this.last) return;
        this.send(Object.assign({}, this.last, { quantity: this.last.quantity + 1 }));
      },

      canResendChanged: function () {
        return Boolean(this.last) && this.last.quantity < 1000;
      },

      send: function (payload) {
        var self = this;
        this.busy = true;
        this.notice = null;
        this.quantityError = '';

        // Errors are reported on this page (silent), because a 401, 429 or 409 means something specific to a till.
        return Patty.api.post('/sales', payload, { channel: 'pos', silent: true }).then(function (response) {
          self.busy = false;
          // Only a request the server accepted becomes the "last sale": a refused change goes to catch and leaves it alone.
          self.last = { menu_item_id: payload.menu_item_id, quantity: payload.quantity, pos_reference: payload.pos_reference };
          self.result = response.data;
          self.refreshRecent();
        }).catch(function (e) {
          self.busy = false;
          self.fail(e);
        });
      },

      fail: function (e) {
        if (e.status === 401) {
          this.notice = { tone: 'danger', title: 'This server requires a POS key', text: 'POS_API_KEY is set on this server, so a sale needs the X-POS-Key header. The simulator does not send one. Unset POS_API_KEY in .env to use it.' };
        } else if (e.status === 429) {
          this.notice = { tone: 'warn', title: 'Too many sales too quickly', text: 'The till is rate limited. Wait a few seconds and sell again.' };
        } else if (e.status === 409 && e.code === 'idempotency_conflict') {
          this.notice = {
            tone: 'warn',
            title: 'Refused: same reference, different sale',
            text: 'That reference was already used for a different item or quantity, so the server will not guess which one is right. Nothing moved in stock. A real till retries the exact same sale, which is a safe replay.',
            detail: e.message,
          };
        } else if (e.status === 422) {
          var quantity = e.errors && e.errors.quantity;
          if (quantity) this.quantityError = quantity[0];
          else this.notice = { tone: 'danger', title: 'The sale was not recorded', text: e.message };
          if (e.code === 'menu_item_not_sellable') this.loadItems();
        } else {
          Patty.notify({ tone: 'error', message: e.message, requestId: e.requestId });
        }
      },

      /* ---------- recent sales (E26) ---------- */

      loadRecent: function () {
        var self = this;
        this.recentLoading = true;
        this.recentError = null;
        return Patty.api.get('/sales', { query: { page: 1, per_page: 25 }, silent: true }).then(function (response) {
          self.recent = response.data;
          self.recentPage = 1;
          self.recentHasMore = Boolean(response.meta.pagination && response.meta.pagination.has_more);
          self.recentLoading = false;
          Patty.markFresh();
          self.recheckSentinel();
        }).catch(function (e) {
          self.recentLoading = false;
          self.recentError = P.failure(e);
        });
      },

      loadMoreRecent: function () {
        var self = this;
        if (!this.recentHasMore || this.recentLoadingMore || this.recentLoading) return;
        this.recentLoadingMore = true;
        return Patty.api.get('/sales', { query: { page: this.recentPage + 1, per_page: 25 }, silent: true }).then(function (response) {
          self.recent = self.recent.concat(response.data);
          self.recentPage += 1;
          self.recentHasMore = Boolean(response.meta.pagination && response.meta.pagination.has_more);
          self.recentLoadingMore = false;
          self.recheckSentinel();
        }).catch(function (e) {
          self.recentLoadingMore = false;
          Patty.notify({ tone: 'error', message: e.message || 'Could not load more sales.', requestId: e.requestId });
        });
      },

      recheckSentinel: function () {
        var self = this;
        this.$nextTick(function () {
          self.observer.unobserve(self.$refs.sentinel);
          self.observer.observe(self.$refs.sentinel);
        });
      },

      // Refetch every page already loaded, keyed rows stay in place so the scroll position holds.
      refreshRecent: function () {
        var self = this;
        if (this.recentLoading || this.recentLoadingMore) return Promise.resolve();
        if (this.recentError) return this.loadRecent();
        var pages = this.recentPage;
        var fresh = [];
        var last = null;

        function next(page) {
          return Patty.api.get('/sales', { query: { page: page, per_page: 25 }, silent: true }).then(function (response) {
            fresh = fresh.concat(response.data);
            last = response;
            return page < pages ? next(page + 1) : null;
          });
        }

        return next(1).then(function () {
          if (self.recentLoadingMore) return;
          self.recent = fresh;
          self.recentHasMore = Boolean(last.meta.pagination && last.meta.pagination.has_more);
        });
      },

      qty: P.qty,
      qtyExact: P.qtyExact,
      ago: function (iso) { return Patty.time.ago(iso); },
      when: function (iso) { return Patty.time.local(iso); },
    };
  });
});
