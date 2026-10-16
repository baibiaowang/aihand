<?php
/**
 * Lightweight regression tests for review-plan helper logic.
 * Run with: php tests/review-unit.php
 */
define('ABSPATH', __DIR__ . '/');
function add_action(...$args) {}
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function hsm_canonical($value) { return $value; }
function wc_get_product_id_by_sku($sku) { return array('SKU-EXISTING' => 42, 'VAR-EXISTING' => 84)[$sku] ?? 0; }
require_once dirname(__DIR__) . '/includes/hsm-review.php';

function check($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}
function sorted_ids($ids) {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids);
    return $ids;
}

$input = array('id' => 12, 'sku' => 'SKU-EXISTING', 'confirm_token' => 'legacy-secret');
$with_token_removed = array('id' => 12, 'sku' => 'SKU-EXISTING');
check(
    hsm_review_canonical('ai-hand/product-upsert', $input) === hsm_review_canonical('ai-hand/product-upsert', $with_token_removed),
    'legacy confirmation token must not affect review plan identity'
);

$product_ids = hsm_review_extract_ids(
    array('id' => 12, 'sku' => 'SKU-EXISTING', 'variations' => array(array('sku' => 'VAR-EXISTING'))),
    'ai-hand/product-upsert'
);
check(sorted_ids($product_ids) === array(12, 42, 84), 'product-upsert must snapshot explicit IDs and existing product/variation SKUs');

$bulk_ids = hsm_review_extract_ids(
    array('items' => array(array('id' => 7), array('sku' => 'SKU-EXISTING')), 'filter' => array('ids' => array(1, 2))),
    'ai-hand/products-bulk-update'
);
check(sorted_ids($bulk_ids) === array(1, 2, 7, 42), 'bulk update must resolve item IDs, item SKUs and filter IDs');

$other_ids = hsm_review_extract_ids(
    array('filter' => array('ids' => array(91, 92))),
    'ai-hand/orders-search'
);
check($other_ids === array(), 'non-product abilities must not treat generic filter IDs as product IDs');

$skus = hsm_review_collect_skus(array('sku' => 'PARENT', 'variations' => array(array('sku' => 'CHILD'))));
check($skus === array('PARENT', 'CHILD'), 'SKU collection must include nested variation SKUs');

echo "Review helper regression tests passed.\n";
