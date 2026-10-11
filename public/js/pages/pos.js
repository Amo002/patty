/*
 * POS Simulator (/pos). Plays the till: menu items (E11) and ingredients (on hand, for the preview) feed a ticket
 * of one or more lines, sold with E25 as channel "pos" (D-048); recent sales come from E26.
 *
 * The point of the page is to show two guarantees of the sales endpoint:
 *   - a ticket is never refused for lack of stock: it goes through and the ingredient shows as Negative (Q-001);
 *   - a retry is safe: the same pos_reference with the same lines is a replay (200, stock not moved again),
 *     and the same reference with different lines is refused (409, nothing moves).
 */
document.addEventListener('alpine:init', function () {
  var P = Patty.purchasing;

  /** A reference no earlier sale shares, in the characters the API accepts: sim-<timestamp>-<random>. */
  function newReference() {
    return 'sim-' + Date.now() + '-' + Math.random().toString(36).slice(2, 7);
  }

  /** Same idea for refunds: sim-ret-<timestamp>-<random>. A new attempt gets a new reference; the panel has no resend. */
  function newReturnReference() {
    return 'sim-ret-' + Date.now() + '-' + Math.random().toString(36).slice(2, 7);
  }

  // The reasons the manager can pick. The API takes free text up to 200 characters; a fixed list keeps the report groupable.
  var REFUND_REASONS = ['Customer complaint', 'Wrong order', 'Cancelled before cooking', 'Long wait'];

  Alpine.data('posSimulator', function () {
    return {
      items: [],
      loading: true,
      loadError: null,

      // Ingredient on-hand figures, for the "leaves X / goes to -X" preview. Refreshed after every sale.
      ingredients: [],

      // The ticket being built: [{ menu_item_id, quantity, error }] in the order the cashier added them.
      // It is sent as `lines` exactly as it stands, so a server error at lines.N belongs to ticket[N].
      // Names and recipes are looked up in `items` by id rather than copied, so a reload of the menu refreshes them.
      ticket: [],
      busy: false,

      // The last request the till sent, kept so it can be sent again unchanged.
      last: null,
      result: null, // the Sale shown on screen
      notice: null, // { tone, title, text } for 401, 429, a 409 conflict and ticket-level 422s

      // The refund panel (E33), one sale at a time. It has its own busy flag, notice and rows so a refund can never
      // touch the ticket, the Result card or `last` (the sale being retried). `sale` is a copy of the Recent sales row:
      // it stays on screen even if the poll drops that row from the loaded pages.
      //   lines: [{ menu_item_id, name, sold, refunded, left, quantity, error }]  one per sale line, in sale order
      //   sent:  the lines actually posted (quantity > 0), because the server names a line by its position there
      //   done:  { title, text } after a 201, announced through a small status element
      refund: { sale: null, lines: [], sent: [], reference: '', restock: false, reason: REFUND_REASONS[0], busy: false, notice: null, done: null },
      reasons: REFUND_REASONS,

      recent: [],
      recentPage: 0,
      recentHasMore: false,
      recentLoading: true,
      recentLoadingMore: false,
      recentRefreshing: false,
      pendingRefresh: false,
      recentError: null,
      observer: null,

      init: function () {
        var self = this;
        this.loadItems();
        this.loadIngredients();
        this.loadRecent();

        this.observer = new IntersectionObserver(function (entries) {
          if (entries[0].isIntersecting) self.loadMoreRecent();
        }, { rootMargin: '300px' });
        this.$nextTick(function () { self.observer.observe(self.$refs.sentinel); });

        Patty.api.poll(function () { return self.refreshRecent(); }, 10000);
      },

      /* ---------- menu items and ingredients ---------- */

      loadItems: function () {
        var self = this;
        this.loading = true;
        this.loadError = null;
        return P.fetchAll('/menu-items').then(function (items) {
          self.items = items;
          self.loading = false;
        }).catch(function (e) {
          self.loading = false;
          self.loadError = P.failure(e);
        });
      },

      // On-hand figures for the preview. A failure only hides "leaves X", it must not block selling.
      loadIngredients: function () {
        var self = this;
        return P.fetchAll('/ingredients').then(function (ingredients) {
          self.ingredients = ingredients;
        }).catch(function () {});
      },

      itemFor: function (id) {
        return this.items.find(function (item) { return item.id === id; }) || null;
      },

      itemName: function (line) {
        var item = this.itemFor(line.menu_item_id);
        return item ? item.name : 'Unknown item';
      },

      plural: function (n, word) {
        return n + ' ' + word + (n === 1 ? '' : 's');
      },

      /* ---------- the ticket ---------- */

      quantityOnTicket: function (id) {
        var line = this.ticket.find(function (l) { return l.menu_item_id === id; });
        return line ? line.quantity : 0;
      },

      itemCount: function () { return this.ticket.length; },

      unitCount: function () {
        return this.ticket.reduce(function (sum, l) { return sum + l.quantity; }, 0);
      },

      // One tap adds one. Tapping an item already on the ticket raises its line instead of adding a duplicate,
      // because the server merges duplicates anyway and one row per item keeps error indexes honest.
      add: function (item) {
        if (!item.is_sellable) return;
        this.clearFeedback();
        var line = this.ticket.find(function (l) { return l.menu_item_id === item.id; });
        if (line) line.quantity = Math.min(1000, line.quantity + 1);
        else this.ticket.push({ menu_item_id: item.id, quantity: 1, error: '' });
      },

      // Stepper and typed value share one path. Going below 1 through the minus button removes the line.
      step: function (index, delta) {
        var line = this.ticket[index];
        if (!line) return;
        this.clearFeedback();
        if (line.quantity + delta < 1) { this.remove(index); return; }
        line.quantity = Math.min(1000, line.quantity + delta);
      },

      // A typed value is clamped to 1..1000 and anything that is not a number falls back to 1, so the
      // ticket can never hold a quantity the API would refuse.
      setQuantity: function (index, raw) {
        var line = this.ticket[index];
        if (!line) return;
        this.clearFeedback();
        var n = Math.floor(Number(raw));
        line.quantity = Number.isFinite(n) ? Math.min(1000, Math.max(1, n)) : 1;
      },

      remove: function (index) {
        this.clearFeedback();
        this.ticket.splice(index, 1);
      },

      clearTicket: function () {
        this.clearFeedback();
        this.ticket = [];
      },

      // An edit makes old server messages stale.
      clearFeedback: function () {
        this.notice = null;
        this.ticket.forEach(function (l) { l.error = ''; });
      },

      // "150 g beef, 1 piece bun, 20 g cheese each"
      recipeHint: function (line) {
        var item = this.itemFor(line.menu_item_id);
        if (!item || !item.recipe || item.recipe.length === 0) return 'No recipe yet';
        return item.recipe.map(function (r) {
          return P.qty(r.quantity, r.ingredient.unit) + ' ' + r.ingredient.name.toLowerCase();
        }).join(', ') + ' each';
      },

      // What this ticket takes out of stock, summed per ingredient across lines, so the manager can predict the
      // result before pressing Sell. The server groups deductions the same way (D-048). Recomputed from the
      // ticket and the last-loaded on-hand figures: it is a prediction, the API response is the truth.
      preview: function () {
        var byIngredient = {};
        var order = [];
        var self = this;
        this.ticket.forEach(function (line) {
          var item = self.itemFor(line.menu_item_id);
          if (!item || !item.is_sellable) return;
          item.recipe.forEach(function (r) {
            var id = r.ingredient.id;
            if (!byIngredient[id]) {
              byIngredient[id] = { id: id, ingredient: r.ingredient, total: 0, sources: [] };
              order.push(id);
            }
            byIngredient[id].total += r.quantity * line.quantity;
            byIngredient[id].sources.push(item.name + ' x' + line.quantity);
          });
        });
        return order.map(function (id) {
          var row = byIngredient[id];
          var stock = self.ingredients.find(function (i) { return i.id === id; });
          row.after = stock ? stock.on_hand - row.total : null; // null when stock is unknown
          row.sourceText = row.sources.length > 1 ? row.sources.join(' + ') : '';
          return row;
        });
      },

      /* ---------- selling ---------- */

      lines: function () {
        return this.ticket.map(function (l) { return { menu_item_id: l.menu_item_id, quantity: l.quantity }; });
      },

      sell: function () {
        if (this.busy || this.ticket.length === 0) return;
        this.send({ lines: this.lines(), pos_reference: newReference() }, true);
      },

      // The same reference and payload again: the server answers 200 with replayed: true and moves nothing.
      resend: function () {
        if (this.busy || !this.last) return;
        this.send(this.copyOf(this.last));
      },

      // Same reference, one more of the first item: a different payload under a used reference, which the server must refuse.
      resendChanged: function () {
        if (this.busy || !this.last) return;
        var payload = this.copyOf(this.last);
        payload.lines[0].quantity += 1;
        this.send(payload);
      },

      canResendChanged: function () {
        return Boolean(this.last) && this.last.lines[0].quantity < 1000;
      },

      copyOf: function (payload) {
        return {
          lines: payload.lines.map(function (l) { return { menu_item_id: l.menu_item_id, quantity: l.quantity }; }),
          pos_reference: payload.pos_reference,
        };
      },

      // fromTicket is true only for Sell ticket. A resend replays `last`, so it must not touch the ticket being built:
      // not clear it on success, and not put its row errors (indexed against the resent lines) on the ticket's rows.
      send: function (payload, fromTicket) {
        var self = this;
        var snapshot = JSON.stringify(payload.lines);
        this.busy = true;
        this.notice = null;
        if (fromTicket) this.ticket.forEach(function (l) { l.error = ''; });

        // Errors are reported on this page (silent), because a 401, 429 or 409 means something specific to a till.
        return Patty.api.post('/sales', payload, { channel: 'pos', silent: true }).then(function (response) {
          self.busy = false;
          // Only a request the server accepted becomes the "last sale": a refused change goes to catch and leaves it alone.
          self.last = self.copyOf(payload);
          self.result = response.data;
          // The sale is on the books, so the ticket is done, but only if it still holds exactly what was sent.
          if (fromTicket && JSON.stringify(self.lines()) === snapshot) self.ticket = [];
          self.refreshRecent();
          self.loadIngredients();
        }).catch(function (e) {
          self.busy = false;
          self.fail(e, fromTicket);
        });
      },

      fail: function (e, fromTicket) {
        if (e.status === 401) {
          this.notice = { tone: 'danger', title: 'This server requires a POS key', text: 'POS_API_KEY is set on this server, so a sale needs the X-POS-Key header. The simulator does not send one. Unset POS_API_KEY in .env to use it.', requestId: e.requestId };
        } else if (e.status === 429) {
          this.notice = { tone: 'warn', title: 'Too many sales too quickly', text: 'The till is rate limited. Wait a few seconds and sell again.', requestId: e.requestId };
        } else if (e.status === 409 && e.code === 'idempotency_conflict') {
          this.notice = {
            tone: 'warn',
            title: 'Refused: same reference, different sale',
            text: 'That reference was already used for different items or quantities, so the server will not guess which one is right. Nothing moved in stock. A real till retries the exact same sale, which is a safe replay.',
            detail: e.message,
            requestId: e.requestId,
          };
        } else if (e.status === 422) {
          this.failValidation(e, fromTicket);
          if (e.code === 'menu_item_not_sellable') this.loadItems();
        } else {
          Patty.notify({ tone: 'error', message: e.message, requestId: e.requestId });
        }
      },

      // 422 keys are `lines` (the ticket as a whole) and `lines.N.menu_item_id` / `lines.N.quantity`, where N is the
      // 0-based position in the array we sent, which is the ticket order. Row errors go on row N, the rest in a notice.
      failValidation: function (e, fromTicket) {
        var errors = e.errors || {};
        var self = this;
        var placed = false;
        var resendMessages = [];
        Object.keys(errors).forEach(function (key) {
          var match = /^lines\.(\d+)\./.exec(key);
          var message = Array.isArray(errors[key]) ? errors[key][0] : errors[key];
          if (match && fromTicket && self.ticket[Number(match[1])]) {
            var line = self.ticket[Number(match[1])];
            line.error = line.error ? line.error + ' ' + message : message;
            placed = true;
          } else if (match) {
            // A resend: the index points into the resent lines, not the ticket, so say it in the notice.
            resendMessages.push(message);
          }
        });
        var general = errors.lines ? (Array.isArray(errors.lines) ? errors.lines[0] : errors.lines) : null;
        if (general) {
          this.notice = { tone: 'danger', title: 'The sale was not recorded', text: general, requestId: e.requestId };
        } else if (resendMessages.length > 0) {
          this.notice = { tone: 'danger', title: 'The sale was not recorded', text: resendMessages.join(' '), requestId: e.requestId };
        } else if (!placed) {
          this.notice = { tone: 'danger', title: 'The sale was not recorded', text: e.message, requestId: e.requestId };
        }
      },

      /* ---------- refunds (E33, D-049) ---------- */

      canRefund: function (sale) {
        return sale.lines.some(function (l) { return l.quantity - (l.refunded || 0) > 0; });
      },

      // "Fries x1 refunded" for each line with something refunded, shown under the Items text.
      refundedPills: function (sale) {
        return sale.lines.filter(function (l) { return (l.refunded || 0) > 0; }).map(function (l) {
          return { id: l.menu_item.id, text: l.menu_item.name + ' x' + l.refunded + ' refunded' };
        });
      },

      openRefund: function (sale) {
        var rf = this.refund;
        rf.sale = JSON.parse(JSON.stringify(sale));
        rf.lines = rf.sale.lines.map(function (l) {
          var refunded = l.refunded || 0;
          return { menu_item_id: l.menu_item.id, name: l.menu_item.name, sold: l.quantity, refunded: refunded, left: l.quantity - refunded, quantity: 0, error: '' };
        });
        // Preselect one on the first line that still has something left, so the common case is a single press.
        var first = rf.lines.find(function (l) { return l.left > 0; });
        if (first) first.quantity = 1;
        rf.sent = [];
        rf.restock = false; // "Yes, it was made": the safe default, stock does not change
        rf.reason = REFUND_REASONS[0];
        rf.busy = false;
        rf.notice = null;
        rf.done = null;
        rf.reference = newReturnReference();
        // Same order as the receive dialog: let Alpine build the rows first, then open and focus the first quantity,
        // so the browser's initial focus does not land on rows of the previous opening that are being removed.
        var dialog = this.$refs.refundDialog;
        this.$nextTick(function () {
          dialog.showModal();
          var first = dialog.querySelector('input.pos-qty:not([disabled])');
          if (first) first.focus();
        });
      },

      closeRefund: function () {
        if (this.refund.busy) return;
        this.$refs.refundDialog.close();
      },

      // Runs on the dialog's close event, so Escape and the Close button both get it. When the opener button turned
      // into a disabled "Refunded", focus would fall to the body: send it to the Recent sales heading (tabindex -1).
      refundClosed: function () {
        var self = this;
        this.$nextTick(function () {
          if (document.activeElement === document.body || document.activeElement === null) self.$refs.recentHeading.focus();
        });
      },

      refundStep: function (line, delta) {
        this.clearRefundFeedback();
        line.quantity = Math.min(line.left, Math.max(0, line.quantity + delta));
      },

      // Typed value clamped to 0..left; not a number falls back to 0, so the panel never builds a request the API refuses.
      refundSet: function (line, raw) {
        this.clearRefundFeedback();
        var n = Math.floor(Number(raw));
        line.quantity = Number.isFinite(n) ? Math.min(line.left, Math.max(0, n)) : 0;
      },

      // Any edit means a different refund, so it gets a new reference. Without an edit the reference is kept, so pressing
      // Refund again after a lost response is a safe replay (D-029), not a second refund.
      clearRefundFeedback: function () {
        this.refund.reference = newReturnReference();
        this.refund.notice = null;
        this.refund.done = null;
        this.refund.lines.forEach(function (l) { l.error = ''; });
      },

      refundUnits: function () {
        return this.refund.lines.reduce(function (sum, l) { return sum + l.quantity; }, 0);
      },

      // What a restock gives back, summed per ingredient: recipe x refunded quantity. A prediction for the preview only;
      // the server restocks from the sale's own movements, so a recipe edited since the sale does not change the real number.
      refundPreview: function () {
        var self = this;
        var byId = {};
        var order = [];
        this.refund.lines.forEach(function (line) {
          var item = self.itemFor(line.menu_item_id);
          if (line.quantity < 1 || !item || !item.recipe) return;
          item.recipe.forEach(function (r) {
            var id = r.ingredient.id;
            if (!byId[id]) { byId[id] = { id: id, ingredient: r.ingredient, total: 0 }; order.push(id); }
            byId[id].total += r.quantity * line.quantity;
          });
        });
        return order.map(function (id) {
          var row = byId[id];
          var stock = self.ingredients.find(function (i) { return i.id === id; });
          row.after = stock ? stock.on_hand + row.total : null; // null when stock is unknown
          return row;
        });
      },

      // "200 g potatoes, 25 ml frying oil" for the not-restocked explanation.
      refundUsedText: function () {
        return this.refundPreview().map(function (row) {
          return P.qty(row.total, row.ingredient.unit) + ' ' + row.ingredient.name.toLowerCase();
        }).join(', ');
      },

      refundLabel: function () {
        return this.refund.restock ? 'Refund and restock' : 'Refund';
      },

      refundSale: function () {
        var rf = this.refund;
        if (rf.busy || !rf.sale || this.refundUnits() === 0) return;
        var self = this;
        // Only quantities above zero, in sale-line order. `sent` remembers which panel row each posted line came from.
        rf.sent = rf.lines.filter(function (l) { return l.quantity > 0; });
        var payload = {
          lines: rf.sent.map(function (l) { return { menu_item_id: l.menu_item_id, quantity: l.quantity }; }),
          restock: rf.restock,
          reason: rf.reason,
          pos_reference: rf.reference,
        };
        var restock = rf.restock;
        rf.busy = true;
        rf.notice = null;
        rf.done = null;
        rf.lines.forEach(function (l) { l.error = ''; });

        return Patty.api.post('/sales/' + rf.sale.id + '/returns', payload, { channel: 'pos', silent: true }).then(function (response) {
          rf.busy = false;
          // A new refund: show its units as refunded now, without waiting for the list. A replay was already counted
          // (the poll may have synced it after a lost response), so adding again would double it: leave the counts to
          // the refresh below and just empty the steppers.
          rf.sent.forEach(function (l) {
            if (!response.data.replayed) {
              l.refunded += l.quantity;
              l.left -= l.quantity;
            }
            l.quantity = 0;
          });
          rf.sent = [];
          var data = response.data;
          var text = 'Stock did not change.';
          if (restock) {
            // The real amounts from the response, not the estimate in the preview.
            var back = (data.restocked || []).map(function (r) { return r.ingredient.name + ' +' + P.qty(r.quantity, r.ingredient.unit); });
            text = back.length > 0 ? 'Back in stock: ' + back.join(', ') : 'The ingredients are back in stock.';
          }
          if (data.replayed) text = '(already recorded, nothing moved again) ' + text;
          rf.done = { title: data.number + ' recorded', text: text };
          // The next press is a new refund, so it needs its own reference.
          rf.reference = newReturnReference();
          self.refreshRecent();
          self.loadIngredients();
        }).catch(function (e) {
          rf.busy = false;
          self.failRefund(e);
        });
      },

      failRefund: function (e) {
        var rf = this.refund;
        if (e.status === 401) {
          rf.notice = { tone: 'danger', title: 'This server requires a POS key', text: 'POS_API_KEY is set on this server, so a refund needs the X-POS-Key header. The simulator does not send one. Unset POS_API_KEY in .env to use it.', requestId: e.requestId };
        } else if (e.status === 429) {
          rf.notice = { tone: 'warn', title: 'Too many requests too quickly', text: 'The till is rate limited. Wait a few seconds and refund again.', requestId: e.requestId };
        } else if (e.status === 409 && e.code === 'idempotency_conflict') {
          rf.notice = { tone: 'warn', title: 'Refused: reference already used', text: 'That reference belongs to a different refund. Nothing moved in stock. Press Refund again to send a fresh one.', detail: e.message, requestId: e.requestId };
          rf.reference = newReturnReference();
        } else if (e.status === 422) {
          this.failRefundValidation(e);
          // Someone else refunded these units first (another till, another tab): reload so "left" tells the truth.
          if (e.code === 'over_refund' || e.code === 'item_not_on_sale') this.refreshRecent();
        } else {
          Patty.notify({ tone: 'error', message: e.message, requestId: e.requestId });
        }
      },

      // Keys are lines.N.quantity / lines.N.menu_item_id with N the 0-based position in the array we SENT, which skips the
      // rows left at 0, so it is looked up in `sent`, not in `lines`. Everything else (restock, reason, lines) goes in a notice.
      failRefundValidation: function (e) {
        var rf = this.refund;
        var errors = e.errors || {};
        var placed = false;
        var other = [];
        Object.keys(errors).forEach(function (key) {
          var message = Array.isArray(errors[key]) ? errors[key][0] : errors[key];
          var match = /^lines\.(\d+)\./.exec(key);
          var row = match ? rf.sent[Number(match[1])] : null;
          if (row) {
            row.error = row.error ? row.error + ' ' + message : message;
            placed = true;
          } else {
            other.push(message);
          }
        });
        if (other.length > 0) {
          rf.notice = { tone: 'danger', title: 'The refund was not recorded', text: other.join(' '), requestId: e.requestId };
        } else if (!placed) {
          rf.notice = { tone: 'danger', title: 'The refund was not recorded', text: e.message, requestId: e.requestId };
        }
      },

      // After the list reloads, the open panel takes the server's refunded counts for its sale (another till may have
      // refunded too) and pulls any typed quantity back under the new "left". A request in flight is left alone.
      syncRefund: function () {
        var rf = this.refund;
        if (!rf.sale || rf.busy) return;
        var fresh = this.recent.find(function (s) { return s.id === rf.sale.id; });
        if (!fresh) return;
        rf.lines.forEach(function (line) {
          var match = fresh.lines.find(function (l) { return l.menu_item.id === line.menu_item_id; });
          if (!match) return;
          line.refunded = match.refunded || 0;
          line.left = match.quantity - line.refunded;
          var clamped = Math.min(line.quantity, line.left);
          // The request would now differ from one already sent under this reference, which the server answers with a 409.
          if (clamped !== line.quantity) { line.quantity = clamped; rf.reference = newReturnReference(); }
        });
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
          self.syncRefund();
          Patty.markFresh();
          self.recheckSentinel();
        }).catch(function (e) {
          self.recentLoading = false;
          self.recentError = P.failure(e);
        }).then(function () {
          self.runPendingRefresh();
        });
      },

      loadMoreRecent: function () {
        var self = this;
        if (!this.recentHasMore || this.recentLoadingMore || this.recentLoading || this.recentRefreshing) return;
        this.recentLoadingMore = true;
        return Patty.api.get('/sales', { query: { page: this.recentPage + 1, per_page: 25 }, silent: true }).then(function (response) {
          // A sale made between two page requests shifts the offsets, so a page can repeat a row already shown.
          self.recent = P.mergeById(self.recent, response.data);
          self.recentPage += 1;
          self.recentHasMore = Boolean(response.meta.pagination && response.meta.pagination.has_more);
          self.recentLoadingMore = false;
          self.recheckSentinel();
        }).catch(function (e) {
          self.recentLoadingMore = false;
          Patty.notify({ tone: 'error', message: e.message || 'Could not load more sales.', requestId: e.requestId });
        }).then(function () {
          self.runPendingRefresh();
        });
      },

      // A refresh asked for while a request is in flight (the one after a sale, or a poll) is remembered and run
      // when that request ends. It is never dropped, so a new sale always reaches Recent sales.
      runPendingRefresh: function () {
        if (this.pendingRefresh && !this.recentLoading && !this.recentLoadingMore && !this.recentRefreshing) this.refreshRecent();
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
        if (this.recentLoading || this.recentLoadingMore || this.recentRefreshing) {
          this.pendingRefresh = true;
          return Promise.resolve();
        }
        this.pendingRefresh = false;
        if (this.recentError) return this.loadRecent();
        this.recentRefreshing = true;
        var pages = this.recentPage;
        var fresh = [];
        var last = null;

        function next(page) {
          return Patty.api.get('/sales', { query: { page: page, per_page: 25 }, silent: true }).then(function (response) {
            fresh = P.mergeById(fresh, response.data);
            last = response;
            return page < pages ? next(page + 1) : null;
          });
        }

        function done() {
          self.recentRefreshing = false;
          self.runPendingRefresh();
        }

        return next(1).then(function () {
          self.recent = fresh;
          self.syncRefund();
          self.recentHasMore = Boolean(last.meta.pagination && last.meta.pagination.has_more);
          self.recheckSentinel();
          done();
        }, function (e) {
          done();
          throw e;
        });
      },

      qty: P.qty,
      qtyExact: P.qtyExact,
      ago: function (iso) { return Patty.time.ago(iso); },
      when: function (iso) { return Patty.time.local(iso); },
    };
  });
});
