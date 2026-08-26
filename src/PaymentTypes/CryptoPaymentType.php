<?php

namespace Lunar\CryptoPayments\PaymentTypes;

use Lunar\Base\DataTransferObjects\PaymentAuthorize;
use Lunar\Base\DataTransferObjects\PaymentCapture;
use Lunar\Base\DataTransferObjects\PaymentRefund;
use Lunar\CryptoPayments\Actions\SettleOnChainPayment;
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

        $requirements = $this->buildPaymentRequirements();

        $result = $this->settle->execute($payload, $requirements);

        if (! $result->success) {
            return new PaymentAuthorize(success: false, message: $result->message, orderId: $this->order->id, paymentType: 'crypto');
        }

        $this->order->transactions()->create([
            'success' => true,
            'type' => 'capture',
            'driver' => 'crypto',
            'amount' => $result->settledAmount,
            'reference' => $result->txHash,
            'status' => 'settled',
        ]);

        $this->order->update(['placed_at' => now()]);

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
