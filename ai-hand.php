<?php
/**
 * Plugin Name: AI Hand (Store Operations)
 * Description: MCP tools that let an AI agent run a WooCommerce store: products (incl. variations), categories, coupons, orders, refunds, customers, shipping, tax, reports, content, media, a lightweight SEO suite (meta, Open Graph, Product JSON-LD, sitemap hygiene, redirects, 404 log, robots.txt) and a Google Merchant Center feed, plus customer support and marketing — with guard rails, two-step confirmation, undo and an audit log.
 * Version: 1.1.0
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Author: Hark
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/hsm-core.php';

/**
 * This plugin's MCP namespace. Kept on the registry (not a constant) so the three AI plugins
 * can be active at once without their constants, functions or ability names colliding.
 */
HSM_Registry::$ns      = 'ai-hand/';
HSM_Registry::$cat     = 'ai-hand';
HSM_Registry::$version = '1.1.0';

$hsm_ns  = HSM_Registry::$ns;                 // Captured now: the shared static is reset by other plugins.
$hsm_pfx = str_replace( '/', '-', $hsm_ns );

require_once __DIR__ . '/includes/hsm-woo.php';
require_once __DIR__ . '/includes/abilities-core.php';
require_once __DIR__ . '/includes/seo-frontend.php';
require_once __DIR__ . '/includes/redirects.php';
require_once __DIR__ . '/includes/feed.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/abilities-store.php';
require_once __DIR__ . '/includes/abilities-orders.php';
require_once __DIR__ . '/includes/shipments.php';
require_once __DIR__ . '/includes/order-export.php';
require_once __DIR__ . '/includes/invoice.php';
require_once __DIR__ . '/includes/order-numbers.php';
require_once __DIR__ . '/includes/content.php';
require_once __DIR__ . '/includes/support.php';
require_once __DIR__ . '/includes/conversion.php';
require_once __DIR__ . '/includes/post-purchase.php';
require_once __DIR__ . '/includes/marketing.php';
require_once __DIR__ . '/includes/followups.php';
require_once __DIR__ . '/includes/shipping-rules.php';
require_once __DIR__ . '/includes/abilities-seo.php';
require_once __DIR__ . '/includes/abilities-site.php';
require_once __DIR__ . '/includes/abilities-reviews.php';

/** Ability category. */
add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'ai-hand', array(
		'label'       => 'AI Hand',
		'description' => 'WooCommerce store operations for AI agents.',
	) );
} );

/** Register a dedicated MCP server exposing only this plugin's abilities. */
add_action( 'mcp_adapter_init', function ( $adapter ) use ( $hsm_ns ) {
	$tools = array_values( array_filter( array_keys( HSM_Registry::$abilities ), function ( $n ) use ( $hsm_ns ) {
		return 0 === strpos( $n, $hsm_ns );
	} ) );
	$adapter->create_server(
		'ai-hand',
		'ai-hand',
		'mcp',
		'AI Hand',
		'Run a WooCommerce store: catalog, orders, marketing, SEO, support.',
		HSM_Registry::$version,
		array( \WP\MCP\Transport\HttpTransport::class ),
		\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
		null,
		$tools,
		array(),
		array(),
		'hsm_transport_permission'
	);
} );

if ( ! function_exists( 'hsm_transport_permission' ) ) {
	/** HTTP transport gate: only staff who can use at least one tool may open an MCP session. */
	function hsm_transport_permission() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		foreach ( array( 'manage_woocommerce', 'manage_options', 'edit_posts', 'upload_files' ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}
		return false;
	}
}

/** tools/list shows only the tools the current user is allowed to call (HTTP and STDIO). */
add_filter( 'mcp_adapter_tools_list', function ( $tools, $server = null ) use ( $hsm_ns, $hsm_pfx ) {
	if ( ! function_exists( 'wp_get_ability' ) ) {
		return $tools;
	}
	return array_values( array_filter( (array) $tools, function ( $t ) use ( $hsm_ns, $hsm_pfx ) {
		$name = is_object( $t ) && method_exists( $t, 'getName' ) ? $t->getName() : ( is_array( $t ) ? ( $t['name'] ?? '' ) : '' );
		if ( 0 !== strpos( $name, $hsm_pfx ) ) {
			return true;
		}
		$ability = wp_get_ability( $hsm_ns . substr( $name, strlen( $hsm_pfx ) ) );
		if ( ! $ability ) {
			return true;
		}
		return true === $ability->check_permissions( array() );
	} ) );
}, 10, 2 );

register_activation_hook( __FILE__, function () {
	hsm_redirects_install();
	hsm_feed_rewrite();
	flush_rewrite_rules();
} );

/** After an upgrade, register new rewrite rules once. */
$hsm_ver = HSM_Registry::$version;
add_action( 'init', function () use ( $hsm_ver ) {
	if ( get_option( 'ai_hand_version' ) !== $hsm_ver ) {
		flush_rewrite_rules( false );
		update_option( 'ai_hand_version', $hsm_ver );
	}
}, 99 );
