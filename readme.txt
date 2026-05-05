=== Jify Loyalty ===
Contributors: jifycloud
Tags: loyalty, points, rewards, line, woocommerce
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.5.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Member loyalty point system with automatic order earning, checkout redemption, rule engine, and LINE Login binding for WooCommerce.

== Description ==

Jify Loyalty is a loyalty / points system for WooCommerce stores that need member-facing point earning and redemption without monthly SaaS fees. It writes its own audit-logged points table, hooks into the order completion lifecycle to award points, and exposes a checkout redemption UI so customers can spend points as cash discount.

A built-in rule engine lets you offer "Spend N points, get $M off" style redemption alongside a flat conversion rate. LINE Login binding is available for stores in markets where LINE is the dominant social channel (Taiwan, Japan, Thailand) — once bound, customers can sign in with LINE and continue accruing points on the same account.

**Key Features:**

*   **Automatic Order Earning**: Points are awarded automatically when an order's status becomes `completed`, based on a configurable conversion rate or fixed-threshold strategy.
*   **Checkout Redemption UI**: A redemption widget on the checkout page lets customers spend points instantly, with AJAX recalculation of the cart total.
*   **Rule Engine**: Define multiple redemption rules (e.g. "100 pts = $50 off", "500 pts = $300 off") and customers pick the one they want at checkout.
*   **My Account Points Page**: Customers can view their balance and full transaction history under their WooCommerce account.
*   **LINE Login Binding**: Optional LINE Login integration. Bound LINE accounts continue to earn points on the same member record.
*   **Thank You Page Hint**: Order confirmation page shows "You earned X points" with a binding CTA for unbound customers.
*   **Admin Adjustment UI**: Site admins can manually adjust any user's balance from the backend with reason logging.
*   **Independent Audit Log Table**: All point movements (earn / redeem / admin adjust) are written to a dedicated table with a `reference_id` for traceability.

== Installation ==

1.  Upload the plugin files to the `/wp-content/plugins/jify-loyalty` directory, or install through the WordPress plugins screen.
2.  Activate the plugin through the 'Plugins' screen in WordPress. The points table is created automatically on first activation.
3.  Go to **Jify Setting > Jify Loyalty** to configure the conversion rate, redemption rules, and (optionally) LINE Login credentials.

== Frequently Asked Questions ==

= When are points awarded? Are they reversed on refund? =
Points are awarded automatically when the order status transitions to `completed`. As of v0.5.1 refunds do not auto-deduct points — an admin needs to deduct manually from the user adjustment screen. Auto-reversal on refund is on the roadmap.

= Can customers earn points without binding LINE? =
Yes. LINE Login is optional. Standard WooCommerce members earn points normally; LINE binding only matters if a customer wants to sign in with LINE and have it map to their existing member account.

= Can I export the points data? =
Yes — the points and history live in dedicated tables, so you can export them via phpMyAdmin or wp-cli. A built-in export UI is planned for a later release.

== Changelog ==

= 0.5.1 =
*   Initial public release (early version).
*   Automatic order completion point earning.
*   Checkout redemption UI with AJAX recalculation.
*   Rule-engine based redemption.
*   LINE Login binding flow.
*   My Account points history page.
*   Independent audit log table with reference_id tracking.
