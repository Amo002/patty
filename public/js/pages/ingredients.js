/*
 * Ingredients page (E2, E3, E5, E6). A paged list from catalogue-common.js, one create/edit dialog,
 * and a history drawer that is itself a small paged list.
 * S5: API data only reaches the page through x-text and attribute bindings, never as HTML.
 */
document.addEventListener('alpine:init', function () {
  var units = Patty.units;

  /* ---------- tolerance: percent text <-> basis points, with string maths only ---------- */

  // "5.5" -> 550. Whole numbers and up to two decimals, 0 to 100. Empty means "use the default" (null).
  // Basis points are an integer, so the text is split on the point instead of multiplying a float by 100.
  function parsePercent(text) {
    var clean = String(text).trim().replace(/%$/, '').trim();
    if (clean === '') return { ok: true, bps: null };
    var match = /^(\d{1,3})(?:\.(\d{1,2}))?$/.exec(clean);
    var bps = match ? parseInt(match[1], 10) * 100 + parseInt((match[2] || '').padEnd(2, '0') || '0', 10) : NaN;
    if (!match || bps > 10000) return { ok: false, message: 'Enter a percentage between 0 and 100, with at most 2 decimals.' };
    return { ok: true, bps: bps };
  }

  // 550 -> "5.5", 500 -> "5", 5 -> "0.05"
  function percentText(bps) {
    if (bps === null || bps === undefined) return '';
    var frac = String(bps % 100).padStart(2, '0').replace(/0+$/, '');
    return Math.floor(bps / 100) + (frac ? '.' + frac : '');
  }

  function blankForm() {
    return {
      id: null, name: '', unit: 'g', originalUnit: 'g', unitLocked: false,
      overPct: '', underPct: '', cap: null, capError: '', notice: '',
      capShow: false, resetTolerance: false, initial: { overPct: '', underPct: '', cap: null },
    };
  }

  Alpine.data('ingredientsPage', function () {
    return Object.assign(Patty.pagedList('/ingredients', { keyOf: Patty.pagedList.byId }), {
      busy: false,
      form: blankForm(),
      errors: {},
      history: null,
      historyItem: null,

      init: function () {
        var self = this;
        this.load();
        // Stock moves with every delivery and sale, so the numbers re-check themselves (U3).
        Patty.api.poll(function () { return self.refresh(); }, 10000);
      },

      /* ---------- how a row reads ---------- */

      show: function (item) {
        return units.display(item.on_hand, item.unit);
      },

      // "+5% / -5%, max +2 kg". The cap is in the ingredient's own unit, so it scales like stock does.
      toleranceText: function (item) {
        var t = item.tolerance || {};
        var text = '+' + percentText(t.over_bps) + '% / -' + percentText(t.under_bps) + '%';
        if (t.over_cap !== null && t.over_cap !== undefined) text += ', max +' + units.display(t.over_cap, item.unit).text;
        return text;
      },

      meta: function (status) {
        return Patty.statusMeta(status);
      },

      /* ---------- create and edit ---------- */

      err: function (field) {
        return (this.errors[field] || [])[0] || '';
      },

      openCreate: function () {
        this.form = blankForm();
        this.errors = {};
        this.$refs.dialog.showModal();
        this.remountCap();
      },

      openEdit: function (item) {
        var t = item.tolerance || {};
        // A default tolerance shows as empty fields: empty means "use the default", and nothing is stored as custom.
        var custom = t.source !== 'default';
        this.form = {
          id: item.id, name: item.name, unit: item.unit, originalUnit: item.unit, unitLocked: Boolean(item.unit_locked),
          overPct: custom ? percentText(t.over_bps) : '', underPct: custom ? percentText(t.under_bps) : '',
          cap: custom && t.over_cap !== undefined ? t.over_cap : null, capError: '', notice: '',
          capShow: false, resetTolerance: false,
        };
        // What the dialog opened with. The API shows the effective values (each falls back to the default on its own),
        // so a field is only sent when it differs from this: renaming must never write a default as an override.
        this.form.initial = { overPct: this.form.overPct, underPct: this.form.underPct, cap: this.form.cap };
        this.errors = {};
        this.$refs.dialog.showModal();
        this.remountCap();
      },

      // The cap's quantity input keeps its own text. Mounting it fresh on every open means a half-typed
      // value from an earlier open can never reappear (the form's cap would be null while the box still shows text).
      remountCap: function () {
        var self = this;
        this.form.capShow = false;
        this.$nextTick(function () { self.form.capShow = true; });
      },

      closeForm: function () {
        if (!this.busy) this.$refs.dialog.close();
      },

      // A different unit is a different quantity, so the cap typed for the old one is dropped.
      unitChanged: function () {
        this.form.cap = null;
        this.form.capError = '';
      },

      // "Use default" empties all three fields and asks for an explicit null on each: the API reads null as "back to the default".
      useDefaultTolerance: function () {
        this.form.resetTolerance = true;
        this.form.overPct = '';
        this.form.underPct = '';
        this.form.cap = null;
        this.form.capError = '';
        this.errors.over_tolerance_bps = [];
        this.errors.under_tolerance_bps = [];
        this.errors.over_tolerance_cap = [];
      },

      hasToleranceInput: function () {
        return this.form.overPct.trim() !== '' || this.form.underPct.trim() !== '' || this.form.cap !== null;
      },

      save: function () {
        if (this.busy) return Promise.resolve();
        var self = this;
        var editing = this.form.id !== null;
        var over = parsePercent(this.form.overPct);
        var under = parsePercent(this.form.underPct);

        // Local checks first. A quantity input that shows an error holds null, which would otherwise be sent as "no cap".
        var local = {};
        if (!over.ok) local.over_tolerance_bps = [over.message];
        if (!under.ok) local.under_tolerance_bps = [under.message];
        if (this.form.capError) local.over_tolerance_cap = [this.form.capError];
        this.errors = local;
        if (Object.keys(local).length) return Promise.resolve();

        var body = { name: this.form.name.trim() };
        var form = this.form;
        if (!editing) {
          body.unit = form.unit;
          // On create, a null tolerance just means "not set", so leave it out of the request.
          if (over.bps !== null) body.over_tolerance_bps = over.bps;
          if (under.bps !== null) body.under_tolerance_bps = under.bps;
          if (form.cap !== null) body.over_tolerance_cap = form.cap;
        } else {
          // Only what the user touched. An empty touched field is an explicit null (back to the default);
          // "Use default" touches all three.
          var reset = form.resetTolerance;
          if (reset || form.overPct.trim() !== form.initial.overPct) body.over_tolerance_bps = over.bps;
          if (reset || form.underPct.trim() !== form.initial.underPct) body.under_tolerance_bps = under.bps;
          if (reset || form.cap !== form.initial.cap) body.over_tolerance_cap = form.cap;
        }
        if (editing && !this.form.unitLocked && this.form.unit !== this.form.originalUnit) {
          body.unit = this.form.unit;
        }

        this.busy = true;
        var request = editing
          ? Patty.api.patch('/ingredients/' + encodeURIComponent(this.form.id), body, { onConflict: function (e) { self.conflicted(e); } })
          : Patty.api.post('/ingredients', body);

        return request.then(
          function (result) {
            self.$refs.dialog.close();
            Patty.notify({ tone: 'success', message: editing ? 'Ingredient updated.' : body.name + ' added.' });
            return self.refresh(result.data.id);
          },
          function (e) {
            // 422 sits next to its field. 409 and the rest already got a notice from api.js.
            if (e.status === 422) self.errors = e.errors;
          }
        ).then(function () { self.busy = false; });
      },

      // api.js has already shown the notice. Either way the list is out of date, so it refreshes.
      // Only unit_locked says anything about the unit: a generic 409 `conflict` (a unique-index race) must not lock the select.
      conflicted: function (e) {
        if (e.code === 'unit_locked') {
          // The server's own message names the use (D-044): stock history, a recipe or a purchase order.
          this.form.unitLocked = true;
          this.form.unit = this.form.originalUnit;
          this.form.notice = e.message;
        }
        this.refresh();
      },

      /* ---------- history drawer (E6) ---------- */

      openHistory: function (item) {
        this.historyItem = item;
        this.history = Patty.pagedList('/ingredients/' + encodeURIComponent(item.id) + '/movements');
        this.$refs.drawer.showModal();
        this.history.load();
      },

      closeHistory: function () {
        this.$refs.drawer.close();
      },

      // Escape, the backdrop and the close button all end here (the dialog's close event). The list is dropped so the
      // next ingredient builds a fresh one, with its own scroll observer, instead of reusing this one's.
      resetHistory: function () {
        this.history = null;
        this.historyItem = null;
      },

      // The drawer's rows can render once more while it is closing and resetting, so never assume an item is set.
      historyUnit: function () {
        return this.historyItem ? this.historyItem.unit : 'g';
      },

      // +600 g, -300 g, +2.4 kg: the sign is always written, so direction never depends on colour alone.
      delta: function (movement) {
        var text = units.display(movement.quantity_delta, this.historyUnit()).text;
        return movement.quantity_delta > 0 ? '+' + text : text;
      },

      balance: function (movement) {
        return units.display(movement.balance_after, this.historyUnit());
      },

      localTime: function (iso) {
        return Patty.time.local(iso);
      },

      timeAgo: function (iso) {
        return Patty.time.ago(iso);
      },
    });
  });
});
