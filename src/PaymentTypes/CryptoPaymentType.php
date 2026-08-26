<?php

namespace Lunar\CryptoPayments\PaymentTypes;

use Illuminate\Support\Facades\DB;
use Lunar\Base\DataTransferObjects\PaymentAuthorize;
use Lunar\Base\DataTransferObjects\PaymentCapture;
use Lunar\Base\DataTransferObjects\PaymentRefund;
use Lunar\CryptoPayments\Actions\SettleOnChainPayment;
use Lunar\CryptoPayments\Models\CryptoSettlement;
use Lunar\Exceptions\Carts\CartException;
use Lunar\Exceptions\DisallowMultipleCartOrdersException;
use Lunar\Models\Contracts\Transaction as TransactionContract;
use Lunar\PaymentTypes\AbstractPayment;

/**
 * Human wallet checkout: the frontend has the shopper sign an EIP-3009
 * transfer (e.g. USDC) via their wallet and posts the resulting x402
 * PaymentPayload here as cart data (`payment_payload`).
 */
class CryptoPaymentType extends AbstractPayment
{
    public function __construct(protected SettleOnChainPayment $settle) {}

    final public function authorize(): ?PaymentAuthorize
    {
        $payload = $this->data['payment_payload'] ?? null;

        if (! $payload) {
            return new PaymentAuthorize(success: false, message: 'Missing signed payment payload', paymentType: 'crypto');
        }

        if (! $this->order) {
            try {
                $this->order = $this->cart->createOrder();
            } catch (DisallowMultipleCartOrdersException|CartException $e) {
                return new PaymentAuthorize(success: false, message: $e->getMessage(), paymentType: 'crypto');
            }
        }

        // Idempotency guard: a prior attempt may have settled on-chain and
        // then failed before the order could be finalized. Never settle
        // twice for the same cart — resume finalizing the existing record
        // instead, so a retry can't double-charge the shopper's wallet.
        $settlement = CryptoSettlement::where('cart_id', $this->order->cart_id)->latest()->first();

        if ($settlement?->isRecorded()) {
            return new PaymentAuthorize(success: true, orderId: $settlement->order_id, paymentType: 'crypto');
        }

        if (! $settlement) {
            $requirements = $this->buildPaymentRequirements();

            $result = $this->settle->execute($payload, $requirements);

            if (! $result->success) {
                return new PaymentAuthorize(success: false, message: $result->message, orderId: $this->order->id, paymentType: 'crypto');
            }

            // Written the instant settlement is confirmed — before the order
            // writes below, which can still fail. If they do, this row is
            // the proof funds already moved, and the next authorize() call
            // resumes from here instead of settling again.
            $settlement = CryptoSettlement::create([
                'cart_id' => $this->order->cart_id,
                'order_id' => $this->order->id,
                'tx_hash' => $result->txHash,
                'network' => $requirements['network'],
                'asset' => $requirements['asset'],
                'amount' => $result->settledAmount,
                'payer' => $result->payer,
                'facilitator' => $result->facilitator,
                'status' => 'settled',
            ]);
        }

        try {
            DB::transaction(function () use ($settlement) {
                $this->order->transactions()->create([
                    'success' => true,
                    'type' => 'capture',
                    'driver' => 'crypto',
                    'amount' => $settlement->amount,
                    'reference' => $settlement->tx_hash,
                    'status' => 'settled',
                ]);

                $this->order->update(['placed_at' => now()]);

                $settlement->update(['status' => 'recorded']);
            });
        } catch (\Throwable $e) {
            // Funds already moved (see the settlement row) but the order
            // couldn't be finalized. Reporting this as a plain failure would
            // be dishonest — the shopper was charged. Surface it distinctly
            // so the caller doesn't tell them to try again.
            return new PaymentAuthorize(
                success: false,
                message: 'Payment settled on-chain but order finalization failed; do not resubmit — this will be reconciled.',
                orderId: $this->order->id,
                paymentType: 'crypto',
            );
        }

        return new PaymentAuthorize(success: true, orderId: $this->order->id, paymentType: 'crypto');
    }

    protected function buildPaymentRequirements(): array
    {
        return [
            'scheme' => 'exact',
            'network' => $this->config['network'] ?? config('lunar-crypto.network'),
            'amount' => (string) $this->order->total->value,
            'asset' => $this->config['asset'] ?? config('lunar-crypto.asset'),
            'payTo' => $this->config['pay_to'] ?? config('lunar-crypto.pay_to'),
            'maxTimeoutSeconds' => 60,
        ];
    }

    // On-chain settlement is atomic (verify + settle in one call) — nothing to
    // separately "capture". Kept as a no-op success to satisfy the interface.
    public function capture(TransactionContract $transaction, $amount = 0): PaymentCapture
    {
        return new PaymentCapture(success: true);
    }

    public function refund(TransactionContract $transaction, int $amount = 0, $notes = null): PaymentRefund
    {
        // Requires a separate outbound on-chain transfer back to the payer's
        // wallet — not yet sketched.
        throw new \RuntimeException('Not yet implemented — spike stub.');
    }
}
