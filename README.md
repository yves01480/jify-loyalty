# Jify Loyalty

> Loyalty point system with auto earning, checkout redemption, rule engine, and LINE Login binding for WooCommerce.

![License: GPLv2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)
![WordPress 6.0+](https://img.shields.io/badge/WordPress-6.0%2B-21759b)
![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![Stable](https://img.shields.io/badge/stable-0.5.1-orange)

A self-hosted loyalty / points plugin for WooCommerce — no monthly SaaS fees. Writes its own audit-logged points table, awards points on order completion, and exposes a checkout redemption widget. LINE Login binding is included for stores in markets where LINE is the dominant social channel (Taiwan / Japan / Thailand).

## Key Features

- **Automatic order earning** — points awarded on `completed` status, configurable conversion rate
- **Checkout redemption UI** — AJAX recalculation, customers spend points as cash discount
- **Rule engine** — multiple redemption tiers (e.g. "100 pts = $50 off", "500 pts = $300 off")
- **My Account points page** — balance and full transaction history
- **LINE Login binding** — optional, members keep accruing points on the same account
- **Thank-you page hint** — "You earned X points" with a binding CTA for unbound customers
- **Admin adjustment UI** — manual balance edit with reason logging
- **Independent audit log table** — every movement traceable via `reference_id`

## Installation

1. Upload to `/wp-content/plugins/jify-loyalty`, or install via the Plugins screen.
2. Activate from the **Plugins** screen — the points table is created on first activation.
3. Configure under **Jify Setting → Jify Loyalty** (conversion rate, redemption rules, optional LINE Login credentials).

## Documentation

Full feature docs and demo: <https://jify.cloud>

The canonical plugin metadata for WordPress.org lives in [`readme.txt`](readme.txt).

## License

GPL-2.0-or-later — see [`license.txt`](license.txt).
