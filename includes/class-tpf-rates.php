<?php
/**
 * FX quotes provider for the calculators.
 *
 * Rates are stored USD-based: rates[X] = how many units of X per 1 USD.
 * Cross rate A->B = rates[B] / rates[A].
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TPF_Rates {

	const CACHE_KEY  = 'tpf_rates_v1';
	const BACKUP_KEY = 'tpf_rates_backup_v1';

	const PRIMARY  = 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.json';
	const FALLBACK = 'https://api.frankfurter.dev/v1/latest?base=USD';

	/** @var TPF_Rates */
	private static $instance = null;

	/** @var TPF_Plugin */
	private $plugin;

	public static function instance( $plugin = null ) {
		if ( null === self::$instance ) {
			self::$instance = new self( $plugin );
		}
		return self::$instance;
	}

	private function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Currencies we keep in the cached table.
	 */
	public static function supported() {
		return array(
			'USD', 'EUR', 'GBP', 'JPY', 'CHF', 'CAD', 'AUD', 'NZD', 'SGD', 'HKD',
			'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF', 'TRY', 'ZAR', 'MXN', 'CNY',
			'INR', 'BRL', 'KRW', 'ILS', 'RON', 'THB', 'PHP', 'MYR', 'IDR',
			'XAU', 'XAG',
		);
	}

	/**
	 * @param bool $force Bypass cache.
	 * @return array{date:string,rates:array,source:string}
	 */
	public function get( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && ! empty( $cached['rates'] ) ) {
				return $cached;
			}
		}

		$data = $this->fetch_primary();
		if ( empty( $data['rates'] ) ) {
			$data = $this->fetch_fallback();
		}

		if ( empty( $data['rates'] ) ) {
			$backup = get_option( self::BACKUP_KEY, array() );
			if ( is_array( $backup ) && ! empty( $backup['rates'] ) ) {
				set_transient( self::CACHE_KEY, $backup, 15 * MINUTE_IN_SECONDS );
				return $backup;
			}
			return array(
				'date'   => '',
				'rates'  => array( 'USD' => 1.0 ),
				'source' => 'none',
			);
		}

		$minutes = $this->plugin ? (int) $this->plugin->get( 'rates_minutes' ) : 60;
		set_transient( self::CACHE_KEY, $data, max( 15, $minutes ) * MINUTE_IN_SECONDS );
		update_option( self::BACKUP_KEY, $data, false );

		return $data;
	}

	private function request( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => 'WordPress/Tools-Paxforex-' . TPF_VERSION . '; ' . home_url( '/' ),
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $json ) ? $json : null;
	}

	private function fetch_primary() {
		$json = $this->request( self::PRIMARY );
		if ( ! $json || empty( $json['usd'] ) || ! is_array( $json['usd'] ) ) {
			return array();
		}

		$rates = array( 'USD' => 1.0 );
		foreach ( self::supported() as $code ) {
			$key = strtolower( $code );
			if ( isset( $json['usd'][ $key ] ) && is_numeric( $json['usd'][ $key ] ) ) {
				$rates[ $code ] = (float) $json['usd'][ $key ];
			}
		}

		if ( count( $rates ) < 5 ) {
			return array();
		}

		return array(
			'date'   => isset( $json['date'] ) ? sanitize_text_field( $json['date'] ) : gmdate( 'Y-m-d' ),
			'rates'  => $rates,
			'source' => 'currency-api',
		);
	}

	private function fetch_fallback() {
		$json = $this->request( self::FALLBACK );
		if ( ! $json || empty( $json['rates'] ) || ! is_array( $json['rates'] ) ) {
			return array();
		}

		$rates = array( 'USD' => 1.0 );
		foreach ( self::supported() as $code ) {
			if ( isset( $json['rates'][ $code ] ) && is_numeric( $json['rates'][ $code ] ) ) {
				$rates[ $code ] = (float) $json['rates'][ $code ];
			}
		}

		return array(
			'date'   => isset( $json['date'] ) ? sanitize_text_field( $json['date'] ) : gmdate( 'Y-m-d' ),
			'rates'  => $rates,
			'source' => 'frankfurter',
		);
	}
}
