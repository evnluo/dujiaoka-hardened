<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive bookkeeping only: no existing order/payment/inventory rows touched.
        // No legacy foreign key/type assumptions and no cascading deletion of history.
        Schema::create('shop_order_refunds', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('pay_id')->nullable();
            $table->string('trade_no', 200);
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3);
            $table->string('provider', 20);
            $table->string('external_reference', 160);
            $table->char('reference_hash', 64)->unique();
            $table->dateTime('refunded_at');
            $table->unsignedBigInteger('recorded_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_order_refunds');
    }
};
