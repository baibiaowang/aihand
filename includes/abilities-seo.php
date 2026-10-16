<?php
defined( 'ABSPATH' ) || exit;

hsm_ability( 'seo-update', array(
	'label'       => 'Set SEO fields for products, posts, pages or categories',
	'description' => 'Bulk-capable. items: [{id (post/product id) | sku | term (category name/id with taxonomy), title, description, focus_keyword, canonical, noindex, nofollow, og_image, gtin, mpn, brand, condition (new|used|refurbished), google_category, feed_exclude}]. Empty string clears a field. Returns lengths so you can keep titles ≈50-60 and descriptions ≈140-160 characters.',
	'idempotent'  => true,
	'cap'         => 'edit_posts',
	'properties'  => array( 'items' => hsm_s( 'array' ) ),
	'required'    => array( 'items' ),
	'callback'    => function ( $in ) {
		$res = array();
		if ( ! is_array( $in['items'] ?? null ) || ! $in['items'] || array_filter( $in['items'], function ( $it ) { return ! is_array( $it ); } ) ) {
			throw new Exception( 'items must be a non-empty array of objects.' );
		}
		if ( count( (array) $in['items'] ) > (int) hsm_guard( 'max_bulk_items' ) ) {
			throw new HSM_Blocked( 'Too many items in one call (guard max_bulk_items).' );
		}
		// All SEO metadata changes need explicit review, not only noindex changes.
		hsm_confirm( $in, 'Update SEO fields for ' . count( $in['items'] ) . ' item(s)', array( 'items' => $in['items'] ) );
		$noidx = array_filter( (array) $in['items'], function ( $it ) { return ! empty( $it['noindex'] ) && filter_var( $it['noindex'], FILTER_VALIDATE_BOOLEAN ); } );
		if ( $noidx ) {
			hsm_confirm( $in, count( $noidx ) . ' item(s) will be hidden from search engines (noindex)', array_values( array_map( function ( $it ) { return $it['id'] ?? $it['sku'] ?? $it['term'] ?? null; }, $noidx ) ) );
		}
		foreach ( (array) $in['items'] as $it ) {
			if ( ! empty( $it['term'] ) ) {
				$tax = $it['taxonomy'] ?? 'product_cat';
				$tid = hsm_terms( array( $it['term'] ), $tax, false )[0] ?? 0;
				if ( ! $tid ) {
					$res[] = array( 'term' => $it['term'], 'error' => 'not found' );
					continue;
				}
				if ( ! current_user_can( 'edit_term', $tid ) ) {
					$res[] = array( 'term_id' => $tid, 'error' => 'not allowed' );
					continue;
				}
				foreach ( array( 'title', 'description', 'noindex' ) as $k ) {
					if ( isset( $it[ $k ] ) ) {
						hsm_undo_add( array( 'type' => 'term_meta', 'id' => $tid, 'key' => '_hsm_seo_' . $k, 'old' => get_term_meta( $tid, '_hsm_seo_' . $k, true ) ) );
						update_term_meta( $tid, '_hsm_seo_' . $k, 'noindex' === $k ? ( filter_var( $it[ $k ], FILTER_VALIDATE_BOOLEAN ) ? 1 : '' ) : sanitize_text_field( $it[ $k ] ) );
					}
				}
				$res[] = array( 'term_id' => $tid, 'updated' => true );
				continue;
			}
			$id = ! empty( $it['id'] ) ? (int) $it['id'] : ( ! empty( $it['sku'] ) && function_exists( 'wc_get_product_id_by_sku' ) ? wc_get_product_id_by_sku( $it['sku'] ) : 0 );
			if ( ! $id || ! get_post( $id ) ) {
				$res[] = array( 'ref' => $it['id'] ?? $it['sku'] ?? null, 'error' => 'not found' );
				continue;
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				$res[] = array( 'id' => $id, 'error' => 'not allowed' );
				continue;
			}
			hsm_set_seo( $id, $it );
			foreach ( array( 'google_category' => '_hsm_google_category', 'feed_exclude' => '_hsm_feed_exclude' ) as $k => $mk ) {
				if ( isset( $it[ $k ] ) ) {
					hsm_undo_add( array( 'type' => 'post_meta', 'id' => $id, 'key' => $mk, 'old' => metadata_exists( 'post', $id, $mk ) ? get_post_meta( $id, $mk, true ) : null ) );
					update_post_meta( $id, $mk, 'feed_exclude' === $k ? ( filter_var( $it[ $k ], FILTER_VALIDATE_BOOLEAN ) ? 1 : '' ) : sanitize_text_field( $it[ $k ] ) );
				}
			}
			if ( isset( $it['gtin'] ) && function_exists( 'wc_get_product' ) && ( $p = wc_get_product( $id ) ) && method_exists( $p, 'set_global_unique_id' ) ) {
				try {
					$p->set_global_unique_id( $it['gtin'] );
					$p->save();
				} catch ( \Throwable $e ) {
					// Keep the SEO-meta copy even if Woo rejects the GTIN format.
				}
			}
			$seo   = hsm_get_seo( $id );
			$res[] = array( 'id' => $id, 'title_len' => mb_strlen( $seo['title'] ?? '' ), 'description_len' => mb_strlen( $seo['description'] ?? '' ), 'seo' => $seo );
		}
		return array( 'results' => $res, 'note' => hsm_other_seo_active() ? 'Another SEO plugin is active: values were mirrored into it where possible; built-in output is disabled.' : 'Built-in SEO output active.' );
	},
) );

hsm_ability( 'seo-audit', array(
	'label'       => 'SEO & merchant-listing audit',
	'description' => 'Scans published products (and optionally posts/pages) and reports issues: missing/short/long meta description, title too long, duplicate titles or descriptions, missing main image, images without alt text, thin description (<300 chars), no category, missing price, no SKU, no brand/GTIN/MPN (Google Merchant), noindexed items, out of stock. Also checks site-level: search-engine visibility, permalinks, organization info, sitemap and feed URLs. Returns a score and a prioritized issue list with ids to fix via seo-update / product-upsert.',
	'readonly'    => true,
	'idempotent'  => true,
	'cap'         => 'edit_posts',
	'properties'  => array( 'include_posts' => hsm_s( 'boolean' ), 'limit' => hsm_s( 'integer', 'Max items to scan (default 500).' ) ),
	'callback'    => function ( $in ) {
		$s      = hsm_seo_settings();
		$issues = array();
		$site   = array();
		if ( ! get_option( 'blog_public' ) ) {
			$site[] = 'CRITICAL: "Discourage search engines" is on (store-settings blog_public=true to fix).';
		}
		if ( ! get_option( 'permalink_structure' ) ) {
			$site[] = 'HIGH: Plain permalinks; set pretty permalinks (site-extensions action=permalinks).';
		}
		if ( ! $s['org_name'] || ! $s['org_logo'] ) {
			$site[] = 'MEDIUM: Organization name/logo not set (seo-settings org_name, org_logo).';
		}
		if ( ! $s['default_brand'] ) {
			$site[] = 'LOW: default_brand not set; products without a brand will lack brand in schema/feed.';
		}
		if ( hsm_other_seo_active() ) {
			$site[] = 'INFO: Another SEO plugin is active; built-in SEO output is disabled to avoid duplicates.';
		}
		$types = array( 'product' );
		! empty( $in['include_posts'] ) && $types = array_merge( $types, array( 'post', 'page' ) );
		$posts  = get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'numberposts' => (int) ( $in['limit'] ?? 500 ) ) );
		$titles = array();
		$descs  = array();
		foreach ( $posts as $post ) {
			$seo   = hsm_get_seo( $post->ID );
			$title = $seo['title'] ?? $post->post_title . ' ' . $s['separator'] . ' ' . get_bloginfo( 'name' );
			$desc  = $seo['description'] ?? '';
			$prob  = array();
			if ( mb_strlen( $title ) > 65 ) {
				$prob[] = 'title too long (' . mb_strlen( $title ) . ')';
			}
			if ( ! $desc ) {
				$prob[] = 'no custom meta description';
			} elseif ( mb_strlen( $desc ) < 70 ) {
				$prob[] = 'meta description short (' . mb_strlen( $desc ) . ')';
			} elseif ( mb_strlen( $desc ) > 165 ) {
				$prob[] = 'meta description long (' . mb_strlen( $desc ) . ')';
			}
			$titles[ mb_strtolower( $title ) ][] = $post->ID;
			$desc && $descs[ mb_strtolower( $desc ) ][] = $post->ID;
			! empty( $seo['noindex'] ) && $prob[] = 'noindex';
			if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
				$p = wc_get_product( $post->ID );
				if ( ! $p ) {
					continue;
				}
				! $p->get_image_id() && $prob[] = 'no main image';
				foreach ( array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() ) as $img ) {
					if ( $img && ! get_post_meta( $img, '_wp_attachment_image_alt', true ) ) {
						$prob[] = 'image without alt text';
						break;
					}
				}
				mb_strlen( wp_strip_all_tags( $p->get_description() ) ) < 300 && $prob[] = 'thin description';
				! $p->get_category_ids() && $prob[] = 'no category';
				'' === $p->get_price() && $prob[] = 'no price';
				! $p->get_sku() && $prob[] = 'no SKU';
				$brand = ( $seo['brand'] ?? '' ) ?: ( taxonomy_exists( 'product_brand' ) ? wp_get_post_terms( $p->get_id(), 'product_brand', array( 'fields' => 'names' ) ) : array() );
				( ! $brand && ! $s['default_brand'] ) && $prob[] = 'no brand';
				$gtin = ( $seo['gtin'] ?? '' ) ?: ( method_exists( $p, 'get_global_unique_id' ) ? $p->get_global_unique_id() : '' );
				( ! $gtin && empty( $seo['mpn'] ) ) && $prob[] = 'no GTIN/MPN';
				! $p->is_in_stock() && $prob[] = 'out of stock';
			}
			if ( $prob ) {
				$issues[] = array( 'id' => $post->ID, 'type' => $post->post_type, 'name' => $post->post_title, 'issues' => $prob );
			}
		}
		foreach ( $titles as $t => $ids ) {
			if ( count( $ids ) > 1 ) {
				$site[] = 'MEDIUM: duplicate title "' . $t . '" on ids ' . implode( ',', $ids );
			}
		}
		foreach ( $descs as $t => $ids ) {
			if ( count( $ids ) > 1 ) {
				$site[] = 'MEDIUM: duplicate meta description on ids ' . implode( ',', $ids );
			}
		}
		$total_checks = max( 1, count( $posts ) * 6 );
		$n            = array_sum( array_map( function ( $i ) { return count( $i['issues'] ); }, $issues ) );
		$counts       = array();
		foreach ( $issues as $i ) {
			foreach ( $i['issues'] as $x ) {
				$k            = preg_replace( '/ \(\d+\)$/', '', $x );
				$counts[ $k ] = ( $counts[ $k ] ?? 0 ) + 1;
			}
		}
		arsort( $counts );
		return array(
			'score'        => max( 0, round( 100 - 100 * $n / $total_checks - 10 * count( array_filter( $site, function ( $x ) { return 0 === strpos( $x, 'CRITICAL' ) || 0 === strpos( $x, 'HIGH' ); } ) ) ) ),
			'scanned'      => count( $posts ),
			'site_issues'  => $site,
			'issue_counts' => $counts,
			'items'        => $issues,
			'sitemap'      => home_url( '/wp-sitemap.xml' ),
			'merchant_feed'=> function_exists( 'hsm_feed_url' ) ? hsm_feed_url() : null,
		);
	},
) );

hsm_ability( 'seo-settings', array(
	'label'       => 'Site-wide SEO, schema and feed settings',
	'description' => 'Read (omit "set") or change built-in SEO settings: enabled, separator, title_template, product_title_template (%title% %sep% %sitename%), home_title, home_description, default_og_image (URL), org_name, org_logo (URL), social_profiles (array of URLs), twitter_site, default_brand, ship_country, handling_days [min,max], transit_days [min,max], return_days, return_fees (FreeReturn|ReturnFeesCustomerResponsibility), robots_txt_extra, noindex_search, noindex_tags, exclude_users_sitemap, merchant_feed.',
	'idempotent'  => true,
	'cap'         => 'manage_options',
	'properties'  => array( 'set' => hsm_s( 'object' ) ),
	'callback'    => function ( $in ) {
		$cur = hsm_seo_settings();
		if ( ! empty( $in['set'] ) ) {
			$defaults = hsm_seo_settings_defaults();
			foreach ( (array) $in['set'] as $k => $v ) {
				if ( ! array_key_exists( $k, $defaults ) ) {
					throw new Exception( "Unknown SEO setting '$k'." );
				}
				$d = $defaults[ $k ];
				if ( is_bool( $d ) ) {
					$v = filter_var( $v, FILTER_VALIDATE_BOOLEAN );
				} elseif ( is_int( $d ) ) {
					if ( ! is_numeric( $v ) || $v < 0 || $v > 3650 ) {
						throw new Exception( "$k must be a number." );
					}
					$v = (int) $v;
				} elseif ( is_array( $d ) ) {
					if ( ! is_array( $v ) ) {
						throw new Exception( "$k must be a list." );
					}
					$v = in_array( $k, array( 'handling_days', 'transit_days' ), true ) ? array( max( 0, (int) ( $v[0] ?? 0 ) ), max( 0, (int) ( $v[1] ?? 0 ) ) ) : array_values( array_filter( array_map( 'esc_url_raw', $v ) ) );
				} elseif ( in_array( $k, array( 'default_og_image', 'org_logo' ), true ) ) {
					$v = esc_url_raw( (string) $v, array( 'http', 'https' ) );
				} elseif ( 'robots_txt_extra' === $k ) {
					$lines = array_filter( array_map( 'trim', explode( "\n", (string) $v ) ) );
					foreach ( $lines as $l ) {
						if ( ! preg_match( '/^(Disallow|Allow|Crawl-delay|Sitemap|User-agent):\s*\S*/i', $l ) ) {
							throw new Exception( "robots_txt_extra line not allowed: $l" );
						}
						if ( preg_match( '/^Disallow:\s*(.*)$/i', $l, $m ) ) {
							// Strip an inline comment, then whitespace / * / $ — "/*", "/$", "/ #x" all block the whole site.
							$path = preg_replace( '/\s*#.*$/', '', $m[1] );
							if ( '/' === preg_replace( '/[\s*$]+/', '', $path ) ) {
								throw new HSM_Blocked( 'Refusing "' . $l . '" — a blanket Disallow would remove the whole store from Google.' );
							}
						}
					}
					$v = implode( "\n", $lines );
					if ( '' !== $v ) {
						hsm_confirm( $in, 'Write custom rules to robots.txt (affects how search engines crawl the whole store)', array() );
					}
				} elseif ( 'return_fees' === $k && ! in_array( $v, array( 'FreeReturn', 'ReturnFeesCustomerResponsibility', 'ReturnShippingFees' ), true ) ) {
					throw new Exception( 'return_fees must be FreeReturn, ReturnFeesCustomerResponsibility or ReturnShippingFees.' );
				} else {
					$v = sanitize_text_field( (string) $v );
				}
				if ( 'enabled' === $k && ! $v ) {
					hsm_confirm( $in, 'Turn off all built-in SEO output (titles, descriptions, structured data)', array() );
				}
				$cur[ $k ] = $v;
			}
			hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_seo', 'old' => get_option( 'hsm_seo', null ) ) );
			update_option( 'hsm_seo', $cur );
		}
		return array( 'settings' => $cur, 'other_seo_plugin_active' => hsm_other_seo_active(), 'sitemap' => home_url( '/wp-sitemap.xml' ), 'robots' => home_url( '/robots.txt' ), 'merchant_feed' => hsm_feed_url() );
	},
) );

hsm_ability( 'redirects-manage', array(
	'label'       => 'Manage URL redirects and view 404 log',
	'description' => 'action=list|add|delete|log|clear_log. add: {redirects:[{source:"/old-path", target:"/new-path or full URL", code:301|302|307|410}]}. A source starting with ^ is a regex (use $1 in target). "log" returns recent 404 URLs with hit counts so you can redirect them.',
	'cap'         => 'manage_options',
	'properties'  => array(
		'action'    => hsm_s( 'string', 'list | add | delete | log | clear_log', array( 'enum' => array( 'list', 'add', 'delete', 'log', 'clear_log' ) ) ),
		'redirects' => hsm_s( 'array' ),
		'source'    => hsm_s( 'string' ),
	),
	'required'    => array( 'action' ),
	'callback'    => function ( $in ) {
		$all = get_option( 'hsm_redirects', array() );
		switch ( $in['action'] ) {
			case 'list':
				return $all;
			case 'log':
				$log = get_option( 'hsm_404_log', array() );
				uasort( $log, function ( $a, $b ) { return $b['hits'] <=> $a['hits']; } );
				return $log;
			case 'clear_log':
				hsm_confirm( $in, 'Clear the 404 log', array( 'count' => count( (array) get_option( 'hsm_404_log', array() ) ) ) );
				hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_404_log', 'old' => get_option( 'hsm_404_log', array() ) ) );
				update_option( 'hsm_404_log', array(), false );
				return array( 'cleared' => true );
			case 'delete':
				$src = 0 === strpos( $in['source'] ?? '', '^' ) ? $in['source'] : hsm_norm_path( $in['source'] ?? '' );
				if ( ! isset( $all[ $src ] ) ) {
					throw new Exception( 'Redirect not found.' );
				}
				hsm_confirm( $in, 'Delete redirect ' . $src, array( 'source' => $src, 'redirect' => $all[ $src ] ) );
				hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_redirects', 'old' => $all ) );
				unset( $all[ $src ] );
				update_option( 'hsm_redirects', $all, false );
				return array( 'deleted' => $src );
			case 'add':
				$added = array();
				if ( count( (array) ( $in['redirects'] ?? array() ) ) > (int) hsm_guard( 'max_bulk_items' ) ) {
					throw new HSM_Blocked( 'Too many redirects in one call.' );
				}
				hsm_confirm( $in, 'Add or update ' . count( (array) ( $in['redirects'] ?? array() ) ) . ' redirect(s)', array( 'redirects' => $in['redirects'] ?? array() ) );
				hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_redirects', 'old' => $all ) );
				$protected = hsm_redirect_protected_paths();
				foreach ( (array) ( $in['redirects'] ?? array() ) as $r ) {
					if ( empty( $r['source'] ) || ! is_string( $r['source'] ) || ! isset( $r['target'] ) || ! is_string( $r['target'] ) ) {
						throw new Exception( 'Each redirect needs string source and target.' );
					}
					$code = (int) ( $r['code'] ?? 301 );
					if ( ! in_array( $code, array( 301, 302, 307, 308, 410 ), true ) ) {
						throw new Exception( 'code must be 301, 302, 307, 308 or 410.' );
					}
					$regex = 0 === strpos( $r['source'], '^' );
					if ( $regex ) {
						if ( strlen( $r['source'] ) > 200 || false === @preg_match( '#' . str_replace( '#', '\\#', $r['source'] ) . '#', '' ) ) {
							throw new Exception( 'Invalid regex source: ' . $r['source'] );
						}
						if ( preg_match( '/\([^)]*[+*][^)]*\)[+*]/', $r['source'] ) ) {
							throw new HSM_Blocked( 'Regex has nested quantifiers (catastrophic backtracking risk).' );
						}
					}
					$src = $regex ? $r['source'] : hsm_norm_path( $r['source'] );
					if ( '/' === $src || ( $regex && @preg_match( '#' . str_replace( '#', '\\#', $src ) . '#', '/' ) ) ) {
						throw new HSM_Blocked( 'Refusing to redirect the home page.' );
					}
					foreach ( $protected as $pp ) {
						$hit = false;
						if ( ! $regex ) {
							$hit = $src === $pp || 0 === strpos( $src, $pp . '/' ) || 0 === strpos( $src, $pp );
						} else {
							foreach ( array( $pp, $pp . '/', $pp . '/x', $pp . '/x/y', $pp . '/order-received/1', $pp . '/orders' ) as $probe ) {
								if ( @preg_match( '#' . str_replace( '#', '\\#', $src ) . '#', $probe ) ) {
									$hit = true;
									break;
								}
							}
						}
						if ( $hit ) {
							throw new HSM_Blocked( "Refusing to redirect a protected path ($pp)." );
						}
					}
					$target = trim( $r['target'] );
					if ( 410 !== $code && preg_match( '#[\r\n]#', $target ) ) {
						throw new HSM_Blocked( 'Line breaks are not allowed in redirect targets.' );
					}
					if ( 410 !== $code && $regex && preg_match( '#^/{0,2}(\$|\\\\)\d#', $target ) ) {
						throw new HSM_Blocked( 'A regex redirect target may not start with a captured group (open-redirect risk). Start the target with a fixed path, e.g. /new/$1.' );
					}
					if ( 410 !== $code && $regex && preg_match( '#^https?://[^/]*(\$|\\\\)\d#i', $target ) ) {
						throw new HSM_Blocked( 'Captured groups may not appear in the host part of a redirect target.' );
					}
					if ( 410 !== $code ) {
						if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $target ) && ! preg_match( '#^https?://#i', $target ) ) {
							throw new HSM_Blocked( 'Redirect target must be a path or an http(s) URL.' );
						}
						if ( 0 === strpos( $target, '//' ) ) {
							throw new HSM_Blocked( 'Protocol-relative targets are not allowed.' );
						}
						$ext_host = wp_parse_url( $target, PHP_URL_HOST );
						if ( $ext_host && $ext_host !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
							hsm_confirm( $in, "Redirect to an external site ($ext_host)", array( 'source' => $src, 'target' => $target ) );
						}
						if ( ! $regex && hsm_norm_path( $target ) === $src ) {
							throw new HSM_Blocked( "Redirect loop: $src points to itself." );
						}
						if ( ! $regex && isset( $all[ hsm_norm_path( $target ) ] ) && hsm_norm_path( $all[ hsm_norm_path( $target ) ]['target'] ) === $src ) {
							throw new HSM_Blocked( "Redirect loop between $src and $target." );
						}
					}
					$r['target'] = esc_url_raw( $target ) ?: sanitize_text_field( $target );
					$trial       = $all;
					$trial[ $src ] = array( 'target' => $r['target'], 'code' => $code, 'hits' => 0 );
					if ( 410 !== $code ) {
						$probe = $src;
						if ( $regex ) {
							// Build a sample path the regex matches: strip anchors and replace groups / wildcards with "a".
							$probe = preg_replace( array( '#^\^#', '#\$$#', '#\((?:\?:)?[^)]*\)[*+?]?#', '#\.[*+]#', '#\[[^\]]*\][*+]?#', '#\\\\#' ), array( '', '', 'a', 'a', 'a', '' ), $src );
							$probe = hsm_norm_path( $probe );
						}
						$res = hsm_redirect_resolve( $probe, $trial );
						if ( $res && 'loop' === $res[0] ) {
							throw new HSM_Blocked( "Redirect loop detected starting at $src (chains are limited to 5 hops)." );
						}
					}
					$all[ $src ] = array( 'target' => $r['target'], 'code' => $code, 'hits' => $all[ $src ]['hits'] ?? 0 );
					$added[]     = $src;
					$log         = get_option( 'hsm_404_log', array() );
					unset( $log[ $src ] );
					update_option( 'hsm_404_log', $log, false );
				}
				update_option( 'hsm_redirects', $all, false );
				return array( 'added' => $added, 'total' => count( $all ) );
		}
	},
) );

hsm_ability( 'merchant-feed', array(
	'label'       => 'Google Merchant / Meta catalog feed status',
	'description' => 'Returns the product feed URLs (feeds.google for Google Merchant Center, feeds.meta for Meta Commerce Manager, feeds.tiktok CSV for TikTok Catalog Manager; submit each as a scheduled fetch), item count, and per-item problems Google would flag (missing image, brand, GTIN/MPN, price, description). preview=n returns the first n items.',
	'readonly'    => true,
	'idempotent'  => true,
	'properties'  => array( 'preview' => hsm_s( 'integer' ) ),
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$items = hsm_feed_items();
		$prob  = array();
		foreach ( $items as $it ) {
			$p = array();
			empty( $it['image'] ) && $p[] = 'image_link';
			empty( $it['brand'] ) && $p[] = 'brand';
			( empty( $it['gtin'] ) && empty( $it['mpn'] ) ) && $p[] = 'gtin/mpn (identifier_exists=no sent)';
			empty( $it['description'] ) && $p[] = 'description';
			( empty( $it['price'] ) || 0 === strpos( $it['price'], '0.00' ) ) && $p[] = 'price';
			$p && $prob[ $it['id'] ] = $p;
		}
		return array( 'url' => hsm_feed_url(), 'feeds' => array( 'google' => hsm_feed_url( 'google' ), 'meta' => hsm_feed_url( 'meta' ), 'tiktok' => hsm_feed_url( 'tiktok' ) ), 'enabled' => (bool) hsm_seo_settings()['merchant_feed'], 'items' => count( $items ), 'items_with_problems' => count( $prob ), 'problems' => $prob, 'preview' => array_slice( $items, 0, (int) ( $in['preview'] ?? 0 ) ) );
	},
) );
