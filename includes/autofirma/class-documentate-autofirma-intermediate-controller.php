<?php
/**
 * REST adapter for the AutoFirma intermediate-server protocol.
 *
 * @package Documentate
 */

use Erseco\AutoFirma\IntermediateServer\IntermediateServer;
use Erseco\AutoFirma\IntermediateServer\Protocol\Request as ProtocolRequest;

/**
 * Exposes temporary storage and retrieval endpoints required by AutoFirma.
 */
final class Documentate_AutoFirma_Intermediate_Controller {

	/**
	 * REST namespace.
	 */
	private const REST_NAMESPACE = 'documentate/v1';

	/**
	 * Session transient prefix.
	 */
	private const SESSION_PREFIX = 'documentate_af_session_';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_as_text' ), 10, 3 );
	}

	/**
	 * Register intermediate-server REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		if ( ! self::is_available() ) {
			return;
		}

		register_rest_route(
			self::REST_NAMESPACE,
			'/autofirma/intermediate-sessions',
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => array( $this, 'create_session' ),
				'permission_callback' => array( $this, 'can_create_session' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/autofirma/intermediate/(?P<token>[A-Za-z0-9]{32})/(?P<service>storage|retrieve)',
			array(
				'methods' => array( 'GET', 'POST' ),
				'callback' => array( $this, 'serve' ),
				'permission_callback' => '__return_true',
				'args' => array(
					'token' => array(
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Determine whether the intermediate server can be exposed.
	 *
	 * @return bool Whether all required runtime classes are available.
	 */
	public static function is_available() {
		$available = class_exists( IntermediateServer::class )
			&& class_exists( 'Documentate_AutoFirma_Transient_Store' )
			&& '' !== self::base_url();

		/**
		 * Filter whether Documentate exposes the AutoFirma intermediate server.
		 *
		 * @param bool $available Whether the service is available.
		 */
		return (bool) apply_filters( 'documentate_autofirma_enable_intermediate_server', $available );
	}

	/**
	 * Get the endpoint used to create an intermediate-server session.
	 *
	 * @return string Session endpoint URL or an empty string.
	 */
	public static function session_url() {
		if ( ! self::is_available() ) {
			return '';
		}

		return rest_url( self::REST_NAMESPACE . '/autofirma/intermediate-sessions' );
	}

	/**
	 * Check whether the current user can start a signing session.
	 *
	 * @return bool Whether the request is allowed.
	 */
	public function can_create_session() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Create a short-lived signing session.
	 *
	 * @return WP_REST_Response Session response.
	 */
	public function create_session() {
		$token = self::generate_token();

		set_transient(
			self::SESSION_PREFIX . $token,
			get_current_user_id(),
			self::session_lifetime()
		);

		$base_url = self::base_url();

		return new WP_REST_Response(
			array(
				'storageUrl' => $base_url . '/' . $token . '/storage',
				'retrieveUrl' => $base_url . '/' . $token . '/retrieve',
				'expiresIn' => self::session_lifetime(),
			),
			201
		);
	}

	/**
	 * Process an AutoFirma storage or retrieval request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response Protocol response.
	 */
	public function serve( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		$operation = strtolower( (string) $request->get_param( 'op' ) );

		if ( 'check' !== $operation && false === get_transient( self::SESSION_PREFIX . $token ) ) {
			return $this->text_response( 'ERR-06=Invalid identifier', 403 );
		}

		$server = new IntermediateServer(
			new Documentate_AutoFirma_Transient_Store( $token ),
			self::max_payload(),
			self::payload_lifetime()
		);

		$response = $server->handle(
			ProtocolRequest::fromRawHttp(
				(string) $request->get_method(),
				array_merge(
					(array) $request->get_query_params(),
					(array) $request->get_body_params()
				),
				(string) $request->get_body()
			)
		);

		return $this->text_response(
			$response->body(),
			$response->statusCode(),
			$response->headers()
		);
	}

	/**
	 * Serve protocol responses as plain text rather than JSON.
	 *
	 * @param bool             $served  Whether the response has already been served.
	 * @param WP_REST_Response $result  REST response.
	 * @param WP_REST_Request  $request REST request.
	 * @return bool Whether the response has been served.
	 */
	public function serve_as_text( $served, $result, $request ) {
		if ( $served || ! $request instanceof WP_REST_Request ) {
			return $served;
		}

		$route_prefix = '/' . self::REST_NAMESPACE . '/autofirma/intermediate/';
		if ( 0 !== strpos( (string) $request->get_route(), $route_prefix ) ) {
			return $served;
		}

		$body = $result->get_data();
		if ( ! is_string( $body ) ) {
			return $served;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The protocol requires its raw encrypted plain-text response.
		echo $body;

		return true;
	}

	/**
	 * Build a plain-text REST response.
	 *
	 * @param string $body    Response body.
	 * @param int    $status  HTTP status.
	 * @param array  $headers Additional headers.
	 * @return WP_REST_Response REST response.
	 */
	private function text_response( $body, $status = 200, array $headers = array() ) {
		$response = new WP_REST_Response( $body, $status );

		foreach ( $headers as $name => $value ) {
			$response->header( $name, $value );
		}

		$response->header( 'Content-Type', 'text/plain; charset=UTF-8' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );

		return $response;
	}

	/**
	 * Build the base URL used by AutoScript storage and retrieval calls.
	 *
	 * AutoScript appends its own query string. Plain permalinks produce a REST
	 * URL that already contains a query string and therefore cannot be used.
	 *
	 * @return string Endpoint base URL or an empty string.
	 */
	private static function base_url() {
		$base_url = rest_url( self::REST_NAMESPACE . '/autofirma/intermediate' );

		if ( false !== strpos( $base_url, '?' ) ) {
			return '';
		}

		return untrailingslashit( $base_url );
	}

	/**
	 * Generate an unpredictable session token.
	 *
	 * @return string Session token.
	 */
	private static function generate_token() {
		return substr( bin2hex( random_bytes( 20 ) ), 0, 32 );
	}

	/**
	 * Get signing-session lifetime.
	 *
	 * @return int Lifetime in seconds.
	 */
	private static function session_lifetime() {
		return (int) apply_filters( 'documentate_autofirma_intermediate_session_lifetime', 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Get encrypted-payload lifetime.
	 *
	 * @return int Lifetime in seconds.
	 */
	private static function payload_lifetime() {
		return (int) apply_filters( 'documentate_autofirma_intermediate_payload_lifetime', 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Get the maximum encrypted payload size.
	 *
	 * @return int Maximum payload size in bytes.
	 */
	private static function max_payload() {
		return (int) apply_filters( 'documentate_autofirma_intermediate_max_payload', 20 * MB_IN_BYTES );
	}
}
