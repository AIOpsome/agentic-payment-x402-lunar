<?php

namespace Lunar\CryptoPayments\Actions;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Lunar\CryptoPayments\DataTransferObjects\CryptoAuthorizationResult;
use Lunar\CryptoPayments\Exceptions\PayeeAddressChangedException;
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
        protected GuardPayeeAddressChange $guardPayee,
    ) {}

    public function execute(Cart $cart, array $payload, array $config = [], ?Order $order = null, string $payeeKey = 'pay_to'): CryptoAuthorizationResult
    {
        if (! $order) {
            try {
                $order = $cart->completedOrder ?: $cart->draftOrder ?: $cart->createOrder();
            } catch (DisallowMultipleCartOrdersException|CartException $e) {
                return new CryptoAuthorizationResult(success: false, message: $e->getMessage());
            }
        }

        // Guards against a compromised/mistyped .env or deploy pipeline
        // silently redirecting settlements to a different wallet — checked
        // before anything else costs a facilitator round-trip. Same
        // pay_to resolution BuildPaymentRequirements uses, so this guards
        // the exact address that would actually be requested.
        try {
            $this->guardPayee->execute($payeeKey, $config['pay_to'] ?? config('lunar-crypto.pay_to'));
        } catch (PayeeAddressChangedException $e) {
            return new CryptoAuthorizationResult(success: false, order: $order, message: $e->getMessage());
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
            // A sequential retry is safe via the check above, but two
            // genuinely concurrent requests for the same cart would both
            // pass that check before either writes — and, worse, both
            // settle on-chain independently before either record exists
            // locally. The lock has to wrap the settle call itself, not
            // just the DB write after it.
            $lock = Cache::lock("lunar-crypto-settlement:{$order->cart_id}", 30);

            try {
                $lock->block(5);
            } catch (LockTimeoutException) {
                return new CryptoAuthorizationResult(success: false, order: $order, message: 'Another payment attempt for this cart is already in progress.');
            }

            try {
                // Re-check now that we hold the lock: another request may
                // have settled while we were waiting for it.
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

                    // Written the instant settlement is confirmed — before
                    // the order writes below, which can still fail. If they
                    // do, this row is the proof funds already moved, and
                    // the next attempt resumes from here instead of
                    // settling again. amount is the order's total in the
                    // STORE currency's minor unit — not the settled asset's
                    // atomic-unit amount, which is a different scale
                    // entirely (see ConvertToAssetUnits) and would corrupt
                    // this audit trail if used directly.
                    $settlement = CryptoSettlement::create([
                        'cart_id' => $order->cart_id,
                        'order_id' => $order->id,
                        'tx_hash' => $result->txHash,
                        'network' => $requirements['network'],
                        'asset' => $requirements['asset'],
                        'amount' => $order->total->value,
                        'payer' => $result->payer,
                        'facilitator' => $result->facilitator,
                        'status' => 'settled',
                    ]);
                }
            } finally {
                $lock->release();
            }
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
                    // (card_type/last_four are NOT NULL in the final
                    // migrated schema — verified empirically; an earlier
                    // migration makes last_four nullable but a later one
                    // resets it via ->change() without ->nullable()) even
                    // though the table is meant to be driver-agnostic —
                    // there's no card here, so this records what actually
                    // authorized the charge instead.
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
