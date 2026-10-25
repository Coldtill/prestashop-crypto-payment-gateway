<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @property Coldtill $module
 */
class ColdtillWebhookModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $raw = file_get_contents('php://input') ?: '';
        if (!ColdtillClient::verifyRequest($_SERVER, $raw, (string) Configuration::get('COLDTILL_WEBHOOK_SECRET'))) {
            http_response_code(401);
            exit;
        }
        $event = ColdtillClient::header($_SERVER, 'EVENT');
        $data = json_decode($raw, true);
        if (str_starts_with($event, 'payment.') && is_array($data) && !empty($data['reference_id'])) {
            $this->module->apply((string) $data['reference_id'], (string) ($data['status'] ?? ''), (string) ($data['amount'] ?? ''), (string) ($data['payment_info'][0]['transaction_hash'] ?? ''));
        }
        http_response_code(200);
        exit;
    }

    // Coldtill must still reach the webhook while the shop is in maintenance mode or blocks the server's country.
    protected function displayMaintenancePage()
    {
    }

    protected function displayRestrictedCountryPage()
    {
    }
}
