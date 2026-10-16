<?php
/**
 * v1.9 Core probe: ping (connectivity check). Read-only and never audited or rate-limited,
 * so it stays callable even when the store is in read-only mode.
 *
 * Guard/limit state is already exposed by the existing hark-store-safety-status tool
 * (includes/abilities-site.php); v1.9 only adds today's usage counters to it.
 */
defined( 'ABSPATH' ) || exit;

hsm_ability( 'ping', array(
	'label'       => 'Ping (connectivity check)',
	'description' => 'Connectivity check. Returns the current GMT time, store name, plugin version and how many tools this server exposes. '
		. 'If any other tool call fails or times out, call this first: when ping answers, the server is up and the failure was in that call; '
		. 'when ping does not answer either, the MCP server is unreachable — pause tool calls instead of retrying them.',
	'readonly'    => true,
	'callback'    => function () {
		return array(
			'ok'        => true,
			'gmt'       => current_time( 'mysql', true ),
			'store'     => get_bloginfo( 'name' ),
			'version'   => HSM_Registry::$version,
			'tools'     => count( HSM_Registry::$abilities ),
			'wc_active' => class_exists( 'WooCommerce' ),
		);
	},
) );
