<?php
/**
 * Plugin Name: Uber Direct Delivery
 * Description: A delivery system for Woocommerce shops. Uses Uber Direct API.
 * Version Date: 04 Oct 2023
 * Version: 1.0.0
 * Author: A. Ali
 * Text Domain: ilaa-uber-direct-delivery
 * Domain Path: /lang/
 */

if ( ! defined( 'ABSPATH' ) ) {exit;} /* Exit if accessed directly */

	
/**
 * Localisation
 **/
load_plugin_textdomain( 'ilaa-uber-direct-delivery', false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );


final class Woocommerce_uberdirect_init {
	
	private static $instance = null;
	private $orders = null;
	
	public static function initialize() {
		if ( is_null( self::$instance ) ){
			self::$instance = new self();
		}

		return self::$instance;
	}
	
	public function __construct() {
		
		// called after all plugins have loaded
		add_action( 'plugins_loaded', 				 array( $this, 'plugins_loaded' ) );
		
		/* Registering Ajax functionality to get OAuth Token from Uber API */
		add_action( 'wp_ajax_requestOAuthToken', 		 array( $this, 'wc_uberdirect_requestOAuthToken_callback' ) );

		/* Registering Ajax functionality to get Delivery estimates from Uber */
		add_action( 'wp_ajax_requestDelivery', 		 array( $this, 'wc_uberdirect_requestDelivery_callback' ) );
		add_action( 'wp_ajax_nopriv_requestDelivery', array( $this, 'wc_uberdirect_requestDelivery_callback' ) );

		add_action( 'wp_ajax_requestDeliveryQuoteID', array( $this, 'wc_uberdirect_requestDeliveryQuoteID_callback' ) );
		add_action( 'wp_ajax_saveUpdatedData', array( $this, 'wc_uberdirect_saveUpdatedData_callback' ) );
		
		
		add_action( 'admin_enqueue_scripts', 		 array( $this, 'wc_uberdirect_register_plugin_scripts_and_styles' ) );

		/**
		 * experimental!!
		 * not in use yet.
		 */
		add_shortcode('ilaa-get-orders', array($this, 'ilaa_displaying_orders_details_on_frontend'));
		add_action( 'woocommerce_after_register_post_type', function() {
				$this->orders = wc_get_orders( array( 'numberposts' => -1 ) );
		});
		
		/**
		 * The function attached here will call ajax to setup the delivery by using the saved quote id.
		 * 
		 * woocommerce_thankyou action hook can also be used but that would need thank you page be loaded. Use ilaa_thankyou_setup_delivery() in 
		 * this case instead of wc_uberdirect_requestDelivery
		 */
		//add_action( 'woocommerce_pre_payment_complete', array($this, 'wc_uberdirect_requestDelivery') );
		add_action( 'woocommerce_thankyou', array($this, 'ilaa_thankyou_setup_delivery') );

		/**
		 * Creates custom checkout field and populates it with delivery quote id from session.
		 * delivery quote id has been saved in session by the "calculate_shipping()" method of WC_aali_uber_direct_delivery_method in 
		 * class-uber-direct-delivery.php
		 */
		add_filter('woocommerce_checkout_fields', function($fields) {

			$ilaa_delivery_quote_id = WC()->session->get('ilaa_delivery_quote_id');
			// Add the custom field to the checkout form.
			$fields['billing']['ilaa_delivery_quote_id'] = array(
				'label' => 'Delivery Quote ID',
				'type' => 'text',
				'placeholder' => 'Delivery quote id',
				'required' => false,
				'default' => $ilaa_delivery_quote_id,
			);

			return $fields;
		});

		add_action('woocommerce_checkout_process', function () {

			global $woocommerce;

			// Check if set, if its not set add an error. This one is only requite for companies

			if ( ! (preg_match('/^\+[0-9]{11}$/', $_POST['billing_phone'] ))){

				wc_add_notice( "Incorrect Phone Number! Please enter valid 11 digits phone number, followed by '+'"  ,'error' );

			}

		});

		/**
		 * Saving the custom checkout field (delivery quote in this case) in database
		 */
		add_action( 'woocommerce_checkout_update_order_meta', function ( $order_id ) {
			if ( ! empty( $_POST['ilaa_delivery_quote_id'] ) ) {
				update_post_meta( $order_id, '_ilaa_delivery_quote_id', sanitize_text_field( $_POST['ilaa_delivery_quote_id'] ) );
			}
		});

		/**
		 * Displaying the Delivery ID on the admin order edition page
		 */
		add_action( 'woocommerce_admin_order_data_after_billing_address', function ($order){
			echo '<p><strong>'.__('Delivery Quote ID').':</strong> ' . get_post_meta( $order->id, '_ilaa_delivery_quote_id', true ) . '</p>';
			echo '<p><strong>'.__('Delivery ID').':</strong> ' . get_post_meta( $order->id, '_ilaa_delivery_api_ret_id', true ) . '</p>';
			echo '<p><strong>'.__('Tracking URL').':</strong> ' . get_post_meta( $order->id, '_ilaa_delivery_api_ret_tracking_url', true ) . '</p>';
		}, 10, 1);

		/**
		 * Create set up delivery button in action column on orders list admin page
		 */
		add_action( 'woocommerce_admin_order_actions_start', array( $this, 'wc_uberdirect_add_content_to_wc_actions_column' ));

		/**
		 * Create hidden admin page which is displayed when set up delivery button is clicked on
		 * orders list admin page.
		 */
		add_action( 'admin_menu', function() {
			add_submenu_page(
				null,
				__( 'Setup Delivery', 'ilaa-uber-direct-delivery' ),
				__( 'Setup Delivery', 'ilaa-uber-direct-delivery' ),
				'manage_woocommerce',
				'setup-delivery',
				array($this, 'wc_uberdirect_setup_delivery')
			);
		} );
	}

	/**
	 * Take care of anything that needs all plugins to be loaded
	 */
	public function plugins_loaded() {

		/*if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}*/

		/**
		 * Add the delivery method to WooCommerce
		 */
		require_once( plugin_basename( 'class-uber-direct-delivery.php' ) );
		add_filter( 'woocommerce_shipping_methods', array( $this, 'ilaa_add_uber_direct_delivery_method') );

	}
	
	/**
	 * Registering the plugin backend class to woocommerce shipping methods.
	 */
	public function ilaa_add_uber_direct_delivery_method( $methods ) {

		$methods['aali_uber_direct_delivery_method'] = 'WC_aali_uber_direct_delivery_method';
		return $methods;
	}
	
	/**
	 * test function!!
	 * not used yet.
	 */
	function ilaa_displaying_orders_details_on_frontend(){
		if (!empty($this->orders)) {
			foreach ( $this->orders as $order ) {
				//var_dump($order->get_items());
				var_dump($order->get_id());
				var_dump($order->get_date_created()->date('d-M-Y'));
				var_dump($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() . ' ' . $order->get_shipping_city() . ' ' . $order->get_shipping_country());
				var_dump($order->get_formatted_order_total());
			}
		}

//		var_dump( $this->orders );
	}

	/**
	 * Creates form to setup Uber delivery manually. 
	 * The form uses ajax which uses callback function 'wc_uberdirect_requestDelivery_callback' of this class.
	 */
	function wc_uberdirect_setup_delivery() {

		$order_id = $_GET['orderid'];

		// order object connected with this id
		$order = wc_get_order( $order_id );

		// Get the shipping method settings.
		$shipping_method_settings = get_option('woocommerce_aali_uber_direct_delivery_method_settings');
		$auto_delivery_enabled = $shipping_method_settings['auto_delivery_enabled'];

		// Get the pickup address field value.
		$quote_id = get_post_meta( $order->id, '_ilaa_delivery_quote_id', true );
		$pickup_address = $shipping_method_settings['pickup_address'];

		$dropoff_address = $order->get_billing_address_1();
		$dropoff_address2 = $order->get_billing_address_2();
		$dropoff_address_city = $order->get_billing_city();
		$dropoff_address_state = $order->get_billing_state(); 
		$dropoff_address_pobox = $order->get_billing_postcode(); 
		$dropoff_address_country = $order->get_billing_country();

		$dropoff_first_name = $order->get_billing_first_name();
		$dropoff_last_name = $order->get_billing_last_name();

		$dropoff_phone_number = $order->get_billing_phone();
		$dropoff_notes = $order->get_customer_note();

		//echo (json_encode( $order->get_data() ));
		
		/**
		 * Getting new quote id
		 */
		//$new_quote_id = ilaa_get_delivery_quote_id($pickup_address, $dropoff_address);

		?>

		<div class="wrap woocommerce">
		<div class="update-nag notice notice-warning inline" id="ilaa-delivery-setup-page"></div>
		<br class="clear" />
		<h1 class="">Dayline Direct Delivery</h1>
		<br class="clear" />
		<h2>Setup Delivery</h2>
		<p>This page uses a Direct API to setup the delivery for the order.</p>
		<form method="post" id="mainform" action="" enctype="multipart/form-data">

			<table class="form-table" id="ilaa-setup-delivery-form">		
			<tbody>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="woocommerce_aali_uber_direct_delivery_dropoff_address">Drop off Address1</label>
					</th>
					<td class="forminp">
						<fieldset>
							<legend class="screen-reader-text"><span>Drop off Address1</span></legend>
							<input type="text" 
								   id="woocommerce_aali_uber_direct_delivery_dropoff_address" 
								   class="input-text regular-input " 
								   name="woocommerce_aali_uber_direct_delivery_dropoff_address" 
								   style="width:400px;" 
								   value="<?php echo $dropoff_address; ?>" placeholder="Drop off Address">
						</fieldset>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="woocommerce_aali_uber_direct_delivery_dropoff_address2">Drop off Address2</label>
					</th>
					<td class="forminp">
						<fieldset>
							<legend class="screen-reader-text"><span>Drop off Address2</span></legend>
							<input type="text" 
								   id="woocommerce_aali_uber_direct_delivery_dropoff_address2" 
								   class="input-text regular-input " 
								   name="woocommerce_aali_uber_direct_delivery_dropoff_address2" 
								   style="width:400px;" 
								   value="<?php echo $dropoff_address2; ?>" placeholder="Drop off Address2">
						</fieldset>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="woocommerce_aali_uber_direct_delivery_dropoff_city">Dropoff City</label>
					</th>
					<td class="forminp">
						<fieldset>
							<legend class="screen-reader-text"><span>Dropoff City</span></legend>
							<input type="text" 
								   id="woocommerce_aali_uber_direct_delivery_dropoff_city" 
								   class="input-text regular-input " 
								   name="woocommerce_aali_uber_direct_delivery_dropoff_city" 
								   style="width:400px;" 
								   value="<?php echo $dropoff_address_city; ?>" placeholder="Dropoff City">
						</fieldset>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="woocommerce_aali_uber_direct_delivery_dropoff_state">Dropoff State</label>
					</th>
					<td class="forminp">
						<fieldset>
							<legend class="screen-reader-text"><span>Dropoff State</span></legend>
							<input type="text" 
								   id="woocommerce_aali_uber_direct_delivery_dropoff_state" 
								   class="input-text regular-input " 
								   name="woocommerce_aali_uber_direct_delivery_dropoff_state" 
								   style="width:400px;" 
								   value="<?php echo $dropoff_address_state; ?>" placeholder="Dropoff State">
						</fieldset>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="woocommerce_aali_uber_direct_delivery_dropoff_pobox">Dropoff P.O. Box</label>
					</th>
					<td class="forminp">
						<fieldset>
							<legend class="screen-reader-text"><span>Dropoff P.O. Box</span></legend>
							<input type="text" 
								   id="woocommerce_aali_uber_direct_delivery_dropoff_pobox" 
								   class="input-text regular-input " 
								   name="woocommerce_aali_uber_direct_delivery_dropoff_pobox" 
								   style="width:400px;" 
								   value="<?php echo $dropoff_address_pobox; ?>" placeholder="Dropoff P.O. Box">
						</fieldset>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="woocommerce_aali_uber_direct_delivery_dropoff_country">Dropoff Country</label>
					</th>
					<td class="forminp">
						<fieldset>
							<legend class="screen-reader-text"><span>Drop off Address</span></legend>
							<input type="text" 
								   id="woocommerce_aali_uber_direct_delivery_dropoff_country" 
								   class="input-text regular-input " 
								   name="woocommerce_aali_uber_direct_delivery_dropoff_country" 
								   style="width:400px;" 
								   value="<?php echo $dropoff_address_country; ?>" placeholder="Dropoff Country">
						</fieldset>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="woocommerce_aali_uber_direct_delivery_dropoff_first_name">Dropoff First Name</label>
					</th>
					<td class="forminp">
						<fieldset>
							<legend class="screen-reader-text"><span>Dropoff First Name</span></legend>
							<input type="text" 
								   id="woocommerce_aali_uber_direct_delivery_dropoff_first_name" 
								   class="input-text regular-input " 
								   name="woocommerce_aali_uber_direct_delivery_dropoff_first_name" 
								   style="width:400px;" 
								   value="<?php echo $dropoff_first_name; ?>" placeholder="Dropoff First Name">
						</fieldset>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="woocommerce_aali_uber_direct_delivery_dropoff_last_name">Dropoff Last Name</label>
					</th>
					<td class="forminp">
						<fieldset>
							<legend class="screen-reader-text"><span>Dropoff Last Name</span></legend>
							<input type="text" 
								   id="woocommerce_aali_uber_direct_delivery_dropoff_last_name" 
								   class="input-text regular-input " 
								   name="woocommerce_aali_uber_direct_delivery_dropoff_last_name" 
								   style="width:400px;" 
								   value="<?php echo $dropoff_last_name; ?>" placeholder="Dropoff Last Name">
						</fieldset>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="woocommerce_aali_uber_direct_delivery_dropoff_phone_number">Dropoff Phone Number</label>
					</th>
					<td class="forminp">
						<fieldset>
							<legend class="screen-reader-text"><span>Dropoff Phone Number</span></legend>
							<input type="tel" 
								   id="woocommerce_aali_uber_direct_delivery_dropoff_phone_number" 
								   class="input-text regular-input " 
								   name="woocommerce_aali_uber_direct_delivery_dropoff_phone_number" 
								   style="width:400px;" 
								   pattern="[+1][0-9]{3}[0-9]{3}[0-9]{4}"
								   maxlength="12"
								   placeholder="+12342342345"  
								   value="<?php echo $dropoff_phone_number; ?>" 
								   >
						</fieldset>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="woocommerce_aali_uber_direct_delivery_dropoff_notes">Dropoff Note</label>
					</th>
					<td class="forminp">
						<fieldset>
							<legend class="screen-reader-text"><span>Dropoff Note</span></legend>
							<textarea
								   id="woocommerce_aali_uber_direct_delivery_dropoff_notes" 
								   class="input-text regular-input " 
								   name="woocommerce_aali_uber_direct_delivery_dropoff_notes" 
								   style="min-width: 50%; height: 75px;" 
								   placeholder="Dropoff Note"><?php echo $dropoff_notes; ?></textarea>
						</fieldset>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="woocommerce_aali_uber_direct_delivery_quote_id">Delivery Quote ID</label>
					</th>
					<td class="forminp">
						<fieldset>
							<legend class="screen-reader-text"><span>Quote ID</span></legend>
							<input type="text" 
								   id="woocommerce_aali_uber_direct_delivery_quote_id" 
								   class="input-text regular-input " 
								   name="woocommerce_aali_uber_direct_delivery_quote_id" 
								   style="width:400px;" 
								   value="<?php echo $quote_id; ?>" placeholder="Quote ID">
						</fieldset>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="woocommerce_aali_uber_direct_delivery_quote_id"></label>
					</th>
					<td>
						<button name="new_quote" id="woocommerce_aali_uber_direct_delivery_new_quote" class="button-primary woocommerce-save-button" type="submit" value="Setup delivery">Setup delivery</button>
					</td>
				</tr>

			</tbody>
			</table>
			<p class="submit">
				<input type="hidden" id="woocommerce_aali_uber_direct_delivery_orderid" name="woocommerce_aali_uber_direct_delivery_orderid" value="<?php echo $order_id ?>">
				<input type="hidden" id="woocommerce_aali_uber_direct_delivery_pickup_address" name="woocommerce_aali_uber_direct_delivery_pickup_address" value="<?php echo $pickup_address ?>">
			</p>
		</form>
		</div>

		<?php
	}

	/**
	 * Add buttons to Action column on orders' page in Admin panel.
	 */
	function wc_uberdirect_add_content_to_wc_actions_column( $order ) {

		$delivery_id = get_post_meta( $order->id, '_ilaa_delivery_api_ret_id', true );
		if (empty($delivery_id)) {
			// create some tooltip text to show on hover
			$tooltip = __('Setup delivery.', 'ilaa-uber-direct-delivery');

			// create a button label
			$label = __('Setup Delivery', 'ilaa-uber-direct-delivery');
		
			echo '<a class="button tips uber-delivery-button-class" href="' . get_site_url() . '/wp-admin?page=setup-delivery&orderid=' . $order->get_id() . '" data-tip="'.$tooltip.'">'.$label.'</a>';
		}

	}

	/**
	 *  Method to handle AJAX calls 
	 *  This Method is called for getting OAuth Token from uber api
	 */
	public function wc_uberdirect_requestOAuthToken_callback() {
		$client_id = $_POST['clientID'];
		$client_secret = $_POST['clientSecret'];

		if (empty($client_id) || empty($client_secret)) {
			echo "ERROR";
			die;
		}

		$url = 'https://login.uber.com/oauth/v2/token';
		$data = array(
			'client_id' => $client_id,
			'client_secret' => $client_secret,
			'grant_type' => 'client_credentials',
			'scope' => 'eats.deliveries',
		);

		$response = wp_remote_post($url, array(
			'body' => $data,
		));

		if (!is_wp_error($response) && $response['response']['code'] === 200) {
			$body = json_decode($response['body']);
			echo $body->access_token;
			die;
		}
	}


	/**
	 * Sets up delivery by calling API on the Thank you page after the checkout is completed.
	 * 
	 * @access public
	 * @param $order_id
	 * @return void
	 */
	function ilaa_thankyou_setup_delivery($order_id) {

		// Get the shipping method settings.
		$shipping_method_settings = get_option('woocommerce_aali_uber_direct_delivery_method_settings');

		// Get the value of checkbox to enable automatic delivery setup.
		$auto_delivery_enabled = $shipping_method_settings['auto_delivery_enabled'];

		if($auto_delivery_enabled === 'yes'){
			$ajaxurl = admin_url('admin-ajax.php');
			?>
			<script>

				jQuery.ajax({
					url: '<?php echo $ajaxurl; ?>',
					type: 'post',
					data: {
						action: 'requestDelivery',
						orderid: '<?php echo $order_id; ?>'
					},
					success: function(response) {
						//alert( response );
						const msgContainer = jQuery( ".wp-block-group" ).find( ".woocommerce-thankyou-order-received" );
						//msgContainer.append(response);
						msgContainer.append( "<br/><span style='font-size:18px;'>To track your order please click <a href='" + response + "' target='_blank'>here</a></span>" );
					}
				});
			</script>
			<?php
		}
	}

	/**
	 * A callback method to handle Ajax calls
	 * This method is called when an ajax uses action: requestDelivery
	 */
	public function wc_uberdirect_requestDelivery_callback(){
		$res = '';

		$orderid = $_POST['orderid'];
		
		$res = $this->wc_uberdirect_requestDelivery($orderid);

		echo $res;die;

	}

	/**
	 * Non-ajax version of wc_uberdirect_requestDelivery_callback() function.
	 * can be called directly in php script
	 */
	public function wc_uberdirect_requestDelivery($orderid){
		
		$quote_id = get_post_meta($orderid, '_ilaa_delivery_quote_id', true);

		// order object connected with this id
		$order = wc_get_order( $orderid );
	
		// Get the shipping method settings.
		$shipping_method_settings = get_option('woocommerce_aali_uber_direct_delivery_method_settings');

		// Get the pickup address field value.
		$pickup_address = $shipping_method_settings['pickup_address'];
		//$dropoff_address = $order->get_formatted_billing_address();
		$dropoff_address = $order->get_billing_address_1() . ', ' . 
							$order->get_billing_address_2() . ', ' . 
							$order->get_billing_city() . ', ' . 
							$order->get_billing_state() . ', ' . 
							$order->get_billing_postcode() . ', ' . 
							$order->get_billing_country();

		$dropoff_full_name = $order->get_formatted_billing_full_name();
		$dropoff_phone_number = $order->get_billing_phone();
		$dropoff_notes = $order->get_customer_note();

		$api_customer_id = $shipping_method_settings['Customer_ID'];
		$api_oauth_token = $shipping_method_settings['OAuth_Token'];

		// Define the request URL.
		$request_url = 'https://api.uber.com/v1/customers/'. $api_customer_id .'/deliveries';

		// Define the request headers.
		$request_headers = array(
			'Content-Type' => 'application/json',
			'Authorization' => 'Bearer ' . $api_oauth_token,
		);

		$now = date_create();
		// Add 15 minutes to the current datetime.
		$datetime = $now->add(new DateInterval('PT15M'));
		// Format the datetime in RFC3339 format.
		$rfc3339_datetime = $datetime->format(DateTime::RFC3339);

		// Define the request body.
		$request_body = array(
			'quote_id' => $quote_id,
			'pickup_address' => $pickup_address,
			'pickup_name' => 'Dayline Delivery',
			'pickup_phone_number' => '4444444444',
			'dropoff_address' => $dropoff_address,
			'dropoff_name' => $dropoff_full_name,
			'dropoff_phone_number' => $dropoff_phone_number,
			'dropoff_notes' => $dropoff_notes,
			'manifest_items' => array(
				array(
					'name' => 'Food',
					'quantity' => 1,
					'weight' => 500,
					'dimensions' => array(
						'length' => 40,
						'height' => 40,
						'depth' => 40
					)
				)
			),
			'pickup_ready_dt' => $rfc3339_datetime,
		);
		//return json_encode($request_body);

		// Make the request using wp_remote_post().
		$response = wp_remote_post($request_url, array(
			'headers' => $request_headers,
			'body' => json_encode($request_body)
		));
		//return json_decode($response['body'])->message;

		if (!is_wp_error($response)) {
			if($response['response']['code'] === 200){
				$body = json_decode($response['body']);

				update_post_meta( $orderid, '_ilaa_delivery_api_ret_id', $body->id );
				update_post_meta( $orderid, '_ilaa_delivery_api_ret_tracking_url', $body->tracking_url );
				update_post_meta( $orderid, '_ilaa_delivery_api_ret_pickup_eta', $body->pickup_eta );
				update_post_meta( $orderid, '_ilaa_delivery_api_ret_response_comp', json_encode($body) );

				/** return is needed for ajax calls. */
				return $body->tracking_url;
			} else {
				switch($response['response']['code']){
					case 400:
						return "ERROR: " . $response['response']['code'] . ", " . json_decode($response['body'])->message . ", Possibaly phone number format is incorrect!";
						break;
					default:
						return "ERROR: " . $response['response']['code'] . ", " . json_decode($response['body'])->message;
				}
			}
		} else {
			update_post_meta( $orderid, '_ilaa_delivery_api_ret_error', 'Error: ' . $response->get_error_message() );

			/** return is needed for ajax calls. */
			return $response->get_error_message();
		}

	}

	/**
	 * Callback method to handle ajax call to get new quote id
	 */
	public function wc_uberdirect_requestDeliveryQuoteID_callback(){
		$res = '';

		$pickup_address = $_POST['pickupAddress'];
		$dropoff_address = $_POST['dropoffAddress'];

		$res = $this->ilaa_get_delivery_quote_id($pickup_address, $dropoff_address);
		echo $res;die;
	}

	/**
	 * get quote id
	 */
	function ilaa_get_delivery_quote_id($pickup_address, $dropoff_address){
		$data = array(
			'dropoff_address' => $dropoff_address,
			'pickup_address' => $pickup_address,
		);

		// Get the shipping method settings.
		$shipping_method_settings = get_option('woocommerce_aali_uber_direct_delivery_method_settings');

		$customerId = $shipping_method_settings['Customer_ID'];
		if($customerId == NULL){return "ERROR";}

		$token = $shipping_method_settings['OAuth_Token'];
		if($token == NULL){return "ERROR";}


		$api_url = 'https://api.uber.com/v1/customers/' . $customerId . '/delivery_quotes';
		$headers = array(
			'Content-Type' => 'application/json',
			'Authorization' => 'Bearer ' . $token,
		);
		
		$response = wp_remote_post(
			$api_url,
			array(
				'headers' => $headers,
				'body' => json_encode($data),
			)
		);

		$cost = '';

		if (!is_wp_error($response)) {
			
			if($response['response']['code'] == '200'){
				$res_body = json_decode( wp_remote_retrieve_body( $response ), true );
				$cost = $res_body['id'];
			} else {
				//wp_remote_post() succeeded but error returned by the server.
				return "ERROR";
				//$cost =  'Error code: ' . $response['response']['code'];
			}
		} else {
			//if error occured in calling wp_remote_post() method.
			return "ERROR";
			//$cost =  'Error: ' . $response->get_error_message(); 
		}

		return $cost;
	}

	/**
	 * ajax callback function to save data from setup delivery page.
	 */
	function wc_uberdirect_saveUpdatedData_callback(){

		$orderid = $_POST["orderid"];
		$quoteid = $_POST["quoteid"];

		$order = wc_get_order( $orderid );

		$dropoffAddress = $_POST["dropoffAddress"];
		$dropoffAddressCity = $_POST["dropoffAddressCity"];
		$dropoffAddressState = $_POST["dropoffAddressState"];
		$dropoffAddressPobox = $_POST["dropoffAddressPobox"];
		$dropoffAddressCountry = $_POST["dropoffAddressCountry"];

		$dropofffName = $_POST["dropofffName"];
		$dropofflName = $_POST["dropofflName"];

		$dropoffPhone = $_POST["dropoffPhone"];
		$dropoffNote = $_POST["dropoffNote"];

		update_post_meta($orderid, '_ilaa_delivery_quote_id', $quoteid);

		update_post_meta($orderid, '_billing_address_1', $dropoffAddress);
		update_post_meta($orderid, '_shipping_address_1', $dropoffAddress);

		update_post_meta($orderid, '_billing_city', $dropoffAddressCity);
		update_post_meta($orderid, '_shipping_city', $dropoffAddressCity);

		update_post_meta($orderid, '_billing_state', $dropoffAddressState);
		update_post_meta($orderid, '_shipping_state', $dropoffAddressState);

		update_post_meta($orderid, '_billing_postcode', $dropoffAddressPobox);
		update_post_meta($orderid, '_shipping_postcode', $dropoffAddressPobox);

		update_post_meta($orderid, '_billing_country', $dropoffAddressCountry);
		update_post_meta($orderid, '_shipping_country', $dropoffAddressCountry);
		
		update_post_meta($orderid, '_billing_first_name', $dropofffName);
		update_post_meta($orderid, '_billing_last_name', $dropofflName);
		
		update_post_meta($orderid, '_billing_phone', $dropoffPhone);

		$order->set_customer_note( $dropoffNote );
		$order->save();

		//echo (json_encode( $order->get_data() ));die;

		echo "UPDATED";die;

	}

	/**
	 *   Register style sheet and Javascript files
	 */
	public function wc_uberdirect_register_plugin_scripts_and_styles() {
		wp_register_style( 'woocommerce-uber-direct-delivery-css', plugins_url( 'css/style.css' , __FILE__ ) );
		wp_enqueue_style( 'woocommerce-uber-direct-delivery-css' );

		wp_register_script( 'woocommerce-uber-direct-delivery-js', plugins_url( 'js/ila-oow.js' , __FILE__ ) );
		wp_enqueue_script( 'woocommerce-uber-direct-delivery-js' );
		wp_localize_script( 'woocommerce-uber-direct-delivery-js', 'ilaa_ajax_object',
			array( 
				'url' => get_bloginfo( 'url' ),
			)
		);

		// Check if the current page is the orders page, reload it every 30 seconds.
		if (is_admin() && get_post_type() === 'shop_order') {
		  // Add the JS to the page.
			wp_register_script( 'ila_reload_orders_page', plugins_url( 'js/ila_reload_orders_page.js' , __FILE__ ) );
			wp_enqueue_script( 'ila_reload_orders_page' );
		}
	}

}

$GLOBALS['Woocommerce_uberdirect_init'] = Woocommerce_uberdirect_init::initialize();

