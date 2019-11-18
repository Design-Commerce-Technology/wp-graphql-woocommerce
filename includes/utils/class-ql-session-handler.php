<?php
/**
 * Handles data for the current customers session.
 *
 * @package WPGraphQL\WooCommerce\Utils
 * @since 0.1.2
 */

namespace WPGraphQL\WooCommerce\Utils;

use Firebase\JWT\JWT;

/**
 * Class - QL_Session_Handler
 */
class QL_Session_Handler extends \WC_Session_Handler {
	/**
	 * Stores the name of the HTTP header used to pass the session token.
	 *
	 * @var string
	 */
	protected $_token;

	/**
	 * Stores Timestamp of when the session token was issued.
	 *
	 * @var string
	 */
	protected $_session_issued;

	/**
	 * True when the token exists.
	 *
	 * @var bool
	 */
	protected $_has_token = false;

	/**
	 * Stores JWT token to be sent through session header.
	 *
	 * @var string
	 */
	private $_token_to_be_sent = false;

	/**
	 * Constructor for the session class.
	 */
	public function __construct() {
		$this->_token = apply_filters( 'graphql_woo_cart_session_http_header', 'woocommerce-session' );
		$this->_table = $GLOBALS['wpdb']->prefix . 'woocommerce_sessions';
	}

	/**
	 * Returns formatted $_SERVER index from provided string.
	 *
	 * @param string $header String to be formatted.
	 *
	 * @return string
	 */
	private function get_server_key( $header = null ) {
		return ! empty( $header )
			? 'HTTP_' . strtoupper( preg_replace( '#[^A-z0-9]#', '_', $header ) )
			: 'HTTP_' . strtoupper( preg_replace( '#[^A-z0-9]#', '_', $this->_token ) );
	}

	/**
	 * This returns the secret key, using the defined constant if defined, and passing it through a filter to
	 * allow for the config to be able to be set via another method other than a defined constant, such as an
	 * admin UI that allows the key to be updated/changed/revoked at any time without touching server files
	 *
	 * @return mixed|null|string
	 */
	private function get_secret_key() {
		// Use the defined secret key, if it exists.
		$secret_key = defined( 'GRAPHQL_WOOCOMMERCE_SECRET_KEY' ) && ! empty( GRAPHQL_WOOCOMMERCE_SECRET_KEY )
			? GRAPHQL_WOOCOMMERCE_SECRET_KEY :
			'graphql-jwt-auth';
		return apply_filters( 'graphql_woocommerce_secret_key', $secret_key );
	}

	/**
	 * Init hooks and session data.
	 *
	 * @since 3.3.0
	 */
	public function init() {
		$this->init_session_token();

		add_action( 'woocommerce_set_cart_cookies', array( $this, 'set_customer_session_token' ), 10 );
		add_filter( 'graphql_response_headers_to_send', array( $this, 'set_session_header' ), 10 );
		add_action( 'shutdown', array( $this, 'save_data' ), 20 );
		add_action( 'wp_logout', array( $this, 'destroy_session' ) );

		if ( ! is_user_logged_in() ) {
			add_filter( 'nonce_user_logged_out', array( $this, 'nonce_user_logged_out' ) );
		}
	}


	/**
	 * Setup token and customer ID.
	 *
	 * @since 3.6.0
	 */
	public function init_session_token() {
		$token = $this->get_session_token();

		if ( $token ) {
			$this->_customer_id        = $token->data->customer->id;
			$this->_session_issued     = $token->iat;
			$this->_session_expiration = $token->exp;
			$this->_session_expiring   = $token->exp - ( 60 * 60 * 1 );
			$this->_has_token          = true;
			$this->_data               = $this->get_session_data();

			// If the user logs in, update session.
			if ( is_user_logged_in() && strval( get_current_user_id() ) !== $this->_customer_id ) {
				$guest_session_id   = $this->_customer_id;
				$this->_customer_id = strval( get_current_user_id() );
				$this->_dirty       = true;
				$this->save_data( $guest_session_id );
				$this->set_customer_session_cookie( true );
			}

			// Update session if its close to expiring.
			if ( time() > $this->_session_expiring ) {
				$this->set_session_expiration();
				$this->update_session_timestamp( $this->_customer_id, $this->_session_expiration );
			}
		} else {
			$this->set_session_expiration();
			$this->_customer_id = $this->generate_customer_id();
			$this->_data        = $this->get_session_data();
		}
	}

	/**
	 * Retrieve and decrypt the session data from session, if set. Otherwise return false.
	 *
	 * Session cookies without a customer ID are invalid.
	 *
	 * @throws \Exception  Invalid token.
	 * @return bool|array
	 */
	public function get_session_token() {
		// Get the Auth header.
		$session_header = $this->get_session_header();

		if ( empty( $session_header ) ) {
			return false;
		}

		list( $token ) = sscanf( $session_header, 'Session %s' );

		/**
		 * Try to decode the token
		 */
		try {
			JWT::$leeway = 60;

			$secret = $this->get_secret_key();
			$token  = ! empty( $token ) ? JWT::decode( $token, $secret, [ 'HS256' ] ) : null;

			// The Token is decoded now validate the iss.
			if ( ! isset( $token->iss ) || get_bloginfo( 'url' ) !== $token->iss ) {
				throw new \Exception( __( 'The iss do not match with this server', 'wp-graphql-woocommerce' ) );
			}

			// Validate the customer id in the token.
			if ( ! isset( $token->data->customer->id ) ) {
				throw new \Exception( __( 'Customer ID not found in the token', 'wp-graphql-woocommerce' ) );
			}
		} catch ( \Exception $error ) {
			return new \WP_Error(
				'invalid_token',
				__( 'The WooCommerce Cart Session Token is invalid', 'wp-graphql-woocommerce' )
			);
		}

		return $token;
	}

	/**
	 * Get the value of the cart session header from the $_SERVER super global
	 *
	 * @return mixed|string
	 */
	public function get_session_header() {
		$session_header_key = $this->get_server_key();

		// Looking for the cart session header.
		$session_header = isset( $_SERVER[ $session_header_key ] )
			? esc_url_raw( wp_unslash( $_SERVER[ $session_header_key ] ) )
			: false;

		/**
		 * Return the cart session header, passed through a filter
		 *
		 * @param string $session_header  The header used to identify a user's cart session token.
		 */
		return apply_filters( 'graphql_woo_cart_session_header', $session_header );
	}

	/**
	 * Encrypts and sets the session header on-demand (usually after adding an item to the cart).
	 *
	 * Warning: Headers will only be set if this is called before the headers are sent.
	 *
	 * @param bool $set Should the session cookie be set.
	 */
	public function set_customer_session_token( $set ) {
		if ( $set ) {
			/**
			 * Determine the "not before" value for use in the token
			 *
			 * @param string  $issued        The timestamp of token was issued.
			 * @param integer $customer_id   Customer ID.
			 * @param array   $session_data  Cart session data.
			 */
			$not_before = apply_filters(
				'graphql_woo_cart_session_not_before',
				$this->session_issued,
				$this->customer_id,
				$this->_data
			);

			// Configure the token array, which will be encoded.
			$token = array(
				'iss'  => get_bloginfo( 'url' ),
				'iat'  => $this->session_issued,
				'nbf'  => $not_before,
				'exp'  => $this->session_expiration,
				'data' => array(
					'customer_id' => $this->customer_id,
				),
			);

			/**
			 * Filter the token, allowing for individual systems to configure the token as needed
			 *
			 * @param array   $token         The token array that will be encoded
			 * @param integer $customer_id   ID of customer associated with token.
			 * @param array   $session_data  Session data associated with token.
			 */
			$token = apply_filters(
				'graphql_woo_cart_session_before_token_sign',
				$token,
				$this->customer_id,
				$this->_data
			);

			/**
			 * Encode the token
			 */
			JWT::$leeway = 60;
			$token       = JWT::encode( $token, $this->get_secret_key() );

			/**
			 * Filter the token before returning it, allowing for individual systems to override what's returned.
			 *
			 * For example, if the user should not be granted a token for whatever reason, a filter could have the token return null.
			 *
			 * @param string  $token         The signed JWT token that will be returned
			 * @param integer $customer_id   ID of customer associated with token.
			 * @param array   $session_data  Session data associated with token.
			 */
			$token = apply_filters(
				'graphql_woo_cart_session_signed_token',
				$token,
				$this->customer_id,
				$this->_data
			);

			if ( ! $token ) {
				return;
			}

			$this->_token_to_be_sent = $token;
		}
	}

	/**
	 * Checks if there is a new token to be sent, sets the header and deletes the token.
	 *
	 * @param array $headers  The HTTP response headers for the current GraphQL request.
	 *
	 * @return array
	 */
	public function set_session_header( $headers ) {
		if ( ! empty( $this->_token_to_be_sent ) ) {
			$headers[ $this->_token ] = $this->_token_to_be_sent;
			unset( $this->_token_to_be_sent );
		}

		return $headers;
	}

	/**
	 * Return true if the current user has an active session, i.e. a cookie to retrieve values.
	 *
	 * @return bool
	 */
	public function has_session() {
		// @codingStandardsIgnoreLine.
		return isset( $_SERVER[ $this->get_server_key() ] ) || $this->_has_token || is_user_logged_in();
	}

	/**
	 * Set session expiration.
	 */
	public function set_session_expiration() {
		$this->_session_issued = time();
		// 47 Hours.
		$this->_session_expiring = apply_filters(
			'graphql_woo_cart_session_expire',
			time() + ( 60 * 60 * 47 )
		);
		// 48 Hours.
		$this->_session_expiration = apply_filters(
			'graphql_woo_cart_session_expire',
			time() + ( 60 * 60 * 48 )
		);
	}

	/**
	 * Forget all session data without destroying it.
	 */
	public function forget_session() {
		if ( isset( $this->_token_to_be_sent ) ) {
			unset( $this->_token_to_be_sent );
		}
		wc_empty_cart();
		$this->_data        = array();
		$this->_dirty       = false;
		$this->_customer_id = $this->generate_customer_id();
	}
}
