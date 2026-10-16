<?php
/**
 * v1.8 Marketing & retention — OFF by default.
 *
 * A subscribe popup that stores subscribers locally (never a third-party script), optionally issues a
 * first-order coupon and can export the list; a wishlist (server-side for logged-in users, a cookie
 * for guests); and a read-only abandoned-cart report built from the carts the follow-up module tracks.
 *
 * The subscribe form requires an explicit consent checkbox; nothing is stored without it.
 */
defined( 'ABSPATH' ) || exit;

const HSM_SUB_CPT = 'hsm_sub';

add_action( 'init', function () {
	register_post_type( HSM_SUB_CPT, array( 'public' => false, 'show_ui' => false, 'rewrite' => false, 'query_var' => false, 'supports' => array( 'title' ) ) );
} );

function hsm_mkt_defaults() {
	return array(
		'subscribe' => array( 'enabled' => false, 'title' => 'Get 10% off', 'text' => 'Join our list for deals and restock alerts.', 'button' => 'Subscribe', 'consent_text' => 'I agree to receive marketing emails.', 'coupon_percent' => 10, 'delay' => 6 ),
		'wishlist'  => array( 'enabled' => false, 'title' => 'My wishlist' ),
	);
}

function hsm_mkt_settings() {
	$o = (array) get_option( 'hsm_marketing', array() );
	$s = array();
	foreach ( hsm_mkt_defaults() as $k => $def ) {
		$s[ $k ] = wp_parse_args( is_array( $o[ $k ] ?? null ) ? $o[ $k ] : array(), $def );
	}
	return $s;
}

/* --------------------------------------------------------------------- */
/* Subscribe popup                                                         */
/* --------------------------------------------------------------------- */

add_action( 'wp_footer', function () {
	$s = hsm_mkt_settings()['subscribe'];
	if ( empty( $s['enabled'] ) || is_admin() ) {
		return;
	}
	$delay = max( 0, (int) $s['delay'] );
	?>
<div id="hsm-sub-popup" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;align-items:center;justify-content:center">
	<div style="background:#fff;max-width:420px;margin:20px;padding:24px;border-radius:10px;position:relative">
		<button type="button" id="hsm-sub-close" aria-label="Close" style="position:absolute;top:8px;right:10px;border:0;background:none;font-size:22px;cursor:pointer">&times;</button>
		<h3 style="margin-top:0"><?php echo esc_html( $s['title'] ); ?></h3>
		<p><?php echo esc_html( $s['text'] ); ?></p>
		<form method="post">
			<?php echo wp_nonce_field( 'hsm_sub', '_hsm_nonce', true, false ); ?>
			<input type="hidden" name="hsm_action" value="subscribe">
			<input type="hidden" name="hsm_back" value="<?php echo esc_url( home_url( add_query_arg( array() ) ) ); ?>">
			<p style="display:none"><label>Leave empty<input type="text" name="hsm_hp" value=""></label></p>
			<input type="email" name="email" required placeholder="you@example.com" maxlength="120" style="width:100%;padding:8px;margin-bottom:8px">
			<label style="display:block;font-size:13px;margin-bottom:8px"><input type="checkbox" name="consent" value="1" required> <?php echo esc_html( $s['consent_text'] ); ?></label>
			<button type="submit" style="width:100%;padding:10px"><?php echo esc_html( $s['button'] ); ?></button>
		</form>
	</div>
</div>
<script>
(function(){var p=document.getElementById("hsm-sub-popup");if(!p)return;
if(document.cookie.indexOf("hsm_sub_done=1")>-1)return;
var show=function(){p.style.display="flex";};
setTimeout(show, <?php echo (int) $delay * 1000; ?>);
document.getElementById("hsm-sub-close").onclick=function(){p.style.display="none";document.cookie="hsm_sub_done=1;path=/;max-age=2592000";};
})();
</script>
	<?php
} );

/* --------------------------------------------------------------------- */
/* Wishlist                                                                */
/* --------------------------------------------------------------------- */

function hsm_mkt_wishlist_get() {
	if ( is_user_logged_in() ) {
		return array_values( array_filter( array_map( 'absint', (array) get_user_meta( get_current_user_id(), '_hsm_wishlist', true ) ) ) );
	}
	return isset( $_COOKIE['hsm_wish'] ) ? array_values( array_filter( array_map( 'absint', explode( ',', (string) $_COOKIE['hsm_wish'] ) ) ) ) : array();
}

function hsm_mkt_wishlist_set( $ids ) {
	$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	if ( is_user_logged_in() ) {
		update_user_meta( get_current_user_id(), '_hsm_wishlist', $ids );
	} else {
		setcookie( 'hsm_wish', implode( ',', $ids ), time() + YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
	}
}

add_action( 'woocommerce_single_product_summary', function () {
	if ( empty( hsm_mkt_settings()['wishlist']['enabled'] ) ) {
		return;
	}
	global $product;
	if ( ! $product ) {
		return;
	}
	$in    = in_array( $product->get_id(), hsm_mkt_wishlist_get(), true );
	$url   = add_query_arg( array( 'hsm_wish_add' => $product->get_id(), '_wpnonce' => wp_create_nonce( 'hsm_wish_' . $product->get_id() ) ), get_permalink( $product->get_id() ) );
	$label = $in ? 'In your wishlist' : 'Add to wishlist';
	echo '<p class="hsm-wish-link"><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>';
}, 36 );

add_shortcode( 'hsm_wishlist', function () {
	$s = hsm_mkt_settings()['wishlist'];
	if ( empty( $s['enabled'] ) ) {
		return '';
	}
	$ids = hsm_mkt_wishlist_get();
	if ( ! $ids ) {
		return '<p>' . esc_html__( 'Your wishlist is empty.', 'hark-store-mcp' ) . '</p>';
	}
	$out = '<div class="hsm-wishlist"><h3>' . esc_html( $s['title'] ) . '</h3><ul>';
	foreach ( $ids as $id ) {
		$p = wc_get_product( $id );
		if ( $p ) {
			$out .= '<li><a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( $p->get_name() ) . '</a></li>';
		}
	}
	return $out . '</ul></div>';
} );

/* --------------------------------------------------------------------- */
/* Form + wishlist handling                                                */
/* --------------------------------------------------------------------- */

add_action( 'init', function () {
	// Wishlist toggle (GET link).
	if ( isset( $_GET['hsm_wish_add'] ) && ! empty( hsm_mkt_settings()['wishlist']['enabled'] ) ) {
		$id = absint( $_GET['hsm_wish_add'] );
		if ( $id && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( (string) $_GET['_wpnonce'], 'hsm_wish_' . $id ) ) {
			$list = hsm_mkt_wishlist_get();
			if ( ! in_array( $id, $list, true ) ) {
				$list[] = $id;
				hsm_mkt_wishlist_set( $list );
			}
		}
		wp_safe_redirect( get_permalink( $id ) ?: home_url( '/' ) );
		exit;
	}

	if ( empty( $_POST['hsm_action'] ) || 'subscribe' !== $_POST['hsm_action'] ) {
		return;
	}
	$back = home_url( '/' );
	$cand = esc_url_raw( wp_unslash( $_POST['hsm_back'] ?? '' ) );
	if ( $cand && wp_parse_url( $cand, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
		$back = $cand;
	}
	$err = '';
	if ( ! empty( $_POST['hsm_hp'] ) || ! isset( $_POST['_hsm_nonce'] ) || ! wp_verify_nonce( (string) $_POST['_hsm_nonce'], 'hsm_sub' ) ) {
		$err = 'Submission rejected.';
	} elseif ( empty( $_POST['consent'] ) ) {
		$err = 'Please tick the consent box.';
	} elseif ( function_exists( 'hsm_support_rate_exceeded' ) && hsm_support_rate_exceeded() ) {
		$err = 'Too many submissions. Please try again later.';
	} else {
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			$err = 'Please enter a valid email.';
		} else {
			$id = wp_insert_post( array( 'post_type' => HSM_SUB_CPT, 'post_status' => 'publish', 'post_title' => $email ) );
			update_post_meta( $id, '_hsm_email', $email );
			$pct = (float) hsm_mkt_settings()['subscribe']['coupon_percent'];
			if ( $pct > 0 && class_exists( 'WC_Coupon' ) ) {
				$code = 'WELCOME-' . strtoupper( wp_generate_password( 6, false, false ) );
				$c    = new WC_Coupon();
				$c->set_code( $code );
				$c->set_discount_type( 'percent' );
				$c->set_amount( $pct );
				$c->set_usage_limit_per_user( 1 );
				$c->set_individual_use( true );
				$c->set_date_expires( time() + 30 * DAY_IN_SECONDS );
				$c->save();
				update_post_meta( $id, '_hsm_coupon', $code );
				wp_mail( $email, 'Your coupon code', 'Welcome! Use code ' . $code . ' for ' . $pct . '% off your first order.' );
			}
			$err = 'Thanks for subscribing!';
		}
	}
	set_transient( 'hsm_sub_notice_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' ), $err, 60 );
	wp_safe_redirect( $back );
	exit;
} );

/* --------------------------------------------------------------------- */
/* MCP tool: hark-store/marketing                                          */
/* --------------------------------------------------------------------- */

function hsm_mkt_actions() {
	return array( 'get_settings' => false, 'subscribers' => false, 'abandoned_report' => false, 'set_settings' => true, 'wishlist_add' => true, 'wishlist_remove' => true );
}

hsm_ability( 'marketing', array(
	'label'       => 'Marketing & retention',
	'description' => 'action=get_settings | set_settings (subscribe {enabled, title, text, button, consent_text, coupon_percent, delay} | wishlist {enabled, title}) | subscribers (list captured emails + issued coupons) | abandoned_report (read-only summary of tracked abandoned carts: count, value, opt-in rate, top products) | wishlist_add / wishlist_remove (user_id, product_id — manage a customer\'s saved products). The subscribe form requires explicit consent; subscribers are stored locally.',
	'properties'  => array(
		'action'     => hsm_s( 'string', '', array( 'enum' => array_keys( hsm_mkt_actions() ) ) ),
		'settings'   => hsm_s( 'object' ),
		'user_id'    => hsm_s( 'integer' ),
		'product_id' => hsm_s( 'integer' ),
		'limit'      => hsm_s( 'integer' ),
	),
	'is_write'    => function ( $in ) {
		return ! empty( hsm_mkt_actions()[ $in['action'] ?? '' ] );
	},
	'callback'    => function ( $in ) {
		$action = (string) ( $in['action'] ?? 'get_settings' );
		if ( ! array_key_exists( $action, hsm_mkt_actions() ) ) {
			throw new Exception( 'Unknown action. Allowed: ' . implode( ', ', array_keys( hsm_mkt_actions() ) ) );
		}
		if ( 'get_settings' === $action ) {
			return hsm_mkt_settings();
		}
		if ( 'subscribers' === $action ) {
			$limit = max( 1, min( 1000, (int) ( $in['limit'] ?? 200 ) ) );
			$subs  = get_posts( array( 'post_type' => HSM_SUB_CPT, 'numberposts' => $limit, 'post_status' => 'publish' ) );
			return array( 'count' => count( $subs ), 'subscribers' => array_map( function ( $s ) {
				return array( 'email' => get_post_meta( $s->ID, '_hsm_email', true ), 'coupon' => get_post_meta( $s->ID, '_hsm_coupon', true ), 'date' => $s->post_date );
			}, $subs ) );
		}
		if ( 'abandoned_report' === $action ) {
			$all   = function_exists( 'hsm_abandoned_get' ) ? hsm_abandoned_get() : array();
			$value = 0.0;
			$top   = array();
			$optin = 0;
			foreach ( $all as $rec ) {
				! empty( $rec['optin'] ) && $optin++;
				foreach ( (array) ( $rec['items'] ?? array() ) as $it ) {
					$p = wc_get_product( (int) ( $it['product_id'] ?? 0 ) );
					if ( $p ) {
						$value += (float) $p->get_price() * (int) ( $it['qty'] ?? 1 );
						$name   = $p->get_name();
						$top[ $name ] = ( $top[ $name ] ?? 0 ) + (int) ( $it['qty'] ?? 1 );
					}
				}
			}
			arsort( $top );
			return array(
				'open_checkouts' => count( $all ),
				'est_value'      => round( $value, 2 ),
				'opt_in_rate'    => $all ? round( $optin / count( $all ) * 100, 1 ) : 0,
				'top_products'   => array_slice( $top, 0, 10 ),
			);
		}
		if ( 'wishlist_add' === $action || 'wishlist_remove' === $action ) {
			$uid = (int) ( $in['user_id'] ?? 0 );
			$pid = (int) ( $in['product_id'] ?? 0 );
			if ( ! $uid || ! $pid ) {
				throw new Exception( 'user_id and product_id are required.' );
			}
			$list = array_values( array_filter( array_map( 'absint', (array) get_user_meta( $uid, '_hsm_wishlist', true ) ) ) );
			hsm_confirm( $in, ( 'wishlist_add' === $action ? 'Add product to' : 'Remove product from' ) . ' customer #' . $uid . ' wishlist', array( 'user_id' => $uid, 'product_id' => $pid, 'current_wishlist' => $list, 'action' => $action ) );
			if ( 'wishlist_add' === $action ) {
				if ( ! in_array( $pid, $list, true ) ) {
					$list[] = $pid;
				}
			} else {
				$list = array_diff( $list, array( $pid ) );
			}
			hsm_undo_add( array( 'type' => 'user_meta', 'id' => $uid, 'key' => '_hsm_wishlist', 'old' => get_user_meta( $uid, '_hsm_wishlist', true ) ) );
			update_user_meta( $uid, '_hsm_wishlist', array_values( $list ) );
			return array( 'action' => $action, 'user_id' => $uid, 'wishlist' => array_values( $list ) );
		}
		$incoming = (array) ( $in['settings'] ?? array() );
		if ( ! $incoming ) {
			throw new Exception( 'settings must be a non-empty object.' );
		}
		$before = get_option( 'hsm_marketing', array() );
		$new    = $before;
		$why    = array();
		foreach ( $incoming as $key => $val ) {
			if ( ! isset( hsm_mkt_defaults()[ $key ] ) ) {
				throw new Exception( 'Unknown settings key: ' . $key );
			}
			$out = array();
			foreach ( hsm_mkt_defaults()[ $key ] as $k => $d ) {
				$v = array_key_exists( $k, (array) $val ) ? $val[ $k ] : $d;
				if ( is_bool( $d ) ) {
					$out[ $k ] = (bool) $v;
				} elseif ( is_int( $d ) ) {
					$out[ $k ] = max( 0, (int) $v );
				} elseif ( is_float( $d ) ) {
					$out[ $k ] = max( 0, (float) $v );
				} else {
					$out[ $k ] = wp_kses_post( (string) $v );
				}
				// The subscribe coupon is a public 100%-off risk: enforce the same caps as coupon-manage.
				if ( 'coupon_percent' === $k && $out[ $k ] > 0 ) {
					$hard = (float) hsm_guard( 'hard_max_coupon_percent' );
					if ( $hard > 0 && $out[ $k ] > $hard ) {
						throw new HSM_Blocked( sprintf( 'coupon_percent %s is above the hard limit of %s%% (guard hard_max_coupon_percent).', $out[ $k ], $hard ) );
					}
					if ( $out[ $k ] > (float) hsm_guard( 'max_coupon_percent' ) ) {
						$why[] = sprintf( 'the subscribe coupon is %s%% (above the %s%% threshold)', $out[ $k ], hsm_guard( 'max_coupon_percent' ) );
					}
				}
			}
			$new[ $key ] = $out;
		}
		$reason = $why ? 'Marketing settings: ' . implode( '; ', $why ) : 'Update marketing and retention settings: ' . implode( ', ', array_keys( $incoming ) );
		hsm_confirm( $in, $reason, array( 'before' => $before, 'after' => $new ) );
		hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_marketing', 'old' => $before ) );
		update_option( 'hsm_marketing', $new );
		return array( 'action' => 'set_settings', 'changed' => array_keys( $incoming ), 'settings' => hsm_mkt_settings() );
	},
) );
