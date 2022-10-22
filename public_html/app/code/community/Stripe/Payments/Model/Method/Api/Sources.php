<?php

class Stripe_Payments_Model_Method_Api_Sources extends Mage_Payment_Model_Method_Abstract
{
		protected $_isInitializeNeeded      = false;
		protected $_canUseForMultishipping  = true;
		protected $_isGateway               = true;
		protected $_canAuthorize            = true;
		protected $_canCapture              = true;
		protected $_canCapturePartial       = true;
		protected $_canRefund               = true;
		protected $_canRefundInvoicePartial = true;
		protected $_canVoid                 = true;
		protected $_canCancelInvoice        = true;
		protected $_canUseInternal          = true;
		protected $_canUseCheckout          = true;
		protected $_canSaveCc               = false;
    //protected $_formBlockType           = 'stripe_payments/form_methodRedirect';

    public $stripe;
    //public static $redirectUrl;

    public function __construct()
    {
        $this->helper = Mage::helper('stripe_payments');
        $this->stripe = Mage::getModel('stripe_payments/method');
				$this->paymentIntent = Mage::getSingleton("stripe_payments/paymentIntent");
        parent::__construct();
    }

    // The Sources API oddly throws an error if an unknown parameter is passed.
    // Delete all non-allowed params
    protected function cleanParams(&$params)
    {
        $allowed = array_flip(array('type', 'amount', 'currency', 'owner', 'redirect', 'metadata', $this->_type));
        $params = array_intersect_key($params, $allowed);
    }

    public function getTestEmail()
    {
        return false;
    }

    public function getTestName()
    {
        return false;
    }

    public function authorize(Varien_Object $payment, $amount)
    {
        parent::authorize($payment, $amount);

        if ($amount > 0)
        {
            try
            {
                $order = $payment->getOrder();
								$quote = $order->getQuote();
                $billingInfo = $this->helper->getSanitizedBillingInfo();
                $params = $this->helper->getStripeParamsFrom($order);
                $params["type"] = $this->_type;

								if($this->_type == 'affirm') {
									$paymentIntentId = Mage::getSingleton("stripe_payments/paymentIntent")->loadFromCache($quote)->id;
									
									$payment->setTransactionId($paymentIntentId);
									$payment->setLastTransId($paymentIntentId);
									$payment->setIsTransactionClosed(0);
									$payment->setIsFraudDetected(false);
									$payment->save();

									 foreach ($invoiceCollection as $invoice)
									{
											if ($invoice->getState() != Mage_Sales_Model_Order_Invoice::STATE_PAID)
													$invoice->pay()->save();
									}

									$comment = Mage::helper("stripe_payments")->__("Payment succeeded.");
									$order->addStatusToHistory($status = false, $comment, $isCustomerNotified = false)
											->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true)
											->save();
									
								}

								else {
									$params["owner"] = array(
											"name" => $order->getCustomerName(),
											// "name" => "succeeding_charge",
											// "name" => "failing_charge",
											"email" => ($this->getTestEmail() ? $this->getTestEmail() : $billingInfo["email"])
									);
									$params["redirect"] = array(
											"return_url" => Mage::getUrl('stripe_payments/return')
									);
									$params["metadata"] = array(
											"Order #" => $order->getIncrementId(),
									);

									if ($this->_type == 'sepa_debit')
									{
											unset($params['amount']); // This will make the source reusable
											$iban = $payment->getAdditionalInformation('iban');
											if (!empty($iban))
													$params['sepa_debit'] = array('iban' => $iban);
											else
													throw new Exception($this->helper->__("No IBAN provided."));
									}

									$statementDescriptor = Mage::getStoreConfig('payment/' . $this->_code . '/statement_descriptor');
									if (!empty($statementDescriptor))
											$params[$this->_type] = array("statement_descriptor" => $statementDescriptor);

									if ($this->_type == 'sofort')
									{
											$address = $this->helper->getBillingAddress();
											$params[$this->_type] = array("country" => $address->getCountry());
									}

									$this->cleanParams($params);

									$source = \Stripe\Source::create($params);

									$payment->setAdditionalInformation('captured', false);
									$payment->setAdditionalInformation('source_id', $source->id);

									if ($this->stripe->saveCards || $this->_type == 'sepa_debit')
									{
											$this->stripe->addCardToCustomer($source->id);
											$customerStripeId = $this->stripe->getCustomerStripeId();
											$payment->setAdditionalInformation('customer_stripe_id', $customerStripeId);
									}
									$payment->save();

									$session = Mage::getSingleton('core/session');
									$session->setRedirectUrl(null);
									if (!empty($source->redirect->url))
											$session->setRedirectUrl($source->redirect->url);
									else if (!empty($source->wechat->qr_code_url))
									{
											$detect = Mage::getModel('stripe_payments/mobileDetect');
											if ($detect->isMobile())
											{
													$session->setRedirectUrl($source->wechat->qr_code_url);
											}
											else
											{
													$payment->setAdditionalInformation('captured', false);
													$payment->setAdditionalInformation('source_id', $source->id);
													$payment->setAdditionalInformation('wechat_qr_code_url', $source->wechat->qr_code_url);
													$payment->setAdditionalInformation('wechat_amount', $source->amount);
													$payment->setAdditionalInformation('wechat_currency', $source->currency);
											}
									}

									$session->setClientSecret($source->client_secret);
									$session->setOrderId($order->getId());
								}		
            }
            catch (\Stripe\Error\Card $e)
            {
                $this->log($e->getMessage());
                Mage::throwException($this->t($e->getMessage()));
            }
            catch (\Stripe\Error $e)
            {
                $this->log($e->getMessage());
                Mage::throwException($this->t($e->getMessage()));
            }
            catch (\Exception $e)
            {
                if (strstr($e->getMessage(), 'Invalid country') !== false)
                    Mage::throwException($this->t("Sorry, this payment method is not available in your country."));

                if (strstr($e->getMessage(), 'Invalid currency: ') !== false)
                    Mage::throwException($this->t("Sorry, the payment method %s cannot be used with the %s currency.", $this->_name, $params["currency"]));

                $this->log($e->getMessage());

                Mage::throwException($this->t($e->getMessage()));
            }
        }

        return $this;
    }

    public function log($msg)
    {
        Mage::log($this->_name . " - " . $msg);
    }

    public function t($str, $arg1 = null, $arg2 = null)
    {
        return $this->helper->__($str, $arg1, $arg2);
    }

    public function getOrderPlaceRedirectUrl()
    {
        $session = Mage::getSingleton('core/session');
        return $session->getRedirectUrl();
    }

    public function isAvailable($quote = null)
    {
        if (!parent::isAvailable($quote))
            return false;

        if (!$quote)
            return parent::isAvailable($quote);

        if ($quote->getGrandTotal() <= 0)
            return false;

        $store = $this->stripe->getStore();
        $allowCurrencies = $store->getConfig("payment/{$this->_code}/allow_currencies");
        $allowedCurrencies = $store->getConfig("payment/{$this->_code}/allowed_currencies");

        // This is the "All currencies" setting
        if (!$allowedCurrencies)
            return true;

        // This is the specific currencies setting
        if ($allowCurrencies && $allowedCurrencies)
        {
            $currency = $quote->getQuoteCurrencyCode();
            $currencies = explode(',', $allowedCurrencies);
            if (!in_array($currency, $currencies))
                return false;
        }

        $allowedCurrencies = explode(',', $allowedCurrencies);

        if (!in_array($quote->getQuoteCurrencyCode(), $allowedCurrencies))
            return false;

        return true;
    }

		public function capture(Varien_Object $payment, $amount)
		{
				parent::capture($payment, $amount);
				
				if ($amount > 0)
				{
						// We get in here when the store is configured in Authorize Only mode and we are capturing a payment from the admin
						$token = $payment->getTransactionId();
						if (empty($token))
								$token = $payment->getLastTransId(); // In case where the transaction was not created during the checkout, i.e. with a Stripe Webhook redirect

						if ($token)
						{
								$token = $this->helper->cleanToken($token);
								try
								{
										if (strpos($token, 'pi_') === 0)
										{
												$pi = \Stripe\PaymentIntent::retrieve($token);
												$ch = $pi->charges->data[0];
												$paymentObject = $pi;
										}
										else
										{
												$ch = \Stripe\Charge::retrieve($token);
												$paymentObject = $ch;
										}

										$finalAmount = $this->getMultiCurrencyAmount($payment, $amount);

										$currency = $payment->getOrder()->getOrderCurrencyCode();
										$cents = 100;
										if ($this->isZeroDecimal($currency))
												$cents = 1;

										if ($ch->captured)
										{
												// In theory this condition should never evaluate, but is added for safety
												if ($ch->currency != strtolower($currency))
														Mage::throwException("This invoice has already been captured in Stripe using a different currency ({$ch->currency}).");

												$capturedAmount = $ch->amount - $ch->amount_refunded;

												if ($capturedAmount != round($finalAmount * $cents))
												{
														$humanReadableAmount = strtoupper($ch->currency) . " " . round($capturedAmount / $cents, 2);
														Mage::throwException("This invoice has already been captured in Stripe for a different amount ($humanReadableAmount). Please cancel and create a new offline invoice for the correct amount.");
												}

												// We return instead of trying to capture the payment to simulate an Offline capture
												return $this;
										}

										$paymentObject->capture(array('amount' => round($finalAmount * $cents)));
								}
								catch (\Exception $e)
								{
										$this->log($e->getMessage());
										if (Mage::app()->getStore()->isAdmin() && $this->isAuthorizationExpired($e->getMessage()) && $this->retryWithSavedCard())
												$this->createCharge($payment, true, true);
										else
												Mage::throwException($e->getMessage());
								}
						}
						else
						{
								$this->paymentIntent->confirmAndAssociateWithOrder($payment->getOrder(), $payment);
						}
				}

				return $this;

				return $this;
		}



    public function isEmailValid($email)
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL))
            return true;

        return false;
    }

	protected function isAuthorizationExpired($errorMessage)
	{
			return ((strstr($errorMessage, "cannot be captured because the charge has expired") !== false) ||
					(strstr($errorMessage, "could not be captured because it has a status of canceled") !== false));
	}

	protected function retryWithSavedCard()
	{
			return Mage::getStoreConfig('payment/stripe_payments/expired_authorizations');
	}

	public function isZeroDecimal($currency)
	{
			return Mage::helper('stripe_payments')->isZeroDecimal($currency);
	}

	public function getStripeParamsFrom($order)
	{
			return $this->helper->getStripeParamsFrom($order);
	}

	public function createCharge(Varien_Object $payment, $capture, $forceUseSavedCard = false)
	{
			$token = $payment->getAdditionalInformation('payment_intent_id');

			try {
					$order = $payment->getOrder();

					$params = $this->getStripeParamsFrom($order);

					$params["capture"] = $capture;


					$params['metadata'] = $this->getChargeMetadataFrom($payment);

					$charge = \Stripe\Charge::create($params);

					if (!$charge->captured && $this->getStore()->getConfig('payment/stripe_payments/automatic_invoicing'))
					{
							$payment->setIsTransactionPending(true);
							$invoice = $order->prepareInvoice();
							$invoice->register();
							$order->addRelatedObject($invoice);
					}

					$payment->setTransactionId($charge->id);
					$payment->setIsTransactionClosed(0);
			}
			catch (\Stripe\Error\Card $e)
			{
					$this->log($e->getMessage());
					Mage::throwException($this->t($e->getMessage()));
			}
			catch (\Stripe\Error $e)
			{
					Mage::logException($e);
					Mage::throwException($this->t($e->getMessage()));
			}
			catch (\Exception $e)
			{
					Mage::logException($e);
					Mage::throwException($this->t($e->getMessage()));
			}
	}

	public function getChargeMetadataFrom($payment)
	{
			return $this->helper->getChargeMetadataFrom($payment);
	}

	public function validateParams($params)
	{
			if (is_array($params) && isset($params['card']) && is_array($params['card']) && empty($params['card']['number']))
					Mage::throwException("Unable to use Stripe.js");
	}

	public function isStripeRadarEnabled()
	{
			return $this->helper->isStripeRadarEnabled();
	}

	public function loadFromPayment(Varien_Object $payment)
	{
			if (empty($payment))
					return null;

			$method = $payment->getMethod();
			if (strpos($method, "stripe_") !== 0)
					return null;

			$token = $payment->getAdditionalInformation('payment_intent_id');

			try
			{
					// Used by Bancontact, iDEAL etc
					if (strpos($sourceId, "src_") === 0)
							$object = \Stripe\Source::retrieve($sourceId);
					// Used by card payments
					else if (strpos($sourceId, "pm_") === 0)
							$object = \Stripe\PaymentMethod::retrieve($sourceId);
					else
							return null;

					if (empty($object->customer))
							return null;

					$stripeId = $object->customer;
			}
			catch (\Exception $e)
			{
					return null;
			}

			return ['customer' => $stripeId, 'token' => $token];
	}

	/**
	 * Cancel payment
	 *
	 * @param   Varien_Object $invoicePayment
	 * @return  Mage_Payment_Model_Abstract
	 */
	public function cancel(Varien_Object $payment, $amount = null)
	{
			if (Mage::getStoreConfig('payment/stripe_payments/use_store_currency'))
			{
					// Captured
					$creditmemo = $payment->getCreditmemo();
					if (!empty($creditmemo))
					{
							$rate = $creditmemo->getStoreToOrderRate();
							if (!empty($rate) && is_numeric($rate))
									$amount *= $rate;
					}
					// Authorized
					$amount = (empty($amount)) ? $payment->getOrder()->getTotalDue() : $amount;

					$currency = $payment->getOrder()->getOrderCurrencyCode();
			}
			else
			{
					// Authorized
					$amount = (empty($amount)) ? $payment->getOrder()->getBaseTotalDue() : $amount;

					$currency = $payment->getOrder()->getBaseCurrencyCode();
			}

			$transactionId = $payment->getParentTransactionId();

			// With asynchronous payment methods, the parent transaction may be empty
			if (empty($transactionId))
					$transactionId = $payment->getLastTransId();

			// Case where an invoice is in Pending status, with no transaction ID, receiving a source.failed event which cancels the invoice.
			if (empty($transactionId))
					return $this;

			$transactionId = $this->helper->cleanToken($transactionId);

			try {
					$cents = 100;
					if ($this->isZeroDecimal($currency))
							$cents = 1;

					$params = array(
							'amount' => round($amount * $cents)
					);

					if (strpos($transactionId, 'pi_') === 0)
					{
							$pi = \Stripe\PaymentIntent::retrieve($transactionId);
							if ($pi->status == Stripe_Payments_Model_PaymentIntent::AUTHORIZED)
							{
									$pi->cancel();
									return $this;
							}
							else
									$charge = $pi->charges->data[0];
					}
					else
					{
							$charge = \Stripe\Charge::retrieve($transactionId);
					}

					// Magento's getStoreToOrderRate may result in a €45.9355 which will ROUND_UP to €45.94, even if the checkout order was €45.93
					if ($params["amount"] > $charge->amount)
							$params["amount"] = $charge->amount;

					// SEPA and SOFORT may have failed charges, refund those offline
					if ($charge->status == "failed")
					{
							return $this;
					}
					// This is true when an authorization has expired, when there was a refund through the Stripe account, or when a partial refund is performed
					if (!$charge->refunded)
					{
							$charge->refund($params);

							$refundId = $this->helper->getRefundIdFrom($charge);
							$payment->setAdditionalInformation('last_refund_id', $refundId);
					}
					else if ($payment->getAmountPaid() == 0)
					{
							// This is an expired authorized only order, which means that it cannot be refunded online or offline
							return $this;
					}
					else
					{
							Mage::throwException('This order has already been refunded in Stripe. To refund from Magento, please refund it offline.');
					}
			}
			catch (\Exception $e)
			{
					$this->log('Could not refund payment: '.$e->getMessage());
					Mage::throwException($this->t('Could not refund payment: ').$e->getMessage());
			}

			return $this;
	}

	/**
	 * Refund money
	 *
	 * @param   Varien_Object $invoicePayment
	 * @return  Mage_Payment_Model_Abstract
	 */
	public function refund(Varien_Object $payment, $amount)
	{
			parent::refund($payment, $amount);
			$this->cancel($payment, $amount);

			return $this;
	}

	/**
	 * Void payment
	 *
	 * @param   Varien_Object $invoicePayment
	 * @return  Mage_Payment_Model_Abstract
	 */
	public function void(Varien_Object $payment)
	{
			parent::void($payment);
			$this->cancel($payment);

			return $this;
	}

	public function getCustomerStripeId($customerId = null)
	{
			return $this->helper->getCustomerStripeId($customerId);
	}

	public function getCustomerStripeIdByEmail($maxAge = null)
	{
			return $this->helper->getCustomerStripeIdByEmail($maxAge);
	}

	protected function createStripeCustomer()
	{
			return $this->helper->createStripeCustomer();
	}

	public function getStripeCustomer($id = null)
	{
			return $this->helper->getStripeCustomer($id);
	}

	public function deleteCards($cards)
	{
			$customer = $this->getStripeCustomer();

			if ($customer)
			{
					foreach ($cards as $cardId)
					{
							try
							{
									if (strpos($cardId, "pm_") === 0)
											\Stripe\PaymentMethod::retrieve($cardId)->detach();
									else if (strpos($cardId, "card_") === 0)
											\Stripe\Customer::deleteSource($customer->id, $cardId);
									else
											$this->helper->retrieveSource($cardId)->delete();
							}
							catch (\Exception $e)
							{
									Mage::logException($e);
							}
					}
					$customer->save();
			}
	}

	protected function updateLastRetrieved($stripeCustomerId)
	{
			$this->helper->updateLastRetrieved($stripeCustomerId);
	}

	protected function deleteStripeCustomerId($stripeId)
	{
			$this->helper->deleteStripeCustomerId($stripeId);
	}

	protected function setStripeCustomerId($stripeId, $forCustomerId)
	{
			$this->helper->setStripeCustomerId($stripeId, $forCustomerId);
	}

	public function getCustomerCards($isAdmin = false, $customerId = null)
	{
			if (!$this->saveCards && !$isAdmin)
					return null;

			if (!$customerId)
					$customerId = $this->getCustomerId();

			if (!$customerId)
					return null;

			$customerStripeId = $this->getCustomerStripeId($customerId);
			if (!$customerStripeId)
					return null;

			return $this->listCards($customerStripeId);
	}

	private function listCards($customerStripeId, $params = array())
	{
			return $this->helper->listCards($customerStripeId, $params);
	}



	public function isGuest()
	{
			return $this->helper->isGuest();
	}

	public function showSaveCardOption()
	{
			return ($this->saveCards && !$this->isGuest() && $this->helper->getSecurityMethod() > 0);
	}

	protected function hasRecurringProducts()
	{
			return $this->_hasRecurringProducts;
	}

	public function alwaysSaveCard()
	{
			return (($this->hasRecurringProducts() || $this->saveCards == 2 || $this->helper->isMultiShipping()) && $this->helper->getSecurityMethod() > 0);
	}

	public function getSecurityMethod()
	{
			return $this->helper->getSecurityMethod();
	}

	public function getAmountCurrencyFromQuote($quote, $useCents = true)
	{
			$params = array();
			$items = $quote->getAllItems();

			if (Mage::getStoreConfig('payment/stripe_payments/use_store_currency'))
			{
					$amount = $quote->getGrandTotal();
					$currency = $quote->getQuoteCurrencyCode();

					foreach ($items as $item)
							if ($item->getProduct()->isRecurring())
									$amount += $item->getNominalRowTotal();
			}
			else
			{
					$amount = $quote->getBaseGrandTotal();;
					$currency = $quote->getBaseCurrencyCode();

					foreach ($items as $item)
							if ($item->getProduct()->isRecurring())
									$amount += $item->getBaseNominalRowTotal();
			}

			if ($useCents)
			{
					$cents = 100;
					if ($this->isZeroDecimal($currency))
							$cents = 1;

					$fields["amount"] = round($amount * $cents);
			}
			else
			{
					// Used for Apple Pay only
					$fields["amount"] = number_format($amount, 2, '.', '');
			}

			$fields["currency"] = $currency;

			return $fields;
	}


	public function retrieveCharge($token)
	{
			if (strpos($token, 'pi_') === 0)
			{
					$pi = \Stripe\PaymentIntent::retrieve($token);

					if (empty($pi->charges->data[0]))
							return null;

					return $pi->charges->data[0];
			}

			return \Stripe\Charge::retrieve($token);
	}



	// Public logging method
	public function plog($msg)
	{
			return $this->log($msg);
	}

	// This method is used mainly for helping customers through support, to find out why Apple Pay does not display at the checkout
	public function getDebuggingInfo()
	{
			$info = array(
					"Active: " . (int)$this->store->getConfig('payment/stripe_payments/active'),
					"Apple Pay: " . (int)$this->store->getConfig('payment/stripe_payments/apple_pay_checkout'),
					"Location: " . (int)$this->store->getConfig('payment/stripe_payments/apple_pay_location'),
					"Invoice: " . (int)$this->store->getConfig('payment/stripe_payments/automatic_invoicing'),
					"Action: " . (int)$this->store->getConfig('payment/stripe_payments/payment_action'),
					"Countries: " . (int)$this->store->getConfig('payment/stripe_payments/allowspecific')
			);

			return implode(",\n", $info);
	}

	public function getMultiCurrencyAmount($payment, $baseAmount)
	{
			if (!Mage::getStoreConfig('payment/stripe_payments/use_store_currency'))
					return $baseAmount;

			$order = $payment->getOrder();
			$grandTotal = $order->getGrandTotal();
			$baseGrandTotal = $order->getBaseGrandTotal();

			$rate = $order->getStoreToOrderRate();

			// Full capture, ignore currency rate in case it changed
			if ($baseAmount == $baseGrandTotal)
					return $grandTotal;
			// Partial capture, consider currency rate but don't capture more than the original amount
			else if (is_numeric($rate))
					return min($baseAmount * $rate, $grandTotal);
			// Not a multicurrency capture
			else
					return $baseAmount;
	}
}
