<?php /** @noinspection SqlDialectInspection */

/** @noinspection SqlNoDataSourceInspection */

namespace Fast_Woo_Order_Lookup;

use stdClass;

class Textdex {

	const FAST_WOO_ORDER_LOOKUP_INDEXING_ERROR_TRANSIENT_NAME = 'fast_woo_order_lookup_indexing_error';
	private $tablename;

	/** @var array Associative array, with keys named for meta_key values. */
	private $meta_keys_to_monitor;

	/** @var string Name of this plugin's option. */
	public $option_name;
	/** @var int The maximum number of tuples per insert */
	private $trigram_batch_size = 250;
	/** @var int The number of posts per metadata query batch. */
	private $batch_size = 200;

	private $attempted_inserts = 0;
	private $actual_inserts = 0;

	private $alias_chars = 'abcdefghijklmnopqrstuvwxyz';

	public function __construct() {
		global $wpdb;
		$this->tablename            = $wpdb->prefix . 'fwol';
		$this->meta_keys_to_monitor = array(
			'_billing_address_index'  => 1,
			'_shipping_address_index' => 1,
			'_billing_last_name'      => 1,
			'_billing_email'          => 1,
			'_billing_phone'          => 1,
			'_order_number_formatted' => 1,
			'_order_number'           => 1
		);

		$this->option_name = FAST_WOO_ORDER_LOOKUP_SLUG . 'textdex_status';

		/* Show any indexing errors on Site Health -> info */
		add_filter( 'debug_information', array( $this, 'debug_information' ) );
	}

	/**
	 * Create the trigram table.
	 *
	 * @return void
	 */
	public function activate() {
		global $wpdb;
		$tablename = $this->tablename;

		$textdex_status = $this->get_option();

		if ( array_key_exists( 'new', $textdex_status ) ) {
			$this->capture_query( 'Indexing start', 'event', false);
			$collation = $wpdb->collate;
			$table     = <<<TABLE
CREATE TABLE $tablename (
	t CHAR(3) NOT NULL COLLATE $collation,
    i BIGINT NOT NULL,
	PRIMARY KEY (t, i),
	KEY i (i)
)
COMMENT 'Fast Woo Order Lookup plugin trigram table, created on activation, dropped on deactivation.';
TABLE;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$result = $wpdb->query( $table );
			if ( false === $result ) {
				if ( ! str_contains( $wpdb->last_error, 'already exists' ) ) {
					$wpdb->bail( 'Table creation failure ' );
				}
			}
			unset ( $textdex_status['new'] );
			$this->update_option( $textdex_status );
		}
		$old_version = array_key_exists( 'version', $textdex_status ) ? $textdex_status['version'] : FAST_WOO_ORDER_LOOKUP_VERSION;
		if ( - 1 === version_compare( $old_version, FAST_WOO_ORDER_LOOKUP_VERSION ) ) {
			if ( $this->new_minor_version( $old_version, FAST_WOO_ORDER_LOOKUP_VERSION ) ) {
				$this->get_order_id_range();
			}
			$textdex_status['version'] = FAST_WOO_ORDER_LOOKUP_VERSION;
			$this->update_option( $textdex_status );
		}
	}

	/**
	 *  Use ActionScheduler to kick off the first batch.
	 *
	 * @return void
	 */
	public function load_textdex() {
		$this->schedule_batch();
	}

	/**
	 * This is the job called by ActionScheduler.
	 *
	 * It loads a batch of orders,
	 * then if there are more orders to do it kicks off another batch.
	 *
	 * @return void
	 */
	public function load_batch() {
		require_once( plugin_dir_path( __FILE__ ) . 'class-custom-fields.php' );
		$start_time = time();
		/* Give ourselves max_execution_time -10 sec to run, unless max_execution_time is very short. */
		$max_time  = ini_get( 'max_execution_time' );
		$max_time  = ( $max_time > 30 ) ? 30 : $max_time;
		$safe_time = ( $max_time > 30 ) ? 5 : 2;
		$end_time  = $start_time + $max_time - $safe_time;
		$end_time  = ( $end_time > $start_time ) ? $end_time : $start_time + 1;
		set_time_limit( $max_time );

		/* Do the field name cache (this is idempotent) */
		$cust = new Custom_Fields();
		$cust->get_order_custom_field_names();
		$done          = false;
		$another_batch = false;
		while( ! $done ) {
			$another_batch = $this->load_next_batch();
			if ( ! $another_batch ) {
				$done = true;
				continue;
			}
			$current_time = time();
			if ( $current_time >= $end_time ) {
				$done = true;
				continue;
			}
			set_time_limit( $max_time );
		}
		delete_transient( 'fast_woo_order_lookup_scheduled' );
		if ( $another_batch ) {
			$this->schedule_batch();
		} else {
			$this->capture_query( 'Indexing end', 'event', false );
		}

	}

	public function schedule_batch() {
		if ( $this->have_more_batches() ) {
			as_enqueue_async_action( 'fast_woo_order_lookup_textdex_action', array(), 'fast_woo_order_lookup', true );
		}
	}

	/**
	 * More batches to process?
	 *
	 * @return bool true if there are still more batches to process.
	 */
	public function have_more_batches( $fuzz_factor = 0 ) {
		$textdex_status = $this->get_option();
		if ( is_string( $textdex_status['error'] ) ) {
			return false;
		}

		return ( ( $fuzz_factor + $textdex_status['current'] ) < $textdex_status['last'] );
	}

	public function fraction_complete() {

		$textdex_status = $this->get_option();

		$denominator = ( 0.0 + $textdex_status['last'] - $textdex_status['first'] );
		if ( $denominator <= 0.0 ) {
			return 0.0;
		}

		$result = 1.0 - ( ( 0.0 + $textdex_status['last'] - $textdex_status['current'] )
		                  / $denominator );
		if ( $result < 0.0 ) {
			$result = 0.0;
		}
		if ( $result > 1.0 ) {
			$result = 1.0;
		}

		return $result;
	}

	/**
	 * Get any recently captured error.
	 *
	 * @return false|string
	 */
	public function get_load_error() {
		$textdex_status = $this->get_option();
		$error          = $textdex_status['error'];

		return is_string( $error ) ? $error : false;
	}

	/**
	 * Load the next batch of orders into the trigram table.
	 *
	 * @return bool true if there are still more batches to process.
	 */
	private function load_next_batch() {
		$textdex_status = $this->get_option();
		if ( is_string( $textdex_status['error'] ) || $textdex_status['current'] >= $textdex_status['last'] ) {
			return false;
		}
		$first = $textdex_status['current'];
		$last  = min( $first + $textdex_status['batch'], $textdex_status['last'] );

		$trigram_count = $textdex_status['trigram_batch'];
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'BEGIN;' );

		$resultset = $this->get_order_metadata( $first, $last );
		if ( is_string( $resultset ) ) {
			$textdex_status['error'] = $resultset;
			$this->update_option( $textdex_status );

			return false;
		} else if ( is_array( $resultset ) ) {
			$trigrams = array();
			foreach ( $this->get_trigrams( $resultset ) as $trigram ) {
				$trigrams[ $wpdb->prepare( '(%s,%d)', $trigram[0], $trigram[1] ) ] = 1;
				if ( count( $trigrams ) >= $trigram_count ) {
					$this->do_insert_statement( $trigrams );
					$trigrams = array();
				}
			}
			$this->do_insert_statement( $trigrams );
			unset ( $resultset, $trigrams );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT;' );
			$textdex_status['current'] = $last;
			$this->update_option( $textdex_status );

			return $textdex_status['current'] < $textdex_status['last'];
		} else {
			/* The resultset was not valid. */
			return false;
		}
	}

	/**
	 * @return bool true if the trigram index is ready to use.
	 */
	public function is_ready( $fuzz_factor = 10 ) {
		if ( $this->get_load_error()) {
			return false;
		}
		return ! $this->have_more_batches( $fuzz_factor );
	}

	public function is_order_meta_key( $meta_key ) {
		return array_key_exists( $meta_key, $this->meta_keys_to_monitor );
	}


	private function get_trigrams( $resultset ) {

		if ( is_string( $resultset ) ) {
			$resultset = array( (object) array( 'id' => 1, 'value' => $resultset ) );
		}
		foreach ( $resultset as $row ) {
			$id    = $row->id;
			$value = $row->value;
			if ( ! is_string( $value ) ) {
				continue;
			}
			$value = trim( $value );
			if ( mb_strlen( $value ) <= 0 ) {
				continue;
			}
			$value = mb_ereg_replace( '\s+', ' ', $value );
			$value = trim( $value );
			$len   = mb_strlen( $value );
			if ( $len <= 0 ) {
				continue;
			} else if ( 1 === $len ) {
				$value .= '  ';
			} else if ( 2 === $len ) {
				$value .= ' ';
			}
			$len = mb_strlen( $value ) - 2;
			if ( $len > 0 ) {
				for ( $i = 0; $i < $len; $i ++ ) {
					yield array( mb_substr( $value, $i, 3 ), $id );
				}
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
	 *
	 * @note The phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared item is necessary because
	 *        the %i (table / column name) placeholder is a recent addition to $wpdb->prepare().
	 */
	public function trigram_clause( $value ) {
		global $wpdb;

		/* Short search terms */
		if ( mb_strlen( $value ) < 3 ) {
			/* Reviewer note: The secure escaping of LIKE terms in
			 * $wpdb->esc_like() and $wpdb->prepare() is handled at a
			 * higher level than the `query` filter and
			 * so is not appropriate here. Hence esc_sql().
			 */
			return 'SELECT DISTINCT i FROM ' . $this->tablename . ' WHERE t LIKE ' . "'" . esc_sql( $value ) . "%'";
		}
		/* Normal search terms */
		$trigrams = array();
		foreach ( $this->get_trigrams( $value ) as $item ) {
			$trigrams[] = $item[0];
		}

		if ( 1 === count( $trigrams ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return $wpdb->prepare( 'SELECT DISTINCT i FROM ' . $this->tablename . ' WHERE t = %s', $trigrams[0] );
		}
		/* We make this sort of query here.
		 *
		 * SELECT a.id FROM
		 *	(SELECT id FROM t2 WHERE trigram = 'Oli') a
		 *	JOIN (SELECT id FROM t2 WHERE trigram = 'liv') b ON a.id = b.id
		 *	JOIN (SELECT id FROM t2 WHERE trigram = 'ive') c ON a.id = c.id
		 *	JOIN (SELECT id FROM t2 WHERE trigram = 'ver') d ON a.id = d.id
		 *  UNION ALL SELECT numvalue id  (only if we have a numeric search term)
		 */
		$alias_num = 0;

		$query = 'SELECT a.i FROM ';
		$query .= '(';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$query .= $wpdb->prepare( 'SELECT i FROM ' . $this->tablename . ' WHERE t = %s', array_pop( $trigrams ) );
		$query .= ') a ';


		while( count( $trigrams ) > 0 ) {
			$alias_num ++;
			$query .= 'JOIN (';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query .= $wpdb->prepare( 'SELECT i FROM ' . $this->tablename . ' WHERE t = %s', array_pop( $trigrams ) );
			$query .= ') ' . $this->alias( $alias_num ) . ' ON a.i=' . $this->alias( $alias_num ) . '.i ';
		}

		return $query;
	}

	/**
	 * Deactivation action. Remove textdex table and option.
	 *
	 * @return void
	 */
	public function deactivate() {
		global $wpdb;
		$this->capture_query( 'Deactivate', 'event', false);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE $this->tablename;" );
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
		$tablename            = $this->tablename;
		$textdex_status       = $this->get_option();
		$textdex_status_dirty = false;

		foreach ( $order_ids as $order_id ) {
			$original               = $textdex_status['last'];
			$textdex_status['last'] = max( $textdex_status['last'], $order_id + 1 );
			if ( $textdex_status['last'] !== $original ) {
				$textdex_status_dirty = true;
			}
			if ( $this->is_ready() ) {
				$textdex_status['current'] = max( $textdex_status['current'], $order_id );
				if ( $textdex_status['current'] !== $original ) {
					$textdex_status_dirty = true;
				}
			}
		}
		if ( $textdex_status_dirty ) {
			$this->update_option( $textdex_status );
			$textdex_status_dirty = false;
		}

		if ( $this->is_ready() ) {
			/* Do this all at once to avoid autocommit overhead. */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'BEGIN;' );
			foreach ( $order_ids as $order_id ) {
				/* Get rid of old metadata */
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $tablename . ' WHERE i = %d', $order_id ) );
				/* Retrieve and add the new metadata */
				$resultset = $this->get_order_metadata( $order_id );
				$this->insert_trigrams( $resultset );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT;' );
		}
	}

	/**
	 * Get order metadata for a sequence of order ids (post_id values)
	 *
	 * @param int $first First order ID to get
	 * @param int $last Last + 1 order ID to get. Default: Just get one.
	 *
	 * @return array|false|mixed|object|stdClass[]|null  Resultset, string, or falsy
	 */
	private function get_order_metadata( $first, $last = null ) {
		$first = (int) $first;
		if ( null === $last ) {
			$last = $first + 1;
		}
		global $wpdb;
		$postmeta   = $wpdb->postmeta;
		$ordersmeta = $wpdb->prefix . 'wc_orders_meta';
		$orders     = $wpdb->prefix . 'wc_orders';
		$orderitems = $wpdb->prefix . 'woocommerce_order_items';
		$addresses  = $wpdb->prefix . 'wc_order_addresses';
		$charset    = $wpdb->charset;
		$collation  = $wpdb->collate;


		$query = <<<QUERY
				SELECT id, value
				FROM (
				SELECT post_id id, CONVERT( meta_value USING $charset) COLLATE $collation value
				  FROM $postmeta
				 WHERE meta_key IN ('_billing_address_index','_shipping_address_index','_billing_last_name','_billing_email','_billing_phone','_order_number','_order_number_formatted')
				   AND post_id >= %d and post_id < %d

				UNION ALL
				SELECT order_id id, CONVERT( meta_value USING $charset) COLLATE $collation value
				  FROM $ordersmeta
				 WHERE meta_key IN ('_billing_address_index','_shipping_address_index','_order_number','_order_number_formatted')
				   AND order_id >= %d and order_id < %d

				UNION ALL
				SELECT order_id id, CONVERT( order_item_name USING $charset) COLLATE $collation value
				  FROM $orderitems
				 WHERE order_id >= %d and order_id < %d

				UNION ALL
				SELECT id, CONVERT( billing_email USING $charset) COLLATE $collation value
				  FROM $orders
				 WHERE id >= %d and id < %d
				
				UNION ALL
				SELECT id, CAST(id AS CHAR) COLLATE $collation value
				  FROM $orders
				 WHERE id >= %d and id < %d
				
				UNION ALL
				SELECT id, CONVERT( transaction_id USING $charset) COLLATE $collation value
				  FROM $orders
				 WHERE id >= %d and id < %d AND transaction_id IS NOT NULL
				
				UNION ALL
				SELECT order_id id, CONVERT( CONCAT_WS (' ', first_name, last_name, company, address_1, address_2, city, state, postcode, country) USING $charset) COLLATE $collation value
				  FROM $addresses
				 WHERE order_id >= %d and order_id < %d

				UNION ALL
				SELECT order_id id, CONVERT( email USING $charset) COLLATE $collation value
				  FROM $addresses
				 WHERE order_id >= %d and order_id < %d

				UNION ALL
				SELECT order_id id, CONVERT( phone USING $charset) COLLATE $collation value
				  FROM $addresses
				 WHERE order_id >= %d and order_id < %d
				) a
			WHERE value IS NOT NULL
			ORDER BY id, value;
QUERY;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$query = $wpdb->prepare( $query,
			array(
				$first,
				$last,
				$first,
				$last,
				$first,
				$last,
				$first,
				$last,
				$first,
				$last,
				$first,
				$last,
				$first,
				$last,
				$first,
				$last,
				$first,
				$last
			) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$resultset = $wpdb->get_results( $query );
		if ( ! $resultset ) {
			return $this->capture_query( '(indexing V' . FAST_WOO_ORDER_LOOKUP_VERSION . ')', 'indexing', true );
		}

		return $resultset;
	}

	/**
	 * If a query fails, put a message in a transient.
	 *
	 * @param string $query Text of the query.
	 * @param string $type 'indexing' or 'search'.
	 * @param bool $is_error We're reporting an error.
	 *
	 * @return string|false
	 */
	public function capture_query( $query, $type, $is_error ) {
		global $wpdb;
		$dberror = false;
		$trace   = debug_backtrace( 0, 2 );
		$msg     = array();
		$msg []  = current_time( 'mysql', false );
		$msg []  = $type;
		$msg []  = $trace[1]['function'] . ':';
		if ( $is_error ) {
			$dberror = $wpdb->dbh ? mysqli_error( $wpdb->dbh ) : 'No database connection';
			$msg []  = 'error:';
			$msg []  = $dberror;
		}
		$msg []  = ltrim( preg_replace( '/\s+/', ' ', $query ) );
		$message = implode( PHP_EOL, array_filter( $msg ) );

		$this->store_message( $message );

		return $dberror;
	}

	/**
	 * Add a stanza to the debug information shown on the Tools -> Site Health -> Info screen.
	 *
	 * @param array $info Associative array of items to show.
	 *
	 * See class-wp-debug-data:75
	 *
	 * @return array
	 */
	public function debug_information( $info ) {
		global $wpdb;

		$errors = get_transient( self::FAST_WOO_ORDER_LOOKUP_INDEXING_ERROR_TRANSIENT_NAME );
		if ( is_array( $errors ) ) {
			$messages = array();
			$counter  = 0;
			foreach ( $errors as $error ) {
				$messages[ 'error' . $counter ] = array(
					'label' => __( 'Operation', 'fast-woo-order-lookup' ),
					'value' => $error,
					'debug' => $error,
				);
				$counter ++;
			}
			$ordersmeta = $wpdb->prefix . 'wc_orders_meta';
			$orders     = $wpdb->prefix . 'wc_orders';
			$tables     = array(
				$wpdb->postmeta,
				$ordersmeta,
				$orders,
				$wpdb->prefix . 'woocommerce_order_items',
				$wpdb->prefix . 'wc_order_addresses',
			);

			foreach ( $tables as $table ) {
				$tbl                = $wpdb->get_col( "SHOW CREATE TABLE $table", 1 );
				$messages[ $table ] = array(
					'label' => $table,
					'value' => $tbl,
				);
			}
			$this->add_message( $messages, 'post-types',
				$this->get_counts( "SELECT post_type, COUNT(*) num FROM $wpdb->posts GROUP BY post_type" ) );
			$this->add_message( $messages, 'order-postmeta-key',
				$this->get_counts( "SELECT meta_key, COUNT(*) num FROM $wpdb->postmeta JOIN $wpdb->posts ON wp_postmeta.meta_id = wp_posts.ID WHERE post_type = 'shop_order' GROUP BY meta_key" ) );
			$this->add_message( $messages, 'order-types',
				$this->get_counts( "SELECT CONCAT_WS('/', type , status) ts, COUNT(*) num FROM $orders GROUP BY type, status" ) );
			$this->add_message( $messages, 'order-meta',
				$this->get_counts( "SELECT meta_key, COUNT(*) num FROM $ordersmeta  GROUP BY meta_key" ) );


			$featurelist = array();
			if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				$features = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_features( true, true );
				foreach ( $features as $feature => $datablock ) {
					$featurelist [] = ( $feature . ':' . ( $datablock['is_enabled'] ? 'enabled' : 'disabled' ) );
				}
			}

			$countout = '?';
			try {
				$counts   = $wpdb->get_results( "SELECT COUNT(*) trigrams, MIN(i) first_id, MAX(i) last_id FROM $this->tablename", ARRAY_A );
				$counts   = $counts[0];
				$countout = array();
				foreach ( $counts as $col => $count ) {
					$countout[] = $col . ':' . $count;
				}
				$countout = implode( ';', $countout );
			} catch( \Exception $e ) {
				$countout = $e->getMessage();
			}
			$fields                         = array(

				'explanation'   => array(
					'label'   => __( 'Explanation', 'fast-woo-order-lookup' ),
					'value'   => __( 'Errors sometimes occur while the plugin is creating its index table. Variations in database server make and version, and your WordPress version when you created it cause these. The plugin author will add suppot for your variation if you open a support topic.', 'fast-woo-order-lookup' ) . ' ' .
					             __( 'And sometimes some types of orders cannot be found. Search for the failing orders and return here to capture useful troubleshooting information.', 'fast-woo-order-lookup' ),
					'debug'   => '',
					'private' => true,
				),
				'request'       => array(
					'label' => __( 'Request', 'fast-woo-order-lookup' ),
					'value' => __( 'Please create a support topic at', 'fast-woo-order-lookup' ) . ' ' . 'https://wordpress.org/support/plugin/fast-woo-order-lookup/' . ' ' .
					           __( 'Click [Copy Site Info To Clipboard] then paste your site info into the topic.', 'fast-woo-order-lookup' ),
					'debug' => __( 'Please create a support topic at', 'fast-woo-order-lookup' ) . ' ' . 'https://wordpress.org/support/plugin/fast-woo-order-lookup/' . ' ' .
					           __( 'and paste this site info (all of it please) into the topic. We will take a look.', 'fast-woo-order-lookup' ),

				),
				'woo-features'  => array(
					'label' => __( 'WooCommerce Features', 'fast-woo-order-lookup' ),
					'value' => implode( '; ', $featurelist ),
				),
				'trigram-table' => array(
					'label' => __( 'Trigram Table', 'fast-woo-order-lookup' ),
					'value' => $countout,
				),
			);
			$item                           = array(
				'label'  => __( 'Fast Woo Order Lookup', 'fast-woo-order-lookup' ),
				'fields' => array_merge( $fields, $messages ),
			);
			$info ['fast-woo-order-lookup'] = $item;
		}

		return $info;

	}

	private function add_message( &$messages, $tag, $message ) {
		$messages [ $tag ] = array(
			'label' => $tag,
			'value' => $message,
		);
	}

	private function get_counts( $query ) {
		global $wpdb;
		try {
			$rows = $wpdb->get_results( $query, OBJECT_K );
			if ( ! $rows ) {
				return 'error ' . ( $wpdb->dbh ? mysqli_error( $wpdb->dbh ) : 'No database connection' ) . ': ' . $query;
			}
			$out = array();
			foreach ( $rows as $item => $row ) {
				$out [] = $item . ':' . $row->num;
			}

			return implode( ';', $out );
		} catch( \Exception $e ) {
			return 'exception ' . $e->getMessage() . ' ' . ( $wpdb->dbh ? mysqli_error( $wpdb->dbh ) : 'No database connection' ) . ': ' . $query;
		}

	}

	/**
	 * Insert a bunch of trigrams.
	 *
	 * @param $resultset
	 *
	 * @return void
	 */
	private function insert_trigrams( $resultset ) {
		global $wpdb;
		$tablename = $this->tablename;

		foreach ( $this->get_trigrams( $resultset ) as $trigram ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$query = $wpdb->prepare( "INSERT IGNORE INTO $tablename (t, i) VALUES (%s, %d);", $trigram[0], $trigram[1] );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$status = $wpdb->query( $query );

			if ( false === $status ) {
				$wpdb->bail( 'Trigram insertion failure' );
			}
		}
	}

	/**
	 * @return false|mixed|null
	 */
	private function get_option() {
		$option = get_option( $this->option_name,
			array(
				'new'           => true,
				'current'       => 0,
				'batch'         => $this->batch_size,
				'trigram_batch' => $this->trigram_batch_size,
				'last'          => - 1,
				'version'       => FAST_WOO_ORDER_LOOKUP_VERSION,
				'error'         => false,
			) );
		if ( ! isset( $option['error'] ) ) {
			$option['error'] = false;
		}

		return $option;
	}

	/**
	 * @param $textdex_status
	 *
	 * @return void
	 */
	private function update_option(
		$textdex_status
	) {
		update_option( $this->option_name, $textdex_status, true );
	}

	/**
	 * @param array $trigrams
	 *
	 * @return void
	 */
	private function do_insert_statement( $trigrams ) {
		global $wpdb;
		if ( ! is_array( $trigrams ) || 0 === count( $trigrams ) ) {
			return;
		}
		$query = "INSERT IGNORE INTO $this->tablename (t, i) VALUES "
		         . implode( ',', array_keys( $trigrams ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query( $query );
		if ( false === $result ) {
			$wpdb->bail( 'inserts failure' );
		}
		$this->attempted_inserts += count( $trigrams );
		$this->actual_inserts    += $result;
	}

	/**
	 * Get a serial alias name a,b,c,d, a0, a1,  etc.
	 *
	 * @param int $n Alias number.
	 *
	 * @return string Short alias name.
	 */
	private function alias( $n ) {
		if ( $n < strlen( $this->alias_chars ) ) {
			return substr( $this->alias_chars, $n, 1 );
		}

		return 'a' . ( $n - strlen( $this->alias_chars ) );
	}

	public function get_order_id_range() {
		global $wpdb;
		$postmeta   = $wpdb->postmeta;
		$ordersmeta = $wpdb->prefix . 'wc_orders_meta';
		$orderaddr  = $wpdb->prefix . 'wc_order_addresses';

		$textdex_status = $this->get_option();

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
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
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
		$textdex_status['first']   = $first + 0;
		$this->update_option( $textdex_status );
	}

	/**
	 * Is $version1 earlier than $version2, ignoring patch levels?
	 *
	 * @param string $version1 In major.minor.patch semver format.
	 * @param string $version2
	 *
	 * @return bool
	 */
	private function new_minor_version( $version1, $version2 ) {
		if ( 0 === version_compare( $version1, $version2 ) ) {
			return false;
		}
		$s        = explode( '.', $version1 );
		$s[2]     = '0';
		$version1 = implode( '.', $s );
		$s        = explode( '.', $version2 );
		$s[2]     = '0';
		$version2 = implode( '.', $s );

		return version_compare( $version1, $version2, '<' );
	}

	public function store_message( $message ) {
		$messages = get_transient( self::FAST_WOO_ORDER_LOOKUP_INDEXING_ERROR_TRANSIENT_NAME );
		if ( ! is_array( $messages ) ) {
			$messages = array();
		}
		array_unshift( $messages, $message );
		$messages = array_slice( $messages, 0, 10 );
		set_transient( self::FAST_WOO_ORDER_LOOKUP_INDEXING_ERROR_TRANSIENT_NAME, $messages, WEEK_IN_SECONDS );
	}

}

