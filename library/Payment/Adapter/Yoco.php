<?php

/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 */

use FOSSBilling\InjectionAwareInterface;
use Symfony\Component\HttpClient\HttpClient;

class Payment_Adapter_Yoco extends Payment_AdapterAbstract implements InjectionAwareInterface
{
    protected ?Pimple\Container $di = null;

    public function __construct(protected $_config)
    {
        parent::__construct($_config);

        $secretKey = $this->getSecretKey();
        if (empty($secretKey)) {
            throw new Payment_Exception(
                'The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing',
                [
                    ':pay_gateway' => 'Yoco',
                    ':missing' => $this->getTestModeFlag() ? 'Test Secret key' : 'Live Secret key',
                ],
                4001
            );
        }
    }

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public static function getConfig()
    {
        return [
            'can_load_in_iframe' => false,
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Yoco Checkout API integration for one-time card payments in ZAR, with optional USD invoice conversion into ZAR at a configured rate. Payment confirmation is handled through Yoco webhooks.',
            'logo' => [
                'logo' => 'yoco.png',
                'height' => '28px',
                'width' => '84px',
            ],
            'form' => [
                'pub_key' => [
                    'text',
                    [
                        'label' => 'Live public key:',
                        'required' => false,
                    ],
                ],
                'api_key' => [
                    'text',
                    [
                        'label' => 'Live Secret key:',
                        'required' => false,
                    ],
                ],
                'test_pub_key' => [
                    'text',
                    [
                        'label' => 'Test public key:',
                        'required' => false,
                    ],
                ],
                'test_api_key' => [
                    'text',
                    [
                        'label' => 'Test Secret key:',
                        'required' => false,
                    ],
                ],
                'webhook_id' => [
                    'text',
                    [
                        'label' => 'Webhook ID:',
                        'required' => false,
                    ],
                ],
                'webhook_secret' => [
                    'text',
                    [
                        'label' => 'Webhook Secret:',
                        'required' => false,
                    ],
                ],
                'usd_to_zar_rate' => [
                    'text',
                    [
                        'label' => 'USD to ZAR conversion rate (optional override):',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        if ($subscription) {
            throw new Payment_Exception('Yoco recurring subscriptions are not supported by this gateway.');
        }

        /** @var Model_Invoice $invoice */
        $invoice = $this->di['db']->load('Invoice', $invoice_id);
        if (!$invoice instanceof Model_Invoice) {
            throw new Payment_Exception('Invoice was not found.');
        }

        $settlement = $this->getSettlementDetails($invoice);
        $checkout = $this->createCheckout($invoice, $settlement);
        $this->rememberCheckout($invoice, $checkout);

        $redirectUrl = htmlspecialchars((string) ($checkout['redirectUrl'] ?? ''), ENT_QUOTES, 'UTF-8');
        if ($redirectUrl === '') {
            throw new Payment_Exception('Yoco did not return a checkout redirect URL.');
        }

        $notice = '<p>You are being redirected to Yoco checkout.</p>';
        if ($settlement['invoice_currency'] === 'USD') {
            $notice = sprintf(
                '<p>This invoice is priced in USD. Yoco will charge %s ZAR using the configured conversion rate of 1 USD = %s ZAR.</p>',
                number_format($settlement['settlement_amount'], 2, '.', ''),
                number_format($settlement['rate'], 4, '.', '')
            );
        }

        return <<<HTML
<div class="loading"><span>Redirecting to Yoco...</span></div>
{$notice}
<p><a class="btn btn-primary" href="{$redirectUrl}">Continue to Yoco</a></p>
<script>
window.location.href = '{$redirectUrl}';
</script>
HTML;
    }

    public function getInvoiceId($data)
    {
        $invoiceId = $data['invoice_id'] ?? $data['get']['invoice_id'] ?? $data['post']['invoice_id'] ?? null;
        if ($invoiceId) {
            return (int) $invoiceId;
        }

        $payload = $this->decodePayload($data);
        $checkoutId = $payload['payload']['metadata']['checkoutId'] ?? null;
        if (!$checkoutId) {
            return null;
        }

        $gatewayId = $data['gateway_id'] ?? $data['get']['gateway_id'] ?? $data['post']['gateway_id'] ?? null;
        $params = [':txn_id' => $checkoutId];
        $sql = 'txn_id = :txn_id';
        if ($gatewayId) {
            $sql .= ' AND gateway_id = :gateway_id';
            $params[':gateway_id'] = $gatewayId;
        }
        $sql .= ' AND invoice_id IS NOT NULL ORDER BY id DESC';

        /** @var Model_Transaction|null $tx */
        $tx = $this->di['db']->findOne('Transaction', $sql, $params);
        if ($tx instanceof Model_Transaction) {
            return (int) $tx->invoice_id;
        }

        return null;
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        /** @var Model_Transaction $tx */
        $tx = $this->di['db']->getExistingModelById('Transaction', $id);
        $payload = $this->decodePayload($data);
        if (empty($payload['type']) || empty($payload['payload']) || !is_array($payload['payload'])) {
            throw new Payment_Exception('Invalid Yoco webhook payload.');
        }

        $rawBody = (string) ($data['http_raw_post_data'] ?? '');
        $webhookId = (string) ($data['server']['HTTP_WEBHOOK_ID'] ?? '');
        $webhookTimestamp = (string) ($data['server']['HTTP_WEBHOOK_TIMESTAMP'] ?? '');
        $signature = (string) ($data['server']['HTTP_WEBHOOK_SIGNATURE'] ?? '');
        if (!$this->isValidWebhookSignature($rawBody, $webhookId, $webhookTimestamp, $signature)) {
            throw new Payment_Exception('Invalid Yoco webhook signature.');
        }

        $eventId = (string) ($payload['id'] ?? '');
        $eventType = (string) $payload['type'];
        $payment = $payload['payload'];
        $checkoutId = (string) ($payment['metadata']['checkoutId'] ?? '');
        $invoiceId = $tx->invoice_id ?: $this->getInvoiceId([
            'gateway_id' => $gateway_id,
            'get' => $data['get'] ?? [],
            'post' => $data['post'] ?? [],
            'invoice_id' => $tx->invoice_id,
            'http_raw_post_data' => $rawBody,
        ]);

        if (!$invoiceId) {
            throw new Payment_Exception('Could not match Yoco payment to an invoice.');
        }

        /** @var Model_Invoice $invoice */
        $invoice = $this->di['db']->getExistingModelById('Invoice', $invoiceId);

        $tx->invoice_id = $invoice->id;
        $tx->txn_id = $eventId !== '' ? $eventId : ($payment['id'] ?? $checkoutId);
        $tx->txn_status = (string) ($payment['status'] ?? $eventType);
        $tx->amount = isset($payment['amount']) ? ((float) $payment['amount']) / 100 : $tx->amount;
        $tx->currency = $payment['currency'] ?? $invoice->currency;
        $tx->note = trim('Yoco event ' . ($eventType ?: 'unknown') . ($checkoutId !== '' ? ' / checkout ' . $checkoutId : ''));
        $tx->updated_at = date('Y-m-d H:i:s');

        if ($eventType !== 'payment.succeeded') {
            $tx->status = $eventType === 'payment.failed' ? 'error' : 'processed';
            if ($eventType === 'payment.failed') {
                $tx->error = 'Yoco reported that the payment failed.';
            }
            $this->di['db']->store($tx);

            return true;
        }

        if ($tx->txn_id && $this->hasProcessedDuplicate($tx->txn_id, $tx->id)) {
            $tx->status = 'processed';
            $tx->error = null;
            $this->di['db']->store($tx);

            return true;
        }

        if ($tx->status !== 'processed') {
            $invoiceService = $this->di['mod_service']('Invoice');
            $invoiceCurrency = strtoupper((string) $invoice->currency);
            $txCurrency = strtoupper((string) $tx->currency);

            if ($invoiceCurrency === 'ZAR' && $txCurrency === 'ZAR') {
                $clientService = $this->di['mod_service']('client');
                /** @var Model_Client $client */
                $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);

                $amount = (float) $tx->amount;
                $description = 'Yoco payment ' . ($checkoutId !== '' ? $checkoutId : $tx->txn_id);
                $balanceData = [
                    'amount' => $amount,
                    'description' => $description,
                    'type' => 'transaction',
                    'rel_id' => $tx->id,
                ];

                $clientService->addFunds($client, $amount, $description, $balanceData);
                $invoiceService->payInvoiceWithCredits($invoice);
            } else {
                $invoiceService->markAsPaid($invoice, true, true);
                $tx->note = trim($tx->note . ' / invoice settled directly because checkout currency differed from invoice currency');
            }
        }

        $tx->status = 'processed';
        $tx->error = null;
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);

        return true;
    }

    private function getSecretKey(): ?string
    {
        return $this->getTestModeFlag()
            ? ($this->_config['test_api_key'] ?? null)
            : ($this->_config['api_key'] ?? null);
    }

    private function getPublicKey(): ?string
    {
        return $this->getTestModeFlag()
            ? ($this->_config['test_pub_key'] ?? null)
            : ($this->_config['pub_key'] ?? null);
    }

    private function getTestModeFlag(): bool
    {
        return (bool) ($this->_config['test_mode'] ?? false);
    }

    private function createCheckout(Model_Invoice $invoice, array $settlement): array
    {
        $amount = (int) $settlement['settlement_amount_cents'];
        if ($amount < 200) {
            throw new Payment_Exception('Yoco requires a minimum amount of R2.00.');
        }

        $gatewayId = $this->extractGatewayId();
        $title = $this->getInvoiceTitle($invoice);

        $payload = [
            'amount' => $amount,
            'currency' => 'ZAR',
            'successUrl' => $this->_config['return_url'],
            'cancelUrl' => $this->_config['cancel_url'],
            'failureUrl' => $this->_config['cancel_url'],
            'externalId' => (string) $invoice->id,
            'clientReferenceId' => 'invoice-' . $invoice->id,
            'metadata' => [
                'invoiceId' => (string) $invoice->id,
                'invoiceHash' => (string) $invoice->hash,
                'gatewayId' => (string) $gatewayId,
                'reference' => $title,
                'invoiceCurrency' => $settlement['invoice_currency'],
                'settlementCurrency' => 'ZAR',
                'settlementRate' => (string) $settlement['rate'],
            ],
        ];

        $response = $this->getHttpClient()->request('POST', 'https://payments.yoco.com/api/checkouts', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->getSecretKey(),
                'Content-Type' => 'application/json',
                'Idempotency-Key' => sha1('yoco-checkout|' . $invoice->id . '|' . $amount . '|' . $settlement['invoice_currency'] . '|' . $settlement['rate'] . '|' . $invoice->updated_at),
            ],
            'json' => $payload,
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->toArray(false);
        if ($statusCode < 200 || $statusCode >= 300) {
            $message = $body['message'] ?? $body['error']['message'] ?? 'Yoco checkout creation failed.';
            throw new Payment_Exception($message);
        }

        return $body;
    }

    private function rememberCheckout(Model_Invoice $invoice, array $checkout): void
    {
        $checkoutId = (string) ($checkout['id'] ?? '');
        if ($checkoutId === '') {
            return;
        }

        $gatewayId = $this->extractGatewayId();
        /** @var Model_Transaction|null $existing */
        $existing = $this->di['db']->findOne(
            'Transaction',
            'gateway_id = :gateway_id AND txn_id = :txn_id ORDER BY id DESC',
            [
                ':gateway_id' => $gatewayId,
                ':txn_id' => $checkoutId,
            ]
        );

        if ($existing instanceof Model_Transaction) {
            $existing->invoice_id = $invoice->id;
            $existing->txn_status = $checkout['status'] ?? $existing->txn_status;
            $existing->amount = isset($checkout['amount']) ? ((float) $checkout['amount']) / 100 : $existing->amount;
            $existing->currency = $checkout['currency'] ?? $existing->currency;
            $existing->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($existing);

            return;
        }

        $tx = $this->di['db']->dispense('Transaction');
        $tx->gateway_id = $gatewayId;
        $tx->invoice_id = $invoice->id;
        $tx->txn_id = $checkoutId;
        $tx->txn_status = $checkout['status'] ?? 'created';
        $tx->amount = isset($checkout['amount']) ? ((float) $checkout['amount']) / 100 : 0;
        $tx->currency = $checkout['currency'] ?? $invoice->currency;
        $tx->status = 'received';
        $tx->ip = $this->di['request']->getClientIp();
        $tx->note = 'Yoco checkout created';
        $tx->created_at = date('Y-m-d H:i:s');
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);
    }

    private function extractGatewayId(): int
    {
        $parts = parse_url((string) ($this->_config['notify_url'] ?? ''));
        if (!isset($parts['query'])) {
            throw new Payment_Exception('Yoco payment gateway is missing callback configuration.');
        }

        parse_str($parts['query'], $query);
        if (empty($query['gateway_id'])) {
            throw new Payment_Exception('Yoco payment gateway is missing callback gateway ID.');
        }

        return (int) $query['gateway_id'];
    }

    private function getAmountInCents(Model_Invoice $invoice): int
    {
        $invoiceService = $this->di['mod_service']('Invoice');
        $total = (float) $invoiceService->getTotalWithTax($invoice);

        return (int) round($total * 100);
    }

    private function getSettlementDetails(Model_Invoice $invoice): array
    {
        $invoiceCurrency = strtoupper((string) $invoice->currency);
        if (!in_array($invoiceCurrency, ['ZAR', 'USD'], true)) {
            throw new Payment_Exception('Yoco currently supports ZAR invoices and optional USD-to-ZAR conversion only.');
        }

        $invoiceService = $this->di['mod_service']('Invoice');
        $invoiceTotal = (float) $invoiceService->getTotalWithTax($invoice);

        if ($invoiceCurrency === 'ZAR') {
            return [
                'invoice_currency' => 'ZAR',
                'rate' => 1.0,
                'settlement_amount' => $invoiceTotal,
                'settlement_amount_cents' => (int) round($invoiceTotal * 100),
            ];
        }

        $rate = $this->getUsdToZarRate();
        $settlementAmount = round($invoiceTotal * $rate, 2);

        return [
            'invoice_currency' => 'USD',
            'rate' => $rate,
            'settlement_amount' => $settlementAmount,
            'settlement_amount_cents' => (int) round($settlementAmount * 100),
        ];
    }

    private function getUsdToZarRate(): float
    {
        $rate = $this->_config['usd_to_zar_rate'] ?? null;
        if ($rate !== null && $rate !== '') {
            if (!is_numeric($rate) || (float) $rate <= 0) {
                throw new Payment_Exception('The configured USD to ZAR conversion rate for Yoco is invalid.');
            }

            return (float) $rate;
        }

        $currencyService = $this->di['mod_service']('Currency');
        $default = $currencyService->getDefault();
        $defaultCode = strtoupper((string) ($default->code ?? ''));
        $usdRate = (float) $currencyService->getRateByCode('USD');
        $zarRate = (float) $currencyService->getRateByCode('ZAR');

        if ($defaultCode === 'USD') {
            if ($zarRate > 0 && abs($zarRate - 1.0) > 0.000001) {
                return $zarRate;
            }
        } elseif ($defaultCode === 'ZAR') {
            if ($usdRate > 0 && abs($usdRate - 1.0) > 0.000001) {
                return 1 / $usdRate;
            }
        } elseif ($usdRate > 0 && $zarRate > 0 && (abs($usdRate - 1.0) > 0.000001 || abs($zarRate - 1.0) > 0.000001)) {
            return (1 / $usdRate) * $zarRate;
        }

        throw new Payment_Exception('USD invoices are enabled for Yoco, but no usable USD/ZAR conversion rate is configured in the gateway or FOSSBilling currencies.');
    }

    private function getInvoiceTitle(Model_Invoice $invoice): string
    {
        $invoiceItems = $this->di['db']->getAll(
            'SELECT title FROM invoice_item WHERE invoice_id = :invoice_id',
            [':invoice_id' => $invoice->id]
        );

        $params = [
            ':id' => sprintf('%05s', $invoice->nr),
            ':serie' => $invoice->serie,
            ':title' => $invoiceItems[0]['title'] ?? 'Invoice',
        ];

        if (count($invoiceItems) > 1) {
            return __trans('Payment for invoice :serie:id', $params);
        }

        return __trans('Payment for invoice :serie:id [:title]', $params);
    }

    private function decodePayload(array $data): array
    {
        $raw = (string) ($data['http_raw_post_data'] ?? '');
        if ($raw === '') {
            return [];
        }

        $payload = json_decode($raw, true);
        return is_array($payload) ? $payload : [];
    }

    private function isValidWebhookSignature(string $payload, string $webhookId, string $webhookTimestamp, string $signatureHeader): bool
    {
        $webhookSecret = (string) ($this->_config['webhook_secret'] ?? '');
        if ($webhookSecret === '') {
            return true;
        }

        if ($payload === '' || $signatureHeader === '' || $webhookId === '' || $webhookTimestamp === '') {
            return false;
        }

        $secret = $webhookSecret;
        if (str_starts_with($secret, 'whsec_')) {
            $encoded = substr($secret, 6);
            $decoded = base64_decode($encoded, true);
            if ($decoded === false) {
                return false;
            }
            $secret = $decoded;
        }

        $signedContent = $webhookId . '.' . $webhookTimestamp . '.' . $payload;
        $expectedSignature = base64_encode(hash_hmac('sha256', $signedContent, $secret, true));

        foreach (preg_split('/\s+/', trim($signatureHeader)) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            if (str_starts_with($candidate, 'v1,')) {
                $candidate = substr($candidate, 3);
            }
            if ($candidate !== '' && hash_equals($expectedSignature, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function hasProcessedDuplicate(string $txnId, int $currentId): bool
    {
        /** @var Model_Transaction|null $duplicate */
        $duplicate = $this->di['db']->findOne(
            'Transaction',
            'txn_id = :txn_id AND id != :id AND status = :status ORDER BY id DESC',
            [
                ':txn_id' => $txnId,
                ':id' => $currentId,
                ':status' => 'processed',
            ]
        );

        return $duplicate instanceof Model_Transaction;
    }

    public function getHttpClient(): Symfony\Contracts\HttpClient\HttpClientInterface
    {
        return HttpClient::create([
            'bindto' => BIND_TO,
            'timeout' => 60,
        ]);
    }
}
