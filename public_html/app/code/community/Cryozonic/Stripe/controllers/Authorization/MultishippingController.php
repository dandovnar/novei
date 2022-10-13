<?php

class Cryozonic_Stripe_Authorization_MultishippingController extends Mage_Core_Controller_Front_Action
{
    public function indexAction()
    {
        $this->loadLayout();
        $this->renderLayout();
    }
}
