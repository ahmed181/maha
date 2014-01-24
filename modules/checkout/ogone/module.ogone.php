<?php
class CHECKOUT_OGONE extends ISC_CHECKOUT_PROVIDER
{
	protected $requiresSSL = false;

	public $_id = "checkout_ogone";

	protected $_currenciesSupported = array('AED','ANG', 'AUD', 'AWG', 'BGN', 'BRL', 'BYR', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EEK', 'EGP', 'EUR', 'GBP', 'GEL', 'HKD', 'HRK', 'HUF', 'ILS', 'ISK', 'JPY', 'KRW','LTL', 'LVL', 'MAD', 'MXN', 'NOK', 'NZD', 'PLN', 'RON', 'RUB', 'SEK', 'SGD', 'SKK', 'THB', 'TRY', 'UAH', 'USD', 'XAF', 'XOF', 'XPF', 'ZAR');

	public $_languagePrefix = 'Ogone';

	public function __construct()
	{
		// Setup the required variables for the Ogone checkout module
		parent::__construct();
		$this->_name = GetLang($this->_languagePrefix.'Name');
		$this->_image = "logo.gif";
		$this->_description = GetLang($this->_languagePrefix.'Desc');

		if (($serverIP = GetServerIP()) === false) {
			$serverIP = GetLang('CantGetServerIP');
		}

		$this->_help = GetLang($this->_languagePrefix.'Help', array(
			"serverIP" => $serverIP,
			"checkoutLink" => $GLOBALS['ShopPathSSL'] . "/checkout.php"
		));
		$this->_height = 0;
	}

	public function IsSupported()
	{
		$currency = GetDefaultCurrency();

		// Check if the default currency is supported by the payment gateway
		if (!in_array($currency['currencycode'], $this->_currenciesSupported)) {
			$this->SetError(sprintf(GetLang($this->_languagePrefix.'CurrecyNotSupported'), implode(',',$this->_currenciesSupported)));
		}

		if($this->HasErrors()) {
			return false;
		}
		else {
			return true;
		}
	}

	/**
	 * Custom variables for the checkout module. Custom variables are stored in the following format:
	 * array(variable_id, variable_name, variable_type, help_text, default_value, required, [variable_options], [multi_select], [multi_select_height])
	 * variable_type types are: text,number,password,radio,dropdown
	 * variable_options is used when the variable type is radio or dropdown and is a name/value array.
	 */
	public function SetCustomVars()
	{
		$this->_variables['displayname'] = array("name" => GetLang($this->_languagePrefix."DisplayName"),
		   "type" => "textbox",
		   "help" => GetLang('DisplayNameHelp'),
		   "default" => $this->GetName(),
		   "required" => true
		);

		$this->_variables['pspid'] = array("name" => GetLang($this->_languagePrefix."Pspid"),
		   "type" => "textbox",
		   "help" => GetLang($this->_languagePrefix.'PspidHelp'),
		   "default" => "",
		   "required" => true
		);

		$this->_variables['signature'] = array("name" => GetLang($this->_languagePrefix."Signature"),
		   "type" => "password",
		   "help" => GetLang($this->_languagePrefix.'SignatureHelp'),
		   "default" => "",
		   "required" => true
		);

		$this->_variables['testmode'] = array("name" => GetLang($this->_languagePrefix."TestMode"),
		   "type" => "dropdown",
		   "help" => GetLang($this->_languagePrefix.'TestModeHelp'),
		   "default" => "no",
		   "required" => true,
		   "options" => array(GetLang($this->_languagePrefix.'TestModeNo') => 'NO',
						  GetLang($this->_languagePrefix.'TestModeYes') => 'YES'
			),
			"multiselect" => false
		);
	}

	public function TransferToProvider()
	{
		$amount = number_format($this->GetGatewayAmount() * 100, 0, '', '');
		$pspid = $this->GetValue("pspid");
		$signature = $this->GetValue("signature");

		if($this->GetValue("testmode") == "YES") {
			$ogone_url = "https://secure.ogone.com/ncol/test/orderstandard.asp";
		}
		else {
			$ogone_url = "https://secure.ogone.com/ncol/prod/orderstandard.asp";
		}

		$billingDetails = $this->GetBillingDetails();

		$currency = GetDefaultCurrency();

		$hiddenFields = array(
			// Ogone Details
			'orderID'		=> $this->GetCombinedOrderId(),
			'PSPID'			=> $pspid,
			'currency' 		=>	$currency['currencycode'],
			'language'		=> 'en_EN',
			'SHASign' 		=> strtoupper(sha1($this->GetCombinedOrderId().$amount.$currency['currencycode'].$pspid.$this->GetValue('signature'))),
			'paramplus'		=> 'OrderToken=' . $_COOKIE['SHOP_ORDER_TOKEN'] . '&SessionToken=' . $_COOKIE['SHOP_SESSION_TOKEN'],

			// Order Details
			'cn' 			=> $billingDetails['ordbillfirstname'] . ' ' . $billingDetails['ordbilllastname'],
			'email'			=> $billingDetails['ordbillemail'],
			'owneraddress'	=> $billingDetails['ordbillstreet1'] . ' ' . $billingDetails['ordbillstreet2']  . ' ' . $billingDetails['ordbillsuburb'] . ' ' .  $billingDetails['ordbillstate'] . ' ' .  $billingDetails['ordbillcountry'],
			'ownerzip'		=> $billingDetails['ordbillzip'],
			'amount'		=> $amount,


			// Notification Details
			'accepturl'		=> GetConfig('ShopPathSSL').'/finishorder.php',
			'declineurl' 	=> GetConfig('ShopPathSSL').'/finishorder.php',
			'exceptionurl'	=> GetConfig('ShopPathSSL').'/finishorder.php',
			'cancelurl'		=> GetConfig('ShopPathSSL').'/cart.php'
		);

		$this->RedirectToProvider($ogone_url, $hiddenFields);
	}

	public function GetOrderToken()
	{
		return @$_COOKIE['SHOP_ORDER_TOKEN'];
	}


	public function VerifyOrderPayment()
	{

		if(!empty($_COOKIE['SHOP_ORDER_TOKEN'])) {
			// No pingback yet, so set something to show the customer
			if($this->GetOrderStatus() == ORDER_STATUS_INCOMPLETE) {
				$this->SetPaymentStatus(PAYMENT_STATUS_PENDING);
			}
			// Always return successful, pingback will do all the work
			return true;
		}
		else {
			// Bad order details
			$GLOBALS['ISC_CLASS_LOG']->LogSystemError(array('payment', $this->_name), GetLang('OgoneErrorInvalid'), __FUNCTION__);
			return false;
		}
	}

	public function ProcessGatewayPing()
	{
		/*
		orderID Your order reference
		amount Order amount (not multiplied by 100)
		currency Currency of the order
		PM Payment method
		ACCEPTANCE Acceptance code returned by acquirer
		STATUS Transaction status
		CARDNO Masked card number
		PAYID Payment reference in our system
		NCERROR Error code
		BRAND Card brand (our system derives it from the card number) or similar information for other payment methods.
		SHASIGN SHA signature composed by our system, if SHA-out configured by you.
		*/

		if(!isset($_REQUEST['OrderToken'])) {
			exit;
		}

		if (!isset($_REQUEST['orderID']) || !isset($_REQUEST['amount']) || !isset($_REQUEST['currency']) || !isset($_REQUEST['STATUS'])) {
			// Bad order details
			$GLOBALS['ISC_CLASS_LOG']->LogSystemError(array('payment', $this->GetName()), GetLang('OgoneErrorInvalid'), print_r($_POST, true));
			return false;
		}

		// ogone response data
		$orderId = $_REQUEST['orderID'];
		$amount = $_REQUEST['amount'];
		$currency = $_REQUEST['currency'];
		$status = $_REQUEST['STATUS'];
		$transactionId = $_REQUEST['PAYID'];
		$errorCode = $_REQUEST['NCERROR'];

		$orderToken = $_REQUEST['OrderToken'];
		$sessionToken = $_REQUEST['SessionToken'];

		$this->SetOrderData(LoadPendingOrdersByToken($orderToken));

		// expected values
		$combinedOrderId = $this->GetCombinedOrderId();
		$gateway_amount = number_format($this->GetGatewayAmount(), 2, '.', '');
		$defaultcurrency = GetDefaultCurrency();

		// verify the SHA Sign
		$sha = strtoupper(sha1($orderId.$currency.$amount.$_REQUEST['PM'].$_REQUEST['ACCEPTANCE'].$status.$_REQUEST['CARDNO'].$transactionId.$errorCode.$_REQUEST['BRAND'].$this->GetValue('signature')));
		if ($sha != $_REQUEST['SHASIGN']) {
			$GLOBALS['ISC_CLASS_LOG']->LogSystemError(array('payment', $this->GetName()), GetLang('OgoneErrorInvalidSHA'), print_r($_POST, true));
			return false;
		}

		// The values passed don't match what we expected
		if($orderId != $combinedOrderId || $amount != $gateway_amount || $currency != $defaultcurrency['currencycode']) {
			$errorMsg = GetLang('OgoneErrorDetailsNoMatch', array(
				"total" => $amount,
				"expectedTotal" => $gateway_amount,
				"orderId" => $orderId,
				"expectedOrderId" => $combinedOrderId,
				"currency" => $currency,
				"expectedCurrency" => $defaultcurrency['currencycode'],
				"status" => $status
			));
			$GLOBALS['ISC_CLASS_LOG']->LogSystemError(array('payment', $this->GetName()), GetLang('OgoneErrorInvalid'), $errorMsg);
			return false;
		}

		$paymentStatus = '';
		$statusLang = $status;
		switch($status) {
			case '0': // incomplete
			case '1': // cancelled by customer
				$newOrderStatus = ORDER_STATUS_INCOMPLETE;
				break;
			case '2': // auth refused
				$newOrderStatus = ORDER_STATUS_DECLINED;
				break;
			case '5': // authorized
				$newOrderStatus = ORDER_STATUS_AWAITING_PAYMENT;
				break;
			case '51': // awaiting authorization
			case '52': // authorization unknown
				$newOrderStatus = ORDER_STATUS_PENDING;
				break;
			case '6': // authorized and cancelled
				$newOrderStatus = ORDER_STATUS_INCOMPLETE;
				break;
			case '7': // payment deleted
			case '74': // payment deleted
				$newOrderStatus = ORDER_STATUS_AWAITING_PAYMENT;
				break;
			case '8': // refund
				$newOrderStatus = ORDER_STATUS_REFUNDED;
				break;
			case '9': // payment authorized and captured
				$newOrderStatus = ORDER_STATUS_AWAITING_FULFILLMENT;
				break;
			case '91': // awaiting payment
			case '93': // payment refused (tech problem or expired auth)
				$newOrderStatus = ORDER_STATUS_AWAITING_PAYMENT;
				break;
			case '92': // unknown payment
				$newOrderStatus = ORDER_STATUS_PENDING;
				break;
			case '94': // payment declined by aquirer
				$newOrderStatus = ORDER_STATUS_DECLINED;
				break;
			default :
				$newOrderStatus = ORDER_STATUS_DECLINED;
				$statusLang = 'Unknown';
				break;
		}

		// If the order was previously incomplete, we need to do some extra work
		if($this->GetOrderStatus() == ORDER_STATUS_INCOMPLETE) {
			session_write_close();
			$session = new ISC_SESSION($sessionToken);
			$orderClass = GetClass('ISC_ORDER');
			$orderClass->EmptyCartAndKillCheckout();
		}

		// update orders with the transaction id
		$updatedOrder = array(
			'ordpayproviderid' => $transactionId
		);

		// if captured then update pay status in order
		if ($newOrderStatus == ORDER_STATUS_AWAITING_FULFILLMENT) {
			$updatedOrder['ordpaymentstatus'] = 'captured';
		}

		$this->UpdateOrders($updatedOrder);

		// we only want to notify the customer of a successfull order
		$emailCustomer = false;
		if ($newOrderStatus != ORDER_STATUS_INCOMPLETE) {
			$emailCustomer = true;
		}

		// update order statuses
		foreach($this->GetOrders() as $orderId => $order) {
			// digital orders should complete right away if captured
			if($order['ordisdigital'] && $newOrderStatus == ORDER_STATUS_AWAITING_FULFILLMENT) {
				$newOrderStatus = ORDER_STATUS_COMPLETED;
			}

			UpdateOrderStatus($orderId, $newOrderStatus, $emailCustomer);
		}

		// Log this payment response
		$oldStatus = GetOrderStatusById($order['ordstatus']);
		if(!$oldStatus) {
			$oldStatus = 'Incomplete';
		}

		$newStatus = GetOrderStatusById($newOrderStatus);
		if (!$newStatus) {
			$newStatus = 'Incomplete';
		}

		$extra = GetLang('OgoneSuccessDetails', array(
			"orderId" => implode(', ', array_keys($this->GetOrders())),
			"amount" => $gateway_amount,
			"paymentId" => $transactionId,
			"paymentStatus" => $status,
			"paymentDesc" => GetLang('OgoneTransactionStatus' . $statusLang),
			"newStatus" => $newStatus,
			"oldStatus" => $oldStatus
		));
		$GLOBALS['ISC_CLASS_LOG']->LogSystemSuccess(array('payment', $this->_name), GetLang('OgoneSuccess'), $extra);

		return true;
	}
}