<?php
/**
 * v1.8 Shipment tracking (replaces "Shipment Tracking" style plugins) — OFF by default.
 *
 * Per order, a list of shipments: carrier (USPS/UPS/FedEx/DHL or an owner-defined custom carrier),
 * tracking number and a tracking URL. The URL is derived from the carrier's template when not given.
 * Shown on the customer's "My Account" order page and in customer emails (the completed email and
 * every later one), only when the module is enabled.
 *
 * The AI agent manages the data through hark-store/shipments (list, add, remove, import, settings).
 * Custom carriers and the on/off switches are whitelisted keys with validation — an agent can never
 * put arbitrary HTML on the storefront: the tracking URL is always http/https, the label is plain text.
 */
defined( 'ABSPATH' ) || exit;

function hsm_shipments_settings() {
	$s = wp_parse_args( (array) get_option( 'hsm_shipments', array() ), array(
		'enabled'         => false,
		'notify_email'    => true,
		'show_account'    => true,
		'default_carrier' => 'usps',
		'custom_carriers' => array(),
	) );
	$s['enabled']      = (bool) $s['enabled'];
	$s['notify_email'] = (bool) $s['notify_email'];
	$s['show_account'] = (bool) $s['show_account'];
	$s['default_carrier'] = sanitize_key( (string) $s['default_carrier'] );
	$s['custom_carriers'] = is_array( $s['custom_carriers'] ) ? $s['custom_carriers'] : array();
	return apply_filters( 'hsm_shipments_settings', $s );
}

/**
 * Clean an owner-entered URL template, keeping the {number} placeholder.
 * esc_url_raw() strips curly braces, so the placeholder is swapped for a letters-only
 * token before sanitizing and restored afterwards.
 */
function hsm_shipment_clean_template( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	if ( ! preg_match( '#^https?://#i', $url ) ) {
		throw new Exception( 'Tracking URL must start with http:// or https://' );
	}
	$token = 'hsmnumbertoken';
	$safe  = str_replace( array( '{number}', '%number%' ), $token, $url );
	$safe  = esc_url_raw( $safe, array( 'http', 'https' ) );
	return str_replace( $token, '{number}', $safe );
}

/** Built-in carrier whitelist + owner-defined custom carriers. slug => {label, url}. */
function hsm_shipment_carriers() {	$builtin = array(
		'usps'  => array( 'label' => 'USPS',  'url' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels={number}' ),
		'ups'   => array( 'label' => 'UPS',   'url' => 'https://www.ups.com/track?loc=en_US&tracknum={number}' ),
		'fedex' => array( 'label' => 'FedEx', 'url' => 'https://www.fedex.com/fedextrack/?trknbr={number}' ),
		'dhl'   => array( 'label' => 'DHL',   'url' => 'https://www.dhl.com/global-en/home/tracking.html?tracking-id={number}' ),
		'other' => array( 'label' => 'Other', 'url' => '' ),
	);
	$custom = array();
	foreach ( hsm_shipments_settings()['custom_carriers'] as $slug => $c ) {
		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug || empty( $c['label'] ) ) {
			continue;
		}
		$custom[ $slug ] = array(
			'label' => sanitize_text_field( (string) $c['label'] ),
			'url'   => ( function ( $u ) {
				try {
					return hsm_shipment_clean_template( $u );
				} catch ( Exception $e ) {
					return '';
				}
			} )( $c['url'] ?? '' ),
		);
	}
	return apply_filters( 'hsm_shipment_carriers', array_merge( $builtin, $custom ) );
}

/** Is a manual tracking URL safe to show a customer? Only known carrier hosts (or this store). */
function hsm_tracking_url_ok( $url ) {
	$u = wp_parse_url( (string) $url );
	if ( ! $u || empty( $u['host'] ) || ! in_array( strtolower( $u['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) {
		return false;
	}
	$host = strtolower( $u['host'] );
	$home = wp_parse_url( home_url() );
	if ( $home && ! empty( $home['host'] ) && strtolower( $home['host'] ) === $host ) {
		return true;
	}
	$allowed = array();
	foreach ( hsm_shipment_carriers() as $c ) {
		$h = wp_parse_url( (string) ( $c['url'] ?? '' ), PHP_URL_HOST );
		if ( $h ) {
			$allowed[] = strtolower( $h );
		}
	}
	$allowed = (array) apply_filters( 'hsm_tracking_url_allowed_hosts', $allowed, $url );
	foreach ( $allowed as $h ) {
		$h = strtolower( (string) $h );
		if ( '' !== $h && ( $host === $h || substr( $host, -strlen( '.' . $h ) ) === '.' . $h ) ) {
			return true;
		}
	}
	return false;
}

/** Build a tracking URL from a carrier template (or an explicit override). '' when no template. */
function hsm_shipment_url( $carrier, $number, $override = '' ) {
	$carriers = hsm_shipment_carriers();
	if ( '' !== (string) $override ) {
		$tpl = esc_url_raw( (string) $override, array( 'http', 'https' ) );
	} else {
		$tpl = $carriers[ $carrier ]['url'] ?? '';
	}
	if ( '' === $tpl ) {
		return '';
	}
	return str_replace( array( '{number}', '%number%' ), rawurlencode( (string) $number ), $tpl );
}

/** Normalized shipment list for an order. Legacy single {carrier,number,url} is upgraded to a list. */
function hsm_shipments_get( $order ) {
	$o = $order instanceof WC_Order ? $order : wc_get_order( (int) $order );
	if ( ! $o ) {
		return array();
	}
	$raw = $o->get_meta( '_hsm_tracking' );
	if ( empty( $raw ) || ! is_array( $raw ) ) {
		return array();
	}
	if ( isset( $raw['number'] ) ) {
		$raw = array( $raw );
	}
	$carriers = hsm_shipment_carriers();
	$out      = array();
	foreach ( $raw as $t ) {
		if ( ! is_array( $t ) || empty( $t['number'] ) ) {
			continue;
		}
		$carrier = sanitize_key( (string) ( $t['carrier'] ?? '' ) );
		$number  = (string) $t['number'];
		$url     = (string) ( $t['url'] ?? '' );
		$out[]   = array(
			'carrier' => $carrier,
			'label'   => $carriers[ $carrier ]['label'] ?? ( '' !== $carrier ? ucfirst( $carrier ) : 'Carrier' ),
			'number'  => $number,
			'url'     => '' !== $url ? esc_url_raw( $url, array( 'http', 'https' ) ) : hsm_shipment_url( $carrier, $number ),
			'date'    => (string) ( $t['date'] ?? '' ),
		);
	}
	return $out;
}

/** Replace the whole shipment list. */
function hsm_shipments_set( $order, $list ) {
	$o = $order instanceof WC_Order ? $order : wc_get_order( (int) $order );
	if ( ! $o ) {
		return false;
	}
	$o->update_meta_data( '_hsm_tracking', array_values( $list ) );
	$o->save();
	return true;
}

/** Append one shipment. Returns the stored row. */
function hsm_shipments_add( $order, $carrier, $number, $url = '', $date = '' ) {
	$o = $order instanceof WC_Order ? $order : wc_get_order( (int) $order );
	if ( ! $o ) {
		throw new Exception( 'Order not found.' );
	}
	$carriers = hsm_shipment_carriers();
	$carrier  = sanitize_key( (string) $carrier );
	if ( '' !== $carrier && ! isset( $carriers[ $carrier ] ) ) {
		throw new Exception( 'Unknown carrier "' . $carrier . '". Known: ' . implode( ', ', array_keys( $carriers ) ) . '. Add a custom carrier via settings.custom_carriers.' );
	}
	if ( '' === $carrier ) {
		$carrier = hsm_shipments_settings()['default_carrier'];
	}
	$number = trim( (string) $number );
	if ( '' === $number ) {
		throw new Exception( 'Tracking number is required.' );
	}
	if ( mb_strlen( $number ) > 64 ) {
		throw new Exception( 'Tracking number is too long (max 64).' );
	}
	if ( '' !== (string) $url && ! preg_match( '#^https?://#i', (string) $url ) ) {
		throw new Exception( 'Tracking URL must start with http:// or https://' );
	}
	$list   = hsm_shipments_get( $o );
	$list[] = array(
		'carrier' => $carrier,
		'number'  => $number,
		'url'     => esc_url_raw( (string) $url, array( 'http', 'https' ) ),
		'date'    => '' !== $date ? $date : wp_date( 'Y-m-d H:i' ),
	);
	hsm_shipments_set( $o, $list );
	return end( $list );
}

/** Remove shipments matching carrier and/or number. Returns how many were removed. */
function hsm_shipments_remove( $order, $number = '', $carrier = '' ) {
	$o = $order instanceof WC_Order ? $order : wc_get_order( (int) $order );
	if ( ! $o ) {
		throw new Exception( 'Order not found.' );
	}
	$number  = trim( (string) $number );
	$carrier = sanitize_key( (string) $carrier );
	if ( '' === $number && '' === $carrier ) {
		throw new Exception( 'Give a tracking number and/or carrier to remove.' );
	}
	$kept = array();
	$gone = 0;
	foreach ( hsm_shipments_get( $o ) as $t ) {
		$hit = ( '' === $number || $t['number'] === $number ) && ( '' === $carrier || $t['carrier'] === $carrier );
		if ( $hit ) {
			$gone++;
		} else {
			$kept[] = $t;
		}
	}
	hsm_shipments_set( $o, $kept );
	return $gone;
}

/** Front-end rendering block (HTML or plain text). */
function hsm_shipments_render( $order, $plain = false ) {
	$rows = hsm_shipments_get( $order );
	if ( ! $rows ) {
		return '';
	}
	if ( $plain ) {
		$out = "\n" . __( 'Shipment tracking', 'hark-store-mcp' ) . ":\n";
		foreach ( $rows as $t ) {
			$out .= '- ' . $t['label'] . ': ' . $t['number'] . ( $t['url'] ? ' — ' . $t['url'] : '' ) . "\n";
		}
		return $out;
	}
	$html = '<section class="hsm-tracking"><h2>' . esc_html__( 'Shipment tracking', 'hark-store-mcp' ) . '</h2><ul>';
	foreach ( $rows as $t ) {
		$html .= '<li><strong>' . esc_html( $t['label'] ) . ':</strong> ';
		$html .= $t['url']
			? '<a href="' . esc_url( $t['url'] ) . '" target="_blank" rel="noopener nofollow">' . esc_html( $t['number'] ) . '</a>'
			: esc_html( $t['number'] );
		$html .= '</li>';
	}
	return $html . '</ul></section>';
}

/* --------------------------------------------------------------------- */
/* Front-end: customer emails + My Account / thank-you page                */
/* --------------------------------------------------------------------- */

add_action( 'woocommerce_email_after_order_table', function ( $order, $sent_to_admin, $plain_text, $email = null ) {
	$s = hsm_shipments_settings();
	if ( ! $s['enabled'] || ! $s['notify_email'] || $sent_to_admin ) {
		return;
	}
	echo wp_kses_post( hsm_shipments_render( $order, (bool) $plain_text ) );
}, 20, 4 );

add_action( 'woocommerce_order_details_after_order_table', function ( $order ) {
	$s = hsm_shipments_settings();
	if ( ! $s['enabled'] || ! $s['show_account'] ) {
		return;
	}
	echo wp_kses_post( hsm_shipments_render( $order, false ) );
}, 20 );

/* --------------------------------------------------------------------- */
/* MCP tool: hark-store/shipments                                          */
/* --------------------------------------------------------------------- */

function hsm_shipments_actions() {
	return array(
		'list'     => false,
		'carriers' => false,
		'get_settings' => false,
		'add'      => true,
		'remove'   => true,
		'import'   => true,
		'set_settings' => true,
	);
}

hsm_ability( 'shipments', array(
	'label'       => 'Shipment tracking',
	'description' => 'Track shipments per order. action=list (order_id → its shipments with carrier, number, tracking URL) | carriers (known carrier slugs and their tracking-URL templates) | get_settings | add (order_id, carrier, number, url?, notify_customer?) | remove (order_id, number and/or carrier) | import (csv: one "order,carrier,number[,url]" per line) | set_settings (enabled, notify_email, show_account, default_carrier, custom_carriers). Carriers: usps, ups, fedex, dhl, other, or a custom slug defined in settings.custom_carriers. The tracking URL is built from the carrier template unless url is given.',
	'properties'  => array(
		'action'          => hsm_s( 'string', '', array( 'enum' => array_keys( hsm_shipments_actions() ) ) ),
		'order_id'        => hsm_s( 'integer' ),
		'carrier'         => hsm_s( 'string', 'Carrier slug (usps/ups/fedex/dhl/other or custom).' ),
		'number'          => hsm_s( 'string', 'Tracking number.' ),
		'url'             => hsm_s( 'string', 'Optional explicit tracking URL (http/https).' ),
		'notify_customer' => hsm_s( 'boolean', 'add: also add a customer-visible note (emailed) about the shipment.' ),
		'csv'             => hsm_s( 'string', 'import: CSV text, one "order,carrier,number[,url]" per line.' ),
		'enabled'         => hsm_s( 'boolean' ),
		'notify_email'    => hsm_s( 'boolean', 'Show tracking in customer emails.' ),
		'show_account'    => hsm_s( 'boolean', 'Show tracking on the My Account order page and thank-you page.' ),
		'default_carrier' => hsm_s( 'string' ),
		'custom_carriers' => hsm_s( 'object', 'slug => {label, url} for carriers not in the built-in list. url may contain {number}.' ),
	),
	'required'    => array( 'action' ),
	'is_write'    => function ( $in ) {
		return ! empty( hsm_shipments_actions()[ $in['action'] ?? '' ] );
	},
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$action = (string) ( $in['action'] ?? '' );
		if ( ! array_key_exists( $action, hsm_shipments_actions() ) ) {
			throw new Exception( 'Unknown action. Allowed: ' . implode( ', ', array_keys( hsm_shipments_actions() ) ) );
		}
		switch ( $action ) {
			case 'carriers':
				$out = array();
				foreach ( hsm_shipment_carriers() as $slug => $c ) {
					$out[] = array( 'slug' => $slug, 'label' => $c['label'], 'url_template' => $c['url'], 'custom' => ! in_array( $slug, array( 'usps', 'ups', 'fedex', 'dhl', 'other' ), true ) );
				}
				return array( 'carriers' => $out );

			case 'get_settings':
				return hsm_shipments_settings();

			case 'list':
				$o = wc_get_order( (int) ( $in['order_id'] ?? 0 ) );
				if ( ! $o ) {
					throw new Exception( 'Order not found.' );
				}
				return array( 'order_id' => $o->get_id(), 'shipments' => hsm_shipments_get( $o ) );

			case 'add':
				$o = wc_get_order( (int) ( $in['order_id'] ?? 0 ) );
				if ( ! $o ) {
					throw new Exception( 'Order not found.' );
				}
				hsm_confirm( $in, 'Add shipment tracking to order #' . $o->get_order_number(), array( 'order_id' => $o->get_id(), 'carrier' => $in['carrier'] ?? '', 'number' => $in['number'] ?? '', 'url' => $in['url'] ?? '', 'notify_customer' => ! empty( $in['notify_customer'] ) ) );
				hsm_shipments_add( $o, (string) ( $in['carrier'] ?? '' ), (string) ( $in['number'] ?? '' ), (string) ( $in['url'] ?? '' ) );
				$list = hsm_shipments_get( $o );
				$row  = end( $list );
				if ( ! empty( $in['notify_customer'] ) ) {
					$o->add_order_note( sprintf( 'Shipment: %s tracking number %s%s', $row['label'], $row['number'], $row['url'] ? ' — ' . $row['url'] : '' ), 1 );
				}
				return array( 'action' => 'add', 'order_id' => $o->get_id(), 'shipment' => $row, 'shipments' => $list );

			case 'remove':
				$o = wc_get_order( (int) ( $in['order_id'] ?? 0 ) );
				if ( ! $o ) {
					throw new Exception( 'Order not found.' );
				}
				hsm_confirm( $in, 'Remove shipment tracking from order #' . $o->get_order_number(), array( 'order_id' => $o->get_id(), 'carrier' => $in['carrier'] ?? '', 'number' => $in['number'] ?? '', 'current_shipments' => hsm_shipments_get( $o ) ) );
				$n = hsm_shipments_remove( $o, (string) ( $in['number'] ?? '' ), (string) ( $in['carrier'] ?? '' ) );
				return array( 'action' => 'remove', 'order_id' => $o->get_id(), 'removed' => $n, 'shipments' => hsm_shipments_get( $o ) );

			case 'import':
				$csv = (string) ( $in['csv'] ?? '' );
				if ( '' === trim( $csv ) ) {
					throw new Exception( 'csv is empty. One "order,carrier,number[,url]" per line.' );
				}
				hsm_confirm( $in, 'Import shipment tracking from CSV', array( 'csv_preview' => wp_trim_words( $csv, 80 ) ) );
				$rows    = preg_split( '/\r\n|\r|\n/', $csv );
				$ok      = 0;
				$errors  = array();
				$touched = array();
				foreach ( $rows as $i => $line ) {
					$line = trim( $line );
					if ( '' === $line ) {
						continue;
					}
					$cols = str_getcsv( $line );
					$cols = array_map( 'trim', $cols );
					if ( count( $cols ) < 3 ) {
						$errors[] = 'Line ' . ( $i + 1 ) . ': needs at least order,carrier,number.';
						continue;
					}
					$oid = (int) $cols[0];
					$o   = $oid ? wc_get_order( $oid ) : false;
					if ( ! $o ) {
						$errors[] = 'Line ' . ( $i + 1 ) . ': order ' . $cols[0] . ' not found.';
						continue;
					}
					try {
						hsm_shipments_add( $o, $cols[1], $cols[2], $cols[3] ?? '' );
						$ok++;
						$touched[ $oid ] = true;
					} catch ( Exception $e ) {
						$errors[] = 'Line ' . ( $i + 1 ) . ': ' . $e->getMessage();
					}
				}
				return array( 'action' => 'import', 'imported' => $ok, 'orders_touched' => count( $touched ), 'errors' => $errors );

			case 'set_settings':
				$cur    = hsm_shipments_settings();
				$before = get_option( 'hsm_shipments', array() );
				$new    = $before;
				if ( array_key_exists( 'enabled', $in ) ) {
					$new['enabled'] = (bool) $in['enabled'];
				}
				if ( array_key_exists( 'notify_email', $in ) ) {
					$new['notify_email'] = (bool) $in['notify_email'];
				}
				if ( array_key_exists( 'show_account', $in ) ) {
					$new['show_account'] = (bool) $in['show_account'];
				}
				if ( array_key_exists( 'default_carrier', $in ) ) {
					$new['default_carrier'] = sanitize_key( (string) $in['default_carrier'] );
				}
				if ( array_key_exists( 'custom_carriers', $in ) ) {
					$clean = array();
					foreach ( (array) $in['custom_carriers'] as $slug => $c ) {
						$slug = sanitize_key( (string) $slug );
						if ( '' === $slug || ! is_array( $c ) || empty( $c['label'] ) ) {
							throw new Exception( 'Each custom carrier needs a slug and a label.' );
						}
						$clean[ $slug ] = array(
							'label' => sanitize_text_field( (string) $c['label'] ),
							'url'   => hsm_shipment_clean_template( $c['url'] ?? '' ),
						);
					}
					$new['custom_carriers'] = $clean;
				}
				hsm_confirm( $in, 'Update shipment tracking settings', array( 'before' => $before, 'after' => $new ) );
				hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_shipments', 'old' => $before ) );
				update_option( 'hsm_shipments', $new );
				return array( 'action' => 'set_settings', 'settings' => hsm_shipments_settings(), 'changed' => array_keys( array_intersect_key( $in, array_flip( array( 'enabled', 'notify_email', 'show_account', 'default_carrier', 'custom_carriers' ) ) ) ) );
		}
		throw new Exception( 'Unhandled action.' );
	},
) );
