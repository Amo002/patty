/*
 * Activity (PTY-12): the audit trail from E29, newest first, with the channel each action came from.
 * Entries have no id (D-031), so rows are keyed by a composite of their own fields, never by `id`.
 * API data reaches the page only through x-text and attribute bindings (U12).
 */
(function (root) {
  'use strict';

  var Patty = root.Patty;
  var POLL_MS = 10000;
  var ULID = /^[0-9a-hjkmnp-tv-z]{26}$/i;

  // The morph aliases E29 accepts (ListActivityRequest::SUBJECT_TYPES). The select in the page lists the same five.
  var SUBJECT_TYPES = ['purchase_order', 'ingredient', 'supplier', 'menu_item', 'sale'];

  var CHANNELS = {
    ui: { label: 'UI', tone: 'neutral' },
    pos: { label: 'POS', tone: 'info' },
    api: { label: 'API', tone: 'neutral' },
  };

  function noop() {}

  function storageSet(key, value) {
    try { root.localStorage.setItem(key, value); } catch (e) { /* the tour just does not tick step 5 */ }
  }

  /**
   * Gives each entry a key that is the same on every refresh, so Alpine keeps its DOM node and the
   * expanded state. Two entries identical in every field get a counter suffix, so keys never collide.
   */
  function keyed(rows) {
    var counts = {};
    return rows.map(function (entry) {
      var base = [entry.created_at, entry.event, entry.subject && entry.subject.type, entry.subject && entry.subject.id, entry.request_id].join('|');
      counts[base] = (counts[base] || 0) + 1;
      return Object.assign({}, entry, { _key: base + '#' + counts[base] });
    });
  }

  function shown(value) {
    if (value === null || value === undefined || value === '') return 'empty';
    return typeof value === 'object' ? JSON.stringify(value) : String(value);
  }

  document.addEventListener('alpine:init', function () {
    var Alpine = root.Alpine;

    Alpine.data('activity', function () {
      // From a PO page: ?subject_type=purchase_order&subject_id=<ulid>. E29 wants both or neither,
      // and a malformed id would only earn a 422, so anything unusable is ignored.
      var params = new URLSearchParams(root.location.search);
      var knownType = SUBJECT_TYPES.indexOf(params.get('subject_type')) !== -1;
      var subject = knownType && ULID.test(params.get('subject_id') || '')
        ? { type: params.get('subject_type'), id: params.get('subject_id') }
        : null;

      return {
        // E29 cannot filter by type alone, so the select narrows what is loaded and keeps loading while it is active.
        typeFilter: subject ? subject.type : '',
        subject: subject, // set only when the server is filtering to one record
        open: {}, // _key -> details expanded
        tick: 0, // bumps every 30 s so "5 min ago" keeps moving without a refetch

        list: Patty.liveList({
          path: '/activity',
          query: subject ? { subject_type: subject.type, subject_id: subject.id } : {},
          // No keyOf: entries have no identity, and dropping look-alikes could hide a real event. A page-boundary
          // repeat after new events arrive is fixed by the next refresh.
          decorate: keyed,
        }),

        init: function () {
          var self = this;
          storageSet('patty-tour-activity', '1'); // step 5 of the dashboard tour
          this.list.load().then(function () { Patty.markFresh(); }, noop);
          Patty.api.poll(function () { return self.list.refresh(); }, POLL_MS);
          root.setInterval(function () { self.tick++; }, 30000);
        },

        /* ---- filters ---- */

        visible: function () {
          var type = this.typeFilter;
          if (!type || this.subject) return this.list.rows;
          return this.list.rows.filter(function (entry) { return entry.subject && entry.subject.type === type; });
        },

        /** Drops the one-record filter (and its query string) and shows everything again. */
        showAll: function () {
          this.subject = null;
          this.typeFilter = '';
          root.history.replaceState(null, '', root.location.pathname);
          this.list.query = {};
          this.list.rows = [];
          this.list.load().then(function () { Patty.markFresh(); }, noop);
        },

        /** Choosing a type while viewing one record means "stop viewing one record". */
        pickType: function () {
          if (!this.subject) return;
          var type = this.typeFilter;
          this.showAll();
          this.typeFilter = type;
        },

        subjectLabel: function () {
          var first = this.list.rows[0];
          return (first && first.subject && first.subject.label) || 'one record';
        },

        /* ---- presentation ---- */

        channel: function (entry) {
          return CHANNELS[entry.channel] || { label: entry.channel ? String(entry.channel).toUpperCase() : 'Unknown', tone: 'neutral' };
        },

        /** Only purchase orders have a page to link to, and only while the record still exists. */
        subjectHref: function (entry) {
          var s = entry.subject;
          if (s && s.type === 'purchase_order' && s.id) return '/purchase-orders/' + encodeURIComponent(s.id);
          return null;
        },

        subjectText: function (entry) {
          var s = entry.subject || {};
          return s.label || (s.type ? s.type.replace(/_/g, ' ') : '');
        },

        ago: function (entry) {
          void this.tick;
          return Patty.time.ago(entry.created_at);
        },

        exact: function (entry) { return Patty.time.local(entry.created_at); },

        /** "status: draft to sent", one line per changed field. */
        changeList: function (entry) {
          var changes = entry.changes || {};
          return Object.keys(changes).map(function (field) {
            var pair = changes[field] || [];
            return { field: field.replace(/_/g, ' '), from: shown(pair[0]), to: shown(pair[1]) };
          });
        },

        toggle: function (entry) { this.open[entry._key] = !this.open[entry._key]; },
      };
    });
  });
})(window);
