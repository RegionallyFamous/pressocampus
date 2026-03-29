<?php
/**
 * Resolves RSA and Defuse encryption keys from constants, files, or options.
 *
 * Precedence (highest first): wp-config constants → explicit file paths →
 * PRESSOCAMPUS_KEY_DIR standard filenames → default WP_CONTENT_DIR/pressocampus-keys/ files → wp_options.
 *
 * @package Pressocampus
 * @license GPL-2.0-or-later
 */

namespace Pressocampus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KeyStore {

	public const DEFAULT_KEY_SUBDIR = 'pressocampus-keys';

	private const RSA_PRIVATE_NAMES = array( 'rsa_private.pem', 'private.pem' );

	private const RSA_PUBLIC_NAMES = array( 'rsa_public.pem', 'public.pem' );

	private const ENC_KEY_NAMES = array( 'encryption.key', 'defuse.key' );

	/**
	 * Which layer supplied each key (for admin diagnostics).
	 *
	 * @var array<string, string>
	 */
	private static array $source_labels = array();

	/**
	 * Default directory for generated PEM files when no PRESSOCAMPUS_KEY_DIR is set.
	 *
	 * @return string Absolute path without trailing slash.
	 */
	private static function resolved_key_dir(): string {
		if ( defined( 'PRESSOCAMPUS_KEY_DIR' ) && is_string( PRESSOCAMPUS_KEY_DIR ) && PRESSOCAMPUS_KEY_DIR !== '' ) {
			return rtrim( PRESSOCAMPUS_KEY_DIR, '/\\' );
		}
		return rtrim( WP_CONTENT_DIR, '/\\' ) . '/' . self::DEFAULT_KEY_SUBDIR;
	}

	/**
	 * Record diagnostic source label.
	 *
	 * @param string $kind Logical key id for diagnostics.
	 */
	private static function set_source( string $kind, string $label ): void {
		self::$source_labels[ $kind ] = $label;
	}

	/**
	 * Human-readable source for RSA private key (e.g. constant, file path, option).
	 */
	public static function get_rsa_private_source_label(): string {
		return self::$source_labels['rsa_private'] ?? 'unknown';
	}

	/**
	 * Human-readable source for RSA public key.
	 */
	public static function get_rsa_public_source_label(): string {
		return self::$source_labels['rsa_public'] ?? 'unknown';
	}

	/**
	 * Human-readable source for Defuse encryption key.
	 */
	public static function get_encryption_source_label(): string {
		return self::$source_labels['encryption'] ?? 'unknown';
	}

	private static function read_file_if_readable( string $path ): string {
		if ( $path === '' || ! is_readable( $path ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents( $path );
		return is_string( $raw ) ? trim( $raw ) : '';
	}

	/**
	 * Try standard filenames inside a directory.
	 *
	 * @param string             $dir   Absolute directory path.
	 * @param array<int, string> $names Candidate filenames.
	 */
	private static function first_readable_in_dir( string $dir, array $names ): string {
		$dir = rtrim( $dir, '/\\' );
		foreach ( $names as $name ) {
			$path = $dir . '/' . $name;
			$pem  = self::read_file_if_readable( $path );
			if ( $pem !== '' ) {
				return $pem;
			}
		}
		return '';
	}

	/**
	 * RSA private key PEM, or empty string.
	 */
	public static function get_rsa_private_pem(): string {
		if ( defined( 'PRESSOCAMPUS_RSA_PRIVATE_KEY' ) && is_string( PRESSOCAMPUS_RSA_PRIVATE_KEY ) && PRESSOCAMPUS_RSA_PRIVATE_KEY !== '' ) {
			self::set_source( 'rsa_private', 'wp-config constant PRESSOCAMPUS_RSA_PRIVATE_KEY' );
			return trim( PRESSOCAMPUS_RSA_PRIVATE_KEY );
		}

		if ( defined( 'PRESSOCAMPUS_RSA_PRIVATE_KEY_FILE' ) && is_string( PRESSOCAMPUS_RSA_PRIVATE_KEY_FILE ) && PRESSOCAMPUS_RSA_PRIVATE_KEY_FILE !== '' ) {
			$pem = self::read_file_if_readable( PRESSOCAMPUS_RSA_PRIVATE_KEY_FILE );
			if ( $pem !== '' ) {
				self::set_source( 'rsa_private', 'file ' . PRESSOCAMPUS_RSA_PRIVATE_KEY_FILE );
				return $pem;
			}
		}

		if ( defined( 'PRESSOCAMPUS_KEY_DIR' ) && is_string( PRESSOCAMPUS_KEY_DIR ) && PRESSOCAMPUS_KEY_DIR !== '' ) {
			$pem = self::first_readable_in_dir( PRESSOCAMPUS_KEY_DIR, self::RSA_PRIVATE_NAMES );
			if ( $pem !== '' ) {
				self::set_source( 'rsa_private', 'PRESSOCAMPUS_KEY_DIR (' . self::RSA_PRIVATE_NAMES[0] . ')' );
				return $pem;
			}
		}

		$default_file = rtrim( WP_CONTENT_DIR, '/\\' ) . '/' . self::DEFAULT_KEY_SUBDIR . '/' . self::RSA_PRIVATE_NAMES[0];
		$pem          = self::read_file_if_readable( $default_file );
		if ( $pem !== '' ) {
			self::set_source( 'rsa_private', 'file ' . $default_file );
			return $pem;
		}

		$opt = (string) get_option( 'pressocampus_rsa_private_key', '' );
		if ( $opt !== '' ) {
			self::set_source( 'rsa_private', 'database option pressocampus_rsa_private_key' );
			return trim( $opt );
		}

		self::set_source( 'rsa_private', 'none' );
		return '';
	}

	/**
	 * RSA public key PEM, or empty string.
	 */
	public static function get_rsa_public_pem(): string {
		if ( defined( 'PRESSOCAMPUS_RSA_PUBLIC_KEY' ) && is_string( PRESSOCAMPUS_RSA_PUBLIC_KEY ) && PRESSOCAMPUS_RSA_PUBLIC_KEY !== '' ) {
			self::set_source( 'rsa_public', 'wp-config constant PRESSOCAMPUS_RSA_PUBLIC_KEY' );
			return trim( PRESSOCAMPUS_RSA_PUBLIC_KEY );
		}

		if ( defined( 'PRESSOCAMPUS_RSA_PUBLIC_KEY_FILE' ) && is_string( PRESSOCAMPUS_RSA_PUBLIC_KEY_FILE ) && PRESSOCAMPUS_RSA_PUBLIC_KEY_FILE !== '' ) {
			$pem = self::read_file_if_readable( PRESSOCAMPUS_RSA_PUBLIC_KEY_FILE );
			if ( $pem !== '' ) {
				self::set_source( 'rsa_public', 'file ' . PRESSOCAMPUS_RSA_PUBLIC_KEY_FILE );
				return $pem;
			}
		}

		if ( defined( 'PRESSOCAMPUS_KEY_DIR' ) && is_string( PRESSOCAMPUS_KEY_DIR ) && PRESSOCAMPUS_KEY_DIR !== '' ) {
			$pem = self::first_readable_in_dir( PRESSOCAMPUS_KEY_DIR, self::RSA_PUBLIC_NAMES );
			if ( $pem !== '' ) {
				self::set_source( 'rsa_public', 'PRESSOCAMPUS_KEY_DIR (' . self::RSA_PUBLIC_NAMES[0] . ')' );
				return $pem;
			}
		}

		$default_file = rtrim( WP_CONTENT_DIR, '/\\' ) . '/' . self::DEFAULT_KEY_SUBDIR . '/' . self::RSA_PUBLIC_NAMES[0];
		$pem          = self::read_file_if_readable( $default_file );
		if ( $pem !== '' ) {
			self::set_source( 'rsa_public', 'file ' . $default_file );
			return $pem;
		}

		$opt = (string) get_option( 'pressocampus_rsa_public_key', '' );
		if ( $opt !== '' ) {
			self::set_source( 'rsa_public', 'database option pressocampus_rsa_public_key' );
			return trim( $opt );
		}

		$priv = self::get_rsa_private_pem();
		if ( $priv !== '' && extension_loaded( 'openssl' ) ) {
			$res = openssl_pkey_get_private( $priv );
			if ( $res ) {
				$details = openssl_pkey_get_details( $res );
				if ( is_array( $details ) && isset( $details['key'] ) && is_string( $details['key'] ) ) {
					self::set_source( 'rsa_public', 'derived from RSA private key' );
					return $details['key'];
				}
			}
		}

		self::set_source( 'rsa_public', 'none' );
		return '';
	}

	/**
	 * Defuse ASCII-safe encryption key string, or empty.
	 */
	public static function get_encryption_key_ascii(): string {
		if ( defined( 'PRESSOCAMPUS_ENCRYPTION_KEY' ) && is_string( PRESSOCAMPUS_ENCRYPTION_KEY ) && PRESSOCAMPUS_ENCRYPTION_KEY !== '' ) {
			self::set_source( 'encryption', 'wp-config constant PRESSOCAMPUS_ENCRYPTION_KEY' );
			return trim( PRESSOCAMPUS_ENCRYPTION_KEY );
		}

		if ( defined( 'PRESSOCAMPUS_ENCRYPTION_KEY_FILE' ) && is_string( PRESSOCAMPUS_ENCRYPTION_KEY_FILE ) && PRESSOCAMPUS_ENCRYPTION_KEY_FILE !== '' ) {
			$ascii = self::read_file_if_readable( PRESSOCAMPUS_ENCRYPTION_KEY_FILE );
			if ( $ascii !== '' ) {
				self::set_source( 'encryption', 'file ' . PRESSOCAMPUS_ENCRYPTION_KEY_FILE );
				return $ascii;
			}
		}

		if ( defined( 'PRESSOCAMPUS_KEY_DIR' ) && is_string( PRESSOCAMPUS_KEY_DIR ) && PRESSOCAMPUS_KEY_DIR !== '' ) {
			$ascii = self::first_readable_in_dir( PRESSOCAMPUS_KEY_DIR, self::ENC_KEY_NAMES );
			if ( $ascii !== '' ) {
				self::set_source( 'encryption', 'PRESSOCAMPUS_KEY_DIR (' . self::ENC_KEY_NAMES[0] . ')' );
				return $ascii;
			}
		}

		$default_file = rtrim( WP_CONTENT_DIR, '/\\' ) . '/' . self::DEFAULT_KEY_SUBDIR . '/' . self::ENC_KEY_NAMES[0];
		$ascii        = self::read_file_if_readable( $default_file );
		if ( $ascii !== '' ) {
			self::set_source( 'encryption', 'file ' . $default_file );
			return $ascii;
		}

		$opt = (string) get_option( 'pressocampus_encryption_key', '' );
		if ( $opt !== '' ) {
			self::set_source( 'encryption', 'database option pressocampus_encryption_key' );
			return trim( $opt );
		}

		self::set_source( 'encryption', 'none' );
		return '';
	}

	/**
	 * Ensure key directory exists and is protected from direct web access.
	 */
	private static function ensure_key_directory( string $dir ): bool {
		if ( $dir === '' ) {
			return false;
		}
		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return false;
			}
		}
		$ht = $dir . '/.htaccess';
		if ( ! file_exists( $ht ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $ht, "# Pressocampus — deny HTTP access\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
		$idx = $dir . '/index.php';
		if ( ! file_exists( $idx ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $idx, "<?php\n// Silence is golden.\n" );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- bootstrap path check before writing keys
		return is_dir( $dir ) && is_writable( $dir );
	}

	/**
	 * Write RSA key pair to file or options.
	 *
	 * @return bool True if persisted to filesystem or database.
	 */
	public static function persist_rsa_pair( string $private_pem, string $public_pem ): bool {
		$dir = self::resolved_key_dir();
		if ( self::ensure_key_directory( $dir ) ) {
			$priv_path = $dir . '/' . self::RSA_PRIVATE_NAMES[0];
			$pub_path  = $dir . '/' . self::RSA_PUBLIC_NAMES[0];
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$w1 = file_put_contents( $priv_path, $private_pem . "\n", LOCK_EX ) !== false;
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- tighten PEM permissions; ignore if host disallows chmod
			@chmod( $priv_path, 0600 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$w2 = file_put_contents( $pub_path, $public_pem . "\n", LOCK_EX ) !== false;
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod
			@chmod( $pub_path, 0644 );
			if ( $w1 && $w2 ) {
				return true;
			}
		}

		update_option( 'pressocampus_rsa_private_key', $private_pem );
		update_option( 'pressocampus_rsa_public_key', $public_pem );
		return true;
	}

	/**
	 * Persist Defuse encryption key ASCII string.
	 */
	public static function persist_encryption_key_ascii( string $ascii ): bool {
		$dir = self::resolved_key_dir();
		if ( self::ensure_key_directory( $dir ) ) {
			$path = $dir . '/' . self::ENC_KEY_NAMES[0];
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( file_put_contents( $path, $ascii, LOCK_EX ) !== false ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod
				@chmod( $path, 0600 );
				return true;
			}
		}

		update_option( 'pressocampus_encryption_key', $ascii, false );
		return true;
	}
}
