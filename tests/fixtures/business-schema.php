<?php
// Synthetic schema mirrors the existing business table/column names, never production rows.
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
    throw new RuntimeException('Fixture schema is restricted to in-memory SQLite.');
}
Schema::create('goods_group', function ($t): void {
    $t->id(); $t->string('gp_name'); $t->integer('is_open')->default(1); $t->integer('ord')->default(1); $t->timestamps(); $t->softDeletes();
});
Schema::create('goods', function ($t): void {
    $t->id(); $t->integer('group_id'); $t->string('gd_name');
    foreach (['gd_description', 'gd_keywords', 'picture', 'buy_prompt', 'description', 'wholesale_price_cnf', 'other_ipu_cnf', 'api_hook'] as $field) $t->text($field)->nullable();
    foreach (['retail_price', 'actual_price'] as $field) $t->decimal($field, 10, 2)->default(0);
    foreach (['in_stock', 'sales_volume', 'buy_limit_num'] as $field) $t->integer($field)->default(0);
    foreach (['ord', 'type', 'is_open'] as $field) $t->integer($field)->default(1);
    $t->timestamps(); $t->softDeletes();
});
Schema::create('carmis', function ($t): void {
    $t->id(); $t->integer('goods_id'); $t->integer('status')->default(1); $t->integer('is_loop')->default(0); $t->text('carmi'); $t->timestamps(); $t->softDeletes();
});
Schema::create('pays', function ($t): void {
    $t->id(); $t->string('pay_name'); $t->string('pay_check')->unique();
    $t->integer('pay_method')->default(1); $t->integer('pay_client')->default(3);
    foreach (['merchant_id', 'merchant_key', 'merchant_pem', 'pay_handleroute'] as $field) $t->text($field);
    $t->integer('is_open')->default(1); $t->timestamps(); $t->softDeletes();
});
Schema::create('coupons', function ($t): void {
    $t->id(); $t->decimal('discount', 10, 2)->default(0); $t->string('coupon')->unique();
    $t->integer('is_use')->default(1); $t->integer('is_open')->default(1); $t->integer('ret')->default(0); $t->timestamps(); $t->softDeletes();
});
Schema::create('coupons_goods', function ($t): void { $t->id(); $t->integer('goods_id'); $t->integer('coupons_id'); });
Schema::create('orders', function ($t): void {
    $t->id(); $t->string('order_sn', 150)->unique(); $t->integer('goods_id'); $t->integer('coupon_id')->default(0); $t->string('title');
    $t->integer('type')->default(1); $t->integer('buy_amount')->default(1);
    foreach (['goods_price', 'coupon_discount_price', 'wholesale_discount_price', 'total_price', 'actual_price'] as $field) $t->decimal($field, 10, 2)->default(0);
    $t->string('search_pwd')->default(''); $t->string('email'); $t->text('info')->nullable(); $t->integer('pay_id')->nullable();
    $t->string('buy_ip'); $t->string('trade_no')->default(''); $t->integer('status')->default(1); $t->integer('coupon_ret_back')->default(0);
    $t->timestamps(); $t->softDeletes();
});
Schema::create('emailtpls', function ($t): void {
    $t->id(); $t->string('tpl_name'); $t->string('tpl_token'); $t->text('tpl_content'); $t->timestamps(); $t->softDeletes();
});
Schema::create('jobs', function ($t): void {
    $t->id(); $t->string('queue')->index(); $t->longText('payload'); $t->unsignedTinyInteger('attempts');
    $t->unsignedInteger('reserved_at')->nullable(); $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at');
});
DB::table('goods_group')->insert(['id'=>1, 'gp_name'=>'Synthetic group']);
DB::table('goods')->insert(['id'=>7, 'group_id'=>1, 'gd_name'=>'Synthetic product', 'actual_price'=>'10.00', 'retail_price'=>'10.00', 'in_stock'=>3]);
DB::table('carmis')->insert(['id'=>501, 'goods_id'=>7, 'carmi'=>'SYNTHETIC-CARD-501']);
DB::table('pays')->insert([
    ['id'=>14, 'pay_name'=>'Yipay fixture', 'pay_check'=>'alipay', 'merchant_id'=>'fixture-merchant', 'merchant_key'=>'https://pay.example.test', 'merchant_pem'=>'fixture-signing-key', 'pay_handleroute'=>'/pay/yipay'],
    ['id'=>15, 'pay_name'=>'Removed integration fixture', 'pay_check'=>'old-paypal', 'merchant_id'=>'fixture', 'merchant_key'=>'fixture', 'merchant_pem'=>'fixture', 'pay_handleroute'=>'/pay/paypal'],
]);
DB::table('emailtpls')->insert(['tpl_name'=>'Synthetic delivery', 'tpl_token'=>'card_send_user_email', 'tpl_content'=>'{ord_info}']);
