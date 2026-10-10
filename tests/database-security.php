<?php
// Synthetic fixtures only; refuses every database except the isolated test service.
require '/dujiaoka/vendor/autoload.php';
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Carmis;
use App\Service\CarmisService;
use App\Http\Controllers\Pay\YipayController;
use Illuminate\Support\Facades\Http;
if (getenv('DB_HOST') !== 'dujiaoka-security-db' || getenv('DB_DATABASE') !== 'dujiaoka_security_test') {
    fwrite(STDERR, "Refusing non-test database\n"); exit(2);
}
$app = new Container(); Container::setInstance($app);
$db = new Capsule($app);
$db->addConnection(['driver'=>'mysql','host'=>getenv('DB_HOST'),'database'=>getenv('DB_DATABASE'),'username'=>'test','password'=>'test-only-password','charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'']);
$db->setAsGlobal(); $db->bootEloquent();
$app->instance('db', $db->getDatabaseManager());
Facade::setFacadeApplication($app);
$app['config']->set('app.url','https://shop.example.test');
$app->instance('cache', new class { public function get($k,$default=null) {return $k==='system-setting' ? [] : $default;} });
$app->instance(App\Support\ShopSettings::class, new class { public function get($key, $default=null) { return $default; } public function getAll(): array { return []; } });
$app->instance('translator', new class { public function get($k,array $r=[],$locale=null,$fallback=true){return $k;} });
$app->instance('log', new Psr\Log\NullLogger());
Http::swap(new Illuminate\Http\Client\Factory());
Http::preventStrayRequests();
Http::fake(['api.dulupay.com/api.php*' => function ($request) {
    check(Capsule::connection()->transactionLevel() === 1, 'provider verification lost order lock');
    usleep(150000);
    return Http::response(['code'=>1,'status'=>1,'pid'=>'test-merchant','out_trade_no'=>$request['out_trade_no'],'trade_no'=>'TESTTRADE'.substr($request['out_trade_no'],9),'type'=>'alipay','money'=>'10.00']);
}]);
// External append-only log deliberately does not participate in DB rollback.
set_error_handler(function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$queueLog = '/dujiaoka/testing-queue-' . getmypid() . '.jsonl';
file_put_contents($queueLog, '');
$app->instance(Illuminate\Contracts\Bus\Dispatcher::class,new class($queueLog) {
    private $path;
    public function __construct($path) {$this->path=$path;}
    public function dispatch($job) {
        file_put_contents($this->path,json_encode(['kind'=>get_class($job),'before_commit'=>Capsule::connection()->transactionLevel()>0])."\n",FILE_APPEND|LOCK_EX);
        return $job;
    }
});
foreach (['GoodsService','CouponService','PayService','OrderService','OrderProcessService'] as $s) {
    $app->bind('Service\\'.$s,'App\\Service\\'.$s);
}
$app->instance('Service\\EmailtplService',new class {
    public function detailByToken(string $token):array { return ['tpl_name'=>'Test','tpl_content'=>'{ord_info}']; }
});
$app->instance('Service\\CarmisService',new class extends CarmisService {
    public function withGoodsByAmountAndStatusUnsold(int $id,int $n) {
        $rows=parent::withGoodsByAmountAndStatusUnsold($id,$n);
        usleep(300000); // Keep competing transactions overlapping at stock allocation.
        return $rows;
    }
});
$sql=[
'CREATE TABLE IF NOT EXISTS goods (id int primary key, gd_name varchar(100), actual_price decimal(10,2), type int, in_stock int, sales_volume int default 0, created_at datetime null, updated_at datetime null, deleted_at datetime null) ENGINE=InnoDB',
'CREATE TABLE IF NOT EXISTS pays (id int primary key, merchant_id varchar(100), merchant_key varchar(100), merchant_pem varchar(100), pay_check varchar(30), pay_handleroute varchar(100), is_open int, deleted_at datetime null) ENGINE=InnoDB',
'CREATE TABLE IF NOT EXISTS coupons (id int primary key, deleted_at datetime null) ENGINE=InnoDB',
'CREATE TABLE IF NOT EXISTS orders (id int auto_increment primary key, order_sn varchar(150) unique, goods_id int, coupon_id int null, pay_id int, status int, actual_price decimal(10,2), trade_no varchar(200), type int, buy_amount int, info text, title varchar(100), email varchar(100), created_at datetime null, updated_at datetime null, deleted_at datetime null) ENGINE=InnoDB',
'CREATE TABLE IF NOT EXISTS carmis (id int primary key, goods_id int, status int, is_loop int default 0, carmi varchar(100), created_at datetime null, updated_at datetime null, deleted_at datetime null, key stock(goods_id,status,id)) ENGINE=InnoDB',
'CREATE TABLE IF NOT EXISTS test_events (id int auto_increment primary key, kind varchar(100)) ENGINE=InnoDB'
];
foreach($sql as $q) Capsule::statement($q);
function check($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
function seed($orders=1,$cards=3,$manual=false){
    global $queueLog; file_put_contents($queueLog, '');
    foreach(['test_events','orders','carmis','pays','goods'] as $t)Capsule::table($t)->delete();
    Capsule::table('goods')->insert(['id'=>7,'gd_name'=>'Synthetic product','actual_price'=>'10.00','type'=>$manual?2:1,'in_stock'=>$cards,'sales_volume'=>0]);
    Capsule::table('pays')->insert(['id'=>14,'merchant_id'=>'test-merchant','merchant_key'=>'https://api.dulupay.com/submit.php','merchant_pem'=>'test-signing-key','pay_check'=>'alipay','pay_handleroute'=>'/pay/yipay','is_open'=>1]);
    for($i=1;$i<=$orders;$i++) Capsule::table('orders')->insert(['order_sn'=>'TESTORDER'.$i,'goods_id'=>7,'coupon_id'=>null,'pay_id'=>14,'status'=>1,'actual_price'=>'10.00','trade_no'=>'','type'=>$manual?2:1,'buy_amount'=>1,'info'=>'','title'=>'Synthetic product','email'=>'buyer@example.test']);
    for($i=1;$i<=$cards;$i++)Capsule::table('carmis')->insert(['id'=>$i,'goods_id'=>7,'status'=>1,'is_loop'=>0,'carmi'=>'SYNTHETIC-CARD-'.$i]);
}
function payload($n=1,$status='TRADE_SUCCESS'){
    $a=['pid'=>'test-merchant','trade_no'=>'TESTTRADE'.$n,'out_trade_no'=>'TESTORDER'.$n,'type'=>'alipay','name'=>'Synthetic product','money'=>'10.00','trade_status'=>$status,'sign_type'=>'MD5'];
    ksort($a);$parts=[];foreach($a as $k=>$v)if($k!=='sign_type')$parts[]="$k=$v";
    $a['sign']=md5(implode('&',$parts).'test-signing-key');return $a;
}
function notify($p){return (new YipayController())->notifyUrl(new Request($p));}
function simultaneous($orderNumbers){
    Capsule::connection()->disconnect();
    $children=[];
    foreach($orderNumbers as $n){
        $pid=pcntl_fork();check($pid>=0,'fork failed');
        if($pid===0){
            try { Capsule::connection()->reconnect(); check(notify(payload($n))==='success','worker callback rejected'); exit(0); }
            catch(Throwable $e){fwrite(STDERR,'Worker failed: '.$e->getMessage()."\n");exit(1);}
        }
        $children[]=$pid;
    }
    foreach($children as $pid){pcntl_waitpid($pid,$status);check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'concurrent callback failed');}
    Capsule::connection()->reconnect();
}
function sold(){return Capsule::table('carmis')->where('status',Carmis::STATUS_SOLD)->count();}
function queueEvents(){global $queueLog;return array_map(function($line){return json_decode($line,true);},file($queueLog,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));}
function mailEvents(){return count(array_filter(queueEvents(),function($e){return $e['kind']===App\Jobs\MailSend::class;}));}
$tests=[];
$tests['invalid callbacks leave real order and stock untouched']=function(){
    seed();check(notify(payload(1,'WAIT_BUYER_PAY'))==='fail','unpaid accepted');
    $p=payload();$p['sign']=true;check(notify($p)==='fail','boolean accepted');
    check(sold()===0 && mailEvents()===0,'invalid callback delivered');
    check((int)Capsule::table('orders')->value('status')===1,'invalid callback changed status');
};
$tests['valid callback and retry deliver exactly once']=function(){
    seed();check(notify(payload())==='success','valid rejected');check(notify(payload())==='success','retry rejected');
    check(sold()===1 && mailEvents()===1,'duplicate delivery');
    check((int)Capsule::table('goods')->value('sales_volume')===1,'duplicate sale');
};
$tests['parallel callbacks for same order deliver exactly once']=function(){
    seed();simultaneous([1,1]);check(sold()===1 && mailEvents()===1,'parallel duplicate delivery');
    check((int)Capsule::table('goods')->value('sales_volume')===1,'parallel duplicate sale');
};
$tests['parallel orders receive distinct cards']=function(){
    seed(2,3);simultaneous([1,2]);
    check(sold()===2 && mailEvents()===2,'incorrect stock count');
    check(Capsule::table('orders')->distinct()->count('info')===2,'same card delivered twice');
};
$tests['parallel orders competing for last card never share it']=function(){
    seed(2,1);simultaneous([1,2]);
    check(sold()===1 && mailEvents()===1,'last card delivered twice');
    check(Capsule::table('orders')->where('status',Order::STATUS_COMPLETED)->count()===1,'wrong completed count');
    check(Capsule::table('orders')->where('status',Order::STATUS_ABNORMAL)->count()===1,'missing stock-shortage state');
};
$tests['manual order retry does not decrement stock twice']=function(){
    seed(1,3,true);simultaneous([1,1]);
    check((int)Capsule::table('goods')->value('in_stock')===2,'manual double decrement');
    check((int)Capsule::table('goods')->value('sales_volume')===1 && mailEvents()===1,'manual duplicate effects');
};
$tests['expiration cannot overwrite paid order']=function(){
    seed();check(notify(payload())==='success','valid rejected');
    check(!app('Service\\OrderService')->expiredOrderSN('TESTORDER1'),'paid order expired');
    check((int)Capsule::table('orders')->value('status')===Order::STATUS_COMPLETED,'paid status lost');
};
$tests['database failure after allocation never leaks a delivery to external queue']=function(){
    seed();
    $real=app('Service\\GoodsService');
    app()->instance('Service\\GoodsService',new class {
        public function salesVolumeIncr($id,$count){throw new RuntimeException('synthetic DB failure after card allocation');}
    });
    try {
        check(notify(payload())==='fail','failed transaction acknowledged');
        check(sold()===0,'card sale not rolled back');
        check((int)Capsule::table('orders')->value('status')===Order::STATUS_WAIT_PAY,'order not rolled back');
        check(count(queueEvents())===0,'rolled-back transaction leaked queued delivery');
    } finally { app()->instance('Service\\GoodsService',$real); }
};
$tests['all fulfilment notifications are submitted after commit']=function(){
    foreach([false,true] as $manual){
        seed(1,3,$manual); check(notify(payload())==='success','valid callback rejected');
        foreach(queueEvents() as $event)check(!$event['before_commit'],'notification submitted inside transaction');
    }
};
function parallelWork(array $jobs): void {
    Capsule::connection()->disconnect(); $children=[];
    foreach ($jobs as $job) {
        $pid=pcntl_fork(); check($pid>=0,'fork failed');
        if ($pid===0) {
            try { Capsule::connection()->reconnect(); $job(); exit(0); }
            catch (Throwable $e) { fwrite(STDERR,'Synthetic race failed: '.$e->getMessage()."\n"); exit(1); }
        }
        $children[]=$pid;
    }
    foreach ($children as $pid) {pcntl_waitpid($pid,$status); check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'race failed');}
    Capsule::connection()->reconnect();
}
function recover($n=1) {return app('Service\\OrderProcessService')->reconcileDuluPayOrder('TESTORDER'.$n,'TESTTRADE'.$n,'synthetic-test');}
$tests['parallel provider-verified recoveries fulfil once after commit']=function(){
    seed(); Capsule::table('orders')->update(['status'=>-1]);
    parallelWork([fn()=>recover(),fn()=>recover()]);
    check(sold()===1 && mailEvents()===1,'recovery duplicate effects');
    check((int)Capsule::table('goods')->value('sales_volume')===1,'recovery duplicate sales');
    foreach(queueEvents() as $event)check(!$event['before_commit'],'recovery notification before commit');
};
$tests['callback versus recovery never duplicates delivery']=function(){
    seed(); Capsule::table('orders')->update(['status'=>-1]);
    parallelWork([fn()=>recover(),function(){check(in_array(notify(payload()),['fail','success'],true),'bad acknowledgment');}]);
    check(sold()===1 && mailEvents()===1,'callback recovery duplicate effects');
    check(notify(payload())==='success','recovered callback retry not acknowledged');
};
$tests['expiration versus recovery preserves paid state']=function(){
    seed();
    parallelWork([function(){app('Service\\OrderService')->expiredOrderSN('TESTORDER1');},function(){try {recover();} catch(App\Exceptions\RuleValidationException $e){check((int)Capsule::table('orders')->value('status')!==4,'failed recovery falsely completed');}}]);
    recover(); check(sold()===1 && mailEvents()===1,'expiration recovery effects');
    check(!app('Service\\OrderService')->expiredOrderSN('TESTORDER1'),'recovered order expired');
};
$tests['same provider trade cannot settle two parallel orders']=function(){
    seed(2,3);
    // Separate products and payment-channel rows must not weaken trade ownership.
    Capsule::table('goods')->insert(['id'=>8,'gd_name'=>'Other synthetic','actual_price'=>'10.00','type'=>1,'in_stock'=>1,'sales_volume'=>0]);
    Capsule::table('pays')->insert(['id'=>15,'merchant_id'=>'test-merchant','merchant_key'=>'https://api.dulupay.com/submit.php','merchant_pem'=>'test-signing-key','pay_check'=>'alipay','pay_handleroute'=>'/pay/yipay','is_open'=>1]);
    Capsule::table('orders')->where('order_sn','TESTORDER2')->update(['goods_id'=>8,'pay_id'=>15]);
    Capsule::table('carmis')->where('id',3)->update(['goods_id'=>8]);
    $work=function($n){try {app('Service\\OrderProcessService')->completedOrder('TESTORDER'.$n,10.0,'SHARED-TRADE');}catch(App\Exceptions\RuleValidationException $e){check(str_contains($e->getMessage(),'payment_trade_already_bound'),'unexpected rejection');}};
    parallelWork([fn()=>$work(1),fn()=>$work(2)]);
    check(sold()===1 && mailEvents()===1,'cross-order duplicate trade delivered');
    check(Capsule::table('orders')->where('trade_no','SHARED-TRADE')->count()===1,'duplicate trade binding');
};
$tests['failed recovery rolls back allocation and releases trade lock']=function(){
    seed(); Capsule::table('orders')->update(['status'=>-1]);
    $real=app('Service\\GoodsService');
    app()->instance('Service\\GoodsService',new class {public function salesVolumeIncr($id,$n){throw new RuntimeException('synthetic failure');}});
    try {recover(); throw new RuntimeException('recovery should fail');}catch(App\Exceptions\RuleValidationException $e){}finally{app()->instance('Service\\GoodsService',$real);}
    check(sold()===0 && mailEvents()===0,'failed recovery leaked delivery');
    check((int)Capsule::table('orders')->value('status')===-1,'failed recovery changed status');
    parallelWork([fn()=>recover()]);check(sold()===1 && mailEvents()===1,'lock not released');
};
$tests['stock shortage recovery records paid abnormal state idempotently']=function(){
    seed(1,0); Capsule::table('orders')->update(['status'=>-1]);
    recover();recover();check(sold()===0 && mailEvents()===0,'stock shortage emailed delivery');
    check((int)Capsule::table('orders')->value('status')===6,'shortage not abnormal');
    check(Capsule::table('orders')->value('trade_no')==='TESTTRADE1','payment evidence lost');
};
$fail=0;foreach($tests as $name=>$run){try{$run();echo "PASS: $name\n";}catch(Throwable $e){$fail++;echo "FAIL: $name: ".$e->getMessage()."\n";}}
echo count($tests)." MariaDB integration tests, $fail failures\n";exit($fail?1:0);
