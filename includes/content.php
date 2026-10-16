<?php
/**
 * v1.8 Content & traffic — OFF by default.
 *
 * Front-end (settings-driven, applies to blog posts): Article structured data, an author box (E-E-A-T),
 * an auto table of contents, reading time, share buttons (plain links, no third-party scripts), a view
 * counter, external links get rel="nofollow noopener", FAQ blocks with FAQPage structured data, and
 * virtual /llms.txt and /ads.txt endpoints. IndexNow pings search engines when a post is published.
 *
 * Tools (actions): drop product cards into a post at a chosen position, list/schedule publishing, and
 * generate legal pages (privacy, terms, refunds, shipping) from store information.
 */
defined( 'ABSPATH' ) || exit;

function hsm_content_defaults() {
	return array(
		'article'           => array( 'enabled' => false, 'author_box' => true, 'toc' => true, 'reading_time' => true, 'share' => true, 'views' => true ),
		'faq'               => array( 'enabled' => false ),
		'external_nofollow' => array( 'enabled' => false ),
		'llms'              => array( 'enabled' => false, 'content' => '' ),
		'ads'               => array( 'enabled' => false, 'content' => '' ),
		'indexnow'          => array( 'enabled' => false, 'key' => '' ),
	);
}

function hsm_content_settings() {
	$o = (array) get_option( 'hsm_content', array() );
	$s = array();
	foreach ( hsm_content_defaults() as $k => $def ) {
		$s[ $k ] = wp_parse_args( is_array( $o[ $k ] ?? null ) ? $o[ $k ] : array(), $def );
	}
	return $s;
}

function hsm_content_on( $key ) {
	$s = hsm_content_settings();
	return ! empty( $s[ $key ]['enabled'] );
}

/** Only touch the main content of a single blog post. */
function hsm_content_is_post_main() {
	return is_singular( 'post' ) && in_the_loop() && is_main_query() && ! is_feed() && ! is_admin();
}

/* --------------------------------------------------------------------- */
/* Article structured data                                                 */
/* --------------------------------------------------------------------- */

add_action( 'wp_head', function () {
	if ( ! hsm_content_on( 'article' ) || ! is_singular( 'post' ) || hsm_other_seo_active() ) {
		return;
	}
	$post = get_queried_object();
	if ( ! $post ) {
		return;
	}
	$author = get_userdata( $post->post_author );
	$data   = array(
		'@context'      => 'https://schema.org',
		'@type'         => 'Article',
		'headline'      => wp_strip_all_tags( get_the_title( $post ) ),
		'datePublished' => get_the_date( 'c', $post ),
		'dateModified'  => get_the_modified_date( 'c', $post ),
		'mainEntityOfPage' => array( '@type' => 'WebPage', '@id' => get_permalink( $post ) ),
		'author'        => array( '@type' => 'Person', 'name' => $author ? $author->display_name : get_bloginfo( 'name' ), 'url' => $author ? get_author_posts_url( $author->ID ) : home_url( '/' ) ),
		'publisher'     => array( '@type' => 'Organization', 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ),
	);
	$img = get_the_post_thumbnail_url( $post, 'full' );
	if ( $img ) {
		$data['image'] = $img;
	}
	echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
}, 20 );

/* --------------------------------------------------------------------- */
/* Table of contents, reading time, author box, share buttons              */
/* --------------------------------------------------------------------- */

function hsm_content_reading_time( $html ) {
	$words = str_word_count( wp_strip_all_tags( $html ) );
	return max( 1, (int) ceil( $words / 200 ) );
}

function hsm_content_toc( $html ) {
	if ( ! preg_match_all( '/<h([23])([^>]*)>(.*?)<\/h\1>/is', $html, $m, PREG_SET_ORDER ) ) {
		return array( $html, '' );
	}
	$items = '';
	$i     = 0;
	foreach ( $m as $h ) {
		$i++;
		$id   = 'hsm-toc-' . $i;
		$text = wp_strip_all_tags( $h[3] );
		if ( '' === trim( $text ) ) {
			continue;
		}
		// Give the heading an id so the link jumps to it.
		$html = str_replace( $h[0], '<h' . $h[1] . $h[2] . ' id="' . $id . '">' . $h[3] . '</h' . $h[1] . '>', $html );
		$items .= '<li class="hsm-toc-' . ( '2' === $h[1] ? 'h2' : 'h3' ) . '"><a href="#' . $id . '">' . esc_html( $text ) . '</a></li>';
	}
	if ( '' === $items ) {
		return array( $html, '' );
	}
	return array( $html, '<nav class="hsm-toc" aria-label="Contents"><strong>Contents</strong><ul>' . $items . '</ul></nav>' );
}

function hsm_content_author_box( $post ) {
	$author = get_userdata( $post->post_author );
	if ( ! $author ) {
		return '';
	}
	$bio = get_user_meta( $author->ID, 'description', true );
	return '<aside class="hsm-author-box" style="display:flex;gap:14px;align-items:flex-start;margin:28px 0;padding:16px;border:1px solid #e5e5e5;border-radius:6px;background:#fafafa">'
		. get_avatar( $author->ID, 56 )
		. '<div><strong>' . esc_html( $author->display_name ) . '</strong>'
		. ( $bio ? '<p style="margin:6px 0 0">' . esc_html( $bio ) . '</p>' : '' )
		. '</div></aside>';
}

function hsm_content_share( $post ) {
	$url   = rawurlencode( get_permalink( $post ) );
	$title = rawurlencode( get_the_title( $post ) );
	$links = array(
		'X'        => "https://twitter.com/intent/tweet?url={$url}&text={$title}",
		'Facebook' => "https://www.facebook.com/sharer/sharer.php?u={$url}",
		'LinkedIn' => "https://www.linkedin.com/sharing/share-offsite/?url={$url}",
		'Email'    => "mailto:?subject={$title}&body={$url}",
	);
	$out = '<div class="hsm-share" style="margin:24px 0"><strong>Share:</strong> ';
	foreach ( $links as $label => $href ) {
		$out .= '<a href="' . esc_url( $href ) . '" target="_blank" rel="noopener nofollow" style="margin-left:10px">' . esc_html( $label ) . '</a>';
	}
	return $out . '</div>';
}

add_filter( 'the_content', function ( $content ) {
	if ( ! hsm_content_is_post_main() || ! hsm_content_on( 'article' ) ) {
		return $content;
	}
	$s    = hsm_content_settings()['article'];
	$post = get_post();
	$top  = '';
	$bot  = '';
	if ( ! empty( $s['reading_time'] ) ) {
		$top .= '<p class="hsm-reading-time" style="color:#666"><em>' . esc_html( hsm_content_reading_time( $content ) ) . ' min read</em></p>';
	}
	if ( ! empty( $s['toc'] ) ) {
		list( $content, $toc ) = hsm_content_toc( $content );
		$top .= $toc;
	}
	if ( ! empty( $s['views'] ) ) {
		$views = (int) get_post_meta( $post->ID, '_hsm_views', true );
		$top  .= '<p class="hsm-views" style="color:#888;font-size:14px">' . esc_html( $views ) . ' views</p>';
	}
	if ( ! empty( $s['share'] ) ) {
		$bot .= hsm_content_share( $post );
	}
	if ( ! empty( $s['author_box'] ) ) {
		$bot .= hsm_content_author_box( $post );
	}
	return $top . $content . $bot;
}, 12 );

/** Count a view on a single post. */
add_action( 'wp', function () {
	if ( hsm_content_on( 'article' ) && hsm_content_settings()['article']['views'] && is_singular( 'post' ) && ! is_user_logged_in() ) {
		$id = get_queried_object_id();
		if ( $id ) {
			update_post_meta( $id, '_hsm_views', (int) get_post_meta( $id, '_hsm_views', true ) + 1 );
		}
	}
} );

/** External links in post content get rel="nofollow noopener". */
add_filter( 'the_content', function ( $content ) {
	if ( ! hsm_content_on( 'external_nofollow' ) || ! is_singular() ) {
		return $content;
	}
	$home = wp_parse_url( home_url(), PHP_URL_HOST );
	return preg_replace_callback( '/<a\s([^>]*href=["\']([^"\']+)["\'][^>]*)>/i', function ( $m ) use ( $home ) {
		$host = wp_parse_url( $m[2], PHP_URL_HOST );
		if ( ! $host || $host === $home ) {
			return $m[0];
		}
		if ( preg_match( '/\brel=/i', $m[1] ) ) {
			return $m[0];
		}
		return '<a ' . $m[1] . ' rel="nofollow noopener">';
	}, $content );
}, 11 );

/* --------------------------------------------------------------------- */
/* FAQ blocks + FAQPage structured data                                    */
/* --------------------------------------------------------------------- */

function hsm_content_faq_items( $post_id ) {
	$items = get_post_meta( $post_id, '_hsm_faq', true );
	return is_array( $items ) ? $items : array();
}

/** [hsm_faq] shortcode — renders the FAQ stored on the post. */
add_shortcode( 'hsm_faq', function ( $atts ) {
	if ( ! hsm_content_on( 'faq' ) ) {
		return '';
	}
	$post_id = (int) ( $atts['post_id'] ?? get_the_ID() );
	$items   = hsm_content_faq_items( $post_id );
	if ( ! $items ) {
		return '';
	}
	$out = '<div class="hsm-faq">';
	foreach ( $items as $qa ) {
		$out .= '<details class="hsm-faq-item"><summary>' . esc_html( (string) ( $qa['q'] ?? '' ) ) . '</summary><div>' . wp_kses_post( wpautop( (string) ( $qa['a'] ?? '' ) ) ) . '</div></details>';
	}
	return $out . '</div>';
} );

add_action( 'wp_head', function () {
	if ( ! hsm_content_on( 'faq' ) || ! is_singular( 'post' ) || hsm_other_seo_active() ) {
		return;
	}
	$items = hsm_content_faq_items( get_queried_object_id() );
	if ( ! $items ) {
		return;
	}
	$main = array();
	foreach ( $items as $qa ) {
		if ( empty( $qa['q'] ) ) {
			continue;
		}
		$main[] = array(
			'@type'          => 'Question',
			'name'           => wp_strip_all_tags( (string) $qa['q'] ),
			'acceptedAnswer' => array( '@type' => 'Answer', 'text' => wp_strip_all_tags( (string) ( $qa['a'] ?? '' ) ) ),
		);
	}
	if ( ! $main ) {
		return;
	}
	echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $main ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
}, 21 );

/* --------------------------------------------------------------------- */
/* Virtual /llms.txt and /ads.txt                                          */
/* --------------------------------------------------------------------- */

add_action( 'init', function () {
	add_rewrite_rule( '^llms\.txt$', 'index.php?hsm_llms=1', 'top' );
	add_rewrite_rule( '^ads\.txt$', 'index.php?hsm_ads=1', 'top' );
} );
add_filter( 'query_vars', function ( $v ) {
	$v[] = 'hsm_llms';
	$v[] = 'hsm_ads';
	return $v;
} );
add_action( 'template_redirect', function () {
	if ( get_query_var( 'hsm_llms' ) && hsm_content_on( 'llms' ) ) {
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo hsm_content_llms_txt(); // phpcs:ignore WordPress.Security.EscapeOutput -- plain text file
		exit;
	}
	if ( get_query_var( 'hsm_ads' ) && hsm_content_on( 'ads' ) ) {
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo (string) hsm_content_settings()['ads']['content']; // phpcs:ignore WordPress.Security.EscapeOutput -- plain text file
		exit;
	}
}, 1 );

/** Do not let redirect_canonical add a trailing slash to these virtual files. */
add_filter( 'redirect_canonical', function ( $redirect, $requested ) {
	if ( get_query_var( 'hsm_llms' ) || get_query_var( 'hsm_ads' ) || get_query_var( 'hsm_indexnow_key' ) ) {
		return false;
	}
	return $redirect;
}, 10, 2 );

/** The /llms.txt body: either the owner's text or a generated summary. */
function hsm_content_llms_txt() {
	$custom = trim( (string) hsm_content_settings()['llms']['content'] );
	if ( '' !== $custom ) {
		return $custom . "\n";
	}
	$out  = '# ' . get_bloginfo( 'name' ) . "\n\n> " . get_bloginfo( 'description' ) . "\n\n";
	$out .= '## Key pages' . "\n";
	$pages = get_posts( array( 'post_type' => 'page', 'numberposts' => 20, 'post_status' => 'publish' ) );
	foreach ( $pages as $p ) {
		$out .= '- [' . $p->post_title . '](' . get_permalink( $p ) . ")\n";
	}
	$cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 20 ) );
	if ( $cats && ! is_wp_error( $cats ) ) {
		$out .= "\n## Product categories\n";
		foreach ( $cats as $c ) {
			$out .= '- [' . $c->name . '](' . get_term_link( $c ) . ")\n";
		}
	}
	$products = wc_get_products( array( 'limit' => 20, 'status' => 'publish', 'orderby' => 'popularity' ) );
	if ( $products ) {
		$out .= "\n## Popular products\n";
		foreach ( $products as $p ) {
			$out .= '- [' . $p->get_name() . '](' . get_permalink( $p->get_id() ) . ')' . ( $p->get_price() ? ' — ' . wp_strip_all_tags( wc_price( $p->get_price() ) ) : '' ) . "\n";
		}
	}
	return $out;
}

/* --------------------------------------------------------------------- */
/* IndexNow: tell search engines a URL changed                             */
/* --------------------------------------------------------------------- */

function hsm_content_indexnow( $url ) {
	$s = hsm_content_settings()['indexnow'];
	if ( empty( $s['enabled'] ) || empty( $s['key'] ) ) {
		return false;
	}
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	$body = array( 'host' => $host, 'key' => $s['key'], 'keyLocation' => home_url( '/' . $s['key'] . '.txt' ), 'urlList' => array( $url ) );
	$res  = wp_remote_post( 'https://api.indexnow.org/indexnow', array( 'timeout' => 10, 'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ), 'body' => wp_json_encode( $body ) ) );
	return ! is_wp_error( $res );
}

add_action( 'transition_post_status', function ( $new, $old, $post ) {
	if ( 'publish' === $new && 'publish' !== $old && 'post' === $post->post_type ) {
		hsm_content_indexnow( get_permalink( $post ) );
	}
}, 10, 3 );

/** Serve the IndexNow key file at /<key>.txt */
add_action( 'init', function () {
	$key = (string) hsm_content_settings()['indexnow']['key'];
	if ( '' === $key ) {
		return;
	}
	add_rewrite_rule( '^' . preg_quote( $key, '#' ) . '\.txt$', 'index.php?hsm_indexnow_key=1', 'top' );
} );
add_filter( 'query_vars', function ( $v ) {
	$v[] = 'hsm_indexnow_key';
	return $v;
} );
add_action( 'template_redirect', function () {
	if ( get_query_var( 'hsm_indexnow_key' ) ) {
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( (string) hsm_content_settings()['indexnow']['key'] );
		exit;
	}
}, 1 );

/* --------------------------------------------------------------------- */
/* Product cards inside posts                                              */
/* --------------------------------------------------------------------- */

function hsm_content_product_card( $product ) {
	if ( ! $product ) {
		return '';
	}
	$img = $product->get_image_id() ? wp_get_attachment_image( $product->get_image_id(), 'woocommerce_thumbnail', false, array( 'style' => 'width:80px;height:80px;object-fit:cover;border-radius:6px' ) ) : '';
	return '<a class="hsm-product-card" href="' . esc_url( get_permalink( $product->get_id() ) ) . '" style="display:flex;gap:12px;align-items:center;padding:12px;border:1px solid #e5e5e5;border-radius:8px;text-decoration:none;color:inherit;margin:12px 0">'
		. $img
		. '<span><strong>' . esc_html( $product->get_name() ) . '</strong><br><span>' . wp_kses_post( wc_price( $product->get_price() ) ) . '</span></span></a>';
}

/** [hsm_product sku="X"] or [hsm_product id="12"] */
add_shortcode( 'hsm_product', function ( $atts ) {
	$atts = shortcode_atts( array( 'sku' => '', 'id' => 0 ), $atts );
	$p    = ! empty( $atts['sku'] ) ? wc_get_product( wc_get_product_id_by_sku( $atts['sku'] ) ) : wc_get_product( (int) $atts['id'] );
	return hsm_content_product_card( $p );
} );

/** [hsm_products skus="A,B,C" columns="3"] */
add_shortcode( 'hsm_products', function ( $atts ) {
	$atts  = shortcode_atts( array( 'skus' => '', 'ids' => '', 'columns' => 3 ), $atts );
	$ids   = array();
	foreach ( array_filter( array_map( 'trim', explode( ',', (string) $atts['skus'] ) ) ) as $sku ) {
		$id = wc_get_product_id_by_sku( $sku );
		if ( $id ) {
			$ids[] = $id;
		}
	}
	foreach ( array_filter( array_map( 'absint', explode( ',', (string) $atts['ids'] ) ) ) as $id ) {
		$ids[] = $id;
	}
	if ( ! $ids ) {
		return '';
	}
	$out = '<div class="hsm-product-grid" style="display:grid;grid-template-columns:repeat(' . max( 1, (int) $atts['columns'] ) . ',1fr);gap:12px">';
	foreach ( $ids as $id ) {
		$out .= hsm_content_product_card( wc_get_product( $id ) );
	}
	return $out . '</div>';
} );

/** [hsm_categories slugs="a,b" columns="3" title="Shop by topic"] — linked category cards. */
add_shortcode( 'hsm_categories', function ( $atts ) {
	$atts  = shortcode_atts( array( 'slugs' => '', 'columns' => 3, 'title' => '' ), $atts );
	$out   = '' !== trim( (string) $atts['title'] ) ? '<h3>' . esc_html( $atts['title'] ) . '</h3>' : '';
	$items = array();
	foreach ( array_filter( array_map( 'trim', explode( ',', (string) $atts['slugs'] ) ) ) as $slug ) {
		$t = get_term_by( 'slug', sanitize_title( $slug ), 'product_cat' );
		if ( $t ) {
			$items[] = $t;
		}
	}
	if ( ! $items ) {
		return $out;
	}
	$out .= '<div class="hsm-cat-grid" style="display:grid;grid-template-columns:repeat(' . max( 1, (int) $atts['columns'] ) . ',1fr);gap:12px">';
	foreach ( $items as $t ) {
		$out .= '<a class="hsm-cat-card" href="' . esc_url( get_term_link( $t ) ) . '" style="padding:12px;border:1px solid #e5e5e5;border-radius:8px;text-decoration:none;color:inherit;display:block">'
			. '<strong>' . esc_html( $t->name ) . '</strong>'
			. ( $t->description ? '<div style="color:#666;font-size:14px">' . esc_html( wp_trim_words( $t->description, 15 ) ) . '</div>' : '' )
			. '<div style="color:#888;font-size:13px">' . (int) $t->count . ' products</div></a>';
	}
	return $out . '</div>';
} );

/**
 * Link the first mention of each product / category name in a post body to its page.
 * Text already inside a link or a heading is protected, so no nested links are created.
 * Returns [ new_content, links_added ].
 */
function hsm_content_auto_link( $post, $targets = 'both', $max = 10 ) {
	$content = (string) $post->post_content;
	$protect = array();
	$content = preg_replace_callback( '#<(a\b[^>]*>.*?</a>|h[1-6]\b[^>]*>.*?</h[1-6]>)#is', function ( $m ) use ( &$protect ) {
		$k             = "\x1A" . count( $protect ) . "\x1A";
		$protect[ $k ] = $m[0];
		return $k;
	}, $content );

	$names = array();
	if ( in_array( $targets, array( 'categories', 'both' ), true ) ) {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true ) );
		foreach ( ( is_wp_error( $terms ) ? array() : $terms ) as $t ) {
			if ( mb_strlen( $t->name ) >= 4 ) {
				$names[ $t->name ] = get_term_link( $t );
			}
		}
	}
	if ( in_array( $targets, array( 'products', 'both' ), true ) ) {
		foreach ( get_posts( array( 'post_type' => 'product', 'numberposts' => 300, 'post_status' => 'publish' ) ) as $p ) {
			if ( mb_strlen( $p->post_title ) >= 4 ) {
				$names[ $p->post_title ] = get_permalink( $p );
			}
		}
	}
	$added = 0;
	foreach ( $names as $name => $url ) {
		if ( $added >= $max || ! $url || is_wp_error( $url ) ) {
			continue;
		}
		$content = preg_replace_callback(
			'#(?<![\w>])(' . preg_quote( $name, '#' ) . ')(?![\w<])#iu',
			function ( $m ) use ( $url, &$added ) {
				$added++;
				return '<a href="' . esc_url( $url ) . '">' . $m[1] . '</a>';
			},
			$content,
			1
		);
	}
	return array( strtr( $content, $protect ), $added );
}

/* --------------------------------------------------------------------- */
/* MCP tools                                                               */
/* --------------------------------------------------------------------- */

function hsm_content_actions() {
	return array( 'get_settings' => false, 'llms_preview' => false, 'calendar' => false, 'set_settings' => true, 'faq_set' => true, 'insert_cards' => true, 'schedule' => true, 'legal_pages' => true, 'auto_link' => true );
}

hsm_ability( 'content', array(
	'label'       => 'Content & traffic',
	'description' => 'Blog/traffic toolkit. action=get_settings | set_settings (merge a subset of: article {enabled, author_box, toc, reading_time, share, views} | faq {enabled} | external_nofollow {enabled} | llms {enabled, content} | ads {enabled, content} | indexnow {enabled, key}) | llms_preview (the /llms.txt that would be served) | faq_set (post_id, items:[{q,a}] — also emits FAQPage structured data) | insert_cards (post_id, skus or ids, columns, after_paragraph — inserts product cards into the post body; omit after_paragraph to append at the end) | auto_link (post_id, targets: products|categories|both, max — links the first mention of each product/category name to its page, skipping text already in a link or heading) | schedule (post_id, datetime YYYY-MM-DD HH:MM — schedule publishing; empty datetime = publish now) | calendar (upcoming scheduled posts) | legal_pages (create privacy/terms/refunds/shipping pages from store info; types:[...]). Shortcodes: [hsm_product sku|id], [hsm_products skus|ids columns], [hsm_categories slugs columns title], [hsm_faq]. Front-end features apply to blog posts only.',
	'properties'  => array(
		'action'          => hsm_s( 'string', '', array( 'enum' => array_keys( hsm_content_actions() ) ) ),
		'settings'        => hsm_s( 'object' ),
		'post_id'         => hsm_s( 'integer' ),
		'items'           => hsm_s( 'array', 'faq_set: [{q,a}]' ),
		'skus'            => hsm_s( 'array', 'insert_cards: product SKUs.' ),
		'ids'             => hsm_s( 'array', 'insert_cards: product IDs.' ),
		'columns'         => hsm_s( 'integer' ),
		'after_paragraph' => hsm_s( 'integer', 'insert_cards: insert after this paragraph number (1-based).' ),
		'datetime'        => hsm_s( 'string', 'schedule: YYYY-MM-DD HH:MM (site time).' ),
		'types'           => hsm_s( 'array', 'legal_pages: privacy, terms, refunds, shipping.' ),
		'targets'         => hsm_s( 'string', 'auto_link: products | categories | both.', array( 'enum' => array( 'products', 'categories', 'both' ) ) ),
		'max'             => hsm_s( 'integer', 'auto_link: max links to add (default 10).' ),
		'limit'           => hsm_s( 'integer' ),
	),
	'is_write'    => function ( $in ) {
		return ! empty( hsm_content_actions()[ $in['action'] ?? '' ] );
	},
	'callback'    => function ( $in ) {
		$action = (string) ( $in['action'] ?? 'get_settings' );
		if ( ! array_key_exists( $action, hsm_content_actions() ) ) {
			throw new Exception( 'Unknown action. Allowed: ' . implode( ', ', array_keys( hsm_content_actions() ) ) );
		}
		if ( 'get_settings' === $action ) {
			return hsm_content_settings();
		}
		if ( 'llms_preview' === $action ) {
			return array( 'url' => home_url( '/llms.txt' ), 'enabled' => hsm_content_on( 'llms' ), 'content' => hsm_content_llms_txt() );
		}
		if ( 'calendar' === $action ) {
			$limit = max( 1, min( 100, (int) ( $in['limit'] ?? 20 ) ) );
			$posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'future', 'numberposts' => $limit, 'orderby' => 'date', 'order' => 'ASC' ) );
			return array( 'count' => count( $posts ), 'scheduled' => array_map( function ( $p ) {
				return array( 'id' => $p->ID, 'title' => $p->post_title, 'publish_at' => $p->post_date, 'author' => get_the_author_meta( 'user_login', $p->post_author ) );
			}, $posts ) );
		}
		// Every remaining content action writes content or site configuration; require approval before its branch can mutate.
		hsm_confirm( $in, 'Content operation: ' . $action, array( 'action' => $action, 'input' => $in ) );
		if ( 'set_settings' === $action ) {
			$incoming = (array) ( $in['settings'] ?? array() );
			if ( ! $incoming ) {
				throw new Exception( 'settings must be a non-empty object.' );
			}
			$before = get_option( 'hsm_content', array() );
			$new    = $before;
			foreach ( $incoming as $key => $val ) {
				if ( ! isset( hsm_content_defaults()[ $key ] ) ) {
					throw new Exception( 'Unknown settings key: ' . $key );
				}
				// Merge onto the CURRENT group so a partial update doesn't reset the rest to defaults.
				$cur         = wp_parse_args( is_array( $before[ $key ] ?? null ) ? $before[ $key ] : array(), hsm_content_defaults()[ $key ] );
				$new[ $key ] = hsm_content_sanitize( $key, array_merge( $cur, (array) $val ) );
			}
			hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_content', 'old' => $before ) );
			update_option( 'hsm_content', $new );
			flush_rewrite_rules( false );
			return array( 'action' => 'set_settings', 'changed' => array_keys( $incoming ), 'settings' => hsm_content_settings() );
		}
		if ( 'faq_set' === $action ) {
			$post = get_post( (int) ( $in['post_id'] ?? 0 ) );
			if ( ! $post ) {
				throw new Exception( 'Post not found.' );
			}
			$clean = array();
			foreach ( (array) ( $in['items'] ?? array() ) as $qa ) {
				if ( empty( $qa['q'] ) ) {
					continue;
				}
				$clean[] = array( 'q' => sanitize_text_field( (string) $qa['q'] ), 'a' => wp_kses_post( (string) ( $qa['a'] ?? '' ) ) );
			}
			hsm_undo_add( array( 'type' => 'post_meta', 'id' => $post->ID, 'key' => '_hsm_faq', 'old' => get_post_meta( $post->ID, '_hsm_faq', true ) ) );
			update_post_meta( $post->ID, '_hsm_faq', $clean );
			return array( 'action' => 'faq_set', 'post_id' => $post->ID, 'count' => count( $clean ) );
		}
		if ( 'insert_cards' === $action ) {
			$post = get_post( (int) ( $in['post_id'] ?? 0 ) );
			if ( ! $post ) {
				throw new Exception( 'Post not found.' );
			}
			$skus = array();
			foreach ( (array) ( $in['skus'] ?? array() ) as $sku ) {
				$id = wc_get_product_id_by_sku( (string) $sku );
				if ( $id ) {
					$skus[] = (int) $id;
				}
			}
			foreach ( (array) ( $in['ids'] ?? array() ) as $id ) {
				if ( wc_get_product( (int) $id ) ) {
					$skus[] = (int) $id;
				}
			}
			if ( ! $skus ) {
				throw new Exception( 'No valid products given (skus/ids).' );
			}
			$shortcode = '[hsm_products ids="' . implode( ',', $skus ) . '" columns="' . max( 1, (int) ( $in['columns'] ?? 3 ) ) . '"]';
			$content   = $post->post_content;
			if ( ! empty( $in['after_paragraph'] ) ) {
				$parts = preg_split( '/(\n\s*\n)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
				$paras = 0;
				$out   = '';
				$done  = false;
				foreach ( $parts as $chunk ) {
					$out .= $chunk;
					if ( trim( $chunk ) !== '' ) {
						$paras++;
						if ( ! $done && $paras >= (int) $in['after_paragraph'] ) {
							$out  .= "\n\n" . $shortcode;
							$done  = true;
						}
					}
				}
				$new = $done ? $out : $content . "\n\n" . $shortcode;
			} else {
				$new = rtrim( $content ) . "\n\n" . $shortcode;
			}
			hsm_undo_add( array( 'type' => 'post', 'id' => $post->ID, 'state' => array( 'ID' => $post->ID, 'post_content' => $post->post_content ) ) );
			wp_update_post( array( 'ID' => $post->ID, 'post_content' => wp_slash( $new ) ) );
			return array( 'action' => 'insert_cards', 'post_id' => $post->ID, 'products' => count( $skus ), 'shortcode' => $shortcode );
		}
		if ( 'schedule' === $action ) {
			$post = get_post( (int) ( $in['post_id'] ?? 0 ) );
			if ( ! $post ) {
				throw new Exception( 'Post not found.' );
			}
			$dt = trim( (string) ( $in['datetime'] ?? '' ) );
			if ( '' === $dt ) {
				$when = null; // publish now
			} else {
				try {
					$when = date_create( $dt, wp_timezone() ); // input is site-local time
				} catch ( Exception $e ) {
					$when = false;
				}
				if ( ! $when ) {
					throw new Exception( 'datetime must be YYYY-MM-DD HH:MM.' );
				}
			}
			$future = $when && $when->getTimestamp() > time();
			if ( $when ) {
				$local = $when->format( 'Y-m-d H:i:s' );
				$gmt   = $when->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			} else {
				$local = current_time( 'mysql' );
				$gmt   = current_time( 'mysql', true );
			}
			hsm_undo_add( array( 'type' => 'post', 'id' => $post->ID, 'state' => array( 'ID' => $post->ID, 'post_status' => $post->post_status, 'post_date' => $post->post_date, 'edit_date' => true ) ) );
			wp_update_post( array( 'ID' => $post->ID, 'post_status' => $future ? 'future' : 'publish', 'post_date' => $local, 'post_date_gmt' => $gmt, 'edit_date' => true ) );
			$now = get_post( $post->ID );
			return array( 'action' => 'schedule', 'post_id' => $post->ID, 'status' => $now->post_status, 'date_local' => $now->post_date );
		}
		if ( 'auto_link' === $action ) {
			$post = get_post( (int) ( $in['post_id'] ?? 0 ) );
			if ( ! $post ) {
				throw new Exception( 'Post not found.' );
			}
			$targets = in_array( $in['targets'] ?? 'both', array( 'products', 'categories', 'both' ), true ) ? $in['targets'] : 'both';
			$max     = max( 1, min( 30, (int) ( $in['max'] ?? 10 ) ) );
			list( $new, $added ) = hsm_content_auto_link( $post, $targets, $max );
			if ( ! $added ) {
				return array( 'action' => 'auto_link', 'post_id' => $post->ID, 'links_added' => 0, 'note' => 'No product or category names matched.' );
			}
			hsm_undo_add( array( 'type' => 'post', 'id' => $post->ID, 'state' => array( 'ID' => $post->ID, 'post_content' => $post->post_content ) ) );
			wp_update_post( array( 'ID' => $post->ID, 'post_content' => wp_slash( $new ) ) );
			return array( 'action' => 'auto_link', 'post_id' => $post->ID, 'links_added' => $added, 'targets' => $targets );
		}
		if ( 'legal_pages' === $action ) {
			$types = (array) ( $in['types'] ?? array( 'privacy', 'terms', 'refunds', 'shipping' ) );
			$made  = array();
			foreach ( $types as $t ) {
				$r = hsm_content_make_legal_page( (string) $t );
				if ( $r ) {
					$made[] = $r;
				}
			}
			return array( 'action' => 'legal_pages', 'created' => $made, 'note' => 'Template text — have a lawyer review before publishing.' );
		}
		throw new Exception( 'Unhandled action.' );
	},
) );

/** Sanitize one content settings group. */
function hsm_content_sanitize( $key, $val ) {
	$val = is_array( $val ) ? $val : array();
	$def = hsm_content_defaults()[ $key ];
	$out = array();
	foreach ( $def as $k => $d ) {
		if ( ! array_key_exists( $k, $val ) ) {
			$out[ $k ] = $d;
			continue;
		}
		$out[ $k ] = is_bool( $d ) ? (bool) $val[ $k ] : ( 'key' === $k ? substr( preg_replace( '/[^A-Za-z0-9-]/', '', (string) $val[ $k ] ), 0, 64 ) : wp_kses_post( (string) $val[ $k ] ) );
	}
	return $out;
}

/** Create one legal page from a template filled with store information. */
function hsm_content_make_legal_page( $type ) {
	$type = sanitize_key( $type );
	$titles = array( 'privacy' => 'Privacy Policy', 'terms' => 'Terms of Service', 'refunds' => 'Refund & Returns Policy', 'shipping' => 'Shipping Policy' );
	if ( ! isset( $titles[ $type ] ) ) {
		return null;
	}
	$name    = get_bloginfo( 'name' );
	$email   = get_option( 'admin_email' );
	$address = get_option( 'woocommerce_store_address' );
	$days    = (int) ( function_exists( 'hsm_seo_settings' ) ? hsm_seo_settings()['return_days'] : 30 );
	$body    = array(
		'privacy'  => "<p>{$name} respects your privacy. This policy explains what we collect and why.</p><h2>What we collect</h2><p>Order details (name, address, email, phone) to fulfil orders, and analytics to improve the store.</p><h2>Sharing</h2><p>We share order data with payment and shipping providers only as needed. We do not sell personal information (CPRA).</p><h2>Your rights</h2><p>Email {$email} to access, correct or delete your data.</p>",
		'terms'    => "<p>By using {$name} you agree to these terms.</p><h2>Orders</h2><p>We may refuse or cancel orders. Prices and availability may change.</p><h2>Liability</h2><p>The store is provided as-is to the extent permitted by law.</p>",
		'refunds'  => "<p>{$name} accepts returns within {$days} days of delivery for unused items in original packaging.</p><h2>How to start a return</h2><p>Email {$email} with your order number.</p><h2>Refunds</h2><p>Refunds are issued to the original payment method once we receive the item.</p>",
		'shipping' => "<p>{$name} ships from {$address}.</p><h2>Processing</h2><p>Orders are processed within 1–2 business days.</p><h2>Tracking</h2><p>You receive a tracking number by email once your order ships.</p>",
	);
	$existing = get_page_by_path( $type );
	if ( $existing ) {
		return array( 'type' => $type, 'id' => $existing->ID, 'skipped' => 'a page with this slug already exists', 'url' => get_permalink( $existing ) );
	}
	$id = wp_insert_post( array(
		'post_type'    => 'page',
		'post_status'  => 'draft',
		'post_title'   => $titles[ $type ],
		'post_name'    => $type,
		'post_content' => $body[ $type ] . "\n<!-- wp:paragraph --><p><em>Template — have a lawyer review before publishing.</em></p><!-- /wp:paragraph -->",
	) );
	if ( is_wp_error( $id ) ) {
		return null;
	}
	hsm_undo_add( array( 'type' => 'post', 'id' => $id, 'created' => true ) );
	return array( 'type' => $type, 'id' => $id, 'status' => 'draft', 'url' => get_permalink( $id ) );
}
