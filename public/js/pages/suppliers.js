/*
 * Suppliers page (E7, E8, E10). A paged list from catalogue-common.js plus one create/edit dialog.
 * S5: API data only reaches the page through x-text and attribute bindings, never as HTML.
 */
document.addEventListener('alpine:init', function () {
  Alpine.data('suppliersPage', function () {
    return Object.assign(Patty.pagedList('/suppliers', { keyOf: Patty.pagedList.byId }), {
      busy: false,
      form: { id: null, name: '', email: '', phone: '' },
      errors: {},

      init: function () {
        this.load();
      },

      openCreate: function () {
        this.form = { id: null, name: '', email: '', phone: '' };
        this.errors = {};
        this.$refs.dialog.showModal();
      },

      openEdit: function (supplier) {
        this.form = { id: supplier.id, name: supplier.name, email: supplier.email || '', phone: supplier.phone || '' };
        this.errors = {};
        this.$refs.dialog.showModal();
      },

      closeForm: function () {
        if (!this.busy) this.$refs.dialog.close();
      },

      err: function (field) {
        return (this.errors[field] || [])[0] || '';
      },

      save: function () {
        if (this.busy) return;
        var self = this;
        var editing = this.form.id !== null;
        // An empty optional field is sent as null, so clearing an email really clears it.
        var body = {
          name: this.form.name.trim(),
          email: this.form.email.trim() || null,
          phone: this.form.phone.trim() || null,
        };
        this.busy = true;
        this.errors = {};

        var request = editing ? Patty.api.patch('/suppliers/' + this.form.id, body) : Patty.api.post('/suppliers', body);
        return request.then(
          function (result) {
            self.$refs.dialog.close();
            Patty.notify({ tone: 'success', message: editing ? 'Supplier updated.' : body.name + ' added.' });
            return self.refresh(result.data.id);
          },
          function (e) {
            // 422 is shown next to the field. Anything else already got a notice from api.js.
            if (e.status === 422) self.errors = e.errors;
          }
        ).then(function () { self.busy = false; });
      },
    });
  });
});
