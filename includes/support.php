<?php
/**
 * v1.8 Customer support — OFF by default.
 *
 * Product Q&A (customer asks on the product page → stored for review → answer shown publicly with
 * QAPage structured data), a contact form (honeypot + rate limit + stored in the database + optional
 * auto-reply), a guest order-lookup page (order number + email, rate limited against enumeration), and
 * one MCP inbox that gathers questions and messages so the AI can draft replies. Replies are only
 * *drafts* until the owner confirms; canned templates can be set to send automatically.
 *
 * Anti-spam on every public form: a honeypot field, a per-IP hourly rate limit, and (for Q&A) an
 * approval step. All input is sanitized; nothing is echoed unescaped.
 */
defined( 'ABSPATH' ) || exit;

const HSM_QNA_CPT = 'hsm_qna';
const HSM_MSG_CPT = 'hsm_msg';

add_action( 'init', function () {
	register_post_type( HSM_QNA_CPT, array( 'public' => false, 'show_ui' => false, 'rewrite' => false, 'query_var' => false, 'supports' => array( 'title', 'editor', 'author' ) ) );
	register_post_type( HSM_MSG_CPT, array( 'public' => false, 'show_ui' => false, 'rewrite' => false, 'query_var' => false, 'supports' => array( 'title', 'editor' ) ) );
} );

function hsm_support_defaults() {
	return array(
		'qna'      => array( 'enabled' => false, 'require_approval' => true, 'title' => 'Questions about this product' ),
		'contact'  => array( 'enabled' => false, 'notify_email' => '', 'auto_reply' => false, 'auto_reply_text' => 'Thanks — we received your message and will reply within 1 business day.' ),
		'lookup'   => array( 'enabled' => false ),
		'templates' => array(), // [{keywords:[], reply:''}]
		'rate_per_hour' => 5,
	);
}

function hsm_support_settings() {
	$o = (array) get_option( 'hsm_support', array() );
	$s = array();
	foreach ( hsm_support_defaults() as $k => $def ) {
		$s[ $k ] = is_array( $def ) ? wp_parse_args( is_array( $o[ $k ] ?? null ) ? $o[ $k ] : array(), $def ) : ( $o[ $k ] ?? $def );
	}
	return $s;
}

/* --------------------------------------------------------------------- */
/* Anti-spam helpers                                                       */
/* --------------------------------------------------------------------- */

function hsm_support_client_ip() {
	$ip = $_SERVER['REMOTE_ADDR'] ?? '';
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
}

/**
 * Stable per-visitor key for the post-redirect notice. Cookie-based, NOT IP-based: a notice can
 * carry an order's details, so keying it by IP let visitors behind one NAT (or a proxy) read each
 * other's results, and a page cache could replay it. A private cookie isolates each visitor.
 */
function hsm_support_visitor_key() {
	$k = isset( $_COOKIE['hsm_sup_vis'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) $_COOKIE['hsm_sup_vis'] ) : '';
	if ( 32 !== strlen( $k ) ) {
		$k = bin2hex( random_bytes( 16 ) );
		if ( ! headers_sent() ) {
			setcookie( 'hsm_sup_vis', $k, time() + HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		}
		$_COOKIE['hsm_sup_vis'] = $k;
	}
	return $k;
}

/** True when this IP is over the hourly submission limit. */
function hsm_support_rate_exceeded() {
	$limit = (int) hsm_support_settings()['rate_per_hour'];
	if ( $limit <= 0 ) {
		return false;
	}
	$key = 'hsm_sup_rl_' . md5( hsm_support_client_ip() . gmdate( 'YmdH' ) );
	$n   = (int) get_transient( $key );
	if ( $n >= $limit ) {
		return true;
	}
	set_transient( $key, $n + 1, 2 * HOUR_IN_SECONDS );
	return false;
}

/** Honeypot + rate limit + nonce. Returns an error string or ''. */
function hsm_support_guard( $nonce_action ) {
	if ( ! empty( $_POST['hsm_hp'] ) ) {
		return 'Submission rejected.';
	}
	if ( ! isset( $_POST['_hsm_nonce'] ) || ! wp_verify_nonce( (string) $_POST['_hsm_nonce'], $nonce_action ) ) {
		return 'Your session expired — please try again.';
	}
	if ( hsm_support_rate_exceeded() ) {
		return 'Too many submissions. Please try again later.';
	}
	return '';
}

/* --------------------------------------------------------------------- */
/* Product Q&A                                                             */
/* --------------------------------------------------------------------- */

/** Approved Q&A for a product: [{id, q, a, date}]. */
function hsm_support_qna_list( $product_id, $approved_only = true ) {
	$posts = get_posts( array(
		'post_type'   => HSM_QNA_CPT,
		'post_parent' => $product_id,
		'numberposts' => 50,
		'post_status' => $approved_only ? 'publish' : array( 'publish', 'pending' ),
		'orderby'     => 'date',
		'order'       => 'ASC',
	) );
	$out = array();
	foreach ( $posts as $p ) {
		$out[] = array(
			'id'     => $p->ID,
			'q'      => $p->post_title,
			'a'      => $p->post_content,
			'date'   => $p->post_date,
			'status' => $p->post_status,
			'name'   => get_post_meta( $p->ID, '_hsm_asker', true ),
		);
	}
	return $out;
}

function hsm_support_qna_form( $product_id ) {
	$s       = hsm_support_settings()['qna'];
	$notice  = get_transient( 'hsm_sup_notice_' . hsm_support_visitor_key() );
	$out     = '<section class="hsm-qna" style="margin:32px 0"><h2>' . esc_html( $s['title'] ) . '</h2>';
	if ( $notice ) {
		$out .= '<p class="hsm-qna-notice" style="padding:10px;background:#eef7ee;border-radius:6px">' . esc_html( $notice ) . '</p>';
	}
	$items = hsm_support_qna_list( $product_id, true );
	if ( $items ) {
		$out .= '<dl class="hsm-qna-list">';
		foreach ( $items as $it ) {
			$out .= '<dt><strong>Q: ' . esc_html( $it['q'] ) . '</strong></dt><dd>A: ' . wp_kses_post( wpautop( $it['a'] ) ) . '</dd>';
		}
		$out .= '</dl>';
	}
	$out .= '<form method="post" class="hsm-qna-form" style="margin-top:16px">'
		. wp_nonce_field( 'hsm_qna', '_hsm_nonce', true, false )
		. '<input type="hidden" name="hsm_action" value="qna">'
		. '<input type="hidden" name="hsm_back" value="' . esc_url( get_permalink() ) . '">'
		. '<input type="hidden" name="product_id" value="' . (int) $product_id . '">'
		. '<p style="display:none"><label>Leave empty<input type="text" name="hsm_hp" value=""></label></p>'
		. '<p><label>Your name<br><input type="text" name="asker" required maxlength="80"></label></p>'
		. '<p><label>Your question<br><textarea name="question" required maxlength="1000" rows="3" style="width:100%"></textarea></label></p>'
		. '<p><button type="submit">Ask a question</button></p></form></section>';
	return $out;
}

add_action( 'woocommerce_after_single_product_summary', function () {
	if ( empty( hsm_support_settings()['qna']['enabled'] ) ) {
		return;
	}
	global $product;
	if ( $product ) {
		echo wp_kses_post( hsm_support_qna_form( $product->get_id() ) );
	}
}, 25 );

/** QAPage structured data on a single product with approved Q&A. */
add_action( 'wp_head', function () {
	if ( empty( hsm_support_settings()['qna']['enabled'] ) || ! is_singular( 'product' ) || hsm_other_seo_active() ) {
		return;
	}
	$items = hsm_support_qna_list( get_queried_object_id(), true );
	if ( ! $items ) {
		return;
	}
	$main = array();
	foreach ( $items as $it ) {
		$main[] = array(
			'@type'          => 'Question',
			'name'           => wp_strip_all_tags( $it['q'] ),
			'acceptedAnswer' => array( '@type' => 'Answer', 'text' => wp_strip_all_tags( $it['a'] ) ),
		);
	}
	echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@type' => 'QAPage', 'mainEntity' => $main ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
}, 22 );

/* --------------------------------------------------------------------- */
/* Contact form shortcode                                                  */
/* --------------------------------------------------------------------- */

add_shortcode( 'hsm_contact_form', function () {
	if ( empty( hsm_support_settings()['contact']['enabled'] ) ) {
		return '';
	}
	$notice = get_transient( 'hsm_sup_notice_' . hsm_support_visitor_key() );
	$out    = '<div class="hsm-contact">';
	if ( $notice ) {
		$out .= '<p class="hsm-contact-notice" style="padding:10px;background:#eef7ee;border-radius:6px">' . esc_html( $notice ) . '</p>';
	}
	return $out . '<form method="post">'
		. wp_nonce_field( 'hsm_contact', '_hsm_nonce', true, false )
		. '<input type="hidden" name="hsm_action" value="contact">'
		. '<input type="hidden" name="hsm_back" value="' . esc_url( get_permalink() ) . '">'
		. '<p style="display:none"><label>Leave empty<input type="text" name="hsm_hp" value=""></label></p>'
		. '<p><label>Name<br><input type="text" name="name" required maxlength="80"></label></p>'
		. '<p><label>Email<br><input type="email" name="email" required maxlength="120"></label></p>'
		. '<p><label>Subject<br><input type="text" name="subject" maxlength="140"></label></p>'
		. '<p><label>Message<br><textarea name="message" required maxlength="4000" rows="5" style="width:100%"></textarea></label></p>'
		. '<p><button type="submit">Send</button></p></form></div>';
} );

/* --------------------------------------------------------------------- */
/* Guest order lookup shortcode                                            */
/* --------------------------------------------------------------------- */

add_shortcode( 'hsm_order_lookup', function () {
	if ( empty( hsm_support_settings()['lookup']['enabled'] ) ) {
		return '';
	}
	$out    = '<div class="hsm-lookup">';
	$notice = get_transient( 'hsm_sup_notice_' . hsm_support_visitor_key() );
	if ( $notice ) {
		$out .= '<div class="hsm-lookup-result" style="padding:12px;background:#f6f7f8;border-radius:6px">' . wp_kses_post( $notice ) . '</div>';
	}
	return $out . '<form method="post">'
		. wp_nonce_field( 'hsm_lookup', '_hsm_nonce', true, false )
		. '<input type="hidden" name="hsm_action" value="lookup">'
		. '<input type="hidden" name="hsm_back" value="' . esc_url( get_permalink() ) . '">'
		. '<p style="display:none"><label>Leave empty<input type="text" name="hsm_hp" value=""></label></p>'
		. '<p><label>Order number<br><input type="text" name="order" required maxlength="30"></label></p>'
		. '<p><label>Email used on the order<br><input type="email" name="email" required maxlength="120"></label></p>'
		. '<p><button type="submit">Look up</button></p></form></div>';
} );

/* --------------------------------------------------------------------- */
/* Form handling                                                           */
/* --------------------------------------------------------------------- */

add_action( 'init', function () {
	if ( empty( $_POST['hsm_action'] ) ) {
		return;
	}
	$action = sanitize_key( (string) $_POST['hsm_action'] );
	$ip_key = 'hsm_sup_notice_' . hsm_support_visitor_key();
	$back   = home_url( '/' );
	$cand   = esc_url_raw( wp_unslash( $_POST['hsm_back'] ?? '' ) );
	if ( $cand && wp_parse_url( $cand, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
		$back = $cand;
	} elseif ( wp_get_referer() ) {
		$back = wp_get_referer();
	}

	if ( 'qna' === $action ) {
		$err = hsm_support_guard( 'hsm_qna' );
		if ( '' === $err ) {
			$pid = (int) ( $_POST['product_id'] ?? 0 );
			$q   = sanitize_text_field( wp_unslash( $_POST['question'] ?? '' ) );
			$by  = sanitize_text_field( wp_unslash( $_POST['asker'] ?? '' ) );
			if ( ! wc_get_product( $pid ) || '' === $q ) {
				$err = 'Please enter a question.';
			} else {
				$status = empty( hsm_support_settings()['qna']['require_approval'] ) ? 'publish' : 'pending';
				$id     = wp_insert_post( array( 'post_type' => HSM_QNA_CPT, 'post_status' => $status, 'post_parent' => $pid, 'post_title' => $q, 'post_content' => '' ) );
				if ( $id ) {
					update_post_meta( $id, '_hsm_asker', $by );
					$err = 'publish' === $status ? 'Thanks — your question is posted.' : 'Thanks — your question was sent for review.';
				}
			}
		}
		set_transient( $ip_key, $err, 60 );
		wp_safe_redirect( $back );
		exit;
	}

	if ( 'contact' === $action ) {
		$err = hsm_support_guard( 'hsm_contact' );
		if ( '' === $err ) {
			$name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
			$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			$subj  = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
			$msg   = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
			if ( ! is_email( $email ) || '' === $msg ) {
				$err = 'Please enter a valid email and message.';
			} else {
				$s  = hsm_support_settings()['contact'];
				$id = wp_insert_post( array(
					'post_type'    => HSM_MSG_CPT,
					'post_status'  => 'publish',
					'post_title'   => $subj ?: ( 'Message from ' . $name ),
					'post_content' => $msg,
				) );
				update_post_meta( $id, '_hsm_from_name', $name );
				update_post_meta( $id, '_hsm_from_email', $email );
				update_post_meta( $id, '_hsm_status', 'new' );
				$auto = hsm_support_match_template( $msg );
				if ( $auto ) {
					wp_mail( $email, 'Re: ' . ( $subj ?: 'your message' ), $auto );
					update_post_meta( $id, '_hsm_status', 'auto-replied' );
					update_post_meta( $id, '_hsm_reply', $auto );
				} elseif ( ! empty( $s['auto_reply'] ) && ! empty( $s['auto_reply_text'] ) ) {
					wp_mail( $email, 'We received your message', $s['auto_reply_text'] );
					update_post_meta( $id, '_hsm_status', 'auto-ack' );
				}
				$to = $s['notify_email'] ?: get_option( 'admin_email' );
				wp_mail( $to, 'New contact message: ' . ( $subj ?: '(no subject)' ), $msg . "\n\nFrom: $name <$email>" );
				$err = 'Thanks — we received your message.';
			}
		}
		set_transient( $ip_key, $err, 60 );
		wp_safe_redirect( $back );
		exit;
	}

	if ( 'lookup' === $action ) {
		$err = hsm_support_guard( 'hsm_lookup' );
		if ( '' === $err ) {
			$num   = sanitize_text_field( wp_unslash( $_POST['order'] ?? '' ) );
			$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			$order = false;
			if ( ctype_digit( $num ) ) {
				$order = wc_get_order( (int) $num );
			}
			if ( ! $order ) {
				$found = wc_get_orders( array( 'limit' => 1, 'billing_email' => $email ) );
				foreach ( $found as $o ) {
					if ( $o->get_order_number() === $num ) {
						$order = $o;
						break;
					}
				}
			}
			// Always the same message shape; never reveal whether the order exists for a wrong email.
			if ( $order && strtolower( $order->get_billing_email() ) === strtolower( $email ) ) {
				$t     = '';
				foreach ( hsm_shipments_get( $order ) as $sh ) {
					$t .= ' ' . $sh['label'] . ' ' . $sh['number'] . ( $sh['url'] ? ' (' . $sh['url'] . ')' : '' );
				}
				$err = 'Order ' . $order->get_order_number() . ': ' . wc_get_order_status_name( $order->get_status() ) . ( $t ? '. Tracking:' . $t : '' );
			} else {
				$err = 'No order found with that number and email.';
			}
		}
		set_transient( $ip_key, $err, 60 );
		wp_safe_redirect( $back );
		exit;
	}
} );

/** First canned template whose keyword appears in the message, or ''. */
function hsm_support_match_template( $message ) {
	$message = mb_strtolower( $message );
	foreach ( (array) hsm_support_settings()['templates'] as $t ) {
		foreach ( (array) ( $t['keywords'] ?? array() ) as $kw ) {
			$kw = trim( mb_strtolower( (string) $kw ) );
			if ( '' !== $kw && false !== mb_strpos( $message, $kw ) ) {
				return (string) ( $t['reply'] ?? '' );
			}
		}
	}
	return '';
}

/* --------------------------------------------------------------------- */
/* MCP tool: hark-store/support                                            */
/* --------------------------------------------------------------------- */

function hsm_support_actions() {
	return array(
		'inbox' => false, 'get' => false, 'get_settings' => false, 'qna_list' => false,
		'set_settings' => true, 'qna_answer' => true, 'qna_approve' => true, 'qna_delete' => true,
		'msg_reply' => true, 'msg_resolve' => true, 'templates_set' => true,
	);
}

hsm_ability( 'support', array(
	'label'       => 'Customer support inbox',
	'description' => 'Product Q&A, contact-form messages and canned replies. action=inbox (pending questions + recent messages) | get (id, type qna|msg) | qna_list (product_id) | qna_answer (id, answer, publish=false → stays a draft; publish=true shows it and adds QAPage data) | qna_approve (id) | qna_delete (id) | msg_reply (id, reply, send=false → draft only; send=true emails the customer) | msg_resolve (id) | templates_set (items:[{keywords:[], reply:""}] — matched messages are auto-replied) | get_settings | set_settings (qna {enabled, require_approval, title} | contact {enabled, notify_email, auto_reply, auto_reply_text} | lookup {enabled} | rate_per_hour). Public forms use a honeypot, a per-IP hourly limit and (for Q&A) approval. Nothing is sent to a customer unless send=true.',
	'properties'  => array(
		'action'     => hsm_s( 'string', '', array( 'enum' => array_keys( hsm_support_actions() ) ) ),
		'id'         => hsm_s( 'integer' ),
		'type'       => hsm_s( 'string', 'qna or msg.', array( 'enum' => array( 'qna', 'msg' ) ) ),
		'product_id' => hsm_s( 'integer' ),
		'answer'     => hsm_s( 'string' ),
		'reply'      => hsm_s( 'string' ),
		'publish'    => hsm_s( 'boolean' ),
		'send'       => hsm_s( 'boolean' ),
		'items'      => hsm_s( 'array' ),
		'settings'   => hsm_s( 'object' ),
		'limit'      => hsm_s( 'integer' ),
	),
	'is_write'    => function ( $in ) {
		return ! empty( hsm_support_actions()[ $in['action'] ?? '' ] );
	},
	'callback'    => function ( $in ) {
		$action = (string) ( $in['action'] ?? 'inbox' );
		if ( ! array_key_exists( $action, hsm_support_actions() ) ) {
			throw new Exception( 'Unknown action. Allowed: ' . implode( ', ', array_keys( hsm_support_actions() ) ) );
		}
		if ( 'get_settings' === $action ) {
			return hsm_support_settings();
		}
		if ( 'inbox' === $action ) {
			$limit = max( 1, min( 100, (int) ( $in['limit'] ?? 25 ) ) );
			$q     = get_posts( array( 'post_type' => HSM_QNA_CPT, 'post_status' => 'pending', 'numberposts' => $limit ) );
			$m     = get_posts( array( 'post_type' => HSM_MSG_CPT, 'post_status' => 'publish', 'numberposts' => $limit ) );
			return array(
				'pending_questions' => array_map( function ( $p ) {
					$prod = wc_get_product( $p->post_parent );
					return array( 'id' => $p->ID, 'question' => $p->post_title, 'asker' => get_post_meta( $p->ID, '_hsm_asker', true ), 'product' => $prod ? $prod->get_name() : null, 'product_id' => $p->post_parent );
				}, $q ),
				'messages' => array_map( function ( $p ) {
					return array( 'id' => $p->ID, 'subject' => $p->post_title, 'from' => get_post_meta( $p->ID, '_hsm_from_email', true ), 'name' => get_post_meta( $p->ID, '_hsm_from_name', true ), 'status' => get_post_meta( $p->ID, '_hsm_status', true ), 'excerpt' => wp_trim_words( $p->post_content, 40 ) );
				}, $m ),
			);
		}
		if ( 'qna_list' === $action ) {
			return array( 'product_id' => (int) ( $in['product_id'] ?? 0 ), 'items' => hsm_support_qna_list( (int) ( $in['product_id'] ?? 0 ), false ) );
		}
		if ( 'get' === $action ) {
			$p = get_post( (int) ( $in['id'] ?? 0 ) );
			if ( ! $p || ! in_array( $p->post_type, array( HSM_QNA_CPT, HSM_MSG_CPT ), true ) ) {
				throw new Exception( 'Not found.' );
			}
			$meta = get_post_meta( $p->ID );
			$flat = array();
			foreach ( $meta as $k => $v ) {
				$flat[ $k ] = maybe_unserialize( $v[0] ?? '' );
			}
			return array( 'id' => $p->ID, 'type' => $p->post_type, 'title' => $p->post_title, 'content' => $p->post_content, 'status' => $p->post_status, 'date' => $p->post_date, 'meta' => $flat );
		}
		if ( 'set_settings' === $action ) {
			$incoming = (array) ( $in['settings'] ?? array() );
			if ( ! $incoming ) {
				throw new Exception( 'settings must be a non-empty object.' );
			}
			$before = get_option( 'hsm_support', array() );
			$new    = $before;
			foreach ( $incoming as $key => $val ) {
				if ( ! isset( hsm_support_defaults()[ $key ] ) ) {
					throw new Exception( 'Unknown settings key: ' . $key );
				}
				$new[ $key ] = hsm_support_sanitize( $key, $val );
			}
			hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_support', 'old' => $before ) );
			update_option( 'hsm_support', $new );
			return array( 'action' => 'set_settings', 'changed' => array_keys( $incoming ), 'settings' => hsm_support_settings() );
		}
		if ( 'templates_set' === $action ) {
			$clean  = array();
			foreach ( (array) ( $in['items'] ?? array() ) as $t ) {
				$clean[] = array(
					'keywords' => array_map( 'sanitize_text_field', array_filter( (array) ( $t['keywords'] ?? array() ) ) ),
					'reply'    => wp_kses_post( (string) ( $t['reply'] ?? '' ) ),
				);
			}
			$before = get_option( 'hsm_support', array() );
			$new    = $before;
			$new['templates'] = $clean;
			hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_support', 'old' => $before ) );
			update_option( 'hsm_support', $new );
			return array( 'action' => 'templates_set', 'count' => count( $clean ) );
		}
		// Item actions.
		$p = get_post( (int) ( $in['id'] ?? 0 ) );
		if ( ! $p || ! in_array( $p->post_type, array( HSM_QNA_CPT, HSM_MSG_CPT ), true ) ) {
			throw new Exception( 'Item not found.' );
		}
		if ( 'qna_answer' === $action ) {
			$answer = wp_kses_post( (string) ( $in['answer'] ?? '' ) );
			if ( '' === trim( $answer ) ) {
				throw new Exception( 'answer is empty.' );
			}
			hsm_undo_add( array( 'type' => 'post', 'id' => $p->ID, 'state' => array( 'ID' => $p->ID, 'post_content' => $p->post_content, 'post_status' => $p->post_status ) ) );
			$publish = ! empty( $in['publish'] );
			wp_update_post( array( 'ID' => $p->ID, 'post_content' => wp_slash( $answer ), 'post_status' => $publish ? 'publish' : 'pending' ) );
			update_post_meta( $p->ID, '_hsm_answered', 1 );
			return array( 'action' => 'qna_answer', 'id' => $p->ID, 'status' => $publish ? 'published' : 'draft (not visible yet)' );
		}
		if ( 'qna_approve' === $action ) {
			hsm_undo_add( array( 'type' => 'post', 'id' => $p->ID, 'state' => array( 'ID' => $p->ID, 'post_status' => $p->post_status ) ) );
			wp_update_post( array( 'ID' => $p->ID, 'post_status' => 'publish' ) );
			return array( 'action' => 'qna_approve', 'id' => $p->ID );
		}
		if ( 'qna_delete' === $action ) {
			wp_delete_post( $p->ID, true );
			return array( 'action' => 'qna_delete', 'id' => $p->ID );
		}
		if ( 'msg_reply' === $action ) {
			$reply = wp_kses_post( (string) ( $in['reply'] ?? '' ) );
			if ( '' === trim( $reply ) ) {
				throw new Exception( 'reply is empty.' );
			}
			$to = get_post_meta( $p->ID, '_hsm_from_email', true );
			if ( ! empty( $in['send'] ) ) {
				if ( ! is_email( $to ) ) {
					throw new Exception( 'This message has no valid reply address.' );
				}
				hsm_confirm( $in, 'Send this reply by email to ' . $to, array( 'to' => $to, 'reply' => wp_trim_words( $reply, 30 ) ) );
				wp_mail( $to, 'Re: ' . $p->post_title, $reply );
				update_post_meta( $p->ID, '_hsm_status', 'replied' );
				update_post_meta( $p->ID, '_hsm_reply', $reply );
				return array( 'action' => 'msg_reply', 'id' => $p->ID, 'sent_to' => $to );
			}
			update_post_meta( $p->ID, '_hsm_reply_draft', $reply );
			return array( 'action' => 'msg_reply', 'id' => $p->ID, 'status' => 'draft saved (not sent)', 'draft' => $reply );
		}
		if ( 'msg_resolve' === $action ) {
			update_post_meta( $p->ID, '_hsm_status', 'resolved' );
			return array( 'action' => 'msg_resolve', 'id' => $p->ID );
		}
		throw new Exception( 'Unhandled action.' );
	},
) );

function hsm_support_sanitize( $key, $val ) {
	$def = hsm_support_defaults()[ $key ];
	if ( ! is_array( $def ) ) {
		// Scalar settings (e.g. rate_per_hour) are not a group of keys.
		if ( is_int( $def ) ) {
			return max( 0, (int) $val );
		}
		return is_bool( $def ) ? (bool) $val : sanitize_text_field( (string) $val );
	}
	$val = is_array( $val ) ? $val : array();
	$out = array();
	foreach ( $def as $k => $d ) {
		if ( ! array_key_exists( $k, $val ) ) {
			$out[ $k ] = $d;
			continue;
		}
		if ( is_bool( $d ) ) {
			$out[ $k ] = (bool) $val[ $k ];
		} elseif ( is_int( $d ) ) {
			$out[ $k ] = max( 0, (int) $val[ $k ] );
		} elseif ( 'notify_email' === $k ) {
			$out[ $k ] = sanitize_email( (string) $val[ $k ] );
		} else {
			$out[ $k ] = wp_kses_post( (string) $val[ $k ] );
		}
	}
	return $out;
}
