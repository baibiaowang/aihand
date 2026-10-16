<?php
/**
 * v1.8 Post-purchase recommendations — OFF by default.
 *
 * After checkout (the thank-you page) shows a few products the shopper did not buy, taken from real
 * store data: what other customers bought together with these items, WooCommerce's own related
 * products, or the store's best sellers. Nothing is invented.
 */
defined( 'ABSPATH' ) || exit;

function hsm_pp_defaults() {
	return array(
		'enabled' => false,
		'title'   => 'You might also like',
		'source'  => 'fbt',   // fbt | related | bestsellers
		'count'   => 4,
		'columns' => 4,
	);
}

function hsm_pp_settings() {
	$s = wp_parse_args( (array) get_option( 'hsm_post_purchase', array() ), hsm_pp_defaults() );
	$s['enabled'] = (bool) $s['enabled'];
	$s['source']  = in_array( $s['source'], array( 'fbt', 'related', 'bestsellers' ), true ) ? $s['source'] : 'fbt';
	$s['count']   = max( 1, min( 8, (int) $s['count'] ) );
	$s['columns'] = max( 1, min( 4, (int) $s['columns'] ) );
	return $s;
}

/** Recommended product ids for an order (excluding what was already bought). */
function hsm_pp_recommend( $order, $limit ) {
	$bought = array();
	foreach ( $order->get_items() as $item ) {
		if ( $item->get_product_id() ) {
			$bought[] = (int) $item->get_product_id();
		}
		if ( $item->get_variation_id() ) {
			$bought[] = (int) $item->get_variation_id();
		}
	}
	$s      = hsm_pp_settings();
	$ids    = array();
	if ( 'fbt' === $s['source'] && function_exists( 'hsm_conv_fbt_ids' ) ) {
		foreach ( $bought as $pid ) {
			foreach ( hsm_conv_fbt_ids( $pid, $limit ) as $id ) {
				$ids[] = (int) $id;
			}
		}
	} elseif ( 'related' === $s['source'] ) {
		foreach ( $bought as $pid ) {
			foreach ( wc_get_related_products( $pid, $limit ) as $id ) {
				$ids[] = (int) $id;
			}
		}
	}
	$ids = array_values( array_unique( array_diff( $ids, $bought ) ) );
	// Drop anything that no longer exists or is not visible before deciding on the fallback.
	$valid = function ( $id ) {
		$p = wc_get_product( (int) $id );
		return $p && 'publish' === $p->get_status() && $p->is_visible();
	};
	$ids = array_values( array_filter( $ids, $valid ) );
	if ( ! $ids ) {
		$best = wc_get_products( array( 'limit' => $limit * 2, 'status' => 'publish', 'orderby' => 'popularity', 'order' => 'DESC' ) );
		foreach ( $best as $p ) {
			if ( $valid( $p->get_id() ) && ! in_array( $p->get_id(), $bought, true ) ) {
				$ids[] = $p->get_id();
			}
		}
	}
	return array_slice( $ids, 0, $limit );
}

function hsm_pp_render( $order ) {
	$s = hsm_pp_settings();
	if ( empty( $s['enabled'] ) || ! $order instanceof WC_Order ) {
		return '';
	}
	$ids = hsm_pp_recommend( $order, (int) $s['count'] );
	if ( ! $ids ) {
		return '';
	}
	$out = '<section class="hsm-post-purchase" style="margin:28px 0"><h2>' . esc_html( $s['title'] ) . '</h2>'
		. '<ul class="products columns-' . (int) $s['columns'] . '" style="display:grid;grid-template-columns:repeat(' . (int) $s['columns'] . ',1fr);gap:16px;list-style:none;padding:0">';
	foreach ( $ids as $id ) {
		$p = wc_get_product( $id );
		if ( ! $p || ! $p->is_visible() ) {
			continue;
		}
		$out .= '<li style="text-align:center">'
			. '<a href="' . esc_url( get_permalink( $id ) ) . '">' . ( $p->get_image_id() ? wp_get_attachment_image( $p->get_image_id(), 'woocommerce_thumbnail', false, array( 'style' => 'max-width:100%;height:auto' ) ) : '' ) . '</a>'
			. '<div><a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( $p->get_name() ) . '</a></div>'
			. '<div>' . wp_kses_post( $p->get_price_html() ) . '</div>'
			. '<a class="button" href="' . esc_url( add_query_arg( 'add-to-cart', $id, get_permalink( $id ) ) ) . '">Add to cart</a>'
			. '</li>';
	}
	return $out . '</ul></section>';
}

add_action( 'woocommerce_thankyou', function ( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( $order ) {
		echo wp_kses_post( hsm_pp_render( $order ) );
	}
}, 20 );

/* --------------------------------------------------------------------- */
/* MCP tool: hark-store/post-purchase                                      */
/* --------------------------------------------------------------------- */

hsm_ability( 'post-purchase', array(
	'label'       => 'Post-purchase recommendations',
	'description' => 'Show recommended products on the thank-you page after checkout. action=get_settings | set_settings (enabled, title, source: fbt (what customers bought together with these items) | related (WooCommerce related products) | bestsellers, count 1-8, columns 1-4) | preview (order_id → the products that would be shown). Products already in the order are excluded; if there is no data it falls back to best sellers.',
	'properties'  => array(
		'action'   => hsm_s( 'string', '', array( 'enum' => array( 'get_settings', 'set_settings', 'preview' ) ) ),
		'settings' => hsm_s( 'object' ),
		'order_id' => hsm_s( 'integer' ),
	),
	'is_write'    => function ( $in ) {
		return 'set_settings' === ( $in['action'] ?? '' );
	},
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$action = (string) ( $in['action'] ?? 'get_settings' );
		if ( ! in_array( $action, array( 'get_settings', 'set_settings', 'preview' ), true ) ) {
			throw new Exception( 'Unknown action.' );
		}
		if ( 'get_settings' === $action ) {
			return hsm_pp_settings();
		}
		if ( 'preview' === $action ) {
			$o = wc_get_order( (int) ( $in['order_id'] ?? 0 ) );
			if ( ! $o ) {
				throw new Exception( 'Order not found.' );
			}
			$ids = hsm_pp_recommend( $o, hsm_pp_settings()['count'] );
			return array( 'order_id' => $o->get_id(), 'products' => array_map( function ( $id ) {
				$p = wc_get_product( $id );
				return $p ? array( 'id' => $id, 'name' => $p->get_name() ) : null;
			}, $ids ) );
		}
		$incoming = (array) ( $in['settings'] ?? array() );
		if ( ! $incoming ) {
			throw new Exception( 'settings must be a non-empty object.' );
		}
		$before = get_option( 'hsm_post_purchase', array() );
		$new    = $before;
		foreach ( array( 'enabled', 'title', 'source', 'count', 'columns' ) as $k ) {
			if ( array_key_exists( $k, $incoming ) ) {
				$new[ $k ] = $incoming[ $k ];
			}
		}
		hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_post_purchase', 'old' => $before ) );
		update_option( 'hsm_post_purchase', $new );
		return array( 'action' => 'set_settings', 'settings' => hsm_pp_settings() );
	},
) );
