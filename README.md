# AI Hand (Store Operations)

> **用途**：一个 WordPress 插件 = 一个 MCP 服务器，让 AI agent **运营真实的 WooCommerce 店铺**——商品（含变体）、订单、退款、优惠券、客户、发货、内容、媒体、内置 SEO、评论、客服、营销，共 **43 个工具**；带确认令牌 / undo / 审计 / 价格护栏。
> **状态**：开发中（**v1.3.0**）。本地 Docker 真店（300 商品）验证通过；**未发布**到 wordpress.org。
> **配套仓库**：同项目的 `ai-tools`（替代后台小插件）与 `ai-design`（店铺美观），三者**可共存**（共享 `hsm-core.php` 框架、命名空间 `ai-hand/`、option 前缀 `hsm_`）。二者暂未单独建仓。
> **快速开始**：见 [安装](#安装)。

带**安全护栏**：人工审核台（触发确认的操作需店主批准并执行）、undo（可回滚）、审计日志（ok/blocked/error 都记）、价格护栏（含成本下限）、单日退款/改价上限、限流。护栏设置**只能由店主在后台/WP-CLI 改，AI 改不了**。

## 依赖
WordPress 6.9+ · WooCommerce 10.9+ · 官方 **MCP Adapter** 插件（wordpress.org/plugins/mcp-adapter）。

## 安装
1. 装并启用 MCP Adapter + 本插件。
2. 本机（STDIO）：`wp mcp-adapter serve --server=ai-hand --user=<店长账号>`
3. 远程（HTTP）：端点 `https://<站点>/wp-json/ai-hand/mcp`，用 **应用密码** 鉴权（Basic）。
4. 把 `agent-skill/SKILL.md` 放进你的 AI 工具的 skills 目录（如 `~/.claude/skills/ai-hand/SKILL.md`）。

## 工具一览（43）

| 组 | 工具 |
|---|---|
| 探针/概览 | `ping` `store-overview` `store-settings` |
| 商品 | `product-upsert` `product-get` `products-search` `products-bulk-update` `product-delete` `taxonomy-manage` `coupon-manage` |
| 订单 | `orders-search` `order-get` `order-create` `order-update` `order-refund` `customers-manage` `sales-report` `order-export` `order-numbers` `invoice` |
| 履约 | `shipments` `shipping-rules` `shipping-manage` `tax-manage` |
| 内容 | `content` `content-upsert` `content-search` `media-upload` |
| SEO | `seo-update` `seo-audit` `seo-settings` `redirects-manage` `merchant-feed` |
| 评论/客服 | `reviews` `support` |
| 营销 | `marketing` `conversion` `post-purchase` |
| 站点 | `site-extensions` `rest-request` |
| 安全/审计 | `audit-log` `undo` `safety-status` |

## 安全层
- **审核台审批**：触发确认的操作会先保存为待审核计划，不执行线上修改。店主在 WooCommerce > AI 操作审核中查看风险、商品当前状态与变更预览，批准后再单独点击执行。旧版 `confirm_token` 不再作为审批凭证接受。
- **undo**：每次写操作可回滚（商品/价格/库存/SEO/优惠券/设置/重定向/页面）。`audit-log` 查历史，`undo {audit_id}` 回滚。
- **护栏**：`safety-status` 读当前限额；店主在 **WooCommerce > Hark Store Safety** 或 `wp hark-store guard set <key> <value>` 改。
