<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Lunar\Base\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->prefix.'crypto_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained($this->prefix.'carts');
            $table->foreignId('order_id')->nullable()->constrained($this->prefix.'orders');
            // Unique so a retried authorize() attempt can never settle twice
            // for the same cart, and a facilitator tx_hash is never recorded twice.
            $table->string('tx_hash')->unique();
            $table->string('network');
            $table->string('asset');
            $table->unsignedBigInteger('amount');
            $table->string('payer')->nullable();
            $table->string('facilitator');
            // 'settled': facilitator confirmed the on-chain transfer, order not
            // yet finalized. 'recorded': order transaction + placed_at written.
            $table->string('status')->default('settled');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->prefix.'crypto_settlements');
    }
};
