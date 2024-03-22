<?php

namespace Fast_Woo_Order_Lookup;

class Textdex {
	public function activate() {
		global $wpdb;
		delete_option( FAST_WOO_ORDER_LOOKUP_SLUG . 'textdex_status' ); //TODO
		$tablename      = $wpdb->prefix . 'fast_woo_textdex';
		$postmeta       = $wpdb->postmeta;
		$ordersmeta     = $wpdb->prefix . 'wc_orders_meta';
		$textdex_status = get_option( FAST_WOO_ORDER_LOOKUP_SLUG . 'textdex_status',
			array(
				'new'     => true,
				'current' => 0,
				'batch'   => 10,
				'last'    => - 1
			) );

		if ( array_key_exists( 'new', $textdex_status ) ) {
			$table = <<<TABLE
CREATE TABLE $tablename (
    id BIGINT NOT NULL,
    dir TINYINT NOT NULL DEFAULT 0,
	word VARCHAR(128) NOT NULL,
	PRIMARY KEY (dir, word, id)
);
TABLE;
			$wpdb->query( $table );
			unset ( $textdex_status['new'] );
			$query                  = <<<QUERY
			SELECT GREATEST (
				(SELECT MAX(post_id) FROM $postmeta WHERE meta_key IN ('_billing_address_index','_shipping_address_index')),
				(SELECT MAX(id) FROM $ordersmeta WHERE meta_key IN ('_billing_address_index','_shipping_address_index'))			       
			) id;

QUERY;
			$textdex_status['last'] = $wpdb->get_var( $query );
			update_option( FAST_WOO_ORDER_LOOKUP_SLUG . 'textdex_status', $textdex_status, false );
		}
	}

	public function doAllBatches() {
		global $wpdb;
		$tablename  = $wpdb->prefix . 'fast_woo_textdex';
		$postmeta   = $wpdb->postmeta;
		$ordersmeta = $wpdb->prefix . 'wc_orders_meta';

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
				SELECT DISTINCT * FROM (
				SELECT post_id id, meta_value COLLATE utf8mb4_unicode_ci value
				  FROM $postmeta
				 WHERE meta_key IN ('_billing_address_index','_shipping_address_index')
				   AND post_id >= %d and post_id <= %d
				UNION ALL
				SELECT id id, meta_value COLLATE utf8mb4_unicode_ci value
				  FROM $ordersmeta
				 WHERE meta_key IN ('_billing_address_index','_shipping_address_index')
				   AND id >= %d and id < %d
				) a;
QUERY;
			$wpdb->query( 'BEGIN;' );
			$query     = $wpdb->prepare( $query, array( $first, $last, $first, $last ) );
			$resultset = $wpdb->get_results( $query );
			foreach ( $resultset as $result ) {

				foreach ( $this->textdex_entry( $result ) as $entry ) {
					$wpdb->query( $wpdb->prepare(
						"INSERT IGNORE INTO $tablename (id, dir, word ) VALUES (%d, %d, %s);",
						$entry ) );
				}
			}
			$wpdb->query( 'COMMIT;' );
			$textdex_status['current'] = $last;
			update_option( FAST_WOO_ORDER_LOOKUP_SLUG . 'textdex_status', $textdex_status, false );
		}
	}

	public function deactivate() {
		global $wpdb;
		$tablename = $wpdb->prefix . 'fast_woo_textdex';
		$wpdb->query( 'DROP TABLE ' . $tablename );
		delete_option( FAST_WOO_ORDER_LOOKUP_SLUG . 'textdex_status' );

	}

	private function textdex_entry( $result ) {

		foreach ( $this->textdex_wordlist( $result->value ) as $word ) {
			yield array( $result->id, 0, substr( $word, 0, 128 ) );
			yield array( $result->id, 1, substr( strrev( $word ), 0, 128 ) );
		}
	}

	private function textdex_wordlist( $value ) {
		$patterns = array (
			'/+?\.?\[d+/',
		)
		$nums         = array();
		$numpattern   = '/[^-_\d\.\)\( ][-_\d\.\)\( ]+[^-_\d\.\)\( ]/';
		$splitpattern = '/[^\w\d]+/';
		preg_match_all( $numpattern, $value, $nums );
/*		foreach ( $nums[0] as $num ) {
			$num = trim( $num );
			if ( strlen( $num ) > 0 ) {
				$value = str_replace( $num, '', $value );
				yield $num;
				yield preg_replace( $splitpattern, '', $num );
			}
		}*/
		foreach ( preg_split( $splitpattern, $value ) as $split ) {
			$split = trim( $split );
			if ( strlen( $split ) > 0 ) {
				yield $split;
			}
		}
	}
}

