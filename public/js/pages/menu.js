/*
 * Menu and recipes page (E11, E12, E14, E15 for items; E2 feeds the ingredient picker).
 * A paged list of cards from catalogue-common.js, plus one dialog that creates an item or edits its
 * name and recipe. A recipe is replaced as a whole (E15 PUT), never patched line by line.
 * S5: API data only reaches the page through x-text and attribute bindings, never as HTML.
 */
document.addEventListener('alpine:init', function () {
  var units = Patty.units;

  // Recipe lines are typed in g and ml, never kg and L (D-038). Pieces have no switch.
  function typedMode(unit) {
    return units.defaultMode(unit, 'recipe');
  }

  Alpine.data('menuPage', function () {
    var nextKey = 1; // every line row gets its own key, so ids and focus survive adding and removing rows

    return Object.assign(Patty.pagedList('/menu-items', { keyOf: Patty.pagedList.byId }), {
      busy: false,
      form: { id: null, name: '', originalName: '', lines: [] },
      errors: {},
      ingredients: [],
      ingredientsStatus: 'idle', // idle | loading | ready | error

      init: function () {
        this.load();
      },

      /* ---------- how a card reads ---------- */

      recipeText: function (line) {
        return line.ingredient.name + ' ' + units.display(line.quantity, line.ingredient.unit).text;
      },

      /* ---------- the ingredient list behind the picker (E2, every page) ---------- */

      loadIngredients: function () {
        var self = this;
        var all = [];
        this.ingredientsStatus = 'loading';

        function next(page) {
          return Patty.api.get('/ingredients', { query: { page: page, per_page: 100 }, silent: true }).then(function (result) {
            all = all.concat(result.data);
            var more = result.meta && result.meta.pagination && result.meta.pagination.has_more;
            return more ? next(page + 1) : null;
          });
        }

        return next(1).then(
          function () {
            self.ingredients = all;
            self.ingredientsStatus = 'ready';
          },
          function () { self.ingredientsStatus = 'error'; }
        );
      },

      /* ---------- dialog ---------- */

      err: function (field) {
        return (this.errors[field] || [])[0] || '';
      },

      newLine: function (ingredient, quantity) {
        return {
          key: nextKey++,
          ingredient: ingredient || null,
          quantity: quantity === undefined ? null : quantity,
          mode: ingredient ? typedMode(ingredient.unit) : 'g',
          search: ingredient ? ingredient.name : '',
          open: false,
          active: 0,
          qtyError: '', // the quantity input's own message, e.g. "Grams must be a whole number"
          error: '', // a message from this page's checks, shown on the row
        };
      },

      openCreate: function () {
        this.form = { id: null, name: '', originalName: '', lines: [] };
        this.errors = {};
        this.$refs.dialog.showModal();
        this.loadIngredients();
      },

      openEdit: function (item) {
        var self = this;
        this.form = {
          id: item.id,
          name: item.name,
          originalName: item.name,
          lines: item.recipe.map(function (line) { return self.newLine(line.ingredient, line.quantity); }),
        };
        this.errors = {};
        this.$refs.dialog.showModal();
        this.loadIngredients();
      },

      closeForm: function () {
        if (!this.busy) this.$refs.dialog.close();
      },

      addLine: function () {
        var line = this.newLine(null);
        this.form.lines.push(line);
        var id = 'recipe-ing-' + line.key;
        this.$nextTick(function () {
          var input = document.getElementById(id);
          if (input) input.focus();
        });
      },

      removeLine: function (line) {
        this.form.lines = this.form.lines.filter(function (other) { return other.key !== line.key; });
      },

      /* ---------- the searchable ingredient picker ---------- */

      // Each ingredient can appear once in a recipe, so those already used by another row are shown but not pickable.
      usedElsewhere: function (ingredient, line) {
        return this.form.lines.some(function (other) { return other.key !== line.key && other.ingredient && other.ingredient.id === ingredient.id; });
      },

      options: function (line) {
        // While the box still shows the chosen name, offer everything: the user is about to change their mind.
        var picked = line.ingredient && line.search === line.ingredient.name;
        var term = picked ? '' : line.search.trim().toLowerCase();
        return this.ingredients
          .filter(function (ingredient) { return term === '' || ingredient.name.toLowerCase().indexOf(term) !== -1; })
          .slice(0, 50);
      },

      pick: function (line, ingredient) {
        if (this.usedElsewhere(ingredient, line)) return;
        // A different unit is a different quantity, so the old number is dropped rather than reinterpreted.
        if (!line.ingredient || line.ingredient.unit !== ingredient.unit) {
          line.quantity = null;
          line.qtyError = '';
          line.mode = typedMode(ingredient.unit);
        }
        line.ingredient = { id: ingredient.id, name: ingredient.name, unit: ingredient.unit };
        line.search = ingredient.name;
        line.error = '';
        line.open = false;
      },

      // Leaving the box puts the chosen name back, so what is shown is always what will be saved.
      closeCombo: function (line) {
        line.open = false;
        line.search = line.ingredient ? line.ingredient.name : '';
      },

      comboKey: function (event, line) {
        var list = this.options(line);
        if (event.key === 'ArrowDown') {
          line.open = true;
          line.active = Math.min(line.active + 1, list.length - 1);
        } else if (event.key === 'ArrowUp') {
          line.active = Math.max(line.active - 1, 0);
        } else if (event.key === 'Enter' && line.open && list[line.active]) {
          this.pick(line, list[line.active]); // Enter picks; it must not submit the form
        } else if (event.key === 'Escape' && line.open) {
          event.stopPropagation(); // the first Escape closes the list, not the whole dialog
          this.closeCombo(line);
        } else if (event.key === 'Tab') {
          this.closeCombo(line);
          return;
        } else {
          return;
        }
        event.preventDefault();
      },

      /* ---------- messages for one row ---------- */

      prefix: function () {
        return this.form.id ? 'lines' : 'recipe'; // E15 calls the field `lines`, E12 calls it `recipe`
      },

      // Local row message first, then whatever the server said about this row's ingredient or quantity.
      lineMessage: function (index) {
        var line = this.form.lines[index];
        var base = this.prefix() + '.' + index;
        var quantity = Patty.fieldErrors(this.errors, (function () {
          var modes = {};
          modes[base + '.quantity'] = line ? line.mode : '';
          return modes;
        })());
        return (line && line.error) || (quantity[base + '.quantity'] || [])[0] || this.err(base + '.ingredient_id');
      },

      // Anything the server said that no field or row shows: the recipe as a whole, or an unexpected key.
      generalErrors: function () {
        var errors = this.errors;
        var rowKey = new RegExp('^(lines|recipe)\\.\\d+\\.');
        var out = [];
        Object.keys(errors).forEach(function (key) {
          if (key === 'name' || rowKey.test(key)) return;
          out = out.concat(errors[key]);
        });
        return out;
      },

      /* ---------- save ---------- */

      // Returns the recipe lines to send, or null after marking the rows that are not ready.
      readyLines: function () {
        var ok = true;
        this.form.lines.forEach(function (line) {
          line.error = '';
          if (!line.ingredient) {
            line.error = 'Choose an ingredient.';
            ok = false;
          } else if (line.qtyError) {
            ok = false; // the quantity input already shows what is wrong
          } else if (line.quantity === null) {
            line.error = 'Enter how much ' + line.ingredient.name + ' this uses.';
            ok = false;
          }
        });
        if (!ok) return null;
        return this.form.lines.map(function (line) { return { ingredient_id: line.ingredient.id, quantity: line.quantity }; });
      },

      save: function () {
        if (this.busy) return Promise.resolve();
        var self = this;
        var editing = this.form.id !== null;
        var name = this.form.name.trim();
        var lines = this.readyLines();

        this.errors = {};
        if (lines === null) return Promise.resolve();
        if (editing && lines.length === 0) {
          this.errors = { lines: ['Add at least one ingredient to the recipe.'] };
          return Promise.resolve();
        }

        this.busy = true;
        var request;
        if (editing) {
          // Rename first if the name changed, then replace the recipe. If the second step fails, the rename stays saved.
          var rename = name === this.form.originalName
            ? Promise.resolve()
            : Patty.api.patch('/menu-items/' + encodeURIComponent(this.form.id), { name: name }).then(function () { self.form.originalName = name; });
          request = rename.then(function () {
            return Patty.api.put('/menu-items/' + encodeURIComponent(self.form.id) + '/recipe', { lines: lines });
          });
        } else {
          var body = { name: name };
          if (lines.length) body.recipe = lines; // the recipe is optional on create
          request = Patty.api.post('/menu-items', body);
        }

        return request.then(
          function (result) {
            self.$refs.dialog.close();
            Patty.notify({
              tone: 'success',
              message: editing ? 'Recipe updated, applies to future sales.' : name + ' added.',
            });
            return self.refresh((result.data && result.data.id) || self.form.id);
          },
          function (e) {
            if (e.status === 422) self.errors = e.errors;
            if (editing) self.refresh(); // a rename may have gone through even though the recipe did not
          }
        ).then(function () { self.busy = false; });
      },
    });
  });
});
