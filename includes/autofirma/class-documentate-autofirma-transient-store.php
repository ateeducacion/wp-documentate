<?php
/**
 * AutoFirma intermediate-server storage backed by WordPress transients.
 *
 * @package Documentate
 */

use Erseco\AutoFirma\IntermediateServer\Storage\StoreInterface;

/**
 * Stores encrypted AutoFirma protocol payloads for a single signing session.
 */
final class Documentate_AutoFirma_Transient_Store implements StoreInterface {

	/**
	 * Transient key prefix.
	 */
	private const PREFIX = 'documentate_af_data_';

	/**
	 * Signing session token.
	 *
	 * @var string
	 */
	private $session;

	/**
	 * Create storage for a signing session.
	 *
	 * @param string $session Signing session token.
	 */
	public function __construct( $session ) {
		$this->session = (string) $session;
	}

	/**
	 * Store an encrypted protocol payload.
	 *
	 * @param string $identifier Protocol identifier.
	 * @param string $payload    Encrypted payload.
	 * @param int    $ttlSeconds Payload lifetime in seconds.
	 * @return void
	 */
	public function put( string $identifier, string $payload, int $ttlSeconds ): void { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Interface parameter name.
		set_transient( $this->key( $identifier ), $payload, $ttlSeconds ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Interface parameter name.
	}

	/**
	 * Retrieve and remove a protocol payload.
	 *
	 * @param string $identifier Protocol identifier.
	 * @return string|null Stored payload or null when unavailable.
	 */
	public function consume( string $identifier ): ?string {
		$key = $this->key( $identifier );
		$payload = get_transient( $key );

		if ( false === $payload || ! delete_transient( $key ) ) {
			return null;
		}

		return (string) $payload;
	}

	/**
	 * Purge expired payloads.
	 *
	 * WordPress already expires and removes transients.
	 *
	 * @return int Number of removed payloads.
	 */
	public function purgeExpired(): int {
		return 0;
	}

	/**
	 * Build a bounded transient key tied to the signing session.
	 *
	 * @param string $identifier Protocol identifier.
	 * @return string Transient key.
	 */
	private function key( $identifier ) {
		return self::PREFIX . substr(
			hash( 'sha256', $this->session . '|' . $identifier ),
			0,
			40
		);
	}
}
