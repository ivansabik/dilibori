# Dilibori for restaurant owners

Dilibori is an online ordering site and back office for one restaurant. That restaurant can be a single location or several. Guests order for pickup or delivery, book a table, and keep an account. Staff run lunch from one admin.

How to start the demo on a laptop is in the [project README](../../README.md#quick-start). This page is about what the software does for the restaurant.

## The problems it covers

Phone and WhatsApp orders get typed in again by hand. A menu change reaches one location and misses the other. Table bookings live in a notebook and double-book on Friday. A shop on the border needs English and Spanish, and dollars or pesos, depending on the location. Marketplace apps take the order, keep a commission, and show other restaurants next to your dishes.

## Locations, language, and currency

Several locations share one admin. Each location has its own hours, delivery zones, checkout rules, and currency. The storefront can offer more than one language. On the El Taconazo demo that is English and Spanish (Mexico). Menu and page translations are edited in admin. When a guest picks a location, prices follow that location: USD in El Paso, MXN in Ciudad Juárez. A one-location shop uses the same software.

What the guest sees: [language and currency](customer-features.md#language-and-currency). What staff configure: [locations](owner-admin-features.md#locations).

## Who it is for

Independent restaurants and small chains, including shops that serve more than one city or more than one currency.

## The El Taconazo demo

The demo restaurant has two locations: Alameda Ave (El Paso, USD) and Tecnológico (Ciudad Juárez, MXN). Guests switch between English and Spanish (Mexico), order for delivery or pickup, use coupons such as `TACOMIERCOLES`, and book a patio table.

Screenshots of that demo are in the guides below.

## What you can do

- Run several locations under one login, each with its own currency when you need that.
- Work orders in one queue: status, assignee, accept, reject, or delay.
- Edit the menu, including translations, and attach items to the locations that sell them.
- Create coupons with rules, including a code that applies at one location, and see who redeemed it.
- Book tables against the seats you actually have.

Day-to-day work stays in the admin. An optional analytics setup can record orders and storefront errors for the person who runs the software. Staff-facing notes are in [owner and staff tools](owner-admin-features.md#eventing-alerting-and-monitoring). The engineering setup is in the [CDP guide](../../cdp/README.md).

## Feature guides

- [What customers see](customer-features.md): ordering, accounts, reservations, language, and currency
- [What owners and staff use](owner-admin-features.md): admin, kitchen workflow, marketing, payments, and automation

Each guide marks a feature as included, available once you configure it, or not part of Dilibori.
