<?php

class Cryozonic_Stripe_Block_Adminhtml_Notifications extends Mage_Adminhtml_Block_Template
{
    public function areWebhooksConfigured()
    {
        return !file_exists(Mage::getBaseDir('cache') . DS . 'cryozonic_stripe_webhooks.log');
    }

    public function getStripeWebhooksConfigurationLink()
    {
        return "https://stripe.com/docs/magento/cryozonic#modules-for-magento-1";
    }
}
