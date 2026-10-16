<?php
/**
 * v1.8 Order export to CSV (replaces "Advanced Order Export" style plugins) — OFF by default.
 *
 * Filters by status / date range, projects a whitelisted set of fields, and either returns the rows
 * inline or writes a CSV into a web-inaccessible directory and returns a nonce-protected download URL.
 *
 * Safety: only whitelisted columns can be exported; every cell is guarded against CSV formula
 * injection (= + @ and non-numeric - get a leading apostrophe); the file lives under
 * wp-content/hsm-exports/<random>/ with index.php / .htaccess / web.config deny rules, and is served
 * only through admin-post.php for a user who can manage WooCommerce and holds a valid nonce.
 */
defined( 'ABSPATH' ) || exit;

function hsm_order_export_settings() {
	$s = wp_parse_args( (array) get_option( 'hsm_order_export', array() ), array( 'enabled' => false, 'max_rows' => 5000 ) );
	$s['enabled']  = (bool) $s['enabled'];
	$s['max_rows'] = max( 1, min( 50000, (int) $s['max_rows'] ) );
	return $s;
}

/** Whitelisted export columns (key => human label). */
function hsm_order_export_fields() {
	return array(
		'order_id'       => 'Order ID',
		'order_number'   => 'Order number',
		'status'         => 'Status',
		'date'           => 'Date',
		'customer_name'  => 'Customer name',
		'email'          => 'Email',
		'phone'          => 'Phone',
		'company'        => 'Company',
		'address_1'      => 'Address 1',
		'address_2'      => 'Address 2',
		'city'           => 'City',
		'state'          => 'State',
		'postcode'       => 'Postcode',
		'country'        => 'Country',
		'subtotal'       => 'Subtotal',
		'discount'       => 'Discount',
		'shipping'       => 'Shipping',
		'tax'            => 'Tax',
		'total'          => 'Total',
		'currency'       => 'Currency',
		'payment_method' => 'Payment method',
		'items'          => 'Items',
		'item_skus'      => 'SKUs',
		'coupons'        => 'Coupons',
		'tracking'       => 'Tracking',
		'customer_note'  => 'Customer note',
	);
}

/** Neutralize spreadsheet formula injection without mangling real numbers. */
function hsm_csv_safe( $v ) {
	$v = (string) $v;
	if ( '' === $v ) {
		return $v;
	}
	$c = $v[0];
	if ( in_array( $c, array( '=', '+', '@', "\t", "\r" ), true ) ) {
		return "'" . $v;
	}
	if ( '-' === $c && ! is_numeric( $v ) ) {
		return "'" . $v;
	}
	return $v;
}

/** One order projected to the requested columns. */
function hsm_order_export_row( $o, $fields ) {
	$items = array();
	$skus  = array();
	foreach ( $o->get_items() as $it ) {
		$p       = $it->get_product();
		$items[] = $it->get_quantity() . ' x ' . $it->get_name();
		if ( $p && $p->get_sku() ) {
			$skus[] = $p->get_sku();
		}
	}
	$track = array();
	foreach ( hsm_shipments_get( $o ) as $t ) {
		$track[] = $t['label'] . ' ' . $t['number'];
	}
	$all = array(
		'order_id'       => $o->get_id(),
		'order_number'   => $o->get_order_number(),
		'status'         => $o->get_status(),
		'date'           => $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : '',
		'customer_name'  => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
		'email'          => $o->get_billing_email(),
		'phone'          => $o->get_billing_phone(),
		'company'        => $o->get_billing_company(),
		'address_1'      => $o->get_billing_address_1(),
		'address_2'      => $o->get_billing_address_2(),
		'city'           => $o->get_billing_city(),
		'state'          => $o->get_billing_state(),
		'postcode'       => $o->get_billing_postcode(),
		'country'        => $o->get_billing_country(),
		'subtotal'       => $o->get_subtotal(),
		'discount'       => $o->get_discount_total(),
		'shipping'       => $o->get_shipping_total(),
		'tax'            => $o->get_total_tax(),
		'total'          => $o->get_total(),
		'currency'       => $o->get_currency(),
		'payment_method' => $o->get_payment_method_title(),
		'items'          => implode( '; ', $items ),
		'item_skus'      => implode( '; ', $skus ),
		'coupons'        => implode( '; ', $o->get_coupon_codes() ),
		'tracking'       => implode( '; ', $track ),
		'customer_note'  => $o->get_customer_note(),
	);
	$row = array();
	foreach ( $fields as $f ) {
		$row[ $f ] = hsm_csv_safe( (string) ( $all[ $f ] ?? '' ) );
	}
	return $row;
}

/** Protected directory that holds the generated CSVs. */
function hsm_order_export_dir( $create = true ) {
	$root = WP_CONTENT_DIR . '/hsm-exports';
	$key  = get_option( 'hsm_export_dir_key' );
	if ( ! $key || ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
		if ( ! $create ) {
			return null;
		}
		$key = bin2hex( random_bytes( 16 ) );
		update_option( 'hsm_export_dir_key', $key, false );
	}
	$dir = $root . '/' . $key;
	if ( $create ) {
		foreach ( array( $root, $dir ) as $d ) {
			if ( ! is_dir( $d ) ) {
				wp_mkdir_p( $d );
			}
			hsm_backup_protect( $d );
		}
	}
	return $dir;
}

/** Query the orders for an export. */
function hsm_order_export_query( $in ) {
	$args = array( 'limit' => hsm_order_export_settings()['max_rows'], 'paginate' => false, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order' );
	! empty( $in['status'] ) && $args['status'] = (array) $in['status'];
	if ( ! empty( $in['date_after'] ) || ! empty( $in['date_before'] ) ) {
		$args['date_created'] = ( $in['date_after'] ?? '2000-01-01' ) . '...' . ( $in['date_before'] ?? '2100-01-01' );
	}
	return wc_get_orders( $args );
}

/* --------------------------------------------------------------------- */
/* Download endpoint (admin-post, nonce + capability protected)            */
/* --------------------------------------------------------------------- */

add_action( 'admin_post_hsm_export_download', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Forbidden', 403 );
	}
	$file  = basename( (string) ( $_GET['file'] ?? '' ) );
	$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $_GET['token'] ?? '' ) );
	if ( ! preg_match( '/^[A-Za-z0-9._-]+\.csv$/', $file ) || '' === $token ) {
		wp_die( 'Bad request', 400 );
	}
	// A one-time token (not a session-bound nonce) so a link handed out by an agent over MCP still
	// works in the store owner's browser session.
	$rec = get_transient( 'hsm_exp_' . $token );
	if ( ! $rec || ( $rec['file'] ?? '' ) !== $file ) {
		wp_die( 'Link expired or invalid', 403 );
	}
	delete_transient( 'hsm_exp_' . $token );
	$dir  = hsm_order_export_dir( false );
	$path = $dir ? $dir . '/' . $file : '';
	if ( ! $path || ! is_file( $path ) ) {
		wp_die( 'File not found', 404 );
	}
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $file . '"' );
	header( 'Content-Length: ' . filesize( $path ) );
	readfile( $path );
	exit;
} );

/* --------------------------------------------------------------------- */
/* MCP tool: hark-store/order-export                                       */
/* --------------------------------------------------------------------- */

function hsm_order_export_actions() {
	return array( 'export' => false, 'get_settings' => false, 'set_settings' => true );
}

hsm_ability( 'order-export', array(
	'label'       => 'Export orders to CSV',
	'description' => 'action=export (default): export orders matching a status and/or date range. deliver=rows returns the rows inline; deliver=link writes a CSV to a protected directory and returns a nonce-protected download URL. fields is a list from the whitelist (order_id, order_number, status, date, customer_name, email, phone, company, address_1, address_2, city, state, postcode, country, subtotal, discount, shipping, tax, total, currency, payment_method, items, item_skus, coupons, tracking, customer_note); omit for a sensible default. Cells are guarded against spreadsheet formula injection. action=get_settings | set_settings (enabled, max_rows).',
	'properties'  => array(
		'action'      => hsm_s( 'string', '', array( 'enum' => array_keys( hsm_order_export_actions() ) ) ),
		'status'      => hsm_s( array( 'string', 'array' ), 'Order status(es) to include.' ),
		'date_after'  => hsm_s( 'string', 'YYYY-MM-DD' ),
		'date_before' => hsm_s( 'string', 'YYYY-MM-DD' ),
		'fields'      => hsm_s( 'array', 'Columns to export (whitelist). Omit for default.' ),
		'deliver'     => hsm_s( 'string', 'rows (default, inline) or link (CSV file + download URL).', array( 'enum' => array( 'rows', 'link' ) ) ),
		'enabled'     => hsm_s( 'boolean', 'set_settings: turn the module on/off.' ),
		'max_rows'    => hsm_s( 'integer', 'set_settings: hard cap on exported rows.' ),
	),
	'is_write'    => function ( $in ) {
		return ! empty( hsm_order_export_actions()[ $in['action'] ?? 'export' ] );
	},
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$action = (string) ( $in['action'] ?? 'export' );
		if ( ! array_key_exists( $action, hsm_order_export_actions() ) ) {
			throw new Exception( 'Unknown action. Allowed: ' . implode( ', ', array_keys( hsm_order_export_actions() ) ) );
		}
		if ( 'get_settings' === $action ) {
			return hsm_order_export_settings();
		}
		if ( 'set_settings' === $action ) {
			$before = get_option( 'hsm_order_export', array() );
			$new    = $before;
			if ( array_key_exists( 'enabled', $in ) ) {
				$new['enabled'] = (bool) $in['enabled'];
			}
			if ( array_key_exists( 'max_rows', $in ) ) {
				$new['max_rows'] = (int) $in['max_rows'];
			}
			hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_order_export', 'old' => $before ) );
			update_option( 'hsm_order_export', $new );
			return array( 'action' => 'set_settings', 'settings' => hsm_order_export_settings() );
		}
		$s = hsm_order_export_settings();
		if ( ! $s['enabled'] ) {
			throw new Exception( 'The order-export module is off. Enable it with action=set_settings enabled=true.' );
		}
		$known  = hsm_order_export_fields();
		$fields = array();
		foreach ( (array) ( $in['fields'] ?? array() ) as $f ) {
			$f = sanitize_key( (string) $f );
			if ( isset( $known[ $f ] ) ) {
				$fields[] = $f;
			}
		}
		if ( ! $fields ) {
			$fields = array( 'order_id', 'order_number', 'status', 'date', 'customer_name', 'email', 'total', 'currency', 'item_skus', 'tracking' );
		}
		$orders = hsm_order_export_query( $in );
		$rows   = array();
		foreach ( $orders as $o ) {
			$rows[] = hsm_order_export_row( $o, $fields );
		}
		$deliver = (string) ( $in['deliver'] ?? 'rows' );

		if ( 'link' !== $deliver ) {
			return array( 'count' => count( $rows ), 'fields' => $fields, 'rows' => $rows );
		}

		$dir  = hsm_order_export_dir( true );
		$name = 'orders-' . gmdate( 'Ymd-His' ) . '-' . substr( bin2hex( random_bytes( 4 ) ), 0, 6 ) . '.csv';
		$fh   = fopen( $dir . '/' . $name, 'w' );
		if ( ! $fh ) {
			throw new Exception( 'Could not write the export file.' );
		}
		fputcsv( $fh, array_map( function ( $f ) use ( $known ) { return $known[ $f ]; }, $fields ) );
		foreach ( $rows as $r ) {
			fputcsv( $fh, array_values( $r ) );
		}
		fclose( $fh );
		$token = wp_generate_password( 32, false );
		set_transient( 'hsm_exp_' . $token, array( 'file' => $name, 'user' => get_current_user_id() ), HOUR_IN_SECONDS );
		$url = admin_url( 'admin-post.php?action=hsm_export_download&file=' . rawurlencode( $name ) . '&token=' . $token );
		return array( 'count' => count( $rows ), 'fields' => $fields, 'file' => $name, 'bytes' => (int) filesize( $dir . '/' . $name ), 'download_url' => $url, 'note' => 'The download link needs a logged-in user who can manage WooCommerce. It is single-use and expires in 1 hour.' );
	},
) );
