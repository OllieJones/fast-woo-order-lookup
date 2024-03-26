<?php

namespace Fast_Woo_Order_Lookup;

class Textdex {

	private $tablename;

	/** @var array Associative array, with keys named for meta_key values. */
	private $meta_keys_to_monitor;

	/** @var string Name of this plugin's option. */
	public $option_name;

	public function __construct() {
		global $wpdb;
		$this->tablename            = $wpdb->prefix . 'fast_woo_textdex';
		$this->meta_keys_to_monitor = array(
			'_billing_address_index'  => 1,
			'_shipping_address_index' => 1,
			'_billing_last_name'      => 1,
			'_billing_email'          => 1,
			'_billing_phone'          => 1
		);

		$this->option_name = FAST_WOO_ORDER_LOOKUP_SLUG . 'textdex_status';
	}

	/**
	 * Create the trigram table.
	 *
	 * @return void
	 */
	public function activate() {
		global $wpdb;
		$tablename  = $this->tablename;
		$postmeta   = $wpdb->postmeta;
		$ordersmeta = $wpdb->prefix . 'wc_orders_meta';

		$textdex_status = $this->get_option();

		if ( array_key_exists( 'new', $textdex_status ) ) {
			$table  = <<<TABLE
CREATE TABLE $tablename (
    id BIGINT NOT NULL,
	trigram CHAR(3) NOT NULL,
	PRIMARY KEY (trigram, id),
	INDEX id (id)
);
TABLE;
			$result = $wpdb->query( $table );
			if ( false === $result ) {
				if ( ! str_contains( $wpdb->last_error, 'already exists' ) ) {
					$wpdb->bail( 'Table creation failure ' );
				}
			}
			unset ( $textdex_status['new'] );
			$query                  = <<<QUERY
			SELECT GREATEST (
				(SELECT MAX(post_id) FROM $postmeta WHERE meta_key IN ('_billing_address_index','_shipping_address_index','_billing_last_name','_billing_email','_billing_phone')),
				(SELECT MAX(id) FROM $ordersmeta WHERE meta_key IN ('_billing_address_index','_shipping_address_index'))			       
			) id;

QUERY;
			$textdex_status['last'] = $wpdb->get_var( $query );
			$this->update_option( $textdex_status );
		}
	}
//('_billing_address_index','_shipping_address_index','_billing_last_name','_billing_email','_billing_phone')

	/**
	 * @return void
	 */
	public function load_textdex() {
		global $wpdb;

		$done = false;
		while( ! $done ) {
			$textdex_status = get_option( $this->option_name );
			if ( $textdex_status['current'] > $textdex_status['last'] ) {
				$done = true;
				$wpdb->query( "ANALYZE TABLE " . $this->tablename );
				continue;
			}
			$first = $textdex_status['current'];
			$last  = min( $first + $textdex_status['batch'], $textdex_status['last'] );

			$wpdb->query( 'BEGIN;' );
			$resultset = $this->get_order_metadata( $first, $last );

			$this->insert_trigrams( $resultset );

			unset ( $resultset );
			$wpdb->query( 'COMMIT;' );
			$textdex_status['current'] = $last + 1;
			$this->update_option( $textdex_status );
		}
	}

	/**
	 * @return bool true if the trigram index is ready to use.
	 */
	public function is_ready() {
		$textdex_status = $this->get_option();

		return $textdex_status['current'] > $textdex_status['last'];
	}

	public function is_order_meta_key( $meta_key ) {
		return array_key_exists( $meta_key, $this->meta_keys_to_monitor );
	}


	/**
	 * Get the trigrams from a string of metadata.
	 *
	 * @param string $value
	 *
	 * @return string[] The trigrams, sorted and deduplicated.
	 */
	public function trigrams( $value ) {

		$result = array();
		if ( ! is_string( $value ) ) {
			return $result;
		}
		$value = trim( $value );
		if ( mb_strlen( $value ) <= 0 ) {
			return $result;
		}
		$value = mb_ereg_replace( '/\s+/', ' ', $value );
		$value = trim( $value );
		$len   = mb_strlen( $value );
		if ( $len <= 0 ) {
			return array();
		} else if ( 1 === $len ) {
			$value .= '  ';
		} else if ( 2 === $len ) {
			$value .= ' ';
		}
		$len = mb_strlen( $value ) - 2;
		if ( $len > 0 ) {
			for ( $i = 0; $i < $len; $i ++ ) {
				$result [ mb_substr( $value, $i, 3 ) ] = 1;
			}
		}

		$result = array_keys( $result );
		natcasesort( $result );

		return $result;
	}

	/**
	 * Create the SQL statement that looks up a sequence of trigrams.
	 *
	 * Handle very short (<3) terms and longer terms correctly.
	 *
	 * @param string $value Search term.
	 *
	 * @return string SQL statement like SELECT id ...
	 */
	public function trigram_clause( $value ) {
		global $wpdb;
		$inlist = array();

		/* Short search terms */
		if ( mb_strlen( $value ) < 3 ) {
			/* Reviewer note: The secure escaping of LIKE terms in
			 * $wpdb->esc_like() and $wpdb->prepare() is handled at a
			 * higher level than the `query` filter and
			 * so is not appropriate here. Hence esc_sql().
			 */
			return 'SELECT DISTINCT id FROM ' . $wpdb->prefix . 'fast_woo_textdex WHERE trigram LIKE ' . "'" . esc_sql( $value ) . "%'";
		}
		/* Normal search terms */
		$trigrams = $this->trigrams( $value );
		if ( 1 === count( $trigrams ) ) {
			return $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'fast_woo_textdex WHERE trigram = %s', $trigrams[0] );
		}
		foreach ( $trigrams as $trigram ) {
			$inlist[] = $wpdb->prepare( '%s', $trigram );
		}
		$clause = 'SELECT id FROM ' . $wpdb->prefix . 'fast_woo_textdex WHERE trigram IN (' . implode( ',', $inlist ) . ') ';
		$clause .= $wpdb->prepare( 'GROUP BY id HAVING COUNT(*) = %d', count( $trigrams ) );

		return $clause;
	}

	/**
	 * Deactivation action. Remove textdex table and option.
	 *
	 * @return void
	 */
	public function deactivate() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE ' . $this->tablename );
		delete_option( $this->option_name );

	}

	/**
	 * Shutdown action.
	 *
	 * Update the textdex for any orders where we've detected a potential change.
	 *
	 * @param array $order_ids
	 *
	 * @return void
	 */
	public function update( array $order_ids ) {
		global $wpdb;
		$tablename = $this->tablename;

		if ( $this->is_ready() ) {
			foreach ( $order_ids as $order_id ) {
				/* Get rid of old metadata */
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $tablename . ' WHERE id = %d', $order_id ) );
				/* Retrieve and add the new metadata */
				$resultset = $this->get_order_metadata( $order_id );
				$this->insert_trigrams( $resultset );
			}
		}
		$textdex_status = $this->get_option();
		$original       = $textdex_status['last'];
		foreach ( $order_ids as $order_id ) {
			$textdex_status['last'] = max( $textdex_status['last'], $order_id );
		}
		if ( $textdex_status['last'] !== $original ) {
			$this->update_option( $textdex_status );
		}
	}

	/**
	 * Get order metadata for a sequence of order ids (post_id values)
	 *
	 * @param int $first First order ID to get
	 * @param int $last Last + 1 order ID to get. Default: Just get one.
	 *
	 * @return array|false|mixed|object|\stdClass[]|null
	 */
	private function get_order_metadata( $first, $last = null ) {
		if ( null === $last ) {
			$last = $first + 1;
		}
		global $wpdb;
		$postmeta   = $wpdb->postmeta;
		$ordersmeta = $wpdb->prefix . 'wc_orders_meta';
		$orders     = $wpdb->prefix . 'wc_orders';
		$orderitems = $wpdb->prefix . 'woocommerce_order_items';


		$query     = <<<QUERY
				SELECT id, GROUP_CONCAT(value SEPARATOR ' ') value
				FROM (
				SELECT post_id id, meta_value COLLATE utf8mb4_unicode_ci value
				  FROM $postmeta
				 WHERE meta_key IN ('_billing_address_index','_shipping_address_index','_billing_last_name','_billing_email','_billing_phone')
				   AND post_id >= %d and post_id <= %d
				UNION ALL
				SELECT id id, meta_value COLLATE utf8mb4_unicode_ci value
				  FROM $ordersmeta
				 WHERE meta_key IN ('_billing_address_index','_shipping_address_index')
				   AND id >= %d and id < %d
				UNION ALL
				SELECT order_id id, order_item_name COLLATE utf8mb4_unicode_ci value
				  FROM $orderitems
				 WHERE order_id >= %d and order_id < %d
				UNION ALL
				SELECT id, billing_email COLLATE utf8mb4_unicode_ci value
				  FROM $orders
				 WHERE id >= %d and id < %d
				) a
				GROUP BY id;
QUERY;
		$query     = $wpdb->prepare( $query, array( $first, $last, $first, $last, $first, $last, $first, $last ) );
		$resultset = $wpdb->get_results( $query );
		if ( false === $resultset ) {
			$wpdb->bail( 'Order data retrieval failure' );
		}

		return $resultset;
	}

	/**
	 * Insert a bunch of trigrams.
	 *
	 * @param $resultset
	 *
	 * @return void
	 */
	public function insert_trigrams( $resultset ) {
		global $wpdb;
		$tablename = $this->tablename;

		foreach ( $resultset as $result ) {

			foreach ( $this->trigrams( $result->value ) as $trigram ) {
				$query  = $wpdb->prepare(
					"INSERT IGNORE INTO $tablename (id, trigram) VALUES (%d, %s);",
					array( $result->id, $trigram ) );
				$status = $wpdb->query( $query );

				if ( false === $status ) {
					$wpdb->bail( 'Trigram insertion failure' );
				}
			}
		}
	}

	/**
	 * @return false|mixed|null
	 */
	public function get_option() {
		return get_option( $this->option_name,
			array(
				'new'     => true,
				'current' => 0,
				'batch'   => 50,
				'last'    => - 1
			) );
	}

	/**
	 * @param $textdex_status
	 *
	 * @return void
	 */
	public function update_option( $textdex_status ) {
		update_option( $this->option_name, $textdex_status, false );
	}

}

