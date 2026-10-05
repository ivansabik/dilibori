# Dilibori for restaurant owners

Dilibori is your own online ordering site and back office for one branch or several: pickup and delivery, table reservations, and a staff admin that runs the lunch rush without rewriting every order by hand. A core strength is multi-branch operation where each *sucursal* can run its own language and currency under one login.

## The problems it tackles

Orders still land on phone or WhatsApp and get retyped. Menu changes hit one branch and miss the other. Table bookings live in a notebook that double-books on Friday. On the border, customers expect English and Mexican Spanish, and dollars or pesos depending on the *sucursal*. Marketplace apps will take the order, and keep a cut.

## Multi-branch, language, and currency

**Included.** Several locations under one admin, each with its own hours, delivery zones, checkout rules, and currency. The storefront can offer more than one language (English and Español México, for example), with menu and page translations edited in admin. When a guest picks a location, prices follow that branch (USD in El Paso, MXN in Juárez). One-branch shops use the same stack.

That is one brand that still prices and speaks correctly per *sucursal*, not a separate website per country. Details: [language and currency for diners](customer-features.md#language-and-currency), [locations for owners](owner-admin-features.md#locations).

## Who it is for

Independent restaurants and small chains. One location or several, including cross-border brands that need mixed languages and currencies.

## What is already running

- **El Taconazo:** Alameda Ave (El Paso, USD) and Tecnológico (Cd Juárez, MXN). English and Español (México) with a language picker. Delivery, pickup, coupons such as `TACOMIERCOLES`, and patio reservations.
- **Pizza Rin:** Centro and Pradera in Ciudad Juárez, Mexican Spanish only, prices in pesos. Same ordering tools for a pizzería that wants customers to invite their *cuates*.

## What owners get out of it

- Several branches under one login, each with its own language set and currency when you need that.
- Orders in one queue with status, assignee, and accept / reject / delay.
- Menu edits (and translations) reach every branch you attach them to.
- Coupons with rules and per-location codes, plus redemption history.
- Table bookings against real seating capacity.

Behind the admin, Dilibori streams storefront events and health checks so whoever runs the stack can see the funnel and catch failures. See [owner and admin features](owner-admin-features.md#eventing-alerting-and-monitoring). Day-to-day work stays in `/admin`.

## Feature guides

- [What customers see](customer-features.md): storefront ordering, accounts, reservations, language and currency
- [What owners and staff use](owner-admin-features.md): admin, kitchen workflow, marketing, payments, automation, and platform eventing

Each guide marks features as Included, Available when configured, or Not part of Dilibori.
