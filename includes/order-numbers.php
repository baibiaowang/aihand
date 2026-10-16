<?php
/**
 * v1.8 Sequential order numbers (replaces "Sequential Order Numbers" style plugins) — OFF by default.
 *
 * Each new order gets a human-friendly number (prefix + counter + optional zero padding) stored in the
 * order meta _hsm_order_number and shown everywhere WooCommerce shows an order number. The counter is
 * advanced with a single atomic UPDATE, so two simultaneous checkouts never get the same number.
 * The custom number is also added to the admin order search (both HPOS and legacy CPT storage).
 */
defined( 'ABSPATH' ) || exit;

function hsm_order_numbers_settings() {
	$s = wp_parse_args( (array) get_option( 'hsm_order_numbers', array() ), array(
		'enabled' => false,
		'prefix'  => '',
		'start'   => 1000,
		'padding' => 0,
	) );
	$s['enabled'] = (bool) $s['enabled'];
	$s['prefix']  = (string) $s['prefix'];
	$s['start']   = max( 1, (int) $s['start'] );
	$s['padding'] = max( 0, min( 20, (int) $s['padding'] ) );
	return $s;
}

/** The stored custom number for an order ('' when none). */
function hsm_order_number_get( $order ) {
	$o = $order instanceof WC_Order ? $order : wc_get_order( (int) $order );
	return $o ? (string) $o->get_meta( '_hsm_order_number' ) : '';
}

/** Reserve the next counter value atomically. Returns the integer. */
function hsm_order_number_reserve() {
	global $wpdb;
	$s   = hsm_order_numbers_settings();
	$opt = 'hsm_order_number_counter';
	// The option holds the last value handed out; seed it one below the configured start.
	add_option( $opt, $s['start'] - 1, '', false );
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID( CAST( option_value AS UNSIGNED ) + 1 ) WHERE option_name = %s",
		$opt
	) );
	return (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
}

/** Assign a number to an order if it does not have one yet and the module is on. Returns the number. */
function hsm_order_number_assign( $order ) {
	$o = $order instanceof WC_Order ? $order : wc_get_order( (int) $order );
	if ( ! $o ) {
		return '';
	}
	$existing = hsm_order_number_get( $o );
	if ( '' !== $existing ) {
		return $existing;
	}
	$s = hsm_order_numbers_settings();
	if ( ! $s['enabled'] ) {
		return '';
	}
	$n = hsm_order_number_reserve();
	$n = $s['prefix'] . ( $s['padding'] > 0 ? str_pad( (string) $n, $s['padding'], '0', STR_PAD_LEFT ) : (string) $n );
	$o->update_meta_data( '_hsm_order_number', $n );
	$o->save();
	return $n;
}

if ( ! function_exists( 'hsm_order_numbers_boot' ) ) {
	function hsm_order_numbers_boot() {
		if ( ! hsm_order_numbers_settings()['enabled'] ) {
			return;
		}
		add_filter( 'woocommerce_order_number', function ( $number, $order ) {
			$n = hsm_order_number_get( $order );
			return '' !== $n ? $n : $number;
		}, 10, 2 );

		add_action( 'woocommerce_new_order', function ( $order_id ) {
			hsm_order_number_assign( $order_id );
		}, 10, 1 );

		$add = function ( $fields ) {
			$fields   = (array) $fields;
			$fields[] = '_hsm_order_number';
			return $fields;
		};
		add_filter( 'woocommerce_shop_order_search_fields', $add );            // legacy CPT storage
		add_filter( 'woocommerce_order_table_search_query_meta_keys', $add );  // HPOS
	}
}
add_action( 'woocommerce_init', 'hsm_order_numbers_boot' );

/* --------------------------------------------------------------------- */
/* MCP tool: hark-store/order-numbers                                      */
/* --------------------------------------------------------------------- */

function hsm_order_numbers_actions() {
	return array( 'status' => false, 'get_settings' => false, 'set_settings' => true, 'backfill' => true );
}

hsm_ability( 'order-numbers', array(
	'label'       => 'Sequential order numbers',
	'description' => 'Give orders a human-friendly sequential number (prefix + counter + zero padding) shown everywhere WooCommerce shows an order number, and searchable in the admin. action=status (settings, next number, how many orders already have one) | get_settings | set_settings (enabled, prefix, start, padding) | backfill (assign numbers to existing orders that have none, oldest first). Changing start does not renumber existing orders.',
	'properties'  => array(
		'action'  => hsm_s( 'string', '', array( 'enum' => array_keys( hsm_order_numbers_actions() ) ) ),
		'enabled' => hsm_s( 'boolean' ),
		'prefix'  => hsm_s( 'string' ),
		'start'   => hsm_s( 'integer', 'Counter value for the first numbered order (only used when the counter is unset).' ),
		'padding' => hsm_s( 'integer', 'Zero-pad the counter to this width (0 = no padding).' ),
		'limit'   => hsm_s( 'integer', 'backfill: max orders to number in this call (default 500).' ),
	),
	'is_write'    => function ( $in ) {
		return ! empty( hsm_order_numbers_actions()[ $in['action'] ?? '' ] );
	},
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$action = (string) ( $in['action'] ?? 'status' );
		if ( ! array_key_exists( $action, hsm_order_numbers_actions() ) ) {
			throw new Exception( 'Unknown action. Allowed: ' . implode( ', ', array_keys( hsm_order_numbers_actions() ) ) );
		}
		if ( 'get_settings' === $action ) {
			return hsm_order_numbers_settings();
		}
		if ( 'set_settings' === $action ) {
			$before = get_option( 'hsm_order_numbers', array() );
			$new    = $before;
			foreach ( array( 'enabled', 'prefix', 'start', 'padding' ) as $k ) {
				if ( array_key_exists( $k, $in ) ) {
					$new[ $k ] = $in[ $k ];
				}
			}
			hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_order_numbers', 'old' => $before ) );
			update_option( 'hsm_order_numbers', $new );
			return array( 'action' => 'set_settings', 'settings' => hsm_order_numbers_settings() );
		}
		if ( 'backfill' === $action ) {
			$s = hsm_order_numbers_settings();
			if ( ! $s['enabled'] ) {
				throw new Exception( 'Enable the module first (action=set_settings enabled=true).' );
			}
			$limit = max( 1, min( 5000, (int) ( $in['limit'] ?? 500 ) ) );
			$orders = wc_get_orders( array(
				'limit'   => $limit,
				'orderby' => 'date',
				'order'   => 'ASC',
				'type'    => 'shop_order',
				'meta_query' => array( array( 'key' => '_hsm_order_number', 'compare' => 'NOT EXISTS' ) ),
			) );
			$n = 0;
			foreach ( $orders as $o ) {
				hsm_order_number_assign( $o );
				$n++;
			}
			return array( 'action' => 'backfill', 'assigned' => $n, 'remaining_maybe' => count( $orders ) >= $limit );
		}
		// status
		global $wpdb;
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$meta_table = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$with = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$meta_table} WHERE meta_key = %s", '_hsm_order_number' ) );
		return array(
			'settings'      => hsm_order_numbers_settings(),
			'counter'       => (int) get_option( 'hsm_order_number_counter', hsm_order_numbers_settings()['start'] - 1 ),
			'orders_numbered' => $with,
		);
	},
) );
