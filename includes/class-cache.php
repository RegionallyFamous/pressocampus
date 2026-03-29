<?php
/**
 * Thin wrapper around WordPress object cache + rate limiting.
 *
 * @package Pressocampus
 * @license GPL-2.0-or-later
 */

namespace Pressocampus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cache {

	private string $group = 'pressocampus';

	/** Read-per-minute ceiling */
	private const RATE_READ = 60;

	/** Write-per-minute ceiling */
	private const RATE_WRITE = 30;

	public function get( string $key ): mixed {
		return wp_cache_get( $key, $this->group );
	}

	public function set( string $key, mixed $value, int $expire = 300 ): bool {
		return wp_cache_set( $key, $value, $this->group, $expire );
	}

	public function delete( string $key ): bool {
		return wp_cache_delete( $key, $this->group );
	}

	// Atomic increment — persistent cache preferred, transient fallback

	/**
	 * Atomically increment a counter and return the new value.
	 *
	 * Uses wp_cache_incr when a persistent object cache is available so the
	 * counter survives across PHP processes.  Falls back to transients on
	 * plain file/APCu-less installs where wp_cache_incr is not reliable.
	 */
	public function increment( string $key, int $expire = 60 ): int {
		if ( wp_using_ext_object_cache() ) {
			// Ensure the key exists before incrementing.
			if ( false === wp_cache_get( $key, $this->group ) ) {
				wp_cache_set( $key, 0, $this->group, $expire );
			}
			$new = wp_cache_incr( $key, 1, $this->group );
			// wp_cache_incr returns false on failure.
			if ( $new !== false ) {
				return (int) $new;
			}
		}

		// File-based counters avoid hammering wp_options on every MCP request when
		// no persistent object cache is installed (transients are stored as options).
		$file_inc = $this->increment_file( $key, $expire );
		if ( $file_inc !== null ) {
			return $file_inc;
		}

		// Last resort: transient fallback.
		$transient_key = 'pc_rl_' . $key;
		$current       = (int) get_transient( $transient_key );
		$new           = $current + 1;
		set_transient( $transient_key, $new, $expire );
		return $new;
	}

	/**
	 * Atomic-ish increment using a file in wp-content (avoids options table writes).
	 *
	 * @return int|null New value, or null if the file store is unavailable.
	 */
	private function increment_file( string $key, int $expire ): ?int {
		if ( ! wp_is_writable( WP_CONTENT_DIR ) ) {
			return null;
		}

		$dir = rtrim( WP_CONTENT_DIR, '/\\' ) . '/cache/pressocampus-rate';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		$ht = $dir . '/.htaccess';
		if ( ! file_exists( $ht ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $ht, "# Pressocampus rate limit cache — deny HTTP access\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}

		$path = $dir . '/' . md5( $key ) . '.json';
		$fp   = fopen( $path, 'c+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! is_resource( $fp ) ) {
			return null;
		}

		if ( ! flock( $fp, LOCK_EX ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- raw handle close after failed lock
			fclose( $fp );
			return null;
		}

		$raw  = stream_get_contents( $fp );
		$data = json_decode( is_string( $raw ) && $raw !== '' ? $raw : '{}', true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$bucket = (int) floor( time() / 60 );
		$stored = isset( $data['bucket'] ) ? (int) $data['bucket'] : 0;
		$count  = isset( $data['count'] ) ? (int) $data['count'] : 0;

		if ( $stored !== $bucket ) {
			$count = 0;
		}

		++$count;
		$payload = wp_json_encode(
			array(
				'bucket' => $bucket,
				'count'  => $count,
				'exp'    => time() + $expire + 60,
			)
		);

		ftruncate( $fp, 0 );
		rewind( $fp );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $fp, $payload !== false ? $payload : '{}' );
		fflush( $fp );
		flock( $fp, LOCK_UN );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- raw handle close after successful write
		fclose( $fp );

		return $count;
	}

	/**
	 * Check whether the token is under its rate limit for the given operation type.
	 *
	 * Returns true if the request is allowed, false if the limit is exceeded.
	 *
	 * @param string $type     'read' or 'write'
	 * @param string $token_id Hashed or raw token identifier.
	 */
	public function check_rate_limit( string $type, string $token_id ): bool {
		// Allow the stored settings to override the compiled-in defaults so the
		// Settings UI actually has an effect.  Reading an option on every MCP
		// call is fine — WordPress caches options in memory after the first load.
		$settings = get_option( 'pressocampus_settings', array() );
		$limit    = match ( $type ) {
			'read'  => (int) ( $settings['rate_limit_reads'] ?? self::RATE_READ ),
			'write' => (int) ( $settings['rate_limit_writes'] ?? self::RATE_WRITE ),
			default => self::RATE_READ,
		};

		// Key is per-token, per-type, bucketed to the current minute.
		$bucket = (int) floor( time() / 60 );
		$key    = 'rate_' . $type . '_' . md5( $token_id ) . '_' . $bucket;
		$count  = $this->increment( $key, 65 ); // slightly longer than a minute

		return $count <= $limit;
	}

	public function flush_group(): void {
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( $this->group );
		}
	}
}
