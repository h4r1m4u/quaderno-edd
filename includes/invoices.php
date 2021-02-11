<?php
/**
* Invoices
*
* @package    EDD Quaderno
* @copyright  Copyright (c) 2015, Carlos Hernandez
* @license    http://opensource.org/licenses/gpl-2.0.php GNU Public License
* @since      1.0
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
* Create invoice
*
* @since  1.0
* @param  int $payment_id
* @return mixed|void
*/
function edd_quaderno_create_invoice($payment_id, $parent_id = 0) {
	global $edd_options;

	// Get the payment
	$payment = new EDD_Payment($payment_id);

	// Return if the invoice total is zero
	$skip = false; 
	if ( $payment->total == 0 || apply_filters('quaderno_invoice_skip', $skip, $payment) ) {
		return;
	}

	// Return if an invoice has already been issued for this order
	$invoice_id = $payment->get_meta( '_quaderno_invoice_id' );
	if ( !empty( $invoice_id ) ) {
		return;
	}

	if ( $parent_id != 0 ) {
		// if this is a recurring payment, we use metadata from the original payment
		$parent = new EDD_Payment( $parent_id );
		$parent_meta = $parent->get_meta();
		
		$meta = $payment->get_meta();

		$new_meta = array( 
			'tax_id' => empty( $parent_meta['vat_number'] ) ? $parent_meta['tax_id'] : $parent_meta['vat_number'], 
			'business_name' => $parent_meta['business_name']
		);

		$merged_meta = array_merge( $meta, $new_meta );
		$payment->update_meta( '_edd_payment_meta', $merged_meta );
		$payment->save();

		$ip_address = $parent->ip;
	} else {
		$ip_address = $payment->ip;		
	}

	// Get metadata
	$metadata = $payment->get_meta();
	$tax_id = empty( $metadata['vat_number'] ) ? $metadata['tax_id'] : $metadata['vat_number'];
	$business_name = isset( $metadata['business_name'] ) ? $metadata['business_name'] : '';
	
	// Get the taxes
	$tax = edd_quaderno_tax( $payment->address['country'], $payment->address['zip'], $payment->address['city'], $tax_id );
	$tax = apply_filters( 'quaderno_invoice_tax', $tax, $payment );

	// Add the invoice params
	$invoice_params = array(
		'currency' => $payment->currency,
		'po_number' => $payment->number,
		'interval_count' => $payment->parent_payment == 0 ? '0' : '1',
		'notes' => apply_filters( 'quaderno_invoice_notes', $tax->notes, $payment, $tax ),
		'processor' => 'edd',
		'processor_id' => time() . '_' . $payment_id,
		'payment_method' => get_quaderno_payment_method( $payment->gateway ),
		'evidence_attributes' => array( 'billing_country' => $payment->address['country'], 'ip_address' => $ip_address ),
		'custom_metadata' => array( 'processor_url' => add_query_arg( 'id', $payment_id, admin_url( 'edit.php?post_type=download&page=edd-payment-history&view=view-order-details' ) ) )
	);

	// Add the contact
	$customer = new EDD_Customer( $payment->customer_id );
	if ( !empty( $business_name ) ) {
		$kind = 'company';
		$first_name = $business_name;
		$last_name = '';
		$contact_name = implode( ' ', array($payment->first_name, $payment->last_name) );
	} else {
		$kind = 'person';
		$first_name = $payment->first_name;
		$last_name = $payment->last_name;
		$contact_name = '';
	}

	$invoice_params['contact'] = array(
		'kind' => $kind,
		'first_name' => $first_name ?: 'EDD Customer',
		'last_name' => $last_name,
		'contact_name' => $contact_name,
		'street_line_1' => $payment->address['line1'] ?: '',
		'street_line_2' => $payment->address['line2'] ?: '',
		'city' => $payment->address['city'],
		'postal_code' => $payment->address['zip'],
		'region' => edd_get_state_name($payment->address['country'], $payment->address['state']),
		'country' => $payment->address['country'],
		'email' => $payment->email,
		'tax_id' => $tax_id,
		'processor' => 'edd'
	);

  $contact_id = $customer->get_meta( '_quaderno_contact', true );

  // The following code is quite hacky in order to skip Quaderno API's contact default initialization 
  // 2 reasons for contact_id to be empty:
  // - This is the first purchase ever for the user and the contact does not exist in Quaderno
  // - This is not the first purchase of the user but she does not have a contact_id saved yet in EDD metadata (until v1.24.3)

  $payments = $customer->get_payments();
  end($payments);
  $last_payment = prev($payments);  

  if(!empty($last_payment)){
    $last_payment_metadata = $last_payment->get_meta();
    $last_payment_business_name = isset( $last_payment_metadata['business_name'] ) ? $last_payment_metadata['business_name'] : '';
  }
  
  // If this is the customer's first purchase or their billing info has changed, then a new contact must be created
  if (empty( $last_payment ) ||
      $last_payment_business_name != $business_name ||
      $last_payment->first_name != $payment->first_name ||
      $last_payment->last_name != $payment->last_name){

    // Force the creation of a new contact
    $hashed_billing_name = md5(implode( '-', array($payment->first_name, $payment->last_name, $business_name)));
    // We don't want Quaderno to initialize the contact by processor_id
    $invoice_params['contact']['processor_id'] = $hashed_billing_name . '_' . $payment->customer_id;
  }

  if(!isset($invoice_params['contact']['processor_id'])){
    if ( empty( $contact_id ) ){
      // Use the processor_id the already exists in Quaderno in order to identify the contact until we have contact_id stored in EDD
      $invoice_params['contact']['processor_id'] = strtotime($customer->date_created) . '_' . $payment->customer_id;
    }else{
      // Use the contact_id to identify the contact
      $invoice_params['contact']['id'] = $contact_id;
      unset($invoice_params['contact']['first_name']);
      unset($invoice_params['contact']['last_name']);
    }
  }
	
	// Let's create the invoice
	$invoice = new QuadernoIncome($invoice_params);

	// Let's create the tag list
	$tags = array();

	// Add the invoice item
	foreach ( $payment->cart_details as $cart_item ) {
		$download = new EDD_Download( $cart_item['id'] );
		$product_name = $download->post_title;

		// Check if the item is a product variant
		$price_id = edd_get_cart_item_price_id( $cart_item );
		if ( edd_has_variable_prices( $cart_item['id'] ) && ! is_null( $price_id ) ) {
			$product_name .= ' - ' . edd_get_price_option_name( $cart_item['id'], $price_id, $payment->transaction_id );
		}

		// Calculate discount rate (if it exists)
    $discount_rate = 0;
		if ( $cart_item['discount'] > 0 ) {
			$discount_rate = $cart_item['discount'] / $cart_item['subtotal'] * 100;
		}

		$item = new QuadernoDocumentItem(array(
			'product_code' => $download->post_name,
			'description' => $product_name,
			'quantity' => $cart_item['quantity'],
      'discount_rate' => $discount_rate,
			'total_amount' => $cart_item['price'],
			'tax_1_name' => $tax->name,
			'tax_1_rate' => $tax->rate,
			'tax_1_country' => $tax->country,
			'tax_1_region' => $tax->region,
			'tax_1_transaction_type' => 'eservice'
		));

		$invoice->addItem( $item );

		$tags = array_merge( $tags, wp_get_object_terms( $cart_item['id'], 'download_tag', array( 'fields' => 'slugs' ) ) );

		// Create product on Quaderno
		if ( !get_post_meta( $download->get_ID(), '_quaderno_product_id', true ) ) {

			$product = new QuadernoItem(array(
				'code' => $download->post_name,
				'name' => $download->post_title,
				'product_type' => 'good',
				'unit_cost' => $download->get_price(),
				'currency' => $payment->currency,
				'tax_class' => 'eservice',
				'kind' => !get_post_meta( $download->get_ID(), 'edd_recurring', true ) ? 'one_off' : 'subscription'
			));

			if ( $product->save() ) {
				update_post_meta( $download->get_ID(), '_quaderno_product_id', $product->id );
			}
		}
	}

	// Add download tags to invoice
	if ( count( $tags ) > 0 ) {
		$invoice->tag_list = implode( ',', $tags );
	}

	// Add gateway fees
	foreach ( $payment->fees as $fee ) {
		$item = new QuadernoDocumentItem(array(
			'description' => $fee['label'],
			'quantity' => 1,
			'unit_price' => $fee['amount'],
			'tax_1_name' => $tax->name,
			'tax_1_rate' => $tax->rate,
      'tax_1_country' => $tax->country,
			'tax_1_region' => $tax->region,
			'tax_1_transaction_type' => 'eservice'
		));
		$invoice->addItem( $item );
	}	

	do_action( 'quaderno_invoice_pre_create', $invoice, $payment );

	// Save the invoice and the location evidences
	if ( $invoice->save() ) {
		$payment->update_meta( '_quaderno_invoice_id', $invoice->id );
		$payment->update_meta( '_quaderno_url', $invoice->permalink );
		$payment->add_note( 'Receipt created on Quaderno' );

    $customer->update_meta( '_quaderno_contact', $invoice->contact->id );

		do_action( 'quaderno_invoice_created', $invoice, $payment );

		// Send the invoice
		if ( isset( $edd_options['autosend_receipts'] ) ) {
			$invoice->deliver();
		}
	}

}
add_action( 'edd_complete_purchase', 'edd_quaderno_create_invoice', 999 );
add_action( 'edd_recurring_record_payment', 'edd_quaderno_create_invoice', 999, 2 );

/**
* Resend invoice
*
* @since  1.23
* @param  array $data Payment Data
* @return void
*/
function edd_quaderno_resend_invoice( $data ) {
	$payment_id = absint( $data['purchase_id'] );

	if( empty( $payment_id ) ) {
		return;
	}

	$payment = new EDD_Payment($payment_id);
	edd_quaderno_create_invoice( $payment_id, $payment->parent_payment );

	wp_redirect( add_query_arg( array( 'edd-message' => 'email_sent', 'edd-action' => false, 'purchase_id' => false ) ) );
	exit;
}
add_action( 'edd_resend_invoice', 'edd_quaderno_resend_invoice', 999 );

