<?php
defined( 'ABSPATH' ) || exit;

/**
 * Product reviews: list, reply as the store, moderate, and preview-only sample reviews.
 * Sample reviews are always flagged with comment meta _hsm_sample_review=1, labelled on the storefront,
 * excluded from structured data, and removed with action=purge_samples before launch (FTC: no fake reviews).
 */

function hsm_review_out( $c ) {
	return array(
		'id'         => (int) $c->comment_ID,
		'product_id' => (int) $c->comment_post_ID,
		'product'    => get_the_title( $c->comment_post_ID ),
		'author'     => $c->comment_author,
		'rating'     => (int) get_comment_meta( $c->comment_ID, 'rating', true ),
		'content'    => $c->comment_content,
		'status'     => wp_get_comment_status( $c ),
		'date'       => $c->comment_date_gmt,
		'verified'   => (bool) get_comment_meta( $c->comment_ID, 'verified', true ),
		'sample'     => (bool) get_comment_meta( $c->comment_ID, '_hsm_sample_review', true ),
		'parent'     => (int) $c->comment_parent,
	);
}

function hsm_review_get( $id ) {
	$c = get_comment( absint( $id ) );
	if ( ! $c || ! in_array( $c->comment_type, array( 'review', 'comment', '' ), true ) || 'product' !== get_post_type( $c->comment_post_ID ) ) {
		throw new Exception( 'Review not found.' );
	}
	return $c;
}

function hsm_review_product( $in ) {
	$pid = hsm_product_id( $in );
	if ( ! $pid || 'product' !== get_post_type( $pid ) ) {
		throw new Exception( 'Product not found.' );
	}
	return $pid;
}

function hsm_review_text( $s, $max = 5000 ) {
	$s = trim( wp_strip_all_tags( (string) $s ) );
	if ( '' === $s ) {
		throw new Exception( 'content is required.' );
	}
	return mb_substr( $s, 0, $max );
}

hsm_ability( 'reviews', array(
	'label'       => 'Product reviews',
	'description' => 'Manage product reviews. action=list (filter by status all|approved|hold|spam, product id/sku, rating, unanswered=true, sample=true); action=reply (review_id, content) posts a public reply as the store; action=moderate (review_id, status approve|hold|spam|trash); action=add_sample (product id/sku, author, rating 1-5, content) adds a clearly flagged SAMPLE review for store previews only; action=purge_samples deletes every sample review (run before launch — fake reviews are illegal under FTC rules); action=import (product id/sku, author, rating, content, source REQUIRED, date) imports a review you actually collected elsewhere and labels it on the storefront as "via <source>" — an import without a source is rejected; action=get_settings | set_settings (reviews_enabled, ratings_enabled — turn the store\'s customer review flow on or off).',
	'properties'  => array(
		'action'     => hsm_s( 'string', '', array( 'enum' => array( 'list', 'reply', 'moderate', 'add_sample', 'purge_samples', 'import', 'get_settings', 'set_settings' ) ) ),
		'review_id'  => hsm_s( 'integer' ),
		'id'         => hsm_s( 'integer', 'Product id' ),
		'sku'        => hsm_s( 'string', 'Product SKU' ),
		'status'     => hsm_s( 'string', 'list filter, or new status for moderate', array( 'enum' => array( 'all', 'approved', 'hold', 'spam', 'approve', 'trash' ) ) ),
		'rating'     => hsm_s( 'integer', '', array( 'minimum' => 1, 'maximum' => 5 ) ),
		'unanswered' => hsm_s( 'boolean', 'list: only reviews without a store reply' ),
		'sample'     => hsm_s( 'boolean', 'list: only sample reviews' ),
		'author'     => hsm_s( 'string', 'add_sample/import: display name' ),
		'content'    => hsm_s( 'string' ),
		'source'     => hsm_s( 'string', 'import: where the review really came from (required, e.g. "Trustpilot", "in-store feedback").' ),
		'date'       => hsm_s( 'string', 'import: the review date, YYYY-MM-DD HH:MM.' ),
		'settings'   => hsm_s( 'object', 'set_settings: {reviews_enabled, ratings_enabled}' ),
		'page'       => hsm_s( 'integer' ),
		'per_page'   => hsm_s( 'integer' ),
	),
	'required'    => array( 'action' ),
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$action = (string) ( $in['action'] ?? '' );

		if ( 'list' === $action ) {
			list( $per, $page ) = hsm_paginate( $in );
			$status = (string) ( $in['status'] ?? 'all' );
			$q      = array( 'post_type' => 'product', 'type' => 'review', 'parent' => 0, 'number' => $per, 'offset' => ( $page - 1 ) * $per, 'orderby' => 'comment_date_gmt', 'order' => 'DESC',
				'status' => in_array( $status, array( 'approved', 'hold', 'spam' ), true ) ? ( 'approved' === $status ? 'approve' : $status ) : 'all' );
			if ( ! empty( $in['id'] ) || ! empty( $in['sku'] ) ) {
				$q['post_id'] = hsm_review_product( $in );
			}
			$mq = array();
			if ( ! empty( $in['rating'] ) ) {
				$mq[] = array( 'key' => 'rating', 'value' => (int) $in['rating'] );
			}
			if ( ! empty( $in['sample'] ) ) {
				$mq[] = array( 'key' => '_hsm_sample_review', 'value' => '1' );
			}
			$mq && $q['meta_query'] = $mq;
			$out = array();
			foreach ( get_comments( $q ) as $c ) {
				$r            = hsm_review_out( $c );
				$replies      = get_comments( array( 'parent' => $c->comment_ID, 'type' => 'review', 'count' => true, 'status' => 'all' ) );
				$r['replied'] = $replies > 0;
				if ( ! empty( $in['unanswered'] ) && $r['replied'] ) {
					continue;
				}
				$out[] = $r;
			}
			return array( 'page' => $page, 'count' => count( $out ), 'reviews' => $out );
		}

		if ( 'reply' === $action ) {
			$c = hsm_review_get( $in['review_id'] ?? 0 );
			$reply_content = hsm_review_text( $in['content'] ?? '', 3000 );
			hsm_confirm( $in, 'Reply publicly to product review #' . (int) $c->comment_ID, array( 'review_id' => (int) $c->comment_ID, 'product_id' => (int) $c->comment_post_ID, 'reply' => $reply_content ) );
			$user = wp_get_current_user();
			$cid  = wp_insert_comment( array(
				'comment_post_ID'  => $c->comment_post_ID,
				'comment_parent'   => $c->comment_ID,
				'comment_content'  => $reply_content,
				'comment_type'     => 'review',
				'comment_approved' => 1,
				'user_id'          => $user->ID,
				'comment_author'   => get_bloginfo( 'name' ),
				'comment_author_email' => $user->user_email,
			) );
			if ( ! $cid ) {
				throw new Exception( 'Could not save the reply.' );
			}
			hsm_undo_add( array( 'type' => 'comment', 'id' => $cid, 'created' => true ) );
			return array( 'reply_id' => $cid, 'review' => hsm_review_out( $c ) );
		}

		if ( 'moderate' === $action ) {
			$c   = hsm_review_get( $in['review_id'] ?? 0 );
			$new = (string) ( $in['status'] ?? '' );
			if ( ! in_array( $new, array( 'approve', 'hold', 'spam', 'trash' ), true ) ) {
				throw new Exception( 'status must be approve, hold, spam or trash.' );
			}
			$old = wp_get_comment_status( $c );
			hsm_confirm( $in, 'Change review #' . (int) $c->comment_ID . ' status from ' . $old . ' to ' . $new, array( 'review_id' => (int) $c->comment_ID, 'from' => $old, 'to' => $new ) );
			hsm_undo_add( array( 'type' => 'comment', 'id' => (int) $c->comment_ID, 'old_status' => $old ) );
			wp_set_comment_status( $c->comment_ID, $new );
			WC_Comments::clear_transients( $c->comment_post_ID );
			return array( 'review_id' => (int) $c->comment_ID, 'from' => $old, 'to' => wp_get_comment_status( $c->comment_ID ) );
		}

		if ( 'import' === $action ) {
			$pid    = hsm_review_product( $in );
			$source = trim( (string) ( $in['source'] ?? '' ) );
			if ( '' === $source ) {
				throw new Exception( 'source is required: say where this review really came from (e.g. "Trustpilot", "in-store feedback"). An unlabelled import would be a fake review.' );
			}
			$rating = (int) ( $in['rating'] ?? 0 );
			if ( $rating < 1 || $rating > 5 ) {
				throw new Exception( 'rating must be 1-5.' );
			}
			$data = array(
				'comment_post_ID'      => $pid,
				'comment_content'      => hsm_review_text( $in['content'] ?? '', 5000 ),
				'comment_type'         => 'review',
				'comment_approved'     => 1,
				'comment_author'       => mb_substr( sanitize_text_field( $in['author'] ?? '' ), 0, 60 ) ?: 'Imported reviewer',
				'comment_author_email' => 'imported@example.invalid',
				'comment_meta'         => array( 'rating' => $rating, '_hsm_review_source' => sanitize_text_field( $source ), 'verified' => 0 ),
			);
			if ( ! empty( $in['date'] ) ) {
				$when = date_create( (string) $in['date'], wp_timezone() );
				if ( $when ) {
					$data['comment_date']     = $when->format( 'Y-m-d H:i:s' );
					$data['comment_date_gmt'] = $when->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
				}
			}
			hsm_confirm( $in, 'Import a product review from ' . $source, array( 'product_id' => $pid, 'source' => $source, 'rating' => $rating, 'content' => $data['comment_content'], 'author' => $data['comment_author'] ) );
			$cid = wp_insert_comment( $data );
			if ( ! $cid ) {
				throw new Exception( 'Could not save the imported review.' );
			}
			WC_Comments::clear_transients( $pid );
			hsm_undo_add( array( 'type' => 'comment', 'id' => $cid, 'created' => true ) );
			return array( 'review_id' => $cid, 'source' => $source, 'note' => 'Shown on the storefront labelled "via ' . $source . '".' );
		}

		if ( 'get_settings' === $action ) {
			return array(
				'reviews_enabled' => 'yes' === get_option( 'woocommerce_enable_reviews' ),
				'ratings_enabled' => 'yes' === get_option( 'woocommerce_enable_review_rating' ),
			);
		}

		if ( 'set_settings' === $action ) {
			$s = (array) ( $in['settings'] ?? array() );
			if ( ! $s ) {
				throw new Exception( 'settings must be a non-empty object.' );
			}
			hsm_confirm( $in, 'Change product review settings', array( 'settings' => $s ) );
			hsm_undo_add( array( 'type' => 'option', 'name' => 'woocommerce_enable_reviews', 'old' => get_option( 'woocommerce_enable_reviews' ) ) );
			if ( array_key_exists( 'reviews_enabled', $s ) ) {
				update_option( 'woocommerce_enable_reviews', $s['reviews_enabled'] ? 'yes' : 'no' );
			}
			if ( array_key_exists( 'ratings_enabled', $s ) ) {
				update_option( 'woocommerce_enable_review_rating', $s['ratings_enabled'] ? 'yes' : 'no' );
			}
			return array(
				'action'          => 'set_settings',
				'reviews_enabled' => 'yes' === get_option( 'woocommerce_enable_reviews' ),
				'ratings_enabled' => 'yes' === get_option( 'woocommerce_enable_review_rating' ),
			);
		}

		if ( 'add_sample' === $action ) {
			$pid    = hsm_review_product( $in );
			$rating = (int) ( $in['rating'] ?? 0 );
			if ( $rating < 1 || $rating > 5 ) {
				throw new Exception( 'rating must be 1-5.' );
			}
			$n = (int) get_comments( array( 'type' => 'review', 'meta_key' => '_hsm_sample_review', 'meta_value' => '1', 'count' => true, 'status' => 'all' ) );
			if ( $n >= 200 ) {
				throw new HSM_Blocked( 'At most 200 sample reviews. Purge some first.' );
			}
			hsm_confirm( $in, 'Add a clearly labelled sample review', array( 'product_id' => $pid, 'rating' => $rating, 'content' => hsm_review_text( $in['content'] ?? '', 2000 ), 'author' => $in['author'] ?? 'Sample Customer' ) );
			$cid = wp_insert_comment( array(
				'comment_post_ID'  => $pid,
				'comment_content'  => hsm_review_text( $in['content'] ?? '', 2000 ),
				'comment_type'     => 'review',
				'comment_approved' => 1,
				'comment_author'   => mb_substr( sanitize_text_field( $in['author'] ?? 'Sample Customer' ), 0, 60 ) ?: 'Sample Customer',
				'comment_author_email' => 'sample@example.invalid',
				'comment_meta'     => array( 'rating' => $rating, '_hsm_sample_review' => 1, 'verified' => 0 ),
			) );
			if ( ! $cid ) {
				throw new Exception( 'Could not save the sample review.' );
			}
			WC_Comments::clear_transients( $pid );
			hsm_undo_add( array( 'type' => 'comment', 'id' => $cid, 'created' => true ) );
			return array( 'review_id' => $cid, 'sample' => true, 'note' => 'Flagged as SAMPLE. Remove all samples with action=purge_samples before launch.' );
		}

		if ( 'purge_samples' === $action ) {
			$ids = get_comments( array( 'type' => 'review', 'meta_key' => '_hsm_sample_review', 'meta_value' => '1', 'fields' => 'ids', 'status' => 'all', 'number' => 0 ) );
			hsm_confirm( $in, sprintf( 'Permanently delete %d sample reviews.', count( $ids ) ), array( 'count' => count( $ids ), 'review_ids' => array_map( 'intval', $ids ) ) );
			$posts = array();
			foreach ( $ids as $id ) {
				$c = get_comment( $id );
				$c && $posts[ $c->comment_post_ID ] = 1;
				wp_delete_comment( $id, true );
			}
			foreach ( array_keys( $posts ) as $p ) {
				WC_Comments::clear_transients( $p );
			}
			return array( 'deleted' => count( $ids ) );
		}

		throw new Exception( 'Unknown action.' );
	},
) );

/** Storefront: label sample reviews so nobody mistakes a preview for a real customer review. */
add_filter( 'comment_text', function ( $text, $c = null ) {
	if ( $c && ! is_admin() && get_comment_meta( $c->comment_ID, '_hsm_sample_review', true ) ) {
		$text = '<p class="hsm-sample-review" style="display:inline-block;padding:2px 8px;border-radius:4px;background:#fff3cd;color:#664d03;font-size:12px;margin:0 0 6px">Sample review — store preview only</p>' . $text;
	}
	return $text;
}, 10, 2 );

/** Structured data: never present sample ratings to search engines as real reviews. */
add_filter( 'woocommerce_structured_data_product', function ( $markup, $product ) {
	if ( $product && get_comments( array( 'post_id' => $product->get_id(), 'type' => 'review', 'meta_key' => '_hsm_sample_review', 'meta_value' => '1', 'count' => true ) ) ) {
		unset( $markup['aggregateRating'], $markup['review'] );
	}
	return $markup;
}, 99, 2 );
add_filter( 'woocommerce_structured_data_review', function ( $markup, $comment ) {
	return ( $comment && get_comment_meta( $comment->comment_ID, '_hsm_sample_review', true ) ) ? array() : $markup;
}, 99, 2 );

/** Storefront: an imported review always shows where it really came from. */
add_filter( 'comment_text', function ( $text, $comment = null ) {
	if ( ! $comment || 'review' !== $comment->comment_type ) {
		return $text;
	}
	$src = get_comment_meta( $comment->comment_ID, '_hsm_review_source', true );
	if ( $src ) {
		$text .= '<p class="hsm-review-source" style="font-size:13px;color:#666">via ' . esc_html( $src ) . '</p>';
	}
	return $text;
}, 10, 2 );

/** Admin: a persistent notice while sample reviews exist. */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'moderate_comments' ) ) {
		return;
	}
	$n = (int) get_comments( array( 'type' => 'review', 'meta_key' => '_hsm_sample_review', 'meta_value' => '1', 'count' => true, 'status' => 'all' ) );
	if ( $n ) {
		printf( '<div class="notice notice-warning"><p><strong>Hark Store:</strong> %d sample reviews are on this store. Delete them before launch (FTC rules ban fake reviews): run hark-store-reviews action=purge_samples.</p></div>', $n );
	}
} );
