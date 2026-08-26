<?php

namespace Lunar\CryptoPayments\Console\Commands;

use Illuminate\Console\Command;
use Lunar\CryptoPayments\Actions\ResolvePayeeAddress;
use Lunar\CryptoPayments\Models\CryptoPayeeConfig;

/**
 * Explicit re-confirmation step for GuardPayeeAddressChange: an operator
 * who intentionally changed lunar-crypto's pay_to/x402.pay_to config runs
 * this to accept the new address as the confirmed baseline, unblocking
 * settlement. Resolves the address via the same ResolvePayeeAddress the
 * guard itself uses (including the x402.pay_to -> global pay_to fallback),
 * so this can only ever confirm the exact "effective" address the guard is
 * actually checking — never something that drifts from it, and never an
 * arbitrary address supplied on the command line.
 */
class ConfirmPayeeAddress extends Command
{
    protected $signature = 'lunar-crypto:confirm-payee {key : pay_to or x402_pay_to}';

    protected $description = 'Confirm a changed lunar-crypto payee (pay_to) address, unblocking settlement';

    public function __construct(protected ResolvePayeeAddress $resolvePayee)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $key = $this->argument('key');

        if (! in_array($key, ['pay_to', 'x402_pay_to'], true)) {
            $this->error("Unknown payee key [{$key}] — expected 'pay_to' or 'x402_pay_to'.");

            return self::FAILURE;
        }

        $configuredAddress = $this->resolvePayee->execute($key);

        if (! $configuredAddress) {
            $this->error("No configured address found for [{$key}] — nothing to confirm.");

            return self::FAILURE;
        }

        $record = CryptoPayeeConfig::updateOrCreate(
            ['key' => $key],
            ['address' => $configuredAddress]
        );

        $this->info("Confirmed lunar-crypto payee [{$key}] = [{$record->address}].");

        return self::SUCCESS;
    }
}
