<?php

namespace Lunar\CryptoPayments\PaymentTypes;

use Lunar\Base\DataTransferObjects\PaymentAuthorize;
use Lunar\Base\DataTransferObjects\PaymentCapture;
use Lunar\Base\DataTransferObjects\PaymentRefund;
use Lunar\CryptoPayments\Actions\AuthorizeCryptoPayment;
use Lunar\Models\Contracts\Transaction as TransactionContract;
use Lunar\PaymentTypes\AbstractPayment;

/**
 * Human wallet checkout: the frontend has the shopper sign an EIP-3009
 * transfer (e.g. USDC) via their wallet and posts the resulting x402
 * PaymentPayload here as cart data (`payment_payload`). Delegates the
 * settle-then-finalize logic to AuthorizeCryptoPayment, shared with the
 * agent (x402) checkout flow.
 */
class CryptoPaymentType extends AbstractPayment
{
    public function __construct(protected AuthorizeCryptoPayment $authorizeCryptoPayment) {}

    final public function authorize(): ?PaymentAuthorize
    {
        $payload = $this->data['payment_payload'] ?? null;

        if (! $payload) {
            return new PaymentAuthorize(success: false, message: 'Missing signed payment payload', paymentType: 'crypto');
        }

        $cart = $this->cart ?: $this->order->cart;

        $result = $this->authorizeCryptoPayment->execute($cart, $payload, $this->config, $this->order);

        return new PaymentAuthorize(
            success: $result->success,
            message: $result->message,
            orderId: $result->order?->id,
            paymentType: 'crypto',
        );
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
