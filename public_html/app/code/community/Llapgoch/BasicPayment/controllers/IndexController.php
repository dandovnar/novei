<?php

class Llapgoch_BasicPayment_IndexController extends Mage_Core_Controller_Front_Action
{
	public function indexAction()
	{
			$order = $payment->getOrder();
			$quote = $order->getQuote();
			$params = Mage::helper("stripe_payments")->getStripeParamsFrom($order);
			$params["description"] = "Order #" . $order->getIncrementId();
			$pp['type'] = 'affirm';
			$params['payment_method_types'] = ['card', 'affirm', 'afterpay_clearpay']; // For now
			$params['payment_method'] = \Stripe\PaymentMethod::create($pp);
			$update = \Stripe\PaymentIntent::update(Mage::getSingleton("stripe_payments/paymentIntent")->loadFromCache($quote)->id,$params);
	}
}
