<?php

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/ColdtillClient.php';

class Coldtill extends PaymentModule
{
    public const OS_AWAITING = 'COLDTILL_OS_AWAITING';
    public const OS_PARTIAL = 'COLDTILL_OS_PARTIAL';
    private const SETTINGS = ['COLDTILL_BASE_URL', 'COLDTILL_API_KEY', 'COLDTILL_WEBHOOK_SECRET'];

    public function __construct()
    {
        $this->name = 'coldtill';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Coldtill';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];
        $this->controllers = ['validation', 'webhook'];
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->trans('Coldtill', [], 'Modules.Coldtill.Admin');
        $this->description = $this->trans('Self-custody crypto payments through your own Coldtill install. Funds go straight to your wallets.', [], 'Modules.Coldtill.Admin');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('displayPaymentReturn')
            && $this->installTable()
            && $this->installState(self::OS_AWAITING, 'Awaiting crypto payment', '#4169E1')
            && $this->installState(self::OS_PARTIAL, 'Partial crypto payment', '#FF8C00');
    }

    /**
     * Keeps the payment table and the order states, so existing orders keep their status and a reinstall picks up
     * where it left off. The states are only hidden from the status list.
     */
    public function uninstall()
    {
        foreach ([self::OS_AWAITING, self::OS_PARTIAL] as $key) {
            $state = new OrderState((int) Configuration::getGlobalValue($key));
            if (Validate::isLoadedObject($state)) {
                $state->deleted = true;
                $state->save();
            }
        }
        foreach (self::SETTINGS as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    private function installTable(): bool
    {
        return Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'coldtill_payment` (
            `id_order` INT UNSIGNED NOT NULL,
            `reference_id` VARCHAR(64) NOT NULL,
            `url` VARCHAR(2048) NOT NULL DEFAULT \'\',
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_order`),
            KEY `reference_id` (`reference_id`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    private function installState(string $key, string $name, string $color): bool
    {
        $state = new OrderState((int) Configuration::getGlobalValue($key));
        if (!Validate::isLoadedObject($state)) {
            $state = new OrderState();
            foreach (Language::getLanguages(false) as $lang) {
                $state->name[$lang['id_lang']] = $name;
            }
        }
        $state->module_name = $this->name;
        $state->color = $color;
        $state->send_email = false;
        $state->logable = false;
        $state->paid = false;
        $state->invoice = false;
        $state->shipped = false;
        $state->delivery = false;
        $state->hidden = false;
        $state->unremovable = true;
        $state->deleted = false;

        return $state->save() && Configuration::updateGlobalValue($key, (int) $state->id);
    }

    public function getContent()
    {
        $out = '';
        if (Tools::isSubmit('submitColdtill')) {
            $url = rtrim(trim((string) Tools::getValue('COLDTILL_BASE_URL')), '/');
            if ($url !== '' && !ColdtillClient::validBaseUrl($url)) {
                $out .= $this->displayError($this->trans('Coldtill URL must start with https:// so the API key is not sent in the clear.', [], 'Modules.Coldtill.Admin'));
            } else {
                Configuration::updateValue('COLDTILL_BASE_URL', $url);
            }
            // Password fields are never filled in by the form, so a blank one keeps the saved value.
            foreach (['COLDTILL_API_KEY', 'COLDTILL_WEBHOOK_SECRET'] as $key) {
                $value = trim((string) Tools::getValue($key));
                if ($value !== '') {
                    Configuration::updateValue($key, $value);
                }
            }
            if ($out === '') {
                $out .= $this->displayConfirmation($this->trans('Settings updated', [], 'Admin.Global'));
            }
        }

        return $out . $this->renderForm();
    }

    private function renderForm(): string
    {
        $webhook = htmlspecialchars($this->context->link->getModuleLink($this->name, 'webhook', [], true), ENT_QUOTES, 'UTF-8');
        $saved = $this->trans('Leave blank to keep the saved value.', [], 'Modules.Coldtill.Admin');
        $form = [
            'form' => [
                'legend' => ['title' => $this->trans('Settings', [], 'Admin.Global'), 'icon' => 'icon-cogs'],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Coldtill URL', [], 'Modules.Coldtill.Admin'),
                        'name' => 'COLDTILL_BASE_URL',
                        'desc' => $this->trans('For example https://pay.example.com', [], 'Modules.Coldtill.Admin'),
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->trans('API key', [], 'Modules.Coldtill.Admin'),
                        'name' => 'COLDTILL_API_KEY',
                        'desc' => $this->trans('Project API key from Coldtill → API keys.', [], 'Modules.Coldtill.Admin') . ' ' . $saved,
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->trans('Webhook secret', [], 'Modules.Coldtill.Admin'),
                        'name' => 'COLDTILL_WEBHOOK_SECRET',
                        'desc' => $this->trans('In Coldtill → Webhooks, set the URL to %url% and paste the signing secret here.', ['%url%' => '<code>' . $webhook . '</code>'], 'Modules.Coldtill.Admin') . ' ' . $saved,
                    ],
                ],
                'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitColdtill';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = ['fields_value' => [
            'COLDTILL_BASE_URL' => (string) Configuration::get('COLDTILL_BASE_URL'),
            'COLDTILL_API_KEY' => '',
            'COLDTILL_WEBHOOK_SECRET' => '',
        ]];

        return $helper->generateForm([$form]);
    }

    private function client(): ColdtillClient
    {
        return new ColdtillClient((string) Configuration::get('COLDTILL_BASE_URL'), (string) Configuration::get('COLDTILL_API_KEY'));
    }

    public function isUsd(int $idCurrency): bool
    {
        return (new Currency($idCurrency))->iso_code === 'USD';
    }

    public function hookPaymentOptions($params)
    {
        $cart = $params['cart'];
        if (!$this->active || !$this->isUsd((int) $cart->id_currency)
            || Configuration::get('COLDTILL_BASE_URL') == '' || Configuration::get('COLDTILL_API_KEY') == '') {
            return [];
        }
        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->trans('Pay with crypto', [], 'Modules.Coldtill.Shop'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
            ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/option.png'))
            ->setAdditionalInformation($this->fetch('module:coldtill/views/templates/hook/payment_option.tpl'));

        return [$option];
    }

    /**
     * Creates the Coldtill payment, then the order in the awaiting state, and returns the checkout URL.
     * The order reference is chosen first so it can go into the payment's invoiceID.
     */
    public function startPayment(Cart $cart, Customer $customer): string
    {
        do {
            $reference = Order::generateReference();
        } while (Order::getByReference($reference)->count());

        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $req = [
            // Coldtill allows one open payment per customer, so guests get a per-order id.
            'customerID' => $customer->is_guest ? 'ps-order-' . $reference : 'ps-customer-' . (int) $customer->id,
            'customerEmail' => (string) $customer->email,
            'amountInUSD' => number_format($total, 2, '.', ''),
            'invoiceID' => 'PS-' . $reference,
        ];
        // The checkout's "Return to store" button brings the customer back to this order, which also re-checks it.
        $return = $this->context->link->getPageLink('order-confirmation', true, null, [
            'id_cart' => (int) $cart->id,
            'id_module' => (int) $this->id,
            'key' => $customer->secure_key,
        ]);
        if (str_starts_with($return, 'https://')) {
            $req['returnURL'] = $return;
        }
        $res = $this->client()->createPayment($req);
        if (empty($res['reference_id']) || empty($res['url'])) {
            throw new RuntimeException('Coldtill: unexpected response');
        }

        $this->validateOrder((int) $cart->id, (int) Configuration::getGlobalValue(self::OS_AWAITING), $total, $this->displayName, null, [], (int) $cart->id_currency, false, $customer->secure_key, null, $reference);
        foreach (Order::getByReference($reference) as $order) {
            Db::getInstance()->insert('coldtill_payment', [
                'id_order' => (int) $order->id,
                'reference_id' => pSQL((string) $res['reference_id']),
                'url' => pSQL((string) $res['url']),
                'date_add' => date('Y-m-d H:i:s'),
            ], false, true, Db::REPLACE);
        }

        return (string) $res['url'];
    }

    private function payment(int $idOrder): array
    {
        $row = Db::getInstance()->getRow('SELECT `reference_id`, `url` FROM `' . _DB_PREFIX_ . 'coldtill_payment` WHERE `id_order` = ' . $idOrder);

        return is_array($row) ? $row : ['reference_id' => '', 'url' => ''];
    }

    /** Fallback for missed webhooks: re-check the payment when the customer lands on the confirmation page. */
    public function hookDisplayPaymentReturn($params)
    {
        $order = $params['order'] ?? null;
        if (!$this->active || !$order instanceof Order || $order->module !== $this->name) {
            return '';
        }
        $payment = $this->payment((int) $order->id);
        if ($payment['reference_id'] !== '' && !$order->hasBeenPaid()) {
            try {
                $p = $this->client()->getPayment($payment['reference_id']);
                $this->apply($payment['reference_id'], (string) ($p['paymentState'] ?? ''), (string) ($p['amountInUSD'] ?? ''), (string) ($p['deposits'][0]['transactionHash'] ?? ''));
            } catch (RuntimeException $e) {
                PrestaShopLogger::addLog($e->getMessage(), 2, null, 'Order', (int) $order->id, true);
            }
            $order = new Order((int) $order->id);
        }

        $state = (int) $order->getCurrentState();
        if ($order->hasBeenPaid()) {
            $status = 'paid';
        } elseif ($state === (int) Configuration::getGlobalValue(self::OS_PARTIAL)) {
            $status = 'partial';
        } elseif ($state === (int) Configuration::get('PS_OS_CANCELED')) {
            $status = 'cancelled';
        } else {
            $status = 'awaiting';
        }
        $this->context->smarty->assign(['coldtill_status' => $status, 'coldtill_url' => $payment['url']]);

        return $this->fetch('module:coldtill/views/templates/hook/payment_return.tpl');
    }

    /** Applies a Coldtill payment state, from a webhook or a status check, to the order that holds this reference. */
    public function apply(string $ref, string $status, string $amount, string $tx): void
    {
        // The webhook and the customer's return can arrive together; one waits so the order changes once.
        $db = Db::getInstance();
        $lock = '\'' . pSQL('coldtill_' . substr(sha1($ref), 0, 40)) . '\'';
        $db->getValue('SELECT GET_LOCK(' . $lock . ', 15)');
        try {
            $this->applyLocked($ref, $status, $amount, $tx);
        } finally {
            $db->getValue('SELECT RELEASE_LOCK(' . $lock . ')');
        }
    }

    private function applyLocked(string $ref, string $status, string $amount, string $tx): void
    {
        // The reference is stored per order, so a reference that was replaced on an order no longer finds it.
        $rows = Db::getInstance()->executeS('SELECT `id_order` FROM `' . _DB_PREFIX_ . 'coldtill_payment` WHERE `reference_id` = \'' . pSQL($ref) . '\'');
        $orders = [];
        foreach ($rows ?: [] as $row) {
            $order = new Order((int) $row['id_order']);
            if (Validate::isLoadedObject($order) && $order->module === $this->name) {
                $orders[] = $order;
            }
        }
        // One cart can be split into several orders that share a reference and a payment.
        $order = $orders[0] ?? null;
        if (!$order || $order->hasBeenPaid()) {
            return;
        }
        if (!$this->isUsd((int) $order->id_currency)) {
            $this->note($order, 'Coldtill payment ignored: the order currency is not USD.');

            return;
        }
        $total = 0.0;
        foreach ($orders as $o) {
            $total += (float) $o->total_paid_tax_incl;
        }
        $partial = (int) $order->getCurrentState() === (int) Configuration::getGlobalValue(self::OS_PARTIAL);

        switch (ColdtillClient::action($status, $amount, $total, $partial)) {
            case ColdtillClient::MISMATCH:
                $this->note($order, sprintf('Coldtill amount %s USD does not match the order total; not changing status.', $amount));
                break;
            case ColdtillClient::OVERPAID:
                $this->note($order, 'Crypto payment received (overpaid; refund the difference from your wallet if needed).');
                // no break
            case ColdtillClient::PAID:
                foreach ($orders as $o) {
                    $this->setState($o, (int) Configuration::get('PS_OS_PAYMENT'));
                    foreach ((new Order((int) $o->id))->getOrderPaymentCollection() as $p) {
                        if ($tx !== '' && !$p->transaction_id) {
                            $p->transaction_id = $tx;
                            $p->update();
                        }
                    }
                }
                break;
            case ColdtillClient::PARTIAL:
                foreach ($orders as $o) {
                    $this->setState($o, (int) Configuration::getGlobalValue(self::OS_PARTIAL));
                }
                break;
            case ColdtillClient::CANCEL:
                foreach ($orders as $o) {
                    $this->setState($o, (int) Configuration::get('PS_OS_CANCELED'));
                }
                break;
            case ColdtillClient::MANUAL:
                $this->note($order, 'Coldtill payment ' . strtolower($status) . ' after a partial payment; check the amount received in Coldtill and settle this order by hand.');
                break;
        }
    }

    private function setState(Order $order, int $idState): void
    {
        if ((int) $order->getCurrentState() === $idState) {
            return;
        }
        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState($idState, $order);
        $history->addWithemail();
    }

    /** Adds a private message to the order, which shows in the order's Messages panel in the back office. */
    private function note(Order $order, string $text): void
    {
        $customer = new Customer((int) $order->id_customer);
        $idThread = (int) CustomerThread::getIdCustomerThreadByEmailAndIdOrder($customer->email, (int) $order->id);
        if (!$idThread) {
            $thread = new CustomerThread();
            $thread->id_contact = 0;
            $thread->id_customer = (int) $order->id_customer;
            $thread->id_shop = (int) $order->id_shop;
            $thread->id_order = (int) $order->id;
            $thread->id_lang = (int) $order->id_lang;
            $thread->email = $customer->email;
            $thread->status = 'open';
            $thread->token = Tools::passwdGen(12);
            $thread->add();
            $idThread = (int) $thread->id;
        }
        $message = new CustomerMessage();
        $message->id_customer_thread = $idThread;
        $message->id_employee = 0;
        $message->message = $text;
        $message->private = true;
        $message->add();
    }
}
