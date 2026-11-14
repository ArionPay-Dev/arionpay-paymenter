<?php

namespace Paymenter\Extensions\Gateways\ArionPay;

use App\Classes\Extension\Gateway;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

if (!class_exists(\ArionPay\Client::class, true)) {
    require_once __DIR__ . '/lib/arionpay-php/autoload.php';
}

/**
 * ArionPay for Paymenter: crypto payments that go straight to the merchant's own wallet.
 *
 * The client presses Pay on an invoice and goes to the ArionPay payment page. Only a signed ArionPay webhook
 * (invoice.paid) records the payment; the transaction id is the ArionPay invoice id, so repeated deliveries update
 * one transaction instead of adding money twice. Whether an invoice is paid is decided by ArionPay alone.
 */
class ArionPay extends Gateway
{
    public const VERSION = '1.0.0';

    public const CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'TRY', 'AED', 'INR', 'BRL', 'RUB', 'CNY', 'SGD', 'HKD', 'KRW', 'MXN', 'PLN', 'SEK', 'NOK', 'ZAR'];

    public function boot()
    {
        require __DIR__ . '/routes.php';
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'api_key',
                'label' => 'API key (pk_...)',
                'type' => 'text',
                'description' => 'ArionPay dashboard > your store > API key.',
                'required' => true,
            ],
            [
                'name' => 'api_secret',
                'label' => 'API secret (sk_...)',
                'type' => 'text',
                'description' => 'ArionPay dashboard > your store > API secret.',
                'encrypted' => true,
                'required' => true,
            ],
            [
                'name' => 'webhook_secret',
                'label' => 'Webhook signing secret (whsec_...)',
                'type' => 'text',
                'description' => 'ArionPay dashboard > Webhooks > signing secret. Nothing to paste into ArionPay: every invoice tells ArionPay where to send its payment updates.',
                'encrypted' => true,
                'required' => true,
            ],
            [
                'name' => 'test_mode',
                'label' => 'Test mode',
                'type' => 'checkbox',
                'description' => 'Test invoices: no real money moves. Turn it off before you take real payments.',
                'required' => false,
            ],
            [
                'name' => 'base_url',
                'label' => 'ArionPay address',
                'type' => 'text',
                'description' => 'Leave empty unless ArionPay tells you otherwise.',
                'required' => false,
            ],
        ];
    }

    public function canUseGateway($items, $type)
    {
        return true;
    }

    /**
     * Send the client to the ArionPay payment page for the amount still open on the invoice. Idempotency keys make
     * this stateless: the same key returns the same ArionPay invoice, and a new attempt number makes a fresh one
     * once the previous invoice expired.
     */
    public function pay(Invoice $invoice, $total)
    {
        $currency = strtoupper((string) $invoice->currency_code);
        if (!in_array($currency, self::CURRENCIES, true)) {
            throw new \Exception('ArionPay cannot take payments in ' . $currency . '.');
        }
        $amount = number_format((float) $total, 2, '.', '');
        $test = (bool) $this->config('test_mode');
        $site = substr(hash('sha256', config('app.url') . '|' . config('database.connections.' . config('database.default') . '.database')), 0, 12);
        $base = 'paymenter-' . $site . '-' . $invoice->id . '-' . $amount . $currency . ($test ? '-test' : '');
        $back = route('invoices.show', $invoice);
        $client = $this->client();

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $created = $client->createInvoice([
                'amount' => $amount,
                'currency' => $currency,
                'orderId' => (string) $invoice->id,
                'description' => mb_substr('Invoice #' . ($invoice->number ?: $invoice->id) . ' at ' . config('app.name', 'Paymenter'), 0, 500),
                'successUrl' => $back,
                'cancelUrl' => $back,
                'callbackUrl' => route('extensions.gateways.arionpay.webhook'),
                'metadata' => [
                    'paymenter_invoice_id' => (int) $invoice->id,
                    'paymenter_amount' => $amount,
                    'platform' => 'paymenter',
                ],
                'idempotencyKey' => $base . '-' . $attempt,
                'test' => $test,
            ]);
            $expires = isset($created['expiresAt']) ? strtotime((string) $created['expiresAt']) : 0;
            if ((($created['status'] ?? 'new') === 'new' && $expires > time() + 300) || in_array($created['status'] ?? '', ['pending', 'paid_partial', 'claim_pending'], true)) {
                return $created['invoiceUrl'];
            }
        }

        throw new \Exception('Too many ArionPay invoices for this invoice.');
    }

    /** Webhook endpoint: POST /extensions/gateways/arionpay/webhook. Non-2xx answers are retried by ArionPay. */
    public function webhook(Request $request)
    {
        $secret = (string) $this->config('webhook_secret');
        if ($secret === '') {
            return response()->json(['error' => 'not_configured'], 503);
        }
        $raw = (string) $request->getContent();
        if (strlen($raw) > 262144) {
            return response()->json(['error' => 'payload_too_large'], 413);
        }
        try {
            // The body is signed byte for byte, so it is read raw, never through Laravel's parsed input.
            $event = \ArionPay\Webhook::verify($raw, (string) $request->header('ArionPay-Signature', ''), $secret);
        } catch (\ArionPay\SignatureException $e) {
            Log::warning('ArionPay webhook refused: ' . $e->getMessage());
            return response()->json(['error' => 'invalid_signature'], 401);
        }
        if (\ArionPay\Webhook::isTestEvent($event)) {
            return response()->json(['received' => true, 'test_event' => true]);
        }
        if (!in_array($event['type'], \ArionPay\Webhook::EVENT_TYPES, true)) {
            return response()->json(['received' => true, 'ignored' => 'unknown_event']);
        }
        $remote = $event['data']['invoice'];
        $metadata = isset($remote['metadata']) && is_array($remote['metadata']) ? $remote['metadata'] : [];
        $invoiceId = (int) ($metadata['paymenter_invoice_id'] ?? 0);
        $remoteId = (string) ($remote['id'] ?? '');
        if ($invoiceId <= 0 || !preg_match('/^[0-9a-f]{24}$/i', $remoteId)) {
            return response()->json(['received' => true, 'ignored' => 'not_a_paymenter_invoice']);
        }
        $invoice = Invoice::find($invoiceId);
        if (!$invoice) {
            return response()->json(['error' => 'invoice_not_found'], 404);
        }
        if ((string) ($remote['orderId'] ?? '') !== (string) $invoice->id || (bool) ($remote['test'] ?? false) !== (bool) $this->config('test_mode')) {
            return response()->json(['error' => 'invoice_mismatch'], 409);
        }

        $lock = Cache::lock('arionpay-paymenter-' . $invoice->id, 30);
        if (!$lock->get()) {
            return response()->json(['error' => 'busy'], 503)->header('Retry-After', '30');
        }
        try {
            return $this->apply($invoice->fresh(), $event, $remote, $metadata);
        } finally {
            $lock->release();
        }
    }

    private function apply(Invoice $invoice, array $event, array $remote, array $metadata)
    {
        $transactionId = 'arionpay:' . $remote['id'];
        $existing = InvoiceTransaction::where('transaction_id', $transactionId)->first();
        $paid = is_numeric($remote['amountFiat'] ?? null) ? number_format((float) $remote['amountFiat'], 2, '.', '') : '';
        $currency = strtoupper((string) ($remote['currency'] ?? ''));

        if ($event['type'] === 'invoice.paid') {
            if ($existing && $existing->status === InvoiceTransactionStatus::Succeeded) {
                return response()->json(['received' => true, 'duplicate' => true]);
            }
            // The ArionPay invoice must still match: same currency, same amount as it was made for and as is still open
            // on the invoice (not a payment tolerance: it catches an invoice that was edited after the client went to pay).
            $open = number_format(max(0, (float) $invoice->remaining), 2, '.', '');
            if ($currency !== strtoupper((string) $invoice->currency_code) || $paid !== (string) ($metadata['paymenter_amount'] ?? '') || $paid !== $open) {
                Log::warning(sprintf('ArionPay invoice %s is paid (%s %s), but Paymenter invoice #%s has %s %s open, so no payment was added. Check it and add the payment by hand. %s', $remote['id'], $paid !== '' ? $paid : '?', $currency, $invoice->id, $open, $invoice->currency_code, (float) $open <= 0 ? 'The invoice was already paid: this may be a double payment, check your wallet and refund the client if needed.' : ''));
                ExtensionHelper::addFailedPayment($invoice, 'ArionPay', (float) ($paid !== '' ? $paid : 0), null, $transactionId);
                return response()->json(['received' => true, 'ignored' => (float) $open <= 0 ? 'already_paid' : 'amount_mismatch']);
            }
            ExtensionHelper::addPayment($invoice, 'ArionPay', (float) $paid, null, $transactionId);
            return response()->json(['received' => true]);
        }

        if ($event['type'] === 'invoice.pending' && (!$existing || $existing->status !== InvoiceTransactionStatus::Succeeded) && (float) $invoice->remaining > 0) {
            // money on its way: shown as a processing payment until it is confirmed
            ExtensionHelper::addProcessingPayment($invoice, 'ArionPay', (float) ($paid !== '' ? $paid : 0), null, $transactionId);
        } elseif ($event['type'] === 'invoice.expired' && $existing && $existing->status === InvoiceTransactionStatus::Processing) {
            ExtensionHelper::addFailedPayment($invoice, 'ArionPay', (float) $existing->amount, null, $transactionId);
        } elseif ($event['type'] === 'invoice.needs_review') {
            Log::warning(sprintf('ArionPay invoice %s (Paymenter invoice #%s) needs your review in the ArionPay dashboard: %s', $remote['id'], $invoice->id, (string) ($remote['reviewReason'] ?? '-')));
        }
        return response()->json(['received' => true]);
    }

    private function client(): \ArionPay\Client
    {
        $base = trim((string) $this->config('base_url'));
        return new \ArionPay\Client(trim((string) $this->config('api_key')), trim((string) $this->config('api_secret')), [
            'baseUrl' => $base !== '' ? $base : 'https://merchant.arionpay.com',
            'appInfo' => 'paymenter arionpay-paymenter/' . self::VERSION,
        ]);
    }
}
