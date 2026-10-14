<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop_mcp_previews', function (Blueprint $t): void {
            $t->char('token_hash', 64)->primary(); $t->unsignedBigInteger('admin_id'); $t->unsignedBigInteger('token_id');
            $t->string('operation', 80); $t->string('scope', 40); $t->char('payload_hash', 64); $t->char('state_hash', 64);
            $t->timestamp('expires_at')->index(); $t->timestamp('created_at');
        });
        Schema::create('shop_mcp_receipts', function (Blueprint $t): void {
            $t->id(); $t->char('binding', 64)->unique(); $t->unsignedBigInteger('admin_id'); $t->unsignedBigInteger('token_id');
            $t->string('operation', 80); $t->string('scope', 40); $t->char('payload_hash', 64);
            $t->string('status', 30); $t->longText('result')->nullable(); $t->timestamps();
        });
        Schema::create('shop_mcp_audit', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('admin_id'); $t->unsignedBigInteger('token_id'); $t->string('operation', 80);
            $t->string('scope', 40); $t->string('status', 40); $t->unsignedBigInteger('receipt_id')->nullable();
            $t->string('reason_code', 80)->nullable(); $t->timestamp('created_at')->index();
            $t->text('targets')->nullable(); $t->char('payload_hash', 64)->nullable();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('shop_mcp_audit'); Schema::dropIfExists('shop_mcp_receipts'); Schema::dropIfExists('shop_mcp_previews');
    }
};
