<?php

namespace Lunar\CryptoPayments\Actions;

use Illuminate\Support\Facades\DB;
use Lunar\CryptoPayments\DataTransferObjects\CryptoAuthorizationResult;
use Lunar\CryptoPayments\Models\CryptoSettlement;
use Lunar\Exceptions\Carts\CartException;
use Lunar\Exceptions\DisallowMultipleCartOrdersException;
use Lunar\Models\Contracts\Cart;
use Lunar\Models\Contracts\Order;

/**
 * The one place a crypto payment gets turned into a placed Lunar order —
 * shared by both entry points (CryptoPaymentType for human checkout,
 * X402PaymentMiddleware for agent checkout) so there's exactly one
 * settle-then-record-then-finalize implementation, not two that could drift.
 *
 * A cart is a cart either way: the human flow gets one from the checkout
 * session, the agent (x402) flow gets one bound to the request route. What
 * happens once a signed payload and a cart exist is identical.
 */
class AuthorizeCryptoPayment
{
    public function __construct(
        protected BuildPaymentRequirements $buildRequirements,
        protected ValidatePaymentPayload $validatePayload,
        protected SettleOnChainPayment $settle,
    ) {}

    public function execute(Cart $cart, array $payload, array $config = [], ?Order $order = null): CryptoAuthorizationResult
    {
        if (! $order) {
            try {
                $order = $cart->completedOrder ?: $cart->draftOrder ?: $cart->createOrder();
            } catch (DisallowMultipleCartOrdersException|CartException $e) {
                return new CryptoAuthorizationResult(success: false, message: $e->getMessage());
            }
        }

        // Idempotency guard: a prior attempt may have settled on-chain and
        // then failed before the order could be finalized. Never settle
        // twice for the same cart — resume finalizing the existing record
        // instead, so a retry can't double-charge the payer's wallet.
        $settlement = CryptoSettlement::where('cart_id', $order->cart_id)->latest()->first();

        if ($settlement?->isRecorded()) {
            return new CryptoAuthorizationResult(success: true, order: $order);
        }

        if (! $settlement) {
            $requirements = $this->buildRequirements->execute($order->total, $config);

            if ($error = $this->validatePayload->execute($payload, $requirements)) {
                return new CryptoAuthorizationResult(success: false, order: $order, message: $error);
            }

            $result = $this->settle->execute($payload, $requirements);

            if (! $result->success) {
                return new CryptoAuthorizationResult(success: false, order: $order, message: $result->message);
            }

            // Written the instant settlement is confirmed — before the order
            // writes below, which can still fail. If they do, this row is
            // the proof funds already moved, and the next attempt resumes
            // from here instead of settling again.
            $settlement = CryptoSettlement::create([
                'cart_id' => $order->cart_id,
                'order_id' => $order->id,
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
            DB::transaction(function () use ($order, $settlement) {
                $order->transactions()->create([
                    'success' => true,
                    'type' => 'capture',
                    'driver' => 'crypto',
                    'amount' => $settlement->amount,
                    'reference' => $settlement->tx_hash,
                    'status' => 'settled',
                    // Lunar's transactions table is card-payment-shaped
                    // (card_type/last_four are NOT NULL) even though it's
                    // meant to be driver-agnostic — there's no card here,
                    // so this records what actually authorized the charge.
                    'card_type' => 'crypto',
                    'last_four' => '',
                ]);

                $order->update(['placed_at' => now()]);

                $settlement->update(['status' => 'recorded']);
            });
        } catch (\Throwable $e) {
            // Funds already moved (see the settlement row) but the order
            // couldn't be finalized. Reporting this as a plain failure would
            // be dishonest — the payer was charged. Surface it distinctly so
            // the caller doesn't tell them to try again.
            return new CryptoAuthorizationResult(
                success: false,
                order: $order,
                message: 'Payment settled on-chain but order finalization failed; do not resubmit — this will be reconciled.',
            );
        }

        return new CryptoAuthorizationResult(success: true, order: $order);
    }
}
