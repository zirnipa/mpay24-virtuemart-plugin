<?php

/**
 * mPAY24 Plugin for VirtueMart (3.7.2 Stable + VirtueMart 3.2.2)
 * @author zirnipa
 * @version 2.5
 * @license http://ec.europa.eu/idabc/eupl.html EUPL, Version 1.1
 */

defined('_JEXEC') or die('Restricted access');
if (!class_exists('vmPSPlugin')) {
	require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

require("API/bootstrap.php");
use Mpay24\Mpay24Order;
use Mpay24\Mpay24;

class plgVmPaymentMpay24 extends vmPSPlugin {
	public static $_this = FALSE;

	function __construct (& $subject, $config) {
		parent::__construct($subject, $config);

		$this->_loggable = true;
		$this->tableFields = array_keys($this->getTableSQLFields());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';

		// variables for configuration push
		$varsToPush = array(
				'vm_conf_accepted_currency'	=> array('', 'int'),
				'vm_conf_accepted_countries'=> array('', 'char'),
				'vm_conf_min_amount'		=> array('', 'int'),
				'vm_conf_max_amount'		=> array('', 'int'),
				'mpay24_conf_modus'			=> array('', 'int'),
				'mpay24_conf_merchantid'	=> array('', 'char'),
				'mpay24_conf_password'		=> array('', 'char'),
				'mpay24_conf_debug'			=> array('', 'char'),
				'mpay24_conf_status_pending'=> array('', 'char'),
				'mpay24_conf_status_success'=> array('', 'char'),
				'mpay24_conf_status_failed'	=> array('', 'char'),
				'mpay24_conf_status_credited'=>array('', 'char'),
				'mpay24_conf_billingaddr'	=> array('', 'char'),
				'payment_logos'       		=> array('', 'char'));

		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	}

	// @override
	public function getVmPluginCreateTableSQL() {
		return $this->createTableSQL('Payment mPAY24 Table');
	}

	// @override (vmPlugin)
	public function getTableSQLFields() {
		// table fields for order details
		$sqlFields = array(
				'id' 				=> ' INT(11) unsigned NOT NULL AUTO_INCREMENT ',
				'virtuemart_order_id' => 'INT(11) UNSIGNED DEFAULT NULL',
				'virtuemart_paymentmethod_id' => ' mediumint(1) UNSIGNED DEFAULT NULL',
				'payment_name' 		=> 'varchar(5000)',

				'mpaytid'			=> 'VARCHAR(255) NOT NULL',
				'tid'				=> 'VARCHAR(255) NOT NULL',
				'status'			=> 'VARCHAR(255) NOT NULL',
				'amount_reserved'	=> 'INT(11) UNSIGNED NOT NULL',
				'amount_billed'		=> 'INT(11) UNSIGNED NOT NULL',
				'amount_credited'	=> 'INT(11) UNSIGNED NOT NULL',
				'currency'			=> 'VARCHAR(3) NOT NULL',
				'p_type'			=> 'VARCHAR(255) NOT NULL',
				'brand'				=> 'VARCHAR(255) NOT NULL',
				'customer'			=> 'VARCHAR(255) NOT NULL',
				'appr_code'			=> 'VARCHAR(255) NOT NULL',
				'secret'			=> 'VARCHAR(255) NOT NULL');
		return $sqlFields;
	}

	// @override
	function plgVmConfirmedOrder($cart, $order, $payment_method = '') {
		if(!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return NULL;
		} // Another method was selected, do nothing

		if(!$this->selectedThisElement($method->payment_element)) {
			return FALSE;
		}

		$session = JFactory::getSession ();
		$return_context = $session->getId ();

		$this->logInfo(__FUNCTION__.'plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');

		if(!class_exists ('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		if(!class_exists ('VirtueMartModelCurrency')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
		}

		$usrBT = $order['details']['BT'];
		$address = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);

		if(!class_exists ('TableVendors')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'table' . DS . 'vendors.php');
		}
		$vendorModel = VmModel::getModel('Vendor');
		$vendorModel->setId(1);
		$vendor = $vendorModel->getVendor();
		$vendorModel->addImages($vendor, 1);
		$this->getPaymentCurrency($method);

		$mPAY24Instance = $this->createPaymentInstance($method);
		$app = JFactory::getApplication();

		if(isset($mPAY24Instance)) {
			// get db object
			$db = JFactory::getDBO();

			// get currency code
			$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' .
					$method->payment_currency . '" ';
			$db->setQuery($q);
			$currency_code_3 = $db->loadResult();

			// get country code
			$q = 'SELECT `country_2_code` FROM `#__virtuemart_countries` WHERE `virtuemart_country_id`="' .
					$order['details']['BT']->virtuemart_country_id . '" ';
			$db->setQuery($q);
			$country_2_code = $db->loadResult();

			// get state name
			if($order['details']['BT']->virtuemart_state_id != 0) {
				$q = 'SELECT `state_name` FROM `#__virtuemart_states` WHERE `virtuemart_state_id`="' .
						$order['details']['BT']->virtuemart_state_id . '" ';
				$db->setQuery($q);
				$state_name = $db->loadResult();
			} else {
				$state_name = NULL;
			}

			$paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
			$totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, FALSE), 2);
			$cd = CurrencyDisplay::getInstance($cart->pricesCurrency);
			$lang = JFactory::getLanguage();

			// get item infos for mPAY24 shopping cart
			$mPAY24Cart = array();
			$mPAY24Cart['Description'] = NULL;

			foreach($order['items'] as $key => $item) {
				$mPAY24Cart['Item'][$key]['Number']		= NULL;
				$mPAY24Cart['Item'][$key]['ProductNr']	= $item->order_item_sku;
				$mPAY24Cart['Item'][$key]['Description']= $item->order_item_name;
				$mPAY24Cart['Item'][$key]['Package']	= NULL;
				$mPAY24Cart['Item'][$key]['Quantity']	= $item->product_quantity;
				$mPAY24Cart['Item'][$key]['ItemPrice']	= $item->product_item_price;
				$mPAY24Cart['Item'][$key]['Price']		= $item->product_final_price;
				$mPAY24Cart['Item'][$key]['ItemTax']	= $item->product_tax;
			}

			// get additional headers (subtotal, discount, shipping costs, tax)
			$mPAY24Cart['SubTotal'] = NULL;

			if($order['details']['BT']->order_discount != 0) {
				$mPAY24Cart['Discount'] = $order['details']['BT']->order_discount;
			} else {
				$mPAY24Cart['Discount'] = NULL;
			}
			if($order['details']['BT']->coupon_discount != 0) {
				$mPAY24Cart['DiscountC'] = $order['details']['BT']->coupon_discount;
			} else {
				$mPAY24Cart['DiscountC'] = NULL;
			}
			if($order['details']['BT']->order_shipment != 0) {
				$mPAY24Cart['ShippingCosts'] = $order['details']['BT']->order_shipment;
			} else {
				$mPAY24Cart['ShippingCosts'] = NULL;
			}
			if($order['details']['BT']->order_tax != 0) {
				$mPAY24Cart['Tax'] = $order['details']['BT']->order_tax;
			} else {
				$mPAY24Cart['Tax'] = NULL;
			}

			// transaction id
			$orderNr = $order['details']['BT']->order_number;

			$customer = $order['details']['BT']->first_name . ' ' . (isset($addrBT['middle_name'])
					? $addrBT['middle_name'] .' ' : '') . $order['details']['BT']->last_name;

			function createSecret($tid, $amount, $currency, $timeStamp){
			$ts   = microtime();
			$rand = mt_rand();
			$seed = (string) $ts * $rand * $amount . $currency . $timeStamp . $tid;

			$secret = hash("sha1", $seed);

			return $secret;
			}

			$secretToken = createSecret($orderNr, $totalInPaymentCurrency, $currency_code_3, 0);

			// create data array and provide it the mPAY24Shop instance
			$data = array(
					'Tid'			=> $orderNr,
					'TemplateSet'	=> NULL,
						'TemplateSet_CSSName'	=> NULL,
						'TemplateSet_Language'	=> 'EN',
					'ShoppingCart'	=> $mPAY24Cart,
					'Amount'		=> $totalInPaymentCurrency,
					'Currency'		=> $currency_code_3,
					'BillingAddr'	=> array(
						'Mode'			=> $method->mpay24_conf_billingaddr,
						'Name'			=> $customer,
						'Street'		=> $order['details']['BT']->address_1,
						'Street2'		=> $order['details']['BT']->address_2,
						'Zip'			=> $order['details']['BT']->zip,
						'City'			=> $order['details']['BT']->city,
						'State'			=> $state_name,
						'Country'		=> $country_2_code,
						'Email'			=> $order['details']['BT']->email),
					'ShippingAddr'	=> NULL,
					'URL'			=> array(
						'Success'		=> JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id),
						'Error'			=> JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' . $orderNr . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id).'&ef=1',
						'Confirmation'	=> JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&token=' . $secretToken),
						'Cancel'		=> JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' . $orderNr . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id))
					);


					$mdxi = new Mpay24Order();
							$mdxi->Order->UserField = "User Field ".$data['Tid'];
							$mdxi->Order->Tid = $data['Tid'];

							$mdxi->Order->TemplateSet->setCSSName("MODERN");
							$mdxi->Order->TemplateSet->setLanguage($data['TemplateSet_Language']);

							if(isset($data['ShoppingCart']['Description'])) {
								$mdxi->Order->ShoppingCart->Description = $data['ShoppingCart']['Description'];
							}

							for($i = 0; $i < count($data['ShoppingCart']['Item']); $i++) {
								if(isset($data['ShoppingCart']['Item'][$i]['Number']))
									$mdxi->Order->ShoppingCart->Item(($i+1))->Number = $data['ShoppingCart']['Item'][$i]['Number'];
								$mdxi->Order->ShoppingCart->Item(($i+1))->ProductNr = $data['ShoppingCart']['Item'][$i]['ProductNr'];
								$mdxi->Order->ShoppingCart->Item(($i+1))->Description = $data['ShoppingCart']['Item'][$i]['Description'];
								if(isset($data['ShoppingCart']['Item'][$i]['Package']))
									$mdxi->Order->ShoppingCart->Item(($i+1))->Package = $data['ShoppingCart']['Item'][$i]['Package'];
								$mdxi->Order->ShoppingCart->Item(($i+1))->Quantity = $data['ShoppingCart']['Item'][$i]['Quantity'];
								$mdxi->Order->ShoppingCart->Item(($i+1))->ItemPrice = $data['ShoppingCart']['Item'][$i]['Price'];
								$mdxi->Order->ShoppingCart->Item(($i+1))->ItemPrice->setTax($data['ShoppingCart']['Item'][$i]['ItemTax']);
								$mdxi->Order->ShoppingCart->Item(($i+1))->Price = $data['ShoppingCart']['Item'][$i]['Price'];
							}

							if(isset($data['ShoppingCart']['SubTotal']))
								$mdxi->Order->ShoppingCart->SubTotal(1, number_format($data['ShoppingCart']['SubTotal'], 2, '.', ''));
							if(isset($data['ShoppingCart']['Discount']))
								$mdxi->Order->ShoppingCart->Discount(1, $data['ShoppingCart']['Discount']);
							if(isset($data['ShoppingCart']['DiscountC']))
								$mdxi->Order->ShoppingCart->Discount(1, $data['ShoppingCart']['DiscountC']);
							if(isset($data['ShoppingCart']['ShippingCosts']))
								$mdxi->Order->ShoppingCart->ShippingCosts(1, $data['ShoppingCart']['ShippingCosts']);
							if(isset($data['ShoppingCart']['Tax']))
								$mdxi->Order->ShoppingCart->Tax(1, $data['ShoppingCart']['Tax']);


							$mdxi->Order->Price = $data['Amount'];
							$mdxi->Order->Currency = $data['Currency'];

							$mdxi->Order->BillingAddr->setMode($data['BillingAddr']['Mode']);
							$mdxi->Order->BillingAddr->Name = $data['BillingAddr']['Name'];
							$mdxi->Order->BillingAddr->Street = $data['BillingAddr']['Street'];
							if(isset($data['BillingAddr']['Street2']))
								$mdxi->Order->BillingAddr->Street2 = $data['BillingAddr']['Street2'];
							$mdxi->Order->BillingAddr->Zip = $data['BillingAddr']['Zip'];
							$mdxi->Order->BillingAddr->City = $data['BillingAddr']['City'];
							if(isset($data['BillingAddr']['State']))
								$mdxi->Order->BillingAddr->State = $data['BillingAddr']['State'];
							$mdxi->Order->BillingAddr->Country->setCode($data['BillingAddr']['Country']);
							$mdxi->Order->BillingAddr->Email = $data['BillingAddr']['Email'];

							$mdxi->Order->ShippingAddr->setMode('ReadOnly');
							if(isset($data['ShippingAddr'])) {
								$mdxi->Order->ShippingAddr->Name = $data['ShippingAddr']['Name'];
								$mdxi->Order->ShippingAddr->Street = $data['ShippingAddr']['Street'];
								if(isset($data['ShippingAddr']['Street2']))
									$mdxi->Order->ShippingAddr->Street2 = $data['ShippingAddr']['Street2'];
								$mdxi->Order->ShippingAddr->Zip = $data['ShippingAddr']['Zip'];
								$mdxi->Order->ShippingAddr->City = $data['ShippingAddr']['City'];
								if(isset($data['ShippingAddr']['State']))
										$mdxi->Order->ShippingAddr->State = $data['ShippingAddr']['State'];
								$mdxi->Order->ShippingAddr->Country->setCode($data['ShippingAddr']['Country']);
								$mdxi->Order->ShippingAddr->Email = $data['ShippingAddr']['Email'];
							} else {
								$mdxi->Order->ShippingAddr->Name = $data['BillingAddr']['Name'];
								$mdxi->Order->ShippingAddr->Street = $data['BillingAddr']['Street'];
								if(isset($data['BillingAddr']['Street2']))
									$mdxi->Order->ShippingAddr->Street2 = $data['BillingAddr']['Street2'];
								$mdxi->Order->ShippingAddr->Zip = $data['BillingAddr']['Zip'];
								$mdxi->Order->ShippingAddr->City = $data['BillingAddr']['City'];
								if(isset($data['BillingAddr']['State']))
									$mdxi->Order->ShippingAddr->State = $data['BillingAddr']['State'];
								$mdxi->Order->ShippingAddr->Country->setCode($data['BillingAddr']['Country']);
								$mdxi->Order->ShippingAddr->Email = $data['BillingAddr']['Email'];
							}

							$mdxi->Order->URL->Success = $data['URL']['Success'];
							$mdxi->Order->URL->Error = $data['URL']['Error'];
							$mdxi->Order->URL->Confirmation = $data['URL']['Confirmation'];
							$mdxi->Order->URL->Cancel = $data['URL']['Cancel'];



			$result = $mPAY24Instance->paymentPage($mdxi);

			$html = '<html>
						<head><title>Redirection to mPAY24</title></head>
						<body>';

			if($result->getStatus() == "OK") {
				header("Location: " . $result->getLocation());
				$html = JText::_('VMPAYMENT_MPAY24_MSG_REDIRECT').
							"<a href=\'".$result->getLocation()."\'>the destination</a>";

				// prepare and store data in database
				$fields['virtuemart_order_id'] = VirtueMartModelOrders::getOrderIdByOrderNumber($orderNr);
				$fields['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
				$fields['payment_name'] = $this->renderPluginName($method, $order);
				$fields['tid'] = $orderNr;
				$fields['currency'] = $currency_code_3;
				$fields['customer'] = $customer;
				$fields['secret'] = $secretToken;
				$this->storePSPluginInternalData($fields, 'virtuemart_order_id', true);
			} else {
				echo JText::_('VMPAYMENT_MPAY24_MSG_ERROR');
				$this->logInfo(__FUNCTION__.' error by redirecting: '.$result->getReturnCode());
			}
			$html = '</body>
					</html>';

			// replaced $this->processConfirmedOrderPaymentResponse() function with the following:
			$cart->_confirmDone = FALSE;
			$cart->_dataValidated = FALSE;
			$cart->setCartIntoSession();
			JRequest::setVar('html', $html);

		} else {
			echo JText::_('VMPAYMENT_MPAY24_MSG_ERROR');
			$this->logInfo('mPAY24 not configured or wrong configured. Please check your username and password!');
			return null;
		}
	}

	function plgVmDeclarePluginParamsPaymentVM3( &$data) {
      return $this->declarePluginParams('payment', $data);
   }

	// @override
	function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) {
		if (!($method = $this->getVmPluginMethod ($virtuemart_paymentmethod_id))) {
			return NULL;
		} // Another method was selected, do nothing

		if (!$this->selectedThisElement ($method->payment_element)) {
			return FALSE;
		}

		$this->getPaymentCurrency ($method);
		$paymentCurrencyId = $method->payment_currency;
	}

	// @override
	function plgVmOnPaymentResponseReceived(&$html) {
		// the payment itself should send the parameter needed.
		$paymentMethodId = JRequest::getInt ('pm', 0);
		if (!($method = $this->getVmPluginMethod ($paymentMethodId))) {
			return NULL;
		} // Another method was selected, do nothing

		if (!$this->selectedThisElement ($method->payment_element)) {
			return NULL;
		}
		if (!class_exists ('VirtueMartCart')) {
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
		}
		if (!class_exists ('shopFunctionsF')) {
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
		}
		if (!class_exists ('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}

		// get order id
		$orderNr = JRequest::getString ('TID', 0);
		if (!($virtuemartOrderNr = VirtueMartModelOrders::getOrderIdByOrderNumber($orderNr))) {
			return NULL;
		}

		$db = JFactory::getDBO();

		// get currency code
 		$q = 'SELECT * FROM `#__virtuemart_payment_plg_mpay24` WHERE `virtuemart_order_id`="' .
				$virtuemartOrderNr . '" ';
 		$db->setQuery($q);
		$orderPaymentData = $db->loadAssoc();

		// check if confirmation has already been confirmed
		if( $orderPaymentData['status'] === "0") { // confirmation not received, data not updated yet
			$app = JFactory::getApplication();
			$mPAY24Instance = $this->createPaymentInstance($method);
			$result = $mPAY24Instance->paymentStatusByTID($orderNr);
			if($result->getStatus() == "OK" || urlencode($result->getReturnCode()) == "NOT_FOUND"){
				try {
					$params = $result->getParams();
					$this->updateTransactionDetails($method, $virtuemartOrderNr, $paymentMethodId, $orderPaymentData['payment_name'], $params, $orderPaymentData['secret']);
				} catch(Exception $e) {
					echo JText::_('VMPAYMENT_MPAY24_MSG_ERROR');
					$this->logInfo(__FUNCTION__.' transaction update not correctly saved: '.$e->errorMessage(), 'message');
					return FALSE;
				}

				$status = $result['STATUS'];
				if($this->getPaymentStatus($method, $status) != $method->mpay24_conf_status_failed) {
					$this->logInfo(__FUNCTION__.' transaction status updated, order success ', 'message');
					$cart = VirtueMartCart::getCart();
					$cart->emptyCart();
					return TRUE;
				} else {
					echo JText::_('VMPAYMENT_MPAY24_MSG_RETRY');
					$this->logInfo(__FUNCTION__.' transaction status updated, order error ', 'message');
					return FALSE;
				}
			} else {
				// error occurred
				$msg = 'Update transaction communication error. Update not received: '.
						$result->getStatus()."<br>".
						urlencode($result->getReturnCode());
				echo $msg;

				//echo ' update transaction communication error, update not received: '.$result->getGeneralResponse()->getReturnCode();
				$this->logInfo(__FUNCTION__.' update transaction communication error, update not received: '.$result->getReturnCode());
			}
		} else { // confirmation received, data has been updated
			if($orderPaymentData['status'] != $method->mpay24_conf_status_failed) {
				$this->logInfo(__FUNCTION__.' confirmation received, order success ', 'message');
				$cart = VirtueMartCart::getCart();
				$cart->emptyCart();
				return TRUE;
			} else {
				echo JText::_('VMPAYMENT_MPAY24_MSG_RETRY');
				$this->logInfo(__FUNCTION__.' confirmation received, order error ', 'message');
				return FALSE;
			}
		}
	}

	// @override
	function plgVmOnUserPaymentCancel() {
		if (!class_exists('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		$paymentMethodId = JRequest::getInt ('pm', 0);
		if (!($method = $this->getVmPluginMethod ($paymentMethodId))) {
			return NULL;
		} // Another method was selected, do nothing

		$order_number = JRequest::getVar('on');
		if(!$order_number) {
			return false;
		}

		if(!class_exists ('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}

		$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
		if (!$virtuemart_order_id) {
			return null;
		}

		$this->handlePaymentUserCancel($virtuemart_order_id);
	}

	// @override
	function plgVmOnPaymentNotification() {
		$this->logInfo (__FUNCTION__ . ' was triggered!', 'message');

		// the payment itself should send the parameter needed.
		$secretToken = JRequest::getString('token');
		if(!isset($secretToken)) {
			$this->logInfo (__FUNCTION__ . ' secret token was not set!', 'message');
			return;
		}

		// get order id
		$orderNr = JRequest::getString ('TID', 0);

		if (!($virtuemartOrderNr = VirtueMartModelOrders::getOrderIdByOrderNumber($orderNr))) {
			$this->logInfo (__FUNCTION__ . ' can\'t get VirtueMart order id', 'message');
			return;
		}

		if (!($payment = $this->getDataByOrderId ($virtuemartOrderNr))) {
			$this->logInfo (__FUNCTION__ . ' can\'t get payment type', 'message');
			return;
		}

		$method = $this->getVmPluginMethod ($payment->virtuemart_paymentmethod_id);
		if (!$this->selectedThisElement($method->payment_element)) {
			return NULL;
		} // Another method was selected, do nothing

		// check if secret token was correct
		$q = 'SELECT `payment_name` FROM `#__virtuemart_payment_plg_mpay24` ' .
				'WHERE `virtuemart_order_id`="' . $virtuemartOrderNr . '" ' .
				'AND `secret` ="' . $secretToken . '" ';
		$db = JFactory::getDBO();
		$db->setQuery($q);

		if(!($paymentName = $db->loadResult())) {
			$this->logInfo (__FUNCTION__ . ' secret token doesn\'t match!', 'message');
			return;
		}

		$mPAY24Instance = $this->createPaymentInstance($method);
		$result = $mPAY24Instance->paymentStatusByTID($orderNr);
		if($result->getStatus() == "OK" || urlencode($result->getReturnCode()) == "NOT_FOUND"){
			try {
				// update transaction details
				$params = $result->getParams();
				$this->updateTransactionDetails($method, $virtuemartOrderNr, $payment->virtuemart_paymentmethod_id, $paymentName, $params, $secretToken);
			} catch(Exception $e) {
				$this->logInfo(__FUNCTION__.' confirmation not correctly saved: '.$e->errorMessage(), 'message');
				echo 'ERROR';
				exit();
			}
			// get versions
			$modulVersion = $this->getParams('mpay24', 'version');
			$virtumartVersion = $this->getParams('com_virtuemart', 'version');
			$cmsVersionControll = new JVersion;
			$joomlaVersion = $cmsVersionControll->getShortVersion();

			echo 'OK: ' . 'Joomla ' . $joomlaVersion . ' VirtueMart ' . $virtumartVersion . ' ' . $modulVersion;
			exit();
		} else {
			$this->logInfo(__FUNCTION__.' confirmation communication error, confirmation not received: '.
					$result->getReturnCode(), 'message');
			echo 'ERROR';
			exit();
		}
		echo 'ERROR';
		exit();
	}

	// helper method
	private function updateTransactionDetails($method, $virtuemartOrderNr, $paymentMethodId, $paymentName, $params, $secretToken) {
		$status = $params['STATUS'];

		// save payment plugin data
		$fields['virtuemart_order_id'] = $virtuemartOrderNr;
		$fields['virtuemart_paymentmethod_id'] = $paymentMethodId;
		$fields['payment_name'] = $paymentName;

		$fields['tid'] = $params['TID'];
		$fields['currency'] = $params['CURRENCY'];
		$fields['customer'] = $params['CUSTOMER'];
		$fields['secret'] = $secretToken;

		$fields['mpaytid'] = $params['MPAYTID'];
		$fields['status'] = $params['STATUS'];
		$fields['p_type'] = $params['P_TYPE'];
		$fields['brand'] = $params['BRAND'];
		$fields['appr_code'] = $params['APPR_CODE'];

		$oldData = $this->getInternalData($virtuemartOrderNr);

		if($params['STATUS'] == 'RESERVED') {
			$fields['amount_reserved'] = $params['PRICE'];
		} elseif($params['STATUS'] == 'BILLED') {
			$fields['amount_billed'] = $params['PRICE'];
			$fields['amount_reserved'] = $oldData->amount_reserved;
		} elseif($params['STATUS'] == 'CREDITED') {
			$fields['amount_credited'] = $params['PRICE'];
			$fields['amount_billed'] = $oldData->amount_billed;
			$fields['amount_reserved'] = $oldData->amount_reserved;
		}

		$this->storePSPluginInternalData($fields, 'virtuemart_order_id', true);

		$modelOrder = VmModel::getModel('orders');
		$order = array();
		$order['order_status'] = $this->getPaymentStatus($method, $params['STATUS']);
		$order['customer_notified'] = 1;

		if(strcmp($status, 'BILLED') == 0) {
			$order['comments'] = JText::_('VMPAYMENT_BACKEND_TEXT_BILLED');
		} elseif(strcmp($status, 'ERROR') == 0) {
			$order['comments'] = JText::_('VMPAYMENT_BACKEND_TEXT_ERROR');
		} elseif(strcmp($status, 'RESERVED') == 0) {
			$order['comments'] = JText::_('VMPAYMENT_BACKEND_TEXT_RESERVED');
		} elseif(strcmp($status, 'CREDITED') == 0) {
			$order['comments'] = JText::_('VMPAYMENT_BACKEND_TEXT_CREDITED');
		} elseif(strcmp($status, 'REVERSED') == 0) {
			$order['comments'] = JText::_('VMPAYMENT_BACKEND_TEXT_REVERSED');
		} elseif(strcmp($status, 'REDIRECTED') == 0) {
			$order['comments'] = JText::_('VMPAYMENT_BACKEND_TEXT_REDIRECTED');
		} elseif(strcmp($status, 'SUSPENDED') == 0) {
			$order['comments'] = JText::_('VMPAYMENT_BACKEND_TEXT_SUSPENDED');
			$order['customer_notified'] = 0;
		} else {
			$this->logInfo (__FUNCTION__ . ' unexpected status set ' . $orderNr . 'to IN_PROCESS', 'message');
			$order['customer_notified'] = 0;
			$order['comments'] = JText::_('VMPAYMENT_BACKEND_TEXT_IN_PROCESS');
		}
		$modelOrder->updateStatusForOneOrder($virtuemartOrderNr, $order, true);

		if($method->mpay24_conf_billingaddr == 'ReadWrite')	{
			if(isset($params['BILLING_ADDR'])) {
				$db = JFactory::getDBO();
				$q = 'SELECT * FROM `#__virtuemart_order_userinfos` WHERE `virtuemart_order_id`="' . $virtuemartOrderNr . '" AND `address_type`="BT" ';
				$db->setQuery($q);
				$addrBT = $db->loadAssoc();

				$addConfirm = simplexml_load_string($params['BILLING_ADDR']);

				$updateDb = FALSE;

				$custName = $addrBT['first_name'] .' '. (isset($addrBT['middle_name']) ? $addrBT['middle_name'] .' ' : '') . $addrBT['last_name'];
				if((string)$addConfirm->Name != $custName) {
					$updateDb = TRUE;
					$exName = explode(' ', $addConfirm->Name);
					if(count($exName) == 2) {
						$addrBT['first_name'] = $exName[0];
						$addrBT['middle_name'] = NULL;
						$addrBT['last_name'] = $exName[1];
					} else {
						$addrBT['first_name'] = $exName[0];
						for($i=1; $i-1<count($exName); $i++) {
							$addrBT['middle_name'] = $exName[$i] . ' ';
						}
						$addrBT['last_name'] = $exName[count($exName[$i])-1];
					}
				}

				if((string)$addConfirm->Street != $addrBT['address_1']) {
					$updateDb = TRUE;
					$addrBT['address_1'] = $addConfirm->Street;
				}
				if((string)$addConfirm->Street2 != $addrBT['address_2']) {
					$updateDb = TRUE;
					$addrBT['address_2'] = $addConfirm->Street2;
				}
				if((string)$addConfirm->City != $addrBT['city']) {
					$updateDb = TRUE;
					$addrBT['city'] = $addConfirm->City;
				}
				if((string)$addConfirm->Zip != $addrBT['zip']) {
					$updateDb = TRUE;
					$addrBT['zip'] = $addConfirm->Zip;
				}

				// get country code
				$q = 'SELECT `country_2_code` FROM `#__virtuemart_countries` WHERE `virtuemart_country_id`="' .
						$addrBT['virtuemart_country_id'] . '" ';
				$db->setQuery($q);
				$countryCode = $db->loadResult();
				if((string)$addConfirm->Country->attributes()->code[0] != $countryCode) {
					$updateDb = TRUE;
					$q = 'SELECT `virtuemart_country_id` FROM `#__virtuemart_countries` WHERE `country_2_code`="' .
							$addConfirm->Country->attributes()->code[0] . '" ';
					$db->setQuery($q);
					$addrBT['virtuemart_country_id'] = $db->loadResult();
				}

				// get state name
				$q = 'SELECT `state_name` FROM `#__virtuemart_states` WHERE `virtuemart_state_id`="' .
						$addrBT['virtuemart_state_id'] . '" ';
				$db->setQuery($q);
				$stateName = $db->loadResult();
				if((string)$addConfirm->State != $stateName) {
					$updateDb = TRUE;
					$q = 'SELECT `virtuemart_state_id` FROM `#__virtuemart_countries` WHERE `stateName`="' . $addConfirm['state'] . '" ';
					$db->setQuery($q);
					$addrBT['virtuemart_state_id'] = $db->loadResult();
				}

				if((string)$addConfirm->Email != $addrBT['email']) {
					$updateDb = TRUE;
					$addrBT['email'] = $addConfirm['Email'];
				}
				if($updateDb) {
					// check if the ST address was set
					$addrST = 'SELECT * FROM `#__virtuemart_order_userinfos` WHERE `virtuemart_order_id`="'
								. $virtuemartOrderNr . '" AND `add	ress_type`="ST" ';
					$db->setQuery($q);
					$addrST = $db->loadAssoc();

					$uFieldsBT = array();
					$uColumnsST = array();
					$uValuesST = array();
					$uCondition = array("virtuemart_order_id = " .$virtuemartOrderNr);

					foreach($addrBT as $rowName => $newValue) {
						$uFieldsBT[] = $rowName . ' = "' . $newValue . '"';
						if($rowName != 'virtuemart_order_userinfo_id') {
							$uColumnsST[] = $rowName;
							if($rowName != 'address_type') {
								$uValuesST[] = $newValue;
							} else {
								$uValuesST[] = "BT";
							}
						}
					}

					$query = $db->getQuery(true);
					$query->update($db->quoteName('#__virtuemart_order_userinfos'))->set($uFieldsBT)->where($uCondition);
					$db->setQuery($query);
					try {
						$result = $db->query();
					} catch (Exception $e) {
						$this->logInfo (__FUNCTION__ . ' database error while saving configuration', 'error');
					}
					$this->logInfo (__FUNCTION__ . ' update billing address (e)', 'message');

					if(!isset($addrST)) {
						$query->insert($db->quoteName('#__virtuemart_order_userinfos'))
								->columns($db->quoteName($uColumnsST))->values(implode(',', $uValuesST));
						$db->setQuery($query);
						try {
							$result = $db->query();
						} catch (Exception $e) {
							$this->logInfo (__FUNCTION__ . ' database error while saving configuration', 'error');
						}
						$this->logInfo (__FUNCTION__ . ' create shipping address (ST)', 'message');
					}
				}

			} else {
				$this->logInfo (__FUNCTION__ . ' shipping has not been confirmed!', 'message');

				// set mode to read only (first get config)
				$this->logInfo (__FUNCTION__ . ' set billing address mode to READONLY', 'message');

				$db = JFactory::getDBO();
				$query = 'SELECT `payment_params` FROM `#__virtuemart_paymentmethods` WHERE `virtuemart_paymentmethod_id`= "' . $method->virtuemart_paymentmethod_id . '"';
				$db->setQuery($query);
				$params = $db->loadAssoc();
				$params = str_replace('mpay24_conf_billingaddr="ReadWrite"', 'mpay24_conf_billingaddr="ReadOnly"', $params["payment_params"]);

				$uFields = array("payment_params='".$params."'");
				$uCondition = array("virtuemart_paymentmethod_id = " .$method->virtuemart_paymentmethod_id);

				$query = $db->getQuery(true);
				$query->update($db->quoteName('#__virtuemart_paymentmethods'))->set($uFields)->where($uCondition);
				$db->setQuery($query);
				try {
					$result = $db->query();
				} catch (Exception $e) {
					$this->logInfo (__FUNCTION__ . ' database error while saving configuration', 'error');
				}

				// TODO send email?!

				// make a log entry in the order backend for the administrator
				$order['customer_notified'] = 0;
				$order['comments'] .= JText::_('VMPAYMENT_BACKEND_TEXT_NOADDRCONF');
				$modelOrder->updateStatusForOneOrder($virtuemartOrderNr, $order, true);
			}
		}
	}

	// @override
	function plgVmOnShowOrderBEPayment ($virtuemart_order_id, $payment_method_id) {
		if (!$this->selectedThisByMethodId ($payment_method_id)) {
			return NULL;
		} // Another method was selected, do nothing

		if (!($paymentTable = $this->getInternalData ($virtuemart_order_id))) {
			return '';
		}

		$html = '<table class="adminlist">' . "\n";
		$html .= $this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('BACKEND_LABEL_MPAYTID', $paymentTable->mpaytid);
		$html .= $this->getHtmlRowBE('BACKEND_LABEL_TID', $paymentTable->tid);
		$html .= $this->getHtmlRowBE('BACKEND_LABEL_STATUS', $paymentTable->status);
		$html .= $this->getHtmlRowBE('BACKEND_LABEL_AMOUNT_RESERVED', $paymentTable->amount_reserved);
		$html .= $this->getHtmlRowBE('BACKEND_LABEL_AMOUNT_BILLED', $paymentTable->amount_billed);
		$html .= $this->getHtmlRowBE('BACKEND_LABEL_AMOUNT_CREDITED', $paymentTable->amount_credited);
		$html .= $this->getHtmlRowBE('BACKEND_LABEL_CURRENCY', $paymentTable->currency);
		$html .= $this->getHtmlRowBE('BACKEND_LABEL_P_TYPE', $paymentTable->p_type);
		$html .= $this->getHtmlRowBE('BACKEND_LABEL_BRAND', $paymentTable->brand);
		$html .= $this->getHtmlRowBE('BACKEND_LABEL_CUSTOMER', $paymentTable->customer);
		$html .= $this->getHtmlRowBE('BACKEND_LABEL_APPR_CODE', $paymentTable->appr_code);
		$html .= '</table>' . "\n";
		return $html;
	}

	// @override
	protected function checkConditions($cart, $method, $cart_prices) {
		// convert amounts
		$method->vm_conf_min_amount = (float)$method->vm_conf_min_amount;
		$method->vm_conf_max_amount = (float)$method->vm_conf_max_amount;

		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);
		$amount = $cart_prices['salesPrice'];
		$amount_cond =
			($amount >= $method->vm_conf_min_amount AND $amount <= $method->vm_conf_max_amount
				OR
			($method->vm_conf_min_amount <= $amount AND ($method->vm_conf_max_amount == 0)));

		if(isset($method->vm_conf_accepted_currency)
				&& ($method->vm_conf_accepted_currency != $cart->paymentCurrency && $method->vm_conf_accepted_currency != 0)) {
			$this->logInfo(__FUNCTION__.' currency not accepted: ' . $method->vm_conf_accepted_currency .
							' (expected ' . $method->vm_conf_accepted_currency . ')', 'message');
			return FALSE;
		}

		$countries = array();
		if (!empty($method->vm_conf_accepted_countries)) {
			if (!is_array ($method->vm_conf_accepted_countries)) {
				$countries[0] = $method->vm_conf_accepted_countries;
			} else {
				$countries = $method->vm_conf_accepted_countries;
			}
		}

		// check if BT:ST address was given
		if (!is_array ($address)) {
			$address = array();
			$address['virtuemart_country_id'] = 0;
		}

		if (!isset($address['virtuemart_country_id'])) {
			$address['virtuemart_country_id'] = 0;
		}
		if (in_array ($address['virtuemart_country_id'], $countries) || count ($countries) == 0) {
			if ($amount_cond) {
				return TRUE;
			}
		}
		return FALSE;
	}

	// @override
	function getCosts(VirtueMartCart $cart, $method, $cart_prices) {
		return 0;
	}

	// @override
	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {
		return $this->onStoreInstallPluginTable($jplugin_id);
	}

	// @override
	public function plgVmOnSelectCheckPayment(VirtueMartCart $cart,  &$msg) {
		return $this->OnSelectCheck($cart);
	}

	// @override
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
		return $this->displayListFE($cart, $selected, $htmlIn);
	}

	// @override
	public function plgVmOnSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
		return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
	}

	// @override
	function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) {
		return $this->onCheckAutomaticSelected ($cart, $cart_prices, $paymentCounter);
	}

	// @override
	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
		$this->onShowOrderFE ($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}

	// @override
	function plgVmonShowOrderPrintPayment($order_number, $method_id) {
		return $this->onShowOrderPrint ($order_number, $method_id);
	}

	// @override
	function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
		if (!$this->selectedThisByMethodId ($data->virtuemart_paymentmethod_id)) {
			return NULL;
		} // Another method was selected, do nothing

		// get config data concerning only the payment params
		$modablePaymentData = $this->getParams(null, null, $data->payment_params, true);
		// set debug mode
		$debug = ($modablePaymentData['mpay24_conf_debug'] == 'true') ? TRUE : FALSE;
		if($debug) {
			$this->_debug = TRUE;
		} else {
			$this->_debug = FALSE;
		}

		$selfConfigure = $modablePaymentData['mpay24_conf_paysystem'];
		$method = $this->getVmPluginMethod($data->virtuemart_paymentmethod_id);

		$mpay24 = $this->createPaymentInstance(
				$modablePaymentData['mpay24_conf_merchantid'],
				$modablePaymentData['mpay24_conf_password'],
				$modablePaymentData['mpay24_conf_debug']);

		$reading = fopen(JPATH_ROOT . DS . "plugins" . DS . "vmpayment" . DS . "mpay24" . DS . "mpay24.xml", 'r');
		$writing = fopen(JPATH_ROOT . DS . "plugins" . DS . "vmpayment" . DS . "mpay24" . DS . "mpay24.xml.tmp", 'w+');
		$mapping = fopen(JPATH_ROOT . DS . "plugins" . DS . "vmpayment" . DS . "mpay24" . DS . "mapping", 'w+');

		if(isset($mpay24)) {
			// get payment systems/methods configured by mpay24
			$result = $mpay24->getPaymentMethods(); // get configured payment methods by mPAY24
			// rewrite the config script
			if($result->getStatus() == "OK") {
				$all = $result->getAll();
			} else {
				// no request can be made and configuration is set back
				$modablePaymentData['mpay24_conf_paysystem'] = '0';
				echo $result->returnCode;
				$this->logInfo(JText::_('VMPAYMENT_MPAY24_BMSG_CONNECTION') . "\n" . $result->returnCode);
			}
		} else {
			// if mpay24 instance is not create, no request can be made and configuration is set back
			$modablePaymentData['mpay24_conf_paysystem'] = '0';
			echo JText::_('VMPAYMENT_MPAY24_BMSG_AUTH');
			$this->logInfo(JText::_('VMPAYMENT_MPAY24_BMSG_AUTH'));
		}

		$i = 1;
		while (!feof($reading)) {
			$line = fgets($reading);
			if (strstr($line,'mpay24_conf_paysystem_'.$i) !== FALSE) {
				if($selfConfigure && isset($all)) { // if selfconfigure is false, set all elements hidden
					if($i <= $all) {
						$line = "\t\t".'<param type="radio" name="mpay24_conf_paysystem_'.$i.'" default="1" '.
								'label="'.$result->getDescription($i-1).'" description="'.$result->getDescription($i-1).'" >' ."\r\n";

						$map = $i.'|'.$modablePaymentData['mpay24_conf_paysystem_'.$i].'|'.$result->getPType($i-1).'|'.$result->getBrand($i-1)."\r\n";
						fputs($mapping, $map);
					} else {
						$line = "\t\t".'<param type="hidden" name="mpay24_conf_paysystem_'.$i.'" default="1" >' . "\r\n";
					}
				} else {
					$line = "\t\t".'<param type="hidden" name="mpay24_conf_paysystem_'.$i.'" default="1" >' . "\r\n";
				}
				$i++;
			}
			fputs($writing, $line);
		}
		fclose($reading); fclose($writing); fclose($mapping);
		rename(JPATH_ROOT . DS . "plugins" . DS . "vmpayment" . DS . "mpay24" . DS . "mpay24.xml.tmp",
				JPATH_ROOT . DS . "plugins" . DS . "vmpayment" . DS . "mpay24" . DS . "mpay24.xml");

		// convert to string
		$tmp = '';
		foreach($modablePaymentData as $k => $p) {
			$tmp .= $k . '="' . $p . '"|';
		}
		$modablePaymentData = $tmp;
		$data->payment_params = $modablePaymentData;
		return $this->declarePluginParams ('payment', $name, $id, $data);
	}

	// @override
	function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
		return $this->setOnTablePluginParams ($name, $id, $table);
	}

	private function getInternalData($virtuemart_order_id, $orderNr = '') {
		$db = JFactory::getDBO ();
		$q = 'SELECT * FROM `' . $this->_tablename . '` WHERE ';
		if ($orderNr) {
			$q .= " `tid` = '" . $orderNr . "'";
		} else {
			$q .= ' `virtuemart_order_id` = ' . $virtuemart_order_id;
		}

		$db->setQuery ($q);
		if (!($paymentTable = $db->loadObject ())) {
			$this->logInfo(__FUNCTION__.' '.$db->getErrorMsg(), 'error');
			return '';
		}
		return $paymentTable;
	}

	private function getPaymentStatus($method, $status) {
		if (strcmp($status, 'BILLED') == 0) {
			$newStatus = $method->mpay24_conf_status_success;
		} elseif (strcmp($status, 'SUSPENDED') == 0) {
			$newStatus = $method->mpay24_conf_status_pending;
		} elseif (strcmp($status, 'RESERVED') == 0) {
			$newStatus = $method->mpay24_conf_status_pending;
		} elseif (strcmp($status, 'REVERSED') == 0) {
			$newStatus = $method->mpay24_conf_status_failed;
		} elseif (strcmp($status, 'CREDITED') == 0) {
			$newStatus = $method->mpay24_conf_status_credited;
		} else { // if status is error or is unknown, set to error
			$this->logInfo(__FUNCTION__.' unknown status, set to FAILED: ' . $status, 'message');
			$newStatus = $method->mpay24_conf_status_failed;
		}
		return $newStatus;
	}

	private function createPaymentInstance($method, $merchant = null, $pass = null, $modus = null, $debug = null) {
		if(isset($method)) {
			$merchant = $method->mpay24_conf_merchantid;
			$pass = $method->mpay24_conf_password;
			$modus = $method->mpay24_conf_modus;
			$debug = $method->mpay24_conf_debug;
		}
		if(isset($merchant) && isset($pass) && $merchant != "" && $pass != "") {
			$modus = ($modus == 'true' || ($modus != 'true' && $modus != 'false')) ? TRUE : FALSE;
			$debug = ($debug == 'true' || ($debug != 'true' && $debug != 'false')) ? TRUE : FALSE;
			$this->logInfo(__FUNCTION__.': create shop instance without proxy (' . ($modus) ? 'used productive system' : 'used test system' . ')', 'message');
			return $mPAY24Instance = new mpay24($merchant, $pass, $modus, $debug);
		} else {
			$this->logInfo(__FUNCTION__.' didn\'t create shop instance (!) ', 'error');
			return NULL;
		}
	}

	private function getParams($extension, $search, $data = null, $returnSet = FALSE) {
		if($data == null) {
			$q = 'SELECT `manifest_cache` FROM `#__extensions` ' .
					'WHERE `element`="' . $extension . '" ';
			$db = JFactory::getDBO();
			$db->setQuery($q);
			$data = $db->loadResult();
			$params = str_replace('":"', '=', str_replace('","', '&', $data));
		} else {
			$params = str_replace('="', '=', str_replace('"|', '&', $data));
		}
		$test = array();
 		parse_str($params, $test);
 		if($returnSet) {
 			return $test;
 		} else {
 			return isset($test[$search]) ? $test[$search] : NULL;
 		}
	}
}

// No closing tag
