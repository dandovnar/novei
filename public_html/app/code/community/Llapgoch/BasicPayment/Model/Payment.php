<?php
class Llapgoch_BasicPayment_Model_Payment extends Mage_Payment_Model_Method_Abstract{
	// Code to match up with the groups node in default.xml
	protected $_code = "llapgoch_pay";
	// This is the block that's displayed on the checkout
	protected $_formBlockType = 'llapgoch_basicpayment/form_pay';
	// This is the block that's used to add information to the payment info in the admin and previous
	// order screens
	protected $_infoBlockType = 'llapgoch_basicpayment/info_pay';
	
	
	// Use this to set whether the payment method should be available in only certain circumstances
	// This should only allow our payment method for over two items.
	public function isAvailable($quote = null){
		if(!$quote){
			return false;
		}
		
		if($quote->getAllVisibleItems() <= 2){
			return false;
		}
		
		return true;
	}

	public function getShippingAddressFrom($quote)
	{
			$address = $quote->getShippingAddress();

			if(empty($quote) || $quote->getIsVirtual() || empty($address))
					return null;

			$addressId = $address->getAddressId();
			if(empty($addressId))
					return null;

			$firstName = $address->getFirstname();
			if(empty($firstName))
			{
					$address = Mage::getModel('customer/address')->load($address->getAddressId());
					if(!empty($address))
							$firstName = $address->getFirstname();
			}

			if(empty($firstName))
					return null;

			$street = $address->getStreet();

			return [
					"address" => [
							"city" => $address->getCity(),
							"country" => $address->getCountryId(),
							"line1" => $street[0],
							"line2" => (!empty($street[1]) ? $street[1] : null),
							"postal_code" => $address->getPostcode(),
							"state" => $address->getRegion()
					],
					"carrier" => null,
					"name" => $address->getFirstname() . " " . $address->getLastname(),
					"phone" => $address->getTelephone(),
					"tracking_number" => null
			];
	}
	
	// Errors are handled as a javascript alert on the client side
	// This method gets run twice - once on the quote payment object, once on the order payment object
	// To make sure the values come across from quote payment to order payment, use the config node sales_convert_quote_payment
    public function validate(){
       parent::validate();
	  
       return $this;
   }

	protected function getParamsFrom($quote, $payment = null)
	{
			if (Mage::getStoreConfig('payment/stripe_payments/use_store_currency'))
			{
					$amount = $quote->getGrandTotal();
					$currency = $quote->getQuoteCurrencyCode();
			}
			else
			{
					if ($this->helper->isMultiShipping())
							$amount = $payment->getOrder()->getBaseGrandTotal();
					else
							$amount = $quote->getBaseGrandTotal();

					$currency = $quote->getBaseCurrencyCode();
			}

			$cents = 100;
			if ($this->helper->isZeroDecimal($currency))
					$cents = 1;

			$this->params['amount'] = round($amount * $cents);
			$this->params['currency'] = strtolower($currency);
			$this->params['capture_method'] = $this->getCaptureMethod();
			$this->params['payment_method_types'] = ['card', 'affirm', 'afterpay_clearpay']; // For now

			$statementDescriptor = Mage::getStoreConfig('payment/stripe_payments/statement_descriptor');
			if (!empty($statementDescriptor))
					$this->params["statement_descriptor"] = $statementDescriptor;
			else
					unset($this->params['statement_descriptor']);

			$shipping = $this->getShippingAddressFrom($quote);
			if ($shipping)
					$this->params['shipping'] = $shipping;
			else
					unset($this->params['shipping']);

			return $this->params;
	}
	   
	 public function create($quote = null, $payment = null)
	{
			$this->getParamsFrom($quote, $payment);

			return $this;
	}

}