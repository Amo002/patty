// Browser-free check of public/js/pages/catalogue-common.js. Run: node scripts/check-paged-list.js
// Guards two review findings: rows without an id must all survive "Load more" (F1), and a refresh
// requested while another is running queues exactly one follow-up (F2).
const assert = require('assert');
const path = require('path');

global.window = global;
global.Patty = {
  markFresh() {},
  api: {
    get(url, o) {
      const p = o.query.page;
      return Promise.resolve({ data: [{ n: p * 2 - 1 }, { n: p * 2 }], meta: { pagination: { has_more: p < 2 } } });
    },
  },
};
require(path.join(__dirname, '..', 'public', 'js', 'pages', 'catalogue-common.js'));

(async () => {
  const noKey = Patty.pagedList('/x');
  await noKey.load();
  await noKey.loadMore();
  assert.strictEqual(noKey.items.length, 4, 'rows without an id must not be de-duplicated');

  const keyed = Patty.pagedList('/x', { keyOf: (item) => item.n });
  await keyed.load();
  await keyed.loadMore();
  assert.strictEqual(keyed.items.length, 4);

  let calls = 0;
  Patty.api.get = () => {
    calls++;
    return new Promise((resolve) => setTimeout(() => resolve({ data: [{ n: 1 }], meta: { pagination: {} } }), 10));
  };
  const list = Patty.pagedList('/x');
  list.status = 'loaded';
  list.page = 1;
  const first = list.refresh();
  list.refresh('A');
  list.refresh('B');
  await first;
  await new Promise((resolve) => setTimeout(resolve, 60));
  assert.strictEqual(calls, 2, 'one running refresh plus exactly one queued follow-up');

  console.log('paged list checks passed');
})();
