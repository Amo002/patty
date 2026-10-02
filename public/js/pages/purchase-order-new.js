/*
 * New purchase order (/purchase-orders/new). Reads suppliers (E7) and ingredients (E2), posts E17.
 * The order is created as a draft; sending it is a separate, confirmed step on the detail page.
 */
document.addEventListener('alpine:init', function () {
  var P = Patty.purchasing;

  Alpine.data('purchaseOrderNew', function () {
    return {
      loading: true,
      loadError: null,
      suppliers: [],
      ingredients: [],
      supplierId: '',
      lines: [],
      nextKey: 1,
      busy: false,
      // errors: the server's 422 messages, already in the unit each line was typed in. local: checks made before sending.
      errors: {},
      local: {},

      init: function () {
        this.addLine();
        this.loadLists();
      },

      loadLists: function () {
        var self = this;
        this.loading = true;
        this.loadError = null;
        return Promise.all([P.fetchAll('/suppliers'), P.fetchAll('/ingredients')]).then(function (results) {
          self.suppliers = results[0];
          self.ingredients = results[1];
          self.loading = false;
        }).catch(function (e) {
          self.loading = false;
          self.loadError = P.failure(e);
        });
      },

      /* ---------- lines ---------- */

      // `key` is the row's identity for x-for. It never changes, so removing row 1 does not reuse its input for row 2.
      addLine: function () {
        this.lines.push({ key: this.nextKey++, ingredient_id: '', unit: '', quantity: null, mode: '', error: '' });
      },

      removeLine: function (index) {
        this.lines.splice(index, 1);
        this.errors = {};
        this.local = {};
        if (this.lines.length === 0) this.addLine();
      },

      unitOf: function (line) {
        var ingredient = this.ingredients.find(function (item) { return item.id === line.ingredient_id; });
        return ingredient ? ingredient.unit : '';
      },

      // A different unit means the old number is meaningless (800 g is not 800 pieces), so it is cleared.
      ingredientChanged: function (line) {
        var unit = this.unitOf(line);
        if (unit !== line.unit) line.quantity = null;
        line.unit = unit;
        // The input starts in kg or L; it reports its real mode on the first keystroke or switch.
        line.mode = unit ? Patty.units.defaultMode(unit, 'purchase') : '';
        this.errors = {};
        this.local = {};
      },

      // One row per ingredient: an ingredient already used on another row is greyed out in the picker.
      taken: function (line, ingredientId) {
        return this.lines.some(function (other) { return other !== line && other.ingredient_id === ingredientId; });
      },

      /* ---------- errors ---------- */

      lineMessages: function (index) {
        var prefix = 'lines.' + index + '.';
        var out = [];
        var self = this;
        Object.keys(this.errors).forEach(function (field) {
          if (field.indexOf(prefix) === 0) out = out.concat(self.errors[field]);
        });
        if (this.local[index]) out.push(this.local[index]);
        return out;
      },

      // Messages about the order as a whole (the supplier, or "at least one line").
      orderMessages: function () {
        var self = this;
        var out = [];
        Object.keys(this.errors).forEach(function (field) {
          if (field.indexOf('lines.') !== 0) out = out.concat(self.errors[field]);
        });
        return out;
      },

      // The same fields the server checks, so the obvious mistakes never cost a round trip.
      check: function () {
        var local = {};
        var ok = true;
        this.lines.forEach(function (line, index) {
          if (!line.ingredient_id) { local[index] = 'Choose an ingredient.'; ok = false; }
          else if (line.quantity === null) { local[index] = line.error || 'Enter a quantity.'; ok = false; }
        });
        this.local = local;
        if (!this.supplierId) {
          this.errors = { supplier_id: ['Choose a supplier.'] };
          ok = false;
        }
        return ok;
      },

      /* ---------- submit ---------- */

      submit: function () {
        var self = this;
        if (this.busy) return;
        this.errors = {};
        if (!this.check()) return;

        // Server errors name the line by its position in what we send, which is the order of the rows.
        var modes = {};
        this.lines.forEach(function (line, index) { modes['lines.' + index + '.quantity_ordered'] = line.mode; });

        this.busy = true;
        Patty.api.post('/purchase-orders', {
          supplier_id: this.supplierId,
          lines: this.lines.map(function (line) {
            return { ingredient_id: line.ingredient_id, quantity_ordered: line.quantity };
          }),
        }, { silent: false }).then(function (result) {
          // Stay busy while the browser navigates, so a second click cannot draft a second order.
          location.href = '/purchase-orders/' + encodeURIComponent(result.data.id);
        }).catch(function (e) {
          self.busy = false;
          if (e.status === 422) {
            self.errors = Patty.fieldErrors(e.errors, modes);
            if (Object.keys(self.errors).length === 0) self.errors = { order: [e.message] };
          }
        });
      },
    };
  });
});
