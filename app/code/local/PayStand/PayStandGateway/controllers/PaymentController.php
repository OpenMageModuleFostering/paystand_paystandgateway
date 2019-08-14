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
  define('PS_SANDBOX_URL', 'https://sandbox.paystand.co');
}

class PayStand_PayStandGateway_PaymentController extends Mage_Core_Controller_Front_Action
{
  public function redirectAction()
  {
    $this->loadLayout();
    $block = $this->getLayout()->createBlock('Mage_Core_Block_Template',
        'paystandgateway',
         array('template' => 'paystandgateway/redirect.phtml'));
    $this->getLayout()->getBlock('content')->append($block);
    $this->renderLayout();
  }

  public function responseAction()
  {
    $request = $this->getRequest();
    $json = $request->getOriginalRequest()->getRawBody();
    Mage::log('PayStand responseAction request: ' . print_r($json, true), null, PS_LOG);
    $psn = json_decode($json, true);
    $ok = $this->verify_psn($psn);
    if (!$ok) {
      Mage::log('PSN failed to verify', null, PS_LOG);
      Mage::app()->getResponse()->setHeader('HTTP/1.1','400 Bad Request')
          ->sendResponse();
      exit;
    }

    $order_id = $psn['order_id'];
    $txn_id = $psn['txn_id'];
    $payment_status = $psn['payment_status'];
    $success = $psn['success'];
    $rail = $psn['rail'];

    $order = Mage::getModel('sales/order');
    $order->loadByIncrementId($order_id);
    $payment = $order->getPayment();

    if ($success) {
      $pre_fee_total = $psn['pre_fee_total'];
      $total_amount = $psn['total_amount'];
      $processing_fee = $total_amount - $pre_fee_total;

      //$fee_item = new Mage_Sales_Model_Order_Item();
      // XXX set fee in item
      // XXX add fee item if not already there
      //$order->addItem($fee_item);

      // XXX set state to order_status from module system config
      $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true,
          'Payment success.');

      $payment->setTransactionId($txn_id);
      $payment->setIsTransactionClosed(1);
      $payment->setTransactionAdditionalInfo(
          Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,
          array('payment_status' => $payment_status,
          'processing_fee' => $processing_fee));

      $order->sendNewOrderEmail();
      $order->setEmailSent(true);
      $order->save();

      Mage::getSingleton('checkout/session')->unsQuoteId();
      Mage_Core_Controller_Varien_Action::_redirect(
          'checkout/onepage/success', array('_secure' => true));
    } else {
      if ('failed' == $payment_status) {
        $this->cancelAction();
        Mage_Core_Controller_Varien_Action::_redirect(
            'checkout/onepage/failure', array('_secure' => true));
      }
    }
  }

  public function cancelAction()
  {
    Mage::log('PayStand cancelAction', null, PS_LOG);
    if (Mage::getSingleton('checkout/session')->getLastRealOrderId()) {
      $order = Mage::getModel('sales/order')->loadByIncrementId(
          Mage::getSingleton('checkout/session')->getLastRealOrderId());
      if ($order->getId()) {
        $order->cancel()->setState(Mage_Sales_Model_Order::STATE_CANCELED,
            true, 'Payment failed or canceled.')->save();
      }
    }
  }

  function verify_psn($psn)
  {
    if (empty($psn) || !is_array($psn)) {
      Mage::log('verify_psn psn is empty');
      return false;
    }

    $api_key = Mage::getStoreConfig('payment/paystandgateway/api_key');
    $use_sandbox = Mage::getStoreConfig('payment/paystandgateway/use_sandbox');
    if ($use_sandbox) {
      $paystand_url = PS_SANDBOX_URL;
    } else {
      $paystand_url = PS_LIVE_URL;
    }
    $endpoint = $paystand_url . '/api/v2/orders';

    $request = array(
        'action' => 'verify_psn',
        'api_key' => $api_key,
        'order_id' => $psn['txn_id'],
        'psn' => $psn
    );

    Mage::log('verify_psn endpoint: ' . $endpoint, null, PS_LOG);
    Mage::log('verify_psn request: ' . print_r($request, true), null, PS_LOG);

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
        Mage::log('verify_psn retry: ' . $retry, null, PS_LOG);
      }
      $response = file_get_contents($endpoint, false, $context);
      $retry++;
    }
    if ($response === false) {
      Mage::log('verify_psn returned false', null, PS_LOG);
      return false;
    }

    $response_data = json_decode($response, true);
    Mage::log('verify_psn response: ' . print_r($response_data, true), null, PS_LOG);

    if ($response_data['data'] !== true) {
      Mage::log('verify_psn response was not success', null, PS_LOG);
      return false;
    }

    $defined = array(
        'txn_id', 'org_id', 'consumer_id', 'pre_fee_total',
        'fee_merchant_owes', 'rate_merchant_owes',
        'fee_consumer_owes', 'rate_consumer_owes', 'total_amount',
        'payment_status', 'success'
    );
    $numerics = array(
        'pre_fee_total', 'fee_merchant_owes', 'rate_merchant_owes',
        'fee_consumer_owes', 'rate_consumer_owes', 'total_amount',
        'txn_id', 'org_id', 'consumer_id'
    );

    foreach ($defined as $def) {
      if (!isset($psn[$def])) {
        Mage::log('PSN validation error: ' . $def . ' is not defined or is empty', null, PS_LOG);
        return false;
      }
    }

    foreach ($numerics as $numeric) {
      if (!is_numeric($psn[$numeric])) {
        Mage::log('PSN validation error: ' . $numeric . ' is not numeric', null, PS_LOG);
        return false;
      }
    }

    $order_id = false;
    if (!empty($psn['order_id'])) {
      $order_id = $psn['order_id'];
    }

    Mage::log('verify_psn order_id: ' . $order_id);

    $order = false;
    if ($order_id) {
      $order = new Mage_Sales_Model_Order();
      $order->loadByIncrementId($order_id);
    }
    if (!$order->getId()) {
      Mage::log('Order not found for order id: ' . $order_id, null, PS_LOG);
      return false;
    }

    $pre_fee_total = false;
    if (!empty($psn['pre_fee_total'])) {
      $pre_fee_total = $psn['pre_fee_total'];
    }
    if ($pre_fee_total != $order->getBaseGrandTotal()) {
      Mage::log('PSN validation error: psn pre_fee_total: ' . $psn['pre_fee_total'] . ' not equal to order_total: ' . $order->getBaseGrandTotal(), null, PS_LOG);
      return false;
    }

    return true;
  }
}

