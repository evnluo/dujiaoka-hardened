<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop_mcp_tokens', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('admin_id')->index(); $t->string('name', 100);
            $t->char('token_hash', 64)->unique(); $t->text('scopes');
            // DATETIME avoids legacy MariaDB's implicit TIMESTAMP defaults/ON UPDATE.
            $t->dateTime('expires_at'); $t->dateTime('revoked_at')->nullable(); $t->dateTime('created_at');
        });
    }
    public function down(): void { Schema::dropIfExists('shop_mcp_tokens'); }
};
