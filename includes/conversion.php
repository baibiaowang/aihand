<?php
/**
 * v1.8 Conversion & repeat purchase — OFF by default.
 *
 * Back-in-stock alerts, tiered quantity discounts (clamped to the safety guards' price floor so a
 * discount can never break the margin rule), a free gift over a threshold, a free-shipping nudge,
 * gift message / gift wrap at checkout, a real-deadline countdown, recently viewed products and
 * "frequently bought together" computed from real order data.
 *
 * No fake urgency: the countdown only renders for a deadline the caller supplies (e.g. a product's
 * real sale end), and nothing here invents stock, sales or reviews.
 */
defined( 'ABSPATH' ) || exit;

const HSM_BIS_CPT = 'hsm_bis';

add_action( 'init', function () {
	register_post_type( HSM_BIS_CPT, array( 'public' => false, 'show_ui' => false, 'rewrite' => false, 'query_var' => false, 'supports' => array( 'title' ) ) );
} );

function hsm_conv_defaults() {
	return array(
		'back_in_stock' => array( 'enabled' => false, 'title' => 'Notify me when back in stock' ),
		'qty_discount'  => array( 'enabled' => false, 'tiers' => array() ), // [{min_qty, percent}]
		'free_gift'     => array( 'enabled' => false, 'threshold' => 0, 'product_id' => 0, 'message' => '' ),
		'gift'          => array( 'enabled' => false, 'wrap_fee' => 0, 'message_label' => 'Gift message (optional)' ),
		'countdown'     => array( 'enabled' => false ),
		'recently_viewed' => array( 'enabled' => false, 'limit' => 4, 'title' => 'Recently viewed' ),
		'fbt'           => array( 'enabled' => false, 'limit' => 3, 'title' => 'Frequently bought together' ),
	);
}

function hsm_conv_settings() {
	$o = (array) get_option( 'hsm_conversion', array() );
	$s = array();
	foreach ( hsm_conv_defaults() as $k => $def ) {
		$s[ $k ] = wp_parse_args( is_array( $o[ $k ] ?? null ) ? $o[ $k ] : array(), $def );
	}
	return $s;
}

function hsm_conv_on( $k ) {
	return ! empty( hsm_conv_settings()[ $k ]['enabled'] );
}

/* --------------------------------------------------------------------- */
/* Back in stock                                                           */
/* --------------------------------------------------------------------- */

function hsm_conv_bis_form( $product_id ) {
	$s      = hsm_conv_settings()['back_in_stock'];
	$notice = get_transient( 'hsm_bis_notice_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	$out    = '<div class="hsm-bis"><strong>' . esc_html( $s['title'] ) . '</strong>';
	if ( $notice ) {
		$out .= '<p style="padding:8px;background:#eef7ee;border-radius:6px">' . esc_html( $notice ) . '</p>';
	}
	return $out . '<form method="post">'
		. wp_nonce_field( 'hsm_bis', '_hsm_nonce', true, false )
		. '<input type="hidden" name="hsm_action" value="bis"><input type="hidden" name="product_id" value="' . (int) $product_id . '">'
		. '<input type="hidden" name="hsm_back" value="' . esc_url( get_permalink() ) . '">'
		. '<p style="display:none"><label>Leave empty<input type="text" name="hsm_hp" value=""></label></p>'
		. '<input type="email" name="email" required placeholder="you@example.com" maxlength="120"> <button type="submit">Notify me</button></form></div>';
}

add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( ! $product || ! hsm_conv_on( 'back_in_stock' ) || $product->is_in_stock() ) {
		return;
	}
	echo wp_kses_post( hsm_conv_bis_form( $product->get_id() ) );
}, 35 );

/** Notify subscribers when a product comes back in stock. */
add_action( 'woocommerce_product_set_stock_status', function ( $product_id, $status ) {
	if ( 'instock' !== $status || ! hsm_conv_on( 'back_in_stock' ) ) {
		return;
	}
	$subs = get_posts( array( 'post_type' => HSM_BIS_CPT, 'post_parent' => $product_id, 'numberposts' => -1, 'post_status' => 'publish' ) );
	$p    = wc_get_product( $product_id );
	if ( ! $p ) {
		return;
	}
	foreach ( $subs as $s ) {
		$email = get_post_meta( $s->ID, '_hsm_email', true );
		if ( is_email( $email ) ) {
			wp_mail( $email, 'Back in stock: ' . $p->get_name(), $p->get_name() . " is available again:\n" . get_permalink( $product_id ) );
		}
		wp_delete_post( $s->ID, true );
	}
}, 10, 2 );

/* --------------------------------------------------------------------- */
/* Tiered quantity discount (clamped to the safety price floor)            */
/* --------------------------------------------------------------------- */

function hsm_conv_tier_percent( $qty ) {
	$best = 0.0;
	foreach ( (array) hsm_conv_settings()['qty_discount']['tiers'] as $t ) {
		$min = (int) ( $t['min_qty'] ?? 0 );
		$pct = (float) ( $t['percent'] ?? 0 );
		if ( $qty >= $min && $pct > $best ) {
			$best = $pct;
		}
	}
	return $best;
}

/** Lowest price the guards allow for a product (min_price, or cost + min_margin_percent). */
function hsm_conv_price_floor( $product_id ) {
	$floor = (float) hsm_guard( 'min_price' );
	$cost  = function_exists( 'hsm_product_cost' ) ? hsm_product_cost( $product_id ) : 0.0;
	if ( $cost > 0 ) {
		$floor = max( $floor, round( $cost * ( 1 + (float) hsm_guard( 'min_margin_percent' ) / 100 ), 2 ) );
	}
	return $floor;
}

add_action( 'woocommerce_before_calculate_totals', function ( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}
	if ( ! hsm_conv_on( 'qty_discount' ) || ! $cart instanceof WC_Cart ) {
		return;
	}
	// before_calculate_totals fires several times per request; remember each line's original price so
	// the discount is applied once, not compounded.
	static $orig = array();
	foreach ( $cart->get_cart() as $key => $item ) {
		if ( empty( $item['data'] ) ) {
			continue;
		}
		if ( ! isset( $orig[ $key ] ) ) {
			$orig[ $key ] = (float) $item['data']->get_price();
		}
		$base = $orig[ $key ];
		$pct  = hsm_conv_tier_percent( (int) $item['quantity'] );
		$new  = $base;
		if ( $pct > 0 ) {
			$new = round( $base * ( 1 - $pct / 100 ), wc_get_price_decimals() );
			$floor = hsm_conv_price_floor( $item['data']->get_id() );
			if ( $new < $floor ) {
				$new = $floor; // Never break the guard's price floor.
			}
		}
		$item['data']->set_price( $new );
	}
}, 20 );

/** Show the tier hint on the product page. */
add_action( 'woocommerce_single_product_summary', function () {
	if ( ! hsm_conv_on( 'qty_discount' ) ) {
		return;
	}
	$tiers = (array) hsm_conv_settings()['qty_discount']['tiers'];
	$rows  = array();
	foreach ( $tiers as $t ) {
		if ( (int) ( $t['min_qty'] ?? 0 ) > 0 && (float) ( $t['percent'] ?? 0 ) > 0 ) {
			$rows[] = sprintf( 'Buy %d+ → %s%% off', (int) $t['min_qty'], rtrim( rtrim( number_format( (float) $t['percent'], 2 ), '0' ), '.' ) );
		}
	}
	if ( $rows ) {
		echo '<p class="hsm-tier-hint" style="font-size:14px;color:#2e7d32">' . esc_html( implode( ' · ', $rows ) ) . '</p>';
	}
}, 31 );

/* --------------------------------------------------------------------- */
/* Free gift over a threshold                                              */
/* --------------------------------------------------------------------- */

add_action( 'woocommerce_check_cart_items', function () {
	static $running = false;
	if ( $running || ! hsm_conv_on( 'free_gift' ) || ! WC()->cart ) {
		return;
	}
	$s   = hsm_conv_settings()['free_gift'];
	$gid = (int) $s['product_id'];
	$thr = (float) $s['threshold'];
	if ( ! $gid || $thr <= 0 || ! wc_get_product( $gid ) ) {
		return;
	}
	$running = true;
	$in_cart = false;
	foreach ( WC()->cart->get_cart() as $item ) {
		if ( (int) $item['product_id'] === $gid ) {
			$in_cart = true;
			break;
		}
	}
	$eligible = (float) WC()->cart->get_subtotal() >= $thr;
	if ( $eligible && ! $in_cart ) {
		WC()->cart->add_to_cart( $gid, 1, 0, array(), array( 'hsm_free_gift' => true ) );
		if ( ! empty( $s['message'] ) ) {
			wc_add_notice( $s['message'], 'success' );
		}
	} elseif ( ! $eligible && $in_cart ) {
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( (int) $item['product_id'] === $gid && ! empty( $item['hsm_free_gift'] ) ) {
				WC()->cart->remove_cart_item( $key );
			}
		}
	}
	$running = false;
} );

/** The free gift line is free. */
add_action( 'woocommerce_before_calculate_totals', function ( $cart ) {
	if ( ! $cart instanceof WC_Cart ) {
		return;
	}
	foreach ( $cart->get_cart() as $item ) {
		if ( ! empty( $item['hsm_free_gift'] ) && ! empty( $item['data'] ) ) {
			$item['data']->set_price( 0 );
		}
	}
}, 30 );

/* --------------------------------------------------------------------- */
/* Gift message / gift wrap at checkout (classic checkout)                 */
/* --------------------------------------------------------------------- */

add_action( 'woocommerce_after_order_notes', function ( $checkout ) {
	if ( ! hsm_conv_on( 'gift' ) ) {
		return;
	}
	$s = hsm_conv_settings()['gift'];
	echo '<div class="hsm-gift"><h3>Gift options</h3>';
	woocommerce_form_field( 'hsm_gift_message', array( 'type' => 'textarea', 'label' => $s['message_label'], 'required' => false, 'maxlength' => 300 ), $checkout->get_value( 'hsm_gift_message' ) );
	if ( (float) $s['wrap_fee'] > 0 ) {
		woocommerce_form_field( 'hsm_gift_wrap', array( 'type' => 'checkbox', 'label' => 'Gift wrap (+' . wp_strip_all_tags( wc_price( $s['wrap_fee'] ) ) . ')' ), $checkout->get_value( 'hsm_gift_wrap' ) );
	}
	echo '</div>';
} );

add_action( 'woocommerce_cart_calculate_fees', function ( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}
	$s = hsm_conv_settings()['gift'];
	if ( empty( $s['enabled'] ) || (float) $s['wrap_fee'] <= 0 ) {
		return;
	}
	if ( ! empty( WC()->session ) && WC()->session->get( 'hsm_gift_wrap' ) ) {
		$cart->add_fee( 'Gift wrap', (float) $s['wrap_fee'] );
	}
} );

add_action( 'woocommerce_checkout_update_order_review', function ( $post ) {
	parse_str( $post, $data );
	if ( isset( $data['hsm_gift_wrap'] ) && WC()->session ) {
		WC()->session->set( 'hsm_gift_wrap', ! empty( $data['hsm_gift_wrap'] ) );
	}
} );

add_action( 'woocommerce_checkout_create_order', function ( $order, $data ) {
	if ( ! hsm_conv_on( 'gift' ) ) {
		return;
	}
	if ( ! empty( $data['hsm_gift_message'] ) ) {
		$order->update_meta_data( '_hsm_gift_message', sanitize_textarea_field( $data['hsm_gift_message'] ) );
	}
	if ( ! empty( $data['hsm_gift_wrap'] ) ) {
		$order->update_meta_data( '_hsm_gift_wrap', 'yes' );
	}
}, 10, 2 );

add_action( 'woocommerce_admin_order_data_after_shipping_address', function ( $order ) {
	$msg = $order->get_meta( '_hsm_gift_message' );
	if ( $msg ) {
		echo '<p><strong>Gift message:</strong><br>' . esc_html( $msg ) . '</p>';
	}
	if ( 'yes' === $order->get_meta( '_hsm_gift_wrap' ) ) {
		echo '<p><strong>Gift wrap:</strong> yes</p>';
	}
} );

/* --------------------------------------------------------------------- */
/* Countdown (real deadline only)                                          */
/* --------------------------------------------------------------------- */

add_shortcode( 'hsm_countdown', function ( $atts ) {
	if ( ! hsm_conv_on( 'countdown' ) ) {
		return '';
	}
	$atts  = shortcode_atts( array( 'until' => '', 'label' => 'Offer ends in' ), $atts );
	$until = trim( (string) $atts['until'] );
	if ( '' === $until ) {
		return '';
	}
	try {
		$when = date_create( $until, wp_timezone() );
	} catch ( Exception $e ) {
		return '';
	}
	if ( ! $when ) {
		return '';
	}
	$ts = $when->getTimestamp();
	if ( $ts <= time() ) {
		return '';
	}
	$id = 'hsm-cd-' . wp_rand( 1000, 9999 );
	$js = sprintf(
		'(function(){var t=%d*1000,el=document.getElementById("%s");if(!el)return;function u(){var d=t-Date.now();if(d<0){el.textContent="";return;}var s=Math.floor(d/1000),h=Math.floor(s/3600),m=Math.floor(s%%3600/60),x=s%%60;el.textContent=h+"h "+m+"m "+x+"s";}u();setInterval(u,1000);})();',
		$ts,
		$id
	);
	return '<span class="hsm-countdown">' . esc_html( $atts['label'] ) . ' <strong id="' . esc_attr( $id ) . '"></strong></span>'
		. '<script>' . $js . '</script>';
} );

/* --------------------------------------------------------------------- */
/* Recently viewed                                                         */
/* --------------------------------------------------------------------- */

add_action( 'template_redirect', function () {
	if ( ! hsm_conv_on( 'recently_viewed' ) || ! is_singular( 'product' ) ) {
		return;
	}
	$id   = get_queried_object_id();
	$list = isset( $_COOKIE['hsm_recent'] ) ? array_filter( array_map( 'absint', explode( ',', (string) $_COOKIE['hsm_recent'] ) ) ) : array();
	$list = array_diff( $list, array( $id ) );
	array_unshift( $list, $id );
	$limit = max( 1, (int) hsm_conv_settings()['recently_viewed']['limit'] );
	$list  = array_slice( array_values( $list ), 0, $limit + 1 );
	setcookie( 'hsm_recent', implode( ',', $list ), time() + MONTH_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
} );

add_shortcode( 'hsm_recently_viewed', function () {
	if ( ! hsm_conv_on( 'recently_viewed' ) ) {
		return '';
	}
	$s    = hsm_conv_settings()['recently_viewed'];
	$list = isset( $_COOKIE['hsm_recent'] ) ? array_filter( array_map( 'absint', explode( ',', (string) $_COOKIE['hsm_recent'] ) ) ) : array();
	$list = array_diff( $list, array( get_the_ID() ) );
	if ( ! $list ) {
		return '';
	}
	$out = '<div class="hsm-recent"><h3>' . esc_html( $s['title'] ) . '</h3><ul>';
	foreach ( array_slice( $list, 0, max( 1, (int) $s['limit'] ) ) as $id ) {
		$p = wc_get_product( $id );
		if ( $p ) {
			$out .= '<li><a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( $p->get_name() ) . '</a></li>';
		}
	}
	return $out . '</ul></div>';
} );

/* --------------------------------------------------------------------- */
/* Frequently bought together (from real orders)                           */
/* --------------------------------------------------------------------- */

function hsm_conv_fbt_ids( $product_id, $limit = 3 ) {
	global $wpdb;
	$product_id = (int) $product_id;
	$limit      = max( 1, min( 10, (int) $limit ) );
	// Order line items live in woocommerce_order_items / _itemmeta for BOTH HPOS and legacy CPT
	// storage (only the order record itself moves to wc_orders under HPOS), so this one query works
	// everywhere. The wc_order_product_lookup table is not reliably populated, so it is not used.
	$oi   = $wpdb->prefix . 'woocommerce_order_items';
	$oim  = $wpdb->prefix . 'woocommerce_order_itemmeta';
	$rows = $wpdb->get_col( $wpdb->prepare(
		"SELECT m2.meta_value FROM {$oi} i
		 JOIN {$oim} m ON m.order_item_id = i.order_item_id AND m.meta_key = '_product_id'
		 JOIN {$oi} i2 ON i2.order_id = i.order_id AND i2.order_item_id <> i.order_item_id AND i2.order_item_type = 'line_item'
		 JOIN {$oim} m2 ON m2.order_item_id = i2.order_item_id AND m2.meta_key = '_product_id'
		 WHERE i.order_item_type = 'line_item' AND m.meta_value = %d AND m2.meta_value <> %d
		 GROUP BY m2.meta_value ORDER BY COUNT(*) DESC LIMIT %d",
		$product_id,
		$product_id,
		$limit
	) );
	return array_map( 'intval', (array) $rows );
}

add_action( 'woocommerce_after_single_product_summary', function () {
	global $product;
	if ( ! $product || ! hsm_conv_on( 'fbt' ) ) {
		return;
	}
	$s    = hsm_conv_settings()['fbt'];
	$ids  = hsm_conv_fbt_ids( $product->get_id(), (int) $s['limit'] );
	$ids  = array_filter( $ids, 'wc_get_product' );
	if ( ! $ids ) {
		return;
	}
	echo '<section class="hsm-fbt"><h2>' . esc_html( $s['title'] ) . '</h2><ul>';
	foreach ( $ids as $id ) {
		$p = wc_get_product( $id );
		echo '<li><a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( $p->get_name() ) . '</a> — ' . wp_kses_post( wc_price( $p->get_price() ) ) . '</li>';
	}
	echo '</ul></section>';
}, 30 );

/* --------------------------------------------------------------------- */
/* Form handling (back in stock)                                           */
/* --------------------------------------------------------------------- */

add_action( 'init', function () {
	if ( empty( $_POST['hsm_action'] ) || 'bis' !== $_POST['hsm_action'] ) {
		return;
	}
	$ip_key = 'hsm_bis_notice_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
	$back   = home_url( '/' );
	$cand   = esc_url_raw( wp_unslash( $_POST['hsm_back'] ?? '' ) );
	if ( $cand && wp_parse_url( $cand, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
		$back = $cand;
	} elseif ( wp_get_referer() ) {
		$back = wp_get_referer();
	}
	$err = '';
	if ( ! empty( $_POST['hsm_hp'] ) || ! isset( $_POST['_hsm_nonce'] ) || ! wp_verify_nonce( (string) $_POST['_hsm_nonce'], 'hsm_bis' ) ) {
		$err = 'Submission rejected.';
	} elseif ( hsm_support_rate_exceeded() ) {
		$err = 'Too many submissions. Please try again later.';
	} else {
		$pid   = (int) ( $_POST['product_id'] ?? 0 );
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		if ( ! wc_get_product( $pid ) || ! is_email( $email ) ) {
			$err = 'Please enter a valid email.';
		} else {
			$id = wp_insert_post( array( 'post_type' => HSM_BIS_CPT, 'post_status' => 'publish', 'post_parent' => $pid, 'post_title' => $email ) );
			if ( $id ) {
				update_post_meta( $id, '_hsm_email', $email );
			}
			$err = 'Thanks — we will email you when it is back.';
		}
	}
	set_transient( $ip_key, $err, 60 );
	wp_safe_redirect( $back );
	exit;
} );

/* --------------------------------------------------------------------- */
/* MCP tool: hark-store/conversion                                         */
/* --------------------------------------------------------------------- */

function hsm_conv_actions() {
	return array( 'get_settings' => false, 'bis_list' => false, 'set_settings' => true, 'fbt_preview' => false );
}

hsm_ability( 'conversion', array(
	'label'       => 'Conversion & repeat purchase',
	'description' => 'action=get_settings | set_settings (merge a subset of: back_in_stock {enabled, title} | qty_discount {enabled, tiers:[{min_qty, percent}]} (the discounted price is clamped to the safety price floor) | free_gift {enabled, threshold, product_id, message} | gift {enabled, wrap_fee, message_label} (classic checkout) | countdown {enabled} | recently_viewed {enabled, limit, title} | fbt {enabled, limit, title}) | bis_list (product_id → back-in-stock subscribers) | fbt_preview (product_id → co-purchased products from real orders). Shortcodes: [hsm_countdown until="YYYY-MM-DD HH:MM"], [hsm_recently_viewed], [hsm_wishlist is in the marketing module].',
	'properties'  => array(
		'action'     => hsm_s( 'string', '', array( 'enum' => array_keys( hsm_conv_actions() ) ) ),
		'settings'   => hsm_s( 'object' ),
		'product_id' => hsm_s( 'integer' ),
	),
	'is_write'    => function ( $in ) {
		return ! empty( hsm_conv_actions()[ $in['action'] ?? '' ] );
	},
	'callback'    => function ( $in ) {
		$action = (string) ( $in['action'] ?? 'get_settings' );
		if ( ! array_key_exists( $action, hsm_conv_actions() ) ) {
			throw new Exception( 'Unknown action. Allowed: ' . implode( ', ', array_keys( hsm_conv_actions() ) ) );
		}
		if ( 'get_settings' === $action ) {
			return hsm_conv_settings();
		}
		if ( 'bis_list' === $action ) {
			$pid  = (int) ( $in['product_id'] ?? 0 );
			$subs = get_posts( array( 'post_type' => HSM_BIS_CPT, 'post_parent' => $pid, 'numberposts' => -1, 'post_status' => 'publish' ) );
			return array( 'product_id' => $pid, 'count' => count( $subs ), 'emails' => array_map( function ( $s ) { return get_post_meta( $s->ID, '_hsm_email', true ); }, $subs ) );
		}
		if ( 'fbt_preview' === $action ) {
			$pid = (int) ( $in['product_id'] ?? 0 );
			$ids = hsm_conv_fbt_ids( $pid, 5 );
			return array( 'product_id' => $pid, 'products' => array_map( function ( $id ) {
				$p = wc_get_product( $id );
				return $p ? array( 'id' => $id, 'name' => $p->get_name() ) : null;
			}, $ids ) );
		}
		$incoming = (array) ( $in['settings'] ?? array() );
		if ( ! $incoming ) {
			throw new Exception( 'settings must be a non-empty object.' );
		}
		$before = get_option( 'hsm_conversion', array() );
		$new    = $before;
		foreach ( $incoming as $key => $val ) {
			if ( ! isset( hsm_conv_defaults()[ $key ] ) ) {
				throw new Exception( 'Unknown settings key: ' . $key );
			}
			$new[ $key ] = hsm_conv_sanitize( $key, $val );
		}
		hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_conversion', 'old' => $before ) );
		update_option( 'hsm_conversion', $new );
		return array( 'action' => 'set_settings', 'changed' => array_keys( $incoming ), 'settings' => hsm_conv_settings() );
	},
) );

function hsm_conv_sanitize( $key, $val ) {
	$val = is_array( $val ) ? $val : array();
	$def = hsm_conv_defaults()[ $key ];
	$out = array();
	foreach ( $def as $k => $d ) {
		if ( ! array_key_exists( $k, $val ) ) {
			$out[ $k ] = $d;
			continue;
		}
		if ( is_bool( $d ) ) {
			$out[ $k ] = (bool) $val[ $k ];
		} elseif ( is_int( $d ) ) {
			$out[ $k ] = max( 0, (int) $val[ $k ] );
		} elseif ( is_float( $d ) ) {
			$out[ $k ] = max( 0, (float) $val[ $k ] );
		} elseif ( 'tiers' === $k ) {
			$out[ $k ] = array();
			foreach ( (array) $val[ $k ] as $t ) {
				$out[ $k ][] = array( 'min_qty' => max( 1, (int) ( $t['min_qty'] ?? 1 ) ), 'percent' => max( 0, min( 90, (float) ( $t['percent'] ?? 0 ) ) ) );
			}
		} else {
			$out[ $k ] = sanitize_text_field( (string) $val[ $k ] );
		}
	}
	return $out;
}
