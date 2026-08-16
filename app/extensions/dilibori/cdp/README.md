# Dilibori CDP extension

Storefront analytics for TastyIgniter. Destination is selected by `EVENT_CLIENT_DRIVER`.

## Event catalog

[EVENTS.md](EVENTS.md) covers names, hooks, properties, and business questions. Keep [`src/Support/Events.php`](src/Support/Events.php) in sync.

## Drivers

| `EVENT_CLIENT_DRIVER` | Status | Notes |
|-----------------------|--------|--------|
| `rudderstack` | Implemented | Local data plane under `/cdp` |
| `null` | Implemented | No-op |
| `segment` | Stub | Same identify/track API |
| `kafka` | Stub | Topic naming later |

Listeners and `window.diliboriAnalytics` do not import a vendor SDK. Change the driver with env.
