<?php /** @noinspection SqlDialectInspection */

/** @noinspection SqlNoDataSourceInspection */

namespace Fast_Woo_Order_Lookup;

class Textdex {

	private $tablename;

	/** @var array Associative array, with keys named for meta_key values. */
	private $meta_keys_to_monitor;

	/** @var string Name of this plugin's option. */
	public $option_name;
	/** @var int The maximum number of tuples per insert */
	private $trigram_batch_size = 250;
	/** @var int The number of posts per metadata query batch. */
	private $batch_size = 41;  // HACK HACK

	private $attempted_inserts = 0;
	private $actual_inserts = 0;

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
		$orderaddr  = $wpdb->prefix . 'wc_order_addresses';

		$textdex_status = $this->get_option();

		if ( array_key_exists( 'new', $textdex_status ) ) {
			$table  = <<<TABLE
CREATE TABLE $tablename (
	trigram CHAR(3) NOT NULL,
    id BIGINT NOT NULL,
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
			$query = <<<QUERY
			SELECT  *
			  FROM (
			    	SELECT MAX(post_id) maxmeta, MIN(post_id) minmeta
			     	  FROM $postmeta
			         WHERE meta_key IN ('_billing_address_index','_shipping_address_index','_billing_last_name','_billing_email','_billing_phone') 
			        ) a
			  JOIN (
 			      SELECT MAX(order_id) maxhpos, MIN(order_id) minhpos
			        FROM $ordersmeta WHERE meta_key IN ('_billing_address_index','_shipping_address_index')
			      ) b ON 1=1
        	  JOIN (
 			      SELECT MAX(order_id) maxaddr, MIN(order_id) minaddr
			        FROM $orderaddr 
            ) c ON 1=1
QUERY;
			$res   = $wpdb->get_results( $query );
			$res   = $res[0];
			$first = ( null !== $res->minmeta ) ? $res->minmeta : 0;
			$first = ( null !== $res->minhpos && $res->minhpos < $first ) ? $res->minhpos : $first;
			$first = ( null !== $res->minaddr && $res->minaddr < $first ) ? $res->minaddr : $first;
			$last  = ( null !== $res->maxmeta ) ? $res->maxmeta : 0;
			$last  = ( null !== $res->maxhpos && $res->maxhpos > $last ) ? $res->maxhpos : $last;
			$last  = ( null !== $res->maxaddr && $res->maxaddr > $last ) ? $res->maxaddr : $last;

			$textdex_status['last']    = $last + 1;
			$textdex_status['current'] = $first + 0;
			$this->update_option( $textdex_status );
		}
	}

	/**
	 * @return void
	 */
	public function load_textdex() {

		$this->schedule_batch();
	}

	public function load_batch() {
		$result = $this->load_next_batch();
		if ( $result ) {
			$this->schedule_batch();
		}
	}

	public function schedule_batch() {
		if ( $this->have_more_batches() ) {
			require_once( plugin_dir_path( __FILE__ ) . '/../libraries/action-scheduler/action-scheduler.php' );
			as_enqueue_async_action( 'fast_woo_order_lookup_textdex_action', array(), 'fast_woo_order_lookup', false, 10 );
		}
	}

	/**
	 * More batches to process?
	 *
	 * @return bool true if there are still more batches to process.
	 */
	public function have_more_batches() {
		$textdex_status = $this->get_option();

		return ( $textdex_status['current'] < $textdex_status['last'] );
	}

	/**
	 * Load the next batch of orders into the trigram table.
	 *
	 * @return bool true if there are still more batches to process.
	 */
	public function load_next_batch() {
		$textdex_status = $this->get_option();
		if ( $textdex_status['current'] >= $textdex_status['last'] ) {
			return false;
		}
		$first = $textdex_status['current'];
		$last  = min( $first + $textdex_status['batch'], $textdex_status['last'] );

		set_time_limit( 300 );
		$trigram_count = $textdex_status['trigram_batch'];
		global $wpdb;
		$wpdb->query( 'BEGIN;' );

		$resultset = $this->get_order_metadata( $first, $last );
		$trigrams  = array();
		foreach ( $this->get_trigrams( $resultset ) as $trigram ) {
			$trigrams[ $wpdb->prepare( '(%d,%s)', $trigram ) ] = 1;
			if ( count( $trigrams ) >= $trigram_count ) {
				$this->do_insert_statement( $trigrams );
				$trigrams = array();
			}
		}
		$this->do_insert_statement( $trigrams );
		unset ( $resultset );
		$wpdb->query( 'COMMIT;' );
		$textdex_status['current'] = $last;
		$this->update_option( $textdex_status );

		return $textdex_status['current'] < $textdex_status['last'];
	}


	/**
	 * @return bool true if the trigram index is ready to use.
	 */
	public function is_ready() {
		return ! $this->have_more_batches();
	}

	public function is_order_meta_key( $meta_key ) {
		return array_key_exists( $meta_key, $this->meta_keys_to_monitor );
	}


	public function get_trigrams( $resultset ) {

		if ( is_string( $resultset ) ) {
			$resultset = array( (object) array( 'id' => 1, 'value' => $resultset ) );
		}
		foreach ( $resultset as $row ) {
			$id    = $row->id;
			$value = $row->value;
			if ( $id == 3867 || $id == 3519 || str_contains( $value, 'ghanis' ) ) {
				error_log( "$id: $value" );
			} //HACK HACK
			$result = array();
			if ( ! is_string( $value ) ) {
				break;
			}
			$value = trim( $value );
			if ( mb_strlen( $value ) <= 0 ) {
				break;
			}
			$value = mb_ereg_replace( '/\s+/', ' ', $value );
			$value = trim( $value );
			$len   = mb_strlen( $value );
			if ( $len <= 0 ) {
				break;
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

			foreach ( $result as $item ) {
				yield array( $id, $item );
			}
		}
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
		$trigrams = array();
		foreach ( $this->get_trigrams( $value ) as $item ) {
			$trigrams[] = $item[1];
		}

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
		$first = $first + 0;
		if ( null === $last ) {
			$last = $first + 1;
		}
		global $wpdb;
		$postmeta   = $wpdb->postmeta;
		$ordersmeta = $wpdb->prefix . 'wc_orders_meta';
		$orders     = $wpdb->prefix . 'wc_orders';
		$orderitems = $wpdb->prefix . 'woocommerce_order_items';
		$addresses  = $wpdb->prefix . 'wc_order_addresses';
		$collation  = $wpdb->collate;


		$wpdb->query( $wpdb->prepare( 'SET @ifirst:=%d', $first ) );
		$wpdb->query( $wpdb->prepare( 'SET @ilast:=%d', $last ) );
		$query = <<<QUERY
				SELECT id,  TRIM(value) value
				FROM (
				SELECT post_id id, meta_value COLLATE $collation value
				  FROM $postmeta
				 WHERE meta_key IN ('_billing_address_index','_shipping_address_index','_billing_last_name','_billing_email','_billing_phone')
				   AND post_id >= @ifirst and post_id < @ilast

				UNION ALL
				SELECT order_id id, meta_value COLLATE $collation value
				  FROM $ordersmeta
				 WHERE meta_key IN ('_billing_address_index','_shipping_address_index')
				   AND order_id >= @ifirst and order_id < @ilast

				UNION ALL
				SELECT order_id id, order_item_name COLLATE $collation value
				  FROM $orderitems
				 WHERE order_id >= @ifirst and order_id < @ilast

				UNION ALL
				SELECT id, billing_email COLLATE $collation value
				  FROM $orders
				 WHERE id >= @ifirst and id < @ilast
				
				UNION ALL
				SELECT order_id id, first_name COLLATE $collation value
				  FROM $addresses
				 WHERE order_id >= @ifirst and order_id < @ilast

				UNION ALL
				SELECT order_id id, last_name COLLATE $collation value
				  FROM $addresses
				 WHERE order_id >= @ifirst and order_id < @ilast

				UNION ALL
				SELECT order_id id, company COLLATE $collation value
				  FROM $addresses
				 WHERE order_id >= @ifirst and order_id < @ilast

				UNION ALL
				SELECT order_id id, address_1 COLLATE $collation value
				  FROM $addresses
				 WHERE order_id >= @ifirst and order_id < @ilast

				UNION ALL
				SELECT order_id id, address_2 COLLATE $collation value
				  FROM $addresses
				 WHERE order_id >= @ifirst and order_id < @ilast

				UNION ALL
				SELECT order_id id, city COLLATE $collation value
				  FROM $addresses
				 WHERE order_id >= @ifirst and order_id < @ilast

				UNION ALL
				SELECT order_id id, state COLLATE $collation value
				  FROM $addresses
				 WHERE order_id >= @ifirst and order_id < @ilast

				UNION ALL
				SELECT order_id id, postcode COLLATE $collation value
				  FROM $addresses
				 WHERE order_id >= @ifirst and order_id < @ilast

				UNION ALL
				SELECT order_id id, country COLLATE $collation value
				  FROM $addresses
				 WHERE order_id >= @ifirst and order_id < @ilast

				UNION ALL
				SELECT order_id id, email COLLATE $collation value
				  FROM $addresses
				 WHERE order_id >= @ifirst and order_id < @ilast

				UNION ALL
				SELECT order_id id, phone COLLATE $collation value
				  FROM $addresses
				 WHERE order_id >= @ifirst and order_id < @ilast
				) a
			WHERE value IS NOT NULL;
QUERY;
		$resultset = $wpdb->get_results( $query );
		if ( false === $resultset ) {
			$wpdb->bail( 'Order data retrieval failure' );
		}

		error_log ("ifirst: $first  ilast: $last  rows: " . count($resultset));


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

		foreach ( $this->get_trigrams( $resultset ) as $trigram ) {
			$query  = $wpdb->prepare(
				"INSERT IGNORE INTO $tablename (id, trigram) VALUES (%d, %s);",
				$trigram );
			$status = $wpdb->query( $query );

			if ( false === $status ) {
				$wpdb->bail( 'Trigram insertion failure' );
			}
		}
	}

	/**
	 * @return false|mixed|null
	 */
	public function get_option() {
		return get_option( $this->option_name,
			array(
				'new'           => true,
				'current'       => 0,
				'batch'         => $this->batch_size,
				'trigram_batch' => $this->trigram_batch_size,
				'last'          => - 1,
			) );
	}

	/**
	 * @param $textdex_status
	 *
	 * @return void
	 */
	public function update_option(
		$textdex_status
	) {
		update_option( $this->option_name, $textdex_status, false );
	}

	/**
	 * Shutdown handler to kick a cronjob to continue background processing.
	 * Use only when DISABLE_WP_CRON is set.
	 *
	 * @return void
	 */
	public function kick_cron() {
		if ( wp_doing_cron() ) {
			/* NEVER hit the cron endpoint when doing cron, or you'll break lots of things */
			return;
		}
		$url = get_site_url( null, 'wp-cron.php' );
		$req = new \WP_Http();
		$res = $req->get( $url );
	}

	/**
	 * @param array $trigrams
	 * @param $wpdb
	 *
	 * @return void
	 */
	public function do_insert_statement( $trigrams ) {
		global $wpdb;
		if ( ! is_array( $trigrams ) || 0 === count( $trigrams ) ) {
			return;
		}
		$query  = 'INSERT IGNORE INTO ' . $this->tablename . ' (id, trigram) VALUES ' . implode( ',', array_keys( $trigrams ) );
		$result = $wpdb->query( $query );
		if ( false === $result ) {
			$wpdb->bail( 'inserts failure' );
		}
		$this->attempted_inserts += count( $trigrams );
		$this->actual_inserts    += $result;
	}
}

