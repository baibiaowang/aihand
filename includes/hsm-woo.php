<?php
/**
 * WooCommerce-specific guards and utilities — AI Hand only (product/SEO/media helpers,
 * price / refund / stock guard rails, product-state undo snapshots).
 */
defined( 'ABSPATH' ) || exit;

/** Resolve a product id from id or sku. */
function hsm_product_id( $in ) {
	if ( ! empty( $in['id'] ) ) {
		$id = is_numeric( $in['id'] ) ? (int) $in['id'] : 0;
		return $id > 0 ? $id : 0;
	}
	if ( ! empty( $in['sku'] ) && is_string( $in['sku'] ) ) {
		$id = wc_get_product_id_by_sku( $in['sku'] );
		return $id ? (int) $id : 0;
	}
	return 0;
}

/** Turn names/ids/slugs into term ids, creating missing terms. */
function hsm_terms( $values, $taxonomy, $create = true ) {
	$ids = array();
	foreach ( (array) $values as $v ) {
		if ( is_numeric( $v ) && term_exists( (int) $v, $taxonomy ) ) {
			$ids[] = (int) $v;
			continue;
		}
		// Term names are plain text: strip markup before splitting "Parent > Child" (a '>' inside a tag must not split).
		$v = trim( wp_strip_all_tags( (string) $v ) );
		if ( '' === $v ) {
			continue;
		}
		// Support "Parent > Child" paths.
		$parent = 0;
		$parts  = array_values( array_filter( array_map( 'trim', explode( '>', $v ) ), 'strlen' ) );
		$tid    = 0;
		foreach ( $parts as $part ) {
			$t = get_term_by( 'name', $part, $taxonomy );
			if ( $t && (int) $t->parent !== $parent ) {
				$found = get_terms( array( 'taxonomy' => $taxonomy, 'name' => $part, 'parent' => $parent, 'hide_empty' => false ) );
				$t     = $found ? $found[0] : null;
			}
			if ( ! $t ) {
				$t = get_term_by( 'slug', sanitize_title( $part ), $taxonomy );
			}
			if ( ! $t && $create ) {
				$r = wp_insert_term( $part, $taxonomy, array( 'parent' => $parent ) );
				if ( is_wp_error( $r ) ) {
					throw new Exception( $r->get_error_message() );
				}
				$tid = (int) $r['term_id'];
			} elseif ( $t ) {
				$tid = (int) $t->term_id;
			}
			$parent = $tid;
		}
		if ( $tid ) {
			$ids[] = $tid;
		}
	}
	return array_values( array_unique( $ids ) );
}

/** Import an image from URL, local path or existing attachment id. Returns attachment id. */
/** Size / pixel limits for imported images (filterable). */
function hsm_media_check_limits( $file ) {
	$max_bytes  = (int) apply_filters( 'hsm_max_import_bytes', 15 * MB_IN_BYTES );
	$max_pixels = (int) apply_filters( 'hsm_max_image_pixels', 50000000 );
	$size       = @filesize( $file );
	if ( false === $size || $size > $max_bytes ) {
		throw new Exception( sprintf( 'Image is too large (max %d MB).', (int) ( $max_bytes / MB_IN_BYTES ) ) );
	}
	$dim = @getimagesize( $file );
	if ( $dim && (int) $dim[0] * (int) $dim[1] > $max_pixels ) {
		throw new Exception( sprintf( 'Image dimensions too large (%dx%d, max %d megapixels).', $dim[0], $dim[1], $max_pixels / 1000000 ) );
	}
}

/** Is this URL safe to fetch server-side? Own site (same host AND port) or a public address only. */
function hsm_media_url_ok( $url ) {
	if ( ! preg_match( '#^https?://#i', $url ) ) {
		return false;
	}
	$u    = wp_parse_url( $url );
	$home = wp_parse_url( home_url() );
	if ( ! $u || empty( $u['host'] ) || isset( $u['user'] ) || isset( $u['pass'] ) ) {
		return false;
	}
	$host  = strtolower( $u['host'] );
	$port  = $u['port'] ?? ( 'https' === strtolower( $u['scheme'] ) ? 443 : 80 );
	$hport = $home['port'] ?? ( 'https' === ( $home['scheme'] ?? 'http' ) ? 443 : 80 );
	if ( $host === strtolower( $home['host'] ) ) {
		return (int) $port === (int) $hport;
	}
	if ( apply_filters( 'hsm_allow_local_image_urls', false, $url ) ) {
		return true;
	}
	if ( ! wp_http_validate_url( $url ) ) {
		return false;
	}
	// Re-check every resolved address — both A and AAAA (wp_http_validate_url only checks the first A
	// record, and gethostbynamel returns IPv4 only, so an A=public + AAAA=private host slips through).
	$ips = array();
	foreach ( (array) @dns_get_record( $host, DNS_A | DNS_AAAA ) as $r ) {
		if ( ! empty( $r['ip'] ) ) {
			$ips[] = $r['ip'];
		}
		if ( ! empty( $r['ipv6'] ) ) {
			$ips[] = $r['ipv6'];
		}
	}
	if ( ! $ips ) {
		$ips = @gethostbynamel( $host ) ?: array();
	}
	foreach ( $ips as $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
	}
	return (bool) $ips;
}

/** Download an image, validating every redirect hop, with size and pixel limits. Returns attachment id or WP_Error. */
function hsm_media_download( $url, $parent = 0 ) {
	$max_bytes = (int) apply_filters( 'hsm_max_import_bytes', 15 * MB_IN_BYTES );
	for ( $hop = 0; $hop <= 3; $hop++ ) {
		if ( ! hsm_media_url_ok( $url ) ) {
			throw new Exception( "Image URL rejected (not a public http/https address): $url" );
		}
		$tmp = wp_tempnam( 'hsm-img' );
		$res = wp_remote_get( $url, array( 'timeout' => 30, 'redirection' => 0, 'stream' => true, 'filename' => $tmp, 'limit_response_size' => $max_bytes + 1, 'reject_unsafe_urls' => false ) );
		if ( is_wp_error( $res ) ) {
			@unlink( $tmp );
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			@unlink( $tmp );
			$loc = wp_remote_retrieve_header( $res, 'location' );
			if ( ! $loc ) {
				throw new Exception( 'Image redirect without Location.' );
			}
			$url = 0 === strpos( $loc, '/' ) && 0 !== strpos( $loc, '//' ) ? preg_replace( '#^(https?://[^/]+).*$#i', '$1', $url ) . $loc : $loc;
			continue;
		}
		if ( 200 !== $code ) {
			@unlink( $tmp );
			throw new Exception( "Image download failed (HTTP $code)." );
		}
		try {
			hsm_media_check_limits( $tmp );
		} catch ( Exception $e ) {
			@unlink( $tmp );
			throw $e;
		}
		$name = sanitize_file_name( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) ?: 'image';
		$ft   = wp_check_filetype_and_ext( $tmp, $name );
		if ( empty( $ft['type'] ) || 0 !== strpos( $ft['type'], 'image/' ) || ! @getimagesize( $tmp ) ) {
			@unlink( $tmp );
			throw new Exception( 'Only image files can be imported: ' . $name );
		}
		if ( ! preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $name ) ) {
			$name .= '.' . ( $ft['ext'] ?: 'jpg' );
		}
		return media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), $parent );
	}
	throw new Exception( 'Too many redirects while downloading the image.' );
}

function hsm_media( $src, $parent = 0, $alt = '' ) {
	if ( is_numeric( $src ) ) {
		return (int) $src;
	}
	if ( is_array( $src ) ) {
		$alt = $src['alt'] ?? $alt;
		$src = $src['src'] ?? ( $src['id'] ?? '' );
		if ( is_numeric( $src ) ) {
			if ( $alt && ( 'attachment' !== get_post_type( (int) $src ) || ! current_user_can( 'edit_post', (int) $src ) ) ) {
				throw new Exception( 'Attachment not found or not editable: ' . (int) $src );
			}
			if ( $alt ) {
				update_post_meta( (int) $src, '_wp_attachment_image_alt', $alt );
			}
			return (int) $src;
		}
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	// Re-use previously imported files.
	$existing = get_posts( array( 'post_type' => 'attachment', 'meta_key' => '_hsm_source', 'meta_value' => $src, 'fields' => 'ids', 'numberposts' => 1, 'post_status' => 'any' ) );
	if ( $existing ) {
		$id = (int) $existing[0];
	} elseif ( preg_match( '#^https?://#i', $src ) ) {
		$id = hsm_media_download( $src, $parent );
	} elseif ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $src ) ) {
		throw new Exception( "Unsupported image source scheme: $src" );
	} elseif ( hsm_path_allowed( $src ) ) {
		$real = realpath( $src );
		hsm_media_check_limits( $real );
		$ft   = wp_check_filetype_and_ext( $real, basename( $real ) );
		if ( empty( $ft['type'] ) || 0 !== strpos( $ft['type'], 'image/' ) || ! @getimagesize( $real ) ) {
			throw new Exception( 'Only image files can be imported: ' . basename( $real ) );
		}
		$tmp = wp_tempnam( basename( $real ) );
		copy( $real, $tmp );
		$id = media_handle_sideload( array( 'name' => basename( $real ), 'tmp_name' => $tmp ), $parent );
	} else {
		throw new Exception( "Image not found or outside the allowed import folders (uploads/ and wp-content/hsm-import/): $src" );
	}
	if ( is_wp_error( $id ) ) {
		throw new Exception( 'Image import failed: ' . $id->get_error_message() );
	}
	update_post_meta( $id, '_hsm_source', $src );
	if ( $alt ) {
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	}
	return (int) $id;
}

/** SEO meta keys stored by this plugin. */
function hsm_seo_fields() {
	return array( 'title', 'description', 'focus_keyword', 'canonical', 'noindex', 'nofollow', 'og_image', 'gtin', 'mpn', 'brand', 'condition' );
}

function hsm_get_seo( $post_id ) {
	$out = array();
	foreach ( hsm_seo_fields() as $f ) {
		$v = get_post_meta( $post_id, '_hsm_seo_' . $f, true );
		if ( '' !== $v && null !== $v ) {
			$out[ $f ] = $v;
		}
	}
	return $out;
}

function hsm_set_seo( $post_id, $seo ) {
	foreach ( hsm_seo_fields() as $f ) {
		if ( ! array_key_exists( $f, $seo ) ) {
			continue;
		}
		$v = $seo[ $f ];
		if ( in_array( $f, array( 'canonical', 'og_image' ), true ) && '' !== (string) $v && ! is_numeric( $v ) ) {
			$v = esc_url_raw( $v, array( 'http', 'https' ) );
		} elseif ( in_array( $f, array( 'noindex', 'nofollow' ), true ) ) {
			$v = filter_var( $v, FILTER_VALIDATE_BOOLEAN ) ? 1 : '';
		} elseif ( is_string( $v ) ) {
			$v = sanitize_text_field( $v );
			if ( 'condition' === $f && ! in_array( $v, array( 'new', 'used', 'refurbished' ), true ) ) {
				throw new Exception( 'seo.condition must be new, used or refurbished.' );
			}
		}
		if ( is_array( $v ) ) {
			throw new Exception( "seo.$f must be a string." );
		}
		if ( 'title' === $f && mb_strlen( (string) $v ) > 200 ) {
			throw new Exception( 'seo.title is too long (max 200).' );
		}
		if ( 'description' === $f && mb_strlen( (string) $v ) > 500 ) {
			throw new Exception( 'seo.description is too long (max 500).' );
		}
		hsm_undo_add( array( 'type' => 'post_meta', 'id' => $post_id, 'key' => '_hsm_seo_' . $f, 'old' => metadata_exists( 'post', $post_id, '_hsm_seo_' . $f ) ? get_post_meta( $post_id, '_hsm_seo_' . $f, true ) : null ) );
		if ( null === $v || '' === $v ) {
			delete_post_meta( $post_id, '_hsm_seo_' . $f );
		} else {
			update_post_meta( $post_id, '_hsm_seo_' . $f, is_bool( $v ) ? ( $v ? 1 : 0 ) : $v );
		}
	}
	// Mirror into popular SEO plugins if the user installs one later / already has one.
	$map = array(
		'title'         => array( '_yoast_wpseo_title', 'rank_math_title' ),
		'description'   => array( '_yoast_wpseo_metadesc', 'rank_math_description' ),
		'focus_keyword' => array( '_yoast_wpseo_focuskw', 'rank_math_focus_keyword' ),
		'canonical'     => array( '_yoast_wpseo_canonical', 'rank_math_canonical_url' ),
	);
	if ( hsm_other_seo_active() ) {
		foreach ( $map as $f => $keys ) {
			if ( isset( $seo[ $f ] ) ) {
				foreach ( $keys as $k ) {
					update_post_meta( $post_id, $k, sanitize_text_field( $seo[ $f ] ) );
				}
			}
		}
	}
}

/** Compact product representation. */
function hsm_product_out( $p, $full = false ) {
	if ( ! $p ) {
		return null;
	}
	$o = array(
		'id'             => $p->get_id(),
		'name'           => $p->get_name(),
		'type'           => $p->get_type(),
		'status'         => $p->get_status(),
		'sku'            => $p->get_sku(),
		'price'          => $p->get_price(),
		'regular_price'  => $p->get_regular_price(),
		'sale_price'     => $p->get_sale_price(),
		'stock_status'   => $p->get_stock_status(),
		'stock_quantity' => $p->get_stock_quantity(),
		'permalink'      => get_permalink( $p->get_id() ),
		'categories'     => wp_list_pluck( get_the_terms( $p->get_id(), 'product_cat' ) ?: array(), 'name' ),
	);
	if ( $p->is_type( 'variable' ) ) {
		$o['price_range'] = array( $p->get_variation_price( 'min' ), $p->get_variation_price( 'max' ) );
	}
	if ( ! $full ) {
		return $o;
	}
	$o += array(
		'slug'               => $p->get_slug(),
		'description'        => $p->get_description(),
		'short_description'  => $p->get_short_description(),
		'date_on_sale_from'  => $p->get_date_on_sale_from() ? $p->get_date_on_sale_from()->date( 'Y-m-d' ) : null,
		'date_on_sale_to'    => $p->get_date_on_sale_to() ? $p->get_date_on_sale_to()->date( 'Y-m-d' ) : null,
		'manage_stock'       => $p->get_manage_stock(),
		'backorders'         => $p->get_backorders(),
		'low_stock_amount'   => $p->get_low_stock_amount(),
		'weight'             => $p->get_weight(),
		'dimensions'         => array( 'length' => $p->get_length(), 'width' => $p->get_width(), 'height' => $p->get_height() ),
		'shipping_class'     => $p->get_shipping_class(),
		'tax_status'         => $p->get_tax_status(),
		'tax_class'          => $p->get_tax_class(),
		'featured'           => $p->get_featured(),
		'catalog_visibility' => $p->get_catalog_visibility(),
		'tags'               => wp_list_pluck( get_the_terms( $p->get_id(), 'product_tag' ) ?: array(), 'name' ),
		'brands'             => taxonomy_exists( 'product_brand' ) ? wp_list_pluck( get_the_terms( $p->get_id(), 'product_brand' ) ?: array(), 'name' ) : array(),
		'image'              => $p->get_image_id() ? wp_get_attachment_url( $p->get_image_id() ) : null,
		'gallery'            => array_map( 'wp_get_attachment_url', $p->get_gallery_image_ids() ),
		'upsell_skus'        => array_values( array_filter( array_map( function ( $id ) { $x = wc_get_product( $id ); return $x ? $x->get_sku() ?: $id : null; }, $p->get_upsell_ids() ) ) ),
		'cross_sell_skus'    => array_values( array_filter( array_map( function ( $id ) { $x = wc_get_product( $id ); return $x ? $x->get_sku() ?: $id : null; }, $p->get_cross_sell_ids() ) ) ),
		'attributes'         => array(),
		'seo'                => hsm_get_seo( $p->get_id() ),
		'total_sales'        => $p->get_total_sales(),
		'average_rating'     => $p->get_average_rating(),
		'review_count'       => $p->get_review_count(),
	);
	foreach ( $p->get_attributes() as $attr ) {
		if ( ! is_a( $attr, 'WC_Product_Attribute' ) ) {
			continue;
		}
		$o['attributes'][] = array(
			'name'      => $attr->is_taxonomy() ? wc_attribute_label( $attr->get_name() ) : $attr->get_name(),
			'options'   => $attr->is_taxonomy() ? wp_list_pluck( $attr->get_terms() ?: array(), 'name' ) : $attr->get_options(),
			'variation' => $attr->get_variation(),
			'visible'   => $attr->get_visible(),
			'global'    => $attr->is_taxonomy(),
		);
	}
	if ( $p->is_type( 'external' ) ) {
		$o['external_url'] = $p->get_product_url();
		$o['button_text']  = $p->get_button_text();
	}
	if ( $p->is_type( 'variable' ) ) {
		$o['variations'] = array();
		foreach ( $p->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v ) {
				continue;
			}
			$o['variations'][] = array(
				'id'             => $vid,
				'attributes'     => $v->get_attributes(),
				'sku'            => $v->get_sku(),
				'regular_price'  => $v->get_regular_price(),
				'sale_price'     => $v->get_sale_price(),
				'stock_quantity' => $v->get_stock_quantity(),
				'stock_status'   => $v->get_stock_status(),
				'image'          => $v->get_image_id() ? wp_get_attachment_url( $v->get_image_id() ) : null,
				'status'         => $v->get_status(),
			);
		}
	}
	return $o;
}

function hsm_check_price( $label, $old_regular, $new_regular, $new_sale, $old_effective = null, $product_id = 0 ) {
	$min  = (float) hsm_guard( 'min_price' );
	$cost = hsm_product_cost( $product_id );
	if ( $cost > 0 ) {
		$floor = round( $cost * ( 1 + (float) hsm_guard( 'min_margin_percent' ) / 100 ), 2 );
		$min   = max( $min, $floor );
	}
	foreach ( array( 'regular' => $new_regular, 'sale' => $new_sale ) as $k => $v ) {
		if ( null === $v || '' === $v ) {
			continue;
		}
		if ( ! is_numeric( $v ) ) {
			throw new HSM_Blocked( "$label: $k price '$v' is not a number." );
		}
		if ( (float) $v < 0 ) {
			throw new HSM_Blocked( "$label: negative $k price is not allowed." );
		}
		if ( (float) $v < $min ) {
			throw new HSM_Blocked( $cost > 0 && $min > (float) hsm_guard( 'min_price' )
				? "$label: $k price $v is below the cost floor $min (cost $cost + guard min_margin_percent)."
				: "$label: $k price $v is below the store's minimum price $min (guard min_price)." );
		}
	}
	if ( '' !== (string) $new_sale && null !== $new_sale && '' !== (string) $new_regular && null !== $new_regular && (float) $new_sale >= (float) $new_regular ) {
		throw new HSM_Blocked( "$label: sale price $new_sale must be lower than regular price $new_regular." );
	}
	$base = (float) ( $old_effective ?? $old_regular );
	$new  = ( '' !== (string) $new_sale && null !== $new_sale ) ? (float) $new_sale : ( ( null !== $new_regular && '' !== (string) $new_regular ) ? (float) $new_regular : null );
	if ( $base > 0 && null !== $new ) {
		$pct  = ( $new - $base ) / $base * 100;
		$hard = (float) hsm_guard( 'hard_max_price_drop_percent' );
		if ( $hard > 0 && $pct < -$hard ) {
			throw new HSM_Blocked( sprintf( '%s: price drops %.0f%% (%s → %s), more than the hard limit of %s%% (guard hard_max_price_drop_percent). Only the store owner can do this in wp-admin.', $label, -$pct, $base, $new, $hard ) );
		}
		if ( $pct < -hsm_guard( 'max_price_drop_percent' ) ) {
			return sprintf( '%s: price drops %.0f%% (%s → %s).', $label, -$pct, $base, $new );
		}
		if ( $pct > hsm_guard( 'max_price_raise_percent' ) ) {
			return sprintf( '%s: price rises %.0f%% (%s → %s).', $label, $pct, $base, $new );
		}
	}
	return '';
}

/** Owner-entered unit cost (meta _hsm_cost; variations fall back to the parent). Never writable through MCP. */
function hsm_product_cost( $id ) {
	$id = (int) $id;
	if ( ! $id ) {
		return 0.0;
	}
	$c = get_post_meta( $id, '_hsm_cost', true );
	if ( '' === $c && ( $parent = wp_get_post_parent_id( $id ) ) ) {
		$c = get_post_meta( $parent, '_hsm_cost', true );
	}
	return is_numeric( $c ) ? (float) $c : 0.0;
}

/** Daily counters for hard caps (site-wide, reset at midnight store time). */
function hsm_daily_counter( $name, $add = 0 ) {
	$key = 'hsm_day_' . $name . '_' . wp_date( 'Ymd' );
	$v   = (float) get_option( $key, 0 );
	if ( $add ) {
		$v += $add;
		update_option( $key, $v, false );
	}
	return $v;
}

/** Throw if re-pricing $n more items today would pass max_price_changes_per_day. Call before writing; counts on success. */
function hsm_check_price_changes( $n ) {
	$cap = (int) hsm_guard( 'max_price_changes_per_day' );
	if ( $n <= 0 || $cap <= 0 ) {
		return;
	}
	$used = (int) hsm_daily_counter( 'reprice' );
	if ( $used + $n > $cap ) {
		throw new HSM_Blocked( "Daily limit: $used of $cap price changes already used today; this call would re-price $n more (guard max_price_changes_per_day)." );
	}
	$GLOBALS['hsm_pending_reprice'] = ( $GLOBALS['hsm_pending_reprice'] ?? 0 ) + $n;
}

/** Throw if refunding $amount would pass max_refund_per_day. */
function hsm_check_refund_day( $amount ) {
	$cap = (float) hsm_guard( 'max_refund_per_day' );
	if ( $cap <= 0 ) {
		return;
	}
	$used = hsm_daily_counter( 'refund' );
	if ( $used + $amount > $cap + 0.001 ) {
		throw new HSM_Blocked( sprintf( 'Daily refund limit: %.2f of %.2f already refunded today; this refund of %.2f would exceed it (guard max_refund_per_day). Refund in wp-admin instead.', $used, $cap, $amount ) );
	}
}

function hsm_check_stock( $label, $qty ) {
	if ( null !== $qty && '' !== $qty ) {
		if ( ! is_numeric( $qty ) || (int) $qty != $qty ) {
			throw new HSM_Blocked( "$label: stock_quantity must be an integer." );
		}
		if ( (int) $qty < 0 ) {
			throw new HSM_Blocked( "$label: negative stock is not allowed." );
		}
		if ( (int) $qty > 1000000 ) {
			throw new HSM_Blocked( "$label: stock_quantity $qty looks wrong (over 1,000,000)." );
		}
	}
}

function hsm_product_state( $id ) {
	$p = wc_get_product( $id );
	if ( ! $p ) {
		return null;
	}
	$s = array(
		'id' => $id, 'name' => $p->get_name(), 'slug' => $p->get_slug(), 'status' => $p->get_status(), 'sku' => $p->get_sku(),
		'regular_price' => $p->get_regular_price(), 'sale_price' => $p->get_sale_price(),
		'date_on_sale_from' => $p->get_date_on_sale_from() ? $p->get_date_on_sale_from()->format( 'c' ) : '',
		'date_on_sale_to' => $p->get_date_on_sale_to() ? $p->get_date_on_sale_to()->format( 'c' ) : '',
		'manage_stock' => $p->get_manage_stock(), 'stock_quantity' => $p->get_stock_quantity(), 'stock_status' => $p->get_stock_status(),
		'description' => $p->get_description(), 'short_description' => $p->get_short_description(),
		'category_ids' => $p->get_category_ids(), 'tag_ids' => $p->get_tag_ids(), 'featured' => $p->get_featured(),
		'catalog_visibility' => $p->get_catalog_visibility(), 'weight' => $p->get_weight(), 'image_id' => $p->get_image_id(),
		'gallery_image_ids' => $p->get_gallery_image_ids(), 'seo' => hsm_get_seo( $id ),
		'backorders' => $p->get_backorders(), 'low_stock_amount' => $p->get_low_stock_amount(),
		'tax_status' => $p->get_tax_status(), 'tax_class' => $p->get_tax_class(),
		'virtual' => $p->get_virtual(), 'downloadable' => $p->get_downloadable(), 'sold_individually' => $p->get_sold_individually(),
		'menu_order' => $p->get_menu_order(), 'purchase_note' => $p->get_purchase_note(), 'reviews_allowed' => $p->get_reviews_allowed(),
		'length' => $p->get_length(), 'width' => $p->get_width(), 'height' => $p->get_height(),
		'shipping_class_id' => $p->get_shipping_class_id(),
		'upsell_ids' => $p->get_upsell_ids(), 'cross_sell_ids' => $p->get_cross_sell_ids(),
	);
	if ( $p->is_type( 'variable' ) ) {
		$s['children'] = array();
		foreach ( $p->get_children() as $c ) {
			$s['children'][ $c ] = hsm_product_state( $c );
		}
	}
	return $s;
}

function hsm_snap_product( $id ) {
	static $done = array();
	$key = ( $GLOBALS['hsm_audit_seq'] ?? 0 ) . ':' . $id;
	if ( isset( $done[ $key ] ) ) {
		return;
	}
	$done[ $key ] = true;
	$st           = hsm_product_state( $id );
	if ( $st ) {
		hsm_undo_add( array( 'type' => 'product', 'state' => $st ) );
	}
}

function hsm_restore_product( $st ) {
	$p = wc_get_product( $st['id'] );
	if ( ! $p ) {
		return 'product ' . $st['id'] . ' no longer exists';
	}
	if ( 'trash' === get_post_status( $st['id'] ) && 'trash' !== $st['status'] ) {
		wp_untrash_post( $st['id'] );
		$p = wc_get_product( $st['id'] );
	}
	$props = array(
		'name' => $st['name'], 'slug' => $st['slug'], 'status' => $st['status'], 'regular_price' => $st['regular_price'], 'sale_price' => $st['sale_price'],
		'date_on_sale_from' => $st['date_on_sale_from'] ?: null, 'date_on_sale_to' => $st['date_on_sale_to'] ?: null, 'manage_stock' => $st['manage_stock'],
		'stock_quantity' => $st['stock_quantity'], 'stock_status' => $st['stock_status'], 'description' => $st['description'],
		'short_description' => $st['short_description'], 'featured' => $st['featured'], 'catalog_visibility' => $st['catalog_visibility'],
		'weight' => $st['weight'], 'image_id' => $st['image_id'], 'gallery_image_ids' => $st['gallery_image_ids'],
	);
	// Fields added 2026-10: restore only when the snapshot carries them (undo entries made before then don't).
	foreach ( array( 'sku', 'backorders', 'low_stock_amount', 'tax_status', 'tax_class', 'virtual', 'downloadable', 'sold_individually', 'menu_order', 'purchase_note', 'reviews_allowed', 'length', 'width', 'height', 'shipping_class_id', 'upsell_ids', 'cross_sell_ids' ) as $k ) {
		if ( array_key_exists( $k, $st ) ) {
			$props[ $k ] = $st[ $k ];
		}
	}
	$p->set_props( $props );
	if ( ! $p->is_type( 'variation' ) ) {
		$p->set_category_ids( $st['category_ids'] );
		$p->set_tag_ids( $st['tag_ids'] );
	}
	$p->save();
	foreach ( hsm_seo_fields() as $f ) {
		isset( $st['seo'][ $f ] ) ? update_post_meta( $st['id'], '_hsm_seo_' . $f, $st['seo'][ $f ] ) : delete_post_meta( $st['id'], '_hsm_seo_' . $f );
	}
	if ( isset( $st['children'] ) ) {
		foreach ( $p->get_children() as $c ) {
			if ( ! isset( $st['children'][ $c ] ) ) {
				wc_get_product( $c ) && wc_get_product( $c )->delete( true ); // Created by the undone call.
			}
		}
		foreach ( $st['children'] as $c ) {
			$c && hsm_restore_product( $c );
		}
		WC_Product_Variable::sync( $st['id'] );
	}
	wc_delete_product_transients( $st['id'] );
	return 'restored product ' . $st['id'];
}

function hsm_coupon_state( $c ) {
	$keys = array( 'code', 'amount', 'discount_type', 'date_expires', 'free_shipping', 'minimum_amount', 'maximum_amount', 'individual_use', 'exclude_sale_items', 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items', 'product_ids', 'excluded_product_ids', 'product_categories', 'excluded_product_categories', 'email_restrictions', 'description' );
	$out  = array();
	foreach ( $keys as $k ) {
		$v         = $c->{"get_$k"}( 'edit' );
		$out[ $k ] = $v instanceof WC_DateTime ? $v->format( 'c' ) : $v;
	}
	return $out;
}

/* Owner-only unit cost field on the product edit screen (powers guard min_margin_percent). */
add_action( 'woocommerce_product_options_pricing', function () {
	woocommerce_wp_text_input( array(
		'id' => '_hsm_cost', 'label' => 'Unit cost (' . get_woocommerce_currency_symbol() . ')', 'data_type' => 'price', 'desc_tip' => true,
		'description' => 'Landed cost per unit. AI agents can never set a price below cost + Hark Store Safety "min_margin_percent", and cannot change this field.',
	) );
} );
add_action( 'woocommerce_admin_process_product_object', function ( $p ) {
	if ( isset( $_POST['_hsm_cost'] ) && current_user_can( 'edit_product', $p->get_id() ) ) {
		$v = wc_format_decimal( wp_unslash( $_POST['_hsm_cost'] ) );
		'' === $v ? $p->delete_meta_data( '_hsm_cost' ) : $p->update_meta_data( '_hsm_cost', $v );
	}
} );
