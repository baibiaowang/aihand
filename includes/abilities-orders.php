<?php
defined( 'ABSPATH' ) || exit;

function hsm_order_out( $o, $full = false ) {
	$r = array(
		'id'       => $o->get_id(),
		'number'   => $o->get_order_number(),
		'status'   => $o->get_status(),
		'date'     => $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : null,
		'total'    => $o->get_total(),
		'currency' => $o->get_currency(),
		'customer' => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
		'email'    => $o->get_billing_email(),
		'items'    => count( $o->get_items() ),
	);
	if ( ! $full ) {
		return $r;
	}
	$r['line_items'] = array();
	foreach ( $o->get_items() as $item ) {
		$p                 = $item->get_product();
		$r['line_items'][] = array( 'item_id' => $item->get_id(), 'name' => $item->get_name(), 'sku' => $p ? $p->get_sku() : null, 'product_id' => $item->get_product_id(), 'variation_id' => $item->get_variation_id(), 'qty' => $item->get_quantity(), 'total' => $item->get_total() );
	}
	$r += array(
		'subtotal'        => $o->get_subtotal(),
		'discount_total'  => $o->get_discount_total(),
		'shipping_total'  => $o->get_shipping_total(),
		'tax_total'       => $o->get_total_tax(),
		'refunded'        => $o->get_total_refunded(),
		'coupons'         => $o->get_coupon_codes(),
		'payment_method'  => $o->get_payment_method_title(),
		'transaction_id'  => $o->get_transaction_id(),
		'shipping_method' => $o->get_shipping_method(),
		'billing'         => $o->get_address( 'billing' ),
		'shipping'        => $o->get_address( 'shipping' ),
		'customer_note'   => $o->get_customer_note(),
		'tracking'        => hsm_shipments_get( $o ) ?: null,
		'notes'           => array_map( function ( $n ) { return array( 'date' => $n->date_created->date( 'Y-m-d H:i' ), 'note' => $n->content, 'customer_note' => $n->customer_note ); }, wc_get_order_notes( array( 'order_id' => $o->get_id() ) ) ),
		'edit_url'        => $o->get_edit_order_url(),
	);
	return $r;
}

hsm_ability( 'orders-search', array(
	'label'       => 'Search / list orders',
	'description' => 'List orders filtered by status (processing, on-hold, completed, pending, cancelled, refunded, failed; array ok), customer email, product sku, date range (YYYY-MM-DD) or search text. Newest first.',
	'readonly'    => true,
	'idempotent'  => true,
	'properties'  => array(
		'status'      => hsm_s( array( 'string', 'array' ) ),
		'email'       => hsm_s( 'string' ),
		'search'      => hsm_s( 'string' ),
		'sku'         => hsm_s( 'string', 'Only orders containing this product/variation SKU.' ),
		'date_after'  => hsm_s( 'string' ),
		'date_before' => hsm_s( 'string' ),
		'page'        => hsm_s( 'integer' ),
		'per_page'    => hsm_s( 'integer' ),
	),
	'callback'    => function ( $in ) {
		hsm_require_wc();
		list( $per, $page ) = hsm_paginate( $in );
		$args = array( 'limit' => $per, 'page' => $page, 'paginate' => true, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order' );
		! empty( $in['status'] ) && $args['status'] = (array) $in['status'];
		! empty( $in['email'] ) && $args['billing_email'] = $in['email'];
		if ( ! empty( $in['search'] ) ) {
			// WC_Order_Query has no free-text search: resolve it through WooCommerce's own search
			// (which also honors woocommerce_shop_order_search_fields, e.g. sequential order numbers),
			// then apply the remaining filters and paginate in PHP. Works for both HPOS and CPT.
			$ids = function_exists( 'wc_order_search' ) ? wc_order_search( (string) $in['search'] ) : array();
			$all = array();
			foreach ( (array) $ids as $oid ) {
				$o = wc_get_order( $oid );
				if ( ! $o || 'shop_order' !== $o->get_type() ) {
					continue;
				}
				if ( ! empty( $in['status'] ) && ! $o->has_status( (array) $in['status'] ) ) {
					continue;
				}
				if ( ! empty( $in['email'] ) && strtolower( $o->get_billing_email() ) !== strtolower( (string) $in['email'] ) ) {
					continue;
				}
				$d = $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d' ) : '';
				if ( ! empty( $in['date_after'] ) && $d < $in['date_after'] ) {
					continue;
				}
				if ( ! empty( $in['date_before'] ) && $d > $in['date_before'] ) {
					continue;
				}
				$all[] = $o;
			}
			usort( $all, function ( $a, $b ) {
				return strcmp( (string) $b->get_date_created(), (string) $a->get_date_created() );
			} );
			$slice = array_slice( $all, ( $page - 1 ) * $per, $per );
			return array( 'total' => count( $all ), 'orders' => array_map( 'hsm_order_out', $slice ) );
		}
		if ( ! empty( $in['date_after'] ) || ! empty( $in['date_before'] ) ) {
			$args['date_created'] = ( $in['date_after'] ?? '2000-01-01' ) . '...' . ( $in['date_before'] ?? '2100-01-01' );
		}
		if ( ! empty( $in['sku'] ) ) {
			$pid = wc_get_product_id_by_sku( $in['sku'] );
			if ( ! $pid ) {
				return array( 'total' => 0, 'orders' => array() );
			}
			$args['limit'] = -1;
			$args['paginate'] = false;
			$all = array_filter( wc_get_orders( $args ), function ( $o ) use ( $pid ) {
				foreach ( $o->get_items() as $i ) {
					if ( in_array( $pid, array( $i->get_product_id(), $i->get_variation_id() ), true ) ) {
						return true;
					}
				}
				return false;
			} );
			$slice = array_slice( array_values( $all ), ( $page - 1 ) * $per, $per );
			return array( 'total' => count( $all ), 'orders' => array_map( 'hsm_order_out', $slice ) );
		}
		$r = wc_get_orders( $args );
		return array( 'total' => $r->total, 'pages' => $r->max_num_pages, 'orders' => array_map( 'hsm_order_out', $r->orders ) );
	},
) );

hsm_ability( 'order-get', array(
	'label'       => 'Get full order details',
	'description' => 'Order with line items (sku, qty, totals), addresses, payment, shipping, coupons, refunds, tracking and notes.',
	'readonly'    => true,
	'idempotent'  => true,
	'properties'  => array( 'id' => hsm_s( 'integer' ) ),
	'required'    => array( 'id' ),
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$o = wc_get_order( (int) $in['id'] );
		if ( ! $o ) {
			throw new Exception( 'Order not found.' );
		}
		return hsm_order_out( $o, true );
	},
) );

hsm_ability( 'order-create', array(
	'label'       => 'Create a manual order',
	'description' => 'Create an order (phone/wholesale/replacement/test). items: [{sku|product_id, qty, price? (override unit price)}]. Variation SKUs work. Optional billing/shipping address objects {first_name,last_name,company,address_1,address_2,city,state,postcode,country,email,phone}, customer_email (links/creates customer), coupons [codes], shipping {method_title, total}, fees [{name,total}], status (default pending), set_paid, payment_method_title, note. Totals and taxes are calculated.',
	'properties'  => array(
		'items' => hsm_s( 'array' ), 'billing' => hsm_s( 'object' ), 'shipping' => hsm_s( 'object' ), 'customer_email' => hsm_s( 'string' ),
		'coupons' => hsm_s( 'array' ), 'shipping_line' => hsm_s( 'object', '{method_title, total}' ), 'fees' => hsm_s( 'array' ),
		'status' => hsm_s( 'string' ), 'set_paid' => hsm_s( 'boolean' ), 'payment_method_title' => hsm_s( 'string' ), 'note' => hsm_s( 'string' ),
		'reduce_stock' => hsm_s( 'boolean', 'Default true when the order is paid/processing/completed.' ),
	),
	'required'    => array( 'items' ),
	'callback'    => function ( $in ) {
		hsm_require_wc();
		if ( empty( $in['items'] ) || ! is_array( $in['items'] ) ) {
			throw new Exception( 'items must be a non-empty list.' );
		}
		if ( ! empty( $in['customer_email'] ) && ! is_email( $in['customer_email'] ) ) {
			throw new Exception( 'customer_email is not a valid email.' );
		}
		$why   = array();
		$lines = array();
		foreach ( (array) $in['items'] as $it ) {
			$pid = ! empty( $it['product_id'] ) ? (int) $it['product_id'] : wc_get_product_id_by_sku( $it['sku'] ?? '' );
			$p   = wc_get_product( $pid );
			if ( ! $p ) {
				throw new Exception( 'Product not found: ' . ( $it['sku'] ?? $it['product_id'] ?? '?' ) );
			}
			if ( $p->is_type( 'variable' ) ) {
				throw new Exception( $p->get_name() . ' is a variable product: order a specific variation SKU.' );
			}
			$qty = $it['qty'] ?? 1;
			if ( ! is_numeric( $qty ) || (int) $qty != $qty || $qty < 1 || $qty > 10000 ) {
				throw new Exception( 'qty must be an integer between 1 and 10000.' );
			}
			if ( isset( $it['price'] ) ) {
				if ( ! is_numeric( $it['price'] ) || $it['price'] < 0 ) {
					throw new HSM_Blocked( 'price override must be a non-negative number.' );
				}
				$price_why = hsm_check_price( 'Order item ' . ( $p->get_sku() ?: $p->get_name() ), $p->get_regular_price(), (float) $it['price'], null, $p->get_price(), $p->get_id() );
				$why[]     = sprintf( '%s at custom price %s (list %s)', $p->get_sku() ?: $p->get_name(), $it['price'], $p->get_price() );
				if ( $price_why ) {
					$why[] = $price_why;
				}
			}
			if ( $p->managing_stock() && ! $p->backorders_allowed() && $p->get_stock_quantity() < $qty && ! empty( $in['set_paid'] ) ) {
				throw new HSM_Blocked( sprintf( 'Only %d of %s in stock.', $p->get_stock_quantity(), $p->get_sku() ?: $p->get_name() ) );
			}
			$lines[] = array( $p, (int) $qty, $it );
		}
		! empty( $in['set_paid'] ) && $why[] = 'order will be marked PAID without a real payment';
		foreach ( (array) ( $in['fees'] ?? array() ) as $f ) {
			if ( ! isset( $f['total'] ) || ! is_numeric( $f['total'] ) ) {
				throw new Exception( 'Each fee needs a numeric total.' );
			}
			$f['total'] < 0 && $why[] = 'negative fee (discount) ' . $f['total'];
		}
		if ( ! empty( $in['status'] ) && ! array_key_exists( 'wc-' . $in['status'], wc_get_order_statuses() ) ) {
			throw new Exception( 'Unknown order status ' . $in['status'] );
		}
		// Creating an order is always a store mutation, even when no unusual price/status flags were supplied.
		// Never let the empty-$why path bypass owner review.
		hsm_confirm( $in, 'Create manual order' . ( $why ? ': ' . implode( '; ', $why ) : '' ), array( 'items' => array_map( function ( $l ) { return array( 'sku' => $l[0]->get_sku(), 'qty' => $l[1], 'price' => $l[2]['price'] ?? $l[0]->get_price() ); }, $lines ) ) );
		$o = wc_create_order( array( 'created_via' => 'hark-store-mcp' ) );
		foreach ( (array) $in['items'] as $it ) {
			$pid = ! empty( $it['product_id'] ) ? (int) $it['product_id'] : wc_get_product_id_by_sku( $it['sku'] ?? '' );
			$p   = wc_get_product( $pid );
			if ( ! $p ) {
				$o->delete( true );
				throw new Exception( 'Product not found: ' . ( $it['sku'] ?? $it['product_id'] ?? '?' ) );
			}
			$qty  = max( 1, (int) ( $it['qty'] ?? 1 ) );
			$args = array();
			if ( isset( $it['price'] ) ) {
				$args = array( 'subtotal' => $it['price'] * $qty, 'total' => $it['price'] * $qty );
			}
			$o->add_product( $p, $qty, $args );
		}
		if ( ! empty( $in['customer_email'] ) ) {
			$in['customer_email'] = sanitize_email( (string) $in['customer_email'] );
			$u = get_user_by( 'email', $in['customer_email'] );
			$u && $o->set_customer_id( $u->ID );
			$o->set_billing_email( $in['customer_email'] );
		}
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			if ( ! empty( $in[ $type ] ) ) {
				$in[ $type ] = array_map( 'sanitize_text_field', array_map( 'strval', array_filter( array_intersect_key( (array) $in[ $type ], array_flip( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ) ) ), 'is_scalar' ) ) );
				$o->set_address( $in[ $type ], $type );
			}
		}
		if ( empty( $in['shipping'] ) && ! empty( $in['billing'] ) ) {
			$o->set_address( $in['billing'], 'shipping' );
		}
		if ( ! empty( $in['shipping_line'] ) ) {
			$s = new WC_Order_Item_Shipping();
			$s->set_method_title( sanitize_text_field( (string) ( $in['shipping_line']['method_title'] ?? 'Shipping' ) ) ?: 'Shipping' );
			$s->set_total( $in['shipping_line']['total'] ?? 0 );
			$o->add_item( $s );
		}
		foreach ( (array) ( $in['fees'] ?? array() ) as $f ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( sanitize_text_field( (string) ( $f['name'] ?? 'Fee' ) ) ?: 'Fee' );
			$fee->set_total( $f['total'] );
			$o->add_item( $fee );
		}
		$o->calculate_totals();
		foreach ( (array) ( $in['coupons'] ?? array() ) as $code ) {
			$r = $o->apply_coupon( $code );
			if ( is_wp_error( $r ) ) {
				$o->add_order_note( 'Coupon failed: ' . $code . ' - ' . $r->get_error_message() );
			}
		}
		! empty( $in['payment_method_title'] ) && $o->set_payment_method_title( sanitize_text_field( (string) $in['payment_method_title'] ) );
		! empty( $in['note'] ) && $o->add_order_note( sanitize_textarea_field( $in['note'] ) );
		$o->save();
		if ( ! empty( $in['set_paid'] ) ) {
			$o->payment_complete();
		}
		if ( ! empty( $in['status'] ) ) {
			$o->update_status( $in['status'], 'Created by AI agent.' );
		}
		if ( ( $in['reduce_stock'] ?? true ) && $o->has_status( array( 'processing', 'completed', 'on-hold' ) ) ) {
			wc_maybe_reduce_stock_levels( $o->get_id() );
		}
		return hsm_order_out( wc_get_order( $o->get_id() ), true );
	},
) );

hsm_ability( 'order-update', array(
	'label'       => 'Update an order (status, notes, tracking, addresses)',
	'description' => 'Change status (sends the normal customer emails), add a private note or a customer-visible note (emailed), set shipment tracking {carrier, number, url} (also added as a customer note when notify_customer=true), update billing/shipping address, or set customer_note / meta.',
	'properties'  => array(
		'id' => hsm_s( 'integer' ), 'status' => hsm_s( 'string' ), 'note' => hsm_s( 'string' ), 'customer_note' => hsm_s( 'boolean', 'If true, "note" is sent to the customer.' ),
		'tracking' => hsm_s( 'object', '{carrier, number, url}' ), 'notify_customer' => hsm_s( 'boolean' ), 'billing' => hsm_s( 'object' ), 'shipping' => hsm_s( 'object' ), 'meta' => hsm_s( 'object' ),
	),
	'required'    => array( 'id' ),
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$o = wc_get_order( (int) $in['id'] );
		if ( ! $o || 'shop_order' !== $o->get_type() ) {
			throw new Exception( 'Order not found.' );
		}
		if ( ! empty( $in['status'] ) ) {
			$in['status'] = preg_replace( '/^wc-/', '', $in['status'] );
			if ( ! array_key_exists( 'wc-' . $in['status'], wc_get_order_statuses() ) ) {
				throw new Exception( 'Unknown order status ' . $in['status'] . '. Valid: ' . implode( ', ', array_map( function ( $k ) { return substr( $k, 3 ); }, array_keys( wc_get_order_statuses() ) ) ) );
			}
		}
		if ( ! empty( $in['tracking'] ) ) {
			$t = (array) $in['tracking'];
			$in['tracking'] = array( 'carrier' => sanitize_text_field( $t['carrier'] ?? '' ), 'number' => sanitize_text_field( $t['number'] ?? '' ), 'url' => esc_url_raw( $t['url'] ?? '', array( 'https', 'http' ) ) );
			if ( '' === $in['tracking']['number'] ) {
				throw new Exception( 'tracking.number is required.' );
			}
			if ( '' !== $in['tracking']['url'] && ! hsm_tracking_url_ok( $in['tracking']['url'] ) ) {
				throw new HSM_Blocked( 'Tracking URL host is not an allowed carrier (or this store). Use a known carrier link, or omit "url" to use the carrier template.' );
			}
		}
		foreach ( array_keys( (array) ( $in['meta'] ?? array() ) ) as $mk ) {
			if ( '_' === substr( $mk, 0, 1 ) ) {
				throw new HSM_Blocked( 'Protected (underscore) meta keys cannot be set on orders.' );
			}
		}
		foreach ( array( 'billing', 'shipping' ) as $t ) {
			if ( ! empty( $in[ $t ] ) ) {
				$in[ $t ] = array_map( 'sanitize_text_field', array_intersect_key( (array) $in[ $t ], array_flip( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ) ) ) );
			}
		}
		// Capture only fields this proposal intends to change, so unrelated order updates do not invalidate it.
		$order_snapshot = array( 'status' => $o->get_status(), 'billing' => array(), 'shipping' => array(), 'meta' => array() );
		foreach ( array( 'billing', 'shipping' ) as $address_type ) {
			if ( ! empty( $in[ $address_type ] ) ) {
				$current_address = $o->get_address( $address_type );
				foreach ( array_keys( $in[ $address_type ] ) as $field ) {
					$order_snapshot[ $address_type ][ $field ] = $current_address[ $field ] ?? '';
				}
			}
		}
		foreach ( array_keys( (array) ( $in['meta'] ?? array() ) ) as $meta_key ) {
			$order_snapshot['meta'][ $meta_key ] = $o->get_meta( $meta_key, true );
		}
		if ( ! empty( $in['tracking'] ) ) {
			$order_snapshot['tracking'] = $o->get_meta( '_hsm_tracking', true );
		}
		// Use one confirmation point so every proposal includes the same live-state snapshot.
		$review_reason = 'Update order #' . $o->get_order_number();
		if ( ! empty( $in['status'] ) && in_array( $in['status'], array( 'cancelled', 'refunded', 'failed' ), true ) ) {
			$review_reason = sprintf( 'Change order #%s (%s %s) from %s to %s%s', $o->get_order_number(), $o->get_total(), $o->get_currency(), $o->get_status(), $in['status'], 'refunded' === $in['status'] ? ' — this marks it refunded but does NOT send money back; use order-refund for that' : ' — stock may be restored and the customer emailed' );
		} elseif ( ! empty( $in['status'] ) && $o->has_status( array( 'refunded', 'cancelled' ) ) && in_array( $in['status'], array( 'processing', 'completed' ), true ) ) {
			$review_reason = "Re-open a {$o->get_status()} order as {$in['status']}";
		}
		// Status, address, metadata, tracking and notes are all order mutations, even when the change is routine.
		hsm_confirm( $in, $review_reason, array( 'order' => $o->get_id(), 'current_status' => $o->get_status(), 'order_snapshot' => $order_snapshot, 'requested_changes' => $in ) );
		foreach ( array( 'billing', 'shipping' ) as $t ) {
			! empty( $in[ $t ] ) && $o->set_address( array_merge( $o->get_address( $t ), $in[ $t ] ), $t );
		}
		foreach ( (array) ( $in['meta'] ?? array() ) as $k => $v ) {
			$o->update_meta_data( $k, $v );
		}
		if ( ! empty( $in['tracking'] ) ) {
			$t   = $in['tracking'];
			$raw = $o->get_meta( '_hsm_tracking' );
			$raw = empty( $raw ) || ! is_array( $raw ) ? array() : ( isset( $raw['number'] ) ? array( $raw ) : $raw );
			$raw[] = array( 'carrier' => $t['carrier'], 'number' => $t['number'], 'url' => $t['url'], 'date' => wp_date( 'Y-m-d H:i' ) );
			$o->update_meta_data( '_hsm_tracking', array_values( $raw ) );
			$row = hsm_shipment_url( $t['carrier'], $t['number'], $t['url'] );
			$msg = sprintf( 'Your order has shipped via %s. Tracking number: %s%s', $t['carrier'] ?: 'carrier', $t['number'], $row ? ' — ' . $row : '' );
			$o->add_order_note( $msg, ! empty( $in['notify_customer'] ) ? 1 : 0 );
		}
		$o->save();
		if ( ! empty( $in['note'] ) ) {
			$o->add_order_note( sanitize_textarea_field( $in['note'] ), ! empty( $in['customer_note'] ) ? 1 : 0, true );
		}
		if ( ! empty( $in['status'] ) ) {
			$o->update_status( $in['status'], 'Status changed by AI agent.', true );
		}
		return hsm_order_out( wc_get_order( $o->get_id() ), true );
	},
) );

hsm_ability( 'order-refund', array(
	'label'       => 'Refund an order (full or partial)',
	'description' => 'Create a refund. Omit amount and items for a full refund. items: [{item_id or sku, qty}] refunds those lines (amount computed). restock (default true) returns items to stock. api_refund=true also refunds through the payment gateway when supported; default false records a manual refund only.',
	'destructive' => true,
	'properties'  => array(
		'id' => hsm_s( 'integer' ), 'amount' => hsm_s( array( 'string', 'number' ) ), 'reason' => hsm_s( 'string' ),
		'items' => hsm_s( 'array' ), 'restock' => hsm_s( 'boolean' ), 'api_refund' => hsm_s( 'boolean' ),
	),
	'required'    => array( 'id' ),
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$o = wc_get_order( (int) $in['id'] );
		if ( ! $o || 'shop_order' !== $o->get_type() ) {
			throw new Exception( 'Order not found.' );
		}
		if ( ! $o->is_paid() && ! $o->has_status( array( 'completed', 'processing', 'on-hold', 'refunded' ) ) ) {
			throw new HSM_Blocked( 'Order #' . $o->get_order_number() . ' is ' . $o->get_status() . ' (not paid); nothing to refund.' );
		}
		$remaining = (float) $o->get_remaining_refund_amount();
		if ( $remaining <= 0 ) {
			throw new HSM_Blocked( 'Order is already fully refunded.' );
		}
		$line_items = array();
		$amount     = 0;
		foreach ( (array) ( $in['items'] ?? array() ) as $it ) {
			foreach ( $o->get_items() as $iid => $item ) {
				$p = $item->get_product();
				if ( ( isset( $it['item_id'] ) && (int) $it['item_id'] === $iid ) || ( isset( $it['sku'] ) && $p && $p->get_sku() === $it['sku'] ) ) {
					$left = $item->get_quantity() - absint( $o->get_qty_refunded_for_item( $iid ) );
					$qty  = (int) ( $it['qty'] ?? $left );
					if ( $qty < 1 || $qty > $left ) {
						throw new HSM_Blocked( sprintf( 'Can refund at most %d of %s.', $left, $item->get_name() ) );
					}
					$unit               = $item->get_total() / max( 1, $item->get_quantity() );
					$unit_tax           = $item->get_total_tax() / max( 1, $item->get_quantity() );
					$line_items[ $iid ] = array( 'qty' => $qty, 'refund_total' => wc_format_decimal( $unit * $qty ), 'refund_tax' => array() );
					$amount            += $unit * $qty + $unit_tax * $qty;
				}
			}
		}
		if ( ! empty( $in['items'] ) && ! $line_items ) {
			throw new HSM_Blocked( 'None of the given items are on this order.' );
		}
		if ( isset( $in['amount'] ) ) {
			if ( ! is_numeric( $in['amount'] ) || $in['amount'] <= 0 ) {
				throw new HSM_Blocked( 'Refund amount must be greater than 0.' );
			}
			$amount = (float) $in['amount'];
		} elseif ( ! $line_items ) {
			$amount = $o->get_remaining_refund_amount();
			foreach ( $o->get_items() as $iid => $item ) {
				$line_items[ $iid ] = array( 'qty' => $item->get_quantity() - absint( $o->get_qty_refunded_for_item( $iid ) ), 'refund_total' => $item->get_total() );
			}
		}
		$amount = round( $amount, wc_get_price_decimals() );
		if ( $amount > $remaining + 0.001 ) {
			throw new HSM_Blocked( sprintf( 'Refund %.2f exceeds the refundable remainder %.2f.', $amount, $remaining ) );
		}
		$api = ! empty( $in['api_refund'] );
		if ( $api ) {
			$gw = WC()->payment_gateways()->payment_gateways()[ $o->get_payment_method() ] ?? null;
			if ( ! $gw || ! $gw->supports( 'refunds' ) ) {
				throw new HSM_Blocked( 'The payment method "' . $o->get_payment_method_title() . '" cannot refund automatically. Use api_refund=false and refund the customer manually.' );
			}
		}
		hsm_check_refund_day( $amount );
		$refund_items_snapshot = array();
		foreach ( $o->get_items() as $snapshot_item_id => $snapshot_item ) {
			$refund_items_snapshot[ (string) $snapshot_item_id ] = array(
				'product_id' => (int) $snapshot_item->get_product_id(),
				'variation_id' => (int) $snapshot_item->get_variation_id(),
				'sku' => ( $snapshot_item->get_product() ? (string) $snapshot_item->get_product()->get_sku() : '' ),
				'quantity' => (int) $snapshot_item->get_quantity(),
				'total' => (string) $snapshot_item->get_total(),
				'total_tax' => (string) $snapshot_item->get_total_tax(),
				'qty_refunded' => (int) absint( $o->get_qty_refunded_for_item( $snapshot_item_id ) ),
			);
		}
		// Every refund requires explicit owner review, including small refunds and gateway refunds.
		hsm_confirm( $in, sprintf( 'Refund %s %s on order #%s%s', wc_format_decimal( $amount, 2 ), $o->get_currency(), $o->get_order_number(), $api ? ' — money WILL be sent back through ' . $o->get_payment_method_title() : ' — recorded in the store only; you must send the money yourself' ), array( 'order' => $o->get_id(), 'order_total' => $o->get_total(), 'already_refunded' => $o->get_total_refunded(), 'current_status' => $o->get_status(), 'amount' => $amount, 'restock' => $in['restock'] ?? true, 'gateway_refund' => $api, 'refund_items_snapshot' => $refund_items_snapshot ) );
		$refund = wc_create_refund( array(
			'amount'         => wc_format_decimal( $amount, wc_get_price_decimals() ),
			'reason'         => sanitize_text_field( $in['reason'] ?? '' ),
			'order_id'       => $o->get_id(),
			'line_items'     => $line_items,
			'refund_payment' => $api,
			'restock_items'  => $in['restock'] ?? true,
		) );
		if ( is_wp_error( $refund ) ) {
			throw new Exception( $refund->get_error_message() );
		}
		hsm_daily_counter( 'refund', (float) $refund->get_amount() );
		return array( 'refund_id' => $refund->get_id(), 'amount' => $refund->get_amount(), 'order' => hsm_order_out( wc_get_order( $o->get_id() ) ) );
	},
) );

hsm_ability( 'customers-manage', array(
	'label'       => 'Search, view or upsert customers',
	'description' => 'action=search {search?, page?} lists customers with order count and total spent. action=get {email|id}. action=upsert {email, first_name, last_name, billing{}, shipping{}} creates or updates a customer account (no email is sent).',
	'properties'  => array(
		'action' => hsm_s( 'string', 'search | get | upsert', array( 'enum' => array( 'search', 'get', 'upsert' ) ) ),
		'search' => hsm_s( 'string' ), 'id' => hsm_s( 'integer' ), 'email' => hsm_s( 'string' ), 'first_name' => hsm_s( 'string' ), 'last_name' => hsm_s( 'string' ),
		'billing' => hsm_s( 'object' ), 'shipping' => hsm_s( 'object' ), 'page' => hsm_s( 'integer' ), 'per_page' => hsm_s( 'integer' ),
	),
	'required'    => array( 'action' ),
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$out = function ( $c ) {
			return array( 'id' => $c->get_id(), 'email' => $c->get_email(), 'name' => trim( $c->get_first_name() . ' ' . $c->get_last_name() ), 'orders' => $c->get_order_count(), 'total_spent' => $c->get_total_spent(), 'billing' => $c->get_billing(), 'shipping' => $c->get_shipping(), 'registered' => $c->get_date_created() ? $c->get_date_created()->date( 'Y-m-d' ) : null );
		};
		if ( 'search' === $in['action'] ) {
			list( $per, $page ) = hsm_paginate( $in );
			$q = new WP_User_Query( array( 'role__in' => array( 'customer', 'subscriber' ), 'number' => $per, 'paged' => $page, 'search' => ! empty( $in['search'] ) ? '*' . $in['search'] . '*' : '' ) );
			return array( 'total' => $q->get_total(), 'customers' => array_map( function ( $u ) use ( $out ) { return $out( new WC_Customer( $u->ID ) ); }, $q->get_results() ) );
		}
		$uid = ! empty( $in['id'] ) ? (int) $in['id'] : ( ! empty( $in['email'] ) ? ( get_user_by( 'email', $in['email'] )->ID ?? 0 ) : 0 );
		if ( 'get' === $in['action'] ) {
			if ( ! $uid ) {
				throw new Exception( 'Customer not found.' );
			}
			return $out( new WC_Customer( $uid ) );
		}
		if ( ! $uid ) {
			if ( empty( $in['email'] ) || ! is_email( $in['email'] ) ) {
				throw new Exception( 'A valid email is required.' );
			}
			hsm_confirm( $in, 'Create customer account for ' . sanitize_email( $in['email'] ), array( 'action' => 'create', 'email' => sanitize_email( $in['email'] ), 'first_name' => $in['first_name'] ?? '', 'last_name' => $in['last_name'] ?? '', 'billing' => $in['billing'] ?? array(), 'shipping' => $in['shipping'] ?? array() ) );
			$uid = wc_create_new_customer( $in['email'], '', wp_generate_password(), array( 'first_name' => $in['first_name'] ?? '', 'last_name' => $in['last_name'] ?? '' ) );
			if ( is_wp_error( $uid ) ) {
				throw new Exception( $uid->get_error_message() );
			}
		}
		if ( ! user_can( $uid, 'customer' ) && ! in_array( 'customer', (array) get_userdata( $uid )->roles, true ) && ! in_array( 'subscriber', (array) get_userdata( $uid )->roles, true ) ) {
			throw new HSM_Blocked( 'That account is staff, not a customer; it cannot be edited here.' );
		}
		$c = new WC_Customer( $uid );
		$customer_snapshot = array();
		foreach ( array( 'first_name', 'last_name' ) as $field ) {
			if ( array_key_exists( $field, $in ) ) $customer_snapshot[ $field ] = 'first_name' === $field ? $c->get_first_name() : $c->get_last_name();
		}
		foreach ( array( 'billing', 'shipping' ) as $address_type ) {
			foreach ( array_keys( (array) ( $in[ $address_type ] ?? array() ) ) as $field ) {
				$getter = 'get_' . $address_type . '_' . $field;
				if ( method_exists( $c, $getter ) ) $customer_snapshot[ $address_type ][ $field ] = $c->$getter();
			}
		}
		hsm_confirm( $in, 'Update customer #' . $uid, array( 'action' => 'update', 'customer_id' => $uid, 'changes' => $in, 'customer_snapshot' => $customer_snapshot ) );
		isset( $in['first_name'] ) && $c->set_first_name( $in['first_name'] );
		isset( $in['last_name'] ) && $c->set_last_name( $in['last_name'] );
		foreach ( (array) ( $in['billing'] ?? array() ) as $k => $v ) {
			method_exists( $c, "set_billing_$k" ) && $c->{"set_billing_$k"}( sanitize_text_field( $v ) );
		}
		foreach ( (array) ( $in['shipping'] ?? array() ) as $k => $v ) {
			method_exists( $c, "set_shipping_$k" ) && $c->{"set_shipping_$k"}( sanitize_text_field( $v ) );
		}
		$c->save();
		return $out( $c );
	},
) );

hsm_ability( 'sales-report', array(
	'label'       => 'Sales report',
	'description' => 'Revenue analytics for a date range (YYYY-MM-DD, default last 30 days): gross sales, refunds, net, orders, items, average order value, discount, shipping, tax, daily breakdown, top products (by qty and revenue), top coupons, new vs returning customers, low-stock and out-of-stock products. Counts paid statuses (processing, completed, on-hold) by default.',
	'readonly'    => true,
	'idempotent'  => true,
	'properties'  => array(
		'date_after'   => hsm_s( 'string' ),
		'date_before'  => hsm_s( 'string' ),
		'statuses'     => hsm_s( 'array' ),
		'top'          => hsm_s( 'integer', 'How many top products (default 10).' ),
		'low_stock_at' => hsm_s( 'integer', 'Low-stock threshold (default store setting).' ),
	),
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$tz     = wp_timezone();
		$after  = $in['date_after'] ?? ( new DateTime( '-30 days', $tz ) )->format( 'Y-m-d' );
		$before = $in['date_before'] ?? ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );
		$orders = wc_get_orders( array( 'limit' => -1, 'type' => 'shop_order', 'status' => $in['statuses'] ?? array( 'processing', 'completed', 'on-hold' ), 'date_created' => strtotime( $after . ' 00:00:00 ' . wp_timezone_string() ) . '...' . strtotime( $before . ' 23:59:59 ' . wp_timezone_string() ) ) );
		$sum    = array( 'gross' => 0, 'refunds' => 0, 'discount' => 0, 'shipping' => 0, 'tax' => 0, 'orders' => 0, 'items' => 0 );
		$daily  = array();
		$prods  = array();
		$coup   = array();
		$cust   = array( 'new' => 0, 'returning' => 0 );
		foreach ( $orders as $o ) {
			$sum['orders']++;
			$sum['gross']    += (float) $o->get_total();
			$sum['refunds']  += (float) $o->get_total_refunded();
			$sum['discount'] += (float) $o->get_discount_total();
			$sum['shipping'] += (float) $o->get_shipping_total();
			$sum['tax']      += (float) $o->get_total_tax();
			$d                = $o->get_date_created()->date( 'Y-m-d' );
			$daily[ $d ]      = ( $daily[ $d ] ?? 0 ) + (float) $o->get_total();
			foreach ( $o->get_items() as $it ) {
				$sum['items'] += $it->get_quantity();
				$k             = $it->get_name();
				$prods[ $k ]   = array( 'name' => $k, 'qty' => ( $prods[ $k ]['qty'] ?? 0 ) + $it->get_quantity(), 'revenue' => round( ( $prods[ $k ]['revenue'] ?? 0 ) + (float) $it->get_total(), 2 ) );
			}
			foreach ( $o->get_coupon_codes() as $c ) {
				$coup[ $c ] = ( $coup[ $c ] ?? 0 ) + 1;
			}
			$email = $o->get_billing_email();
			if ( $email ) {
				$prev = wc_get_orders( array( 'billing_email' => $email, 'limit' => 1, 'return' => 'ids', 'date_created' => '<' . $o->get_date_created()->getTimestamp(), 'status' => array( 'processing', 'completed', 'on-hold' ) ) );
				$prev ? $cust['returning']++ : $cust['new']++;
			}
		}
		usort( $prods, function ( $a, $b ) { return $b['revenue'] <=> $a['revenue']; } );
		ksort( $daily );
		arsort( $coup );
		$th  = (int) ( $in['low_stock_at'] ?? get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
		$low = array();
		foreach ( wc_get_products( array( 'limit' => 200, 'manage_stock' => true, 'type' => array( 'simple', 'variation' ), 'status' => 'publish' ) ) as $p ) {
			if ( $p->get_stock_quantity() !== null && $p->get_stock_quantity() <= $th ) {
				$low[] = array( 'id' => $p->get_id(), 'name' => $p->get_name(), 'sku' => $p->get_sku(), 'stock' => $p->get_stock_quantity() );
			}
		}
		$net = $sum['gross'] - $sum['refunds'];
		return array(
			'range'             => array( $after, $before ),
			'currency'          => get_woocommerce_currency(),
			'gross_sales'       => round( $sum['gross'], 2 ),
			'refunds'           => round( $sum['refunds'], 2 ),
			'net_sales'         => round( $net, 2 ),
			'orders'            => $sum['orders'],
			'items_sold'        => $sum['items'],
			'average_order'     => $sum['orders'] ? round( $sum['gross'] / $sum['orders'], 2 ) : 0,
			'discounts'         => round( $sum['discount'], 2 ),
			'shipping'          => round( $sum['shipping'], 2 ),
			'tax'               => round( $sum['tax'], 2 ),
			'customers'         => $cust,
			'daily'             => $daily,
			'top_products'      => array_slice( $prods, 0, (int) ( $in['top'] ?? 10 ) ),
			'top_coupons'       => $coup,
			'low_stock'         => $low,
		);
	},
) );
