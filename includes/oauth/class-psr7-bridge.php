<?php
/**
 * Minimal PSR-7 implementation bridging WordPress globals to league/oauth2-server.
 *
 * League/oauth2-server requires PSR-7 objects but no concrete PSR-7 package
 * ships as a production dependency of this plugin. This bridge provides the
 * exact surface area the library exercises and nothing more.
 *
 * @package Pressocampus\OAuth
 * @license GPL-2.0-or-later
 */

namespace Pressocampus\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

// Stream

// phpcs:ignore Squiz.Commenting.ClassComment.WrongStyle -- section divider used intentionally; class is part of a grouped bridge file
class WPStream implements StreamInterface {

	private int $position = 0;

	public function __construct( private string $data = '' ) {}

	public function __toString(): string {
		return $this->data;
	}

	public function close(): void {}

	public function detach(): mixed {
		return null;
	}

	public function getSize(): ?int {
		return strlen( $this->data );
	}

	public function tell(): int {
		return $this->position;
	}

	public function eof(): bool {
		return $this->position >= strlen( $this->data );
	}

	public function isSeekable(): bool {
		return true;
	}

	public function seek( int $offset, int $whence = SEEK_SET ): void {
		$this->position = match ( $whence ) {
			SEEK_SET => $offset,
			SEEK_CUR => $this->position + $offset,
			SEEK_END => strlen( $this->data ) + $offset,
			default  => $this->position,
		};
	}

	public function rewind(): void {
		$this->position = 0;
	}

	public function isWritable(): bool {
		return true;
	}

	public function write( string $string ): int { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.stringFound -- PSR-7 MessageInterface parameter name
		$this->data .= $string;
		return strlen( $string );
	}

	public function isReadable(): bool {
		return true;
	}

	public function read( int $length ): string {
		$chunk           = substr( $this->data, $this->position, $length );
		$this->position += strlen( $chunk );
		return $chunk;
	}

	public function getContents(): string {
		return substr( $this->data, $this->position );
	}

	public function getMetadata( ?string $key = null ): mixed {
		return $key === null ? array() : null;
	}
}

// URI

// phpcs:ignore Squiz.Commenting.ClassComment.WrongStyle -- section divider used intentionally
class WPUri implements UriInterface {

	public function __construct( private string $uri = '' ) {}

	public function getScheme(): string {
		return (string) ( parse_url( $this->uri, PHP_URL_SCHEME ) ?? '' );
	}

	public function getAuthority(): string {
		$host = $this->getHost();
		$port = $this->getPort();
		$user = $this->getUserInfo();
		return ( $user !== '' ? $user . '@' : '' ) . $host . ( $port !== null ? ':' . $port : '' );
	}

	public function getUserInfo(): string {
		$user = (string) ( parse_url( $this->uri, PHP_URL_USER ) ?? '' );
		$pass = parse_url( $this->uri, PHP_URL_PASS );
		return $user . ( $pass !== null ? ':' . $pass : '' );
	}

	public function getHost(): string {
		return strtolower( (string) ( parse_url( $this->uri, PHP_URL_HOST ) ?? '' ) );
	}

	public function getPort(): ?int {
		$port = parse_url( $this->uri, PHP_URL_PORT );
		return is_int( $port ) ? $port : null;
	}

	public function getPath(): string {
		return (string) ( parse_url( $this->uri, PHP_URL_PATH ) ?? '' );
	}

	public function getQuery(): string {
		return (string) ( parse_url( $this->uri, PHP_URL_QUERY ) ?? '' );
	}

	public function getFragment(): string {
		return (string) ( parse_url( $this->uri, PHP_URL_FRAGMENT ) ?? '' );
	}

	public function withScheme( string $scheme ): static {
		return clone $this;
	}

	public function withUserInfo( string $user, ?string $password = null ): static {
		return clone $this;
	}

	public function withHost( string $host ): static {
		return clone $this;
	}

	public function withPort( ?int $port ): static {
		return clone $this;
	}

	public function withPath( string $path ): static {
		return clone $this;
	}

	public function withQuery( string $query ): static {
		$clone      = clone $this;
		$base       = preg_replace( '/\?.*$/', '', $clone->uri );
		$clone->uri = $base . ( $query !== '' ? '?' . $query : '' );
		return $clone;
	}

	public function withFragment( string $fragment ): static {
		return clone $this;
	}

	public function __toString(): string {
		return $this->uri;
	}
}

// Response

// phpcs:ignore Squiz.Commenting.ClassComment.WrongStyle -- section divider used intentionally
class WPResponse implements ResponseInterface {

	private int $status_code      = 200;
	private string $reason_phrase = 'OK';
	private array $headers        = array();
	private StreamInterface $body;
	private string $protocol = '1.1';

	public function __construct() {
		$this->body = new WPStream();
	}

	public function getProtocolVersion(): string {
		return $this->protocol;
	}

	public function withProtocolVersion( string $version ): static {
		$clone           = clone $this;
		$clone->protocol = $version;
		return $clone;
	}

	public function getHeaders(): array {
		return $this->headers;
	}

	public function hasHeader( string $name ): bool {
		return isset( $this->headers[ strtolower( $name ) ] );
	}

	public function getHeader( string $name ): array {
		return $this->headers[ strtolower( $name ) ] ?? array();
	}

	public function getHeaderLine( string $name ): string {
		return implode( ', ', $this->getHeader( $name ) );
	}

	public function withHeader( string $name, $value ): static {
		$clone                                 = clone $this;
		$clone->headers[ strtolower( $name ) ] = is_array( $value ) ? $value : array( $value );
		return $clone;
	}

	public function withAddedHeader( string $name, $value ): static {
		$clone                  = clone $this;
		$key                    = strtolower( $name );
		$clone->headers[ $key ] = array_merge(
			$clone->headers[ $key ] ?? array(),
			is_array( $value ) ? $value : array( $value )
		);
		return $clone;
	}

	public function withoutHeader( string $name ): static {
		$clone = clone $this;
		unset( $clone->headers[ strtolower( $name ) ] );
		return $clone;
	}

	public function getBody(): StreamInterface {
		return $this->body;
	}

	public function withBody( StreamInterface $body ): static {
		$clone       = clone $this;
		$clone->body = $body;
		return $clone;
	}

	public function getStatusCode(): int {
		return $this->status_code;
	}

	public function withStatus( int $code, string $reasonPhrase = '' ): static {
		$clone                = clone $this;
		$clone->status_code   = $code;
		$clone->reason_phrase = $reasonPhrase;
		return $clone;
	}

	public function getReasonPhrase(): string {
		return $this->reason_phrase;
	}
}

// Server Request

/** Minimal PSR-7 ServerRequestInterface implementation wrapping WordPress globals. */
class WPServerRequest implements ServerRequestInterface {

	private array $attributes = array();

	public function __construct(
		private string $method,
		private string $uri,
		private array $headers,
		private array $server_params,
		private array $query_params,
		private array|object|null $parsed_body,
		private string $body_content = '',
	) {}

	/**
	 * Build a request from WordPress superglobals — used for the token endpoint
	 * where the raw request arrives before WP has parsed it into a WP_REST_Request.
	 */
	public static function from_globals(): self {
		$method = strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
		$uri    = $scheme . '://' . $host . ( $_SERVER['REQUEST_URI'] ?? '/' );

		$headers = array();
		foreach ( $_SERVER as $key => $value ) {
			if ( str_starts_with( $key, 'HTTP_' ) ) {
				$normalized             = strtolower( str_replace( '_', '-', substr( $key, 5 ) ) );
				$headers[ $normalized ] = array( $value );
			}
		}
		if ( isset( $_SERVER['CONTENT_TYPE'] ) ) {
			$headers['content-type'] = array( $_SERVER['CONTENT_TYPE'] );
		}
		if ( isset( $_SERVER['CONTENT_LENGTH'] ) ) {
			$headers['content-length'] = array( $_SERVER['CONTENT_LENGTH'] );
		}

		$raw  = (string) file_get_contents( 'php://input' );
		$body = $_POST;

		$content_type = $_SERVER['CONTENT_TYPE'] ?? '';
		if ( str_contains( $content_type, 'application/json' ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$body = $decoded;
			}
		}

		return new self( $method, $uri, $headers, $_SERVER, $_GET, $body ?: null, $raw );
	}

	/**
	 * Build from a WP_REST_Request — used for the authorize and token handlers
	 * that receive a structured request object from the REST router.
	 */
	public static function from_wp_request( \WP_REST_Request $request ): self {
		$method = $request->get_method();
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
		$uri    = $scheme . '://' . $host . ( $_SERVER['REQUEST_URI'] ?? '/' );

		// WP stores header keys as lowercase with underscores; normalise to hyphens.
		$headers = array();
		foreach ( $request->get_headers() as $name => $values ) {
			$normalized             = strtolower( str_replace( '_', '-', $name ) );
			$headers[ $normalized ] = (array) $values;
		}

		$body = $request->get_body_params();
		if ( empty( $body ) ) {
			$json = $request->get_json_params();
			if ( ! empty( $json ) ) {
				$body = $json;
			}
		}

		return new self(
			$method,
			$uri,
			$headers,
			$_SERVER,
			$request->get_query_params(),
			$body ?: null,
			$request->get_body() ?? '',
		);
	}

	/**
	 * Build a minimal request carrying only an Authorization header.
	 * Used when validating a Bearer token via ResourceServer.
	 */
	public static function for_bearer_token( string $bearer_token ): self {
		return new self(
			'GET',
			home_url(),
			array( 'authorization' => array( 'Bearer ' . $bearer_token ) ),
			$_SERVER,
			array(),
			null,
			'',
		);
	}

	// --- MessageInterface -----------------------------------------------

	public function getProtocolVersion(): string {
		return '1.1';
	}

	public function withProtocolVersion( string $version ): static {
		return clone $this;
	}

	public function getHeaders(): array {
		return $this->headers;
	}

	public function hasHeader( string $name ): bool {
		return isset( $this->headers[ strtolower( $name ) ] );
	}

	public function getHeader( string $name ): array {
		return $this->headers[ strtolower( $name ) ] ?? array();
	}

	public function getHeaderLine( string $name ): string {
		return implode( ', ', $this->getHeader( $name ) );
	}

	public function withHeader( string $name, $value ): static {
		$clone                                 = clone $this;
		$clone->headers[ strtolower( $name ) ] = is_array( $value ) ? $value : array( $value );
		return $clone;
	}

	public function withAddedHeader( string $name, $value ): static {
		$clone                  = clone $this;
		$key                    = strtolower( $name );
		$clone->headers[ $key ] = array_merge(
			$clone->headers[ $key ] ?? array(),
			is_array( $value ) ? $value : array( $value )
		);
		return $clone;
	}

	public function withoutHeader( string $name ): static {
		$clone = clone $this;
		unset( $clone->headers[ strtolower( $name ) ] );
		return $clone;
	}

	public function getBody(): StreamInterface {
		return new WPStream( $this->body_content );
	}

	public function withBody( StreamInterface $body ): static {
		$clone               = clone $this;
		$clone->body_content = (string) $body;
		return $clone;
	}

	// --- RequestInterface -----------------------------------------------

	public function getRequestTarget(): string {
		$parts = parse_url( $this->uri );
		$path  = $parts['path'] ?? '/';
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		return $path . $query;
	}

	public function withRequestTarget( string $requestTarget ): static {
		return clone $this;
	}

	public function getMethod(): string {
		return $this->method;
	}

	public function withMethod( string $method ): static {
		$clone         = clone $this;
		$clone->method = strtoupper( $method );
		return $clone;
	}

	public function getUri(): UriInterface {
		return new WPUri( $this->uri );
	}

	public function withUri( UriInterface $uri, bool $preserveHost = false ): static {
		$clone      = clone $this;
		$clone->uri = (string) $uri;
		return $clone;
	}

	// --- ServerRequestInterface -----------------------------------------

	public function getServerParams(): array {
		return $this->server_params;
	}

	public function getCookieParams(): array {
		return $_COOKIE ?? array();
	}

	public function withCookieParams( array $cookies ): static {
		return clone $this;
	}

	public function getQueryParams(): array {
		return $this->query_params;
	}

	public function withQueryParams( array $query ): static {
		$clone               = clone $this;
		$clone->query_params = $query;
		return $clone;
	}

	public function getUploadedFiles(): array {
		return array();
	}

	public function withUploadedFiles( array $uploadedFiles ): static {
		return clone $this;
	}

	public function getParsedBody(): array|object|null {
		return $this->parsed_body;
	}

	public function withParsedBody( mixed $data ): static {
		$clone              = clone $this;
		$clone->parsed_body = $data;
		return $clone;
	}

	public function getAttributes(): array {
		return $this->attributes;
	}

	public function getAttribute( string $name, mixed $default = null ): mixed { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- PSR-7 MessageInterface parameter name
		return $this->attributes[ $name ] ?? $default;
	}

	public function withAttribute( string $name, mixed $value ): static {
		$clone                      = clone $this;
		$clone->attributes[ $name ] = $value;
		return $clone;
	}

	public function withoutAttribute( string $name ): static {
		$clone = clone $this;
		unset( $clone->attributes[ $name ] );
		return $clone;
	}
}
