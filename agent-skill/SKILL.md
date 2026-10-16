---
name: ai-hand
description: Run a WooCommerce store through the AI Hand MCP server (tools prefixed ai-hand-). Use for products (incl. variations), pricing/promotions, inventory, orders, refunds, shipping, tax, coupons, customers, content, media, a built-in lightweight SEO suite (meta, Open Graph, Product JSON-LD, sitemap hygiene, redirects, 404 log, robots.txt) and a Google Merchant feed, plus reviews, customer support and marketing (subscriptions, conversion, post-purchase).
---

# Running a WooCommerce store with AI Hand

## Connect
- Same machine (best): `wp mcp-adapter serve --server=ai-hand --user=<shop-manager>` (STDIO), from the WordPress root.
- Remote (HTTP): endpoint `https://SITE/wp-json/ai-hand/mcp`, Basic auth with an **Application Password**. Use a Shop Manager user unless you need plugins/settings.

## Golden rules
1. Start every session with `ai-hand-store-overview`. It tells you currency, country, tax state, counts and which plugins are active.
2. Identify products by **SKU**. `product-upsert`, `product-get`, `products-bulk-update`, `order-create`, `coupon-manage` all accept SKUs.
3. `product-upsert` is idempotent: same SKU twice updates, never duplicates. Send only the fields you change.
4. Before any bulk price change run `products-bulk-update` with `dry_run: true`, show the diff to the user, then run it for real.
5. **Dangerous writes return `CONFIRMATION_REQUIRED` first (nothing changes).** Show that preview to the user; only if they approve, call again with the same arguments plus `confirm_token`.
6. Never refund, delete permanently, or change order status to cancelled/refunded without the user's explicit go-ahead. Refunds default to manual (`api_refund: false`).
7. Prices are strings without currency symbols ("19.99"). Dates are YYYY-MM-DD.
8. If a dedicated tool is missing a field, use `rest-request` (e.g. `/wc/v3/...`). **`rest-request` cannot write posts/pages/templates/global-styles/menu-items/navigation/blocks** — those go through `content-upsert` (which applies HTML filtering). Do not edit the database.
9. Check the guard rails any time with `ai-hand-safety-status` (read-only mode, daily refund/price budgets).

## Recipes
**New product with variants** — `product-upsert` {type:"variable", name, sku, description (≥300 chars, benefits first), short_description, categories:["Parent > Child"], images:[main, ...], attributes:[{name:"Size", options:[...], variation:true, global:true}], variations:[{attributes:{Size:"M"}, sku, gtin, regular_price, stock_quantity, image}], weight, dimensions, seo:{title ≤60, description 140–160, focus_keyword}}. Then `product-get` to verify, `merchant-feed` to check listing problems.

**Promotion** — `products-bulk-update` {filter:{category}, changes:{sale_percent:20, date_on_sale_from, date_on_sale_to}} (dry run first) + `coupon-manage` upsert. End with `changes:{clear_sale:true}`.

**Restock** — `sales-report` (low stock) or `products-search` {low_stock:5}; update with `products-bulk-update` items [{sku, stock_quantity}].

**Fulfil orders** — `orders-search` {status:"processing"} → `order-get` → `order-update` {tracking:{carrier, number, url}, notify_customer:true, status:"completed"}.

**Returns** — `order-refund` {id, items:[{sku, qty}], reason, restock:true}.

**Shipping** — `shipping-manage` upsert_zone; conditional rules via `shipping-rules`.

**SEO (built in, no SEO plugin needed)** — `seo-settings` once (org_name, org_logo, default_brand, ship_country, return_days, social_profiles). Then `seo-audit` → fix with `seo-update` / `product-upsert`. Moved URLs: `redirects-manage` add; check its 404 log weekly. Sitemap: `/wp-sitemap.xml`. If Yoast/Rank Math/AIOSEO/SEOPress is active, built-in output turns off and `seo-update` mirrors title/description/keyword into that plugin.

**Customer support** — `support` (Q&A / contact / order-lookup inbox). **Marketing** — `marketing` (newsletter + welcome coupon), `conversion` (BIS / urgency), `post-purchase` (recommendations).

## Undo
`ai-hand-audit-log` lists recent writes (with `undoable`); `ai-hand-undo {audit_id}` reverts one (needs confirmation).
