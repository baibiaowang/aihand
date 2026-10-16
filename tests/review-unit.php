<?php
/**
 * Lightweight regression tests for review-plan helper logic.
 * Run with: php tests/review-unit.php
 */
define('ABSPATH', __DIR__ . '/');
function add_action(...$args) {}
function absint($value) { return abs((int)$value); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function hsm_canonical($value) { return $value; }
function wc_get_product_id_by_sku($sku) { return array('SKU-EXISTING' => 42, 'VAR-EXISTING' => 84)[$sku] ?? 0; }
function hsm_product_id($input) { return !empty($input['id']) ? (int)$input['id'] : wc_get_product_id_by_sku((string)($input['sku'] ?? '')); }
class ReviewTestWpError { public function get_error_message() { return 'restore failed'; } }
function is_wp_error($value) { return $value instanceof ReviewTestWpError; }
function hsm_apply_undo($entries) { return $GLOBALS['review_mock_undo_result'] ?? array(); }
class ReviewTestProduct {
    private $id;
    private $type;
    private $children;
    public function __construct($id, $type = 'simple', $children = array()) { $this->id = $id; $this->type = $type; $this->children = $children; }
    public function get_id() { return $this->id; }
    public function is_type($type) { return $this->type === $type; }
    public function get_children() { return $this->children; }
}
function wc_get_products($args) { return $GLOBALS['review_mock_products'] ?? array(); }
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

$GLOBALS['review_mock_products'] = array(new ReviewTestProduct(1, 'variable', array(2, 3)));
$candidate_ids = hsm_review_bulk_target_ids(array('items' => array(array('id' => 9)), 'filter' => array('ids' => array(1)), 'changes' => array('set_status' => 'draft')));
check($candidate_ids === array(1, 2, 3, 9), 'bulk target snapshot must include matched parents, variations and explicit items');

$GLOBALS['hsm_undo'] = array(array('type' => 'product', 'state' => array('id' => 1)));
$GLOBALS['review_mock_undo_result'] = array('restored product 1');
$rollback_ok = hsm_review_rollback_partial('ai-hand/product-upsert');
check(!empty($rollback_ok['attempted']) && $rollback_ok['ok'] === true, 'rollback should report success when every undo entry has a clean result');
$GLOBALS['review_mock_undo_result'] = array('product 1 no longer exists');
$rollback_failed = hsm_review_rollback_partial('ai-hand/product-upsert');
check($rollback_failed['ok'] === false, 'rollback must not report success when an undo entry could not be restored');
$GLOBALS['review_mock_undo_result'] = array(new ReviewTestWpError());
$rollback_wp_error = hsm_review_rollback_partial('ai-hand/product-upsert');
check($rollback_wp_error['ok'] === false && $rollback_wp_error['details'][0] === 'restore failed', 'WP_Error rollback details must be marked failed and normalized for safe admin rendering');
$GLOBALS['review_mock_undo_result'] = array('restored product 1');
$rollback_unsupported = hsm_review_rollback_partial('ai-hand/orders-refund');
check(empty($rollback_unsupported['attempted']), 'rollback helper must not run for unsupported or irreversible abilities');

echo "Review helper regression tests passed.\\n";
