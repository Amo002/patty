/*
 * The one fetch wrapper every page uses (D-019, U7, U11).
 *
 *   const { data, meta } = await Patty.api.get('/stock');
 *   try { await Patty.api.post('/suppliers', { name }); }
 *   catch (e) { if (e.status === 422) this.errors = e.errors; }
 *
 * Success resolves { data, message, meta, status }.
 * Anything else rejects with an ApiError { status, code, message, errors, requestId, handled }.
 *   - 422: handled=false. The page shows e.errors inline, next to the fields.
 *   - 409: a notice is shown and `onConflict` runs, because the data on screen is out of date.
 *   - 401, 429, 500, network: a notice with the message and the request id. handled=true.
 * `handled` tells a page whether the user has already been told, so it never double-reports.
 */
(function (root) {
  'use strict';

  var BASE = '/api/v1';
  var DEFAULT_CHANNEL = 'ui'; // U11: the audit trail records where an action came from.

  function ApiError(init) {
    this.name = 'ApiError';
    this.status = init.status;
    this.code = init.code || null;
    this.message = init.message;
    this.errors = init.errors || {};
    this.requestId = init.requestId || null;
    this.handled = Boolean(init.handled);
  }
  ApiError.prototype = Object.create(Error.prototype);
  ApiError.prototype.constructor = ApiError;

  function notify(tone, message, requestId) {
    if (root.Patty && typeof root.Patty.notify === 'function') {
      root.Patty.notify({ tone: tone, message: message, requestId: requestId });
    } else if (root.console) {
      root.console.warn(message, requestId || '');
    }
  }

  function buildUrl(path, query) {
    var url = BASE + path;
    if (!query) return url;
    var pairs = [];
    Object.keys(query).forEach(function (key) {
      var value = query[key];
      if (value !== undefined && value !== null && value !== '') {
        pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
      }
    });
    return pairs.length ? url + '?' + pairs.join('&') : url;
  }

  /**
   * options: { body, query, channel, signal, onConflict, silent }
   * `channel` overrides X-Patty-Channel (the POS Simulator passes 'pos').
   * `silent` skips the notice for background polls, so a flaky network does not stack toasts.
   */
  function request(method, path, options) {
    options = options || {};
    var headers = {
      Accept: 'application/json',
      'X-Patty-Channel': options.channel || DEFAULT_CHANNEL,
    };
    var init = { method: method, headers: headers, signal: options.signal, credentials: 'same-origin' };

    if (options.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(options.body);
    }
    if (options.headers) {
      Object.keys(options.headers).forEach(function (name) {
        headers[name] = options.headers[name];
      });
    }

    return fetch(buildUrl(path, options.query), init).then(
      function (response) {
        var requestId = response.headers.get('X-Request-Id');

        return response.text().then(function (text) {
          var body = null;
          try {
            body = text ? JSON.parse(text) : null;
          } catch (e) {
            body = null; // an HTML error page or a proxy message: treated as a plain failure below
          }

          if (response.ok && body && body.success !== false) {
            return { data: body.data, message: body.message, meta: body.meta || {}, status: response.status };
          }

          var message = (body && body.message) || 'Something went wrong. Please try again.';
          var error = new ApiError({
            status: response.status,
            code: body && body.code,
            message: message,
            errors: body && body.errors,
            requestId: requestId,
          });

          if (response.status === 422) {
            return Promise.reject(error);
          }

          error.handled = true;
          if (!options.silent) {
            if (response.status === 409) {
              notify('warn', message + ' The view has been refreshed.', requestId);
            } else {
              notify('error', message, requestId);
            }
          }
          if (response.status === 409 && typeof options.onConflict === 'function') {
            options.onConflict(error);
          }
          return Promise.reject(error);
        });
      },
      function (failure) {
        if (failure && failure.name === 'AbortError') {
          return Promise.reject(failure);
        }
        var error = new ApiError({ status: 0, code: 'network', message: 'Cannot reach the server. Check the connection and try again.', handled: true });
        if (!options.silent) notify('error', error.message);
        return Promise.reject(error);
      }
    );
  }

  /**
   * Runs `fn` every `ms` while the tab is visible (D-006, U3).
   * Also runs it when the tab becomes visible again and on `pageshow`, which covers
   * back/forward navigation restored from the bfcache with stale data on screen.
   * Calls never overlap: a slow response skips the next tick instead of queueing.
   * After each successful run it fires `patty:refreshed`, which the freshness stamp listens to.
   */
  function poll(fn, ms) {
    var timer = null;
    var running = false;
    var stopped = false;

    function run() {
      if (stopped || running) return;
      running = true;
      Promise.resolve()
        .then(fn)
        .then(function () {
          root.dispatchEvent(new CustomEvent('patty:refreshed', { detail: { at: Date.now() } }));
        })
        .catch(function () {
          // The wrapper has already told the user. A failed poll just waits for the next tick.
        })
        .then(function () {
          running = false;
        });
    }

    function tick() {
      if (!document.hidden) run();
    }

    function onVisibility() {
      if (!document.hidden) run();
    }

    // A normal load also fires pageshow, and the page has just fetched its own data, so only a restore counts.
    function onPageShow(event) {
      if (event.persisted) run();
    }

    timer = root.setInterval(tick, ms);
    document.addEventListener('visibilitychange', onVisibility);
    root.addEventListener('pageshow', onPageShow);

    return {
      refresh: run,
      stop: function () {
        stopped = true;
        root.clearInterval(timer);
        document.removeEventListener('visibilitychange', onVisibility);
        root.removeEventListener('pageshow', onPageShow);
      },
    };
  }

  root.Patty = root.Patty || {};
  root.Patty.api = {
    request: request,
    get: function (path, options) { return request('GET', path, options); },
    post: function (path, body, options) { return request('POST', path, Object.assign({}, options, { body: body === undefined ? {} : body })); },
    put: function (path, body, options) { return request('PUT', path, Object.assign({}, options, { body: body })); },
    patch: function (path, body, options) { return request('PATCH', path, Object.assign({}, options, { body: body })); },
    delete: function (path, options) { return request('DELETE', path, options); },
    poll: poll,
    ApiError: ApiError,
  };
})(window);
