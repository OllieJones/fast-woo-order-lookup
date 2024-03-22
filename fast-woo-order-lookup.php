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

	private $initialized = false;

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
		add_filter( 'woocommerce_shop_order_search_fields', array( $this, 'filter_search_fields' ), 10, 1 );
		add_filter( 'woocommerce_shop_subscription_search_fields', array( $this, 'filter_search_fields' ), 10, 1 );
		add_filter( 'woocommerce_shop_order_search_results', array( $this, 'filter_search_results' ), 10, 3 );
		add_filter( 'woocommerce_shop_subscription_search_results', array( $this, 'filter_search_results' ), 10, 3 );

		$dir = plugin_dir_path( __FILE__ );
		$dir = '/home/ollie/src/fast-woo-order-lookup/';  //TODO
		require_once( $dir . 'code/class-textdex.php' );
		$this->textdex = new Textdex();
		$this->textdex->activate();
		$this->textdex->doAllBatches();


		$this->initialized = true;
	}

	/**
	 * Filter. immediately before metadata search.
	 *
	 * @param array $search_fields
	 *
	 * @return array
	 */
	public function filter_search_fields( $search_fields ) {
		if ( ! $this->initialized ) {
			return $search_fields;
		}
		/* Hook to mung the queries. */
		add_filter( 'query', array( $this, 'query' ), 1, 1 );

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

		/* Discontinue filtering queries after the metadata search */
		remove_filter( 'query', array( $this, 'query' ), 1 );

		return $order_ids;
	}

	/**
	 * Filters the database query.
	 *
	 * Mung the order metadata search queries to change LIKE '%whatever%' to LIKE 'whatever%'
	 * to make them sargable.
	 *
	 * @param string $query Database query.
	 *
	 * @return string
	 */
	public function query( $query ) {

		return str_replace(
			array( '.meta_value LIKE \'%', 'WHERE order_item_name LIKE \'%', '.user_mail LIKE \'%' ),
			array( '.meta_value LIKE \'', 'WHERE order_item_name LIKE \'', '.user_mail LIKE \'' ),
			$query );
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

	require_once( plugin_dir_path( __FILE__ ) . 'code/class-textdex.php.' );
	$textdex = new Textdex();
	$textdex->deactivate();
}

function uninstall() {

}
