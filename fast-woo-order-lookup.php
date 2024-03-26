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
 * Version:       0.1.4
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
	private $orders_to_update = array();


	public static function woocommerce_changing_order( $order_id, $order ) {
		$instance                                = self::getInstance();
		$instance->orders_to_update[ $order_id ] = 1;
	}

	public static function woocommerce_deleting_order( $order_id ) {
		$instance                                = self::getInstance();
		$instance->orders_to_update[ $order_id ] = 1;
	}

	public static function woocommerce_order_object_updated_props( $order, $props ) {
		$instance                                = self::getInstance();
		$order_id                                = $order->get_id();
		$instance->orders_to_update[ $order_id ] = 1;
	}

	public static function update_post_meta( $meta_id, $object_id, $meta_key, $_meta_value ) {
		$instance = self::getInstance();
		if ( $instance->textdex->is_order_meta_key( $meta_key ) ) {
			$instance->orders_to_update[ $object_id ] = 1;
		}

	}

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
		/* Query manipulation */
		add_filter( 'woocommerce_shop_order_search_fields', array( $this, 'filter_search_fields' ), 10, 1 );
		add_filter( 'woocommerce_shop_subscription_search_fields', array( $this, 'filter_search_fields' ), 10, 1 );
		add_filter( 'woocommerce_shop_order_search_results', array( $this, 'filter_search_results' ), 10, 3 );
		add_filter( 'woocommerce_shop_subscription_search_results', array( $this, 'filter_search_results' ), 10, 3 );
		add_filter( 'woocommerce_order_query_args', array( $this, 'woocommerce_order_query_args' ), 10, 1 );
		add_filter( 'woocommerce_order_query', array( $this, 'woocommerce_order_query' ), 10, 2 );

		$dir = plugin_dir_path( __FILE__ );
		require_once( $dir . 'code/class-textdex.php' );
		$this->textdex = new Textdex();
		$this->textdex->activate();
		$this->textdex->load_textdex();

		add_action( 'shutdown', array( $this, 'update_textdex' ), 1, 0 );
	}

	/**
	 * 'shutdown' action handler to update trigram indexes when orders change.
	 *
	 * @return void
	 */
	public function update_textdex() {
		if ( count( $this->orders_to_update ) > 0 ) {
			$this->textdex->update( array_keys( $this->orders_to_update ) );
		}
	}

	/**
	 * Filter. immediately before metadata search.
	 *
	 * @param array $search_fields
	 *
	 * @return array
	 */
	public function filter_search_fields( $search_fields ) {
		if ( ! $this->textdex->is_ready() ) {
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
	 * HPOS filter. Fix up the args.
	 *
	 * We use this to know we're about to get some queries we need to mung.
	 *
	 * @param $args
	 *
	 * @return mixed
	 */
	public function woocommerce_order_query_args( $args ) {
		if ( ! $this->textdex->is_ready() ) {
			return $args;
		}
		if ( array_key_exists( 's', $args ) && is_string( $args['s'] ) ) {
			$this->term           = $args['s'];
			$this->trigram_clause = $this->textdex->trigram_clause( $this->term );

			/* Hook to mung the queries. */
			$this->filtering = true;
			add_filter( 'query', array( $this, 'hpos_query' ), 1, 1 );
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
			remove_filter( 'query', array( $this, 'hpos_query' ), 1 );
			$this->filtering = false;
		}

		return $results;
	}

	/**
	 * Mung the order metadata search queries to include the trigram selection clause, in the HPOS queries
	 *
	 * This is a MISERABLE hack. There aren't any suitable hooks to do this more elegantly.
	 *
	 * Two queries use this. They're the same except the second
	 * counts the resultset, without the LIMIT clause, of the first,
	 * which is Oracle MySQL's replacement for SQL_CALC_FOUND_ROWS.
	 *
	 * @param string $query Database query.
	 *
	 * @return string
	 */
	public function hpos_query( $query ) {
		if ( str_contains( $query, 'wp_woocommerce_order_items AS search_query_items ON search_query_items.order_id = wp_wc_orders.id WHERE 1=1 AND' ) ) {
			return str_replace( 'WHERE 1=1 AND', 'WHERE 1=1 AND  wp_wc_orders.id IN (' . $this->trigram_clause . ') AND ', $query );
		}

		return $query;
	}

}

// Plugin name
const FAST_WOO_ORDER_LOOKUP_NAME = 'Fast Woo Order Lookup';

// Plugin version
const FAST_WOO_ORDER_LOOKUP_VERSION = '0.1.4';

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

/* Don't do anything until WooCommerce does ->load( 'order' ). */
add_action( 'woocommerce_order_data_store',
	array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'woocommerce_order_data_store' ) );

/* Hook anything that changes an order object */
add_action( 'woocommerce_new_order',
	array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'woocommerce_changing_order' ), 10, 2 );
add_action( 'woocommerce_update_order',
	array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'woocommerce_changing_order' ), 10, 2 );
add_action( 'woocommerce_order_object_updated_props',
	array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'woocommerce_order_object_updated_props' ), 10, 2 );
/* Hook changes to order status. */
add_action( 'woocommerce_delete_order',
	array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'woocommerce_deleting_order' ), 10, 1 );
add_action( 'woocommerce_trash_order',
	array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'woocommerce_deleting_order' ), 10, 1 );
add_action( 'woocommerce_untrash_order',
	array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'woocommerce_deleting_order' ), 10, 1 );
add_action( 'woocommerce_cancelled_order',
	array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'woocommerce_deleting_order' ), 10, 1 );
add_action( 'update_post_meta',
	array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'update_post_meta' ), 10, 4 );


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
