<?php

namespace Fast_Woo_Order_Lookup;

class Textdex {
	/**
	 * Create the trigram table.
	 *
	 * @return void
	 */
	public function activate() {
		global $wpdb;
		$tablename      = $wpdb->prefix . 'fast_woo_textdex';
		$postmeta       = $wpdb->postmeta;
		$ordersmeta     = $wpdb->prefix . 'wc_orders_meta';
		$textdex_status = get_option( FAST_WOO_ORDER_LOOKUP_SLUG . 'textdex_status',
			array(
				'new'     => true,
				'current' => 0,
				'batch'   => 50,
				'last'    => - 1
			) );

		if ( array_key_exists( 'new', $textdex_status ) ) {
			$table  = <<<TABLE
CREATE TABLE $tablename (
    id BIGINT NOT NULL,
	trigram CHAR(3) NOT NULL,
	PRIMARY KEY (trigram, id)
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
			update_option( FAST_WOO_ORDER_LOOKUP_SLUG . 'textdex_status', $textdex_status, false );
		}
	}
//('_billing_address_index','_shipping_address_index','_billing_last_name','_billing_email','_billing_phone')

	/**
	 * @return void
	 */
	public function loadTextdex() {
		global $wpdb;
		$tablename  = $wpdb->prefix . 'fast_woo_textdex';
		$postmeta   = $wpdb->postmeta;
		$ordersmeta = $wpdb->prefix . 'wc_orders_meta';
		$orders     = $wpdb->prefix . 'wc_orders';
		$orderitems = $wpdb->prefix . 'woocommerce_order_items';

		$done = false;
		while( ! $done ) {
			$textdex_status = get_option( FAST_WOO_ORDER_LOOKUP_SLUG . 'textdex_status' );
			if ( $textdex_status['current'] > $textdex_status['last'] ) {
				$done = true;
				continue;
			}
			$first = $textdex_status['current'];
			$last  = min( $first + $textdex_status['batch'], $textdex_status['last'] );

			$query = <<<QUERY
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
			$wpdb->query( 'BEGIN;' );
			$query     = $wpdb->prepare( $query, array( $first, $last, $first, $last, $first, $last, $first, $last ) );
			$resultset = $wpdb->get_results( $query );
			if ( false === $resultset ) {
				$wpdb->bail( 'Order data retrieval failure' );
			}

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

			unset ( $resultset );
			$wpdb->query( 'COMMIT;' );
			$textdex_status['current'] = $last + 1;
			update_option( FAST_WOO_ORDER_LOOKUP_SLUG . 'textdex_status', $textdex_status, false );
		}
	}

	/**
	 * @return bool true if the trigram index is ready to use.
	 */
	public function isReady() {
		$textdex_status = get_option( FAST_WOO_ORDER_LOOKUP_SLUG . 'textdex_status', array(
			'new'     => true,
			'current' => 0,
			'batch'   => 50,
			'last'    => - 1
		) );

		return $textdex_status['current'] > $textdex_status['last'];
	}


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

	public function deactivate() {
		global $wpdb;
		$tablename = $wpdb->prefix . 'fast_woo_textdex';
		$wpdb->query( 'DROP TABLE ' . $tablename );
		delete_option( FAST_WOO_ORDER_LOOKUP_SLUG . 'textdex_status' );

	}

}

