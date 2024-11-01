<?php
/**
 * Plugin Name: Airbuy | Paying online without banking details
 * Description: Making easy online payments without your debit or credit card details.
 * Author: Airbuy
 * Author URI: https://www.airbuy.africa/business-woocommerce-setup-desktop
 * Version: 2.0.0
 * Text Domain: wc-airbuy-gateway
 * Domain Path: /i18n/languages/
 *
 * Copyright: (c) 2016-2022 Airbuy (info@airbuy.africa) and WooCommerce
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   WC-Airbuy-Gateway
 * @author    Airbuy
 * @category  Admin
 * @copyright Copyright (c) 2016-2022, Airbuy and WooCommerce
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 *
 * Online consumers can now buy online without the need of their banking details.
 */
 
defined( 'ABSPATH' ) or exit;

// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}


/**
 * Add the gateway to WC Available Gateways
 * 
 * @since 1.0.0
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + Airbuy gateway
 */
function wc_airbuy_add_to_gateways( $gateways )
{
	$gateways[] = 'WC_Airbuy_Gateway';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'wc_airbuy_add_to_gateways' );


/**
 * Adds plugin page links
 * 
 * @since 1.0.0
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
function wc_airbuy_gateway_plugin_links( $links ): array
{

	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=airbuy_gateway' ) . '">' . __( 'Configure', 'wc-airbuy-gateway' ) . '</a>'
	);

	return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_airbuy_gateway_plugin_links' );


/**
 * Pay online without any banking details
 *
 *
 * @class 		WC_Airbuy_Gateway
 * @extends		WC_Payment_Gateway
 * @version		1.1.3
 * @package		WooCommerce/Classes/Payment
 * @author 		Airbuy
 */
add_action( 'plugins_loaded', 'wc_airbuy_gateway_init', 11 );
global $wp_version;
function wc_airbuy_gateway_init() {
		
	class WC_Airbuy_Gateway extends WC_Payment_Gateway {

		/**
		 * Constructor for the gateway.
		 */

		public function __construct() {
			$plugin_dir = plugin_dir_url(__FILE__);
			
			$this->id                 = 'airbuy_gateway';
			$this->icon               = 'https://airbuy.africa/static/dashboard/img/logos/airbuy.png';
			$this->has_fields         = false;
			$this->supports           = array(
				'refunds',
			);
			$this->method_title       = __( 'Airbuy', 'wc-airbuy-gateway' );
			$this->method_description = __( "Caters for online payments without card details. Opens you up to customers without bank details as well as insecure online buyers who are not comfortable revealing their bank details online", 'wc-airbuy-gateway' );
			
			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();
			
			// Define user set variables
			$this->title        = $this->get_option( 'title' );
			$this->description  = $this->get_option( 'description' );
			$this->instructions = $this->get_option( 'instructions', $this->description );
			$this->merchant_id  = $this->get_option( 'merchant_id' );
			
			$this->my_custom_url_handler();
			
			// Actions
			add_action('init', 'my_custom_url_handler', 9);
			
			if( version_compare(get_bloginfo('version'), '2.0.0', '>=' )){
				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			}
			else{
				add_action( 'woocommerce_update_options_payment_gateways' , array( &$this, 'process_admin_options' ) );
			}
			add_action( 'woocommerce_receipt_airbuy' . $this->id, array( $this, 'receipt_page' ) );
			// Customer Emails
			add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
		}
		
		
		function my_custom_url_handler(){

			if (!empty( $_POST )){

				if($_POST['id'] && $_POST['success'] && $_POST['message']){
					$order = wc_get_order( $_POST['id'] );

					if( $_POST['success'] == 'False'){
						if(!str_contains($_POST['message'], 'Payment with same ID is already completed.'))
							$order->update_status( 'failed', __( $_POST['message'], 'wc-airbuy-gateway' ) );
					}
					else {

						if(!$order->has_status( 'processing' )){
							// Reduce stock levels
							if( version_compare(get_bloginfo('version'), '3.0', '>=' ))
								wc_reduce_stock_levels( $_POST['id'] );
							else
								$order->reduce_order_stock();
						}

						$order->update_status( 'processing', __( $_POST['message'], 'wc-airbuy-gateway' ) );

						// Remove cart
						WC()->cart->empty_cart();

					}

					$data_more = array(
						"from" => 'woocommerce-gateway',
						"status" => 200,
						"order_status" => $order->has_status( 'processing' )
					);
					$data = array_merge($data_more, $_POST);

					/*
					// deprecated - not working on all themes consistently
					$options = array(
						'http' => array(
							'method'  => 'POST',
							'content' => http_build_query($data)
						)
					);
					$context  = stream_context_create($options);

					$response = file_get_contents('https://airbuy.africa/api/payment-result/', false, $context);
					*/

					$args = array(
						'method' => 'POST',
						'timeout' => 45,
						'sslverify' => false,
						'headers' => array(
							'Content-Type' => 'application/json',
							'Accept' => 'application/json',
						),
						'body' => json_encode($data),
					);

					$response = wp_remote_post('https://airbuy.africa/api/payment-result/', $args);

					do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() );

				}

			}

		}

		/**
		 * Initialize Gateway Settings Form Fields
		 */
		public function init_form_fields() {

			$this->form_fields = array(

				'enabled' => array(
					'title'   => __( 'Enable/Disable', 'wc-airbuy-gateway' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable Airbuy Payment', 'wc-airbuy-gateway' ),
					'default' => 'yes'
				),

				'title' => array(
					'title'       => __( 'Title', 'wc-airbuy-gateway' ),
					'type'        => 'text',
					'description' => __( 'This controls the title for the payment method the customer sees during checkout.', 'wc-airbuy-gateway' ),
					'default'     => __( 'Pay online without your banking details', 'wc-airbuy-gateway' ),
					'desc_tip'    => true,
				),

				'description' => array(
					'title'       => __( 'Description', 'wc-airbuy-gateway' ),
					'type'        => 'textarea',
					'description' => __( 'Payment method description that the customer will see on your checkout.', 'wc-airbuy-gateway' ),
					'default'     => __( 'Buy online without any banking details', 'wc-airbuy-gateway' ),
					'desc_tip'    => true,
				),

				'instructions' => array(
					'title'       => __( 'Instructions', 'wc-airbuy-gateway' ),
					'type'        => 'textarea',
					'description' => __( 'Instructions that will be added to the thank you page and emails.', 'wc-airbuy-gateway' ),
					'default'     => __( 'Pay online without any banking details', 'wc-airbuy-gateway' ),
					'desc_tip'    => true,
				),

				'merchant_id' => array(
					'title'       => __( 'API Key', 'wc-airbuy-gateway' ),
					'type'        => 'password',
					'description' => __( 'Your API Key is necessary for identifying your store. Log into your Airbuy account to locate it.' ),
				),
			 );
		}


		/**
		 * Output for the order received page.
		 */
		public function thankyou_page() {
			if ( $this->instructions ) {
				echo wpautop( wptexturize( $this->instructions ) );
			}
		}


		/**
		 * Add content to the WC emails.
		 *
		 * @access public
		 * @param WC_Order $order
		 * @param bool $sent_to_admin
		 * @param bool $plain_text
		 */
		public function email_instructions(WC_Order $order, bool $sent_to_admin, bool $plain_text = false ) {
		
			if ( $this->instructions && ! $sent_to_admin && $this->id === $order->get_payment_method() && $order->has_status( 'on-hold' ) ) {
				echo woocommerce - airbuy - gateway . phpwpautop(wptexturize($this->instructions));
			}
		}
	
	
		/**
		 * Process the payment and return the result
		 *
		 * @param int $order_id
		 * @return array
		 */
		public function process_payment( $order_id ): array
        {
			$shipping_count = 0;
	
			$order = wc_get_order( $order_id );
			
			
			try{
				
				$items = "";
				
				foreach( $order->get_items() as $item ){
					if( !empty($item) ){
						if($items=="")
							$items = "Product id: ".$item['product_id']." - ".$item['name'];
						else
							$items = $items .", Product id: ".$item['product_id']." - ".$item['name'];
					}
				}

				$data = array(
					"id" => $order_id,
					"api_key" => $this->merchant_id,
					"type" => "payment",
					"currency" => "R",
					"total_amount" => $order->get_total(),
					"items" => $items,
					"return_method" => "POST",
					"return_url" => $this->get_return_url( $order )
				);
				
				$signature = $this->generateSignature($data);
				$data['signature'] = $signature;

				/*
				// deprecated - not working on all themes consistently
				$options = array(
					'http' => array(
						'method'  => 'POST',
						'content' => http_build_query($data)
					)
				);
				$context  = stream_context_create($options);
				
				
				$response = file_get_contents('https://airbuy.africa/api/payment/', false, $context);
				$result = json_decode($response, true); //https://airbuy.africa/api/payment/
				*/
				
				$args = array(
					'method' => 'POST',
					'timeout' => 45,
					'sslverify' => false,
					'headers' => array(
						'Content-Type' => 'application/json',
						'Accept' => 'application/json',
					),
					'body' => json_encode($data),
				);
				
				$response = wp_remote_post('https://airbuy.africa/api/payment/', $args);
				$body     = wp_remote_retrieve_body( $response );
				$result   = json_decode($body, true);

				$order->update_status( 'awaiting process', __( 'Awaiting Airbuy payment', 'wc-airbuy-gateway' ) );
			
				// Remove cart
				WC()->cart->empty_cart();

				// Mark as on-hold (we're awaiting the payment)
				
				// Return thankyou redirect
				return array(
					'result' 	=> 'success',
					'redirect'	=> $result['complete_payment_url']
				);
			}
			catch( Exception $e ) {
				
				wc_add_notice(  $e, 'error' );
				return [];
			}
			
			
		}
		
		// Do your refund here. Refund $amount for the order with ID $order_id


		private function generateSignature($data): string
        {// Create parameter string
			$pfOutput = '';
			foreach( $data as $key => $val ) {
				if($val !== '') {
					$pfOutput .= $key .'='. urlencode( trim( $val ) ) .'&';
				}
			}
			// Remove last ampersand
			$getString = substr( $pfOutput, 0, -1 );
			if( null !== null ) {
				$getString .= '&passphrase='. urlencode( trim(null) );
			}
			return md5( $getString );
		}

	} // end - WC_Airbuy_Gateway class

}