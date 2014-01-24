<?php

class GOOGLE_CHECKOUT_HANDLER
{
	// The Interspire Checkout Module (checkout_googlecheckout)
	private $module = null;

	// The google response object
	private $response = null;

	// The google request object
	private $request = null;

	// The google notification serial number;
	private $serial = null;

	// A shortcut for logging in this class
	private $logtype = null;

	// Coupon codes to be removed at the end of a merchant-calculations-callback
	private $removeCouponCodes = array();

	// Gift certificate codes to be removed at the end of a merchant-calculations-callback
	private $removeGiftCertificateCodes = array();

	/**
	 * @var ISC_QUOTE ISC_QUOTE Instance when checking out.
	 */
	protected $quote = null;

	/**
	 * The constructor. If you pass in xml_response then it will automatically call HandleRequest for you too
	 *
	 * @return void
	 **/
	public function __construct($xml_response = null)
	{
		// If the google checkout module is not enabled and configured we don't need to do anything
		GetModuleById('checkout', $this->module, 'checkout_googlecheckout');

		if (!$this->module) {
			$GLOBALS['ISC_CLASS_LOG']->LogSystemError(array('payment', 'checkout_googlecheckout'), 'Google checkout not configured.');
			die();
		}

		$this->logtype = array('payment', $this->module->_name);

		require_once(dirname(__FILE__).'/library/googleresponse.php');
		$this->response = new GoogleResponse($this->module->GetValue('merchantid'), $this->module->GetValue('merchanttoken'));

		if ($xml_response !== null) {
			$this->HandleRequest($xml_response);
		}
	}

	/**
	 * Handle an xml request and perform actions based on the type of request
	 *
	 * @param string the raw xml request
	 *
	 * @return void
	 **/
	public function HandleRequest($xml_response)
	{
		list($root, $data) = $this->response->GetParsedXML($xml_response);

		if(!empty($data[$root]['serial-number']))
			$this->serial = $data[$root]['serial-number'];

		$this->response->SetMerchantAuthentication($this->module->GetValue('merchantid'), $this->module->GetValue('merchanttoken'));
		$status = $this->response->HttpAuthentication();
		if(!$status) {
			$GLOBALS['ISC_CLASS_LOG']->LogSystemError($this->logtype, sprintf(GetLang('GoogleCheckoutHandlerInvalidAuth'), isc_html_escape(GetIp())));
			die();
		}

		$this->module->DebugLog($xml_response);

		$GLOBALS['ISC_CLASS_LOG']->LogSystemDebug($this->logtype, 'Authenticated request of type '.isc_html_escape($root).' recieved.');

		switch ($root) {
			case "request-received":
			case "error":
			case "diagnosis":
			case "checkout-redirect":
			{
				break;
			}
			case "new-order-notification":
			{
				$this->module->cartid = $data[$root]['shopping-cart']['merchant-private-data']['VALUE'];

				$GLOBALS['ISC_CLASS_LOG']->LogSystemDebug($this->logtype, 'New order notification recieved for cart id: '.isc_html_escape($this->module->cartid));

				$this->CreateOrder();

				$this->SendAck();
				break;
			}
			case "order-state-change-notification":
			{
				$this->HandleStateChange($data[$root]);
				$this->SendAck();
				break;
			}
			case "authorization-amount-notification":
			{
				$this->HandleAuthorizationAmountNotification($root, $data);
				$this->SendAck();
				break;
			}
			case "charge-amount-notification":
			{
				$this->HandleAmountNotification($root, $data);
				$this->SendAck();
				break;
			}
			case "chargeback-amount-notification":
			{
				$this->HandleAmountNotification($root, $data);
				$this->SendAck();
				break;
			}
			case "refund-amount-notification":
			{
				$this->HandleAmountNotification($root, $data);
				$this->SendAck();
				break;
			}
			case "risk-information-notification":
			{
				$GLOBALS['ISC_CLASS_LOG']->LogSystemSuccess($this->logtype, GetLang('GoogleCheckoutHandlerGotRiskInfo'));
				$this->ProcessRiskInformation($data[$root]);
				$this->SendAck();
				break;
			}
			case 'merchant-calculation-callback':
			{
				$this->HandleMerchantCallback($data);
				break;
			}
			default:
			{
				$this->response->SendBadRequestStatus("Invalid or not supported Message");
				break;
			}
		}
	}

	/**
	 * Send google an acknowledgement with the notification serial
	 * if it was provided.
	 */
	public function SendAck()
	{
		$this->response->SendAck($this->serial);
	}

	/**
	 * Handle the merchant-calculations-callback request from google. This is for calculating post checkout
	 * adjustments to the order total such as tax, shipping , gift certificates and coupon codes
	 *
	 * @param Array $data The parsed array of data representing the google request
	 *
	 * @return void
	 **/
	private function HandleMerchantCallback($data)
	{
		$root = 'merchant-calculation-callback';

		$this->LoadCart($data[$root]['shopping-cart']['merchant-private-data']['VALUE']);

		include_once(dirname(__FILE__).'/library/googlemerchantcalculations.php');
		include_once(dirname(__FILE__).'/library/googleresult.php');

		$currency = GetDefaultCurrency();

		// Strip gift certificates from the quote as they must be applied
		// explicitly on the google checkout page through the merchant-calculations
		// request.
		$this->quote->removeAllGiftCertificates();

		// Split shipping is not supported with Google Checkout
		$this->quote->setIsSplitShipping(false);

		$merchant_calc = new GoogleMerchantCalculations($currency['currencycode']);

		// Apply coupon and gift certificate adjustments
		list($coupons, $giftcerts) = $this->applyCouponsAndGiftCertificates($data);

		$addresses = $this->get_arr_result($data[$root]['calculate']['addresses']['anonymous-address']);
		foreach($addresses as $curr_address) {
			$curr_id		= $curr_address['id'];
			$country		= $curr_address['country-code']['VALUE'];
			$city			= $curr_address['city']['VALUE'];
			$region			= $curr_address['region']['VALUE'];
			$postal_code	= $curr_address['postal-code']['VALUE'];

			$costs = $this->CalculateShippingCost($curr_address);
			$tax = false;
			$zoneInfo = $costs['zone'];
			unset($costs['zone']);

			if($data[$root]['calculate']['tax']['VALUE'] == "true")
				$tax = true;

			// Loop through each shipping method if merchant-calculated shipping
			// support is to be provided
			if(isset($data[$root]['calculate']['shipping']['method'])) {
				$shipping = $this->get_arr_result($data[$root]['calculate']['shipping']['method']);
				foreach($shipping as $curr_ship) {
					$name = $curr_ship['name'];
					$price = 0;
					$shippable = "false";

					foreach ($costs as $key => $cost) {
						if ($zoneInfo['zoneid'] == 1) {
							$shipping_name = $cost['description'];
						} else {
							$shipping_name = $cost['description']. ' ('.$zoneInfo['zonename'].')';
						}

						if ($name != $shipping_name) {
							continue;
						}
						//Compute the price for this shipping method and address id
						if (isset($cost['price'])) {
							$price = $cost['price'];
						}

						$shippable = "true";
						break;
					}

					$shippingDetails = array(
						'name' => $name,
						'shippable' => $shippable
					);

					$merchant_result = $this->newMerchantResult(
						$curr_id,
						$tax,
						$price,
						$shippingDetails,
						$coupons,
						$giftcerts,
						$data);

					$merchant_calc->AddResult($merchant_result);
				}
			} else {
				$merchant_result = $this->newMerchantResult(
					$curr_id,
					$tax,
					null,
					null,
					$coupons,
					$giftcerts,
					$data);

				$merchant_calc->AddResult($merchant_result);
			}
		}

		// Remove addresses and coupons
		$this->removeAppliedCouponsAndGiftCertificates();
		$this->quote->removeAllAddresses();
		$this->module->DebugLog($merchant_calc->GetXML());
		$this->response->ProcessMerchantCalculations($merchant_calc);
	}

	/**
	 * Applies coupons and gift certificates in a merchant-calculations-callback
	 * request.
	 *
	 * @param array merchant-calculation-callback xml data
	 * @return array Returns an array of google coupons and google gift certificate objects.
	 */
	private function applyCouponsAndGiftCertificates($data)
	{
		$root = 'merchant-calculation-callback';
		$giftcerts = array();
		$coupons = array();

		if(isset($data[$root]['calculate']['merchant-code-strings']['merchant-code-string'])) {
			$codes = $this->get_arr_result($data[$root]['calculate']['merchant-code-strings']['merchant-code-string']);

			// Seperate coupon codes form gift card codes
			foreach($codes as $code)
			{
				if($this->isGiftCertificateCode($code['code']))
					$giftcerts[] = $this->applyGiftCertificate($code['code'], $data);
				else
					$coupons[] = $this->applyCouponCode($code['code'], $data);
			}
		}

		return array($coupons, $giftcerts);
	}

	/**
	 * Remove coupons and gift certificates applied using
	 * applyCouponsAndGiftCertificates().
	 *
	 */
	private function removeAppliedCouponsAndGiftCertificates()
	{
		foreach($this->removeCouponCodes as $code) {
			$this->quote->removeCoupon($code);
		}

		$this->quote->removeCouponCodes = array();

		foreach($this->removeGiftCertificateCodes as $code) {
			$this->quote->removeGiftCertificate($code);
		}

		$this->Quote->removeGiftCertificates = array();
	}

	private function newMerchantResult($curr_id, $tax, $price, $shippingDetails, $coupons, $giftcerts, $data)
	{
		$merchant_result = new GoogleResult($curr_id);
		$provider = '';

		if($shippingDetails)
		{
			$merchant_result->SetShippingDetails(
				$shippingDetails['name'],
				$price,
				$shippingDetails['shippable']);

			$provider = $shippingDetails['name'];
		}

		// Add coupons to the result
		foreach($coupons as $coupon)
			$merchant_result->AddCoupons($coupon, $data);

		if($tax) {
			//Compute tax for this address id and shipping type
			$amount = $this->RecalculateTax($data, $price, $provider);
			$merchant_result->SetTaxDetails($amount);
		}

		// Apply gift certificates
		foreach($giftcerts as $giftcert)
			$merchant_result->AddGiftCertificates($giftcert, $data);

		return $merchant_result;
	}

	private function isGiftCertificateCode($code)
	{
		return isc_strlen($code) == GIFT_CERTIFICATE_LENGTH && gzte11(ISC_LARGEPRINT);
	}

	private function getMerchantDataFromItem($item)
	{
		if(!empty($item['merchant-private-item-data']['VALUE']))
			return json_decode($item['merchant-private-item-data']['VALUE']);

		return null;
	}

	private function getItemsFromShoppingCart($data)
	{
		if(!empty($data['shopping-cart']['items']['item']))
			return $data['shopping-cart']['items']['item'];

		return array();
	}

	private function getCouponCodesInShoppingCart($data)
	{
		$codes = array();

		$items = $this->getItemsFromShoppingCart($data);

		foreach($items as $item)
		{
			$itemData = $this->getMerchantDataFromItem($item);

			if($itemData && $itemData->type == "coupon")
				$codes[] = $itemData->code;
		}

		return $codes;
	}

	/**
	 * Calculate the tax based on the anonymised address information provided by google
	 *
	 * @param array $data The data representing the google request
	 *
	 * @return void
	 **/
	private function RecalculateTax($data, $amount, $provider)
	{
		$root = 'merchant-calculation-callback';

		foreach ($data[$root]['calculate']['addresses'] as $data_address) {
			$billingAddress = $this->quote->getBillingAddress();
			$billingAddress->setCity($data_address['city']['VALUE']);
			$billingAddress->setZip($data_address['postal-code']['VALUE']);
			$billingAddress->setCountryByIso2($data_address['country-code']['VALUE']);
			$stateId = getStateByAbbrev($data_address['region']['VALUE'], $billingAddress->getCountryId());
			$billingAddress->setStateById($stateId);

			$shippingAddress = $this->quote->getShippingAddress();
			$shippingAddress->setCity($data_address['city']['VALUE']);
			$shippingAddress->setZip($data_address['postal-code']['VALUE']);
			$shippingAddress->setCountryByIso2($data_address['country-code']['VALUE']);
			$shippingAddress->setStateById($stateId);

			$shippingAddress->setShippingMethod($amount, $provider);
			$tax = $this->quote->getTaxTotal();
			$shippingAddress->invalidateCachedTotals();
			return $tax;
		}
	}

	/**
	 * Calculate the shipping cost for the current order based on the address
	 *
	 * @param array $address The ISC format address array
	 *
	 * @return array
	 **/
	private function CalculateShippingCost($address)
	{
		$shippingDetails = $this->GetAddressFromResponse($address);

		$enableRealTime = true;
		if($this->module->getValue('disablerealtimeshipping') == 1) {
			$enableRealTime = false;
		}

		$methods = $this->quote->getShippingAddress()
			->setAddressByArray($shippingDetails)
			->getAvailableShippingMethods($enableRealTime, false);

		if(!is_array($methods)) {
			$methods = array();
		}

		// Now we have the zone, what available shipping methods do we have?
		$methods['zone'] = getShippingZoneById($this->quote->getShippingAddress()
			->getShippingAddressZone());
		return $methods;
	}

	/**
	 * Revert the session to a previous cart's session
	 *
	 * @param string $cartid The previous session id
	 *
	 * @return boolean true on success
	 **/
	public function LoadCart($cartid)
	{
		// Load the session that the user had when they were checking out
		session_write_close();

		$session = new ISC_SESSION($cartid);

		if (!isset($_SESSION['QUOTE'])) {
			$GLOBALS['ISC_CLASS_LOG']->LogSystemError($this->logtype, sprintf(GetLang('GoogleCheckoutHandlerCantLoadCart'), isc_html_escape($cartid)));
			return false;
		}

		$error = '';

		$this->quote = getCustomerQuote();
		return true;
	}

	/**
	 * Attempt to apply a gift certificate code and return a google
	 * certificate object result.
	 *
	 * @param string $code The gift certificate code
	 * @param array $data The google request array
	 *
	 * @return mixed A google certificate object of the result
	 **/
	private function applyGiftCertificate($code, $data)
	{
		$root = 'merchant-calculation-callback';

		$giftcert = new GoogleGiftcerts("false", $code, 0, GetLang('BadGiftCertificate'));

		try {
			$this->quote->applyGiftCertificate($code);
			$certificates = $this->quote->getAppliedGiftCertificates();
			$this->removeGiftCertificateCodes[] = $code;

			foreach($certificates as $certificate) {
				if($certificate['code'] == $code) {
					break;
				}
			}
			$message = sprintf(GetLang('GiftCertificateAppliedToCart'), $code, GetConfig('CurrencyToken') . $certificate['remaining']);
			$giftcert = new GoogleGiftcerts('true', $code, $certificate['balance'], $message);
		}
		catch(ISC_QUOTE_EXCEPTION $e) {
			return $giftcert;
		}

		return $giftcert;
	}

	/**
	 * Check that a coupon code is valid to apply
	 *
	 * @param string $code The coupon code
	 * @param array $data The google request array
	 *
	 * @return object A google coupon
	 **/
	private function applyCouponCode($code, $data)
	{
		$error = '';
		$root = 'merchant-calculation-callback';

		if(!$coupon = $this->quote->fetchCoupon($code))
		{
			$invalidCoupon = new GoogleCoupons("false", $code, 0, "Invalid coupon code (".$code.")");
			return $invalidCoupon;
		}

		$coupons = $this->quote->getAppliedCoupons();

		if (!empty($coupons[$coupon['couponcode']]))
		{
			$invalidCoupon = new GoogleCoupons("false", $code, 0, 'This coupon has already been applied');
			return $invalidCoupon;
		}

		if (!empty($coupons)) {
			$invalidCoupon = new GoogleCoupons("false", $code, 0, 'You can only apply 1 coupon code');
			return $invalidCoupon;
		}

		try {
			// Add coupon code temoporarily
			$this->quote->applyCoupon($code);
			$this->removeCouponCodes[] = $coupon['couponcode'];
		}
		catch(ISC_QUOTE_EXCEPTION $e) {
			return new GoogleCoupons('false', $code, 0, $e->getMessage());
		}

		// Get applied coupon values
		$coupons = $this->quote->getAppliedCoupons();
		$coupon = $coupons[$coupon['couponcode']];

		$googleCoupon = new GoogleCoupons('true', $code, $coupon['totalDiscount'], $this->GetCouponName($code));
		return $googleCoupon;
	}

	/**
	 * Get the name of a valid coupon code
	 *
	 * @param string A valid coupon code
	 *
	 * @return String The name of the coupon
	 **/
	public function GetCouponName($code)
	{
		$query = "SELECT couponname
		FROM [|PREFIX|]coupons
		WHERE couponcode='".$GLOBALS['ISC_CLASS_DB']->Quote($code)."'";
		$name = $GLOBALS['ISC_CLASS_DB']->FetchOne($query);

		return $name;
	}

	/**
	 * Handle an amount notification for things like charging, refunds etc
	 *
	 * @param string $root The root node of the request
	 * @param array $data The google request array
	 *
	 * @return void
	 **/
	private function HandleAmountNotification($root, $data)
	{
		$googleid = $data[$root]['google-order-number']['VALUE'];
		$orderid = $this->GetOrderIdByGoogleId($googleid);

		$transaction = GetClass('ISC_TRANSACTION');

		switch ($root) {
			case 'charge-amount-notification':
			{
				$amount = $data[$root]['total-charge-amount']['VALUE'];
				$currency = $data[$root]['total-charge-amount']['currency'];
				$message = sprintf(GetLang('GoogleCheckoutTransactionCharge'), FormatPrice($amount), $currency, $orderid);
				$status = TRANS_STATUS_CHARGED;
				break;
			}
			case 'chargeback-amount-notification':
			{
				$amount = $data[$root]['total-chargeback-amount']['VALUE'];
				$currency = $data[$root]['total-chargeback-amount']['currency'];
				$message = sprintf(GetLang('GoogleCheckoutTransactionChargeback'), FormatPrice($amount), $currency, $orderid);
				$status = TRANS_STATUS_CHARGEBACK;

				UpdateOrderStatus($orderid, ORDER_STATUS_CANCELLED, false, true);
				$GLOBALS['ISC_CLASS_LOG']->LogSystemSuccess($this->logtype, sprintf(GetLang('GoogleCheckoutOrderStatusUpdated'), $orderid, GetOrderStatusById(ORDER_STATUS_CANCELLED)));
				break;
			}
			case 'refund-amount-notification':
			{
				$amount = $data[$root]['total-refund-amount']['VALUE'];
				$currency = $data[$root]['total-refund-amount']['currency'];
				$message = sprintf(GetLang('GoogleCheckoutTransactionRefund'), FormatPrice($amount), $currency, $orderid);
				$status = TRANS_STATUS_REFUND;
				UpdateOrderStatus($orderid, ORDER_STATUS_REFUNDED, false, true);
				$GLOBALS['ISC_CLASS_LOG']->LogSystemSuccess($this->logtype, sprintf(GetLang('GoogleCheckoutOrderStatusUpdated'), $orderid, GetOrderStatusById(ORDER_STATUS_REFUNDED)));

				break;
			}
			default:
			{
				$amount = 0;
				$currency = '';
				$message = sprintf(GetLang('GoogleCheckoutTransactionUnknownAmountNotification'), isc_html_escape(print_r($data, true)));
				$status = TRANS_STATUS_ERROR;
				break;
			}
		}

		$transData = array (
			'providerid'		=> 'checkout_googlecheckout',
			'transactiondate'	=> time(),
			'transactionid'		=> $googleid,
			'orderid'			=> $orderid,
			'message'			=> $message,
			'amount'			=> $amount,
			'status'			=> $status,
		);

		$transactionid = $transaction->Create($transData);

		$this->module->DebugLog("Transaction #".$transactionid." created successfully (".$message.")");
	}

	private function HandleAuthorizationAmountNotification($root, $data)
	{
		$googleid = $data[$root]['google-order-number']['VALUE'];
		$orderid = $this->GetOrderIdByGoogleId($googleid);

		$updatedOrder = array(
			'ordpaymentstatus' => 'authorized'
		);

		$GLOBALS['ISC_CLASS_DB']->UpdateQuery('orders', $updatedOrder, "orderid='".(int)$orderid."'");
		UpdateOrderStatus($orderid, ORDER_STATUS_AWAITING_PAYMENT, false, true);
		$GLOBALS['ISC_CLASS_LOG']->LogSystemSuccess($this->logtype, sprintf(GetLang('GoogleCheckoutOrderStatusUpdated'), $orderid, GetOrderStatusById(ORDER_STATUS_AWAITING_PAYMENT)));
	}

	/**
	 * In case the XML API contains multiple open tags with the same value, then invoke this function and perform
	 * a foreach on the resultant array. This takes care of cases when there is only one unique tag or multiple tags.
	 * Examples of this are "anonymous-address", "merchant-code-string" from the merchant-calculations-callback API
	 *
	 * @param string The node
	 *
	 * @return array
	 **/
	private function get_arr_result($child_node)
	{
		$result = array();
		if(isset($child_node)) {
			if($this->is_associative_array($child_node)) {
				$result[] = $child_node;
			}
			else {
				foreach($child_node as $curr_node) {
					$result[] = $curr_node;
				}
			}
		}
		return $result;
	}

	/**
	 * Returns true if a given variable represents an associative array
	 *
	 * @param mixed $var The variable to check if it is an associative array
	 *
	 * @return boolean
	 **/
	private function is_associative_array($var)
	{
		return is_array($var) && !is_numeric(implode('', array_keys($var)));
	}

	/**
	 * Find the ISC order id based on a Google order Id
	 *
	 * @param string The google id
	 *
	 * @return string or false the ISC order id or false if none was found
	 **/
	private function GetOrderIdByGoogleId($googleid)
	{
		static $maps = array();

		if (isset($maps[$googleid])) {
			return $maps[$googleid];
		}

		$query = "SELECT orderid FROM [|PREFIX|]orders WHERE ordpayproviderid = '".$GLOBALS['ISC_CLASS_DB']->Quote($googleid)."'";
		$orderid = $GLOBALS['ISC_CLASS_DB']->FetchOne($query);

		$maps[$googleid] = $orderid;

		if ($orderid === false) {
			$GLOBALS['ISC_CLASS_LOG']->LogSystemError($this->logtype, sprintf(GetLang('GoogleCheckoutMissingOrder'), isc_html_escape($googleid)));
			return false;
		}
		return $orderid;
	}

	/**
	 * Process a risk information notification request
	 *
	 * @param array $data The google request array
	 *
	 * @return void
	 **/
	private function ProcessRiskInformation($data)
	{
		$googleid = $data['google-order-number']['VALUE'];
		$orderid = $this->GetOrderIdByGoogleId($googleid);
		if ($orderid === false) {
			return;
		}

		$approveProtected = (bool) ($this->module->GetValue('autoapproveprotected') === 'YES');

		if ($data['risk-information']['eligible-for-protection']['VALUE'] == 'true' && $approveProtected) {
			UpdateOrderStatus($orderid, ORDER_STATUS_AWAITING_FULFILLMENT, false, true);
		}

		// We only get the customers actual ip when we get the risk information so make sure we update the order with it
		UpdateOrderIpAddress($orderid, $data['risk-information']['ip-address']['VALUE']);
	}

	/**
	 * Handle a change of fulfillment state of an order
	 *
	 * @param array $data The google request array
	 *
	 * @return void
	 **/
	private function HandleFulfillmentStateChange($data)
	{
		$googleid = $data['google-order-number']['VALUE'];

		$orderid = $this->GetOrderIdByGoogleId($googleid);
		if ($orderid === false) {
			return;
		}

		$new_fulfillment_state = $data['new-fulfillment-order-state']['VALUE'];

		switch($new_fulfillment_state) {
			case 'PROCESSING':
			{
				UpdateOrderStatus($orderid, ORDER_STATUS_AWAITING_FULFILLMENT, false, true);
				$GLOBALS['ISC_CLASS_LOG']->LogSystemSuccess($this->logtype, sprintf(GetLang('GoogleCheckoutOrderStatusUpdated'), $orderid, GetOrderStatusById(ORDER_STATUS_AWAITING_FULFILLMENT)));
				break;
			}
			case 'DELIVERED':
			{
				$order = GetOrder($orderid, false);
				if (!OrderIsComplete($order['ordstatus'])) {
					$this->module->debuglog($order);
					UpdateOrderStatus($orderid, ORDER_STATUS_SHIPPED, false, true);
					$GLOBALS['ISC_CLASS_LOG']->LogSystemSuccess($this->logtype, sprintf(GetLang('GoogleCheckoutOrderStatusUpdated'), $orderid, GetOrderStatusById(ORDER_STATUS_SHIPPED)));
				}

				break;
			}
			case 'WILL_NOT_DELIVER':
			{
				UpdateOrderStatus($orderid, ORDER_STATUS_CANCELLED, false, true);
				$GLOBALS['ISC_CLASS_LOG']->LogSystemSuccess($this->logtype, sprintf(GetLang('GoogleCheckoutOrderStatusUpdated'), $orderid, GetOrderStatusById(ORDER_STATUS_CANCELLED)));
				break;
			}
			default:
			break;
		}
	}

	/**
	 * Handle a change of financial state of an order
	 *
	 * @param array $data The google request array
	 *
	 * @return void
	 **/
	private function HandleFinancialStateChange($data)
	{
		$googleid = $data['google-order-number']['VALUE'];

		$orderid = $this->GetOrderIdByGoogleId($googleid);
		if ($orderid === false) {
			return;
		}

		$new_financial_state = $data['new-financial-order-state']['VALUE'];

		switch($new_financial_state) {
			case 'REVIEWING':
			{
				UpdateOrderStatus($orderid, ORDER_STATUS_PENDING, false, true);
				$GLOBALS['ISC_CLASS_LOG']->LogSystemSuccess($this->logtype, sprintf(GetLang('GoogleCheckoutOrderStatusUpdated'), $orderid, GetOrderStatusById(ORDER_STATUS_PENDING)));
				break;
			}
			case 'CHARGEABLE':
			{
				// Mark as void
				$updatedOrder = array(
					'ordpaymentstatus' => 'authorized'
				);
				$GLOBALS['ISC_CLASS_DB']->UpdateQuery('orders', $updatedOrder, "orderid='".(int)$orderid."'");
				UpdateOrderStatus($orderid, ORDER_STATUS_AWAITING_PAYMENT, false, true);
				$GLOBALS['ISC_CLASS_LOG']->LogSystemSuccess($this->logtype, sprintf(GetLang('GoogleCheckoutOrderStatusUpdated'), $orderid, GetOrderStatusById(ORDER_STATUS_AWAITING_PAYMENT)));
				break;
			}
			case 'CHARGING':
			{
				// We don't need to do anything on our end when Google is midway through charging an order
				break;
			}
			case 'CHARGED':
			{
				$order = GetOrder($orderid, false);

				if (!OrderIsComplete($order['ordstatus'])) {

					// Mark the payment as captured
					$updatedOrder = array(
						'ordpaymentstatus' => 'captured'
					);
					$GLOBALS['ISC_CLASS_DB']->UpdateQuery('orders', $updatedOrder, "orderid='".(int)$orderid."'");

					$this->module->debuglog($order);

					if ($order['ordisdigital'] == 1) {
						UpdateOrderStatus($orderid, ORDER_STATUS_COMPLETED, true, true);
						$GLOBALS['ISC_CLASS_LOG']->LogSystemSuccess($this->logtype, sprintf(GetLang('GoogleCheckoutOrderStatusUpdated'), $orderid, GetOrderStatusById(ORDER_STATUS_COMPLETED)));
					}
					else {
						$status = $this->module->GetValue('orderchargestatus');
						if(!$status) {
							$status = ORDER_STATUS_AWAITING_FULFILLMENT;
						}
						UpdateOrderStatus($orderid, $status, false, true);
						$GLOBALS['ISC_CLASS_LOG']->LogSystemSuccess($this->logtype, sprintf(GetLang('GoogleCheckoutOrderStatusUpdated'), $orderid, GetOrderStatusById($status)));
					}
				}
				break;
			}
			case 'PAYMENT_DECLINED':
			{
				// Mark as void
				$updatedOrder = array(
					'ordpaymentstatus' => 'void'
				);
				$GLOBALS['ISC_CLASS_DB']->UpdateQuery('orders', $updatedOrder, "orderid='".(int)$orderid."'");
				UpdateOrderStatus($orderid, ORDER_STATUS_DECLINED, false, true);
				$GLOBALS['ISC_CLASS_LOG']->LogSystemSuccess($this->logtype, sprintf(GetLang('GoogleCheckoutOrderStatusUpdated'), $orderid, GetOrderStatusById(ORDER_STATUS_DECLINED)));
				break;
			}
			case 'CANCELLED':
			{
				// Mark as void
				$updatedOrder = array(
					'ordpaymentstatus' => 'void'
				);
				$GLOBALS['ISC_CLASS_DB']->UpdateQuery('orders', $updatedOrder, "orderid='".(int)$orderid."'");
				UpdateOrderStatus($orderid, ORDER_STATUS_CANCELLED, false, true);
				$GLOBALS['ISC_CLASS_LOG']->LogSystemSuccess($this->logtype, sprintf(GetLang('GoogleCheckoutOrderStatusUpdated'), $orderid, GetOrderStatusById(ORDER_STATUS_CANCELLED)));
				break;
			}
			case 'CANCELLED_BY_GOOGLE':
			{
				// Mark as void
				$updatedOrder = array(
					'ordpaymentstatus' => 'void'
				);
				$GLOBALS['ISC_CLASS_DB']->UpdateQuery('orders', $updatedOrder, "orderid='".(int)$orderid."'");
				UpdateOrderStatus($orderid, ORDER_STATUS_CANCELLED, false, true);
				$GLOBALS['ISC_CLASS_LOG']->LogSystemSuccess($this->logtype, sprintf(GetLang('GoogleCheckoutOrderStatusUpdated'), $orderid, GetOrderStatusById(ORDER_STATUS_CANCELLED)));
				break;
			}
			default:
			break;
		}
	}

	/**
	 * Handle a change of state of an order. Usually it will be just a financial or fulfilment state change
	 * however it technically could be both at once
	 *
	 * @param array $data The google request array
	 *
	 * @return void
	 **/
	private function HandleStateChange($data)
	{
		$new_financial_state = $data['new-financial-order-state']['VALUE'];
		$new_fulfillment_order = $data['new-fulfillment-order-state']['VALUE'];

		$old_financial_state = $data['previous-financial-order-state']['VALUE'];
		$old_fulfillment_order = $data['previous-fulfillment-order-state']['VALUE'];

		if ($new_financial_state !== $old_financial_state) {
			$this->HandleFinancialStateChange($data);
		}
		if ($new_fulfillment_order !== $old_fulfillment_order) {
			$this->HandleFulfillmentStateChange($data);
		}
	}

	/**
	 * Create a new order in ISC based on a new-order-notification from google
	 *
	 * @return void
	 **/
	private function CreateOrder()
	{
		if(!$this->LoadCart($this->module->cartid))
		{
			// Todo: What is the correct way to fail here?
			return;
		}

		// Ensure split shipping is disabled
		$this->quote->setIsSplitShipping(false);

		// Set the billing address for the order
		$billingAddress =
			$this->GetAddressFromResponse($this->response->data[$this->response->root]['buyer-billing-address']);
		$this->quote->getBillingAddress()
			->setAddressByArray($billingAddress);

		if(!$this->quote->isDigital()) {
			// Set the shipping address for the order
			$shippingAddress =
				$this->GetAddressFromResponse($this->response->data[$this->response->root]['buyer-shipping-address']);
			$this->quote->getShippingAddress()
				->setAddressByArray($shippingAddress);

			// Attempt to find shipping costs in the response from Google
			if (isset($this->response->data[$this->response->root]['order-adjustment']['shipping']['merchant-calculated-shipping-adjustment'])) {
				$shipping = $this->response->data[$this->response->root]['order-adjustment']['shipping']['merchant-calculated-shipping-adjustment'];
			} else {
				$shipping = array (
					'shipping-cost' => array (
						'VALUE' => 0
					),
					'shipping-name' => array (
						'VALUE' => ''
					),
				);
			}

			$this->quote->getShippingAddress()
				->setShippingMethod(
					$shipping['shipping-cost']['VALUE'],
					$shipping['shipping-name']['VALUE'],
					$this->getShippingProviderModuleByName($shipping['shipping-name']['VALUE'])
				);
		}

		$this->handleNewOrderNotificationCouponAdjustment();
		$this->handleNewOrderNotificationGiftCertificateAdjustment();

		$selectedCurrency = getCurrencyById($GLOBALS['CurrentCurrency']);
		$newOrder = array(
			'orderpaymentmodule' => 'checkout_googlecheckout',
			'ordcurrencyid' => $selectedCurrency['currencyid'],
			'ordcurrencyexchangerate' => $selectedCurrency['currencyexchangerate'],
			'ordipaddress' => '',
			'extraInfo' => array(),

			'quote' => $this->quote,
		);

		$entity = new ISC_ENTITY_ORDER();
		$orderId = $entity->add($newOrder);

		// Failed to create the order
		if(!$orderId) {
			$GLOBALS['ISC_CLASS_LOG']->LogSystemError($this->logtype, sprintf(GetLang('GoogleCheckoutMissingCart'), isc_html_escape($this->module->cartid)));
			return;
		}

		$order = getOrder($orderId);

		$googleid = $this->response->data['new-order-notification']['google-order-number']['VALUE'];
		$this->SendGoogleNewOrderId($googleid, $order['orderid']);
		$updatedOrder = array(
			'ordpayproviderid' => $googleid,
		);

		$orderIds = array($order['orderid']);

		// Update the orders in the database
		$GLOBALS['ISC_CLASS_DB']->UpdateQuery('orders', $updatedOrder, "orderid IN (".implode(',', $orderIds).")");

		$completed = CompletePendingOrder($order['ordtoken'], ORDER_STATUS_PENDING, false);

		if ($this->response->data['new-order-notification']['buyer-marketing-preferences']['email-allowed']['VALUE'] == 'true') {
			$this->SubscribeCustomerToLists($order['orderid']);
		}

		if (!$completed) {
			$GLOBALS['ISC_CLASS_LOG']->LogSystemError($this->logtype, sprintf(GetLang('GoogleCheckoutCantCompleteOrder'), isc_html_escape($pendingToken), isc_html_escape(var_export($completed, true))));
			return;
		}

		EmptyCartAndKillCheckout();
		$GLOBALS['ISC_CLASS_LOG']->LogSystemSuccess($this->logtype, sprintf(GetLang('GoogleCheckoutOrderCreated'), (int) $order['orderid'], isc_html_escape($googleid)));
	}

	private function handleNewOrderNotificationCouponAdjustment()
	{
		$data = $this->response->data;
		$root = $this->response->root;

		if(empty($data[$root]['order-adjustment']['merchant-codes']['coupon-adjustment']))
			return;

		$coupons = $this->get_arr_result(
			$data[$root]['order-adjustment']['merchant-codes']['coupon-adjustment']);

		foreach($coupons as $coupon) {
			try	{
				$this->quote->applyCoupon($coupon['code']['VALUE']);

				// TODO: Verify our discount coupon amount and
				// google's coupon amount match, otherwise cancel
				// order.
			}
			catch(ISC_QUOTE_EXCEPTION $e) {
				// TODO: Invalid coupon at order creation
				// cancel order.
			}
		}
	}

	private function handleNewOrderNotificationGiftCertificateAdjustment()
	{
		$data = $this->response->data;
		$root = $this->response->root;

		if(empty($data[$root]['order-adjustment']['merchant-codes']['gift-certificate-adjustment']))
			return;

		$giftcerts = $this->get_arr_result(
			$data[$root]['order-adjustment']['merchant-codes']['gift-certificate-adjustment']);

		foreach($giftcerts as $giftcert)
		{
			try {
				$this->quote->applyGiftCertificate($giftcert['code']['VALUE']);

				// TODO: Verify our gift certificate usage amount and
				// google's usage amount match, otherwise cancel
				// order.
			}
			catch(ISC_QUOTE_EXCEPTION $e) {
				// TODO: Invalid gift cert at order creation
				// cancel order.
			}
		}
	}

	/**
	 * Get's the shipping provider module id from a name
	 *
	 * @param string $name The name to get the shipping module id for
	 *
	 * @return string
	 **/
	public function GetShippingProviderModuleByName($name)
	{
		// $cost['description']. ' ('.$zoneInfo['zonename'].')'

		$shipping_zones = GetShippingZoneInfo();


		foreach ($shipping_zones as $shipping_zone) {
			if (!isset($shipping_zone['methods']) || !is_array($shipping_zone['methods'])) {
				continue;
			}

			foreach ($shipping_zone['methods'] as $shipping_method) {
				if ($shipping_method['methodname'] == $name) {
					// Check for static method names
					return $shipping_method['methodmodule'];
				}

				if ($shipping_method['methodname']. ' ('.$shipping_zone['zonename'].')' == $name) {
					// Check for static method names
					return $shipping_method['methodmodule'];
				}

				foreach (array_keys($shipping_method['vars']) as $shipping_var) {
					$test_name = $shipping_method['methodname'].' '.$shipping_var;
					if ($test_name == $name) {
						// Check for real time shipping names
						return $shipping_method['methodmodule'];
					}
				}
			}

		}

		return $name;
	}

	/**
	 * Convert a google request format address to an ISC format address
	 *
	 * @param array $top The google formatted address array
	 *
	 * @return array The ISC format address
	 **/
	public function GetAddressFromResponse($top)
	{
		include_once(ISC_BASE_PATH.'/lib/shipping.php');

		$countryid = GetCountryIdByISO2($top['country-code']['VALUE']);

		$address = array (
			'shipcity'		=> $top['city']['VALUE'],
			'shipstate'		=> GetStateNameByAbbrev($top['region']['VALUE'], $countryid),
			'shipzip'		=> $top['postal-code']['VALUE'],
			'shipcountry'	=> GetCountryById($countryid),
			'shipcountryid' => $countryid,
			'shipstateid'	=> GetStateByAbbrev($top['region']['VALUE'], $countryid),
		);

		// If we don't have the contact name then this is an anonymous request so we dont have any of the
		// other personally identifyable information
		if (isset($top['contact-name']['VALUE'])) {

			$name = $top['contact-name']['VALUE'];
			$name = explode(' ', $name, 2);

			$address['shipfirstname']	= $name[0];
			$address['shiplastname']	= $name[1];
			$address['shipaddress1']	= $top['address1']['VALUE'];
			$address['shipaddress2']	= $top['address2']['VALUE'];
			$address['shipcompany']		= $top['company-name']['VALUE'];
			$address['shipemail']		= $top['email']['VALUE'];
			$address['shipphone']		= $top['phone']['VALUE'];
		}

		return $address;
	}

	/**
	 * Subscribe a customer to newsletter and other lists based on their order
	 * if they have opted in to them
	 *
	 * @param array $orderRow An array that is ready to be passed to CreateOrder()
	 *
	 * @return void
	 */
	public function SubscribeCustomerToLists($orderid)
	{
		$orderRow = getOrder($orderid);

		if ($orderRow === false) {
			return;
		}

		// No point trying to subscribe them if we don't have an email to subscribe them with
		if (trim($orderRow['ordbillemail']) == '') {
			return;
		}

		// If the customer didn't opt in, stop immediately
		if ($this->response->data['new-order-notification']['buyer-marketing-preferences']['email-allowed']['VALUE'] != 'true') {
			return;
		}

		// Should we add them to our newsletter mailing list?
		$this->SubscribeCustomerToNewsletter($orderRow['ordbillemail'], $orderRow['ordbillfirstname']);

		// Should we add them to our special offers & discounts mailing list?
		$this->SubscribeCustomerToOtherLists($orderRow);
	}

	/**
	 * Subscribe a customer to newsletter if they have opted in to them
	 *
	 * @param string $email The customers email
	 * @param string $first_name The customers first name
	 *
	 * @return void
	 */
	public function SubscribeCustomerToNewsletter($email, $first_name)
	{
		// If the customer didn't opt in, stop immediately
		if ($this->response->data['new-order-notification']['buyer-marketing-preferences']['email-allowed']['VALUE'] != 'true') {
			return;
		}

		$subscription = new Interspire_EmailIntegration_Subscription_Newsletter($email, $first_name);
		$subscription->routeSubscription();
	}

	/**
	 * Subscribe a customer to any other lists based on their order if they have opted in to them
	 *
	 * @param array $orderRow An array that is ready to be passed to CreateOrder()
	 *
	 * @return void
	 */
	public function SubscribeCustomerToOtherLists($orderRow)
	{
		// If the customer didn't opt in, stop immediately
		if ($this->response->data['new-order-notification']['buyer-marketing-preferences']['email-allowed']['VALUE'] != 'true') {
			return;
		}

		$subscription = new Interspire_EmailIntegration_Subscription_Order($orderRow['orderid']);
		$subscription->routeSubscription();
	}

	/**
	 * Send google a Shopping Cart Id to associate with the google id
	 *
	 * @return void
	 **/
	public function SendGoogleNewOrderId($googleid, $orderid)
	{
		$request_result = $this->module->request->SendMerchantOrderNumber($googleid, $orderid);
	}

}