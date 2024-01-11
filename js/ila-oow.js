jQuery(document).ready(function(){

	jQuery("#woocommerce_aali_uber_direct_delivery_method_OAuth_Token_Button").click(function() {
		jQuery("body").block({
			overlayCSS:
			{
				background: "#fff",
				opacity: 0.6
			},
			css: {
				padding:        20,
				textAlign:      "center",
				color:          "#555",
				border:         "3px solid #aaa",
				backgroundColor:"#fff",
				cursor:         "wait",
				lineHeight:		"32px"
			}
		});
		var data = {
			action: 'requestOAuthToken',
			customerID:jQuery('input[name="woocommerce_aali_uber_direct_delivery_method_Customer_ID"]').val(),
			clientID:	jQuery('input[name="woocommerce_aali_uber_direct_delivery_method_Client_ID"]').val(),
			clientSecret:	jQuery('input[name="woocommerce_aali_uber_direct_delivery_method_Client_Secret"]').val(),
		};
		jQuery.post(ajaxurl, data, function(response) {
			if(response=="ERROR"){
				jQuery("#woocommerce_aali_uber_direct_delivery_method_OAuth_Token").val("");
				console.log("ajax call returned 'ERROR'");
			}else{
				jQuery("#woocommerce_aali_uber_direct_delivery_method_OAuth_Token").val(response);
				
			}
			jQuery("body").unblock();
		});
		return false;
	});

	/**
	 * Get new delivery quote id
	 */
	jQuery("#woocommerce_aali_uber_direct_delivery_new_quote").click(function(event) {
		event.preventDefault();

		var d_address = jQuery('input[name="woocommerce_aali_uber_direct_delivery_dropoff_address"]').val() +
						jQuery('input[name="woocommerce_aali_uber_direct_delivery_dropoff_city"]').val() +
						jQuery('input[name="woocommerce_aali_uber_direct_delivery_dropoff_state"]').val() +
						jQuery('input[name="woocommerce_aali_uber_direct_delivery_dropoff_pobox"]').val() +
						jQuery('input[name="woocommerce_aali_uber_direct_delivery_dropoff_country"]').val();

		//setup data to send the request to Ajax handler function (php function in main file)
		var data = {
			action: 'requestDeliveryQuoteID',
			pickupAddress: jQuery('input[name="woocommerce_aali_uber_direct_delivery_pickup_address"]').val(),
			dropoffAddress: d_address,
		};

		jQuery.post(ajaxurl, data, function(response) {

			if(response=="ERROR"){
				jQuery("#woocommerce_aali_uber_direct_delivery_quote_id").val("");

				console.log("ajax call returned 'ERROR'");

				jQuery('#ilaa-delivery-setup-page').append("<span class='notice-inner-span'>Error while getting new quote id.</span>");
				jQuery('#ilaa-delivery-setup-page').show();

			}else{
				jQuery("#woocommerce_aali_uber_direct_delivery_quote_id").val( response );

				jQuery('#ilaa-delivery-setup-page').append("<span class='notice-inner-span'>New quote generated successfully!</span>");
				jQuery('#ilaa-delivery-setup-page').show();

				saveOrderData();
			}

		});
	
		return false;
	});

	/**
	 * Save order data updated on delivery setup page
	 * 
	 * @returns false if save operation fails. true if save is successfull.
	 */
	function saveOrderData() {

		var d_address = jQuery('input[name="woocommerce_aali_uber_direct_delivery_dropoff_address"]').val();
		var d_address_city = jQuery('input[name="woocommerce_aali_uber_direct_delivery_dropoff_city"]').val();
		var d_address_state = jQuery('input[name="woocommerce_aali_uber_direct_delivery_dropoff_state"]').val();
		var d_address_pobox = jQuery('input[name="woocommerce_aali_uber_direct_delivery_dropoff_pobox"]').val();
		var d_address_country = jQuery('input[name="woocommerce_aali_uber_direct_delivery_dropoff_country"]').val();

		//setup data to send the request to Ajax handler function (php function in main file)
		var data = {
			action: 'saveUpdatedData',
			orderid: jQuery('input[name="woocommerce_aali_uber_direct_delivery_orderid"]').val(),

			quoteid: jQuery('input[name="woocommerce_aali_uber_direct_delivery_quote_id"]').val(),
			dropoffAddress: d_address,
			dropoffAddressCity: d_address_city,
			dropoffAddressState: d_address_state,
			dropoffAddressPobox: d_address_pobox,
			dropoffAddressCountry: d_address_country,
			dropofffName: jQuery('input[name="woocommerce_aali_uber_direct_delivery_dropoff_first_name"]').val(),
			dropofflName: jQuery('input[name="woocommerce_aali_uber_direct_delivery_dropoff_last_name"]').val(),
			dropoffPhone: jQuery('input[name="woocommerce_aali_uber_direct_delivery_dropoff_phone_number"]').val(),
			dropoffNote: jQuery('textarea[name="woocommerce_aali_uber_direct_delivery_dropoff_notes"]').val()
		};
		jQuery.post(ajaxurl, data, function(response) {

			if(response=="ERROR"){
				// alert("Data could not be updated in the database.");

				jQuery('#ilaa-delivery-setup-page').append("<span class='notice-inner-span'>Error while saving order data!</span>");
				jQuery('#ilaa-delivery-setup-page').show();

			}else{
				jQuery('#ilaa-delivery-setup-page').append("<span class='notice-inner-span'>Order data saved successfully!</span>");
				jQuery('#ilaa-delivery-setup-page').show();

				setupUberDelivery();
			}

		});
		return false;

	}

	/**
	 * Setup Delivery in Uber system using API.
	 * 
	 */
	function setupUberDelivery() {
		/** Data is saved now call the Uber API to setup Delivery. */
		
		var n_data = {
			action: 'requestDelivery',
			orderid: jQuery('input[name="woocommerce_aali_uber_direct_delivery_orderid"]').val()
		};

		jQuery.post(ajaxurl, n_data, function(response) {
			//alert(response);

			if(response.indexOf("ERROR") != -1){
				// alert("Error occured when setting up the delivery. " + response);

				jQuery('#ilaa-delivery-setup-page').append("<span class='notice-inner-span'>Error while setting up delivery! <br/> " + response + "</span>");
				jQuery('#ilaa-delivery-setup-page').show();

			} else {

				jQuery('#ilaa-delivery-setup-page').append("<span class='notice-inner-span'>Delivery setup sucessfull!</span>");
				jQuery('#ilaa-delivery-setup-page').show();

				window.location.replace(ilaa_ajax_object.url + '/wp-admin/edit.php?post_type=shop_order');
			}
		});

		return false;
	}
});
