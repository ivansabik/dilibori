{{-- Driver-agnostic storefront analytics. Swap EVENT_CLIENT_DRIVER without changing listeners. --}}
@php
    $driver = $driver ?? 'null';
    $skip = in_array($driver, ['null', 'none', 'disabled'], true);
@endphp
@unless ($skip)
<script data-dilibori-cdp="1">
(function () {
  var COOKIE = @json($anonymousIdCookie);
  var CONTEXT = @json($context ?? []);
  var USER_ID = @json($userId);
  var TRAITS = @json($traits ?? []);
  var ANON = @json($anonymousId);

  function readCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }

  function writeCookie(name, value, days) {
    var maxAge = (days || 365) * 24 * 60 * 60;
    document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + maxAge + '; SameSite=Lax';
  }

  var anonymousId = readCookie(COOKIE) || readCookie('rl_anonymous_id') || ANON;
  if (anonymousId) {
    writeCookie(COOKIE, anonymousId);
  }

  /**
   * Minimal facade so page code never references RudderStack / Segment directly.
   * window.diliboriAnalytics.track('Event Name', { ... })
   */
  var queue = [];
  var ready = false;
  var impl = {
    identify: function () {},
    track: function () {},
    page: function () {},
    ready: function (cb) { if (typeof cb === 'function') cb(); }
  };

  window.diliboriAnalytics = {
    track: function (event, props) {
      var payload = Object.assign({}, CONTEXT, props || {});
      if (!ready) { queue.push(['track', event, payload]); return; }
      impl.track(event, payload);
    },
    identify: function (userId, traits) {
      if (!ready) { queue.push(['identify', userId, traits || {}]); return; }
      impl.identify(userId, traits || {});
    },
    page: function (name, props) {
      var payload = Object.assign({}, CONTEXT, props || {});
      if (!ready) { queue.push(['page', name, payload]); return; }
      impl.page(name, payload);
    },
    flushQueue: function () {
      while (queue.length) {
        var item = queue.shift();
        var method = item[0];
        impl[method].apply(impl, item.slice(1));
      }
    }
  };

  function simpleHash(str) {
    var h = 0;
    for (var i = 0; i < str.length; i++) {
      h = ((h << 5) - h) + str.charCodeAt(i);
      h |= 0;
    }
    return ('00000000' + (h >>> 0).toString(16)).slice(-8);
  }

  function jsErrorId(message, source, line) {
    var normalized = String(message || '').replace(/\s+/g, ' ').trim();
    return 'js_' + simpleHash(normalized + '|' + (source || '') + '|' + (line || 0));
  }

  function trackApplicationError(props) {
    var id = props.error_id;
    try {
      var key = 'cdp_err_' + id;
      if (sessionStorage.getItem(key)) return;
      sessionStorage.setItem(key, '1');
    } catch (e) {}
    window.diliboriAnalytics.track('Application Error', props);
  }

  function messageFromFailHtml(content, status) {
    if (!content || typeof content !== 'string') {
      return 'Livewire HTTP ' + status;
    }
    var title = content.match(/<title[^>]*>([^<]+)<\/title>/i);
    if (title && title[1]) {
      return title[1].replace(/\s+/g, ' ').trim().slice(0, 500);
    }
    return 'Livewire HTTP ' + status;
  }

  function isLivewireRequest(url, init) {
    var u = String(url || '');
    if (/livewire/i.test(u)) return true;
    try {
      var headers = (init && init.headers) || {};
      if (typeof Headers !== 'undefined' && headers instanceof Headers) {
        return headers.has('X-Livewire');
      }
      if (headers['X-Livewire'] != null || headers['x-livewire'] != null) return true;
    } catch (e) {}
    return false;
  }

  function bindLivewireErrorTracking() {
    var attach = function () {
      if (!window.Livewire || typeof Livewire.hook !== 'function') return;
      Livewire.hook('request', function (args) {
        if (!args || typeof args.fail !== 'function') return;
        args.fail(function (fail) {
          var status = (fail && fail.status) || 0;
          var content = fail && fail.content;
          var msg = messageFromFailHtml(content, status);
          trackApplicationError({
            error_id: jsErrorId(msg, 'livewire', status),
            error_source: 'js',
            error_type: 'livewire',
            error_message: msg,
            status_code: status || undefined,
            path: location.pathname,
            url: location.href
          });
        });
      });
    };
    if (window.Livewire) {
      attach();
    } else {
      document.addEventListener('livewire:init', attach);
    }
  }

  function bindErrorTracking() {
    window.addEventListener('error', function (ev) {
      var msg = ev.message || (ev.error && ev.error.message) || 'Error';
      var file = ev.filename || '';
      var line = ev.lineno || 0;
      trackApplicationError({
        error_id: jsErrorId(msg, file, line),
        error_source: 'js',
        error_type: 'Error',
        error_message: String(msg).slice(0, 500),
        error_file: file,
        error_line: line,
        path: location.pathname,
        url: location.href
      });
    });

    window.addEventListener('unhandledrejection', function (ev) {
      var reason = ev.reason;
      var msg = (reason && reason.message) ? reason.message : String(reason || 'unhandledrejection');
      trackApplicationError({
        error_id: jsErrorId(msg, 'unhandledrejection', 0),
        error_source: 'js',
        error_type: 'unhandledrejection',
        error_message: String(msg).slice(0, 500),
        path: location.pathname,
        url: location.href
      });
    });

    bindLivewireErrorTracking();

    if (typeof window.fetch === 'function') {
      var origFetch = window.fetch;
      window.fetch = function (input, init) {
        var url = typeof input === 'string' ? input : (input && input.url) || '';
        var skipHttpTrack = isLivewireRequest(url, init);
        return origFetch.apply(this, arguments).then(function (res) {
          // Livewire failures use Livewire.hook('request') so we get the exception title.
          if (!skipHttpTrack && res && res.status >= 500) {
            trackApplicationError({
              error_id: jsErrorId('http_' + res.status + '_' + (res.url || ''), 'fetch', 0),
              error_source: 'js',
              error_type: 'http',
              error_message: 'HTTP ' + res.status,
              status_code: res.status,
              path: location.pathname,
              url: res.url || location.href
            });
          }
          return res;
        });
      };
    }
  }

  function onReady(adapter) {
    impl = adapter;
    ready = true;
    if (USER_ID) {
      impl.identify(String(USER_ID), TRAITS || {});
    }
    impl.page(document.title, { path: location.pathname, url: location.href, referrer: document.referrer });
    window.diliboriAnalytics.flushQueue();
    bindStorefrontEvents();
    bindErrorTracking();
  }

  function bindStorefrontEvents() {
    // Product List Viewed — menus / category pages
    if (/\/menus(\/|$)/.test(location.pathname) || document.querySelector('[data-menu-list], .menu-list, #category-menu-list')) {
      var category = '';
      var catEl = document.querySelector('[data-category-name], .category-title, h1');
      if (catEl) category = (catEl.getAttribute('data-category-name') || catEl.textContent || '').trim();
      window.diliboriAnalytics.track('Product List Viewed', {
        list_id: location.pathname,
        category: category || undefined
      });
    }

    // Cart Viewed — basket page
    if (/\/cart(\/|$)/i.test(location.pathname)) {
      try {
        if (!sessionStorage.getItem('cdp_cart_viewed_' + location.pathname)) {
          sessionStorage.setItem('cdp_cart_viewed_' + location.pathname, '1');
          window.diliboriAnalytics.track('Cart Viewed', { path: location.pathname });
        }
      } catch (e) {
        window.diliboriAnalytics.track('Cart Viewed', { path: location.pathname });
      }
    }

    // Checkout Started — JS fallback if backend hook has not fired yet this session
    if (/checkout/i.test(location.pathname)) {
      try {
        if (!sessionStorage.getItem('cdp_checkout_started')) {
          sessionStorage.setItem('cdp_checkout_started', '1');
          window.diliboriAnalytics.track('Checkout Started', { path: location.pathname, producer: 'frontend' });
        }
      } catch (e) {}
    }
  }

  @if ($driver === 'rudderstack')
  (function loadRudderstack() {
    var writeKey = @json($rudderstack['js_write_key'] ?? '');
    var dataPlaneUrl = @json($rudderstack['js_data_plane_url'] ?? '');
    if (!writeKey || !dataPlaneUrl) return;

    !function(){"use strict";window.rudderanalytics=window.rudderanalytics||[];var e=["load","page","track","identify","alias","group","ready","reset","getAnonymousId","setAnonymousId"];for(var i=0;i<e.length;i++){var t=e[i];window.rudderanalytics[t]=function(e){return function(){window.rudderanalytics.push([e].concat(Array.prototype.slice.call(arguments)))}}(t)}}();

    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://cdn.rudderlabs.com/v3/modern/rsa.min.js';
    s.onload = function () {
      rudderanalytics.load(writeKey, dataPlaneUrl, {
        configUrl: @json($rudderstack['js_config_url'] ?? 'http://127.0.0.1:8090'),
        anonymousIdOptions: { autoCapture: { enabled: true } }
      });
      if (anonymousId) {
        try { rudderanalytics.setAnonymousId(anonymousId); } catch (e) {}
      }
      rudderanalytics.ready(function () {
        try {
          var id = rudderanalytics.getAnonymousId && rudderanalytics.getAnonymousId();
          if (id) writeCookie(COOKIE, id);
        } catch (e) {}
        onReady({
          identify: function (userId, traits) { rudderanalytics.identify(userId, traits); },
          track: function (event, props) { rudderanalytics.track(event, props); },
          page: function (name, props) { rudderanalytics.page(name, props); },
          ready: function (cb) { rudderanalytics.ready(cb); }
        });
      });
    };
    document.head.appendChild(s);
  })();
  @elseif ($driver === 'segment')
  // Future: load analytics.js and wire onReady(...) the same way.
  console.info('[dilibori.cdp] Segment driver not implemented in JS yet');
  @else
  console.info('[dilibori.cdp] No browser adapter for driver: {{ $driver }}');
  @endif
})();
</script>
@endunless
