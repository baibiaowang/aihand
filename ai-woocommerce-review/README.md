# AI WooCommerce Review V1

这是 baibiaowang/aihand 中的第一版 AI WooCommerce 人工审核闸门。

## 核心流程

AI 只提交结构化变更计划：

AI -> JSON Plan -> 服务端读取真实 WooCommerce 当前值 -> pending_review -> 店主审核 -> approve/reject -> approve 时再次校验 -> approved -> execute -> completed/failed/partial_failed。

审核台不会因为打开页面而修改商品。

## V1 支持

任务类型：
- product_price_update
- product_stock_update
- product_text_update

商品字段：
- regular_price
- sale_price
- stock_quantity
- name
- description
- short_description

V1 不支持退款、删除、支付设置、客户数据导出、PHP/Shell/SQL 执行、主题/插件部署。

## 安全边界

1. AI 的 before 不可信；创建任务时服务器重新读取 WooCommerce 当前值并要求一致。
2. 任务保存 SHA-256 plan hash；批准和执行前重新计算。
3. 批准时再次读取线上商品；线上数据发生变化则任务进入 stale，不执行。
4. approve 与 execute 是两个独立状态转换。
5. 执行使用条件状态更新，避免同一任务被两个请求同时领取。
6. REST endpoint 使用 WordPress capability。
7. 浏览器内容使用 text/attribute escaping，AI 文本不会直接作为脚本执行。
8. 商品修改使用 WooCommerce CRUD 对象，不直接写 WooCommerce postmeta。
9. 所有审批和执行事件写入 aiwc_task_audit。

## 安装

将 ai-woocommerce-review 放入 WordPress wp-content/plugins/，确认 WooCommerce 已启用，然后激活插件。

激活时创建：
- wp_aiwc_tasks
- wp_aiwc_task_audit

并给管理员增加 AIWC 相关 capability。

## AI 集成

AI 应调用 POST /wp-json/aiwc/v1/tasks 创建任务。认证必须使用现有 WordPress 认证机制；不要把 WooCommerce secret/API key 放进浏览器。

示例 JSON：

{
  "schema_version": 1,
  "task_type": "product_price_update",
  "title": "降低户外用品售价",
  "description": "将指定商品售价降低10%",
  "changes": [
    {
      "object_type": "product",
      "object_id": 123,
      "field": "regular_price",
      "before": "49.99",
      "after": "44.99"
    }
  ]
}

AI 必须把任务创建成功理解为“等待人工审核”，而不是“商品已修改”。

## 当前状态

这是 V1 核心实现，不包含本地 Docker 页面沙盒。页面实时预览可以在后续版本加入；V1 先把审批安全边界和商品变更闭环做正确。
