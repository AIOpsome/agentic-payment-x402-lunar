<?php

namespace Lunar\CryptoPayments\Console\Commands;

use Illuminate\Console\Command;
use Lunar\CryptoPayments\Models\CryptoPayeeConfig;

/**
 * Explicit re-confirmation step for GuardPayeeAddressChange: an operator
 * who intentionally changed lunar-crypto's pay_to/x402.pay_to config runs
 * this to accept the new address as the confirmed baseline, unblocking
 * settlement. Reads the address from the live config rather than accepting
 * one as an argument, so this can only confirm what's actually configured
 * — not point the baseline at an arbitrary address.
 */
class ConfirmPayeeAddress extends Command
{
    protected $signature = 'lunar-crypto:confirm-payee {key : pay_to or x402_pay_to}';

    protected $description = 'Confirm a changed lunar-crypto payee (pay_to) address, unblocking settlement';

    public function handle(): int
    {
        $key = $this->argument('key');

        $configPath = match ($key) {
            'pay_to' => 'lunar-crypto.pay_to',
            'x402_pay_to' => 'lunar-crypto.x402.pay_to',
            default => null,
        };

        if ($configPath === null) {
            $this->error("Unknown payee key [{$key}] — expected 'pay_to' or 'x402_pay_to'.");

            return self::FAILURE;
        }

        $configuredAddress = config($configPath);

        if (! $configuredAddress) {
            $this->error("[{$configPath}] is not set in config — nothing to confirm.");

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
