/*
 * PTY-22: demo data actions (D-037). Loaded by the layout only when the demo tools are on (APP_ENV=local),
 * so this file never runs anywhere else. Each action asks first (Patty.confirm), calls the API, then
 * reloads the whole page so every number on screen is read fresh.
 *
 *   <div x-data="demoTools" data-empty="1"> ... <button @click="reset()">
 */
(function (root) {
  'use strict';

  var ACTIONS = {
    reset: {
      path: '/demo/reset',
      title: 'Reset demo data?',
      message: 'Everything you have entered is deleted, including the stock history, and the demo restaurant is loaded again.',
      confirmLabel: 'Reset demo',
    },
    clear: {
      path: '/demo/clear',
      title: 'Clear all data?',
      message: 'Every ingredient, supplier, menu item, order, delivery and sale is deleted, including the stock history. This cannot be undone.',
      confirmLabel: 'Clear all data',
    },
    seed: {
      path: '/demo/seed',
      title: 'Load demo data?',
      message: 'Adds a realistic restaurant: ingredients, suppliers, a menu, purchase orders in every state and three days of sales.',
      confirmLabel: 'Load demo data',
    },
  };

  function perform(name) {
    var action = ACTIONS[name];
    return root.Patty.confirm({
      title: action.title,
      message: action.message,
      confirmLabel: action.confirmLabel,
      tone: name === 'seed' ? 'primary' : 'danger',
      run: function () { return root.Patty.api.post(action.path); },
    }).then(function (done) {
      // A full reload, not a refetch: no page has to know how to refresh itself after the data changed under it.
      if (done) root.location.reload();
    });
  }

  document.addEventListener('alpine:init', function () {
    root.Alpine.data('demoTools', function () {
      return {
        // The server renders whether the system is empty; "Load demo data" is only offered then (409 otherwise).
        isEmpty: false,
        init: function () { this.isEmpty = this.$el.dataset.empty === '1'; },
        reset: function () { return perform('reset'); },
        clear: function () { return perform('clear'); },
        seed: function () { return perform('seed'); },
      };
    });
  });
})(window);
