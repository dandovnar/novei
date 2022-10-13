<?php
/**
 * Cryozonic
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Single Domain License
 * that is available through the world-wide-web at this URL:
 * http://cryozonic.com/licenses/stripe.html
 * If you are unable to obtain it through the world-wide-web,
 * please send an email to info@cryozonic.com so we can send
 * you a copy immediately.
 *
 * @category   Cryozonic
 * @package    Cryozonic_Stripe
 * @copyright  Copyright (c) Cryozonic Ltd (http://cryozonic.com)
 */

class Cryozonic_Stripe_Model_Webhooks_Observer
{
    /*************
     * 3D Secure *
     *************/

    // source.chargeable
    public function cryozonic_stripe_webhook_source_chargeable_three_d_secure($observer)
    {
        $event = $observer->getEvent();
        $order = Mage::helper('cryozonic_stripe/webhooks')->loadOrderFromEvent($event);
        Mage::getModel('cryozonic_stripe/method_threeDSecure')->charge($order, $event['data']['object']);
    }

    // source.canceled
    public function cryozonic_stripe_webhook_source_canceled_three_d_secure($observer)
    {
        $this->cryozonic_stripe_webhook_source_failed_three_d_secure($observer);
    }

    // source.failed
    public function cryozonic_stripe_webhook_source_failed_three_d_secure($observer)
    {
        $event = $observer->getEvent();
        $order = Mage::helper('cryozonic_stripe/webhooks')->loadOrderFromEvent($event);
        $order->addStatusHistoryComment("Authorization failed.");
        Mage::helper('cryozonic_stripe')->cancelOrCloseOrder($order);
    }

    // charge.failed - may happen if 3DS succeeded but the CVC was wrong or there were insufficient funds in the card
    public function cryozonic_stripe_webhook_charge_failed_three_d_secure($observer)
    {
        $this->cryozonic_stripe_webhook_source_failed_three_d_secure($observer);
    }
}