<?php
/**
 * Image optimisation (replaces Smush/EWWW-type plugins for the basics):
 * - strip EXIF/GPS from uploaded JPEGs (privacy) while keeping orientation,
 * - cap the main image at 2000px (big_image_size_threshold),
 * - JPEG/WebP quality 82,
 * - generate WebP for JPEG/PNG uploads when the server supports it,
 * - reject images over 40 megapixels before WordPress tries to decode them.
 * Each can be switched off with the option hsm_media = array( 'webp' => 0, 'strip_exif' => 0, 'max_px' => 2000 ).
 */
defined( 'ABSPATH' ) || exit;

function hsm_media_opt( $k ) {
	$o = wp_parse_args( (array) get_option( 'hsm_media', array() ), array( 'webp' => 1, 'strip_exif' => 1, 'max_px' => 2000, 'quality' => 82, 'max_mp' => 40 ) );
	return $o[ $k ];
}

add_filter( 'big_image_size_threshold', function () {
	return max( 800, (int) hsm_media_opt( 'max_px' ) );
} );

add_filter( 'wp_editor_set_quality', function ( $q, $mime = '' ) {
	return min( 95, max( 40, (int) hsm_media_opt( 'quality' ) ) );
}, 10, 2 );

function hsm_webp_supported() {
	return function_exists( 'wp_image_editor_supports' ) && wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
}

add_filter( 'image_editor_output_format', function ( $formats ) {
	if ( hsm_media_opt( 'webp' ) && hsm_webp_supported() ) {
		$formats['image/jpeg'] = 'image/webp';
		$formats['image/png']  = 'image/webp';
	}
	return $formats;
} );

// Pixel-count guard + EXIF strip, on every upload path (admin, REST, media_handle_sideload used by MCP).
add_filter( 'wp_handle_upload_prefilter', 'hsm_media_prefilter' );
add_filter( 'wp_handle_sideload_prefilter', 'hsm_media_prefilter' );
function hsm_media_prefilter( $file ) {
	if ( empty( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
		return $file;
	}
	$info = @getimagesize( $file['tmp_name'] );
	if ( ! $info ) {
		return $file;
	}
	if ( $info[0] * $info[1] > hsm_media_opt( 'max_mp' ) * 1000000 ) {
		$file['error'] = sprintf( 'Image is %d x %d pixels; the limit is %d megapixels.', $info[0], $info[1], hsm_media_opt( 'max_mp' ) );
		return $file;
	}
	if ( IMAGETYPE_JPEG === $info[2] && hsm_media_opt( 'strip_exif' ) ) {
		hsm_strip_jpeg_exif( $file['tmp_name'] );
	}
	return $file;
}

/** Re-encode a JPEG without metadata, applying the EXIF orientation first so the picture is not rotated. */
function hsm_strip_jpeg_exif( $path ) {
	if ( ! function_exists( 'imagecreatefromjpeg' ) ) {
		return false;
	}
	$orient = 1;
	if ( function_exists( 'exif_read_data' ) ) {
		$e      = @exif_read_data( $path );
		$orient = (int) ( $e['Orientation'] ?? 1 );
	}
	$im = @imagecreatefromjpeg( $path );
	if ( ! $im ) {
		return false;
	}
	switch ( $orient ) {
		case 3: $im = imagerotate( $im, 180, 0 ); break;
		case 6: $im = imagerotate( $im, -90, 0 ); break;
		case 8: $im = imagerotate( $im, 90, 0 ); break;
	}
	$ok = imagejpeg( $im, $path, 90 );
	imagedestroy( $im );
	clearstatcache( true, $path );
	return $ok;
}
