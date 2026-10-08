<?php

use App\Support\PurchasePrompt;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods', function (Blueprint $table): void {
            $table->boolean('buy_prompt_enabled')->default(false);
        });

        // Include archived products, preserve their HTML, and leave new products opt-in.
        DB::table('goods')->select(['id', 'buy_prompt'])->orderBy('id')->chunkById(200, function ($rows): void {
            $ids = $rows->filter(fn ($row) => PurchasePrompt::hasContent($row->buy_prompt))->pluck('id');
            if ($ids->isNotEmpty()) {
                DB::table('goods')->whereIn('id', $ids)->update(['buy_prompt_enabled' => true]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('goods', fn (Blueprint $table) => $table->dropColumn('buy_prompt_enabled'));
    }
};
