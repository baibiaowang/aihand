<?php
/**
 * Lightweight built-in SEO: titles, meta description, robots, canonical, Open Graph / Twitter,
 * JSON-LD (Organization, WebSite, Article, enriched WooCommerce Product), sitemap hygiene, robots.txt.
 * Steps aside automatically if Yoast SEO, Rank Math, AIOSEO or SEOPress is active.
 */
defined( 'ABSPATH' ) || exit;

function hsm_seo_settings() {
	return wp_parse_args( (array) get_option( 'hsm_seo', array() ), hsm_seo_settings_defaults() );
}

function hsm_seo_settings_defaults() {
	return ( array(
		'enabled'            => true,
		'separator'          => '|',
		'title_template'     => '%title% %sep% %sitename%',
		'product_title_template' => '%title% %sep% %sitename%',
		'home_title'         => '',
		'home_description'   => '',
		'default_og_image'   => '',
		'org_name'           => '',
		'org_logo'           => '',
		'social_profiles'    => array(),
		'twitter_site'       => '',
		'default_brand'      => '',
		'return_days'        => 30,
		'return_fees'        => 'FreeReturn',
		'ship_country'       => 'US',
		'handling_days'      => array( 0, 1 ),
		'transit_days'       => array( 3, 7 ),
		'robots_txt_extra'   => '',
		'noindex_search'     => true,
		'noindex_tags'       => false,
		'exclude_users_sitemap' => true,
		'merchant_feed'      => true,
	) );
}

function hsm_other_seo_active() {
	if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) ) {
		return true;
	}
	// Also check the stored plugin list: a long-running MCP server may predate the activation.
	$active = (array) get_option( 'active_plugins', array() );
	foreach ( array( 'wordpress-seo/', 'seo-by-rank-math/', 'all-in-one-seo-pack/', 'wp-seopress/' ) as $slug ) {
		foreach ( $active as $a ) {
			if ( 0 === strpos( $a, $slug ) ) {
				return true;
			}
		}
	}
	return false;
}

function hsm_seo_on() {
	$s = hsm_seo_settings();
	return ! empty( $s['enabled'] ) && ! hsm_other_seo_active();
}

function hsm_trim( $text, $len = 158 ) {
	$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( (string) $text ) ) ) );
	if ( mb_strlen( $text ) <= $len ) {
		return $text;
	}
	$cut = mb_substr( $text, 0, $len - 1 );
	$sp  = mb_strrpos( $cut, ' ' );
	return ( $sp > $len * 0.6 ? mb_substr( $cut, 0, $sp ) : $cut ) . '…';
}

function hsm_current_object_seo() {
	if ( is_singular() ) {
		return hsm_get_seo( get_queried_object_id() );
	}
	if ( is_category() || is_tag() || is_tax() ) {
		$t   = get_queried_object();
		$out = array();
		foreach ( array( 'title', 'description', 'noindex' ) as $k ) {
			$v = get_term_meta( $t->term_id, '_hsm_seo_' . $k, true );
			'' !== $v && $out[ $k ] = $v;
		}
		return $out;
	}
	return array();
}

function hsm_meta_description() {
	$s   = hsm_seo_settings();
	$seo = hsm_current_object_seo();
	if ( ! empty( $seo['description'] ) ) {
		return $seo['description'];
	}
	if ( is_front_page() ) {
		return $s['home_description'] ?: get_bloginfo( 'description' );
	}
	if ( is_singular() ) {
		$post = get_queried_object();
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$p = wc_get_product( $post->ID );
			if ( $p ) {
				return hsm_trim( $p->get_short_description() ?: $p->get_description() );
			}
		}
		return hsm_trim( $post->post_excerpt ?: $post->post_content );
	}
	if ( is_category() || is_tag() || is_tax() ) {
		return hsm_trim( term_description() );
	}
	return '';
}

add_filter( 'pre_get_document_title', function ( $title ) {
	if ( ! hsm_seo_on() ) {
		return $title;
	}
	$s   = hsm_seo_settings();
	$seo = hsm_current_object_seo();
	if ( is_front_page() && $s['home_title'] ) {
		return esc_html( $s['home_title'] );
	}
	if ( ! empty( $seo['title'] ) ) {
		return esc_html( wp_strip_all_tags( $seo['title'] ) ); // Core does not escape pre_get_document_title.
	}
	if ( is_singular( 'product' ) ) {
		return esc_html( strtr( $s['product_title_template'], array( '%title%' => wp_strip_all_tags( single_post_title( '', false ) ), '%sep%' => $s['separator'], '%sitename%' => get_bloginfo( 'name' ) ) ) );
	}
	return $title;
}, 20 );

add_filter( 'document_title_separator', function ( $sep ) {
	return hsm_seo_on() ? esc_html( hsm_seo_settings()['separator'] ) : $sep;
} );

add_filter( 'wp_robots', function ( $robots ) {
	if ( ! hsm_seo_on() ) {
		return $robots;
	}
	$s   = hsm_seo_settings();
	$seo = hsm_current_object_seo();
	if ( ! empty( $seo['noindex'] ) || ( is_search() && $s['noindex_search'] ) || ( is_tag() && $s['noindex_tags'] ) || is_404() ) {
		$robots['noindex'] = true;
		$robots['follow']  = empty( $seo['nofollow'] );
		unset( $robots['max-image-preview'] );
	}
	if ( ! empty( $seo['nofollow'] ) ) {
		$robots['nofollow'] = true;
		unset( $robots['follow'] );
	}
	return $robots;
} );

add_filter( 'get_canonical_url', function ( $url, $post ) {
	if ( hsm_seo_on() ) {
		$c = get_post_meta( $post->ID, '_hsm_seo_canonical', true );
		if ( $c ) {
			return $c;
		}
	}
	return $url;
}, 10, 2 );

function hsm_og_image() {
	$s = hsm_seo_settings();
	if ( is_singular() ) {
		$id  = get_queried_object_id();
		$cus = get_post_meta( $id, '_hsm_seo_og_image', true );
		if ( $cus ) {
			return is_numeric( $cus ) ? wp_get_attachment_image_url( $cus, 'large' ) : $cus;
		}
		if ( has_post_thumbnail( $id ) ) {
			return get_the_post_thumbnail_url( $id, 'large' );
		}
	}
	if ( is_tax( 'product_cat' ) ) {
		$tid = get_term_meta( get_queried_object_id(), 'thumbnail_id', true );
		if ( $tid ) {
			return wp_get_attachment_image_url( $tid, 'large' );
		}
	}
	return $s['default_og_image'] ?: ( $s['org_logo'] ?: '' );
}

add_action( 'wp_head', function () {
	if ( ! hsm_seo_on() ) {
		return;
	}
	$s     = hsm_seo_settings();
	$desc  = hsm_meta_description();
	$title = wp_get_document_title();
	$url   = is_singular() ? wp_get_canonical_url() : ( is_front_page() ? home_url( '/' ) : ( ( is_category() || is_tag() || is_tax() ) ? get_term_link( get_queried_object() ) : '' ) );
	$img   = hsm_og_image();
	$type  = is_singular( 'product' ) ? 'product' : ( is_singular( 'post' ) ? 'article' : 'website' );
	echo "\n<!-- Hark Store SEO -->\n";
	if ( $desc ) {
		printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $desc ) );
	}
	if ( ( is_category() || is_tag() || is_tax() ) && $url && ! is_wp_error( $url ) ) {
		printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $url ) );
	}
	$og = array(
		'og:locale'      => get_locale(),
		'og:type'        => $type,
		'og:title'       => wp_specialchars_decode( $title, ENT_QUOTES ),
		'og:description' => $desc,
		'og:url'         => is_wp_error( $url ) ? '' : $url,
		'og:site_name'   => get_bloginfo( 'name' ),
		'og:image'       => $img,
	);
	if ( is_singular( 'product' ) && function_exists( 'wc_get_product' ) ) {
		$p = wc_get_product( get_queried_object_id() );
		if ( $p ) {
			$og['product:price:amount']   = wc_format_decimal( $p->get_price(), wc_get_price_decimals() );
			$og['product:price:currency'] = get_woocommerce_currency();
			$og['product:availability']   = $p->is_in_stock() ? 'in stock' : 'out of stock';
			$og['product:retailer_item_id'] = $p->get_sku();
		}
	}
	foreach ( $og as $k => $v ) {
		if ( '' !== (string) $v ) {
			printf( '<meta property="%s" content="%s" />' . "\n", esc_attr( $k ), esc_attr( $v ) );
		}
	}
	printf( '<meta name="twitter:card" content="%s" />' . "\n", $img ? 'summary_large_image' : 'summary' );
	if ( $s['twitter_site'] ) {
		printf( '<meta name="twitter:site" content="%s" />' . "\n", esc_attr( $s['twitter_site'] ) );
	}
	// JSON-LD.
	$graph = array();
	if ( is_front_page() ) {
		$org = array( '@type' => 'Organization', '@id' => home_url( '/#organization' ), 'name' => $s['org_name'] ?: get_bloginfo( 'name' ), 'url' => home_url( '/' ) );
		$s['org_logo'] && $org['logo'] = $s['org_logo'];
		$s['social_profiles'] && $org['sameAs'] = array_values( (array) $s['social_profiles'] );
		$graph[] = $org;
		$graph[] = array(
			'@type'           => 'WebSite',
			'@id'             => home_url( '/#website' ),
			'url'             => home_url( '/' ),
			'name'            => get_bloginfo( 'name' ),
			'publisher'       => array( '@id' => home_url( '/#organization' ) ),
			'potentialAction' => array( '@type' => 'SearchAction', 'target' => home_url( '/?s={search_term_string}' . ( class_exists( 'WooCommerce' ) ? '&post_type=product' : '' ) ), 'query-input' => 'required name=search_term_string' ),
		);
	}
	if ( is_singular( 'post' ) ) {
		$post    = get_queried_object();
		$graph[] = array(
			'@type'         => 'Article',
			'headline'      => get_the_title( $post ),
			'description'   => $desc,
			'datePublished' => get_post_time( 'c', true, $post ),
			'dateModified'  => get_post_modified_time( 'c', true, $post ),
			'author'        => array( '@type' => 'Person', 'name' => get_the_author_meta( 'display_name', $post->post_author ) ),
			'image'         => $img ?: null,
			'mainEntityOfPage' => get_permalink( $post ),
		);
	}
	if ( $graph ) {
		echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $graph ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . "</script>\n";
	}
	echo "<!-- /Hark Store SEO -->\n";
}, 2 );

/** Enrich WooCommerce's own Product structured data with what Google Merchant listings want. */
add_filter( 'woocommerce_structured_data_product', function ( $markup, $product ) {
	if ( ! hsm_seo_on() ) {
		return $markup;
	}
	$s     = hsm_seo_settings();
	$seo   = hsm_get_seo( $product->get_id() );
	$brand = $seo['brand'] ?? '';
	if ( ! $brand && taxonomy_exists( 'product_brand' ) ) {
		$terms = get_the_terms( $product->get_id(), 'product_brand' );
		$brand = $terms && ! is_wp_error( $terms ) ? $terms[0]->name : '';
	}
	$brand = $brand ?: $s['default_brand'];
	if ( $brand ) {
		$markup['brand'] = array( '@type' => 'Brand', 'name' => $brand );
	}
	$gtin = $product->is_type( 'variable' ) ? '' : ( ( $seo['gtin'] ?? '' ) ?: ( method_exists( $product, 'get_global_unique_id' ) ? $product->get_global_unique_id() : '' ) );
	if ( $gtin ) {
		$markup['gtin'] = $gtin;
	}
	if ( ! empty( $seo['mpn'] ) ) {
		$markup['mpn'] = $seo['mpn'];
	}
	if ( ! empty( $seo['description'] ) ) {
		$markup['description'] = $seo['description'];
	}
	$cond = $seo['condition'] ?? 'new';
	$cond = array( 'new' => 'NewCondition', 'used' => 'UsedCondition', 'refurbished' => 'RefurbishedCondition' )[ $cond ] ?? 'NewCondition';
	$gallery = array_filter( array_map( function ( $id ) { return wp_get_attachment_url( $id ); }, array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ) );
	if ( $gallery ) {
		$markup['image'] = array_values( $gallery );
	}
	if ( ! empty( $markup['offers'] ) ) {
		foreach ( $markup['offers'] as &$offer ) {
			$offer['itemCondition'] = 'https://schema.org/' . $cond;
			$offer['shippingDetails'] = array(
				'@type'               => 'OfferShippingDetails',
				'shippingDestination' => array( '@type' => 'DefinedRegion', 'addressCountry' => $s['ship_country'] ),
				'deliveryTime'        => array(
					'@type'        => 'ShippingDeliveryTime',
					'handlingTime' => array( '@type' => 'QuantitativeValue', 'minValue' => (int) $s['handling_days'][0], 'maxValue' => (int) $s['handling_days'][1], 'unitCode' => 'DAY' ),
					'transitTime'  => array( '@type' => 'QuantitativeValue', 'minValue' => (int) $s['transit_days'][0], 'maxValue' => (int) $s['transit_days'][1], 'unitCode' => 'DAY' ),
				),
			);
			if ( $s['return_days'] ) {
				$offer['hasMerchantReturnPolicy'] = array(
					'@type'                => 'MerchantReturnPolicy',
					'applicableCountry'    => $s['ship_country'],
					'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
					'merchantReturnDays'   => (int) $s['return_days'],
					'returnMethod'         => 'https://schema.org/ReturnByMail',
					'returnFees'           => 'https://schema.org/' . $s['return_fees'],
				);
			}
		}
	}
	return $markup;
}, 10, 2 );

/* Sitemap hygiene. */
add_filter( 'wp_sitemaps_add_provider', function ( $provider, $name ) {
	return ( 'users' === $name && hsm_seo_on() && hsm_seo_settings()['exclude_users_sitemap'] ) ? false : $provider;
}, 10, 2 );

add_filter( 'wp_sitemaps_posts_query_args', function ( $args ) {
	if ( ! hsm_seo_on() ) {
		return $args;
	}
	$ids = get_posts( array( 'post_type' => 'any', 'meta_key' => '_hsm_seo_noindex', 'meta_value' => '1', 'fields' => 'ids', 'numberposts' => -1 ) );
	if ( $ids ) {
		$args['post__not_in'] = array_merge( $args['post__not_in'] ?? array(), $ids );
	}
	return $args;
} );

add_filter( 'wp_sitemaps_taxonomies', function ( $tax ) {
	if ( hsm_seo_on() && hsm_seo_settings()['noindex_tags'] ) {
		unset( $tax['post_tag'], $tax['product_tag'] );
	}
	unset( $tax['product_shipping_class'], $tax['post_format'] );
	return $tax;
} );

add_filter( 'robots_txt', function ( $out, $public ) {
	if ( ! hsm_seo_on() || ! $public ) {
		return $out;
	}
	$s = hsm_seo_settings();
	if ( class_exists( 'WooCommerce' ) ) {
		$out .= "Disallow: /cart/\nDisallow: /checkout/\nDisallow: /my-account/\nDisallow: /*?orderby=*\n";
	}
	if ( $s['robots_txt_extra'] ) {
		$out .= trim( $s['robots_txt_extra'] ) . "\n";
	}
	return $out;
}, 20, 2 );
