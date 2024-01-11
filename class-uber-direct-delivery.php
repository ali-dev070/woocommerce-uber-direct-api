<?php
if ( ! defined( 'ABSPATH' ) ) {exit;} /* Exit if accessed directly */

if ( ! class_exists( 'WC_aali_uber_direct_delivery_method' ) ) {
    class WC_aali_uber_direct_delivery_method extends WC_Shipping_Method {
        /**
         * Constructor for Uber Direct Delivery class
         *
         * @access public
         * @return void
         */
        public function __construct() {
            $this->id                 = 'aali_uber_direct_delivery_method'; // Id for Uber Direct Delivery method. Should be uunique.
            $this->method_title       = __( 'Dayline Delivery Method', 'ilaa-uber-direct-delivery' );
            $this->method_description = __( 'Dayline Delivery method uses Direct API to calculate the delivery/shipping cost of the order.', 'ilaa-uber-direct-delivery' ); 

            $this->enabled            = "yes";
            $this->title              = __('Dayline Delivery', 'ilaa-uber-direct-delivery');

            $this->init();
        }

        /**
         * Init settings
         *
         * @access public
         * @return void
         */
        function init() {
            // Load the settings API
            $this->init_form_fields();
            $this->init_settings(); // This is part of the settings API. Loads settings you previously init.

            // Save settings in admin.
            add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );

        }

        /**
         * Init admin form fields
         * 
         * @access public
         * @return void
         */
        function init_form_fields() {
            $this->form_fields = array(
                'title' => array(
                    'title' => __('Title', 'ilaa-uber-direct-delivery'),
                    'type' => 'text',
                    'default' => __('Uber Direct Delivery Method', 'ilaa-uber-direct-delivery')
                ),
                'enabled' => array(
                    'title' => __('Enable', 'ilaa-uber-direct-delivery'),
                    'type' => 'checkbox',
                    'default' => 'yes'
                ),
                'auto_delivery_enabled' => array(
                    'title' => __('Enable Automatic Delivery Setup', 'ilaa-uber-direct-delivery'),
                    'type' => 'checkbox',
                    'default' => 'yes'
                ),
                'pickup_address' => array(
                    'title' => __('Pickup/Shop Address', 'ilaa-uber-direct-delivery'),
                    'type' => 'text',
                    'default' => ''
                ),
                'Customer_ID' => array(
                    'title' => __('Customer ID', 'ilaa-uber-direct-delivery'),
                    'type' => 'text',
                    'default' => ''
                ),
                'Client_ID' => array(
                    'title' => __('Client ID', 'ilaa-uber-direct-delivery'),
                    'type' => 'text',
                    'default' => ''
                ),
                'Client_Secret' => array(
                    'title' => __('Client Secret', 'ilaa-uber-direct-delivery'),
                    'type' => 'text',
                    'default' => ''
                ),
                'OAuth_Token' => array(
                    'title' => __('OAuth Token', 'ilaa-uber-direct-delivery'),
                    'type' => 'text',
                    'default' => '',
//                    'custom_attributes' => array('readonly' => 'readonly'),
                    'description' => __('OAuth Token expires in 30 days.', 'ilaa-uber-direct-delivery'),
                    'desc_tip' => true
                ),
                'OAuth_Token_Button' => array(
                    'title' => __('Get OAuth Token', 'ilaa-uber-direct-delivery'),
                    'type' => 'button',
                    'default' => 'Get Token',
                    'class' => 'get-oauth-token-button',
                    'custom_attributes' => array( 'style' => 'height:32px;width:100px;'),
                    'description'       => __( 'Click this button if new OAuth Token is needed. This will call the Uber API for this.', 'ilaa-uber-direct-delivery' ),
                    'desc_tip'          => true,
                ),

            );
        }//*/

        
        /**
         * calculate_shipping function.
         *
         * @access public
         * @param array $package
         * @return void
         */
        public function calculate_shipping( $package = array() ) {

            $billing_address_1 = WC()->checkout->get_value( 'billing_address_1' );
            $billing_address_2 = WC()->checkout->get_value( 'billing_address_2' );
            $billing_city = WC()->checkout->get_value( 'billing_city' );
            $billing_country = WC()->checkout->get_value( 'billing_country' );
            $billing_postcode = WC()->checkout->get_value( 'billing_postcode' );

            $dropoff_address = $billing_address_1 . ', ' . $billing_address_2 . ', ' . $billing_city . ', ' . $billing_postcode . ', ' . $billing_country;
            
            $data = array(
                'dropoff_address' => $dropoff_address,
                'pickup_address' => $this->get_option('pickup_address'),
            );

            $customerId = $this->get_option('Customer_ID');
            $token = $this->get_option('OAuth_Token');

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
            if (is_wp_error($response)) {
                // if no response on frontend, check the contents of $erro below.
                $error =  'Error: ' . $response->get_error_message(); 
            } else {
                $cost = json_decode( wp_remote_retrieve_body( $response ), true );
            }

            $quote_id = $cost['id'];
            // Set the custom field value in the checkout session.
            WC()->session->set('ilaa_delivery_quote_id', $quote_id);

            $rate = array(
                'id' => $this->id . ':' . $quote_id,
                'label' => $this->title,
//                'cost' => strtoupper($cost["currency"]) . ' ' . number_format($cost["fee"]/100,2),
                'cost' => $cost["fee"]/100,
                'calc_tax' => 'per_item'
            );

            // Register the rate
            $this->add_rate( $rate );
            //*/

        }
    }
}
