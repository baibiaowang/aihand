<?php
/**
 * v1.5 Follow-up emails.
 *
 * 1. Review request: N days (default 7) after an order is completed, one email asking for product reviews.
 *    Scheduled with Action Scheduler (WP-Cron fallback), sent at most once per order, skipped for refunded/
 *    cancelled orders and unsubscribed addresses.
 * 2. Abandoned checkout recovery: a checkout (Store API draft / pending / failed order) that has an email but was
 *    not paid gets ONE reminder N hours later (default 4) — only when the shopper opted in to marketing email
 *    (checkbox at checkout, or user meta hsm_marketing_optin), never to unsubscribed addresses, never if the same
 *    email paid for an order since.
 *
 * Every email carries a working unsubscribe link (plus RFC 8058 one-click List-Unsubscribe headers) and the store's
 * postal address (CAN-SPAM). Without a postal address in WooCommerce > Settings nothing is sent.
 * Settings: option hsm_followup_settings (owner / WP-CLI only).
 */
defined( 'ABSPATH' ) || exit;

function hsm_followup_settings() {
	$s = wp_parse_args( (array) get_option( 'hsm_followup_settings', array() ), array(
		'review_enabled' => true, 'review_days' => 7, 'recovery_enabled' => true, 'recovery_hours' => 4, 'recovery_max_age_days' => 7,
	) );
	$s['review_days']           = max( 1, min( 60, (int) $s['review_days'] ) );
	$s['recovery_hours']        = max( 1, min( 72, (int) $s['recovery_hours'] ) );
	$s['recovery_max_age_days'] = max( 1, min( 30, (int) $s['recovery_max_age_days'] ) );
	return $s;
}

/* Unsubscribe ----------------------------------------------------------- */

function hsm_email_hash( $email ) {
	return hash( 'sha256', strtolower( trim( (string) $email ) ) );
}
function hsm_email_unsubscribed( $email ) {
	$u = (array) get_option( 'hsm_email_unsub', array() );
	return isset( $u[ hsm_email_hash( $email ) ] );
}
function hsm_email_unsub_token( $email ) {
	return substr( hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'auth' ) . 'hsm-unsub' ), 0, 32 );
}
function hsm_email_unsub_url( $email ) {
	return add_query_arg( array( 'hsm_unsub' => rtrim( strtr( base64_encode( strtolower( trim( $email ) ) ), '+/', '-_' ), '=' ), 't' => hsm_email_unsub_token( $email ) ), home_url( '/' ) );
}

add_action( 'init', function () {
	if ( empty( $_GET['hsm_unsub'] ) ) {
		return;
	}
	$email = strtolower( (string) base64_decode( strtr( (string) wp_unslash( $_GET['hsm_unsub'] ), '-_', '+/' ) ) );
	$tok   = (string) ( $_GET['t'] ?? '' );
	nocache_headers();
	if ( ! is_email( $email ) || ! hash_equals( hsm_email_unsub_token( $email ), $tok ) ) {
		wp_die( 'This unsubscribe link is invalid or incomplete.', 'Unsubscribe', array( 'response' => 400 ) );
	}
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) { // Confirm button, or one-click from the mail client (RFC 8058).
		$u                            = (array) get_option( 'hsm_email_unsub', array() );
		$u[ hsm_email_hash( $email ) ] = time();
		update_option( 'hsm_email_unsub', $u, false );
		do_action( 'hsm_email_unsubscribed', $email );
		wp_die( '<h1>You are unsubscribed</h1><p>We will not send review requests or cart reminders to this address any more. Order and shipping emails still arrive.</p>', 'Unsubscribed', array( 'response' => 200 ) );
	}
	$masked = substr( $email, 0, 1 ) . '***' . substr( $email, strpos( $email, '@' ) );
	wp_die( '<h1>Unsubscribe</h1><p>Stop review requests and cart reminders to ' . esc_html( $masked ) . '?</p><form method="post"><button type="submit" id="hsm-unsub-confirm">Unsubscribe</button></form>', 'Unsubscribe', array( 'response' => 200 ) );
}, 1 );

/* Shared email body ------------------------------------------------------ */

function hsm_store_postal_address() {
	$parts = array_filter( array( get_option( 'woocommerce_store_address' ), get_option( 'woocommerce_store_address_2' ), trim( get_option( 'woocommerce_store_city' ) . ', ' . explode( ':', (string) get_option( 'woocommerce_default_country' ) . ':' )[1] . ' ' . get_option( 'woocommerce_store_postcode' ), ', ' ) ) );
	return get_option( 'woocommerce_store_address' ) && get_option( 'woocommerce_store_city' ) ? implode( ', ', $parts ) : '';
}

function hsm_followup_send( $to, $subject, $heading, $inner_html, $kind ) {
	$address = hsm_store_postal_address();
	if ( ! $address ) {
		return new WP_Error( 'no_postal_address', 'Store postal address is missing (CAN-SPAM); set it in WooCommerce > Settings > General.' );
	}
	if ( hsm_email_unsubscribed( $to ) ) {
		return new WP_Error( 'unsubscribed', 'Recipient unsubscribed.' );
	}
	$unsub  = hsm_email_unsub_url( $to );
	$footer = '<p style="font-size:12px;color:#777;margin-top:24px">' . esc_html( get_bloginfo( 'name' ) ) . ' &middot; ' . esc_html( $address ) . '<br>'
		. ( 'recovery' === $kind ? 'You are receiving this because you opted in to emails from us at checkout. ' : 'You are receiving this because you ordered from us. ' )
		. '<a href="' . esc_url( $unsub ) . '">Unsubscribe</a> from review requests and reminders.</p>';
	$html   = $inner_html . $footer;
	if ( function_exists( 'WC' ) && WC()->mailer() ) {
		$html = WC()->mailer()->wrap_message( $heading, $html );
		$html = ( new WC_Email() )->style_inline( $html );
	}
	$headers = array( 'Content-Type: text/html; charset=UTF-8', 'List-Unsubscribe: <' . $unsub . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', 'X-HSM-Kind: ' . $kind );
	return wp_mail( $to, $subject, $html, $headers ) ? true : new WP_Error( 'send_failed', 'wp_mail returned false.' );
}

/* 1. Review requests ------------------------------------------------------ */

function hsm_followup_schedule( $hook, $ts, $args ) {
	if ( function_exists( 'as_schedule_single_action' ) ) {
		if ( ! as_next_scheduled_action( $hook, $args, 'hark-store' ) ) {
			as_schedule_single_action( $ts, $hook, $args, 'hark-store' );
		}
	} elseif ( ! wp_next_scheduled( $hook, $args ) ) {
		wp_schedule_single_event( $ts, $hook, $args );
	}
}

add_action( 'woocommerce_order_status_completed', function ( $order_id ) {
	$s     = hsm_followup_settings();
	$order = wc_get_order( $order_id );
	if ( ! $s['review_enabled'] || ! $order || $order->get_meta( '_hsm_review_scheduled' ) || $order->get_meta( '_hsm_review_sent' ) ) {
		return;
	}
	$ts = time() + $s['review_days'] * DAY_IN_SECONDS;
	hsm_followup_schedule( 'hsm_review_request', $ts, array( 'order_id' => (int) $order_id ) );
	$order->update_meta_data( '_hsm_review_scheduled', $ts );
	$order->save_meta_data();
} );

function hsm_review_request( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || 'shop_order' !== $order->get_type() ) {
		return 'no_order';
	}
	if ( $order->get_meta( '_hsm_review_sent' ) ) {
		return 'already_sent';
	}
	if ( 'completed' !== $order->get_status() || $order->get_total_refunded() >= (float) $order->get_total() && (float) $order->get_total() > 0 ) {
		return 'not_completed';
	}
	if ( ! hsm_followup_settings()['review_enabled'] || 'yes' !== get_option( 'woocommerce_enable_reviews', 'yes' ) ) {
		return 'disabled';
	}
	$email = $order->get_billing_email();
	if ( ! is_email( $email ) ) {
		return 'no_email';
	}
	$rows = '';
	$seen = array();
	foreach ( $order->get_items() as $item ) {
		$pid = $item->get_product_id();
		if ( ! $pid || isset( $seen[ $pid ] ) || 'publish' !== get_post_status( $pid ) || ! comments_open( $pid ) ) {
			continue;
		}
		$seen[ $pid ] = true;
		$rows .= '<li style="margin:6px 0"><a href="' . esc_url( get_permalink( $pid ) . '#reviews' ) . '">' . esc_html( get_the_title( $pid ) ) . '</a> — leave a review</li>';
	}
	if ( ! $rows ) {
		return 'nothing_to_review';
	}
	$name = $order->get_billing_first_name();
	$html = '<p>Hi ' . esc_html( $name ?: 'there' ) . ',</p><p>Thanks again for your order #' . esc_html( $order->get_order_number() ) . '. How is everything working out? A short, honest review helps other customers choose the right gear.</p><ul>' . $rows . '</ul><p>Questions or problems? Just reply to this email and we will help.</p>';
	$res  = hsm_followup_send( $email, 'How is your order from ' . get_bloginfo( 'name' ) . '?', 'How did we do?', $html, 'review' );
	if ( true === $res ) {
		$order->update_meta_data( '_hsm_review_sent', time() );
		$order->save_meta_data();
		$order->add_order_note( 'Hark Store: review request email sent.' );
		return 'sent';
	}
	$order->add_order_note( 'Hark Store: review request not sent — ' . $res->get_error_message() );
	if ( in_array( $res->get_error_code(), array( 'unsubscribed', 'no_postal_address' ), true ) ) {
		$order->update_meta_data( '_hsm_review_sent', 'skipped:' . $res->get_error_code() );
		$order->save_meta_data();
	}
	return $res->get_error_code();
}
add_action( 'hsm_review_request', function ( $order_id ) {
	hsm_review_request( is_array( $order_id ) ? ( $order_id['order_id'] ?? 0 ) : $order_id );
} );

/* 2. Abandoned checkout recovery ----------------------------------------- */

add_action( 'woocommerce_init', function () {
	if ( function_exists( 'woocommerce_register_additional_checkout_field' ) && apply_filters( 'hsm_marketing_optin_field', true ) ) {
		try {
			woocommerce_register_additional_checkout_field( array(
				'id'       => 'hsm/marketing-optin',
				'label'    => 'Email me offers and a reminder if I leave items in my cart (unsubscribe anytime)',
				'location' => 'contact',
				'type'     => 'checkbox',
			) );
		} catch ( \Throwable $e ) {
			error_log( 'Hark Store: opt-in field: ' . $e->getMessage() );
		}
	}
} );

function hsm_marketing_optin( $order ) {
	$v = $order->get_meta( '_wc_other/hsm/marketing-optin' );
	$ok = in_array( $v, array( '1', 1, true, 'true', 'yes' ), true );
	if ( ! $ok && $order->get_customer_id() ) {
		$ok = (bool) get_user_meta( $order->get_customer_id(), 'hsm_marketing_optin', true );
	}
	return (bool) apply_filters( 'hsm_marketing_optin', $ok, $order );
}


/*
 * WooCommerce 10.8+ no longer creates a draft order until "Place order" is pressed, so a shopper who typed an
 * email and left never becomes an order. Capture those checkouts from the Store API session instead
 * (cart/update-customer and PUT /checkout), keyed by the Woo session; removed as soon as an order is placed.
 */
function hsm_abandoned_get() {
	return (array) get_option( 'hsm_abandoned_carts', array() );
}
function hsm_abandoned_put( $all ) {
	uasort( $all, function ( $a, $b ) {
		return $b['updated'] <=> $a['updated'];
	} );
	update_option( 'hsm_abandoned_carts', array_slice( $all, 0, 500, true ), false );
}
add_filter( 'rest_request_after_callbacks', function ( $response, $handler, $request ) {
	$route = $request->get_route();
	if ( ! preg_match( '#^/wc/store(/v1)?/(cart/update-customer|checkout)$#', $route ) || ( '/checkout' === substr( $route, -9 ) && 'PUT' !== $request->get_method() ) ) {
		return $response;
	}
	if ( is_wp_error( $response ) || ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart || WC()->cart->is_empty() || ! WC()->customer ) {
		return $response;
	}
	$email = strtolower( (string) WC()->customer->get_billing_email() );
	if ( ! is_email( $email ) ) {
		return $response;
	}
	$key   = 's' . substr( hash( 'sha256', (string) WC()->session->get_customer_id() . wp_salt( 'nonce' ) ), 0, 20 );
	$all   = hsm_abandoned_get();
	$rec   = $all[ $key ] ?? array( 'created' => time(), 'optin' => false );
	$extra = (array) $request->get_param( 'additional_fields' );
	if ( array_key_exists( 'hsm/marketing-optin', $extra ) ) {
		$rec['optin'] = in_array( $extra['hsm/marketing-optin'], array( true, 1, '1', 'true', 'yes' ), true );
	}
	$items = array();
	foreach ( WC()->cart->get_cart() as $ci ) {
		$items[] = array( 'product_id' => (int) $ci['product_id'], 'variation_id' => (int) $ci['variation_id'], 'qty' => (int) $ci['quantity'], 'name' => $ci['data'] ? $ci['data']->get_name() : '' );
	}
	$rec           = array_merge( $rec, array( 'email' => $email, 'name' => WC()->customer->get_billing_first_name(), 'user' => get_current_user_id(), 'items' => $items, 'updated' => time() ) );
	unset( $rec['sent'] );
	$all[ $key ]   = $rec;
	WC()->session->set( 'hsm_abandoned_key', $key );
	hsm_abandoned_put( $all );
	return $response;
}, 10, 3 );
function hsm_abandoned_forget_session() {
	if ( function_exists( 'WC' ) && WC()->session && ( $key = WC()->session->get( 'hsm_abandoned_key' ) ) ) {
		$all = hsm_abandoned_get();
		unset( $all[ $key ] );
		hsm_abandoned_put( $all );
		WC()->session->set( 'hsm_abandoned_key', null );
	}
}
add_action( 'woocommerce_store_api_checkout_order_processed', 'hsm_abandoned_forget_session' ); // Order exists now: the order scan takes over.
add_action( 'woocommerce_checkout_order_processed', 'hsm_abandoned_forget_session' );
function hsm_abandoned_link_token( $key ) {
	return substr( hash_hmac( 'sha256', $key, wp_salt( 'auth' ) . 'hsm-recover' ), 0, 24 );
}

/** Find unpaid checkouts with an email and send one reminder each. $now lets tests move the clock. */
function hsm_recovery_scan( $now = null ) {
	$s   = hsm_followup_settings();
	$now = $now ?: time();
	$out = array();
	if ( ! $s['recovery_enabled'] ) {
		return $out;
	}
	$orders = wc_get_orders( array(
		'type'          => 'shop_order',
		'status'        => array( 'checkout-draft', 'pending', 'failed' ),
		'date_modified' => '<' . ( $now - $s['recovery_hours'] * HOUR_IN_SECONDS ),
		'date_created'  => '>' . ( $now - $s['recovery_max_age_days'] * DAY_IN_SECONDS ),
		'limit'         => 100,
		'orderby'       => 'date',
		'order'         => 'DESC',
	) );
	$done = array();
	foreach ( $orders as $o ) {
		$id    = $o->get_id();
		$email = strtolower( (string) $o->get_billing_email() );
		if ( $o->get_meta( '_hsm_recovery_sent' ) ) {
			continue;
		}
		$skip = '';
		if ( ! is_email( $email ) ) {
			$skip = 'no_email';
		} elseif ( isset( $done[ $email ] ) ) {
			$skip = 'duplicate_checkout';
		} elseif ( ! $o->get_item_count() ) {
			$skip = 'empty';
		} elseif ( ! hsm_marketing_optin( $o ) ) {
			$skip = 'no_marketing_consent';
		} elseif ( hsm_email_unsubscribed( $email ) ) {
			$skip = 'unsubscribed';
		} elseif ( wc_get_orders( array( 'type' => 'shop_order', 'billing_email' => $email, 'status' => array( 'processing', 'completed', 'on-hold' ), 'date_created' => '>=' . $o->get_date_created()->getTimestamp(), 'limit' => 1, 'return' => 'ids' ) ) ) {
			$skip = 'paid_since';
		} elseif ( get_transient( 'hsm_rec_' . hsm_email_hash( $email ) ) ) {
			$skip = 'recently_reminded';
		}
		if ( 'no_email' === $skip || 'no_marketing_consent' === $skip ) {
			$out[ $id ] = $skip; // May still change (email typed later, box ticked later): look again next run.
			continue;
		}
		if ( $skip ) {
			$o->update_meta_data( '_hsm_recovery_sent', 'skipped:' . $skip );
			$o->save_meta_data();
			$out[ $id ] = $skip;
			continue;
		}
		$rows = '';
		foreach ( $o->get_items() as $it ) {
			$rows .= '<li>' . esc_html( $it->get_name() ) . ' &times; ' . (int) $it->get_quantity() . '</li>';
		}
		$link = add_query_arg( array( 'hsm_recover' => $id, 'k' => $o->get_order_key() ), home_url( '/' ) );
		$html = '<p>Hi ' . esc_html( $o->get_billing_first_name() ?: 'there' ) . ',</p><p>You left these items at checkout:</p><ul>' . $rows . '</ul><p><a href="' . esc_url( $link ) . '" style="font-weight:bold">Return to checkout</a></p><p>Items are not reserved and stock can run out. If you ran into a problem paying, reply to this email and we will help.</p>';
		$res  = hsm_followup_send( $email, 'You left something in your cart at ' . get_bloginfo( 'name' ), 'Still thinking it over?', $html, 'recovery' );
		$done[ $email ] = true;
		if ( true === $res ) {
			set_transient( 'hsm_rec_' . hsm_email_hash( $email ), 1, DAY_IN_SECONDS );
		}
		$o->update_meta_data( '_hsm_recovery_sent', true === $res ? time() : 'skipped:' . $res->get_error_code() );
		$o->save_meta_data();
		$out[ $id ] = true === $res ? 'sent' : $res->get_error_code();
	}
	// Checkouts that never became an order (session capture).
	$all = hsm_abandoned_get();
	foreach ( $all as $key => &$rec ) {
		$email = $rec['email'];
		if ( ! empty( $rec['sent'] ) || $rec['updated'] > $now - $s['recovery_hours'] * HOUR_IN_SECONDS ) {
			continue;
		}
		if ( $rec['created'] < $now - $s['recovery_max_age_days'] * DAY_IN_SECONDS ) {
			unset( $all[ $key ] );
			continue;
		}
		$optin = $rec['optin'] || ( $rec['user'] && get_user_meta( $rec['user'], 'hsm_marketing_optin', true ) );
		$skip  = '';
		if ( isset( $done[ $email ] ) ) {
			$skip = 'duplicate_checkout';
		} elseif ( ! $optin ) {
			$out[ $key ] = 'no_marketing_consent';
			continue;
		} elseif ( hsm_email_unsubscribed( $email ) ) {
			$skip = 'unsubscribed';
		} elseif ( wc_get_orders( array( 'type' => 'shop_order', 'billing_email' => $email, 'status' => array( 'processing', 'completed', 'on-hold' ), 'date_created' => '>=' . $rec['created'], 'limit' => 1, 'return' => 'ids' ) ) ) {
			$skip = 'paid_since';
		} elseif ( get_transient( 'hsm_rec_' . hsm_email_hash( $email ) ) ) {
			$skip = 'recently_reminded';
		}
		if ( $skip ) {
			$rec['sent'] = 'skipped:' . $skip;
			$out[ $key ] = $skip;
			continue;
		}
		$rows = '';
		foreach ( $rec['items'] as $it ) {
			$rows .= '<li>' . esc_html( $it['name'] ) . ' &times; ' . (int) $it['qty'] . '</li>';
		}
		$link = add_query_arg( array( 'hsm_recover' => $key, 'k' => hsm_abandoned_link_token( $key ) ), home_url( '/' ) );
		$html = '<p>Hi ' . esc_html( $rec['name'] ?: 'there' ) . ',</p><p>You left these items at checkout:</p><ul>' . $rows . '</ul><p><a href="' . esc_url( $link ) . '" style="font-weight:bold">Return to checkout</a></p><p>Items are not reserved and stock can run out. If you ran into a problem, reply to this email and we will help.</p>';
		$res  = hsm_followup_send( $email, 'You left something in your cart at ' . get_bloginfo( 'name' ), 'Still thinking it over?', $html, 'recovery' );
		$done[ $email ] = true;
		true === $res && set_transient( 'hsm_rec_' . hsm_email_hash( $email ), 1, DAY_IN_SECONDS );
		$rec['sent'] = true === $res ? time() : 'skipped:' . $res->get_error_code();
		$out[ $key ] = true === $res ? 'sent' : $res->get_error_code();
		true === $res && update_option( 'hsm_recovery_sent_count', (int) get_option( 'hsm_recovery_sent_count', 0 ) + 1, false );
	}
	unset( $rec );
	hsm_abandoned_put( $all );
	return $out;
}

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'hsm_followup_cron' ) ) {
		wp_schedule_event( time() + 900, 'hourly', 'hsm_followup_cron' );
	}
} );
add_action( 'hsm_followup_cron', function () {
	hsm_recovery_scan();
} );

/** Recovery link: rebuild the cart from the unpaid order and go to checkout. */
add_action( 'wp_loaded', function () {
	if ( empty( $_GET['hsm_recover'] ) || ! function_exists( 'WC' ) ) {
		return;
	}
	nocache_headers();
	$rk  = (string) wp_unslash( $_GET['hsm_recover'] );
	$rec = null;
	$o   = null;
	if ( preg_match( '/^s[a-f0-9]{20}$/', $rk ) ) {
		$all = hsm_abandoned_get();
		$rec = isset( $all[ $rk ] ) && hash_equals( hsm_abandoned_link_token( $rk ), (string) ( $_GET['k'] ?? '' ) ) ? $all[ $rk ] : null;
		if ( ! $rec ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
	} else {
		$o = wc_get_order( absint( $rk ) );
		if ( ! $o || ! hash_equals( $o->get_order_key(), (string) ( $_GET['k'] ?? '' ) ) || ! in_array( $o->get_status(), array( 'checkout-draft', 'pending', 'failed' ), true ) ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
	}
	if ( null === WC()->session ) {
		WC()->initialize_session();
	}
	if ( null === WC()->cart ) {
		WC()->initialize_cart();
	}
	WC()->session->set_customer_session_cookie( true );
	WC()->cart->empty_cart();
	if ( $o ) {
		foreach ( $o->get_items() as $it ) {
			WC()->cart->add_to_cart( $it->get_product_id(), $it->get_quantity(), $it->get_variation_id() );
		}
		WC()->customer->set_billing_email( $o->get_billing_email() );
		$o->update_meta_data( '_hsm_recovery_clicked', time() );
		$o->save_meta_data();
	} else {
		foreach ( $rec['items'] as $it ) {
			WC()->cart->add_to_cart( $it['product_id'], $it['qty'], $it['variation_id'] );
		}
		WC()->customer->set_billing_email( $rec['email'] );
		update_option( 'hsm_recovery_clicks_session', (int) get_option( 'hsm_recovery_clicks_session', 0 ) + 1, false );
	}
	WC()->customer->save();
	wp_safe_redirect( wc_get_checkout_url() );
	exit;
}, 20 );

function hsm_followup_status() {
	global $wpdb;
	$pending = function_exists( 'as_get_scheduled_actions' ) ? count( as_get_scheduled_actions( array( 'hook' => 'hsm_review_request', 'status' => 'pending', 'per_page' => 500 ), 'ids' ) ) : null;
	$hpos    = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	$table   = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
	$count   = function ( $key, $like ) use ( $wpdb, $table ) {
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE meta_key=%s AND meta_value $like", $key ) );
	};
	return array(
		'settings'               => hsm_followup_settings(),
		'postal_address_set'     => '' !== hsm_store_postal_address(),
		'review_requests_queued' => $pending,
		'review_requests_sent'   => $count( '_hsm_review_sent', "NOT LIKE 'skipped:%'" ),
		'recovery_emails_sent'   => $count( '_hsm_recovery_sent', "NOT LIKE 'skipped:%'" ) + (int) get_option( 'hsm_recovery_sent_count', 0 ),
		'open_checkouts_tracked' => count( hsm_abandoned_get() ),
		'recovery_clicks'        => $count( '_hsm_recovery_clicked', "<> ''" ) + (int) get_option( 'hsm_recovery_clicks_session', 0 ),
		'unsubscribed'           => count( (array) get_option( 'hsm_email_unsub', array() ) ),
		'optin_field'            => 'Checkout checkbox hsm/marketing-optin (block checkout, contact section)',
	);
}
