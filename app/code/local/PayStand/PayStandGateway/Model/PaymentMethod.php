<?php
 
/*
Copyright 2014 PayStand Inc.

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
*/

if (!defined('PS_LOG')) {
  define('PS_LOG', 'paystand.log');
}
if (!defined('PS_LIVE_URL')) {
  define('PS_LIVE_URL', 'https://app.paystand.com');
}
if (!defined('PS_SANDBOX_URL')) {
  define('PS_SANDBOX_URL', 'https://dev.paystand.biz');
}
if (!defined('PS_API')) {
  define('PS_API', '/api/v2');
}

/**
 * PayStand PayStandGateway PaymentMethod
 */
class PayStand_PayStandGateway_Model_PaymentMethod extends Mage_Payment_Model_Method_Abstract
{
  /**
   * Unique internal payment method identifier
   * @var string [a-z0-9_]
   */
  protected $_code = 'paystandgateway';

  /**
   * Functionality availability flags
   * @see all flags and their defaults in Mage_Payment_Model_Method_Abstract
   * It is possible to have a custom dynamic logic by overloading
   * public function can* for each flag respectively
   */

  protected $_isGateway = true;

  protected $_isInitializeNeeded = true;
  protected $_canUseInternal = true;
  protected $_canUseForMultishipping = false;

  protected $accepted_currency_codes = array('USD');
 

  /**
   * PayStand API URLs
   */
  protected $paystand_url;
  protected $api_url;
  protected $pm_url;
  protected $pay_url;

// XXX merge review - do we need these functions vvv
  /**
   * Get config settings.
   */
  public function getPayStandConfig()
  {
    Mage::log('PayStand getPayStandConfig', null, PS_LOG);
    $this->org_id = $this->getConfigData('org_id');
    $this->api_key = $this->getConfigData('api_key');
    $this->use_sandbox = $this->getConfigData('use_sandbox');
    if ($this->use_sandbox) {
      $this->paystand_url = PS_SANDBOX_URL;
    } else {
      $this->paystand_url = PS_LIVE_URL;
    }
    $this->pm_url = $paystand_url . PS_API . '/paymentmethods';
    $this->pay_url = $paystand_url . PS_API . '/payments';
    Mage::log('PayStand config org_id: ' . $this->org_id, null, PS_LOG);
    Mage::log('PayStand config pay_url: ' . $this->pay_url, null, PS_LOG);
  }

  public function getPayStandUrl()
  {
    if (empty($this->paystand_url)) {
      $this->getPayStandConfig();
    }
    return $this->paystand_url;
  }

  public function getOrgId()
  {
    if (empty($this->org_id)) {
      $this->getPayStandConfig();
    }
    return $this->org_id;
  }

  public function getApiKey()
  {
    if (empty($this->api_key)) {
      $this->getPayStandConfig();
    }
    return $this->api_key;
  }
// XXX merge review - do we need these functions ^^^

  /**
   * Check method for processing with base currency
   *
   * @param string $currencyCode
   * @return boolean
   */
  public function canUseForCurrency($currencyCode)
  {
    if (!in_array($currencyCode, $this->getAcceptedCurrencyCodes())) {
      Mage::log('PayStand False canUseForCurrency: ' . $currencyCode, null, PS_LOG);
      return false;
    }
    return true;
  }

  /**
   * Return array of currency codes supported by Payment Gateway
   *
   * @return array
   */
  public function getAcceptedCurrencyCodes()
  {
    return $this->accepted_currency_codes;
  }

  public function getOrderPlaceRedirectUrl()
  {
    $url = Mage::getUrl('paystandgateway/payment/redirect',
        array('_secure' => true));
    return $url;
  }

// XXX merge review - do we need these functions? vvv
  /**
   * Send capture request to gateway
   *
   * @param Mage_Payment_Model_Info $payment
   * @param decimal $amount
   * @return PayStand_PayStandGateway_Model_PaymentMethod
   */
  public function capture(Varien_Object $payment, $amount)
  {
    Mage::log('PayStand capture amount: ' . $amount, null, PS_LOG);

    $this->getConfig();

    if ($amount <= 0) {
      Mage::log('PayStand capture invalid amount: ' . $amount, null, PS_LOG);
      Mage::throwException('Invalid amount for capture.');
    }

    $card_number = $payment->getCcNumber();
    $card_exp_month = $payment->getCcExpMonth();
    $card_exp_year = $payment->getCcExpYear();
    $cvc = $payment->getCcCid();
    $order = $payment->getOrder();
    $order_id = $order->getIncrementId();
    $currency = $order->getBaseCurrencyCode();
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $addr = $order->getBillingAddress();
    if ($addr) {
      $first_name = $addr->getData('firstname');
      $last_name = $addr->getData('lastname');
      $email = $addr->getData('email');
      $street1 = $addr->getData('street');
      $city = $addr->getData('city');
      $state = $addr->getData('region');
      $postal_code = $addr->getData('postcode');
      $country = $addr->getData('country_id');
      $phone = $addr->getData('telephone');
    } else {
      $first_name = '';
      $last_name = '';
      $email = '';
      $street1 = '';
      $city = '';
      $state = '';
      $postal_code = '';
      $country = '';
      $phone = '';
    }

    $rail = 'card';

    $billing = array(
        'full_name' => "Unknown Customer",
        'email' => $email,
        'address_line1' => $street1,
        'address_line2' => $street2,
        'address_city' => $city,
        'address_state' => $state,
        'address_zip' => $postal_code,
        'address_country' => $country,
        'card_number' => $card_number,
        'card_cvv' => $cvc,
        'card_month' => $card_exp_month,
        'card_year' =>$car_exp_year 
    );

    $request = array(
        'action' => 'create_token',
        'api_key' => $this->api_key,
        'org_id' => $this->org_id,
        'rail' => $rail,
        'billing' => $billing
    );

    Mage::log('PayStand capture request: ' . print_r($request, true), null, PS_LOG);

    $context = stream_context_create(array(
        'http' => array(
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($request)
        )
    ));

    $response = false;
    $retry = 0;
    $max_retries = 3;
    while (($response === false) && ($retry < $max_retries)) {
      if ($retry > 0) {
        sleep(1);
      }
      $response = file_get_contents($this->pm_url, false, $context);
      $retry++;
    }
    if ($response === false) {
      Mage::log('PayStand capture failed to contact payment gateway: ' . $this->pm_url, null, PS_LOG);
      Mage::throwException('Failed to contact payment gateway.');
    }

    Mage::log('PayStand capture response: ' . print_r($response, true), null, PS_LOG);

    $response_data = json_decode($response, true);
    $data = $response_data['data'];
    $token = $data['token']['token'];

    $billing = array(
        'payment_token' => $token
    );

    $request = array(
        'action' => 'pay',
        'api_key' => $this->api_key,
        'org_id' => $this->org_id,
        'pre_fee_total' => 12.67,
        'memo' => 'for tix',
        'rail' => $rail,
        'billing' => $billing
    );

    Mage::log('PayStand capture request: ' . print_r($request, true), null, PS_LOG);

    $context = stream_context_create(array(
        'http' => array(
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($request)
        )
    ));

    $response = false;
    $retry = 0;
    $max_retries = 3;
    while (($response === false) && ($retry < $max_retries)) {
      if ($retry > 0) {
        sleep(1);
      }
      $response = file_get_contents($this->pm_url, false, $context);
      $retry++;
    }
    if ($response === false) {
      Mage::throwException('Failed to contact payment gateway.');
    }

    Mage::log('PayStand capture response: ' . print_r($response, true), null, PS_LOG);

    $response_data = json_decode($response, true);
    $data = $response_data['data'];
    $payment_status = $data['payment_status'];
    $pre_fee_total = $data['pre_fee_total'];
    $total_amount = $data['total_amount'];
    $processing_fee = $total_amount - $pre_fee_total;
    $success = $data['success'];
    $txn_id = $data['order_id'];

    $payment->setTransactionId($txn_id);
    $payment->setIsTransactionClosed(1);
    $payment->setTransactionAdditionalInfo(
        Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,
        array('payment_status' => $payment_status,
        'processing_fee' => $processing_fee));

    return $this;
  }

  /**
   * Send refund request to gateway
   *
   * @param Mage_Payment_Model_Info $payment
   * @param decimal $amount
   * @return PayStand_PayStandGateway_Model_PaymentMethod
   */
  public function refund(Varien_Object $payment, $amount)
  {
    if ($amount <= 0) {
      Mage::throwException(Mage::helper('paygate')->__('Invalid amount for capture.'));
    }

    // XXX Implement

    return $this;
  }
// XXX merge review - do we need these functions? ^^^
}

