/*
 * Purchase order detail (/purchase-orders/{ulid}). E18 for the order, E19 to E23 for its actions, E29 for its activity.
 *
 * Three rules shape this file:
 *   - The order is never changed locally. Every action sends a request and then adopts the order the server returns,
 *     so what is on screen is always what the server holds (the ledger and the state machine stay the only authority).
 *   - Buttons come from `allowed_actions`, the server's own list. The page never works out which moves are legal.
 *   - Everything irreversible goes through Patty.confirm with a `run`, which keeps the button busy until the request
 *     ends, so a double-click cannot record a delivery twice (design.md rule 1).
 */
document.addEventListener('alpine:init', function () {
  var P = Patty.purchasing;
  var U = Patty.units;

  /** "A", "A and B", "A, B and C" */
  function joinList(parts) {
    if (parts.length <= 1) return parts.join('');
    return parts.slice(0, -1).join(', ') + ' and ' + parts[parts.length - 1];
  }

  /** Value for <input type="datetime-local" max="">, in the viewer's own timezone. */
  function nowLocal() {
    var now = new Date();
    return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
  }

  Alpine.data('purchaseOrder', function (ulid) {
    return {
      ulid: ulid,
      po: null,
      loading: true,
      notFound: false,
      error: null,
      nextKey: 1,
      poller: null,
      // Bumped each time an action's own response is adopted. See fetchPo.
      actionSeq: 0,

      // Inline line editing (draft only, E19).
      editing: false,
      edit: { lines: [], errors: {}, local: {}, busy: false },
      ingredients: [],

      // Receive dialog (E23). `sent` remembers which rows went into the request, because the server names a line by its position there.
      recv: { lines: [], customTime: false, receivedAt: '', note: '', errors: {}, formError: '', timeError: '', sent: [] },
      recvSeq: 0,

      activity: { loading: true, items: [], error: null },

      init: function () {
        var self = this;
        this.load();
        this.loadActivity();
        this.poller = Patty.api.poll(function () { return self.refresh(); }, 10000);
      },

      // A deleted or unknown order will not come back, so polling stops and the empty state stays put.
      markNotFound: function () {
        this.po = null;
        this.notFound = true;
        if (this.poller) this.poller.stop();
      },

      /* ---------- loading ---------- */

      load: function () {
        var self = this;
        this.loading = true;
        this.error = null;
        this.notFound = false;
        return this.fetchPo().then(function () {
          self.loading = false;
          Patty.markFresh();
        }).catch(function (e) {
          self.loading = false;
          if (e.status === 404) self.markNotFound();
          else self.error = P.failure(e);
        });
      },

      fetchPo: function () {
        var self = this;
        // A read that started before the latest action may come back after it. It describes the order as it was
        // before the action, so it is dropped rather than put back on screen (for example "Send order" reappearing).
        var started = this.actionSeq;
        return Patty.api.get('/purchase-orders/' + this.ulid, { silent: true }).then(function (result) {
          if (started === self.actionSeq) self.adopt(result.data);
        });
      },

      adopt: function (po) {
        this.po = po;
        this.notFound = false;
        document.title = po.number + ' | Patty';
        // Someone else sent the order while it was open for editing: the editor has nothing left to save to.
        if (this.editing && !this.can('edit_lines')) this.editing = false;
      },

      // Used by the poll and after a 409. The order and its activity are both stale after a conflict.
      refresh: function () {
        var self = this;
        if (this.notFound) return Promise.resolve();
        if (!this.po) return this.load();
        this.loadActivity(true);
        return this.fetchPo().catch(function (e) {
          if (e.status === 404) self.markNotFound();
          throw e;
        });
      },

      afterConflict: function () {
        this.editing = false;
        if (this.$refs.receiveDialog.open) this.$refs.receiveDialog.close();
        this.refresh();
      },

      loadActivity: function (quiet) {
        var self = this;
        if (!quiet) this.activity.loading = true;
        return Patty.api.get('/activity', {
          query: { subject_type: 'purchase_order', subject_id: this.ulid, per_page: 25 },
          silent: true,
        }).then(function (result) {
          self.activity.items = result.data;
          self.activity.error = null;
          self.activity.loading = false;
        }).catch(function (e) {
          self.activity.loading = false;
          // The endpoint may not exist yet in an older build: say so in plain words and offer a retry, never break the page.
          if (!quiet || self.activity.items.length === 0) {
            self.activity.error = e.status === 404 || e.status === 0
              ? 'Activity is not available right now.'
              : P.failure(e).message;
          }
        });
      },

      /* ---------- what the order allows ---------- */

      can: function (action) {
        return Boolean(this.po) && this.po.allowed_actions.indexOf(action) !== -1;
      },

      label: function () { return this.po ? P.poLabel(this.po) : ''; },
      tone: function () { return this.po ? P.poTone(this.po) : 'neutral'; },
      qty: P.qty,
      qtyExact: P.qtyExact,
      ago: function (iso) { return Patty.time.ago(iso); },
      when: function (iso) { return Patty.time.local(iso); },

      lineOf: function (id) {
        return this.po.lines.find(function (line) { return line.id === id; });
      },

      // What this line can still take in one delivery: the cap on the total (D-035) minus what has arrived.
      room: function (line) {
        return Math.max(0, line.max_receivable - line.quantity_received);
      },

      // Notes under a line: over, under, short-closed, complete. Amounts are in the unit the user reads.
      tags: function (line) {
        var unit = line.ingredient.unit;
        var out = [];
        if (line.quantity_over_received > 0) out.push({ text: 'Over-received ' + P.qty(line.quantity_over_received, unit), tone: 'warn' });
        if (line.quantity_under_delivered > 0) out.push({ text: 'Under-delivered ' + P.qty(line.quantity_under_delivered, unit) + ' (within tolerance)', tone: 'neutral' });
        if (this.po.short_closed && !line.is_complete) out.push({ text: 'Not delivered ' + P.qty(line.quantity_outstanding, unit), tone: 'neutral' });
        if (out.length === 0 && line.is_complete) out.push({ text: 'Complete', tone: 'ok' });
        return out;
      },

      /* ---------- send, delete, short-close: confirm with a plain summary (rule 2) ---------- */

      send: function () {
        var self = this;
        var po = this.po;
        Patty.confirm({
          title: 'Send ' + po.number + ' to ' + po.supplier.name + '?',
          message: "Lines can't be changed after this.",
          confirmLabel: 'Send order',
          run: function () {
            return Patty.api.post('/purchase-orders/' + self.ulid + '/send').then(function (result) { self.adoptResult(result); });
          },
          onConflict: function () { self.afterConflict(); },
        }).then(function (yes) {
          if (yes) Patty.notify({ tone: 'success', message: po.number + ' sent.' });
        });
      },

      remove: function () {
        var self = this;
        var po = this.po;
        Patty.confirm({
          title: 'Delete draft ' + po.number + '?',
          message: 'The draft and its lines are removed. This cannot be undone.',
          confirmLabel: 'Delete draft',
          tone: 'danger',
          run: function () { return Patty.api.delete('/purchase-orders/' + self.ulid); },
          onConflict: function () { self.afterConflict(); },
        }).then(function (yes) {
          if (yes) location.href = '/purchase-orders';
        });
      },

      shortClose: function () {
        var self = this;
        var po = this.po;
        var open = po.lines.filter(function (line) { return !line.is_complete; });
        Patty.confirm({
          title: 'Close ' + po.number + ' with ' + open.length + ' not delivered?',
          message: 'Stock is not affected. What is still missing is recorded as not delivered, and no more deliveries can be added.',
          lines: open.map(function (line) { return line.ingredient.name + ': ' + P.qty(line.quantity_outstanding, line.ingredient.unit) + ' not delivered'; }),
          confirmLabel: 'Close order',
          tone: 'danger',
          run: function () {
            return Patty.api.post('/purchase-orders/' + self.ulid + '/close').then(function (result) { self.adoptResult(result); });
          },
          onConflict: function () { self.afterConflict(); },
        }).then(function (yes) {
          if (yes) Patty.notify({ tone: 'success', message: po.number + ' closed.' });
        });
      },

      // An action returns the order it produced. If a response ever lacks lines, fetch it instead of guessing.
      adoptResult: function (result) {
        this.actionSeq += 1;
        if (result.data && result.data.lines) this.adopt(result.data);
        else this.fetchPo();
        this.loadActivity(true);
      },

      /* ---------- edit lines (draft) ---------- */

      startEdit: function () {
        var self = this;
        this.edit = {
          lines: this.po.lines.map(function (line) {
            var unit = line.ingredient.unit;
            return {
              key: self.nextKey++,
              ingredient_id: line.ingredient.id,
              unit: unit,
              quantity: line.quantity_ordered,
              mode: U.defaultMode(unit, 'purchase'),
              error: '',
            };
          }),
          errors: {},
          local: {},
          busy: false,
        };
        this.editing = true;
        if (this.ingredients.length === 0) {
          P.fetchAll('/ingredients').then(function (items) { self.ingredients = items; }).catch(function (e) {
            Patty.notify({ tone: 'error', message: 'Could not load ingredients. ' + P.failure(e).message });
          });
        }
      },

      cancelEdit: function () {
        this.editing = false;
      },

      addEditLine: function () {
        this.edit.lines.push({ key: this.nextKey++, ingredient_id: '', unit: '', quantity: null, mode: '', error: '' });
      },

      removeEditLine: function (index) {
        this.edit.lines.splice(index, 1);
        this.edit.errors = {};
        this.edit.local = {};
        if (this.edit.lines.length === 0) this.addEditLine();
      },

      // A different unit means the old number is meaningless, so it is cleared.
      editIngredientChanged: function (line) {
        var ingredient = this.ingredients.find(function (item) { return item.id === line.ingredient_id; });
        var unit = ingredient ? ingredient.unit : '';
        if (unit !== line.unit) line.quantity = null;
        line.unit = unit;
        line.mode = unit ? U.defaultMode(unit, 'purchase') : '';
        this.edit.errors = {};
        this.edit.local = {};
      },

      taken: function (line, ingredientId) {
        return this.edit.lines.some(function (other) { return other !== line && other.ingredient_id === ingredientId; });
      },

      editMessages: function (index) {
        var prefix = 'lines.' + index + '.';
        var errors = this.edit.errors;
        var out = [];
        Object.keys(errors).forEach(function (field) {
          if (field.indexOf(prefix) === 0) out = out.concat(errors[field]);
        });
        if (this.edit.local[index]) out.push(this.edit.local[index]);
        return out;
      },

      editOrderMessages: function () {
        var errors = this.edit.errors;
        var out = [];
        Object.keys(errors).forEach(function (field) {
          if (field.indexOf('lines.') !== 0) out = out.concat(errors[field]);
        });
        return out;
      },

      saveEdit: function () {
        var self = this;
        var edit = this.edit;
        if (edit.busy) return;
        edit.errors = {};

        var local = {};
        var ok = true;
        edit.lines.forEach(function (line, index) {
          if (!line.ingredient_id) { local[index] = 'Choose an ingredient.'; ok = false; }
          else if (line.quantity === null) { local[index] = line.error || 'Enter a quantity.'; ok = false; }
        });
        edit.local = local;
        if (!ok) return;

        var modes = {};
        edit.lines.forEach(function (line, index) { modes['lines.' + index + '.quantity_ordered'] = line.mode; });

        edit.busy = true;
        Patty.api.put('/purchase-orders/' + this.ulid + '/lines', {
          lines: edit.lines.map(function (line) { return { ingredient_id: line.ingredient_id, quantity_ordered: line.quantity }; }),
        }, { onConflict: function () { self.afterConflict(); } }).then(function (result) {
          edit.busy = false;
          self.adoptResult(result);
          self.editing = false;
          Patty.notify({ tone: 'success', message: 'Lines saved.' });
        }).catch(function (e) {
          edit.busy = false;
          if (e.status === 422) {
            edit.errors = Patty.fieldErrors(e.errors, modes);
            if (Object.keys(edit.errors).length === 0) edit.errors = { order: [e.message] };
          }
        });
      },

      /* ---------- receive a delivery ---------- */

      openReceive: function () {
        var self = this;
        this.recvSeq += 1;
        this.recv = {
          // Prefilled with what is still outstanding, and 0 for a line that is already complete (rule 3).
          lines: this.po.lines.map(function (line) {
            var unit = line.ingredient.unit;
            return {
              key: self.recvSeq + ':' + line.id,
              line_id: line.id,
              name: line.ingredient.name,
              unit: unit,
              quantity: line.quantity_outstanding,
              mode: U.defaultMode(unit, 'purchase'),
              error: '',
            };
          }),
          customTime: false,
          receivedAt: '',
          note: '',
          errors: {},
          formError: '',
          timeError: '',
          sent: [],
        };
        // Alpine builds the new rows in a microtask. Opening the dialog after that means the browser's initial
        // focus does not land on the previous opening's inputs, which are being removed. Then focus the first quantity.
        var dialog = this.$refs.receiveDialog;
        this.$nextTick(function () {
          dialog.showModal();
          var first = dialog.querySelector('input.qty-field:not([disabled])');
          if (first) first.focus();
        });
      },

      closeReceive: function () {
        this.$refs.receiveDialog.close();
      },

      maxTime: nowLocal,

      limitText: function (rline) {
        var line = this.lineOf(rline.line_id);
        if (!line) return '';
        var room = this.room(line);
        return room > 0 ? 'up to ' + P.qty(room, rline.unit) : 'Nothing more can be received';
      },

      // Server messages for one row: the row's position in the request is where the server names it.
      rowMessages: function (rline) {
        var index = this.recv.sent.indexOf(rline.key);
        if (index === -1) return [];
        var prefix = 'lines.' + index + '.';
        var errors = this.recv.errors;
        var out = [];
        Object.keys(errors).forEach(function (field) {
          if (field.indexOf(prefix) === 0) out = out.concat(errors[field]);
        });
        return out;
      },

      /**
       * received_at is left out of the request unless the manager picked an earlier time: the server refuses a time
       * even seconds in the future, and a browser clock that runs fast must not turn "now" into a 422.
       * Returns { ok, value }, with value undefined for "now".
       */
      receivedAtValue: function () {
        this.recv.timeError = '';
        if (!this.recv.customTime) return { ok: true, value: undefined };
        var picked = new Date(this.recv.receivedAt);
        if (this.recv.receivedAt === '' || isNaN(picked)) {
          this.recv.timeError = 'Pick the date and time, or switch back to "Received now".';
          return { ok: false };
        }
        if (picked.getTime() > Date.now()) {
          this.recv.timeError = 'That time has not happened yet. Leave it on "Received now" or pick an earlier time.';
          return { ok: false };
        }
        if (this.po.sent_at && picked.getTime() < new Date(this.po.sent_at).getTime()) {
          this.recv.timeError = 'That is before the order was sent (' + Patty.time.local(this.po.sent_at) + ').';
          return { ok: false };
        }
        // toISOString is the local time converted to UTC with an explicit Z offset, which the API reads unambiguously.
        return { ok: true, value: picked.toISOString().replace('.000Z', 'Z') };
      },

      // The dialog's own button: checks the form, then asks for the final yes with a plain summary.
      reviewReceive: function () {
        var self = this;
        var recv = this.recv;
        recv.formError = '';
        recv.errors = {};

        if (recv.lines.some(function (line) { return line.error; })) {
          recv.formError = 'Fix the quantities marked in red first.';
          return;
        }
        // A zero or empty row simply is not part of this delivery.
        var items = recv.lines.filter(function (line) { return line.quantity !== null && line.quantity > 0; });
        if (items.length === 0) {
          recv.formError = 'Enter a quantity for at least one line.';
          return;
        }
        var time = this.receivedAtValue();
        if (!time.ok) return;

        var modes = {};
        items.forEach(function (line, index) { modes['lines.' + index + '.quantity'] = line.mode; });
        var note = recv.note.trim();

        Patty.confirm({
          title: 'Record this delivery?',
          message: 'Add ' + joinList(items.map(function (line) { return P.qty(line.quantity, line.unit) + ' ' + line.name; }))
            + ' to stock? A delivery cannot be edited afterwards.',
          confirmLabel: 'Record delivery',
          run: function () {
            var body = {
              lines: items.map(function (line) { return { purchase_order_line_id: line.line_id, quantity: line.quantity }; }),
            };
            if (time.value) body.received_at = time.value;
            if (note) body.note = note;
            recv.sent = items.map(function (line) { return line.key; });

            return Patty.api.post('/purchase-orders/' + self.ulid + '/deliveries', body).then(function (result) {
              self.adoptDelivery(result);
            }).catch(function (e) {
              if (e.status === 422) {
                // Shown next to the row, in the unit that row was typed in. `handled` closes the confirm dialog instead of repeating it there.
                recv.errors = Patty.fieldErrors(e.errors, modes);
                var onRows = Object.keys(recv.errors).some(function (field) { return field.indexOf('lines.') === 0; });
                if (!onRows && !recv.errors.received_at && !recv.errors.note) recv.formError = e.message;
                e.handled = true;
              }
              throw e;
            });
          },
          onConflict: function () { self.afterConflict(); },
        }).then(function (yes) {
          if (yes) self.closeReceive();
        });
      },

      // E23 returns { delivery, purchase_order }: the order refreshes from the response, with no second request.
      adoptDelivery: function (result) {
        var data = result.data || {};
        this.actionSeq += 1;
        if (data.purchase_order && data.purchase_order.lines) this.adopt(data.purchase_order);
        else this.fetchPo();
        this.loadActivity(true);
        Patty.notify({ tone: 'success', message: (data.delivery && data.delivery.number ? data.delivery.number : 'Delivery') + ' recorded. Stock is updated.' });
      },
    };
  });
});
