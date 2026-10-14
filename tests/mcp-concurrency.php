<?php
// Runs real MCP mutation orchestration against a disposable MariaDB, including lock waits.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): void { fwrite(STDERR, 'FAIL: '.$e->getMessage()."\n"); exit(1); });
use Illuminate\Support\Facades\DB;
use App\Mcp\MutationRunner;
use App\Mcp\OperationException;
if (getenv('MCP_SYNTHETIC') !== '1' || getenv('APP_ENV') !== 'testing' || DB::connection()->getDatabaseName() !== 'dujiaoka_mcp_test') { fwrite(STDERR, "Refusing non-test DB\n"); exit(2); }
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function send($s, $data) { fwrite($s, json_encode($data, JSON_THROW_ON_ERROR)."\n"); }
function receive($s) { $line = fgets($s); check($line !== false, 'worker timeout'); return json_decode($line, true, 512, JSON_THROW_ON_ERROR); }
function actor(int $token): void {
    request()->attributes->set('shop_mcp_token_id', $token);
    $row = DB::table('shop_mcp_tokens')->find($token);
    auth('admin')->setUser(App\Models\AdminUser::findOrFail($row->admin_id));
}
function operation(string $name) { foreach (App\Mcp\ShopServer::operations() as $op) if ($op->name === $name) return $op; throw new RuntimeException('missing operation'); }
function worker($op, array $input, int $token, bool $pause): array {
    DB::disconnect();
    $s = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork(); check($pid >= 0, 'fork');
    if ($pid) { fclose($s[1]); stream_set_timeout($s[0], 20); return [$pid, $s[0]]; }
    fclose($s[0]); $s = $s[1]; stream_set_timeout($s, 20); pcntl_alarm(25);
    try {
        actor($token); DB::statement('SET SESSION innodb_lock_wait_timeout=10');
        $stopped = false;
        DB::listen(function ($q) use ($s, $pause, &$stopped) {
            if ($pause && !$stopped && preg_match('/^insert into `shop_mcp_receipts`/', $q->sql)) { $stopped = true; send($s, ['stage'=>'reserved']); receive($s); }
        });
        send($s, ['stage'=>'ready', 'connection'=>(int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id]); receive($s);
        $result = app(MutationRunner::class)->run($op, $input);
        send($s, ['stage'=>'result', 'status'=>$result['status'], 'replayed'=>$result['replayed']]);
    } catch (OperationException $e) { send($s, ['stage'=>'result', 'error'=>$e->reasonCode]); }
    catch (Throwable $e) { send($s, ['stage'=>'result', 'error'=>get_class($e)]); }
    exit(0);
}
function race($op, array $a, array $b, int $secondToken = 1): array {
    [$pid1,$s1] = worker($op,$a,1,true); [$pid2,$s2] = worker($op,$b,$secondToken,false);
    try {
        receive($s1); receive($s2); send($s1, ['go'=>true]); check(receive($s1)['stage']==='reserved','first reservation'); send($s2,['go'=>true]);
        // The first worker holds its reservation until the other request is actually blocked.
        $deadline = microtime(true)+5; $waiting = false;
        do {
            $rows = DB::select('SHOW PROCESSLIST');
            foreach ($rows as $row) if ($row->Info && str_contains($row->Info, 'for update')) $waiting = true;
            if ($waiting) break;
            usleep(10000);
        } while(microtime(true)<$deadline);
        check($waiting, 'second writer never reached a lock wait'); send($s1,['commit'=>true]);
        return [receive($s1),receive($s2)];
    } finally { fclose($s1); fclose($s2); pcntl_waitpid($pid1,$s); pcntl_waitpid($pid2,$s); DB::purge(); }
}
function preview($op,array $p,int $token=1): array { actor($token); $r=app(MutationRunner::class)->run($op,$p); return [...$p,'dryRun'=>false,'previewToken'=>$r['previewToken']]; }
$cases=0;
$op=operation('catalog_categories_create');
$p=['gp_name'=>'Concurrent MCP','reason'=>'Synthetic'];
$a=preview($op,$p)+['idempotencyKey'=>'synthetic-parallel-same'];
[$one,$two]=race($op,$a,$a); check($one['status']==='succeeded' && $two['replayed']===true,'same-key duplicate'); $cases++;
check(DB::table('goods_group')->where('gp_name','Concurrent MCP')->count()===1,'duplicate business row');
$a=preview($op,['gp_name'=>'Collision first','reason'=>'Synthetic'])+['idempotencyKey'=>'synthetic-parallel-conflict'];
$b=preview($op,['gp_name'=>'Collision second','reason'=>'Synthetic'])+['idempotencyKey'=>'synthetic-parallel-conflict'];
[$one,$two]=race($op,$a,$b); check(($two['error']??'')==='idempotency_conflict','collision not rejected'); $cases++;
$admin=App\Models\AdminUser::create(['username'=>'second-mcp','name'=>'Synthetic second','password'=>'synthetic-password']);
DB::table('admin_role_users')->insert(['user_id'=>$admin->id,'role_id'=>1]);
$token=app(App\Mcp\TokenManager::class)->import('second-mcp','synthetic-password',str_repeat('cd',32),App\Mcp\Access::SCOPES,'Synthetic race',1);
app(App\Support\ShopSettings::class)->setMany(['title'=>'Old']);
$op=operation('settings_update');
$a=preview($op,['values'=>['title'=>'First'],'reason'=>'Synthetic'])+['idempotencyKey'=>'synthetic-settings-race-a'];
$b=preview($op,['values'=>['title'=>'Second'],'reason'=>'Synthetic'],$token)+['idempotencyKey'=>'synthetic-settings-race-b'];
[$one,$two]=race($op,$a,$b,$token); check(($two['error']??'')==='stale_preview','cross-actor stale settings preview applied'); $cases++;
check(app(App\Support\ShopSettings::class)->get('title')==='First','stale settings overwrite');
print("$cases MCP MariaDB concurrency cases passed\n");
