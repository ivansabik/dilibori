# Dilibori CDP extension

Storefront analytics for TastyIgniter. `EVENT_CLIENT_DRIVER` picks the destination.

The local data plane, test steps, and event catalog are in [cdp/README.md](../../../../cdp/README.md). Storefront Compose and seed are in [app/README.md](../../../../app/README.md).

## Event catalog

Names, hooks, properties, and the question each event answers are in the [event catalog](../../../../cdp/README.md#event-catalog). Keep [`src/Support/Events.php`](src/Support/Events.php) in sync with that catalog.

## Drivers

| `EVENT_CLIENT_DRIVER` | Status | Notes |
|-----------------------|--------|--------|
| `rudderstack` | Implemented | Local data plane under `cdp/` |
| `null` | Implemented | Drops events |
| `segment` | Stub | Same identify/track API |
| `kafka` | Stub | Topic naming later |

Listeners and `window.diliboriAnalytics` do not import a vendor SDK. Change the driver with env.
