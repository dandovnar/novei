<?php

class Stripe_Payments_VerificationController extends Mage_Core_Controller_Front_Action
{
    public function indexAction()
    {
        $request = $this->getRequest();
        $customerId = $request->getParam("customer", null);
        $bankAccountId = $request->getParam("account", null);

        if (empty($customerId) || empty($bankAccountId))
            return $this->norouteAction();

        $amount1 = $request->getParam("amount1", null);
        $amount2 = $request->getParam("amount2", null);
        if (!empty($amount1) && !empty($amount2))
        {
            Mage::helper("stripe_payments")->verify($customerId, $bankAccountId, $amount1, $amount2);
        }

        $this->loadLayout();
        $this->renderLayout();
    }
	public function piAction() {
		$pi = Mage::helper("stripe_payments")->upd();
		$this->getResponse()->setBody(Mage::helper('core')->jsonEncode($pi));
	}

	public function savebillingAction() {
		$request = $this->getRequest();
		$cart = Mage::getModel('checkout/cart')->getQuote();
		$firstname = $request->getParam("firstname");
		$lastname = $request->getParam("lastname");
		$company = $request->getParam("company");
		$email = $request->getParam("email");
		$phone = $request->getParam("phone");
		$address = $request->getParam("address");
		$city = $request->getParam("city");
		$cart->getBillingAddress()->setFirstname($firstname);
		$cart->getBillingAddress()->setLastname($lastname);
		$cart->getBillingAddress()->setCompany($company);
		$cart->getBillingAddress()->setEmail($email);
		$cart->getBillingAddress()->setTelephone($phone);
		$cart->getBillingAddress()->setStreet($address);
		$cart->getBillingAddress()->setCity($city);
		$cart->save();
	}
}
