<?php

class Cryozonic_Stripe_Authorization_ConfirmController extends Mage_Core_Controller_Front_Action
{
    public function _construct()
    {
        $this->helper = Mage::helper('cryozonic_stripe');
        $this->multishippingHelper = Mage::helper('cryozonic_stripe/multishipping');
    }

    public function indexAction()
    {
        $outcomes = $this->multishippingHelper->confirmPaymentsForSessionOrders();

        if (empty($outcomes))
        {
            $this->helper->addError("Could not confirm order payment");
            $this->_redirect('checkout/cart');
        }

        if ($outcomes["hasErrors"])
        {
            foreach ($outcomes["orders"] as $outcome)
            {
                if ($outcome["success"])
                    $this->helper->addSuccess($outcome["message"]);
                else
                    $this->helper->addError($outcome["message"]);
            }
            $this->_redirect('checkout/multishipping/addresses');
        }
        else
        {
            Mage::getSingleton('checkout/type_multishipping')->getCheckoutSession()->setDisplaySuccess(true);
            $this->_redirect('checkout/multishipping/success');
        }
    }
}
