<?php
/**
 * Product catalog feeds (replaces a product-feed plugin):
 * - Google Merchant Center RSS 2.0 XML: /feeds/google-merchant.xml (?hsm_feed=google)
 * - Meta (Facebook/Instagram) catalog: the same RSS 2.0 + g: namespace items (Meta reads Google-format XML), served at
 *   /feeds/meta-catalog.xml (?hsm_feed=meta) with Meta's documented availability values (in stock / out of stock /
 *   available for order / preorder). g:id = SKU = content_ids sent by the pixel and CAPI, so catalog ads match.
 * - TikTok catalog CSV: /feeds/tiktok-catalog.csv (?hsm_feed=tiktok) with TikTok template columns.
 * All share one switch (hsm_seo merchant_feed), cached files with hashed names in uploads/hsm-feeds, TTL + rebuild lock,
 * lazy rebuild only after catalog changes, ETag/304, X-Robots-Tag noindex.
 */
defined( 'ABSPATH' ) || exit;

function hsm_feed_rewrite() {
	add_rewrite_rule( '^feeds/google-merchant\.xml$', 'index.php?hsm_feed=google', 'top' );
	add_rewrite_rule( '^feeds/meta-catalog\.xml$', 'index.php?hsm_feed=meta', 'top' );
	add_rewrite_rule( '^feeds/tiktok-catalog\.csv$', 'index.php?hsm_feed=tiktok', 'top' );
}
add_action( 'init', 'hsm_feed_rewrite' );
add_filter( 'query_vars', function ( $v ) {
	$v[] = 'hsm_feed';
	return $v;
} );

function hsm_feed_url( $type = 'google' ) {
	$path = array( 'google' => '/feeds/google-merchant.xml', 'meta' => '/feeds/meta-catalog.xml', 'tiktok' => '/feeds/tiktok-catalog.csv' );
	$type = isset( $path[ $type ] ) ? $type : 'google';
	return get_option( 'permalink_structure' ) ? home_url( $path[ $type ] ) : home_url( '/?hsm_feed=' . $type );
}

function hsm_feed_items() {
	$s     = hsm_seo_settings();
	$items = array();
	$cur   = get_woocommerce_currency();
	foreach ( wc_get_products( array( 'limit' => -1, 'status' => 'publish', 'type' => array( 'simple', 'variable', 'external' ) ) ) as $p ) {
		if ( 'hidden' === $p->get_catalog_visibility() || get_post_meta( $p->get_id(), '_hsm_seo_noindex', true ) || get_post_meta( $p->get_id(), '_hsm_feed_exclude', true ) ) {
			continue;
		}
		$seo   = hsm_get_seo( $p->get_id() );
		$brand = $seo['brand'] ?? '';
		if ( ! $brand && taxonomy_exists( 'product_brand' ) ) {
			$t     = get_the_terms( $p->get_id(), 'product_brand' );
			$brand = $t && ! is_wp_error( $t ) ? $t[0]->name : '';
		}
		$brand = $brand ?: $s['default_brand'];
		$cats  = get_the_terms( $p->get_id(), 'product_cat' );
		$ptype = $cats && ! is_wp_error( $cats ) ? implode( ' > ', array_reverse( array_merge( array( $cats[0]->name ), array_map( function ( $id ) { return get_term( $id )->name; }, get_ancestors( $cats[0]->term_id, 'product_cat' ) ) ) ) ) : '';
		$base  = array(
			'title'                   => $p->get_name(),
			// Google and Meta both require a description: fall back to the short description, then the title.
			'description'             => hsm_trim( $p->get_description() ?: ( $p->get_short_description() ?: $p->get_name() ), 4900 ),
			'link'                    => get_permalink( $p->get_id() ),
			'brand'                   => $brand,
			'condition'               => $seo['condition'] ?? 'new',
			'product_type'            => $ptype,
			'google_product_category' => get_post_meta( $p->get_id(), '_hsm_google_category', true ),
			'image'                   => $p->get_image_id() ? wp_get_attachment_url( $p->get_image_id() ) : '',
			'additional'              => array_slice( array_filter( array_map( 'wp_get_attachment_url', $p->get_gallery_image_ids() ) ), 0, 10 ),
		);
		$variants = $p->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $p->get_children() ) ) : array( $p );
		foreach ( $variants as $v ) {
			if ( 'publish' !== $v->get_status() && $v->is_type( 'variation' ) ) {
				continue;
			}
			// Identifiers are per-SKU: a variation never inherits the parent's GTIN/MPN.
			$vseo = $v->is_type( 'variation' ) ? array( 'mpn' => get_post_meta( $v->get_id(), '_hsm_seo_mpn', true ) ) : $seo;
			$gtin = method_exists( $v, 'get_global_unique_id' ) ? $v->get_global_unique_id() : '';
			$gtin = $gtin ?: ( $v->is_type( 'variation' ) ? '' : ( $seo['gtin'] ?? '' ) );
			$item = $base;
			$item['id']           = $v->get_sku() ?: 'wc-' . $v->get_id();
			$item['availability'] = $v->is_in_stock() ? ( $v->is_on_backorder() ? 'backorder' : 'in_stock' ) : 'out_of_stock';
			$item['price']        = $v->get_regular_price() ? wc_format_decimal( $v->get_regular_price(), 2 ) . ' ' . $cur : wc_format_decimal( $v->get_price(), 2 ) . ' ' . $cur;
			if ( $v->is_on_sale() && $v->get_sale_price() ) {
				$item['sale_price'] = wc_format_decimal( $v->get_sale_price(), 2 ) . ' ' . $cur;
				if ( $v->get_date_on_sale_from() && $v->get_date_on_sale_to() ) {
					$item['sale_price_effective_date'] = $v->get_date_on_sale_from()->format( 'c' ) . '/' . $v->get_date_on_sale_to()->format( 'c' );
				}
			}
			$gtin && $item['gtin'] = $gtin;
			! empty( $vseo['mpn'] ) && $item['mpn'] = $vseo['mpn'];
			if ( ! $gtin && empty( $vseo['mpn'] ) ) {
				$item['identifier_exists'] = 'no';
			}
			if ( $v->get_weight() ) {
				$item['shipping_weight'] = $v->get_weight() . ' ' . get_option( 'woocommerce_weight_unit' );
			}
			if ( $v->is_type( 'variation' ) ) {
				$item['item_group_id'] = $p->get_sku() ?: 'wc-' . $p->get_id();
				$item['link']          = $v->get_permalink();
				$item['title']         = $v->get_name();
				$v->get_image_id() && $item['image'] = wp_get_attachment_url( $v->get_image_id() );
				foreach ( $v->get_attributes() as $k => $val ) {
					$label = strtolower( wc_attribute_label( str_replace( 'attribute_', '', $k ), $p ) );
					if ( taxonomy_exists( $k ) ) {
						$term = get_term_by( 'slug', $val, $k );
						$val  = $term ? $term->name : $val;
					}
					if ( in_array( $label, array( 'color', 'colour', '颜色' ), true ) ) {
						$item['color'] = $val;
					} elseif ( in_array( $label, array( 'size', '尺码', '尺寸' ), true ) ) {
						$item['size'] = $val;
					} elseif ( in_array( $label, array( 'material', '材质' ), true ) ) {
						$item['material'] = $val;
					}
				}
			}
			$items[] = $item;
		}
	}
	return $items;
}

/** Cached feed file: generating 500+ items per request would let anyone DoS the store by hammering the URL. */
function hsm_feed_cache_path( $type = 'google' ) {
	$dir = wp_get_upload_dir()['basedir'] . '/hsm-feeds';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		@file_put_contents( $dir . '/index.html', '' );
	}
	if ( 'tiktok' === $type ) {
		return $dir . '/tiktok-' . substr( wp_hash( 'hsm-feed-tiktok' ), 0, 12 ) . '.csv';
	}
	return 'meta' === $type ? $dir . '/meta-' . substr( wp_hash( 'hsm-feed-meta' ), 0, 12 ) . '.xml' : $dir . '/google-' . substr( wp_hash( 'hsm-feed' ), 0, 12 ) . '.xml';
}

function hsm_feed_build_xml( $flavor = 'google' ) {
	$meta_av = array( 'in_stock' => 'in stock', 'out_of_stock' => 'out of stock', 'backorder' => 'available for order', 'preorder' => 'preorder' );
	$x = new XMLWriter();
	$x->openMemory();
	$x->setIndent( true );
	$x->startDocument( '1.0', 'UTF-8' );
	$x->startElement( 'rss' );
	$x->writeAttribute( 'version', '2.0' );
	$x->writeAttribute( 'xmlns:g', 'http://base.google.com/ns/1.0' );
	$x->startElement( 'channel' );
	$x->writeElement( 'title', get_bloginfo( 'name' ) );
	$x->writeElement( 'link', home_url( '/' ) );
	$x->writeElement( 'description', get_bloginfo( 'description' ) );
	foreach ( hsm_feed_items() as $it ) {
		$x->startElement( 'item' );
		foreach ( $it as $k => $v ) {
			if ( 'additional' === $k ) {
				foreach ( $v as $img ) {
					$x->writeElement( 'g:additional_image_link', $img );
				}
				continue;
			}
			if ( '' === (string) $v ) {
				continue;
			}
			if ( 'meta' === $flavor && 'availability' === $k ) {
				$v = $meta_av[ $v ] ?? 'out of stock';
			}
			$tag = in_array( $k, array( 'title', 'description', 'link' ), true ) ? $k : 'g:' . ( 'image' === $k ? 'image_link' : $k );
			$x->writeElement( $tag, (string) $v );
		}
		$x->endElement();
	}
	$x->endElement();
	$x->endElement();
	return $x->outputMemory();
}

/** TikTok catalog CSV (TikTok Catalog Manager template columns). Cells are guarded against CSV formula injection. */
function hsm_feed_tiktok_columns() {
	return array( 'sku_id', 'title', 'description', 'availability', 'condition', 'price', 'sale_price', 'link', 'image_link', 'additional_image_link', 'brand', 'item_group_id', 'google_product_category', 'product_type', 'gtin', 'mpn', 'color', 'size', 'material', 'shipping_weight' );
}

function hsm_feed_tiktok_row( $it ) {
	$av = array( 'in_stock' => 'in stock', 'out_of_stock' => 'out of stock', 'backorder' => 'available for order', 'preorder' => 'preorder' );
	return array(
		'sku_id'                  => $it['id'],
		'title'                   => hsm_trim( $it['title'], 150 ),
		'description'             => hsm_trim( $it['description'] ?: $it['title'], 9999 ),
		'availability'            => $av[ $it['availability'] ] ?? 'out of stock',
		'condition'               => in_array( $it['condition'], array( 'new', 'refurbished', 'used' ), true ) ? $it['condition'] : 'new',
		'price'                   => $it['price'],
		'sale_price'              => $it['sale_price'] ?? '',
		'link'                    => $it['link'],
		'image_link'              => $it['image'],
		'additional_image_link'   => implode( ',', array_slice( (array) $it['additional'], 0, 10 ) ),
		'brand'                   => $it['brand'],
		'item_group_id'           => $it['item_group_id'] ?? '',
		'google_product_category' => $it['google_product_category'],
		'product_type'            => $it['product_type'],
		'gtin'                    => $it['gtin'] ?? '',
		'mpn'                     => $it['mpn'] ?? '',
		'color'                   => $it['color'] ?? '',
		'size'                    => $it['size'] ?? '',
		'material'                => $it['material'] ?? '',
		'shipping_weight'         => $it['shipping_weight'] ?? '',
	);
}

function hsm_feed_csv_cell( $v ) {
	$v = str_replace( array( "\r", "\n" ), ' ', (string) $v );
	return ( '' !== $v && false !== strpos( '=+-@', $v[0] ) ) ? "'" . $v : $v;
}

function hsm_feed_build_tiktok() {
	$cols = hsm_feed_tiktok_columns();
	$fh   = fopen( 'php://temp', 'w+' );
	fputcsv( $fh, $cols, ',', '"', '' );
	foreach ( hsm_feed_items() as $it ) {
		$row = hsm_feed_tiktok_row( $it );
		fputcsv( $fh, array_map( function ( $c ) use ( $row ) { return hsm_feed_csv_cell( $row[ $c ] ); }, $cols ), ',', '"', '' );
	}
	rewind( $fh );
	$csv = stream_get_contents( $fh );
	fclose( $fh );
	return $csv;
}

/** Returns the feed body, rebuilding at most once per TTL (or after a catalog change) and never concurrently. */
function hsm_feed_get( $force = false, $type = 'google' ) {
	$type  = in_array( $type, array( 'tiktok', 'meta' ), true ) ? $type : 'google';
	$file  = hsm_feed_cache_path( $type );
	$build = 'tiktok' === $type ? 'hsm_feed_build_tiktok' : function () use ( $type ) { return hsm_feed_build_xml( $type ); };
	$ttl   = (int) apply_filters( 'hsm_feed_ttl', HOUR_IN_SECONDS );
	$dirty = (array) get_option( 'hsm_feed_dirty_types', array() );
	$stale = ! file_exists( $file ) || ( time() - filemtime( $file ) ) > $ttl || get_option( 'hsm_feed_dirty' ) || ! empty( $dirty[ $type ] );
	$lock  = 'hsm_feed_lock' . ( 'google' === $type ? '' : '_' . $type );
	if ( ( $stale || $force ) && ! get_transient( $lock ) ) {
		set_transient( $lock, 1, 120 );
		$body = $build();
		file_put_contents( $file . '.tmp', $body );
		rename( $file . '.tmp', $file );
		if ( get_option( 'hsm_feed_dirty' ) ) { // a change marks every feed type stale; clear only ours
			delete_option( 'hsm_feed_dirty' );
			$dirty = array( 'google' => 1, 'meta' => 1, 'tiktok' => 1 );
		}
		unset( $dirty[ $type ] );
		update_option( 'hsm_feed_dirty_types', $dirty, false );
		delete_transient( $lock );
		return $body;
	}
	return file_exists( $file ) ? file_get_contents( $file ) : $build();
}

// Mark the feed stale whenever catalog data changes (cheap; rebuilt lazily on next fetch).
foreach ( array( 'woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_delete_product', 'woocommerce_update_product_variation', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock' ) as $hook ) {
	add_action( $hook, function () {
		if ( ! get_option( 'hsm_feed_dirty' ) ) {
			update_option( 'hsm_feed_dirty', 1, false );
		}
	} );
}

add_action( 'template_redirect', function () {
	$type = get_query_var( 'hsm_feed' ) ?: ( isset( $_GET['hsm_feed'] ) ? sanitize_key( $_GET['hsm_feed'] ) : '' ); // phpcs:ignore
	if ( ! in_array( $type, array( 'google', 'meta', 'tiktok' ), true ) ) {
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) || empty( hsm_seo_settings()['merchant_feed'] ) ) {
		status_header( 404 );
		exit;
	}
	$body = hsm_feed_get( false, $type );
	$etag = '"' . md5( $body ) . '"';
	header( 'Content-Type: ' . ( 'tiktok' === $type ? 'text/csv' : 'application/xml' ) . '; charset=utf-8' );
	'tiktok' === $type && header( 'Content-Disposition: inline; filename="tiktok-catalog.csv"' );
	header( 'Cache-Control: public, max-age=900' );
	header( 'ETag: ' . $etag );
	header( 'X-Robots-Tag: noindex' );
	header( 'X-Content-Type-Options: nosniff' );
	if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) === $etag ) {
		status_header( 304 );
		exit;
	}
	echo $body; // phpcs:ignore
	exit;
}, 0 );
