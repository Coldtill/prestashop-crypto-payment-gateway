<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @property Coldtill $module
 */
class ColdtillValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $cart = $this->context->cart;
        if (!$cart->id || !$cart->id_customer || !$cart->id_address_delivery || !$cart->id_address_invoice || !$this->module->active) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1]));
        }
        // The customer may have changed address or country after the payment step.
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] === $this->module->name) {
                $authorized = true;
                break;
            }
        }
        $customer = new Customer((int) $cart->id_customer);
        if (!$authorized || !Validate::isLoadedObject($customer)) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1]));
        }
        // Multi-currency modules can switch the cart currency after the payment step; Coldtill prices in USD.
        if (!$this->module->isUsd((int) $cart->id_currency)) {
            $this->fail($this->trans('Crypto payment is only available for orders in USD.', [], 'Modules.Coldtill.Shop'));
        }
        try {
            $url = $this->module->startPayment($cart, $customer);
        } catch (Exception $e) {
            PrestaShopLogger::addLog($e->getMessage(), 3, null, 'Cart', (int) $cart->id, true);
            $this->fail($this->trans('Crypto payment is unavailable right now. Please try again or choose another method.', [], 'Modules.Coldtill.Shop'));
        }
        Tools::redirect($url);
    }

    private function fail(string $message): void
    {
        $this->errors[] = $message;
        $this->redirectWithNotifications($this->context->link->getPageLink('order', true));
    }
}
