<?php
/** Resolve a publication poster without fetching arbitrary remote files. */
defined( 'ABSPATH' ) || exit;

final class Treda_Media {
	public static function for_post( $post ) {
		$id = (int) get_post_thumbnail_id( $post->ID );
		if ( $id ) { return self::attachment( $id ); }
		$html = new WP_HTML_Tag_Processor( $post->post_content );
		if ( ! $html->next_tag( 'IMG' ) ) { return false; }
		if ( preg_match( '/\bwp-image-(\d+)\b/', (string) $html->get_attribute( 'class' ), $match ) ) { return self::attachment( (int) $match[1] ); }
		$url = $html->get_attribute( 'data-full-image' ) ?: $html->get_attribute( 'data-src' ) ?: $html->get_attribute( 'src' );
		return is_string( $url ) && $url !== '' ? self::url( $url ) : false;
	}

	private static function attachment( $id ) {
		if ( get_post_type( $id ) !== 'attachment' || ! wp_attachment_is_image( $id ) ) { return self::error(); }
		$path = get_attached_file( $id, true );
		if ( $path && is_file( $path ) ) { return self::file( $path ); }
		$url = wp_get_attachment_url( $id );
		return $url ? self::url( $url ) : self::error();
	}

	private static function url( $url ) {
		$url = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
		$uploads = wp_get_upload_dir();
		$parts = wp_parse_url( $url );
		$base = wp_parse_url( $uploads['baseurl'] );
		$path = rawurldecode( $parts['path'] ?? '' );
		$prefix = trailingslashit( rawurldecode( $base['path'] ?? '' ) );
		$host = strtolower( $parts['host'] ?? '' );
		if ( ( $host === '' || $host === strtolower( $base['host'] ?? '' ) || $host === strtolower( wp_parse_url( home_url(), PHP_URL_HOST ) ) ) && strpos( $path, $prefix ) === 0 ) {
			return self::file( trailingslashit( $uploads['basedir'] ) . substr( $path, strlen( $prefix ) ) );
		}
		// Telegram fetches public CDN images itself. The WordPress server never downloads the URL.
		$public_ip = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
		if ( preg_match( '/(^localhost$|\.local$|\.localhost$)/i', $host ) || ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, $public_ip ) ) ) { return self::error(); }
		if ( $host === strtolower( wp_parse_url( home_url(), PHP_URL_HOST ) ) && ! filter_var( gethostbyname( $host ), FILTER_VALIDATE_IP, $public_ip ) ) { return self::error(); }
		if ( ! wp_http_validate_url( $url ) ) { return self::error(); }
		return array( 'reference' => esc_url_raw( $url ), 'hash' => hash( 'sha256', $url ), 'files' => array() );
	}

	private static function file( $path ) {
		$uploads = wp_get_upload_dir();
		$root = realpath( $uploads['basedir'] );
		$real = realpath( $path );
		if ( ! $root || ! $real || ! is_readable( $real ) ) { return self::error(); }
		$normalized = wp_normalize_path( $real );
		$prefix = trailingslashit( wp_normalize_path( $root ) );
		if ( DIRECTORY_SEPARATOR === '\\' ) { $normalized = strtolower( $normalized ); $prefix = strtolower( $prefix ); }
		if ( strpos( $normalized, $prefix ) !== 0 ) { return self::error(); }
		$size = filesize( $real );
		$image = wp_getimagesize( $real );
		if ( ! $size || $size > 10 * MB_IN_BYTES || ! $image || ! $image[0] || ! $image[1] || ! in_array( $image['mime'] ?? '', array( 'image/jpeg', 'image/png' ), true ) || $image[0] + $image[1] > 10000 || max( $image[0], $image[1] ) > 20 * min( $image[0], $image[1] ) ) { return self::error(); }
		$data = file_get_contents( $real, false, null, 0, 10 * MB_IN_BYTES + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Validated local uploads file; no HTTP request.
		if ( $data === false || strlen( $data ) > 10 * MB_IN_BYTES ) { return self::error(); }
		return array( 'reference' => 'attach://photo', 'hash' => hash( 'sha256', $data ), 'files' => array( 'photo' => array( 'name' => sanitize_file_name( basename( $real ) ), 'type' => $image['mime'], 'data' => $data ) ) );
	}

	private static function error() {
		return new WP_Error( 'treda_poster', __( 'The poster could not be prepared. Use a readable JPEG or PNG in Media Library, up to 10 MB, with a width and height total up to 10000 and an aspect ratio up to 20:1.', 'threadtandem-sync-for-telegram' ), array( 'state' => 'failed' ) );
	}

	/** Telegram caption entities use UTF-16 offsets; keep emoji within the 1024-unit limit. */
	private static function units( $text ) {
		preg_match_all( '/./us', wp_check_invalid_utf8( $text ), $matches );
		return $matches[0];
	}

	private static function limit( $text, $maximum ) {
		$chars = self::units( $text ); $length = 0;
		foreach ( $chars as $char ) { $length += strlen( $char ) === 4 ? 2 : 1; }
		if ( $length <= $maximum ) { return $text; }
		$result = ''; $length = 0;
		foreach ( $chars as $char ) {
			$length += strlen( $char ) === 4 ? 2 : 1;
			if ( $length > $maximum - 1 ) { break; }
			$result .= $char;
		}
		return $result . '…';
	}

	public static function caption( $post ) {
		return Treda_Publisher::text( $post, 1024 );
	}
}
