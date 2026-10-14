<?php
// Synthetic MariaDB proof that review-bound notification dependencies stay locked.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Mcp\ShopServer;
if (getenv('MCP_SYNTHETIC') !== '1' || getenv('APP_ENV') !== 'testing' || DB::connection()->getDatabaseName() !== 'dujiaoka_mcp_test') exit(2);
set_exception_handler(function (Throwable $e): void { fwrite(STDERR, 'FAIL notification lock test: '.get_class($e)."\n"); exit(1); });
$id = DB::table('orders')->insertGetId(['order_sn'=>'MCP-LOCK-FIXTURE','goods_id'=>7,'pay_id'=>14,'title'=>'Synthetic','email'=>'fixture@example.test','buy_ip'=>'127.0.0.1','type'=>1,'status'=>4,'trade_no'=>'SYNTHETIC-LOCK','actual_price'=>'10.00','info'=>'Synthetic history']);
app(App\Support\ShopSettings::class)->setMany(['text_logo'=>'Reviewed']);
$ops = [];
foreach (ShopServer::operations() as $op) $ops[$op->name] = $op;
$cases = 0;
foreach (['orders_resend_delivery'=>['id'=>$id], 'payments_recover_expired'=>['orderId'=>$id,'tradeNo'=>'SYNTHETIC-LOCK']] as $name=>$payload) {
    foreach ([['emailtpls','tpl_token','card_send_user_email','tpl_content','Changed'], ['goods','id',7,'gd_name','Changed'], ['admin_settings','slug','dujiaoka_config','value',serialize(['text_logo'=>'Changed'])]] as [$table,$key,$value,$field,$replacement]) {
        DB::disconnect();
        $s = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if ($pid < 0) throw new RuntimeException('fork');
        if ($pid === 0) {
            fclose($s[0]); stream_set_timeout($s[1], 10); pcntl_alarm(15);
            DB::statement('SET SESSION innodb_lock_wait_timeout=1');
            fwrite($s[1], "ready\n"); fgets($s[1]);
            try {
                DB::table($table)->where($key,$value)->update([$field=>$replacement]);
                fwrite($s[1], "unlocked\n");
            } catch (Illuminate\Database\QueryException $e) {
                fwrite($s[1], (($e->errorInfo[1] ?? null) === 1205 ? 'locked' : 'unexpected')."\n");
            }
            fclose($s[1]); exit(0);
        }
        fclose($s[1]); stream_set_timeout($s[0], 10);
        try {
            if (trim(fgets($s[0]) ?: '') !== 'ready') throw new RuntimeException('worker');
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            DB::beginTransaction();
            ($ops[$name]->snapshot)($payload,true);
            fwrite($s[0], "go\n");
            if (trim(fgets($s[0]) ?: '') !== 'locked') throw new RuntimeException('unreviewed dependency could change: '.$name.'/'.$table);
            $cases++;
        } finally {
            DB::rollBack(); fclose($s[0]); pcntl_waitpid($pid,$status); DB::purge();
        }
    }
}
DB::table('orders')->where('id',$id)->delete();
print("$cases notification dependency MariaDB lock cases passed\n");
