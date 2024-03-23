<?php
/**
 * Fast Woo Order Lookup
 *
 * @package       fast-woo-order-lookup
 * @author        Ollie Jones
 * @license       gplv2
 *
 * @wordpress-plugin
 * Plugin Name:   Fast Woo Order Lookup
 * Plugin URI:    https://plumislandmedia.net/wordpress-plugins/fast-woo-order-lookup/
 * Description:   Look up orders faster in large WooCommerce stores with many orders.
 * Version:       0.1.2
 * Author:        Ollie Jones
 * Author URI:    https://github.com/OllieJones
 * Text Domain:   fast-woo-order-lookup
 * Domain Path:   /languages
 * License:       GPLv2
 * License URI:   https://www.gnu.org/licenses/gpl-2.0.html
 *
 * You should have received a copy of the GNU General Public License
 * along with Fast Woo Order Lookup. If not, see <https://www.gnu.org/licenses/gpl-2.0.html/>.
 */

namespace Fast_Woo_Order_Lookup;

use WP_Query;
use wpdb;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FastWooOrderLookup {
	private static $instance = null;

	private $textdex;
	private $filtering = false;

	private $term;
	private $trigram_clause;

	/**
	 * Filter: Data store name.
	 *
	 * @param string $store
	 *
	 * @return string
	 */
	public static function woocommerce_order_data_store( $store ) {
		if ( 'WC_Order_Data_Store_CPT' === $store || 'WCS_Subscription_Data_Store_CPT' === $store ) {
			$instance = self::getInstance();
		}

		return $store;
	}

	/** Singleton constructor interface.
	 *
	 * @return FastWooOrderLookup
	 */
	public static function getInstance() {
		if ( self::$instance == null ) {
			self::$instance = new FastWooOrderLookup();
		}

		return self::$instance;
	}

	/**
	 * Configure this plugin to intercept metadata searches for WooCommerce orders.
	 */
	private function __construct() {
		add_filter( 'query', array( $this, 'hack_hack_hack' ), 10, 1 );  //TODO this is debug crap
		add_filter( 'woocommerce_shop_order_search_fields', array( $this, 'filter_search_fields' ), 10, 1 );
		add_filter( 'woocommerce_shop_subscription_search_fields', array( $this, 'filter_search_fields' ), 10, 1 );
		add_filter( 'woocommerce_shop_order_search_results', array( $this, 'filter_search_results' ), 10, 3 );
		add_filter( 'woocommerce_shop_subscription_search_results', array( $this, 'filter_search_results' ), 10, 3 );
		add_filter( 'woocommerce_order_query_args', array( $this, 'woocommerce_order_query_args' ), 10, 1 );
		add_filter( 'woocommerce_hpos_pre_query', array( $this, 'woocommerce_hpos_pre_query' ), 10, 3 );
		add_filter( 'woocommerce_order_query', array( $this, 'woocommerce_order_query' ), 10, 2 );


		$dir = plugin_dir_path( __FILE__ );
		require_once( $dir . 'code/class-textdex.php' );
		$this->textdex = new Textdex();
		$this->textdex->activate();
		$this->textdex->loadTextdex();
	}

	public function hack_hack_hack( $query ) {   //TODO this is debug crap
		/*	if ( str_contains( $query, 'Oliver')) {
				$k = $query;
			}*/
		return $query;
	}

	/**
	 * Filter. immediately before metadata search.
	 *
	 * @param array $search_fields
	 *
	 * @return array
	 */
	public function filter_search_fields( $search_fields ) {
		if ( ! $this->textdex->isReady() ) {
			return $search_fields;
		}
		/* Hook to mung the queries. */
		$this->filtering = true;
		add_filter( 'query', array( $this, 'standard_query' ), 1, 1 );

		return $search_fields;
	}

	/**
	 * Filter. Immediately after metadata search.
	 *
	 * @param array $order_ids
	 * @param string $term
	 * @param array $search_fields
	 *
	 * @return array
	 */
	public function filter_search_results( $order_ids, $term, $search_fields ) {

		if ( $this->filtering ) {
			/* Discontinue filtering queries after the metadata search */
			remove_filter( 'query', array( $this, 'standard_query' ), 1 );
			$this->filtering = false;
		}

		return $order_ids;
	}

	/**
	 * HPOS filter. Fix up the args.
	 *
	 * We use this to know we're about to get some queries we need to mung.
	 *
	 * @param $args
	 *
	 * @return mixed
	 */
	public function woocommerce_order_query_args( $args ) {
		if ( ! $this->textdex->isReady() ) {
			return $args;
		}
		if ( array_key_exists( 's', $args ) && is_string( $args['s'] ) ) {
			$this->term = $args['s'];
			$this->trigram_clause = $this->textdex->trigram_clause( $this->term );

			/* Hook to mung the queries. */
			$this->filtering = true;
			add_filter( 'query', array( $this, 'standard_query' ), 1, 1 );
		}

		return $args;
	}

	/**
	 *  HPOS filter. Fix up results.
	 *
	 * We use this to know we're done munging queries.
	 *
	 * @param $results
	 * @param $args
	 *
	 * @return mixed
	 */
	public function woocommerce_order_query( $results, $args ) {
		if ( $this->filtering ) {
			/* Discontinue filtering queries after the search */
			remove_filter( 'query', array( $this, 'standard_query' ), 1 );
			$this->filtering = false;
		}

		return $results;
	}

	/**
	 * woocommerce_hpos_pre_query filter.
	 * Filters the orders array before the query takes place.
	 *
	 * Return a non-null value to bypass the HPOS default order queries.
	 *
	 * If the query includes limits via the `limit`, `page`, or `offset` arguments, we
	 * encourage the `found_orders` and `max_num_pages` properties to also be set.
	 *
	 * @param array|null $order_data {
	 *     An array of order data.
	 *
	 * @type int[] $orders Return an array of order IDs data to short-circuit the HPOS query,
	 *                                or null to allow HPOS to run its normal query.
	 * @type int $found_orders The number of orders found.
	 * @type int $max_num_pages The number of pages.
	 * }
	 *
	 * @param OrdersTableQuery $query The OrdersTableQuery instance.
	 * @param string $sql The OrdersTableQuery instance.
	 *
	 * @since 8.2.0
	 *
	 */
	public function woocommerce_hpos_pre_query( $order_data, $query, $sql ) {
		return $order_data;
	}

	/**
	 * Mung the order metadata search queries to include the trigram selection clause.
	 *
	 * This is a MISERABLE hack. There aren't any suitable hooks to do this more elegantly.
	 *
	 * @param string $query Database query.
	 *
	 * @return string
	 */
	public function standard_query( $query ) {

		if ( ! $this->term ) {
			$splits = explode( 'LIKE \'%', $query, 2 );
			if ( 2 !== count( $splits ) ) {
				return $query;
			}
			$splits = explode( '%\'', $splits[1] );
			if ( 2 !== count( $splits ) ) {
				return $query;
			}
			$this->term           = $splits[0];
			$this->trigram_clause = $this->textdex->trigram_clause( $this->term );
		}
		$newQuery = $query;
		if ( str_contains( $newQuery, 'woocommerce_order_items as order_item' ) ) {
			$newQuery = str_replace( 'WHERE order_item_name LIKE', 'WHERE order_id IN (' . $this->trigram_clause . ') AND order_item_name LIKE', $newQuery );
		} else if ( str_contains( $newQuery, 'SELECT DISTINCT os.order_id FROM wp_wc_order_stats os' ) ) {
			/* empty, intentionally */
		} else {
			$newQuery = str_replace( 'postmeta p1 WHERE ', 'postmeta p1 WHERE post_id IN (' . $this->trigram_clause . ') AND ', $newQuery );
		}

		return $newQuery;
	}
	/**
	 * Mung the order metadata search queries to include the trigram selection clause.
	 *
	 * This is a MISERABLE hack. There aren't any suitable hooks to do this more elegantly.
	 *
	 * @param string $query Database query.
	 *
	 * @return string
	 */
	public function query( $query ) {

		if ( ! $this->term ) {
			$splits = explode( 'LIKE \'%', $query, 2 );
			if ( 2 !== count( $splits ) ) {
				return $query;
			}
			$splits = explode( '%\'', $splits[1] );
			if ( 2 !== count( $splits ) ) {
				return $query;
			}
			$this->term           = $splits[0];
			$this->trigram_clause = $this->textdex->trigram_clause( $this->term );
		}
		$newQuery = $query;
		if ( str_contains( $newQuery, 'woocommerce_order_items as order_item' ) ) {
			$newQuery = str_replace( 'WHERE order_item_name LIKE', 'WHERE order_id IN (' . $this->trigram_clause . ') AND order_item_name LIKE', $newQuery );
		} else if ( str_contains( $newQuery, 'SELECT DISTINCT os.order_id FROM wp_wc_order_stats os' ) ) {
			/* empty, intentionally */
		} else {
			$newQuery = str_replace( 'postmeta p1 WHERE ', 'postmeta p1 WHERE post_id IN (' . $this->trigram_clause . ') AND ', $newQuery );
		}

		return $newQuery;
	}

}

// Plugin name
const FAST_WOO_ORDER_LOOKUP_NAME = 'Fast Woo Order Lookup';

// Plugin version
const FAST_WOO_ORDER_LOOKUP_VERSION = '0.1.3';

// Plugin Root File
const FAST_WOO_ORDER_LOOKUP_PLUGIN_FILE = __FILE__;

// Plugin base
define( 'FAST_WOO_ORDER_LOOKUP_PLUGIN_BASE', plugin_basename( FAST_WOO_ORDER_LOOKUP_PLUGIN_FILE ) );

// Plugin slug
define( 'FAST_WOO_ORDER_LOOKUP_SLUG', explode( DIRECTORY_SEPARATOR, FAST_WOO_ORDER_LOOKUP_PLUGIN_BASE )[0] );

// Plugin Folder Path
define( 'FAST_WOO_ORDER_LOOKUP_PLUGIN_DIR', plugin_dir_path( FAST_WOO_ORDER_LOOKUP_PLUGIN_FILE ) );

// Plugin Folder URL
define( 'FAST_WOO_ORDER_LOOKUP_PLUGIN_URL', plugin_dir_url( FAST_WOO_ORDER_LOOKUP_PLUGIN_FILE ) );


register_activation_hook( __FILE__, 'Fast_Woo_Order_Lookup\activate' );
register_deactivation_hook( __FILE__, 'Fast_Woo_Order_Lookup\deactivate' );

/* Don't do anything until WooCommerce does ->load( 'order' ) */
add_action( 'woocommerce_order_data_store',
	array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'woocommerce_order_data_store' ) );


function activate() {
	register_uninstall_hook( __FILE__, 'Fast_Woo_Order_Lookup\uninstall' );
}

function deactivate() {

	require_once( plugin_dir_path( __FILE__ ) . 'code/class-textdex.php' );
	$textdex = new Textdex();
	$textdex->deactivate();
}

function uninstall() {

}
