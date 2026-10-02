/*
 * Small helpers the four purchasing and POS pages share. Loaded before each page script.
 * Everything here returns plain text or data: pages render it with x-text, never as HTML (S5).
 */
(function (root) {
  'use strict';

  var Patty = (root.Patty = root.Patty || {});

  /** Reads every page of a collection (the pickers need the whole list, 100 per request is the API maximum). */
  function fetchAll(path, query) {
    var items = [];
    function next(page) {
      return Patty.api.get(path, { query: Object.assign({ per_page: 100, page: page }, query), silent: true }).then(function (result) {
        items = items.concat(result.data);
        var pagination = result.meta && result.meta.pagination;
        return pagination && pagination.has_more ? next(page + 1) : items;
      });
    }
    return next(1);
  }

  /** `extra` rows appended to `list`, skipping ids already there (offset pages repeat a row when one is created between requests). */
  function mergeById(list, extra) {
    var seen = {};
    list.forEach(function (item) { seen[item.id] = true; });
    return list.concat(extra.filter(function (item) {
      if (seen[item.id]) return false;
      seen[item.id] = true;
      return true;
    }));
  }

  /** The label a PO shows: the API's own status_label, except a short-closed order, which says so. */
  function poLabel(po) {
    return po.status === 'closed' && po.short_closed ? 'Closed (short)' : po.status_label;
  }

  /** Only the tone comes from the UI map (design.md): the label is the API's. */
  function poTone(po) {
    return Patty.statusMeta(po.status, po.short_closed).tone;
  }

  /** "2.4 kg" from a base quantity. `unit` is the ingredient's unit: g, ml or piece. */
  function qty(base, unit) {
    return Patty.units.display(base, unit).text;
  }

  /** The exact base value, for a hover title. */
  function qtyExact(base, unit) {
    return Patty.units.display(base, unit).exact;
  }

  /** A short readable reason from any failure, for the error banner of a region. */
  function failure(e) {
    return { message: (e && e.message) || 'Something went wrong.', requestId: (e && e.requestId) || null, status: (e && e.status) || 0 };
  }

  Patty.purchasing = { fetchAll: fetchAll, mergeById: mergeById, poLabel: poLabel, poTone: poTone, qty: qty, qtyExact: qtyExact, failure: failure };
})(window);
