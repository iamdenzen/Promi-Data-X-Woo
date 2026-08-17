# Promi-Data X Woo

A WordPress plugin that integrates the **Promi-Data** product feed with **WooCommerce** — syncing products, pricing, and print-customization data, and layering a cost-based markup pricing engine on top.

- **Requires:** WordPress 5.2+, PHP 8.0+, WooCommerce (active)
- **License:** GPL v2 or later

## What it does

- **Promi product sync** — polls a Promi feed, detects changed products by hash, and queues create/update/disable jobs that a background worker processes into WooCommerce products.
- **Cost-based pricing engine** — computes selling prices from Promi purchase costs (or a manufacturer-discounted RRP when no purchase cost is available), with configurable markup by category, and quantity tier pricing.
- **Print customization** — product-page configurator for print positions/options, with per-option price and fee markup, applied consistently on the product page and in the cart.
- **Price-on-request inquiries** — a quote-request form for products without a usable price, stored and manageable from the admin.
- **Import notifications** — configurable email recipients get notified about import errors, newly queued work, and index runs that found nothing to update.

## Requirements

- WordPress 5.2 or newer
- PHP 8.0 or newer
- WooCommerce, installed and active

## Installation

1. Copy the plugin into `wp-content/plugins/promi-data-x-woo`.
2. Activate it from **Plugins** in wp-admin (WooCommerce must already be active).
3. Configure the Promi feed URL under **Promi-Data X Woo → Dashboard**.
4. Run an index sync (manually from the Dashboard, or wait for the scheduled cron job) to start pulling products.

Activation creates the plugin's custom database tables automatically; reactivating or upgrading re-runs schema checks (`Core\Database::maybe_upgrade()`) so existing installs pick up new tables/columns without a manual step.

## How pricing works

```
Promi purchase cost (or RRP × manufacturer discount)
        ↓
  category / default markup      →  article price
        ↓
  quantity tier pricing
        ↓
  print option price + fee markup → printing price
        ↓
  final WooCommerce unit price (rounded once, before it's charged)
```

- **Article pricing** (`Pricing\CostCalculator`, `Pricing\TieredPricing`) resolves a product's cost basis and applies category (or default) markup.
- **Manufacturer discounts** (`Pricing\ManufacturerDiscount`) apply only when a product has no direct Promi purchase price but does have a manufacturer RRP — the discount converts the RRP into an effective cost basis before markup. Configured per brand (WooCommerce `product_brand` taxonomy) under **Promi-Data X Woo → Pricing Markups**.
- **Printing** (`Printing\Calculator`, `Printing\Fees`) prices each selected print position/option from its purchase cost, with independently configurable price and fee markup — as defaults, or overridden per print option.
- **Cart breakdown**: every cart item gets a full, explicitly-named pricing breakdown stored under cart item key `_pdxw_pricing` (see [Cart item pricing breakdown](#cart-item-pricing-breakdown) below), so a theme's `cart.php`/`checkout` templates can render an accurate line-item cost split without recalculating anything.

All prices are rounded once, at the point they're committed to WooCommerce — not before — so the unit price a customer sees and the total for a given quantity always agree exactly.

## Admin screens

Under **Promi-Data X Woo** in wp-admin:

| Page | Purpose |
|---|---|
| Dashboard | Feed URL, worker batch size, notification recipients, manual index/worker triggers, cron pause/resume |
| Promi Index | Local index of feed items and their hashes |
| Queue | Import job queue (create/update/disable), status, retry |
| Ignore SKUs / Ignore Rules | Exclude specific products or fields from sync |
| Tier Pricing | Quantity-based pricing per product/variation |
| Printing | Print positions, options, prices and fees |
| Pricing Markups | Default and per-category/print-option/brand markup rules |
| Inquiries | Price-on-request quote submissions from the storefront |

## Import notifications

Configure one or more recipient emails under **Dashboard → Notification Recipients** (one per line). The plugin (`Promi\Notifier`) emails those recipients when:

- the feed fails to fetch, is empty, or fails the mass-disable safety check
- an import job permanently fails after its retry attempts are exhausted
- an index run queues new create/update/disable work (a summary of what's queued)
- an index run completes but finds nothing that needs updating

Leave the field empty to disable notifications entirely.

## Cart item pricing breakdown

Available on every cart item under `Pricing\CartPricing::BREAKDOWN_KEY` (`_pdxw_pricing`), or via:

```php
$breakdown = pdxw()->pricing()->cart()->breakdown( $cart_item );
// or a single value:
$value = pdxw()->pricing()->cart()->breakdown_value( $cart_item, 'line_total' );
```

| Key | Meaning |
|---|---|
| `base_unit_price` | Product price alone, per unit — excludes printing and fees |
| `base_total` | `base_unit_price × quantity` |
| `printing_unit_price` | All attached print positions/options, per unit |
| `printing_total` | `printing_unit_price × quantity` |
| `fees_total` | All setup + ongoing print fees, combined into one total |
| `fee_breakdown` | Itemized list of each individual fee (label, type, raw cost, markup, selling amount) |
| `unit_price` | Actual WooCommerce per-unit price charged |
| `line_total` | Actual WooCommerce line total — the authoritative charged amount |
| `status`, `price_on_request` | Whether this item could be priced, or needs a quote request |
| `cost`, `article_markup`, `article_source`, `manufacturer_discount` | Diagnostic detail, not usually needed by a template |

`unit_price`/`line_total` are always what WooCommerce actually bills. `base_total` and `printing_total` are each rounded from their own per-unit price, so they agree exactly with the unit price shown on their own row; the sum of `base_total + printing_total + fees_total` is normally, but not strictly guaranteed to be, identical to `line_total` (rounding at different aggregation points can differ by a fraction of a cent in edge cases).

## Architecture

```
includes/
├── Core/       bootstrap, DB schema/migrations, plugin container (Core\Plugin)
├── Catalog/    WooCommerce product/brand access
├── Pricing/    cost calculation, markup rules, tiered pricing, cart price application
├── Printing/   print positions/options, fee calculation, cart integration
├── Promi/      feed client, indexer, queue, worker, product sync, notifications
├── Frontend/   product-page assets/shortcodes/AJAX, price-on-request inquiries
└── Admin/      wp-admin pages, AJAX, product list integration
```

Each module is a plain PHP class autoloaded from its namespace (`PromiDataXWoo\Foo\Bar` → `includes/Foo/Bar.php`, see `promi-data-x-woo.php`) — no build step or Composer dependency required. `Core\Plugin` wires the module dependency graph together in `boot()`; most business logic is reachable from a module's public API rather than through globals.

## Database tables

Managed by `Core\Database`, all prefixed `cx_` (preserved from the plugin's predecessor to avoid a data migration):

`cx_promi_index`, `cx_promi_queue`, `cx_promi_ignore_skus`, `cx_promi_ignore_rules`, `cx_tier_prices`, `cx_print_positions`, `cx_print_options`, `cx_print_prices`, `cx_print_fees`, `cx_print_relation`, `cx_pricing_markup_rules`, `cx_inquiries`.

## License

GPL v2 or later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
