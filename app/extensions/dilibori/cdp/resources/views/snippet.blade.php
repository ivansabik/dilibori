{{-- Driver-agnostic storefront analytics. Swap EVENT_CLIENT_DRIVER without changing listeners. --}}
@php
    $driver = $driver ?? 'null';
    $skip = in_array($driver, ['null', 'none', 'disabled'], true);
@endphp
@unless($skip)
<script data-dilibori-cdp="1">
(function () {
    const COOKIE = @json($anonymousIdCookie);
    const CONTEXT = @json($context ?? []);
    const USER_ID = @json($userId);
    const TRAITS = @json($traits ?? []);
    const ANON = @json($anonymousId);

    function readCookie(name) {
        const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : null;
    }

    function writeCookie(name, value, days) {
        const maxAge = (days || 365) * 24 * 60 * 60;
        document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + maxAge + '; SameSite=Lax';
    }

    let anonymousId = readCookie(COOKIE) || readCookie('rl_anonymous_id') || ANON;
    if (anonymousId) {
        writeCookie(COOKIE, anonymousId);
    }

    /**
     * Minimal facade so page code never references RudderStack / Segment directly.
     * window.diliboriAnalytics.track('Event Name', { ... })
     */
    const queue = [];
    let ready = false;
    let impl = {
        identify() {},
        track() {},
        page() {},
        ready(cb) {
            if (typeof cb === 'function') {
                cb();
            }
        },
    };

    window.diliboriAnalytics = {
        track(event, props) {
            const payload = Object.assign({}, CONTEXT, props || {});
            if (!ready) {
                queue.push(['track', event, payload]);
                return;
            }
            impl.track(event, payload);
        },
        identify(userId, traits) {
            if (!ready) {
                queue.push(['identify', userId, traits || {}]);
                return;
            }
            impl.identify(userId, traits || {});
        },
        page(name, props) {
            const payload = Object.assign({}, CONTEXT, props || {});
            if (!ready) {
                queue.push(['page', name, payload]);
                return;
            }
            impl.page(name, payload);
        },
        flushQueue() {
            while (queue.length) {
                const item = queue.shift();
                const method = item[0];
                impl[method].apply(impl, item.slice(1));
            }
        },
    };

    function simpleHash(str) {
        let h = 0;
        for (let i = 0; i < str.length; i++) {
            h = ((h << 5) - h) + str.charCodeAt(i);
            h |= 0;
        }
        return ('00000000' + (h >>> 0).toString(16)).slice(-8);
    }

    function jsErrorId(message, source, line) {
        const normalized = String(message || '').replace(/\s+/g, ' ').trim();
        return 'js_' + simpleHash(normalized + '|' + (source || '') + '|' + (line || 0));
    }

    function trackApplicationError(props) {
        const id = props.error_id;
        try {
            const key = 'cdp_err_' + id;
            if (sessionStorage.getItem(key)) {
                return;
            }
            sessionStorage.setItem(key, '1');
        } catch (e) {}
        window.diliboriAnalytics.track('Application Error', props);
    }

    function messageFromFailHtml(content, status) {
        if (!content || typeof content !== 'string') {
            return 'Livewire HTTP ' + status;
        }
        const title = content.match(/<title[^>]*>([^<]+)<\/title>/i);
        if (title && title[1]) {
            return title[1].replace(/\s+/g, ' ').trim().slice(0, 500);
        }
        return 'Livewire HTTP ' + status;
    }

    function isLivewireRequest(url, init) {
        const requestUrl = String(url || '');
        if (/livewire/i.test(requestUrl)) {
            return true;
        }
        try {
            const headers = (init && init.headers) || {};
            if (typeof Headers !== 'undefined' && headers instanceof Headers) {
                return headers.has('X-Livewire');
            }
            if (headers['X-Livewire'] !== undefined || headers['x-livewire'] !== undefined) {
                return true;
            }
        } catch (e) {}
        return false;
    }

    function bindLivewireErrorTracking() {
        function attach() {
            if (!window.Livewire || typeof Livewire.hook !== 'function') {
                return;
            }
            Livewire.hook('request', function (args) {
                if (!args || typeof args.fail !== 'function') {
                    return;
                }
                args.fail(function (fail) {
                    const status = (fail && fail.status) || 0;
                    const content = fail && fail.content;
                    const msg = messageFromFailHtml(content, status);
                    trackApplicationError({
                        error_id: jsErrorId(msg, 'livewire', status),
                        error_source: 'js',
                        error_type: 'livewire',
                        error_message: msg,
                        status_code: status || undefined,
                        path: location.pathname,
                        url: location.href,
                    });
                });
            });
        }
        if (window.Livewire) {
            attach();
        } else {
            document.addEventListener('livewire:init', attach);
        }
    }

    function bindErrorTracking() {
        window.addEventListener('error', function (ev) {
            const msg = ev.message || (ev.error && ev.error.message) || 'Error';
            const file = ev.filename || '';
            const line = ev.lineno || 0;
            trackApplicationError({
                error_id: jsErrorId(msg, file, line),
                error_source: 'js',
                error_type: 'Error',
                error_message: String(msg).slice(0, 500),
                error_file: file,
                error_line: line,
                path: location.pathname,
                url: location.href,
            });
        });

        window.addEventListener('unhandledrejection', function (ev) {
            const reason = ev.reason;
            const msg = (reason && reason.message) ? reason.message : String(reason || 'unhandledrejection');
            trackApplicationError({
                error_id: jsErrorId(msg, 'unhandledrejection', 0),
                error_source: 'js',
                error_type: 'unhandledrejection',
                error_message: String(msg).slice(0, 500),
                path: location.pathname,
                url: location.href,
            });
        });

        bindLivewireErrorTracking();

        if (typeof window.fetch === 'function') {
            const origFetch = window.fetch;
            window.fetch = function (input, init) {
                const url = typeof input === 'string' ? input : (input && input.url) || '';
                const skipHttpTrack = isLivewireRequest(url, init);
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
                            url: res.url || location.href,
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
        // Reset JS dedupe when not on checkout so a prior empty/burned visit
        // does not block the next real checkout.
        if (!/checkout/i.test(location.pathname)) {
            try {
                sessionStorage.removeItem('cdp_checkout_started');
            } catch (e) {}
        }

        // Product List Viewed — menus / category pages
        if (/\/menus(\/|$)/.test(location.pathname) || document.querySelector('[data-menu-list], .menu-list, #category-menu-list')) {
            let category = '';
            const catEl = document.querySelector('[data-category-name], .category-title, h1');
            if (catEl) {
                category = (catEl.getAttribute('data-category-name') || catEl.textContent || '').trim();
            }
            window.diliboriAnalytics.track('Product List Viewed', {
                list_id: location.pathname,
                category: category || undefined,
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

        // Checkout Started — JS fallback if backend hook has not fired yet this session.
        // Skip empty-cart bounce pages (redirect target still briefly matches /checkout).
        function maybeTrackCheckoutStarted() {
            if (!/checkout/i.test(location.pathname) || /success/i.test(location.pathname)) {
                return;
            }
            const hasCheckoutUi = document.querySelector(
                'form[id*="checkout"], form[action*="checkout"], [data-control="checkout"], #checkout-form, .checkout'
            );
            if (!hasCheckoutUi) {
                return;
            }
            try {
                if (sessionStorage.getItem('cdp_checkout_started')) {
                    return;
                }
                // Set only after queueing so a later Product Added (new session
                // flag from PHP) can still pair with a fresh checkout visit.
                window.diliboriAnalytics.track('Checkout Started', { path: location.pathname, producer: 'frontend' });
                sessionStorage.setItem('cdp_checkout_started', '1');
            } catch (e) {
                window.diliboriAnalytics.track('Checkout Started', { path: location.pathname, producer: 'frontend' });
            }
        }
        maybeTrackCheckoutStarted();
        document.addEventListener('livewire:navigated', maybeTrackCheckoutStarted);
    }

    @if($driver === 'rudderstack')
    (function loadRudderstack() {
        const writeKey = @json($rudderstack['js_write_key'] ?? '');
        const dataPlaneUrl = @json($rudderstack['js_data_plane_url'] ?? '');
        if (!writeKey || !dataPlaneUrl) {
            return;
        }

        !function(){"use strict";window.rudderanalytics=window.rudderanalytics||[];var e=["load","page","track","identify","alias","group","ready","reset","getAnonymousId","setAnonymousId"];for(var i=0;i<e.length;i++){var t=e[i];window.rudderanalytics[t]=function(e){return function(){window.rudderanalytics.push([e].concat(Array.prototype.slice.call(arguments)))}}(t)}}();

        const script = document.createElement('script');
        script.async = true;
        script.src = 'https://cdn.rudderlabs.com/v3/modern/rsa.min.js';
        script.onload = function () {
            rudderanalytics.load(writeKey, dataPlaneUrl, {
                configUrl: @json($rudderstack['js_config_url'] ?? 'http://127.0.0.1:8090'),
                anonymousIdOptions: { autoCapture: { enabled: true } },
            });
            if (anonymousId) {
                try {
                    rudderanalytics.setAnonymousId(anonymousId);
                } catch (e) {}
            }
            rudderanalytics.ready(function () {
                try {
                    const id = rudderanalytics.getAnonymousId && rudderanalytics.getAnonymousId();
                    if (id) {
                        writeCookie(COOKIE, id);
                    }
                } catch (e) {}
                onReady({
                    identify(userId, traits) {
                        rudderanalytics.identify(userId, traits);
                    },
                    track(event, props) {
                        rudderanalytics.track(event, props);
                    },
                    page(name, props) {
                        rudderanalytics.page(name, props);
                    },
                    ready(cb) {
                        rudderanalytics.ready(cb);
                    },
                });
            });
        };
        document.head.appendChild(script);
    })();
    @elseif($driver === 'segment')
    // Future: load analytics.js and wire onReady(...) the same way.
    console.info('[dilibori.cdp] Segment driver not implemented in JS yet');
    @else
    console.info('[dilibori.cdp] No browser adapter for driver: {{ $driver }}');
    @endif
})();
</script>
@endunless
