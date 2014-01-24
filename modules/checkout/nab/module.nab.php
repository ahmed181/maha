<?php

class CHECKOUT_NAB extends ISC_CHECKOUT_PROVIDER
{

	/**
	 * @var boolean Does this payment provider require SSL?
	 */
	protected $requiresSSL = false;

	/**
	 * @var boolean Does this provider support orders from more than one vendor?
	 */
	protected $supportsVendorPurchases = true;

	/**
	 * @var boolean Does this provider support shipping to multiple addresses?
	 */
	protected $supportsMultiShipping = true;

	/**
	 * @var string Should the order be passed through in test mode?
	 */
	private $_testmode = "";

	/**
	 *	Checkout class constructor
	 */
	public function __construct()
	{
		parent::__construct();
		$this->_name = GetLang('NabName');
		$this->_image = "nab_logo.gif";
		$this->_description = GetLang('NabDesc');
		$this->_help = sprintf(GetLang('NabHelp'), $GLOBALS['ShopPathSSL']);
	}

	/**
	 * Custom variables for the checkout module. Custom variables are stored in the following format:
	 * array(variable_id, variable_name, variable_type, help_text, default_value, required, [variable_options], [multi_select], [multi_select_height])
	 * variable_type types are: text,number,password,radio,dropdown
	 * variable_options is used when the variable type is radio or dropdown and is a name/value array.
	 */
	public function SetCustomVars()
	{
		$this->_variables['displayname'] = array("name" => GetLang('NabDisplayName'),
		   "type" => "textbox",
		   "help" => GetLang('DisplayNameHelp'),
		   "default" => $this->GetName(),
		   "required" => true
		);

		$this->_variables['vendor_name'] = array("name" => GetLang('NabVendorName'),
		   "type" => "textbox",
		   "help" => GetLang('NabVendorNameHelp'),
		   "default" => "",
		   "required" => true
		);

		$this->_variables['email'] = array("name" => GetLang('NabPaymentEmail'),
		   "type" => "textbox",
		   "help" => GetLang('NabPaymentAlertHelp'),
		   "default" => "",
		   "required" => true
		);

		$this->_variables['serverips'] = array("name" => GetLang('NabServerIPs'),
			"type" => "textarea",
			"help" => GetLang('NabServerIPsHelp'),
			"default" => "203.89.255.155",
			"required" => false
		);

		$this->_variables['testmode'] = array("name" => GetLang('NabTestMode'),
		   "type" => "dropdown",
		   "help" => GetLang("NabTestModeHelp"),
		   "default" => "no",
		   "required" => true,
		   "options" => array(GetLang("NabTestModeNo") => "NO",
						  GetLang("NabTestModeYes") => "YES"
			),
			"multiselect" => false
		);
	}

	public function TransferToProvider()
	{
		$total = number_format($this->GetGatewayAmount(), 2, '.', '');

		$testmode_on = $this->GetValue("testmode");

		if($testmode_on == "YES") {
			$nab_url = 'https://transact.nab.com.au/test/hpp/payment';
		}
		else {
			$nab_url = 'https://transact.nab.com.au/live/hpp/payment';
		}

		$billingDetails = $this->GetBillingDetails();

		$orders = $this->GetOrders();
		$orderIds = array();
		foreach($orders as $order) {
			$orderIds[] = '#'.$order['orderid'];
		}
		$orderIdAppend = '('.implode(', ', $orderIds).')';

		$merge_products = array();
		$name = sprintf(GetLang('YourOrderFromX'), GetConfig('StoreName')).' '.$orderIdAppend;

		$merge_products[$name] = "1,$total";

		if(empty($_COOKIE['SHOP_ORDER_TOKEN'])) {
			$payment_reference = $_COOKIE['SHOP_SESSION_TOKEN'];
		}
		else {
			$payment_reference = $_COOKIE['SHOP_ORDER_TOKEN'];
		}

		$hiddenFields = array(
			'vendor_name'			=>	$this->GetValue('vendor_name'),
			'payment_reference'		=>	$payment_reference,
			'payment_alert'			=>  $this->GetValue('email'),
			'Name'					=>	$billingDetails['ordbillfirstname'].' '.$billingDetails['ordbilllastname'],
			'Phone'					=>	$billingDetails['ordbillphone'],
			'Email'					=>	$billingDetails['ordbillemail'],
			'Postal Code'			=>	$billingDetails['ordbillzip'],
			'City'					=>	$billingDetails['ordbillsuburb'],
			'State'					=>	$billingDetails['ordbillstate'],
			'Street'				=>	$billingDetails['ordbillstreet1'],
			'information_fields'	=>	'Name,Phone,Email,Postal Code,City,State,Street',
			'return_link_url'		=> 	GetConfig('ShopPathSSL').'/finishorder.php',
			'reply_link_url'		=> 	GetConfig('ShopPathSSL').'/checkout.php?action=gateway_ping&provider='.$this->GetId().'&bank_reference=&payment_reference=&payment_amount=&payment_date=&payment_number=',

			// Tax / GST Settings should be handled by the store
			//'gst_added'	=>	'true',
			//'gst_rate'	=>	'10',
		);

		// Merging the product hidden fields with the rest of the fields
		$hidden_fields = array_merge($hiddenFields, $merge_products);

		$this->RedirectToProvider($nab_url, $hidden_fields);
	}

	public function VerifyOrderPayment()
	{
		if(!empty($_COOKIE['SHOP_ORDER_TOKEN'])) {
			// This order is still incomplete, the notification hasn't been received yet, so the payment status is pending
			if($this->GetOrderStatus() == ORDER_STATUS_INCOMPLETE) {
				$this->SetPaymentStatus(PAYMENT_STATUS_PENDING);
			}
			// Always return successful, the pingback will actually validate the order and do all of the magic
			return true;
		}
		else {
			// Bad order details
			$GLOBALS['ISC_CLASS_LOG']->LogSystemError(array('payment', $this->GetName()), GetLang('NabErrorInvalid'), __FUNCTION__);
			return false;
		}
	}
	/**
	 * Process the NAB pingback
	 */
	public function ProcessGatewayPing()
	{
		if(!isset($_REQUEST['payment_reference'])) {
			exit;
		}

		if(!isset($_REQUEST['bank_reference'])) {
			exit;
		}

		$sessionToken = explode('_', $_REQUEST['payment_reference'], 2);

		$this->SetOrderData(LoadPendingOrdersByToken($_REQUEST['payment_reference']));

		$orders = $this->GetOrders();
		list(,$order) = each($orders);

		$serverIPs = explode("\n", trim($this->GetValue('serverips')));
		if(!empty($serverIPs)) {
			$serverIPs = array_map("trim", $serverIPs);
			if (!in_array($_SERVER['REMOTE_ADDR'], $serverIPs)) {
				$GLOBALS['ISC_CLASS_LOG']->LogSystemError(array('payment', $this->GetName()), sprintf(GetLang('NabErrorInvalidIPResponse', array('ipAddress' => $_SERVER['REMOTE_ADDR']))));
				return false;
			}
		}

		$amount = number_format($this->GetGatewayAmount(), 2, '.', '');

		if($amount == 0) {
			exit;
		}

		$transaction = GetClass('ISC_TRANSACTION');

		$previousTransaction = $transaction->LoadByTransactionId($_REQUEST['payment_number'], $this->GetId());

		if(is_array($previousTransaction) && $previousTransaction['transactionid']) {
			$GLOBALS['ISC_CLASS_LOG']->LogSystemError(array('payment', $this->GetName()), sprintf(GetLang('NabTransactionAlreadyProcessed'), $_REQUEST['payment_date']));
			return false;
		}

		// Check to see if it is approved
		if(!$this->isApproved($_REQUEST['bank_reference'])) {
			// Not approved
			$errorMsg = $this->getErrorMessage($_REQUEST['bank_reference']);
			$GLOBALS['ISC_CLASS_LOG']->LogSystemError(array('payment', $this->GetName()), GetLang('NabErrorInvalid'), $errorMsg);
			return false;
		}

		// Need to finish the processing of the pingback
		$newTransaction = array(
			'providerid' => $this->GetId(),
			'transactiondate' => $_REQUEST['payment_date'],
			'transactionid' => $_REQUEST['payment_number'],
			'orderid' => array_keys($this->GetOrders()),
			'message' => 'Completed',
			'status' => '',
			'amount' => $_REQUEST['payment_amount'],
			'extrainfo' => array()
		);

		$newTransaction['status'] = TRANS_STATUS_COMPLETED;
		$newOrderStatus = ORDER_STATUS_AWAITING_FULFILLMENT;

		$transactionId = $transaction->Create($newTransaction);

		foreach($this->GetOrders() as $orderId => $order) {
			$status = $newOrderStatus;
			// If it's a digital order & awaiting fulfillment, automatically complete it
			if($order['ordisdigital'] && $status == ORDER_STATUS_AWAITING_FULFILLMENT) {
				$status = ORDER_STATUS_COMPLETED;
			}
			UpdateOrderStatus($orderId, $status);
		}

		$updatedOrder = array(
			'ordpayproviderid' => $_REQUEST['payment_number'],
			'ordpaymentstatus' => 'captured',
		);

		$this->UpdateOrders($updatedOrder);

		// This was a successful order
		$oldStatus = GetOrderStatusById($this->GetOrderStatus());

		if(!$oldStatus) {
			$oldStatus = 'Incomplete';
		}

		$newStatus = GetOrderStatusById($newOrderStatus);
		$extra = sprintf(GetLang('NabSuccessDetails'), implode(', ', array_keys($this->GetOrders())), $amount, $_REQUEST['bank_reference'], 'Captured', $newStatus, $oldStatus);
		$GLOBALS['ISC_CLASS_LOG']->LogSystemSuccess(array('payment', $this->GetName()), GetLang('NabSuccess'), $extra);
		return true;
	}

	private function isApproved($code)
	{
		$approved_codes = array('00','08');

		if(in_array($code, $approved_codes)) {
			return true;
		}
		else {
			return false;
		}
	}

	private function getErrorMessage($code)
	{
		$codes['01'] = 'Refer to Card Issuer';
		$codes['41'] = 'Lost Card?Pick Up';
		$codes['02'] = 'Refer to Issuer?s Special Conditions ';
		$codes['42'] = 'No Universal Amount';
		$codes['03'] = 'Invalid Merchant ';
		$codes['43'] = 'Stolen Card-Pick Up';
		$codes['04'] = 'Pick Up Card ';
		$codes['44'] = 'No Investment Account';
		$codes['05'] = 'Do Not Honour ';
		$codes['51'] = 'Insuficient Funds';
		$codes['06'] = 'Error ';
		$codes['52'] = 'No Cheque Account';
		$codes['07'] = 'Pick Up Card, Special Conditions ';
		$codes['53'] = 'No Savings Account';
		$codes['09'] = 'Request in Progress ';
		$codes['54'] = 'Expired Card';
		$codes['10'] = 'Partial Amount Approved ';
		$codes['55'] = 'Incorrect PIN';
		$codes['12'] = 'Invalid Transaction ';
		$codes['56'] = 'No Card Record';
		$codes['13'] = 'Invalid Amount ';
		$codes['57'] = 'Trans. not Permitted to Cardholder';
		$codes['14'] = 'Invalid Card Number ';
		$codes['58'] = 'Transaction not Permitted to Terminal';
		$codes['15'] = 'No Such Issuer ';
		$codes['59'] = 'Suspected Fraud';
		$codes['17'] = 'Customer Cancellation ';
		$codes['60'] = 'Card Acceptor Contact Acquirer';
		$codes['18'] = 'Customer Dispute ';
		$codes['61'] = 'Exceeds Withdrawal Amount Limits';
		$codes['19'] = 'Re-enter Transaction ';
		$codes['62'] = 'Restricted Card';
		$codes['20'] = 'Invalid Response ';
		$codes['63'] = 'Security Violation';
		$codes['21'] = 'No Action Taken ';
		$codes['64'] = 'Original Amount Incorrect';
		$codes['22'] = 'Suspected Malfunction ';
		$codes['65'] = 'Exceeds Withdrawal Frequency Limit';
		$codes['23'] = 'Unacceptable Transaction Fee ';
		$codes['66'] = 'Card Acceptor Call Acquirer Security';
		$codes['24'] = 'File Update not Supported by Receiver ';
		$codes['67'] = 'Hard Capture?Pick Up Card at ATM';
		$codes['25'] = 'Unable to Locate Record on File ';
		$codes['68'] = 'Response Received Too Late';
		$codes['26'] = 'Duplicate File Update Record ';
		$codes['75'] = 'Allowable PIN Tries Exceeded';
		$codes['27'] = 'File Update Field Edit Error ';
		$codes['86'] = 'ATMMalfunction';
		$codes['28'] = 'File Update File Locked Out ';
		$codes['87'] = 'No Envelope Inserted';
		$codes['29'] = 'File Update not Successful ';
		$codes['88'] = 'Unable to Dispense';
		$codes['30'] = 'Format Error ';
		$codes['89'] = 'Administration Error';
		$codes['31'] = 'Bank not Supported by Switch';
		$codes['90'] = 'Cut-off in Progress';
		$codes['32'] = 'Completed Partially ';
		$codes['91'] = 'Issuer or Switch is Inoperative';
		$codes['33'] = 'Expired Card-Pick Up';
		$codes['92'] = 'Financial Institution not Found';
		$codes['34'] = 'Suspected Fraud-Pick Up';
		$codes['93'] = 'Trans Cannot be Completed';
		$codes['35'] = 'Contact Acquirer?Pick Up';
		$codes['94'] = 'Duplicate Transmission';
		$codes['36'] = 'Restricted Card-Pick Up';
		$codes['95'] = 'Reconcile Error';
		$codes['37'] = 'Call Acquirer Security?Pick Up';
		$codes['96'] = 'System Malfunction';
		$codes['38'] = 'Allowable PIN Tries Exceeded';
		$codes['97'] = 'Reconciliation Totals Reset';
		$codes['39'] = 'No CREDIT Account';
		$codes['98'] = 'MAC Error';
		$codes['40'] = 'Requested Function not Supported ';
		$codes['99'] = 'Reserved for National Use';

		if(array_key_exists($code, $codes)) {
			return $codes[$code];
		}
		else {
			return GetLang('NabNoErrorCode');
		}
	}
}
