<?php /** @noinspection SqlDialectInspection */

/** @noinspection SqlNoDataSourceInspection */

namespace Fast_Woo_Order_Lookup;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStoreMeta;
use stdClass;

class Custom_Fields {
	public function __construct() {
	}

	/**
	 *  Patch the query that looks for non-hidden (don't start with underscore) meta_keys
	 *   so it doesn't take too long.
	 *
	 *  Note that even this query can be sped up by two orders of magnitude by getting rid of the
	 *   prefix index.
	 *
	 * @param string $query
	 *
	 * @return string
	 */
	public function postmeta_form_keys_query( $query ) {
		if ( ! $this->filtering ) {
			return $query;
		}
		global $wpdb;
		$ordermeta = $wpdb->prefix . 'wc_orders_meta';
		$detect890 = "SELECT DISTINCT meta_key FROM $ordermeta WHERE meta_key NOT LIKE '\\\\_%' ORDER BY meta_key ASC";
		$detect893 = "SELECT DISTINCT meta_key FROM $ordermeta WHERE meta_key != '' AND meta_key NOT LIKE '\\\\_%' ORDER BY meta_key ASC";
		$replace   = "SELECT DISTINCT meta_key FROM $ordermeta WHERE meta_key != '' AND meta_key NOT LIKE '\\\\_%' AND meta_key NOT BETWEEN '_a' AND '_z' ORDER BY meta_key ASC";
		if ( false !== strstr( $query, $detect890 ) ) {
			/* we can stop looking at queries as soon as we find ours. */
			$query           = str_replace( $detect890, $replace, $query );
			$this->filtering = false;
			remove_filter( 'query', array( $this, 'postmeta_form_keys_query' ), 1 );
		} else if ( false !== strstr( $query, $detect893 ) ) {
			/* we can stop looking at queries as soon as we find ours. */
			$query           = str_replace( $detect893, $replace, $query );
			$this->filtering = false;
			remove_filter( 'query', array( $this, 'postmeta_form_keys_query' ), 1 );
		}

		return $query;
	}

	/**
	 * Retrieve the
	 * @return array
	 */
	public function get_order_custom_field_names() {
		$cached_keys = get_transient( FAST_WOO_ORDER_LOOKUP_METAKEY_CACHE );
		if ( is_array( $cached_keys ) ) {
			return $cached_keys;
		} else {
			if ( @version_compare( WOOCOMMERCE_VERSION, '9.0.0', '<' ) ) {
				/* we are in WooCommerce < 9.0.0 someplace, mung the queries to come */
				$this->filtering = true;
				add_filter( 'query', array( $this, 'postmeta_form_keys_query' ), 1 );
			}
			$limit = apply_filters( 'postmeta_form_limit', 30 );

			$orders_meta = wc_get_container()->get( OrdersTableDataStoreMeta::class );
			if ( method_exists( $orders_meta, 'get_meta_keys ' )) {
				$keys = wc_get_container()->get( OrdersTableDataStoreMeta::class )->get_meta_keys( $limit );
				set_transient( FAST_WOO_ORDER_LOOKUP_METAKEY_CACHE, $keys );
			} else {
				$keys = array();
			}

		}

		return $keys;
	}
}

