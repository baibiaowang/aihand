<?php
/**
 * v1.8 Invoices & packing slips as printable HTML (replaces "PDF Invoices & Packing Slips" plugins) — OFF by default.
 *
 * No PDF library: a clean, print-ready HTML page (browser → Print → Save as PDF). Each order gets a
 * sequential invoice number (prefix + atomic counter), shown on the invoice and stored in order meta.
 *
 * Access: the store owner can open any invoice from the order screen; a customer can open their own
 * from My Account. The endpoint is guarded by capability, order ownership, or the order key (the same
 * per-order secret WooCommerce puts in its emails), so no other customer's invoice is reachable.
 */
defined( 'ABSPATH' ) || exit;

function hsm_invoice_settings() {
	$s = wp_parse_args( (array) get_option( 'hsm_invoice', array() ), array(
		'enabled'    => false,
		'prefix'     => 'INV-',
		'start'      => 1,
		'padding'    => 4,
		'company'    => array(),
		'footer'     => '',
		'packing'    => true,
	) );
	$s['enabled'] = (bool) $s['enabled'];
	$s['prefix']  = (string) $s['prefix'];
	$s['start']   = max( 1, (int) $s['start'] );
	$s['padding'] = max( 0, min( 20, (int) $s['padding'] ) );
	$s['company'] = is_array( $s['company'] ) ? $s['company'] : array();
	$s['packing'] = (bool) $s['packing'];
	return $s;
}

/** Stored invoice number for an order ('' when none). */
function hsm_invoice_get_number( $order ) {
	$o = $order instanceof WC_Order ? $order : wc_get_order( (int) $order );
	return $o ? (string) $o->get_meta( '_hsm_invoice_number' ) : '';
}

/** Reserve the next invoice counter atomically. */
function hsm_invoice_reserve() {
	global $wpdb;
	$s   = hsm_invoice_settings();
	$opt = 'hsm_invoice_counter';
	add_option( $opt, $s['start'] - 1, '', false );
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID( CAST( option_value AS UNSIGNED ) + 1 ) WHERE option_name = %s",
		$opt
	) );
	return (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
}

/** Assign an invoice number if missing and the module is on. Returns the number. */
function hsm_invoice_assign( $order ) {
	$o = $order instanceof WC_Order ? $order : wc_get_order( (int) $order );
	if ( ! $o ) {
		return '';
	}
	$existing = hsm_invoice_get_number( $o );
	if ( '' !== $existing ) {
		return $existing;
	}
	$s = hsm_invoice_settings();
	if ( ! $s['enabled'] ) {
		return '';
	}
	$n = hsm_invoice_reserve();
	$n = $s['prefix'] . ( $s['padding'] > 0 ? str_pad( (string) $n, $s['padding'], '0', STR_PAD_LEFT ) : (string) $n );
	$o->update_meta_data( '_hsm_invoice_number', $n );
	$o->save();
	return $n;
}

/** Issue the invoice number when an order becomes paid/fulfilled (processing or completed). */
add_action( 'woocommerce_order_status_processing', 'hsm_invoice_assign' );
add_action( 'woocommerce_order_status_completed', 'hsm_invoice_assign' );

/** URL to a printable document for an order. */
function hsm_invoice_url( $order, $type = 'invoice' ) {
	$o = $order instanceof WC_Order ? $order : wc_get_order( (int) $order );
	if ( ! $o ) {
		return '';
	}
	return add_query_arg(
		array( 'hsm_invoice' => '1', 'order' => $o->get_id(), 'type' => 'packing' === $type ? 'packing' : 'invoice', 'key' => $o->get_order_key() ),
		home_url( '/' )
	);
}

/** The printable HTML document. */
function hsm_invoice_html( $order, $type = 'invoice' ) {
	$o   = $order instanceof WC_Order ? $order : wc_get_order( (int) $order );
	$s   = hsm_invoice_settings();
	$c   = $s['company'];
	$cur = $o->get_currency();
	$sym = array( 'currency' => $cur, 'decimal_separator' => wc_get_price_decimal_separator(), 'thousand_separator' => wc_get_price_thousand_separator(), 'decimals' => wc_get_price_decimals(), 'price_format' => get_woocommerce_price_format() );
	$money = function ( $v ) use ( $sym ) {
		return wc_price( $v, $sym );
	};
	$num  = 'invoice' === $type ? hsm_invoice_get_number( $o ) : '';
	$title = 'packing' === $type ? 'Packing slip' : 'Invoice';
	$addr  = $o->get_address( 'shipping' );
	if ( empty( array_filter( $addr ) ) ) {
		$addr = $o->get_address( 'billing' );
	}
	ob_start();
	?>
<!DOCTYPE html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $title . ' ' . ( $num ?: $o->get_order_number() ) ); ?></title>
<style>
body{font:14px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111;margin:0;padding:32px;background:#f6f7f8}
.sheet{max-width:780px;margin:0 auto;background:#fff;padding:40px;border:1px solid #e2e4e7}
h1{font-size:22px;margin:0 0 4px}.muted{color:#666}.row{display:flex;justify-content:space-between;gap:24px;flex-wrap:wrap}
table{width:100%;border-collapse:collapse;margin:20px 0}th,td{text-align:left;padding:8px;border-bottom:1px solid #eee}
th{background:#fafafa}.num{text-align:right}.totals{width:280px;margin-left:auto}
.totals td{border:0;padding:4px 8px}.totals .grand{font-weight:700;border-top:2px solid #111}
.toolbar{max-width:780px;margin:0 auto 12px;text-align:right}@media print{body{background:#fff;padding:0}.sheet{border:0;max:none}.toolbar{display:none}}
</style></head><body>
<div class="toolbar"><button onclick="window.print()">Print / Save as PDF</button></div>
<div class="sheet">
<div class="row">
<div><h1><?php echo esc_html( $c['name'] ?? get_bloginfo( 'name' ) ); ?></h1>
<div class="muted"><?php echo nl2br( esc_html( (string) ( $c['address'] ?? '' ) ) ); ?><br>
<?php echo esc_html( (string) ( $c['email'] ?? '' ) ); ?> <?php echo esc_html( (string) ( $c['phone'] ?? '' ) ); ?></div></div>
<div style="text-align:right"><h1><?php echo esc_html( $title ); ?></h1>
<div><?php echo esc_html( $num ?: ( 'Invoice #' . $o->get_order_number() ) ); ?></div>
<div class="muted"><?php echo esc_html( $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d' ) : '' ); ?><br>Order <?php echo esc_html( $o->get_order_number() ); ?></div></div>
</div>
<div class="row" style="margin-top:24px">
<div><strong>Bill to</strong><br><?php echo wp_kses_post( $o->get_formatted_billing_address() ?: '—' ); ?><br><?php echo esc_html( $o->get_billing_email() ); ?></div>
<div><strong><?php echo 'packing' === $type ? 'Ship to' : 'Ship to'; ?></strong><br><?php echo wp_kses_post( $o->get_formatted_shipping_address() ?: $o->get_formatted_billing_address() ?: '—' ); ?></div>
</div>
<table><thead><tr><th>Item</th><th class="num">Qty</th><?php if ( 'packing' !== $type ) : ?><th class="num">Price</th><th class="num">Total</th><?php endif; ?></tr></thead><tbody>
<?php foreach ( $o->get_items() as $it ) : $p = $it->get_product(); ?>
<tr><td><?php echo esc_html( $it->get_name() ); ?><?php echo $p && $p->get_sku() ? '<br><span class="muted">SKU ' . esc_html( $p->get_sku() ) . '</span>' : ''; ?></td>
<td class="num"><?php echo esc_html( $it->get_quantity() ); ?></td>
<?php if ( 'packing' !== $type ) : ?><td class="num"><?php echo wp_kses_post( $money( $it->get_subtotal() / max( 1, $it->get_quantity() ) ) ); ?></td>
<td class="num"><?php echo wp_kses_post( $money( $it->get_subtotal() ) ); ?></td><?php endif; ?></tr>
<?php endforeach; ?>
</tbody></table>
<?php if ( 'packing' !== $type ) : ?>
<table class="totals"><tbody>
<tr><td>Subtotal</td><td class="num"><?php echo wp_kses_post( $money( $o->get_subtotal() ) ); ?></td></tr>
<?php if ( $o->get_discount_total() > 0 ) : ?><tr><td>Discount</td><td class="num">−<?php echo wp_kses_post( $money( $o->get_discount_total() ) ); ?></td></tr><?php endif; ?>
<?php if ( $o->get_shipping_total() > 0 ) : ?><tr><td>Shipping</td><td class="num"><?php echo wp_kses_post( $money( $o->get_shipping_total() ) ); ?></td></tr><?php endif; ?>
<?php if ( $o->get_total_tax() > 0 ) : ?><tr><td>Tax</td><td class="num"><?php echo wp_kses_post( $money( $o->get_total_tax() ) ); ?></td></tr><?php endif; ?>
<tr class="grand"><td>Total</td><td class="num"><?php echo wp_kses_post( $money( $o->get_total() ) ); ?></td></tr>
</tbody></table>
<p class="muted">Payment: <?php echo esc_html( $o->get_payment_method_title() ?: '—' ); ?></p>
<?php endif; ?>
<?php if ( ! empty( $s['footer'] ) ) : ?><p class="muted" style="margin-top:28px"><?php echo nl2br( esc_html( (string) $s['footer'] ) ); ?></p><?php endif; ?>
</div></body></html>
	<?php
	return (string) ob_get_clean();
}

/* --------------------------------------------------------------------- */
/* Endpoint: /?hsm_invoice=1&order=<id>&type=invoice|packing&key=<order_key> */
/* --------------------------------------------------------------------- */

add_action( 'init', function () {
	if ( empty( $_GET['hsm_invoice'] ) || ! hsm_invoice_settings()['enabled'] ) {
		return;
	}
	$o = wc_get_order( (int) ( $_GET['order'] ?? 0 ) );
	if ( ! $o ) {
		wp_die( 'Order not found', 404 );
	}
	$key   = (string) ( $_GET['key'] ?? '' );
	$owner = $o->get_customer_id() && get_current_user_id() === $o->get_customer_id();
	$staff = current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	if ( ! $staff && ! $owner && ! ( '' !== $key && hash_equals( $o->get_order_key(), $key ) ) ) {
		wp_die( 'Forbidden', 403 );
	}
	$type = ( 'packing' === ( $_GET['type'] ?? '' ) ) ? 'packing' : 'invoice';
	if ( 'invoice' === $type ) {
		hsm_invoice_assign( $o ); // number the order on first invoice
	}
	nocache_headers();
	header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
	echo hsm_invoice_html( $o, $type ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside
	exit;
} );

/* Owner: a button on the order screen. */
add_action( 'woocommerce_admin_order_data_after_order_details', function ( $order ) {
	if ( ! hsm_invoice_settings()['enabled'] ) {
		return;
	}
	$s = hsm_invoice_settings();
	echo '<p class="form-field"><a class="button" target="_blank" href="' . esc_url( hsm_invoice_url( $order, 'invoice' ) ) . '">Invoice</a> ';
	if ( $s['packing'] ) {
		echo '<a class="button" target="_blank" href="' . esc_url( hsm_invoice_url( $order, 'packing' ) ) . '">Packing slip</a>';
	}
	echo '</p>';
} );

/* Customer: an action on the My Account orders list + the single order view. */
add_filter( 'woocommerce_my_account_my_orders_actions', function ( $actions, $order ) {
	if ( hsm_invoice_settings()['enabled'] ) {
		$actions['hsm_invoice'] = array( 'url' => hsm_invoice_url( $order, 'invoice' ), 'name' => 'Invoice' );
	}
	return $actions;
}, 10, 2 );

add_action( 'woocommerce_order_details_after_order_table', function ( $order ) {
	if ( ! hsm_invoice_settings()['enabled'] ) {
		return;
	}
	echo '<p class="hsm-invoice-link"><a class="button" target="_blank" rel="noopener" href="' . esc_url( hsm_invoice_url( $order, 'invoice' ) ) . '">View invoice</a></p>';
}, 25 );

/* --------------------------------------------------------------------- */
/* MCP tool: hark-store/invoice                                            */
/* --------------------------------------------------------------------- */

function hsm_invoice_actions() {
	return array( 'get' => false, 'get_settings' => false, 'assign' => true, 'set_settings' => true );
}

hsm_ability( 'invoice', array(
	'label'       => 'Invoices & packing slips',
	'description' => 'Printable HTML invoices and packing slips (print to PDF in the browser; no PDF library). action=get (order_id → invoice number + printable URLs) | assign (order_id: give the order its sequential invoice number now) | get_settings | set_settings (enabled, prefix, start, padding, company {name,address,email,phone}, footer, packing). The owner opens documents from the order screen; a customer opens their own from My Account. Invoice numbers are sequential and never reused.',
	'properties'  => array(
		'action'   => hsm_s( 'string', '', array( 'enum' => array_keys( hsm_invoice_actions() ) ) ),
		'order_id' => hsm_s( 'integer' ),
		'enabled'  => hsm_s( 'boolean' ),
		'prefix'   => hsm_s( 'string' ),
		'start'    => hsm_s( 'integer' ),
		'padding'  => hsm_s( 'integer' ),
		'company'  => hsm_s( 'object', '{name,address,email,phone}' ),
		'footer'   => hsm_s( 'string' ),
		'packing'  => hsm_s( 'boolean', 'Offer a packing slip as well as the invoice.' ),
	),
	'is_write'    => function ( $in ) {
		return ! empty( hsm_invoice_actions()[ $in['action'] ?? '' ] );
	},
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$action = (string) ( $in['action'] ?? 'get' );
		if ( ! array_key_exists( $action, hsm_invoice_actions() ) ) {
			throw new Exception( 'Unknown action. Allowed: ' . implode( ', ', array_keys( hsm_invoice_actions() ) ) );
		}
		if ( 'get_settings' === $action ) {
			return hsm_invoice_settings();
		}
		if ( 'set_settings' === $action ) {
			$before = get_option( 'hsm_invoice', array() );
			$new    = $before;
			foreach ( array( 'enabled', 'prefix', 'start', 'padding', 'footer', 'packing' ) as $k ) {
				if ( array_key_exists( $k, $in ) ) {
					$new[ $k ] = $in[ $k ];
				}
			}
			if ( array_key_exists( 'company', $in ) ) {
				$new['company'] = array_map( 'sanitize_text_field', array_intersect_key( (array) $in['company'], array_flip( array( 'name', 'address', 'email', 'phone' ) ) ) );
			}
			hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_invoice', 'old' => $before ) );
			update_option( 'hsm_invoice', $new );
			return array( 'action' => 'set_settings', 'settings' => hsm_invoice_settings() );
		}
		$o = wc_get_order( (int) ( $in['order_id'] ?? 0 ) );
		if ( ! $o ) {
			throw new Exception( 'Order not found.' );
		}
		if ( 'assign' === $action ) {
			if ( ! hsm_invoice_settings()['enabled'] ) {
				throw new Exception( 'Enable the invoice module first (action=set_settings enabled=true).' );
			}
			$n = hsm_invoice_assign( $o );
			return array( 'action' => 'assign', 'order_id' => $o->get_id(), 'invoice_number' => $n );
		}
		return array(
			'order_id'       => $o->get_id(),
			'invoice_number' => hsm_invoice_get_number( $o ),
			'invoice_url'    => hsm_invoice_url( $o, 'invoice' ),
			'packing_url'    => hsm_invoice_settings()['packing'] ? hsm_invoice_url( $o, 'packing' ) : null,
			'note'           => 'These URLs carry the order key (the same secret WooCommerce emails). They open for the order owner or store staff.',
		);
	},
) );
