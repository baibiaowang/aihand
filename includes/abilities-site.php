<?php
defined( 'ABSPATH' ) || exit;

hsm_ability( 'content-upsert', array(
	'label'       => 'Create or update a page or blog post',
	'description' => 'Create (no id) or update (id) a post/page/any public post type. content accepts HTML or block markup. Supports title, slug, status (draft|publish|future|private), date (for scheduling), excerpt, parent (pages), template, categories/tags (names, auto-created), featured_image (URL/path/id), seo {title, description, noindex,...}, set_as_front_page / set_as_posts_page (pages).',
	'cap'         => 'edit_posts',
	'properties'  => array(
		'id' => hsm_s( 'integer' ), 'post_type' => hsm_s( 'string', 'post (default) | page | wp_navigation (site menus; needs edit_theme_options)' ), 'title' => hsm_s( 'string' ), 'content' => hsm_s( 'string' ),
		'slug' => hsm_s( 'string' ), 'status' => hsm_s( 'string' ), 'date' => hsm_s( 'string' ), 'excerpt' => hsm_s( 'string' ), 'parent' => hsm_s( 'integer' ),
		'template' => hsm_s( 'string' ), 'categories' => hsm_s( 'array' ), 'tags' => hsm_s( 'array' ), 'featured_image' => hsm_s( array( 'string', 'integer' ) ),
		'seo' => hsm_s( 'object' ), 'set_as_front_page' => hsm_s( 'boolean' ), 'set_as_posts_page' => hsm_s( 'boolean' ),
	),
	'callback'    => function ( $in ) {
		$type = $in['post_type'] ?? ( ! empty( $in['id'] ) ? get_post_type( (int) $in['id'] ) : 'post' );
		if ( ! in_array( $type, apply_filters( 'hsm_content_post_types', array( 'post', 'page', 'wp_navigation' ) ), true ) ) {
			throw new HSM_Blocked( "content-upsert only edits posts and pages (got '$type'). Use product-upsert for products." );
		}
		$pto = get_post_type_object( $type );
		if ( ! empty( $in['id'] ) ) {
			$cur = get_post( (int) $in['id'] );
			if ( ! $cur || $cur->post_type !== $type ) {
				throw new Exception( 'Post not found.' );
			}
			if ( ! current_user_can( 'edit_post', $cur->ID ) ) {
				throw new HSM_Blocked( 'You are not allowed to edit this item.' );
			}
		} elseif ( ! current_user_can( $pto->cap->create_posts ) ) {
			throw new HSM_Blocked( "You are not allowed to create {$type}s." );
		}
		$status = $in['status'] ?? null;
		if ( $status && ! in_array( $status, array( 'draft', 'publish', 'pending', 'private', 'future' ), true ) ) {
			throw new Exception( 'status must be draft, publish, pending, private or future.' );
		}
		if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) && ! current_user_can( $pto->cap->publish_posts ) ) {
			throw new HSM_Blocked( "You are not allowed to publish {$type}s; save as draft or pending." );
		}
		if ( isset( $in['parent'] ) && $in['parent'] && ( ! get_post( (int) $in['parent'] ) || get_post_type( (int) $in['parent'] ) !== $type ) ) {
			throw new Exception( 'parent not found.' );
		}
		if ( ( ! empty( $in['set_as_front_page'] ) || ! empty( $in['set_as_posts_page'] ) ) ) {
			if ( ! current_user_can( 'manage_options' ) || 'page' !== $type ) {
				throw new HSM_Blocked( 'Only administrators can set the front/posts page, and only for pages.' );
			}
			hsm_confirm( $in, 'Change the site home page / blog page', array() );
			hsm_undo_add( array( 'type' => 'option', 'name' => 'show_on_front', 'old' => get_option( 'show_on_front' ) ) );
			hsm_undo_add( array( 'type' => 'option', 'name' => 'page_on_front', 'old' => get_option( 'page_on_front' ) ) );
			hsm_undo_add( array( 'type' => 'option', 'name' => 'page_for_posts', 'old' => get_option( 'page_for_posts' ) ) );
		}
		// All content writes require owner approval, including draft creation and ordinary edits.
		hsm_confirm( $in, ( ! empty( $cur ) ? 'Update ' . $type . ' "' . $cur->post_title . '"' : 'Create ' . $type . ' "' . ( $in['title'] ?? 'untitled' ) . '"' ), array( 'id' => $cur->ID ?? null, 'type' => $type, 'status' => $status ?? ( $cur->post_status ?? 'draft' ), 'title' => $in['title'] ?? ( $cur->post_title ?? '' ), 'input' => $in ) );
		if ( ! empty( $cur ) && 'publish' === $cur->post_status && $status && 'publish' !== $status ) {
			hsm_confirm( $in, 'Unpublish live ' . $type . ' "' . $cur->post_title . '"', array( 'id' => $cur->ID ) );
		}
		if ( ! empty( $cur ) ) {
			hsm_undo_add( array( 'type' => 'post', 'id' => $cur->ID, 'state' => array( 'ID' => $cur->ID, 'post_title' => $cur->post_title, 'post_content' => $cur->post_content, 'post_excerpt' => $cur->post_excerpt, 'post_status' => $cur->post_status, 'post_name' => $cur->post_name, 'post_parent' => $cur->post_parent ) ) );
		}
		$in['post_type'] = $type;
		$arr = array();
		$map = array( 'title' => 'post_title', 'content' => 'post_content', 'slug' => 'post_name', 'status' => 'post_status', 'date' => 'post_date', 'excerpt' => 'post_excerpt', 'parent' => 'post_parent', 'template' => 'page_template' );
		foreach ( $map as $k => $f ) {
			isset( $in[ $k ] ) && $arr[ $f ] = $in[ $k ];
		}
		isset( $arr['post_title'] ) && $arr['post_title'] = wp_strip_all_tags( $arr['post_title'] );
		isset( $arr['post_content'] ) && $arr['post_content'] = hsm_html( $arr['post_content'] );
		isset( $arr['post_excerpt'] ) && $arr['post_excerpt'] = hsm_html( $arr['post_excerpt'] );
		if ( isset( $arr['page_template'] ) && 'page' === $type && ! array_key_exists( $arr['page_template'], wp_get_theme()->get_page_templates() ) && 'default' !== $arr['page_template'] ) {
			throw new Exception( 'Unknown page template.' );
		}
		if ( ! empty( $in['id'] ) ) {
			if ( ! current_user_can( 'edit_post', (int) $in['id'] ) ) {
				throw new Exception( 'Not allowed.' );
			}
			$arr['ID'] = (int) $in['id'];
			$id        = wp_update_post( wp_slash( $arr ), true );
		} else {
			$arr['post_type']   = $in['post_type'] ?? 'post';
			$arr['post_status'] = $arr['post_status'] ?? 'draft';
			$id                 = wp_insert_post( wp_slash( $arr ), true );
			! is_wp_error( $id ) && hsm_undo_add( array( 'type' => 'post', 'id' => $id, 'created' => true ) );
		}
		if ( is_wp_error( $id ) ) {
			throw new Exception( $id->get_error_message() );
		}
		isset( $in['categories'] ) && wp_set_post_terms( $id, hsm_terms( $in['categories'], 'category' ), 'category' );
		isset( $in['tags'] ) && wp_set_post_terms( $id, $in['tags'], 'post_tag' );
		isset( $in['featured_image'] ) && set_post_thumbnail( $id, hsm_media( $in['featured_image'], $id ) );
		isset( $in['seo'] ) && hsm_set_seo( $id, $in['seo'] );
		if ( ! empty( $in['set_as_front_page'] ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $id );
		}
		! empty( $in['set_as_posts_page'] ) && update_option( 'page_for_posts', $id );
		$p = get_post( $id );
		return array( 'id' => $id, 'type' => $p->post_type, 'status' => $p->post_status, 'title' => $p->post_title, 'link' => get_permalink( $id ), 'seo' => hsm_get_seo( $id ) );
	},
) );

hsm_ability( 'content-search', array(
	'label'       => 'Search pages and posts',
	'description' => 'List/search posts, pages or any post type. Set include_content=true to get the raw content (for editing).',
	'readonly'    => true,
	'idempotent'  => true,
	'cap'         => 'edit_posts',
	'properties'  => array( 'post_type' => hsm_s( 'string' ), 'search' => hsm_s( 'string' ), 'status' => hsm_s( 'string' ), 'id' => hsm_s( 'integer' ), 'include_content' => hsm_s( 'boolean' ), 'page' => hsm_s( 'integer' ), 'per_page' => hsm_s( 'integer' ) ),
	'callback'    => function ( $in ) {
		list( $per, $page ) = hsm_paginate( $in );
		$types = array_intersect( (array) ( $in['post_type'] ?? array( 'post', 'page' ) ), apply_filters( 'hsm_content_post_types', array( 'post', 'page', 'wp_navigation' ) ) );
		if ( ! $types ) {
			throw new HSM_Blocked( 'content-search covers posts and pages only.' );
		}
		$q = new WP_Query( array( 'post_type' => $types, 'p' => (int) ( $in['id'] ?? 0 ), 's' => (string) ( $in['search'] ?? '' ), 'post_status' => current_user_can( 'edit_others_posts' ) ? ( $in['status'] ?? 'any' ) : 'publish', 'posts_per_page' => $per, 'paged' => $page ) );
		return array( 'total' => $q->found_posts, 'items' => array_map( function ( $p ) use ( $in ) {
			$r = array( 'id' => $p->ID, 'type' => $p->post_type, 'title' => $p->post_title, 'status' => $p->post_status, 'date' => $p->post_date, 'link' => get_permalink( $p ), 'seo' => hsm_get_seo( $p->ID ) );
			! empty( $in['include_content'] ) && current_user_can( 'edit_post', $p->ID ) && $r['content'] = $p->post_content;
			return $r;
		}, $q->posts ) );
	},
) );

hsm_ability( 'media-upload', array(
	'label'       => 'Import images/files into the media library',
	'description' => 'Import one or more files from URLs or local paths: files:[{src, alt?, title?}]. Re-importing the same source returns the existing attachment. Also action=update_alt {id, alt} and action=list_missing_alt.',
	'cap'         => 'upload_files',
	'properties'  => array( 'action' => hsm_s( 'string', 'import (default) | update_alt | list_missing_alt' ), 'files' => hsm_s( 'array' ), 'id' => hsm_s( 'integer' ), 'alt' => hsm_s( 'string' ) ),
	'callback'    => function ( $in ) {
		$action = $in['action'] ?? 'import';
		if ( 'update_alt' === $action ) {
			if ( 'attachment' !== get_post_type( (int) ( $in['id'] ?? 0 ) ) || ! current_user_can( 'edit_post', (int) $in['id'] ) ) {
				throw new Exception( 'Attachment not found or not editable.' );
			}
			hsm_confirm( $in, 'Update image alt text for attachment #' . (int) $in['id'], array( 'id' => (int) $in['id'], 'alt' => sanitize_text_field( $in['alt'] ?? '' ) ) );
			hsm_undo_add( array( 'type' => 'post_meta', 'id' => (int) $in['id'], 'key' => '_wp_attachment_image_alt', 'old' => get_post_meta( (int) $in['id'], '_wp_attachment_image_alt', true ) ) );
			update_post_meta( (int) $in['id'], '_wp_attachment_image_alt', sanitize_text_field( $in['alt'] ?? '' ) );
			return array( 'id' => (int) $in['id'], 'alt' => $in['alt'] ?? '' );
		}
		if ( 'list_missing_alt' === $action ) {
			$ids = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'numberposts' => 200, 'fields' => 'ids', 'meta_query' => array( 'relation' => 'OR', array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ), array( 'key' => '_wp_attachment_image_alt', 'value' => '' ) ) ) );
			return array_map( function ( $id ) { return array( 'id' => $id, 'url' => wp_get_attachment_url( $id ), 'attached_to' => wp_get_post_parent_id( $id ) ); }, $ids );
		}
		$out = array();
		if ( count( (array) ( $in['files'] ?? array() ) ) > 50 ) {
			throw new HSM_Blocked( 'At most 50 files per call.' );
		}
		if ( ! empty( $in['files'] ) ) {
			hsm_confirm( $in, 'Import ' . count( (array) $in['files'] ) . ' media file(s)', array( 'files' => $in['files'] ) );
		}
		foreach ( (array) ( $in['files'] ?? array() ) as $f ) {
			$f  = is_array( $f ) ? $f : array( 'src' => $f );
			$id = hsm_media( $f['src'], 0, $f['alt'] ?? '' );
			! empty( $f['title'] ) && wp_update_post( array( 'ID' => $id, 'post_title' => sanitize_text_field( $f['title'] ) ) );
			$out[] = array( 'id' => $id, 'url' => wp_get_attachment_url( $id ) );
		}
		return $out;
	},
) );

hsm_ability( 'site-extensions', array(
	'label'       => 'Manage plugins, themes and permalinks',
	'description' => 'action=list (plugins + themes with versions, status, available updates) | install_plugin {slug, activate?} (from WordPress.org) | activate_plugin {slug} | deactivate_plugin {slug} | update_plugin {slug} | install_theme {slug, activate?} | activate_theme {slug} | permalinks {structure: "/%postname%/"}.',
	'cap'         => 'manage_options',
	'properties'  => array( 'action' => hsm_s( 'string' ), 'slug' => hsm_s( 'string' ), 'activate' => hsm_s( 'boolean' ), 'structure' => hsm_s( 'string' ) ),
	'required'    => array( 'action' ),
	'callback'    => function ( $in ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		$find = function ( $slug ) {
			foreach ( get_plugins() as $file => $d ) {
				if ( dirname( $file ) === $slug || $file === $slug || basename( $file, '.php' ) === $slug ) {
					return $file;
				}
			}
			return null;
		};
		$skin = new WP_Ajax_Upgrader_Skin();
		$caps = array( 'install_plugin' => 'install_plugins', 'activate_plugin' => 'activate_plugins', 'deactivate_plugin' => 'activate_plugins', 'update_plugin' => 'update_plugins', 'install_theme' => 'install_themes', 'activate_theme' => 'switch_themes', 'permalinks' => 'manage_options', 'list' => 'manage_options' );
		if ( ! isset( $caps[ $in['action'] ] ) ) {
			throw new Exception( 'Unknown action. Use: ' . implode( ', ', array_keys( $caps ) ) );
		}
		if ( ! current_user_can( $caps[ $in['action'] ] ) ) {
			throw new HSM_Blocked( 'You lack the ' . $caps[ $in['action'] ] . ' capability.' );
		}
		if ( 'list' !== $in['action'] ) {
			if ( isset( $in['slug'] ) && ! preg_match( '/^[a-z0-9][a-z0-9._-]*$/', $in['slug'] ) ) {
				throw new HSM_Blocked( 'Invalid slug.' );
			}
			if ( 'permalinks' === $in['action'] && ( empty( $in['structure'] ) || false === strpos( $in['structure'], '%' ) || preg_match( '/[^a-z0-9%\/_-]/i', $in['structure'] ) ) ) {
				throw new HSM_Blocked( 'Invalid permalink structure.' );
			}
			hsm_confirm( $in, ucfirst( str_replace( '_', ' ', $in['action'] ) ) . ' ' . ( $in['slug'] ?? $in['structure'] ?? '' ) . ' — this changes site code/behavior', array( 'action' => $in['action'], 'slug' => $in['slug'] ?? null, 'activate' => $in['activate'] ?? null ) );
			'permalinks' === $in['action'] && hsm_undo_add( array( 'type' => 'option', 'name' => 'permalink_structure', 'old' => get_option( 'permalink_structure' ) ) );
			'activate_theme' === $in['action'] && hsm_undo_add( array( 'type' => 'theme', 'old' => get_stylesheet() ) );
		}
		switch ( $in['action'] ) {
			case 'list':
				$updates = get_site_transient( 'update_plugins' );
				$plugins = array();
				foreach ( get_plugins() as $file => $d ) {
					$plugins[] = array( 'slug' => dirname( $file ) === '.' ? basename( $file, '.php' ) : dirname( $file ), 'name' => $d['Name'], 'version' => $d['Version'], 'active' => is_plugin_active( $file ), 'update' => $updates->response[ $file ]->new_version ?? null );
				}
				$themes = array();
				foreach ( wp_get_themes() as $slug => $t ) {
					$themes[] = array( 'slug' => $slug, 'name' => $t->get( 'Name' ), 'version' => $t->get( 'Version' ), 'active' => get_stylesheet() === $slug, 'block_theme' => $t->is_block_theme() );
				}
				return array( 'plugins' => $plugins, 'themes' => $themes );
			case 'install_plugin':
				$api = plugins_api( 'plugin_information', array( 'slug' => $in['slug'], 'fields' => array( 'sections' => false ) ) );
				if ( is_wp_error( $api ) ) {
					throw new Exception( $api->get_error_message() );
				}
				if ( ! $find( $in['slug'] ) ) {
					$r = ( new Plugin_Upgrader( $skin ) )->install( $api->download_link );
					if ( is_wp_error( $r ) || ! $r ) {
						throw new Exception( 'Install failed: ' . implode( '; ', $skin->get_error_messages() ) );
					}
				}
				if ( ! empty( $in['activate'] ) ) {
					$r = activate_plugin( $find( $in['slug'] ) );
					is_wp_error( $r ) && throw new Exception( $r->get_error_message() );
				}
				return array( 'installed' => $in['slug'], 'version' => $api->version, 'active' => is_plugin_active( $find( $in['slug'] ) ) );
			case 'activate_plugin':
				$f = $find( $in['slug'] ) ?: throw new Exception( 'Plugin not installed.' );
				$r = activate_plugin( $f );
				is_wp_error( $r ) && throw new Exception( $r->get_error_message() );
				return array( 'active' => $in['slug'] );
			case 'deactivate_plugin':
				$f = $find( $in['slug'] ) ?: throw new Exception( 'Plugin not installed.' );
				if ( false !== strpos( $f, 'hark-store-mcp' ) || false !== strpos( $f, 'mcp-adapter' ) || false !== strpos( $f, 'woocommerce/woocommerce.php' ) || false !== strpos( $f, 'sqlite-database-integration' ) ) {
					throw new Exception( 'Refusing to deactivate the plugin this agent depends on.' );
				}
				deactivate_plugins( $f );
				return array( 'deactivated' => $in['slug'] );
			case 'update_plugin':
				$f = $find( $in['slug'] ) ?: throw new Exception( 'Plugin not installed.' );
				wp_update_plugins();
				$r = ( new Plugin_Upgrader( $skin ) )->upgrade( $f );
				return array( 'updated' => (bool) $r && ! is_wp_error( $r ), 'messages' => $skin->get_upgrade_messages() );
			case 'install_theme':
				$api = themes_api( 'theme_information', array( 'slug' => $in['slug'] ) );
				if ( is_wp_error( $api ) ) {
					throw new Exception( $api->get_error_message() );
				}
				if ( ! wp_get_theme( $in['slug'] )->exists() ) {
					( new Theme_Upgrader( $skin ) )->install( $api->download_link );
				}
				! empty( $in['activate'] ) && switch_theme( $in['slug'] );
				return array( 'installed' => $in['slug'], 'active' => get_stylesheet() === $in['slug'] );
			case 'activate_theme':
				if ( ! wp_get_theme( $in['slug'] )->exists() ) {
					throw new Exception( 'Theme not installed.' );
				}
				switch_theme( $in['slug'] );
				return array( 'active' => get_stylesheet() );
			case 'permalinks':
				global $wp_rewrite;
				$wp_rewrite->set_permalink_structure( $in['structure'] ?? '/%postname%/' );
				flush_rewrite_rules();
				return array( 'permalink_structure' => get_option( 'permalink_structure' ) );
		}
		throw new Exception( 'Unknown action.' );
	},
) );

hsm_ability( 'rest-request', array(
	'label'       => 'Call any WordPress / WooCommerce REST endpoint (escape hatch)',
	'description' => 'Runs an internal REST request as the current user, for anything the dedicated tools do not cover, e.g. GET /wc/v3/products/123/variations, POST /wc/v3/products/attributes, GET /wc/v3/reports/top_sellers, POST /wp/v2/menu-items, GET /wc/v3/system_status, /wc/v3/settings/{group}, /wc/v3/payment_gateways/{id}, /wc/v3/webhooks, /wp/v2/navigation, /wp/v2/templates, /wp/v2/global-styles. GET /wc/v3 (or /) lists routes. Response bodies are truncated to ~60 KB.',
	'cap'         => 'edit_posts',
	'properties'  => array(
		'method' => hsm_s( 'string', 'GET | POST | PUT | PATCH | DELETE' ),
		'path'   => hsm_s( 'string', 'Route, e.g. /wc/v3/orders' ),
		'params' => hsm_s( 'object', 'Query (GET) or body (others) parameters.' ),
	),
	'required'    => array( 'path' ),
	'is_write'    => function ( $in ) { return 'GET' !== strtoupper( (string) ( $in['method'] ?? 'GET' ) ); },
	'callback'    => function ( $in ) {
		$method = strtoupper( $in['method'] ?? 'GET' );
		if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			throw new HSM_Blocked( 'Unsupported method.' );
		}
		$path   = '/' . ltrim( preg_replace( '#^/?wp-json#', '', (string) $in['path'] ), '/' );
		$parts  = wp_parse_url( $path );
		if ( ! $parts || empty( $parts['path'] ) || false !== strpos( $parts['path'], '..' ) ) {
			throw new HSM_Blocked( 'Invalid path.' );
		}
		$p     = strtolower( rawurldecode( $parts['path'] ) );
		// Batch endpoints run nested requests that would bypass every route check below.
		if ( preg_match( '#^/+batch(/|$)#', $p ) ) {
			throw new HSM_Blocked( 'rest-request may not call the batch endpoint; send individual requests.' );
		}
		if ( ! empty( $parts['query'] ) && preg_match( '/(^|&)(rest_route|_method|_envelope|_jsonp)=/i', $parts['query'] ) ) {
			throw new HSM_Blocked( 'rest_route, _method, _envelope and _jsonp are not allowed in rest-request paths; use "path" and "method".' );
		}
		$p     = preg_replace( '#/+#', '/', $p );
		// Even GET is not an unrestricted data exfiltration channel. Dedicated tools own
		// customers, orders, settings and site administration; the escape hatch only exposes
		// a small read-only WooCommerce reporting/product surface unless explicitly extended.
		if ( 'GET' === $method && ! preg_match( '#^/(wc/v\\d+)/(products|reports)(/|$)#', $p ) ) {
			$allowed_read = (bool) apply_filters( 'hsm_allow_rest_read', false, $method, $parts['path'], $in['params'] ?? array() );
			if ( ! $allowed_read ) {
				throw new HSM_Blocked( 'rest-request only permits safe WooCommerce product/report reads by default. Use a dedicated tool or explicitly allow this exact read route with hsm_allow_rest_read.' );
			}
		}
		$never = array( '#/application-passwords#', '#^/wp/v2/users/?[^/]*/?$#' => 'write', '#^/mcp#', '#^/hark-store#', '#^/wp/v2/plugins#' => 'write', '#^/wp/v2/themes#' => 'write', '#^/wp/v2/settings#' => 'write', '#^/wp-site-health#' => 'write', '#^/wc/v\d+/settings/advanced#' => 'write', '#^/wc/v\d+/system_status/tools#' => 'write', '#^/wp-abilities#', '#^/wc/v\d+/webhooks#' => 'write', '#^/wp/v2/(posts|pages|templates|template-parts|global-styles|menu-items|navigation|blocks|widgets)(/|$)#' => 'write' );
		foreach ( $never as $k => $v ) {
			$rx    = is_int( $k ) ? $v : $k;
			$scope = is_int( $k ) ? 'all' : $v;
			if ( preg_match( $rx, $p ) && ( 'all' === $scope || 'GET' !== $method ) ) {
				throw new HSM_Blocked( "rest-request may not call $method {$parts['path']} (sensitive route). Use the dedicated tool or WP Admin." );
			}
		}
		if ( preg_match( '#^/(wc/v\d+|wc-analytics|wc/store(/v\d+)?)/(customers|webhooks|payment_gateways|system_status|settings|data)(/|$)#', $p ) ) {
			throw new HSM_Blocked( "rest-request may not call $method {$parts['path']} (sensitive route: customers, webhooks, payment gateways, system tools). Use WP Admin." );
		}
		if ( 'GET' !== $method && preg_match( '#^/wp/v2/(product|shop_order|shop_coupon|shop_order_refund|product_variation|users/me)(/|$)#', $p ) ) {
			throw new HSM_Blocked( "rest-request may not write WooCommerce objects or your own account through core routes ($method {$parts['path']}). Use the dedicated tools." );
		}
		if ( preg_match( '#^/wc/v\d+/(products|orders|coupons|refunds)#', $p ) && 'GET' !== $method ) {
			throw new HSM_Blocked( 'Use the dedicated product/order/coupon tools for writes — they enforce price floors, confirmation and undo. rest-request is read-only for these routes.' );
		}
		// Generic REST is an escape hatch for reads only. A write here could reach a route
		// added by another plugin and bypass its dedicated AI Hand validation/undo rules.
		if ( 'GET' !== $method ) {
			$allowed = (bool) apply_filters( 'hsm_allow_rest_write', false, $method, $parts['path'], $in['params'] ?? array() );
			if ( ! $allowed ) {
				throw new HSM_Blocked( 'rest-request is read-only for safety. Use a dedicated AI Hand write tool, or explicitly allow this exact REST route with the hsm_allow_rest_write filter.' );
			}
			hsm_confirm( $in, "Raw REST write: $method {$parts['path']} (no undo available)", array( 'method' => $method, 'path' => $parts['path'], 'params' => $in['params'] ?? null ) );
		}
		$req    = new WP_REST_Request( $method, $parts['path'] );
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $q );
			$req->set_query_params( $q );
		}
		$params = (array) ( $in['params'] ?? array() );
		unset( $params['_method'], $params['_envelope'], $params['_jsonp'], $params['rest_route'] );
		if ( 'GET' === $method || 'DELETE' === $method ) {
			$req->set_query_params( array_merge( $req->get_query_params(), $params ) );
		} else {
			$req->set_header( 'content-type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
			$req->set_body_params( $params );
		}
		$res  = rest_do_request( $req );
		$data = rest_get_server()->response_to_data( $res, false );
		$json = wp_json_encode( $data );
		if ( strlen( $json ) > 60000 ) {
			return array( 'status' => $res->get_status(), 'truncated' => true, 'total' => $res->get_headers()['X-WP-Total'] ?? null, 'body_head' => substr( $json, 0, 60000 ) );
		}
		return array( 'status' => $res->get_status(), 'total' => $res->get_headers()['X-WP-Total'] ?? null, 'body' => $data );
	},
) );


/* ---------------------------------------------------------------------------
 * Safety: audit log, undo, status
 * ------------------------------------------------------------------------- */

hsm_ability( 'audit-log', array(
	'label'       => 'Audit log of changes made through this server',
	'description' => 'Lists recent write calls (who, when, tool, arguments, result, whether undoable). Filter by tool, status (ok|blocked|error) or user. Use the id with the undo tool.',
	'readonly'    => true,
	'idempotent'  => true,
	'cap'         => 'edit_posts',
	'properties'  => array( 'tool' => hsm_s( 'string' ), 'status' => hsm_s( 'string' ), 'user' => hsm_s( 'string' ), 'id' => hsm_s( 'integer' ), 'per_page' => hsm_s( 'integer' ), 'page' => hsm_s( 'integer' ) ),
	'callback'    => function ( $in ) {
		if ( ! empty( $in['id'] ) ) {
			$d = hsm_audit_read( $in['id'] );
			if ( ! $d || ( (int) get_post_field( 'post_author', $d['id'] ) !== get_current_user_id() && ! current_user_can( 'manage_woocommerce' ) ) ) {
				throw new Exception( 'Audit entry not found.' );
			}
			return $d;
		}
		list( $per, $page ) = hsm_paginate( $in );
		$args = array( 'post_type' => 'hsm_audit', 'posts_per_page' => $per, 'paged' => $page, 'orderby' => 'ID', 'order' => 'DESC' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$args['author'] = get_current_user_id();
		} elseif ( ! empty( $in['user'] ) ) {
			$u              = get_user_by( 'login', $in['user'] );
			$args['author'] = $u ? $u->ID : -1;
		}
		$q   = new WP_Query( $args );
		$out = array();
		foreach ( $q->posts as $p ) {
			$d = hsm_audit_read( $p->ID );
			if ( ( ! empty( $in['tool'] ) && false === strpos( $d['ability'], $in['tool'] ) ) || ( ! empty( $in['status'] ) && $d['status'] !== $in['status'] ) ) {
				continue;
			}
			$out[] = array( 'id' => $d['id'], 'time' => $d['time'], 'user' => $d['user'], 'tool' => $d['ability'], 'status' => $d['status'], 'message' => $d['message'], 'summary' => $d['summary'], 'undoable' => $d['undoable'], 'undone' => ! empty( $d['undone'] ) );
		}
		return array( 'total' => $q->found_posts, 'entries' => $out );
	},
) );

hsm_ability( 'undo', array(
	'label'       => 'Undo an earlier change',
	'description' => 'Reverts a change recorded in the audit log (products, prices, stock, sale settings, SEO fields, coupons, store/SEO settings, redirects, pages). Orders, refunds, emails, plugin installs and permanent deletions cannot be undone. Changes made later to the same items are overwritten, so check audit-log first. Needs confirmation.',
	'cap'         => 'edit_posts',
	'properties'  => array( 'audit_id' => hsm_s( 'integer' ) ),
	'required'    => array( 'audit_id' ),
	'callback'    => function ( $in ) {
		$d = hsm_audit_read( $in['audit_id'] );
		if ( ! $d ) {
			throw new Exception( 'Audit entry not found.' );
		}
		if ( (int) get_post_field( 'post_author', $d['id'] ) !== get_current_user_id() && ! current_user_can( 'manage_woocommerce' ) ) {
			throw new HSM_Blocked( 'You can only undo your own changes.' );
		}
		if ( ! empty( $d['undone'] ) ) {
			throw new HSM_Blocked( 'Already undone.' );
		}
		if ( empty( $d['undo'] ) ) {
			throw new HSM_Blocked( 'This change (' . $d['ability'] . ') has nothing that can be undone.' );
		}
		global $wpdb;
		// Audit ids are monotonic; comparing dates misses changes made in the same second.
		$later = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'hsm_audit' AND ID > %d AND post_title LIKE %s LIMIT 20", $d['id'], '% — ok' ) );
		hsm_confirm( $in, sprintf( 'Undo %s from %s by %s%s', $d['ability'], $d['time'], $d['user'], $later ? ' — ' . count( $later ) . ' later change(s) exist and may be overwritten' : '' ), array( 'audit_id' => $d['id'], 'input' => $d['input'], 'restores' => count( $d['undo'] ) ) );
		$GLOBALS['hsm_undo'] = array();
		$log                 = hsm_apply_undo( $d['undo'] );
		$d['undone']         = true;
		$raw                 = json_decode( get_post_field( 'post_content', $d['id'] ), true );
		$raw['undone']       = current_time( 'mysql' );
		wp_update_post( array( 'ID' => $d['id'], 'post_content' => wp_slash( wp_json_encode( $raw, JSON_UNESCAPED_UNICODE ) ) ) );
		return array( 'undone' => $d['id'], 'log' => $log );
	},
) );

hsm_ability( 'safety-status', array(
	'label'       => 'Safety limits in force',
	'description' => 'Shows the guard rails this store enforces (minimum price, discount and bulk limits, confirmation rules, read-only mode, rate limit) and how much of today\'s daily refund and price-change budget is already used. Agents cannot change these; the owner changes them in WP Admin > WooCommerce > Hark Store Safety or with WP-CLI.',
	'readonly'    => true,
	'idempotent'  => true,
	'cap'         => 'edit_posts',
	'callback'    => function () {
		$u = wp_get_current_user();
		return array(
			'guard' => hsm_guard(),
			'today' => array( 'refunded' => hsm_daily_counter( 'refund' ), 'price_changes' => (int) hsm_daily_counter( 'reprice' ) ),
			'you'   => array( 'login' => $u->user_login, 'roles' => $u->roles, 'can_manage_store' => current_user_can( 'manage_woocommerce' ), 'is_admin' => current_user_can( 'manage_options' ) ),
		);
	},
) );
