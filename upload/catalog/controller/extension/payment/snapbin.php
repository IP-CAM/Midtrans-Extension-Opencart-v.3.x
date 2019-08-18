<?php
/*
status code
1 pending
2 processing
3 shipped
5 complete
7 canceled
8 denied
9 canceled reversal
10 failed
11 refunded
12 reversed
13 chargeback
14 expired
15 processed
16 voided
*/

require_once(dirname(__FILE__) . '/snap_midtrans_version.php');
require_once(DIR_SYSTEM . 'library/veritrans-php/Veritrans.php');

class ControllerExtensionPaymentSnapbin extends Controller {

  public function index() {

    if ($this->request->server['HTTPS']) {
      $data['base'] = $this->config->get('config_ssl');
    } else {
      $data['base'] = $this->config->get('config_url');
    }

    $env = $this->config->get('snap_environment') == 'production' ? true : false;
    $data['mixpanel_key'] = $env == true ? "17253088ed3a39b1e2bd2cbcfeca939a" : "9dcba9b440c831d517e8ff1beff40bd9";
    $data['merchant_id'] = $this->config->get('payment_snapbin_merchant_id');

    $data['errors'] = array();
    $data['button_confirm'] = $this->language->get('button_confirm');

  	$data['pay_type'] = 'snapbin';
    $data['client_key'] = $this->config->get('payment_snapbin_client_key');
    $data['environment'] = $this->config->get('payment_snapbin_environment');
    $data['text_loading'] = $this->language->get('text_loading');

  	$data['process_order'] = $this->url->link('extension/payment/snapbin/process_order');

    $data['opencart_version'] = VERSION;
    $data['mtplugin_version'] = OC3_MIDTRANS_PLUGIN_VERSION;

    $data['disable_mixpanel'] = $this->config->get('payment_snapbin_mixpanel');

	// hpwd
	// unset($this->session->data['order_id']);
	  
    return $this->load->view('extension/payment/snapbin', $data);

  }

  /**
   * Called when a customer checkouts.
   * If it runs successfully, it will redirect to VT-Web payment page.
   */
  public function process_order() {
    $this->load->model('extension/payment/snapbin');
    $this->load->model('checkout/order');
    $this->load->model('extension/total/shipping');
    $this->load->language('extension/payment/snapbin');

    $data['errors'] = array();

    $data['button_confirm'] = $this->language->get('button_confirm');

    $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
    //error_log(print_r($order_info,TRUE));

    $this->model_checkout_order->addOrderHistory($this->session->data['order_id'],1);
    /*$this->model_checkout_order->addOrderHistory($this->session->data['order_id'],
        $this->config->get('veritrans_vtweb_challenge_mapping'));*/
    

    $transaction_details                 = array();
    $transaction_details['order_id']     = $this->session->data['order_id'];
    $transaction_details['gross_amount'] = $order_info['total'];

    $billing_address                 = array();
    $billing_address['first_name']   = $order_info['payment_firstname'];
    $billing_address['last_name']    = $order_info['payment_lastname'];
    $billing_address['address']      = $order_info['payment_address_1'];
    $billing_address['city']         = $order_info['payment_city'];
    $billing_address['postal_code']  = $order_info['payment_postcode'];
    $billing_address['phone']        = $order_info['telephone'];
    $billing_address['country_code'] = strlen($order_info['payment_iso_code_3'] != 3) ? 'IDN' : $order_info['payment_iso_code_3'];

    if ($this->cart->hasShipping()) {
      $shipping_address = array();
      $shipping_address['first_name']   = $order_info['shipping_firstname'];
      $shipping_address['last_name']    = $order_info['shipping_lastname'];
      $shipping_address['address']      = $order_info['shipping_address_1'];
      $shipping_address['city']         = $order_info['shipping_city'];
      $shipping_address['postal_code']  = $order_info['shipping_postcode'];
      $shipping_address['phone']        = $order_info['telephone'];
      $shipping_address['country_code'] = strlen($order_info['payment_iso_code_3'] != 3) ? 'IDN' : $order_info['payment_iso_code_3'];
    } else {
      $shipping_address = $billing_address;
    }

    $customer_details                     = array();
    $customer_details['billing_address']  = $billing_address;
    $customer_details['shipping_address'] = $shipping_address;
    $customer_details['first_name']       = $order_info['payment_firstname'];
    $customer_details['last_name']        = $order_info['payment_lastname'];
    $customer_details['email']            = $order_info['email'];
    $customer_details['phone']            = $order_info['telephone'];

    $products = $this->cart->getProducts();
    
    $item_details = array();

    foreach ($products as $product) {
      if (($this->config->get('config_customer_price')
            && $this->customer->isLogged())
          || !$this->config->get('config_customer_price')) {
        $product['price'] = $this->tax->calculate(
            $product['price'],
            $product['tax_class_id'],
            $this->config->get('config_tax'));
      }

      $item = array(
          'id'       => $product['product_id'],
          'price'    => $product['price'],
          'quantity' => $product['quantity'],
          'name'     => substr($product['name'], 0, 49)
        );
      $item_details[] = $item;
    }

    unset($product);

    $num_products = count($item_details);

    if ($this->cart->hasShipping()) {
      $shipping_info = $this->session->data['shipping_method'];
      if (($this->config->get('config_customer_price')
            && $this->customer->isLogged())
          || !$this->config->get('config_customer_price')) {
        $shipping_info['cost'] = $this->tax->calculate(
            $shipping_info['cost'],
            $shipping_info['tax_class_id'],
            $this->config->get('config_tax'));
      }

      $shipping_item = array(
          'id'       => 'SHIPPING',
          'price'    => $shipping_info['cost'],
          'quantity' => 1,
          'name'     => 'SHIPPING'
        );
      $item_details[] = $shipping_item;
    }

    // convert all item prices to IDR
    if ($this->config->get('config_currency') != 'IDR') {
      if ($this->currency->has('IDR')) {
        foreach ($item_details as &$item) {
          $item['price'] = intval($this->currency->convert(
              $item['price'],
              $this->config->get('config_currency'),
              'IDR'
            ));
        }
        unset($item);

        $transaction_details['gross_amount'] = intval($this->currency->convert(
            $transaction_details['gross_amount'],
            $this->config->get('config_currency'),
            'IDR'
          ));
      }
      else if ($this->config->get('payment_snapbin_currency_conversion') > 0) {
        foreach ($item_details as &$item) {
          $item['price'] = intval($item['price']
              * $this->config->get('payment_snapbin_currency_conversion'));
        }
        unset($item);

        $transaction_details['gross_amount'] = intval(
            $transaction_details['gross_amount']
            * $this->config->get('payment_snapbin_currency_conversion'));
      }
      else {
        $data['errors'][] = "Either the IDR currency is not installed or "
            . "the snap currency conversion rate is valid. "
            . "Please review your currency setting.";
      }
    }

    $total_price = 0;
    foreach ($item_details as $item) {
      $total_price += $item['price'] * $item['quantity'];
    }

    if ($total_price != $transaction_details['gross_amount']) {
      $coupon_item = array(
          'id'       => 'COUPON',
          'price'    => $transaction_details['gross_amount'] - $total_price,
          'quantity' => 1,
          'name'     => 'COUPON'
        );
      $item_details[] = $coupon_item;
    }

    Veritrans_Config::$serverKey = $this->config->get('payment_snapbin_server_key');
    Veritrans_Config::$isProduction = $this->config->get('payment_snapbin_environment') == 'production' ? true : false;
    Veritrans_Config::$isSanitized = true;

    $payloads = array();
    $payloads['transaction_details'] = $transaction_details;
    $payloads['item_details']        = $item_details;
    $payloads['customer_details']    = $customer_details;
    $payloads['credit_card']['secure'] = $this->config->get('payment_snapbin_3d_secure') == 1 ? false : true;
    // $payloads['enabled_payments']    = array('credit_card');
    // $payloads['credit_card'] = $credit_card;

    if ($this->config->get('payment_snapbin_number') !== '') {
      $bin = explode(',', $this->config->get('payment_snapbin_number'));
      $payloads['credit_card']['whitelist_bins'] = $bin;
    }

    if($this->config->get('payment_snapbin_oneclick') == 1){
      $payloads['credit_card']['save_card'] = true;
      $payloads['user_id'] = crypt( $order_info['email'], $this->config->get('snapbin_server_key') );
    }

    if ($this->config->get('payment_snapbin_acq_bank') !== '') {
      $payloads['credit_card']['bank'] = $this->config->get('payment_snapbin_acq_bank');
    }

    // check enabled payment
    $enabled_payments = explode(',', $this->config->get('payment_snapbin_enabled_payments'));
    if (!empty($enabled_payments[0]))
      $payloads['enabled_payments'] = $enabled_payments;

    // if ($this->config->get('payment_snapbin_enabled_payments') !== '') {
    //   $enabled_payments = explode(',', $this->config->get('payment_snapbin_enabled_payments'));
    // $payloads['enabled_payments'] = $enabled_payments;
    // }

    $expiry_unit = $this->config->get('payment_snap_expiry_unit');
    $expiry_duration = $this->config->get('payment_snap_expiry_duration');
    
    if (!empty($expiry_unit) && !empty($expiry_duration)){
          $time = time();
          $payloads['expiry'] = array(
            'unit' => $expiry_unit, 
            'duration'  => $expiry_duration
          );
    }

    if(!empty($this->config->get('payment_snapbin_custom_field1'))){$payloads['custom_field1'] = $this->config->get('payment_snapbin_custom_field1');}
    if(!empty($this->config->get('payment_snapbin_custom_field2'))){$payloads['custom_field2'] = $this->config->get('payment_snapbin_custom_field2');}
    if(!empty($this->config->get('payment_snapbin_custom_field3'))){ $payloads['custom_field3'] = $this->config->get('payment_snapbin_custom_field3');}

      // error_log(print_r($payloads,TRUE));
    try {
      $snapToken = Veritrans_Snap::getSnapToken($payloads);      
      //$this->response->setOutput($redirUrl);
      $this->response->setOutput($snapToken);
    }
    catch (Exception $e) {
      $data['errors'][] = $e->getMessage();
      error_log($e->getMessage());
      echo $e->getMessage();
    }
  }
}
