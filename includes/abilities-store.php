<?php
defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------------------
 * Store overview & settings
 * ------------------------------------------------------------------------- */

hsm_ability( 'store-overview', array(
	'label'       => 'Store overview',
	'description' => 'One call to understand the store: WooCommerce/WordPress versions, currency, base country, tax/coupon settings, product/order counts by status, low-stock count, payment gateways, shipping zones, active plugins, SEO and feed status. Call this first.',
	'readonly'    => true,
	'idempotent'  => true,
	'callback'    => function () {
		$out = array(
			'site'      => array( 'name' => get_bloginfo( 'name' ), 'tagline' => get_bloginfo( 'description' ), 'url' => home_url(), 'wp' => get_bloginfo( 'version' ), 'timezone' => wp_timezone_string(), 'permalinks' => get_option( 'permalink_structure' ) ?: 'plain' ),
			'plugins'   => array_values( array_map( function ( $f ) { return dirname( $f ) === '.' ? $f : dirname( $f ); }, (array) get_option( 'active_plugins' ) ) ),
			'theme'     => get_stylesheet(),
			'seo'       => array( 'module' => 'hark-store built-in', 'sitemap' => home_url( '/wp-sitemap.xml' ), 'blog_public' => (bool) get_option( 'blog_public' ) ),
		);
		if ( ! class_exists( 'WooCommerce' ) ) {
			$out['woocommerce'] = 'not active';
			return $out;
		}
		$counts = (array) wp_count_posts( 'product' );
		$orders = array();
		foreach ( wc_get_order_statuses() as $k => $label ) {
			$orders[ substr( $k, 3 ) ] = (int) wc_orders_count( substr( $k, 3 ) );
		}
		$low = wc_get_products( array( 'limit' => -1, 'return' => 'ids', 'stock_status' => array( 'outofstock' ) ) );
		$out['woocommerce'] = array(
			'version'          => WC()->version,
			'currency'         => get_woocommerce_currency(),
			'base_country'     => WC()->countries->get_base_country(),
			'selling_to'       => get_option( 'woocommerce_allowed_countries' ),
			'prices_include_tax' => wc_prices_include_tax(),
			'taxes_enabled'    => wc_tax_enabled(),
			'coupons_enabled'  => wc_coupons_enabled(),
			'weight_unit'      => get_option( 'woocommerce_weight_unit' ),
			'dimension_unit'   => get_option( 'woocommerce_dimension_unit' ),
			'products'         => array_intersect_key( $counts, array_flip( array( 'publish', 'draft', 'pending', 'private', 'trash' ) ) ),
			'out_of_stock'     => count( $low ),
			'orders'           => $orders,
			'payment_gateways' => array_values( array_map( function ( $g ) { return array( 'id' => $g->id, 'title' => $g->get_title(), 'enabled' => 'yes' === $g->enabled ); }, WC()->payment_gateways()->payment_gateways() ) ),
			'shipping_zones'   => array_values( array_map( function ( $z ) { return $z['zone_name']; }, WC_Shipping_Zones::get_zones() ) ),
			'merchant_feed'    => hsm_feed_url(),
			'shop_page'        => get_permalink( wc_get_page_id( 'shop' ) ),
			'coming_soon_mode' => 'yes' === get_option( 'woocommerce_coming_soon' ),
			'safety'           => array( 'read_only' => (bool) hsm_guard( 'read_only' ), 'min_price' => hsm_guard( 'min_price' ), 'confirmation_required' => (bool) hsm_guard( 'require_confirmation' ) ),
		);
		return $out;
	},
) );

hsm_ability( 'store-settings', array(
	'label'       => 'Read or update store settings',
	'description' => 'Get or set store-wide settings. Pass only the keys you want to change in "set"; omit "set" to read. Keys: currency, base_country (e.g. "US:CA"), allowed_countries (all|specific|all_except), specific_allowed_countries (array of codes), ship_to_countries, calc_taxes (bool), prices_include_tax (bool), tax_display_shop/tax_display_cart (incl|excl), enable_coupons (bool), weight_unit, dimension_unit, price_decimals, store_address, store_city, store_postcode, enable_reviews (bool), review_rating_verification_required (bool), manage_stock (bool), notify_low_stock_amount, hold_stock_minutes, enable_guest_checkout (bool), blogname, blogdescription, timezone_string, blog_public (bool, search engine visibility).',
	'idempotent'  => true,
	'properties'  => array( 'set' => hsm_s( 'object', 'Settings to change (key => value).' ) ),
	'is_write'    => function ( $in ) { return ! empty( $in['set'] ); },
	'callback'    => function ( $in ) {
		$map = array(
			'currency' => 'woocommerce_currency', 'base_country' => 'woocommerce_default_country', 'allowed_countries' => 'woocommerce_allowed_countries',
			'specific_allowed_countries' => 'woocommerce_specific_allowed_countries', 'ship_to_countries' => 'woocommerce_ship_to_countries',
			'calc_taxes' => 'woocommerce_calc_taxes', 'prices_include_tax' => 'woocommerce_prices_include_tax', 'tax_display_shop' => 'woocommerce_tax_display_shop',
			'tax_display_cart' => 'woocommerce_tax_display_cart', 'enable_coupons' => 'woocommerce_enable_coupons', 'weight_unit' => 'woocommerce_weight_unit',
			'dimension_unit' => 'woocommerce_dimension_unit', 'price_decimals' => 'woocommerce_price_num_decimals', 'store_address' => 'woocommerce_store_address',
			'store_city' => 'woocommerce_store_city', 'store_postcode' => 'woocommerce_store_postcode', 'enable_reviews' => 'woocommerce_enable_reviews',
			'review_rating_verification_required' => 'woocommerce_review_rating_verification_required', 'manage_stock' => 'woocommerce_manage_stock',
			'notify_low_stock_amount' => 'woocommerce_notify_low_stock_amount', 'hold_stock_minutes' => 'woocommerce_hold_stock_minutes',
			'enable_guest_checkout' => 'woocommerce_enable_guest_checkout', 'blogname' => 'blogname', 'blogdescription' => 'blogdescription',
			'timezone_string' => 'timezone_string', 'blog_public' => 'blog_public',
		);
		$changed  = array();
		$set      = (array) ( $in['set'] ?? array() );
		$bools    = array( 'calc_taxes', 'prices_include_tax', 'enable_coupons', 'enable_reviews', 'review_rating_verification_required', 'manage_stock', 'enable_guest_checkout', 'blog_public' );
		$critical = array( 'currency', 'base_country', 'allowed_countries', 'specific_allowed_countries', 'ship_to_countries', 'calc_taxes', 'prices_include_tax', 'blog_public', 'manage_stock', 'price_decimals' );
		$risky    = array();
		$clean    = array();
		foreach ( $set as $k => $v ) {
			if ( ! isset( $map[ $k ] ) ) {
				throw new Exception( "Unknown setting '$k'. Allowed: " . implode( ', ', array_keys( $map ) ) );
			}
			if ( in_array( $k, array( 'blogname', 'blogdescription', 'timezone_string', 'blog_public' ), true ) && ! current_user_can( 'manage_options' ) ) {
				throw new HSM_Blocked( "$k is a WordPress site setting; only administrators can change it." );
			}
			if ( in_array( $k, $bools, true ) ) {
				$b = filter_var( $v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				if ( null === $b ) {
					throw new Exception( "$k must be true or false." );
				}
				$v = 'blog_public' === $k ? (int) $b : ( $b ? 'yes' : 'no' );
			} elseif ( is_array( $v ) ) {
				if ( 'specific_allowed_countries' !== $k ) {
					throw new Exception( "$k must be a string." );
				}
				$v = array_values( array_filter( array_map( 'strtoupper', array_map( 'sanitize_text_field', $v ) ) ) );
			} else {
				$v = sanitize_text_field( (string) $v );
			}
			if ( 'currency' === $k && ! array_key_exists( $v, get_woocommerce_currencies() ) ) {
				throw new Exception( "Unknown currency code '$v'." );
			}
			if ( 'base_country' === $k ) {
				$cc = explode( ':', $v )[0];
				if ( ! array_key_exists( $cc, WC()->countries->get_countries() ) ) {
					throw new Exception( "Unknown country '$v'." );
				}
			}
			if ( in_array( $k, array( 'allowed_countries', 'ship_to_countries' ), true ) && ! in_array( $v, array( 'all', 'specific', 'all_except', '', 'disabled' ), true ) ) {
				throw new Exception( "$k must be all, specific or all_except." );
			}
			if ( in_array( $k, array( 'tax_display_shop', 'tax_display_cart' ), true ) && ! in_array( $v, array( 'incl', 'excl' ), true ) ) {
				throw new Exception( "$k must be incl or excl." );
			}
			if ( in_array( $k, array( 'price_decimals', 'notify_low_stock_amount', 'hold_stock_minutes' ), true ) && ( ! is_numeric( $v ) || $v < 0 ) ) {
				throw new Exception( "$k must be a non-negative number." );
			}
			if ( 'timezone_string' === $k && ! in_array( $v, timezone_identifiers_list(), true ) ) {
				throw new Exception( "Unknown timezone '$v'." );
			}
			if ( get_option( $map[ $k ] ) == $v ) {
				continue;
			}
			if ( in_array( $k, $critical, true ) ) {
				$risky[ $k ] = array( 'from' => get_option( $map[ $k ] ), 'to' => $v );
			}
			$clean[ $k ] = $v;
		}
		if ( $risky ) {
			hsm_confirm( $in, 'Changing store-critical settings: ' . implode( ', ', array_keys( $risky ) ), $risky );
		}
		foreach ( $clean as $k => $v ) {
			hsm_undo_add( array( 'type' => 'option', 'name' => $map[ $k ], 'old' => get_option( $map[ $k ], null ) ) );
			update_option( $map[ $k ], $v );
			$changed[] = $k;
		}
		$out = array();
		foreach ( $map as $k => $opt ) {
			$out[ $k ] = get_option( $opt );
		}
		return array( 'changed' => $changed, 'settings' => $out );
	},
) );

/* ---------------------------------------------------------------------------
 * Products
 * ------------------------------------------------------------------------- */

function hsm_apply_attributes( $product, $attrs ) {
	$objs = array();
	$pos  = 0;
	foreach ( $attrs as $a ) {
		$name    = trim( sanitize_text_field( (string) ( $a['name'] ?? '' ) ) );
		$options = array_values( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $a['options'] ?? array() ) ) ) );
		if ( '' === $name ) {
			continue;
		}
		$attr = new WC_Product_Attribute();
		if ( ! empty( $a['global'] ) ) {
			$slug   = wc_sanitize_taxonomy_name( $name );
			$tax    = wc_attribute_taxonomy_name( $slug );
			$att_id = wc_attribute_taxonomy_id_by_name( $slug );
			if ( ! $att_id ) {
				$att_id = wc_create_attribute( array( 'name' => $name, 'slug' => $slug ) );
				if ( is_wp_error( $att_id ) ) {
					throw new Exception( $att_id->get_error_message() );
				}
				register_taxonomy( $tax, array( 'product' ), array( 'hierarchical' => false ) );
			}
			$term_ids = array();
			foreach ( $options as $opt ) {
				$t = term_exists( $opt, $tax );
				if ( ! $t ) {
					$t = wp_insert_term( $opt, $tax );
				}
				if ( is_wp_error( $t ) ) {
					throw new Exception( $t->get_error_message() );
				}
				$term_ids[] = (int) $t['term_id'];
			}
			$attr->set_id( $att_id );
			$attr->set_name( $tax );
			$attr->set_options( $term_ids );
		} else {
			$attr->set_id( 0 );
			$attr->set_name( $name );
			$attr->set_options( $options );
		}
		$attr->set_position( $pos++ );
		$attr->set_visible( $a['visible'] ?? true );
		$attr->set_variation( $a['variation'] ?? $product->is_type( 'variable' ) );
		$objs[] = $attr;
	}
	$product->set_attributes( $objs );
}

/** Map {"Size":"M"} to variation attribute array using the parent's attributes. */
function hsm_variation_attrs( $parent, $given ) {
	$out = array();
	foreach ( $parent->get_attributes() as $key => $attr ) {
		$label = $attr->is_taxonomy() ? wc_attribute_label( $attr->get_name() ) : $attr->get_name();
		$val   = null;
		$slug  = wc_sanitize_taxonomy_name( preg_replace( '/^pa_/', '', $key ) );
		foreach ( (array) $given as $gk => $gv ) {
			if ( 0 === strcasecmp( $gk, $label ) || 0 === strcasecmp( $gk, $key ) || sanitize_title( $gk ) === sanitize_title( $label ) || wc_sanitize_taxonomy_name( $gk ) === $slug ) {
				$val = (string) $gv;
			}
		}
		if ( null === $val ) {
			$out[ $key ] = ''; // "Any".
			continue;
		}
		if ( $attr->is_taxonomy() ) {
			$t   = get_term_by( 'name', $val, $attr->get_name() ) ?: get_term_by( 'slug', sanitize_title( $val ), $attr->get_name() );
			$val = $t ? $t->slug : sanitize_title( $val );
		}
		$out[ $key ] = $val;
	}
	return $out;
}

function hsm_apply_common( $p, $in ) {
	$setters = array(
		'name' => 'set_name', 'slug' => 'set_slug', 'description' => 'set_description', 'short_description' => 'set_short_description',
		'status' => 'set_status', 'sku' => 'set_sku', 'regular_price' => 'set_regular_price', 'sale_price' => 'set_sale_price',
		'date_on_sale_from' => 'set_date_on_sale_from', 'date_on_sale_to' => 'set_date_on_sale_to', 'manage_stock' => 'set_manage_stock',
		'stock_quantity' => 'set_stock_quantity', 'stock_status' => 'set_stock_status', 'backorders' => 'set_backorders', 'low_stock_amount' => 'set_low_stock_amount',
		'weight' => 'set_weight', 'tax_status' => 'set_tax_status', 'tax_class' => 'set_tax_class', 'featured' => 'set_featured',
		'catalog_visibility' => 'set_catalog_visibility', 'virtual' => 'set_virtual', 'downloadable' => 'set_downloadable', 'sold_individually' => 'set_sold_individually',
		'menu_order' => 'set_menu_order', 'purchase_note' => 'set_purchase_note', 'reviews_allowed' => 'set_reviews_allowed',
	);
	$enums = array(
		'status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'stock_status' => array( 'instock', 'outofstock', 'onbackorder' ),
		'backorders' => array( 'no', 'notify', 'yes' ), 'tax_status' => array( 'taxable', 'shipping', 'none' ),
		'catalog_visibility' => array( 'visible', 'catalog', 'search', 'hidden' ),
	);
	foreach ( $enums as $k => $allowed ) {
		if ( isset( $in[ $k ] ) && ! in_array( $in[ $k ], $allowed, true ) ) {
			throw new Exception( "$k must be one of: " . implode( ', ', $allowed ) );
		}
	}
	if ( isset( $in['name'] ) && ( ! is_string( $in['name'] ) || '' === trim( $in['name'] ) || mb_strlen( $in['name'] ) > 300 ) ) {
		throw new Exception( 'name must be a non-empty string of at most 300 characters.' );
	}
	foreach ( array( 'date_on_sale_from', 'date_on_sale_to' ) as $dk ) {
		if ( ! empty( $in[ $dk ] ) && false === strtotime( $in[ $dk ] ) ) {
			throw new Exception( "$dk is not a valid date (use YYYY-MM-DD)." );
		}
	}
	if ( ! empty( $in['date_on_sale_from'] ) && ! empty( $in['date_on_sale_to'] ) && strtotime( $in['date_on_sale_to'] ) < strtotime( $in['date_on_sale_from'] ) ) {
		throw new Exception( 'date_on_sale_to is before date_on_sale_from.' );
	}
	foreach ( array( 'weight', 'low_stock_amount' ) as $nk ) {
		if ( isset( $in[ $nk ] ) && '' !== $in[ $nk ] && ( ! is_numeric( $in[ $nk ] ) || $in[ $nk ] < 0 ) ) {
			throw new Exception( "$nk must be a non-negative number." );
		}
	}
	foreach ( array( 'description', 'short_description', 'purchase_note' ) as $hk ) {
		if ( isset( $in[ $hk ] ) ) {
			$in[ $hk ] = hsm_html( $in[ $hk ] );
		}
	}
	if ( isset( $in['name'] ) ) {
		$in['name'] = wp_strip_all_tags( $in['name'] );
	}
	foreach ( $setters as $k => $m ) {
		if ( array_key_exists( $k, $in ) && method_exists( $p, $m ) ) {
			$v = $in[ $k ];
			if ( in_array( $k, array( 'regular_price', 'sale_price' ), true ) && null !== $v ) {
				$v = wc_format_decimal( $v );
			}
			$p->$m( $v );
		}
	}
	if ( isset( $in['stock_quantity'] ) && ! isset( $in['manage_stock'] ) ) {
		$p->set_manage_stock( true );
	}
	if ( ! empty( $in['gtin'] ) && method_exists( $p, 'set_global_unique_id' ) ) {
		$p->set_global_unique_id( (string) $in['gtin'] );
	}
	if ( isset( $in['mpn'] ) ) {
		$p->update_meta_data( '_hsm_seo_mpn', $in['mpn'] );
	}
	if ( isset( $in['dimensions'] ) ) {
		$d = $in['dimensions'];
		isset( $d['length'] ) && $p->set_length( $d['length'] );
		isset( $d['width'] ) && $p->set_width( $d['width'] );
		isset( $d['height'] ) && $p->set_height( $d['height'] );
	}
	if ( isset( $in['shipping_class'] ) ) {
		$t = hsm_terms( array( $in['shipping_class'] ), 'product_shipping_class' );
		$p->set_shipping_class_id( $t ? $t[0] : 0 );
	}
}

hsm_ability( 'product-upsert', array(
	'label'       => 'Create or update a product (with variations, images, SEO)',
	'description' => 'Create a product, or update one when "id" or an existing "sku" is given. Supports simple, variable, external and grouped products in one call. Categories/tags/brands accept names (auto-created, "Parent > Child" paths ok). Images accept URLs, local file paths or attachment ids (first = main image, rest = gallery). For variable products pass "attributes" and "variations"; variations are matched by sku or attribute combination and created/updated (set "delete_missing_variations": true to remove others). "seo" sets the built-in SEO fields (title, description, focus_keyword, canonical, noindex, gtin, mpn, brand, condition). Prices are strings like "19.99".',
	'idempotent'  => true,
	'properties'  => array(
		'id'                 => hsm_s( 'integer', 'Existing product id to update.' ),
		'sku'                => hsm_s( 'string', 'SKU. If a product with this SKU exists and no id is given, it is updated.' ),
		'type'               => hsm_s( 'string', 'simple | variable | external | grouped', array( 'enum' => array( 'simple', 'variable', 'external', 'grouped' ) ) ),
		'name'               => hsm_s( 'string' ),
		'slug'               => hsm_s( 'string' ),
		'status'             => hsm_s( 'string', 'publish | draft | pending | private. New products default to draft.' ),
		'description'        => hsm_s( 'string', 'HTML allowed.' ),
		'short_description'  => hsm_s( 'string', 'HTML allowed.' ),
		'regular_price'      => hsm_s( 'string' ),
		'sale_price'         => hsm_s( 'string', 'Empty string removes the sale.' ),
		'gtin'               => hsm_s( 'string', 'GTIN/UPC/EAN/ISBN (WooCommerce global unique id).' ),
		'mpn'                => hsm_s( 'string' ),
		'date_on_sale_from'  => hsm_s( 'string', 'YYYY-MM-DD' ),
		'date_on_sale_to'    => hsm_s( 'string', 'YYYY-MM-DD' ),
		'manage_stock'       => hsm_s( 'boolean' ),
		'stock_quantity'     => hsm_s( 'integer' ),
		'stock_status'       => hsm_s( 'string', 'instock | outofstock | onbackorder' ),
		'backorders'         => hsm_s( 'string', 'no | notify | yes' ),
		'low_stock_amount'   => hsm_s( 'integer' ),
		'weight'             => hsm_s( 'string' ),
		'dimensions'         => hsm_s( 'object', '{length,width,height}' ),
		'shipping_class'     => hsm_s( 'string', 'Shipping class name (auto-created).' ),
		'tax_status'         => hsm_s( 'string', 'taxable | shipping | none' ),
		'tax_class'          => hsm_s( 'string' ),
		'virtual'            => hsm_s( 'boolean' ),
		'downloadable'       => hsm_s( 'boolean' ),
		'featured'           => hsm_s( 'boolean' ),
		'catalog_visibility' => hsm_s( 'string', 'visible | catalog | search | hidden' ),
		'categories'         => hsm_s( 'array', 'Category names or ids.', array( 'items' => array( 'type' => array( 'string', 'integer' ) ) ) ),
		'tags'               => hsm_s( 'array', 'Tag names.', array( 'items' => array( 'type' => 'string' ) ) ),
		'brands'             => hsm_s( 'array', 'Brand names (WooCommerce Brands taxonomy).', array( 'items' => array( 'type' => 'string' ) ) ),
		'images'             => hsm_s( 'array', 'URLs, local paths, attachment ids, or {src, alt}. First is the main image.' ),
		'attributes'         => hsm_s( 'array', '[{name, options:[..], variation:bool, visible:bool, global:bool}]' ),
		'variations'         => hsm_s( 'array', '[{attributes:{Size:"M",Color:"Red"}, sku, gtin, mpn, regular_price, sale_price, stock_quantity, stock_status, weight, image, status, description}]. Each variation should have its own GTIN for Google Shopping.' ),
		'delete_missing_variations' => hsm_s( 'boolean' ),
		'default_attributes' => hsm_s( 'object', '{Size:"M"}' ),
		'external_url'       => hsm_s( 'string' ),
		'button_text'        => hsm_s( 'string' ),
		'grouped_skus'       => hsm_s( 'array', 'Child product SKUs/ids for grouped products.' ),
		'upsell_skus'        => hsm_s( 'array', 'SKUs or ids.' ),
		'cross_sell_skus'    => hsm_s( 'array', 'SKUs or ids.' ),
		'purchase_note'      => hsm_s( 'string' ),
		'seo'                => hsm_s( 'object', '{title, description, focus_keyword, canonical, noindex, gtin, mpn, brand, condition}' ),
		'meta'               => hsm_s( 'object', 'Custom meta key => value.' ),
	),
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$id = hsm_product_id( $in );
		if ( ! empty( $in['id'] ) && ! wc_get_product( $id ) ) {
			throw new Exception( "Product {$in['id']} not found." );
		}
		$created  = ! $id;
		$existing = $id ? wc_get_product( $id ) : null;
		if ( $existing && $existing->is_type( 'variation' ) ) {
			throw new Exception( "ID/SKU {$id} is a variation. Update it through its parent product's \"variations\" list, or with products-bulk-update items." );
		}
		$type = $in['type'] ?? ( $existing ? $existing->get_type() : ( ! empty( $in['variations'] ) ? 'variable' : 'simple' ) );
		if ( ! in_array( $type, array( 'simple', 'variable', 'external', 'grouped' ), true ) ) {
			throw new Exception( 'type must be simple, variable, external or grouped.' );
		}
		if ( $existing && $existing->get_type() !== $type && $existing->is_type( 'variable' ) ) {
			throw new Exception( 'Changing a variable product to another type would orphan its variations; create a new product instead.' );
		}
		if ( $created && empty( $in['name'] ) ) {
			throw new Exception( 'name is required when creating a product.' );
		}
		// ---- Guard checks before anything is written.
		$family = $existing ? array_merge( array( $id ), $existing->get_children() ) : array();
		$skus   = array();
		foreach ( array_merge( array( $in ), (array) ( $in['variations'] ?? array() ) ) as $row ) {
			if ( ! empty( $row['sku'] ) ) {
				if ( ! is_string( $row['sku'] ) || mb_strlen( $row['sku'] ) > 100 ) {
					throw new HSM_Blocked( 'SKU must be a string of at most 100 characters.' );
				}
				if ( isset( $skus[ $row['sku'] ] ) ) {
					throw new HSM_Blocked( "SKU {$row['sku']} is used twice in this request." );
				}
				$skus[ $row['sku'] ] = 1;
				$owner = wc_get_product_id_by_sku( $row['sku'] );
				if ( $owner && ! in_array( (int) $owner, $family, true ) ) {
					throw new HSM_Blocked( "SKU {$row['sku']} already belongs to product {$owner}." );
				}
			}
		}
		$reasons = array();
		foreach ( array_keys( (array) ( $in['meta'] ?? array() ) ) as $mk ) {
			if ( '_' === substr( (string) $mk, 0, 1 ) ) {
				throw new HSM_Blocked( "Protected (underscore) meta key '$mk' cannot be set through MCP: it could bypass price, stock or cost guards." );
			}
		}
		$base_reg = $existing ? $existing->get_regular_price() : null;
		hsm_check_stock( 'product', $in['stock_quantity'] ?? null );
		if ( 'variable' !== $type && ( array_key_exists( 'regular_price', $in ) || array_key_exists( 'sale_price', $in ) ) ) {
			$new_reg = array_key_exists( 'regular_price', $in ) ? $in['regular_price'] : $base_reg;
			$new_sal = array_key_exists( 'sale_price', $in ) ? $in['sale_price'] : ( $existing ? $existing->get_sale_price() : '' );
			if ( $created && ( null === $new_reg || '' === $new_reg ) && 'grouped' !== $type && 'publish' === ( $in['status'] ?? 'draft' ) ) {
				throw new HSM_Blocked( 'A published product needs a regular_price.' );
			}
			$r = hsm_check_price( $in['sku'] ?? ( $existing ? $existing->get_name() : $in['name'] ), $base_reg, $new_reg, $new_sal, $existing ? $existing->get_price() : null, $existing ? $existing->get_id() : 0 );
			$existing && ( (string) $new_reg !== (string) $existing->get_regular_price() || (string) $new_sal !== (string) $existing->get_sale_price() ) && $reprice_n = ( $reprice_n ?? 0 ) + 1;
			$r && $reasons[] = $r;
		}
		foreach ( (array) ( $in['variations'] ?? array() ) as $vin ) {
			hsm_check_stock( 'variation ' . ( $vin['sku'] ?? '' ), $vin['stock_quantity'] ?? null );
			$old = ! empty( $vin['sku'] ) ? wc_get_product( wc_get_product_id_by_sku( $vin['sku'] ) ) : null;
			$nr  = array_key_exists( 'regular_price', $vin ) ? $vin['regular_price'] : ( $old ? $old->get_regular_price() : null );
			$ns  = array_key_exists( 'sale_price', $vin ) ? $vin['sale_price'] : ( $old ? $old->get_sale_price() : '' );
			if ( ! $old && ( null === $nr || '' === $nr ) && ( $vin['status'] ?? 'publish' ) === 'publish' && empty( $vin['sku'] ) === false ) {
				// New variation without a price would be unpurchasable; allowed but flagged.
				$reasons[] = 'variation ' . $vin['sku'] . ' has no price and will not be purchasable';
			}
			$r = hsm_check_price( 'variation ' . ( $vin['sku'] ?? wp_json_encode( $vin['attributes'] ?? array() ) ), $old ? $old->get_regular_price() : null, $nr, $ns, $old ? $old->get_price() : null, $old ? $old->get_id() : ( $existing ? $existing->get_id() : 0 ) );
			$old && ( (string) $nr !== (string) $old->get_regular_price() || (string) $ns !== (string) $old->get_sale_price() ) && $reprice_n = ( $reprice_n ?? 0 ) + 1;
			$r && $reasons[] = $r;
		}
		$final_status = $in['status'] ?? ( $existing ? $existing->get_status() : 'draft' );
		if ( 'publish' === $final_status && in_array( $type, array( 'simple', 'external' ), true ) ) {
			$final_price = array_key_exists( 'regular_price', $in ) ? $in['regular_price'] : ( $existing ? $existing->get_regular_price() : '' );
			if ( null === $final_price || '' === (string) $final_price ) {
				throw new HSM_Blocked( 'A published product needs a regular_price (or keep it as draft).' );
			}
		}
		if ( ! empty( $in['delete_missing_variations'] ) && $existing ) {
			$reasons[] = 'delete_missing_variations will permanently delete variations not listed';
		}
		if ( $existing && isset( $in['status'] ) && 'publish' === $existing->get_status() && 'publish' !== $in['status'] ) {
			$reasons[] = 'unpublishes a live product (status ' . $in['status'] . ')';
		}
		hsm_check_price_changes( $reprice_n ?? 0 );
		// Every product write must go through the owner review queue, not only risky price/status changes.
		$review_reason = $reasons ? implode( '; ', $reasons ) : ( $existing ? 'Update product "' . $existing->get_name() . '"' : 'Create product "' . ( $in['name'] ?? 'unnamed product' ) . '"' );
		hsm_confirm( $in, $review_reason, array( 'product' => $existing ? $existing->get_name() : ( $in['name'] ?? '' ), 'changes' => $reasons, 'input' => $in ) );
		if ( $existing ) {
			hsm_snap_product( $id );
		}
		$class = WC_Product_Factory::get_product_classname( $id, $type );
		$p     = new $class( $id );
		if ( $created && ! isset( $in['status'] ) ) {
			$p->set_status( 'draft' );
		}
		hsm_apply_common( $p, $in );
		if ( isset( $in['categories'] ) ) {
			$p->set_category_ids( hsm_terms( $in['categories'], 'product_cat' ) );
		}
		if ( isset( $in['tags'] ) ) {
			$p->set_tag_ids( hsm_terms( $in['tags'], 'product_tag' ) );
		}
		if ( isset( $in['attributes'] ) ) {
			hsm_apply_attributes( $p, $in['attributes'] );
		}
		if ( $p->is_type( 'external' ) ) {
			isset( $in['external_url'] ) && $p->set_product_url( esc_url_raw( (string) $in['external_url'], array( 'http', 'https' ) ) );
			isset( $in['button_text'] ) && $p->set_button_text( sanitize_text_field( (string) $in['button_text'] ) );
		}
		$resolve = function ( $list ) {
			return array_values( array_filter( array_map( function ( $x ) { return is_numeric( $x ) ? (int) $x : wc_get_product_id_by_sku( $x ); }, (array) $list ) ) );
		};
		isset( $in['upsell_skus'] ) && $p->set_upsell_ids( $resolve( $in['upsell_skus'] ) );
		isset( $in['cross_sell_skus'] ) && $p->set_cross_sell_ids( $resolve( $in['cross_sell_skus'] ) );
		if ( $p->is_type( 'grouped' ) && isset( $in['grouped_skus'] ) ) {
			$p->set_children( $resolve( $in['grouped_skus'] ) );
		}
		$id = $p->save();
		if ( $created ) {
			hsm_undo_add( array( 'type' => 'product_created', 'id' => $id ) );
		}
		if ( isset( $in['brands'] ) && taxonomy_exists( 'product_brand' ) ) {
			wp_set_object_terms( $id, hsm_terms( $in['brands'], 'product_brand' ), 'product_brand' );
		}
		if ( isset( $in['images'] ) ) {
			$ids = array();
			foreach ( (array) $in['images'] as $img ) {
				$ids[] = hsm_media( $img, $id, is_array( $img ) ? ( $img['alt'] ?? $p->get_name() ) : $p->get_name() );
			}
			$p->set_image_id( $ids ? array_shift( $ids ) : '' );
			$p->set_gallery_image_ids( $ids );
		}
		$var_report = array();
		if ( $p->is_type( 'variable' ) && isset( $in['variations'] ) ) {
			$existing = array();
			foreach ( $p->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v ) {
					$existing[ $vid ] = $v;
				}
			}
			$kept = array();
			foreach ( (array) $in['variations'] as $vin ) {
				$has_attrs = ! empty( $vin['attributes'] );
				$attrs     = $has_attrs ? hsm_variation_attrs( $p, $vin['attributes'] ) : array();
				$match     = null;
				foreach ( $existing as $vid => $v ) {
					if ( ! empty( $vin['sku'] ) && $v->get_sku() === $vin['sku'] ) {
						$match = $v;
						break;
					}
				}
				if ( ! $match && $has_attrs ) {
					foreach ( $existing as $vid => $v ) {
						if ( ! in_array( $vid, $kept, true ) && $v->get_attributes() == $attrs ) {
							$match = $v;
							break;
						}
					}
				}
				if ( ! $match && ! $has_attrs ) {
					throw new Exception( 'Variation needs "attributes" (or an existing variation sku): ' . wp_json_encode( $vin ) );
				}
				$v = $match ?: new WC_Product_Variation();
				$v->set_parent_id( $id );
				$has_attrs && $v->set_attributes( $attrs );
				if ( $match && ! empty( $vin['sku'] ) && $vin['sku'] === $match->get_sku() ) {
					unset( $vin['sku'] );
				}
				hsm_apply_common( $v, $vin );
				if ( ! isset( $vin['status'] ) && ! $match ) {
					$v->set_status( 'publish' );
				}
				if ( isset( $vin['image'] ) ) {
					$v->set_image_id( hsm_media( $vin['image'], $id ) );
				}
				$vid          = $v->save();
				$kept[]       = $vid;
				$var_report[] = array( 'id' => $vid, 'attributes' => $attrs, 'action' => $match ? 'updated' : 'created' );
			}
			if ( ! empty( $in['delete_missing_variations'] ) ) {
				foreach ( $existing as $vid => $v ) {
					if ( ! in_array( $vid, $kept, true ) ) {
						$v->delete( true );
						$var_report[] = array( 'id' => $vid, 'action' => 'deleted' );
					}
				}
			}
			if ( isset( $in['default_attributes'] ) ) {
				$p->set_default_attributes( hsm_variation_attrs( $p, $in['default_attributes'] ) );
			}
			WC_Product_Variable::sync( $id );
		}
		$p->save();
		if ( isset( $in['seo'] ) ) {
			hsm_set_seo( $id, $in['seo'] );
		}
		foreach ( (array) ( $in['meta'] ?? array() ) as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		wc_delete_product_transients( $id );
		$out = hsm_product_out( wc_get_product( $id ), true );
		$warn = array();
		if ( 'publish' === $out['status'] && '' === (string) $out['price'] && ! $p->is_type( 'grouped' ) ) {
			$warn[] = 'Product is published but has no purchasable price.';
		}
		if ( $created && 'draft' === $out['status'] ) {
			$warn[] = 'Created as draft (not visible to shoppers). Set status "publish" when it is ready.';
		}
		return array( 'action' => $created ? 'created' : 'updated', 'id' => $id, 'warnings' => $warn, 'variations_changed' => $var_report, 'product' => $out );
	},
) );

hsm_ability( 'product-get', array(
	'label'       => 'Get full product details',
	'description' => 'Full product record by id or sku: prices, stock, attributes, variations, images, categories, tags, brands, SEO fields, sales and reviews.',
	'readonly'    => true,
	'idempotent'  => true,
	'properties'  => array( 'id' => hsm_s( 'integer' ), 'sku' => hsm_s( 'string' ) ),
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$pid = hsm_product_id( $in );
		$p   = $pid ? wc_get_product( $pid ) : false;
		if ( ! $p || ! $p->get_id() ) {
			throw new Exception( 'Product not found.' );
		}
		if ( $p->is_type( 'variation' ) ) {
			$p = wc_get_product( $p->get_parent_id() );
		}
		return hsm_product_out( $p, true );
	},
) );

hsm_ability( 'products-search', array(
	'label'       => 'Search / list products',
	'description' => 'List products with filters. Returns compact rows (id, name, type, sku, prices, stock, categories). Use product-get for full detail.',
	'readonly'    => true,
	'idempotent'  => true,
	'properties'  => array(
		'search'       => hsm_s( 'string', 'Text in title/content/sku.' ),
		'category'     => hsm_s( 'string', 'Category name or slug.' ),
		'tag'          => hsm_s( 'string' ),
		'status'       => hsm_s( 'string', 'publish | draft | pending | private | any (default any)' ),
		'type'         => hsm_s( 'string' ),
		'stock_status' => hsm_s( 'string', 'instock | outofstock | onbackorder' ),
		'low_stock'    => hsm_s( 'integer', 'Only managed-stock products with quantity <= this.' ),
		'on_sale'      => hsm_s( 'boolean' ),
		'featured'     => hsm_s( 'boolean' ),
		'orderby'      => hsm_s( 'string', 'date | title | price | popularity | rating | id' ),
		'order'        => hsm_s( 'string', 'ASC | DESC' ),
		'page'         => hsm_s( 'integer' ),
		'per_page'     => hsm_s( 'integer', 'Max 100, default 20.' ),
	),
	'callback'    => function ( $in ) {
		hsm_require_wc();
		list( $per, $page ) = hsm_paginate( $in );
		$args = array( 'limit' => $per, 'page' => $page, 'paginate' => true, 'status' => ( $in['status'] ?? 'any' ) === 'any' ? array( 'publish', 'draft', 'pending', 'private' ) : $in['status'], 'orderby' => $in['orderby'] ?? 'date', 'order' => $in['order'] ?? 'DESC' );
		$matched_variation = null;
		if ( ! empty( $in['search'] ) ) {
			$sku_id = wc_get_product_id_by_sku( $in['search'] );
			if ( $sku_id ) {
				$sp = wc_get_product( $sku_id );
				if ( $sp && $sp->is_type( 'variation' ) ) {
					$matched_variation = $sku_id;
					$sku_id            = $sp->get_parent_id();
				}
				$args['include'] = array( $sku_id );
			} else {
				$args['s'] = $in['search'];
			}
		}
		if ( isset( $in['low_stock'] ) ) {
			// Stock lives on variations for variable products, so query both levels directly.
			global $wpdb;
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.ID, p.post_parent FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_manage_stock' AND ms.meta_value = 'yes'
				 JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_stock'
				 WHERE p.post_type IN ('product','product_variation') AND p.post_status IN ('publish','private','draft','pending')
				 AND CAST(st.meta_value AS SIGNED) <= %d ORDER BY CAST(st.meta_value AS SIGNED) ASC LIMIT 500", (int) $in['low_stock'] ) );
			$items = array();
			foreach ( $rows as $row ) {
				$x = wc_get_product( $row->ID );
				if ( ! $x || ( $row->post_parent && 'publish' !== get_post_status( $row->post_parent ) && 'publish' === ( $in['status'] ?? '' ) ) ) {
					continue;
				}
				$items[] = array( 'id' => $x->get_id(), 'parent_id' => $x->get_parent_id() ?: null, 'name' => $x->get_name(), 'sku' => $x->get_sku(), 'stock_quantity' => $x->get_stock_quantity(), 'stock_status' => $x->get_stock_status(), 'price' => $x->get_price() );
			}
			return array( 'total' => count( $items ), 'pages' => 1, 'page' => 1, 'low_stock_items' => $items, 'products' => $items );
		}
		if ( ! empty( $in['category'] ) ) {
			$t                = get_term_by( 'name', $in['category'], 'product_cat' ) ?: get_term_by( 'slug', $in['category'], 'product_cat' );
			$args['category'] = array( $t ? $t->slug : $in['category'] );
		}
		! empty( $in['tag'] ) && $args['tag'] = array( $in['tag'] );
		! empty( $in['type'] ) && $args['type'] = $in['type'];
		! empty( $in['stock_status'] ) && $args['stock_status'] = $in['stock_status'];
		isset( $in['featured'] ) && $args['featured'] = (bool) $in['featured'];
		if ( ! empty( $in['on_sale'] ) ) {
			$args['include'] = array_merge( $args['include'] ?? array(), wc_get_product_ids_on_sale() ?: array( 0 ) );
		}
		if ( isset( $in['low_stock'] ) ) {
			$args['manage_stock'] = true;
			$args['stock_quantity_lte'] = (int) $in['low_stock'];
		}
		add_filter( 'woocommerce_product_data_store_cpt_get_products_query', 'hsm_stock_qty_query', 10, 2 );
		$r = wc_get_products( $args );
		remove_filter( 'woocommerce_product_data_store_cpt_get_products_query', 'hsm_stock_qty_query', 10 );
		$out = array( 'total' => $r->total, 'pages' => $r->max_num_pages, 'page' => $page, 'products' => array_map( 'hsm_product_out', $r->products ) );
		$matched_variation && $out['matched_variation_id'] = $matched_variation;
		return $out;
	},
) );

function hsm_stock_qty_query( $query, $vars ) {
	if ( isset( $vars['stock_quantity_lte'] ) ) {
		$query['meta_query'][] = array( 'key' => '_stock', 'value' => $vars['stock_quantity_lte'], 'compare' => '<=', 'type' => 'NUMERIC' );
	}
	return $query;
}

hsm_ability( 'products-bulk-update', array(
	'label'       => 'Bulk update products (prices, stock, status, categories, sale)',
	'description' => 'Change many products at once. Either list explicit "items" [{id|sku, regular_price, sale_price, date_on_sale_from, date_on_sale_to, stock_quantity, stock_status, status, manage_stock, categories}] (variation SKUs work), or give a "filter" {category, tag, search, stock_status, ids} plus "changes" {price_adjust_percent, price_adjust_amount, sale_percent (sale = regular × (1 − pct/100)), clear_sale, set_status, set_stock_status, set_stock_quantity, add_categories, remove_categories, add_tags, date_on_sale_from, date_on_sale_to}. Always run with dry_run=true first and show the diff. Everything is validated before anything is written; large or deep-discount changes need a confirm_token; every change is undoable via the undo tool.',
	'properties'  => array(
		'items'   => hsm_s( 'array' ),
		'filter'  => hsm_s( 'object' ),
		'changes' => hsm_s( 'object' ),
		'dry_run' => hsm_s( 'boolean' ),
	),
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$dry  = ! empty( $in['dry_run'] );
		$plan = array(); // Each: [product, props, label, parent_id|null].
		$errs = array();
		$dec  = wc_get_price_decimals();
		$allowed_item_keys = array( 'id', 'sku', 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to', 'stock_quantity', 'stock_status', 'status', 'manage_stock', 'categories', 'low_stock_amount', 'backorders' );
		foreach ( (array) ( $in['items'] ?? array() ) as $it ) {
			$it = (array) $it;
			$extra = array_diff( array_keys( $it ), $allowed_item_keys );
			if ( $extra ) {
				throw new HSM_Blocked( 'Unsupported item fields: ' . implode( ', ', $extra ) . '. Use product-upsert for other fields.' );
			}
			$p = wc_get_product( hsm_product_id( $it ) );
			if ( ! $p ) {
				$errs[] = array( 'ref' => $it['sku'] ?? $it['id'] ?? null, 'error' => 'not found' );
				continue;
			}
			$props = $it;
			unset( $props['id'] );
			if ( isset( $props['sku'] ) && $props['sku'] === $p->get_sku() ) {
				unset( $props['sku'] );
			}
			if ( isset( $props['sku'] ) ) {
				throw new HSM_Blocked( 'Changing SKUs in bulk is not supported.' );
			}
			if ( $p->is_type( 'variable' ) && ( isset( $props['regular_price'] ) || isset( $props['sale_price'] ) ) ) {
				throw new HSM_Blocked( $p->get_name() . ' is a variable product: set prices on its variation SKUs.' );
			}
			$plan[] = array( $p, $props );
		}
		if ( ! empty( $in['filter'] ) || ! empty( $in['changes'] ) ) {
			$f = (array) ( $in['filter'] ?? array() );
			$c = (array) ( $in['changes'] ?? array() );
			if ( empty( $f ) ) {
				throw new HSM_Blocked( 'A filter is required with "changes" (refusing to change the whole catalog implicitly). Use filter {"all": true} to mean every product.' );
			}
			$known = array( 'price_adjust_percent', 'price_adjust_amount', 'sale_percent', 'clear_sale', 'set_status', 'set_stock_status', 'set_stock_quantity', 'add_categories', 'remove_categories', 'add_tags', 'date_on_sale_from', 'date_on_sale_to' );
			$bad   = array_diff( array_keys( $c ), $known );
			if ( $bad ) {
				throw new HSM_Blocked( 'Unknown changes: ' . implode( ', ', $bad ) );
			}
			if ( isset( $c['sale_percent'] ) && ( ! is_numeric( $c['sale_percent'] ) || $c['sale_percent'] <= 0 || $c['sale_percent'] >= 100 ) ) {
				throw new HSM_Blocked( 'sale_percent must be between 0 and 100 (exclusive).' );
			}
			if ( isset( $c['price_adjust_percent'] ) && ( ! is_numeric( $c['price_adjust_percent'] ) || $c['price_adjust_percent'] <= -100 ) ) {
				throw new HSM_Blocked( 'price_adjust_percent must be greater than -100.' );
			}
			$args = array( 'limit' => -1, 'status' => array( 'publish', 'draft', 'private', 'pending' ) );
			if ( ! empty( $f['category'] ) ) {
				$t = get_term_by( 'name', $f['category'], 'product_cat' ) ?: get_term_by( 'slug', $f['category'], 'product_cat' );
				if ( ! $t ) {
					throw new HSM_Blocked( "Category '{$f['category']}' not found." );
				}
				$args['category'] = array( $t->slug );
			}
			! empty( $f['tag'] ) && $args['tag'] = array( $f['tag'] );
			! empty( $f['search'] ) && $args['s'] = $f['search'];
			! empty( $f['stock_status'] ) && $args['stock_status'] = $f['stock_status'];
			! empty( $f['ids'] ) && $args['include'] = array_map( 'intval', (array) $f['ids'] );
			foreach ( wc_get_products( $args ) as $p ) {
				$targets = $p->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $p->get_children() ) ) : array( $p );
				$parent_props = array();
				isset( $c['set_status'] ) && $parent_props['status'] = $c['set_status'];
				isset( $c['add_categories'] ) && $parent_props['categories'] = array_unique( array_merge( $p->get_category_ids(), hsm_terms( $c['add_categories'], 'product_cat', ! $dry ) ) );
				isset( $c['remove_categories'] ) && $parent_props['categories'] = array_values( array_diff( $parent_props['categories'] ?? $p->get_category_ids(), hsm_terms( $c['remove_categories'], 'product_cat', false ) ) );
				isset( $c['add_tags'] ) && $parent_props['tag_names'] = $c['add_tags'];
				foreach ( $targets as $t ) {
					$props = array();
					$reg   = (float) $t->get_regular_price();
					if ( isset( $c['price_adjust_percent'] ) && $reg ) {
						$reg                    = round( $reg * ( 1 + $c['price_adjust_percent'] / 100 ), $dec );
						$props['regular_price'] = (string) $reg;
					}
					if ( isset( $c['price_adjust_amount'] ) && $reg ) {
						$reg                    = round( $reg + $c['price_adjust_amount'], $dec );
						$props['regular_price'] = (string) $reg;
					}
					if ( isset( $c['sale_percent'] ) && $reg ) {
						$props['sale_price'] = (string) round( $reg * ( 1 - $c['sale_percent'] / 100 ), $dec );
						isset( $c['date_on_sale_from'] ) && $props['date_on_sale_from'] = $c['date_on_sale_from'];
						isset( $c['date_on_sale_to'] ) && $props['date_on_sale_to'] = $c['date_on_sale_to'];
					}
					if ( ! empty( $c['clear_sale'] ) ) {
						$props += array( 'sale_price' => '', 'date_on_sale_from' => '', 'date_on_sale_to' => '' );
					}
					isset( $c['set_stock_quantity'] ) && $props += array( 'stock_quantity' => $c['set_stock_quantity'], 'manage_stock' => true );
					isset( $c['set_stock_status'] ) && $props['stock_status'] = $c['set_stock_status'];
					if ( $t->get_id() === $p->get_id() ) {
						$props += $parent_props;
						$parent_props = array();
					}
					$props && $plan[] = array( $t, $props );
				}
				if ( $parent_props ) {
					$plan[] = array( $p, $parent_props );
				}
			}
		}
		// ---- Validate the whole plan before writing anything.
		$max = (int) hsm_guard( 'max_bulk_items' );
		if ( count( $plan ) > $max ) {
			throw new HSM_Blocked( sprintf( 'This would change %d products/variations; the limit per call is %d (guard max_bulk_items). Split it up.', count( $plan ), $max ) );
		}
		$reasons = array();
		$preview = array();
		foreach ( $plan as $row ) {
			list( $t, $props ) = $row;
			$label = ( $t->get_sku() ?: '#' . $t->get_id() ) . ' ' . $t->get_name();
			if ( isset( $props['status'] ) && ! in_array( $props['status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
				throw new HSM_Blocked( "Invalid status {$props['status']}." );
			}
			if ( isset( $props['stock_status'] ) && ! in_array( $props['stock_status'], array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
				throw new HSM_Blocked( "Invalid stock_status {$props['stock_status']}." );
			}
			hsm_check_stock( $label, $props['stock_quantity'] ?? null );
			if ( array_key_exists( 'regular_price', $props ) || array_key_exists( 'sale_price', $props ) ) {
				$nr = array_key_exists( 'regular_price', $props ) ? $props['regular_price'] : $t->get_regular_price();
				$ns = array_key_exists( 'sale_price', $props ) ? $props['sale_price'] : $t->get_sale_price();
				$r  = hsm_check_price( $label, $t->get_regular_price(), $nr, $ns, $t->get_price(), $t->get_id() );
				( (string) $nr !== (string) $t->get_regular_price() || (string) $ns !== (string) $t->get_sale_price() ) && $reprice_n = ( $reprice_n ?? 0 ) + 1;
				$r && $reasons[] = $r;
			}
			$diff = array();
			foreach ( $props as $k => $v ) {
				$get  = 'categories' === $k ? 'get_category_ids' : ( 'tag_names' === $k ? null : 'get_' . $k );
				$from = $get && method_exists( $t, $get ) ? $t->$get() : null;
				$from = $from instanceof WC_DateTime ? $from->date( 'Y-m-d' ) : $from;
				$diff[ $k ] = array( 'from' => $from, 'to' => $v );
			}
			$preview[] = array( 'id' => $t->get_id(), 'item' => $label, 'changes' => $diff );
		}
		if ( $dry ) {
			return array( 'dry_run' => true, 'count' => count( $plan ), 'would_need_confirmation' => ( $reasons || count( $plan ) > hsm_guard( 'confirm_bulk_over' ) ), 'warnings' => $reasons, 'not_found' => $errs, 'results' => $preview );
		}
		hsm_check_price_changes( $reprice_n ?? 0 );
		if ( count( $plan ) > (int) hsm_guard( 'confirm_bulk_over' ) ) {
			array_unshift( $reasons, count( $plan ) . ' products/variations will change' );
		}
		if ( $plan ) {
			// Small, ordinary bulk edits also require explicit owner approval; the size threshold is not an approval bypass.
			$review_reason = $reasons ? implode( '; ', array_slice( $reasons, 0, 10 ) ) . ( count( $reasons ) > 10 ? '; …' : '' ) : count( $plan ) . ' product(s)/variation(s) will be updated';
			hsm_confirm( $in, $review_reason, array( 'count' => count( $plan ), 'target_ids' => array_values( array_unique( array_map( function ( $row ) { return (int) $row[0]->get_id(); }, $plan ) ) ), 'candidate_ids' => hsm_review_bulk_target_ids( $in ), 'first_changes' => array_slice( $preview, 0, 15 ) ) );
		}
		// ---- Apply.
		$parents = array();
		foreach ( $plan as $row ) {
			list( $t, $props ) = $row;
			hsm_snap_product( $t->is_type( 'variation' ) ? $t->get_parent_id() : $t->get_id() );
			$t = wc_get_product( $t->get_id() ); // Fresh copy.
			if ( isset( $props['categories'] ) ) {
				$t->set_category_ids( hsm_terms( $props['categories'], 'product_cat' ) );
				unset( $props['categories'] );
			}
			if ( isset( $props['tag_names'] ) ) {
				$t->set_tag_ids( array_unique( array_merge( $t->get_tag_ids(), hsm_terms( $props['tag_names'], 'product_tag' ) ) ) );
				unset( $props['tag_names'] );
			}
			hsm_apply_common( $t, $props );
			$t->save();
			$t->is_type( 'variation' ) && $parents[ $t->get_parent_id() ] = true;
		}
		foreach ( array_keys( $parents ) as $pid ) {
			WC_Product_Variable::sync( $pid );
			wc_delete_product_transients( $pid );
		}
		return array( 'dry_run' => false, 'count' => count( $plan ), 'not_found' => $errs, 'results' => $preview );
	},
) );

hsm_ability( 'product-delete', array(
	'label'       => 'Delete or trash a product',
	'description' => 'Moves a product (by id or sku) to trash; force=true deletes permanently including variations.',
	'destructive' => true,
	'properties'  => array( 'id' => hsm_s( 'integer' ), 'sku' => hsm_s( 'string' ), 'force' => hsm_s( 'boolean' ) ),
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$p = wc_get_product( hsm_product_id( $in ) );
		if ( ! $p ) {
			throw new Exception( 'Product not found.' );
		}
		if ( $p->is_type( 'variation' ) ) {
			throw new Exception( 'That SKU is a variation; remove it via product-upsert on the parent with delete_missing_variations, or set its status to private.' );
		}
		$force = ! empty( $in['force'] );
		hsm_confirm( $in, ( $force ? 'PERMANENTLY delete' : 'Move to trash' ) . ' product "' . $p->get_name() . '"', array( 'id' => $p->get_id(), 'sku' => $p->get_sku(), 'status' => $p->get_status(), 'total_sales' => $p->get_total_sales(), 'variations' => count( $p->get_children() ), 'undoable' => ! $force ) );
		if ( ! $force ) {
			hsm_snap_product( $p->get_id() );
		}
		if ( $force && $p->is_type( 'variable' ) ) {
			foreach ( $p->get_children() as $c ) {
				wc_get_product( $c ) && wc_get_product( $c )->delete( true );
			}
		}
		$p->delete( $force );
		return array( 'id' => $p->get_id(), 'deleted' => $force ? 'permanently' : 'trashed' );
	},
) );

/* ---------------------------------------------------------------------------
 * Categories / tags / brands
 * ------------------------------------------------------------------------- */

hsm_ability( 'taxonomy-manage', array(
	'label'       => 'Manage product categories, tags, brands, shipping classes',
	'description' => 'action=list|upsert|delete on taxonomy product_cat (default), product_tag, product_brand or product_shipping_class. upsert takes {name, id?, slug?, parent?, description?, image?, seo?:{title,description}}.',
	'properties'  => array(
		'action'      => hsm_s( 'string', 'list | upsert | delete', array( 'enum' => array( 'list', 'upsert', 'delete' ) ) ),
		'taxonomy'    => hsm_s( 'string', 'product_cat | product_tag | product_brand | product_shipping_class' ),
		'id'          => hsm_s( 'integer' ),
		'name'        => hsm_s( 'string' ),
		'slug'        => hsm_s( 'string' ),
		'parent'      => hsm_s( array( 'string', 'integer' ), 'Parent name or id.' ),
		'description' => hsm_s( 'string' ),
		'image'       => hsm_s( array( 'string', 'integer' ), 'URL/path/attachment id (categories).' ),
		'seo'         => hsm_s( 'object', '{title, description, noindex}' ),
	),
	'required'    => array( 'action' ),
	'callback'    => function ( $in ) {
		$tax = $in['taxonomy'] ?? 'product_cat';
		if ( ! taxonomy_exists( $tax ) ) {
			throw new Exception( "Taxonomy $tax does not exist." );
		}
		if ( 'list' === $in['action'] ) {
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
			return array_map( function ( $t ) {
				return array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'parent' => $t->parent, 'count' => $t->count, 'link' => get_term_link( $t ) );
			}, $terms );
		}
		if ( 'delete' === $in['action'] ) {
			$id = $in['id'] ?? ( get_term_by( 'name', $in['name'] ?? '', $tax )->term_id ?? 0 );
			$t  = get_term( (int) $id, $tax );
			if ( ! $t || is_wp_error( $t ) ) {
				throw new Exception( 'Term not found.' );
			}
			hsm_confirm( $in, "Delete $tax term \"{$t->name}\" (used by {$t->count} items)", array( 'id' => $t->term_id, 'name' => $t->name, 'count' => $t->count ) );
			$r  = wp_delete_term( (int) $id, $tax );
			return array( 'deleted' => (bool) $r && ! is_wp_error( $r ) );
		}
		$args = array();
		isset( $in['slug'] ) && $args['slug'] = $in['slug'];
		isset( $in['description'] ) && $args['description'] = $in['description'];
		if ( empty( $in['id'] ) && ( empty( $in['name'] ) || ! is_string( $in['name'] ) ) ) {
			throw new Exception( 'name is required.' );
		}
		isset( $in['name'] ) && $in['name'] = sanitize_text_field( $in['name'] );
		isset( $args['description'] ) && $args['description'] = wp_kses_post( $args['description'] );
		if ( isset( $in['parent'] ) ) {
			$args['parent'] = is_numeric( $in['parent'] ) ? (int) $in['parent'] : ( hsm_terms( array( $in['parent'] ), $tax )[0] ?? 0 );
		}
		$id = $in['id'] ?? ( get_term_by( 'name', $in['name'] ?? '', $tax )->term_id ?? 0 );
		if ( $id ) {
			isset( $in['name'] ) && $args['name'] = $in['name'];
			$r = wp_update_term( (int) $id, $tax, $args );
		} else {
			$r = wp_insert_term( $in['name'], $tax, $args );
		}
		if ( is_wp_error( $r ) ) {
			throw new Exception( $r->get_error_message() );
		}
		$tid = (int) $r['term_id'];
		if ( isset( $in['image'] ) && 'product_cat' === $tax ) {
			update_term_meta( $tid, 'thumbnail_id', hsm_media( $in['image'] ) );
		}
		foreach ( (array) ( $in['seo'] ?? array() ) as $k => $v ) {
			if ( in_array( $k, array( 'title', 'description', 'noindex' ), true ) ) {
				hsm_undo_add( array( 'type' => 'term_meta', 'id' => $tid, 'key' => '_hsm_seo_' . $k, 'old' => get_term_meta( $tid, '_hsm_seo_' . $k, true ) ) );
				update_term_meta( $tid, '_hsm_seo_' . $k, 'noindex' === $k ? ( filter_var( $v, FILTER_VALIDATE_BOOLEAN ) ? 1 : '' ) : sanitize_text_field( $v ) );
			}
		}
		$t = get_term( $tid, $tax );
		return array( 'id' => $tid, 'name' => $t->name, 'slug' => $t->slug, 'parent' => $t->parent, 'link' => get_term_link( $t ) );
	},
) );

/* ---------------------------------------------------------------------------
 * Coupons
 * ------------------------------------------------------------------------- */

hsm_ability( 'coupon-manage', array(
	'label'       => 'Create, update, list or delete coupons',
	'description' => 'action=list|upsert|delete. upsert by code: {code, discount_type: percent|fixed_cart|fixed_product, amount, expires (YYYY-MM-DD), free_shipping, minimum_amount, maximum_amount, individual_use, exclude_sale_items, usage_limit, usage_limit_per_user, limit_usage_to_x_items, product_skus, exclude_product_skus, categories, exclude_categories, email_restrictions, description}.',
	'properties'  => array(
		'action' => hsm_s( 'string', 'list | upsert | delete', array( 'enum' => array( 'list', 'upsert', 'delete' ) ) ),
		'code'   => hsm_s( 'string' ),
		'discount_type' => hsm_s( 'string' ), 'amount' => hsm_s( array( 'string', 'number' ) ), 'expires' => hsm_s( 'string' ),
		'free_shipping' => hsm_s( 'boolean' ), 'minimum_amount' => hsm_s( array( 'string', 'number' ) ), 'maximum_amount' => hsm_s( array( 'string', 'number' ) ),
		'individual_use' => hsm_s( 'boolean' ), 'exclude_sale_items' => hsm_s( 'boolean' ), 'usage_limit' => hsm_s( 'integer' ),
		'usage_limit_per_user' => hsm_s( 'integer' ), 'limit_usage_to_x_items' => hsm_s( 'integer' ),
		'product_skus' => hsm_s( 'array' ), 'exclude_product_skus' => hsm_s( 'array' ), 'categories' => hsm_s( 'array' ), 'exclude_categories' => hsm_s( 'array' ),
		'email_restrictions' => hsm_s( 'array' ), 'description' => hsm_s( 'string' ),
	),
	'required'    => array( 'action' ),
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$out = function ( $c ) {
			return array(
				'id' => $c->get_id(), 'code' => $c->get_code(), 'type' => $c->get_discount_type(), 'amount' => $c->get_amount(),
				'expires' => $c->get_date_expires() ? $c->get_date_expires()->date( 'Y-m-d' ) : null, 'usage_count' => $c->get_usage_count(),
				'usage_limit' => $c->get_usage_limit(), 'free_shipping' => $c->get_free_shipping(), 'minimum_amount' => $c->get_minimum_amount(),
				'individual_use' => $c->get_individual_use(), 'status' => get_post_status( $c->get_id() ),
			);
		};
		if ( 'list' === $in['action'] ) {
			$ids = get_posts( array( 'post_type' => 'shop_coupon', 'numberposts' => 200, 'fields' => 'ids', 'post_status' => array( 'publish', 'draft', 'future' ) ) );
			return array_map( function ( $id ) use ( $out ) { return $out( new WC_Coupon( $id ) ); }, $ids );
		}
		if ( empty( $in['code'] ) ) {
			throw new Exception( 'code is required.' );
		}
		$in['code'] = wc_format_coupon_code( $in['code'] );
		if ( '' === $in['code'] || mb_strlen( $in['code'] ) > 50 ) {
			throw new Exception( 'code must be 1-50 characters.' );
		}
		$cid = wc_get_coupon_id_by_code( $in['code'] );
		if ( 'delete' === $in['action'] ) {
			if ( ! $cid ) {
				throw new Exception( 'Coupon not found.' );
			}
			$old = new WC_Coupon( $cid );
			hsm_confirm( $in, 'Delete coupon ' . $in['code'], array( 'code' => $in['code'], 'used' => $old->get_usage_count() ) );
			hsm_undo_add( array( 'type' => 'coupon_deleted', 'state' => hsm_coupon_state( $old ) ) );
			wp_delete_post( $cid, true );
			return array( 'deleted' => $in['code'] );
		}
		$type   = $in['discount_type'] ?? ( $cid ? ( new WC_Coupon( $cid ) )->get_discount_type() : 'fixed_cart' );
		$amount = $in['amount'] ?? ( $cid ? ( new WC_Coupon( $cid ) )->get_amount() : 0 );
		if ( ! in_array( $type, array( 'percent', 'fixed_cart', 'fixed_product' ), true ) ) {
			throw new HSM_Blocked( 'discount_type must be percent, fixed_cart or fixed_product.' );
		}
		if ( ! is_numeric( $amount ) || $amount < 0 ) {
			throw new HSM_Blocked( 'amount must be a non-negative number.' );
		}
		if ( ! empty( $in['expires'] ) && ( false === strtotime( $in['expires'] ) ) ) {
			throw new HSM_Blocked( 'expires must be a date (YYYY-MM-DD).' );
		}
		foreach ( array( 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items' ) as $k ) {
			if ( isset( $in[ $k ] ) && ( ! is_numeric( $in[ $k ] ) || $in[ $k ] < 0 ) ) {
				throw new HSM_Blocked( "$k must be a non-negative integer." );
			}
		}
		$why = array();
		if ( 'percent' === $type ) {
			if ( $amount > hsm_guard( 'hard_max_coupon_percent' ) ) {
				throw new HSM_Blocked( "Percent coupons above " . hsm_guard( 'hard_max_coupon_percent' ) . '% are not allowed (guard hard_max_coupon_percent).' );
			}
			$amount > hsm_guard( 'max_coupon_percent' ) && $why[] = "$amount% off is above the " . hsm_guard( 'max_coupon_percent' ) . '% threshold';
		} elseif ( $amount > hsm_guard( 'max_coupon_fixed' ) ) {
			$why[] = "fixed discount $amount is above the " . hsm_guard( 'max_coupon_fixed' ) . ' threshold';
		}
		$unlimited = empty( $in['usage_limit'] ) && ( ! $cid || ! ( new WC_Coupon( $cid ) )->get_usage_limit() );
		if ( $why && $unlimited ) {
			$why[] = 'no total usage_limit';
		}
		if ( ! empty( $in['free_shipping'] ) && empty( $in['minimum_amount'] ) ) {
			$why[] = 'free shipping with no minimum order';
		}
		if ( $why ) {
			hsm_confirm( $in, 'Coupon ' . $in['code'] . ': ' . implode( '; ', $why ), array( 'code' => $in['code'], 'type' => $type, 'amount' => $amount, 'usage_limit' => $in['usage_limit'] ?? null, 'expires' => $in['expires'] ?? null ) );
		}
		hsm_undo_add( array( 'type' => 'coupon', 'id' => $cid, 'state' => $cid ? hsm_coupon_state( new WC_Coupon( $cid ) ) : null ) );
		$c = new WC_Coupon( $cid ?: 0 );
		$c->set_code( $in['code'] );
		$sku_ids = function ( $l ) { return array_values( array_filter( array_map( function ( $x ) { return is_numeric( $x ) ? (int) $x : wc_get_product_id_by_sku( $x ); }, (array) $l ) ) ); };
		$set = array(
			'discount_type' => 'set_discount_type', 'amount' => 'set_amount', 'expires' => 'set_date_expires', 'free_shipping' => 'set_free_shipping',
			'minimum_amount' => 'set_minimum_amount', 'maximum_amount' => 'set_maximum_amount', 'individual_use' => 'set_individual_use',
			'exclude_sale_items' => 'set_exclude_sale_items', 'usage_limit' => 'set_usage_limit', 'usage_limit_per_user' => 'set_usage_limit_per_user',
			'limit_usage_to_x_items' => 'set_limit_usage_to_x_items', 'email_restrictions' => 'set_email_restrictions', 'description' => 'set_description',
		);
		foreach ( $set as $k => $m ) {
			array_key_exists( $k, $in ) && $c->$m( $in[ $k ] );
		}
		isset( $in['product_skus'] ) && $c->set_product_ids( $sku_ids( $in['product_skus'] ) );
		isset( $in['exclude_product_skus'] ) && $c->set_excluded_product_ids( $sku_ids( $in['exclude_product_skus'] ) );
		isset( $in['categories'] ) && $c->set_product_categories( hsm_terms( $in['categories'], 'product_cat', false ) );
		isset( $in['exclude_categories'] ) && $c->set_excluded_product_categories( hsm_terms( $in['exclude_categories'], 'product_cat', false ) );
		isset( $in['description'] ) && $c->set_description( sanitize_text_field( $in['description'] ) );
		$c->save();
		if ( ! $cid ) {
			$GLOBALS['hsm_undo'][ count( $GLOBALS['hsm_undo'] ) - 1 ]['id'] = $c->get_id();
		}
		return array( 'action' => $cid ? 'updated' : 'created', 'id' => $c->get_id(), 'coupon' => $out( $c ) );
	},
) );

/* ---------------------------------------------------------------------------
 * Shipping & tax
 * ------------------------------------------------------------------------- */

hsm_ability( 'shipping-manage', array(
	'label'       => 'Configure shipping zones and methods',
	'description' => 'action=list shows zones, regions and methods with costs. action=upsert_zone {zone_name, regions:["US","US:CA","CA", "postcode:90210", "continent:EU"], methods:[{type: flat_rate|free_shipping|local_pickup, title?, cost?, min_amount? (free shipping), requires?: "min_amount"|"coupon"|"either"|"both", class_costs?:{ClassName: cost}}], replace_methods?: bool}. action=delete_zone {zone_name|zone_id}. action=shipping_classes lists classes.',
	'properties'  => array(
		'action'          => hsm_s( 'string', 'list | upsert_zone | delete_zone | shipping_classes', array( 'enum' => array( 'list', 'upsert_zone', 'delete_zone', 'shipping_classes' ) ) ),
		'zone_id'         => hsm_s( 'integer' ),
		'zone_name'       => hsm_s( 'string' ),
		'regions'         => hsm_s( 'array' ),
		'methods'         => hsm_s( 'array' ),
		'replace_methods' => hsm_s( 'boolean' ),
	),
	'required'    => array( 'action' ),
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$describe = function ( $zone ) {
			$methods = array();
			foreach ( $zone->get_shipping_methods() as $m ) {
				$methods[] = array( 'instance_id' => $m->instance_id, 'type' => $m->id, 'title' => $m->get_title(), 'enabled' => 'yes' === $m->enabled, 'cost' => $m->get_option( 'cost' ), 'min_amount' => $m->get_option( 'min_amount' ), 'requires' => $m->get_option( 'requires' ) );
			}
			return array( 'id' => $zone->get_id(), 'name' => $zone->get_zone_name(), 'regions' => array_map( function ( $l ) { return $l->type . ':' . $l->code; }, $zone->get_zone_locations() ), 'methods' => $methods );
		};
		$find = function () use ( $in ) {
			if ( ! empty( $in['zone_id'] ) ) {
				return new WC_Shipping_Zone( (int) $in['zone_id'] );
			}
			foreach ( WC_Shipping_Zones::get_zones() as $z ) {
				if ( 0 === strcasecmp( $z['zone_name'], $in['zone_name'] ?? '' ) ) {
					return new WC_Shipping_Zone( $z['id'] );
				}
			}
			return null;
		};
		switch ( $in['action'] ) {
			case 'list':
				$zones = array_map( function ( $z ) use ( $describe ) { return $describe( new WC_Shipping_Zone( $z['id'] ) ); }, WC_Shipping_Zones::get_zones() );
				$zones[] = $describe( new WC_Shipping_Zone( 0 ) );
				return $zones;
			case 'shipping_classes':
				return array_map( function ( $t ) { return array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count ); }, WC()->shipping()->get_shipping_classes() );
			case 'delete_zone':
				$z = $find();
				if ( ! $z || ! $z->get_id() ) {
					throw new Exception( 'Zone not found.' );
				}
				hsm_confirm( $in, 'Delete shipping zone ' . $z->get_zone_name() . ' — customers there may no longer be able to check out', $describe( $z ) );
				$z->delete();
				return array( 'deleted' => true );
			case 'upsert_zone':
				$why = array();
				foreach ( (array) ( $in['methods'] ?? array() ) as $mi ) {
					if ( ! in_array( $mi['type'] ?? '', array( 'flat_rate', 'free_shipping', 'local_pickup' ), true ) ) {
						throw new HSM_Blocked( 'Shipping method type must be flat_rate, free_shipping or local_pickup.' );
					}
					if ( isset( $mi['cost'] ) && ! preg_match( '/^[0-9.\s\[\]\*\+\-\/a-z_=\"\'()]*$/i', (string) $mi['cost'] ) ) {
						throw new HSM_Blocked( 'Invalid shipping cost expression.' );
					}
					if ( isset( $mi['cost'] ) && is_numeric( $mi['cost'] ) && $mi['cost'] < 0 ) {
						throw new HSM_Blocked( 'Shipping cost cannot be negative.' );
					}
					if ( 'free_shipping' === $mi['type'] && empty( $mi['min_amount'] ) && empty( $mi['requires'] ) ) {
						$why[] = 'free shipping on every order in this zone';
					}
					if ( 'flat_rate' === $mi['type'] && isset( $mi['cost'] ) && 0.0 === (float) $mi['cost'] ) {
						$why[] = 'flat rate of 0';
					}
				}
				! empty( $in['replace_methods'] ) && $why[] = 'replaces all existing methods in the zone';
				$why && hsm_confirm( $in, 'Shipping change: ' . implode( '; ', $why ), array( 'zone' => $in['zone_name'] ?? $in['zone_id'] ?? null, 'regions' => $in['regions'] ?? null, 'methods' => $in['methods'] ?? null ) );
				$z = $find() ?: new WC_Shipping_Zone();
				! empty( $in['zone_name'] ) && $z->set_zone_name( $in['zone_name'] );
				if ( isset( $in['regions'] ) ) {
					$locs = array();
					foreach ( (array) $in['regions'] as $r ) {
						if ( preg_match( '/^(postcode|continent|state|country):(.+)$/', $r, $m ) ) {
							$locs[] = array( 'type' => $m[1], 'code' => $m[2] );
						} elseif ( strpos( $r, ':' ) !== false ) {
							$locs[] = array( 'type' => 'state', 'code' => $r );
						} else {
							$locs[] = array( 'type' => 'country', 'code' => $r );
						}
					}
					$z->set_locations( $locs );
				}
				$z->save();
				if ( isset( $in['methods'] ) ) {
					if ( ! empty( $in['replace_methods'] ) ) {
						foreach ( $z->get_shipping_methods() as $m ) {
							$z->delete_shipping_method( $m->instance_id );
						}
					}
					foreach ( (array) $in['methods'] as $mi ) {
						$iid = 0;
						foreach ( $z->get_shipping_methods() as $m ) {
							if ( $m->id === $mi['type'] ) {
								$iid = $m->instance_id;
							}
						}
						$iid  = $iid ?: $z->add_shipping_method( $mi['type'] );
						$opts = get_option( "woocommerce_{$mi['type']}_{$iid}_settings", array() );
						isset( $mi['title'] ) && $opts['title'] = $mi['title'];
						isset( $mi['cost'] ) && $opts['cost'] = (string) $mi['cost'];
						isset( $mi['min_amount'] ) && $opts['min_amount'] = (string) $mi['min_amount'];
						if ( 'free_shipping' === $mi['type'] ) {
							$opts['requires'] = $mi['requires'] ?? ( isset( $mi['min_amount'] ) ? 'min_amount' : ( $opts['requires'] ?? '' ) );
						}
						foreach ( (array) ( $mi['class_costs'] ?? array() ) as $cls => $cost ) {
							$cid = hsm_terms( array( $cls ), 'product_shipping_class' )[0] ?? 0;
							$cid && $opts[ 'class_cost_' . $cid ] = (string) $cost;
						}
						$opts['tax_status'] = $opts['tax_status'] ?? 'taxable';
						update_option( "woocommerce_{$mi['type']}_{$iid}_settings", $opts );
					}
				}
				WC_Cache_Helper::get_transient_version( 'shipping', true );
				return $describe( new WC_Shipping_Zone( $z->get_id() ) );
		}
	},
) );

hsm_ability( 'tax-manage', array(
	'label'       => 'Manage tax rates',
	'description' => 'action=list|add|delete|clear_class. add: {rates:[{country:"US", state:"CA", postcode:"", city:"", rate:"7.25", name:"CA Tax", priority:1, compound:false, shipping:true, class:""}]}. Enable taxes with store-settings calc_taxes=true. For US sales-tax automation use a tax service; this manages manual rates.',
	'properties'  => array(
		'action'  => hsm_s( 'string', 'list | add | delete | clear_class', array( 'enum' => array( 'list', 'add', 'delete', 'clear_class' ) ) ),
		'rates'   => hsm_s( 'array' ),
		'rate_id' => hsm_s( 'integer' ),
		'class'   => hsm_s( 'string', 'Tax class slug ("" = standard).' ),
	),
	'required'    => array( 'action' ),
	'callback'    => function ( $in ) {
		hsm_require_wc();
		global $wpdb;
		$class = $in['class'] ?? '';
		if ( 'list' === $in['action'] ) {
			$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_order", ARRAY_A );
			return array( 'taxes_enabled' => wc_tax_enabled(), 'classes' => WC_Tax::get_tax_class_slugs(), 'rates' => $rows );
		}
		if ( 'delete' === $in['action'] ) {
			$row = WC_Tax::_get_tax_rate( (int) ( $in['rate_id'] ?? 0 ) );
			if ( ! $row ) {
				throw new Exception( 'Tax rate not found.' );
			}
			hsm_confirm( $in, 'Delete tax rate ' . $row['tax_rate_name'], $row );
			WC_Tax::_delete_tax_rate( (int) $in['rate_id'] );
			return array( 'deleted' => (int) $in['rate_id'] );
		}
		if ( 'clear_class' === $in['action'] ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class=%s", $class ) );
			hsm_confirm( $in, 'Delete all ' . count( $ids ) . ' tax rates in class "' . ( $class ?: 'standard' ) . '"', array( 'count' => count( $ids ) ) );
			foreach ( $ids as $id ) {
				WC_Tax::_delete_tax_rate( $id );
			}
			return array( 'deleted' => count( $ids ) );
		}
		$added = array();
		foreach ( (array) ( $in['rates'] ?? array() ) as $r ) {
			if ( ! isset( $r['rate'] ) || ! is_numeric( $r['rate'] ) || $r['rate'] < 0 || $r['rate'] > 30 ) {
				throw new HSM_Blocked( 'Tax rate must be a number between 0 and 30.' );
			}
			if ( empty( $r['country'] ) || ! preg_match( '/^[A-Za-z]{2}$/', $r['country'] ) ) {
				throw new HSM_Blocked( 'Each tax rate needs a 2-letter country code.' );
			}
		}
		foreach ( (array) ( $in['rates'] ?? array() ) as $r ) {
			$id = WC_Tax::_insert_tax_rate( array(
				'tax_rate_country'  => strtoupper( $r['country'] ?? '' ),
				'tax_rate_state'    => strtoupper( $r['state'] ?? '' ),
				'tax_rate'          => (string) ( $r['rate'] ?? '0' ),
				'tax_rate_name'     => sanitize_text_field( $r['name'] ?? 'Tax' ),
				'tax_rate_priority' => (int) ( $r['priority'] ?? 1 ),
				'tax_rate_compound' => ! empty( $r['compound'] ) ? 1 : 0,
				'tax_rate_shipping' => isset( $r['shipping'] ) ? (int) (bool) $r['shipping'] : 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => $r['class'] ?? $class,
			) );
			if ( ! empty( $r['postcode'] ) ) {
				WC_Tax::_update_tax_rate_postcodes( $id, $r['postcode'] );
			}
			if ( ! empty( $r['city'] ) ) {
				WC_Tax::_update_tax_rate_cities( $id, $r['city'] );
			}
			$added[] = $id;
		}
		return array( 'added_rate_ids' => $added );
	},
) );
