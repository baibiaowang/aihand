<?php
/**
 * v1.8 Shipping rules — OFF by default.
 *
 * Conditional shipping adjustments on top of the store's own methods: free shipping over an amount,
 * and surcharges/blocking by destination state, ZIP prefix, cart subtotal or weight. Applied to the
 * calculated rates, so taxes and totals stay consistent.
 */
defined( 'ABSPATH' ) || exit;

function hsm_ship_rules_defaults() {
	return array(
		'enabled'   => false,
		'free_over' => array( 'enabled' => false, 'amount' => 0, 'label' => 'Free shipping' ),
		'rules'     => array(), // [{when:'state'|'zip_prefix'|'subtotal_under'|'subtotal_over'|'weight_over', value, add_fee, message}]
	);
}

function hsm_ship_rules_settings() {
	$s = wp_parse_args( (array) get_option( 'hsm_shipping_rules', array() ), hsm_ship_rules_defaults() );
	$s['enabled']   = (bool) $s['enabled'];
	$s['free_over'] = wp_parse_args( is_array( $s['free_over'] ?? null ) ? $s['free_over'] : array(), hsm_ship_rules_defaults()['free_over'] );
	$s['rules']     = is_array( $s['rules'] ?? null ) ? $s['rules'] : array();
	return $s;
}

/** Surcharge and free-shipping decision for a package. */
function hsm_ship_rules_evaluate( $package ) {
	$s        = hsm_ship_rules_settings();
	$subtotal = ( function_exists( 'WC' ) && WC()->cart ) ? (float) WC()->cart->get_subtotal() : 0.0;
	$weight   = 0.0;
	foreach ( (array) ( $package['contents'] ?? array() ) as $item ) {
		$p = $item['data'] ?? null;
		if ( $p && $p->get_weight() ) {
			$weight += (float) $p->get_weight() * (int) ( $item['quantity'] ?? 1 );
		}
	}
	$dest  = strtoupper( (string) ( $package['destination']['state'] ?? '' ) );
	$zip   = preg_replace( '/[^0-9A-Za-z]/', '', (string) ( $package['destination']['postcode'] ?? '' ) );
	$fee   = 0.0;
	$free  = false;
	$notes = array();
	foreach ( (array) $s['rules'] as $r ) {
		$when  = (string) ( $r['when'] ?? '' );
		$value = (string) ( $r['value'] ?? '' );
		$hit   = false;
		switch ( $when ) {
			case 'state':
				$hit = ( '' !== $dest && $dest === strtoupper( preg_replace( '/[^A-Za-z]/', '', $value ) ) );
				break;
			case 'zip_prefix':
				$pre = preg_replace( '/[^0-9A-Za-z]/', '', $value );
				$hit = ( '' !== $pre && 0 === stripos( $zip, $pre ) );
				break;
			case 'subtotal_under':
				$hit = $subtotal < (float) $value;
				break;
			case 'subtotal_over':
				$hit = $subtotal >= (float) $value;
				break;
			case 'weight_over':
				$hit = $weight > (float) $value;
				break;
		}
		if ( $hit ) {
			$fee += (float) ( $r['add_fee'] ?? 0 );
			if ( ! empty( $r['message'] ) ) {
				$notes[] = (string) $r['message'];
			}
		}
	}
	if ( ! empty( $s['free_over']['enabled'] ) && (float) $s['free_over']['amount'] > 0 && $subtotal >= (float) $s['free_over']['amount'] ) {
		$free = true;
	}
	return array( 'fee' => round( $fee, 2 ), 'free' => $free, 'notes' => $notes, 'label' => $s['free_over']['label'] );
}

add_filter( 'woocommerce_package_rates', function ( $rates, $package ) {
	if ( empty( hsm_ship_rules_settings()['enabled'] ) || ! is_array( $rates ) ) {
		return $rates;
	}
	$ev = hsm_ship_rules_evaluate( $package );
	foreach ( $rates as $rate ) {
		if ( ! $rate instanceof WC_Shipping_Rate ) {
			continue;
		}
		$cost = (float) $rate->get_cost();
		if ( $ev['free'] ) {
			$cost = 0;
			$rate->set_label( $rate->get_label() . ' — ' . $ev['label'] );
		} elseif ( $ev['fee'] > 0 ) {
			$cost = round( $cost + $ev['fee'], 2 );
		}
		$rate->set_cost( $cost );
		$rate->set_taxes( array() ); // let Woo recalculate
	}
	return $rates;
}, 10, 2 );

/** Show any rule messages on the cart. */
add_action( 'woocommerce_before_cart', function () {
	if ( empty( hsm_ship_rules_settings()['enabled'] ) || ! WC()->cart ) {
		return;
	}
	$package = array( 'destination' => array(
		'state'    => WC()->customer ? WC()->customer->get_shipping_state() : '',
		'postcode' => WC()->customer ? WC()->customer->get_shipping_postcode() : '',
	) );
	$ev = hsm_ship_rules_evaluate( $package );
	if ( $ev['free'] ) {
		wc_add_notice( 'You qualify for free shipping!', 'success' );
	}
	foreach ( $ev['notes'] as $n ) {
		wc_add_notice( $n, 'notice' );
	}
}, 6 );

/* --------------------------------------------------------------------- */
/* MCP tool: hark-store/shipping-rules                                     */
/* --------------------------------------------------------------------- */

hsm_ability( 'shipping-rules', array(
	'label'       => 'Conditional shipping rules',
	'description' => 'Adjust the store\'s shipping rates by condition. action=get_settings | set_settings (enabled, free_over {enabled, amount, label}, rules:[{when: state|zip_prefix|subtotal_under|subtotal_over|weight_over, value, add_fee, message}]). free_over zeroes shipping when the cart subtotal reaches the amount; each matching rule adds add_fee (negative not allowed) and can show a message. Applied on top of the store\'s own shipping methods.',
	'properties'  => array(
		'action'   => hsm_s( 'string', '', array( 'enum' => array( 'get_settings', 'set_settings' ) ) ),
		'settings' => hsm_s( 'object' ),
	),
	'is_write'    => function ( $in ) {
		return 'set_settings' === ( $in['action'] ?? 'get_settings' );
	},
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$action = (string) ( $in['action'] ?? 'get_settings' );
		if ( 'get_settings' === $action ) {
			return hsm_ship_rules_settings();
		}
		if ( 'set_settings' !== $action ) {
			throw new Exception( 'Unknown action. Allowed: get_settings, set_settings.' );
		}
		$incoming = (array) ( $in['settings'] ?? array() );
		if ( ! $incoming ) {
			throw new Exception( 'settings must be a non-empty object.' );
		}
		$before = get_option( 'hsm_shipping_rules', array() );
		$new    = $before;
		if ( array_key_exists( 'enabled', $incoming ) ) {
			$new['enabled'] = (bool) $incoming['enabled'];
		}
		if ( array_key_exists( 'free_over', $incoming ) ) {
			$fo = (array) $incoming['free_over'];
			$new['free_over'] = array(
				'enabled' => (bool) ( $fo['enabled'] ?? false ),
				'amount'  => max( 0, (float) ( $fo['amount'] ?? 0 ) ),
				'label'   => sanitize_text_field( (string) ( $fo['label'] ?? 'Free shipping' ) ),
			);
		}
		if ( array_key_exists( 'rules', $incoming ) ) {
			$clean = array();
			foreach ( (array) $incoming['rules'] as $r ) {
				$when = (string) ( $r['when'] ?? '' );
				if ( ! in_array( $when, array( 'state', 'zip_prefix', 'subtotal_under', 'subtotal_over', 'weight_over' ), true ) ) {
					throw new Exception( 'Unknown rule condition: ' . $when );
				}
				$clean[] = array(
					'when'    => $when,
					'value'   => sanitize_text_field( (string) ( $r['value'] ?? '' ) ),
					'add_fee' => max( 0, (float) ( $r['add_fee'] ?? 0 ) ),
					'message' => sanitize_text_field( (string) ( $r['message'] ?? '' ) ),
				);
			}
			$new['rules'] = $clean;
		}
		hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_shipping_rules', 'old' => $before ) );
		update_option( 'hsm_shipping_rules', $new );
		return array( 'action' => 'set_settings', 'settings' => hsm_ship_rules_settings() );
	},
) );
