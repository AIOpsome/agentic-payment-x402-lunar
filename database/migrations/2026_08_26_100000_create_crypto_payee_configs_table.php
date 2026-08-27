<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Lunar\Base\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->prefix.'crypto_payee_configs', function (Blueprint $table) {
            $table->id();
            // 'pay_to' (human checkout) or 'x402_pay_to' (agent checkout) —
            // each is guarded independently since they can be configured to
            // different wallets.
            $table->string('key')->unique();
            $table->string('address');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->prefix.'crypto_payee_configs');
    }
};
