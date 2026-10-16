<?php
/** Redirect manager + 404 log (replaces a redirection plugin). */
defined( 'ABSPATH' ) || exit;

function hsm_redirects_install() {
	add_option( 'hsm_redirects', array(), '', false );
	add_option( 'hsm_404_log', array(), '', false );
}

function hsm_norm_path( $p ) {
	$p = preg_replace( '#^https?://[^/]+#', '', trim( $p ) );
	$p = '/' . ltrim( $p, '/' );
	return strlen( $p ) > 1 ? rtrim( $p, '/' ) : $p;
}

function hsm_redirect_protected_paths() {
	return array( '/wp-admin', '/wp-login.php', '/wp-json', '/cart', '/checkout', '/my-account', '/xmlrpc.php', '/wp-cron.php', '/feeds/google-merchant.xml', '/wp-sitemap.xml', '/robots.txt' );
}

function hsm_redirect_is_protected( $path ) {
	foreach ( hsm_redirect_protected_paths() as $pp ) {
		if ( $path === $pp || 0 === strpos( $path, $pp . '/' ) || 0 === strpos( $path, $pp . '?' ) ) {
			return true;
		}
	}
	return false;
}

/** One rule step: returns array(target, rule_src, code) or null. */
function hsm_redirect_match( $path, $all ) {
	foreach ( $all as $src => $r ) {
		$src = (string) $src;
		if ( 0 === strpos( $src, '^' ) ) {
			$rx = '#' . str_replace( '#', '\\#', $src ) . '#';
			if ( @preg_match( $rx, $path ) ) {
				return array( preg_replace( $rx, $r['target'], $path ), $src, (int) $r['code'] );
			}
		} elseif ( $src === $path ) {
			return array( $r['target'], $src, (int) $r['code'] );
		}
	}
	return null;
}

/**
 * Follows the chain of rules server-side (max 5 hops) so visitors get one redirect.
 * Returns array(final_target, first_rule_src, code) or null; 'loop' as target when the chain cycles.
 */
function hsm_redirect_resolve( $path, $all, $max = 5 ) {
	$first = hsm_redirect_match( $path, $all );
	if ( ! $first ) {
		return null;
	}
	$seen   = array( $path => true );
	$target = $first[0];
	$code   = $first[2];
	for ( $i = 0; $i < $max; $i++ ) {
		if ( 410 === $code || preg_match( '#^https?://#i', $target ) && wp_parse_url( $target, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return array( $target, $first[1], $code );
		}
		$next_path = hsm_norm_path( strtok( $target, '?' ) );
		if ( isset( $seen[ $next_path ] ) ) {
			return array( 'loop', $first[1], $code );
		}
		$seen[ $next_path ] = true;
		$step = hsm_redirect_match( $next_path, $all );
		if ( ! $step ) {
			return array( $target, $first[1], $code );
		}
		$target = $step[0];
		if ( 410 === $step[2] ) {
			$code = 410;
		}
	}
	return array( 'loop', $first[1], $code );
}

add_action( 'template_redirect', function () {
	$req  = wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' );
	$path = hsm_norm_path( rawurldecode( strtok( $req, '?' ) ) );
	$all  = get_option( 'hsm_redirects', array() );
	if ( $all && ! hsm_redirect_is_protected( $path ) ) {
		$res = hsm_redirect_resolve( $path, $all );
		if ( $res && 'loop' !== $res[0] ) {
			list( $target, $src, $code ) = $res;
			$r = $all[ $src ];
			// Sampled hit counting (1 in 20, weighted) so a flood of requests cannot turn into a flood of DB writes.
			if ( 1 === wp_rand( 1, 20 ) ) {
				$all[ $src ]['hits'] = ( $r['hits'] ?? 0 ) + 20;
				$all[ $src ]['last'] = gmdate( 'Y-m-d H:i' );
				update_option( 'hsm_redirects', $all, false );
			}
			if ( 410 === $code ) {
				status_header( 410 );
				nocache_headers();
				exit;
			}
			$target = str_replace( array( "\r", "\n" ), '', $target );
			if ( preg_match( '#^https?://#i', $target ) ) {
				// Only targets written literally in a rule may leave the site; captured input never can.
				$host = wp_parse_url( $target, PHP_URL_HOST );
				$lit  = 0 !== strpos( $src, '^' ) || preg_match( '#^https?://#i', $r['target'] );
				if ( ! $lit && $host !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
					return;
				}
				$url = $target;
			} else {
				$url = home_url( '/' . ltrim( $target, '/' ) );
			}
			wp_redirect( $url, $code ?: 301, 'Hark Store' );
			exit;
		}
	}
	if ( is_404() ) {
		$log  = get_option( 'hsm_404_log', array() );
		$path = substr( $path, 0, 300 );
		if ( isset( $log[ $path ] ) ) {
			if ( 1 !== wp_rand( 1, 10 ) ) {
				return; // Sampled: known 404s are counted 1-in-10, weighted.
			}
			$log[ $path ]['hits'] += 10;
			$log[ $path ]['last']  = gmdate( 'Y-m-d H:i' );
		} else {
			// Scanners hit thousands of random URLs: record at most 30 new 404 paths per minute.
			$recent = 0;
			$now    = time();
			foreach ( $log as $e ) {
				$recent += ( isset( $e['first'] ) && $now - $e['first'] < 60 ) ? 1 : 0;
			}
			if ( $recent >= 30 ) {
				return;
			}
			$log[ $path ] = array( 'hits' => 1, 'first' => $now, 'last' => gmdate( 'Y-m-d H:i' ), 'referer' => esc_url_raw( substr( wp_get_referer() ?: '', 0, 200 ) ) );
		}
		if ( count( $log ) > 300 ) {
			uasort( $log, function ( $a, $b ) { return strcmp( $b['last'], $a['last'] ); } );
			$log = array_slice( $log, 0, 300, true );
		}
		update_option( 'hsm_404_log', $log, false );
	}
}, 1 );
