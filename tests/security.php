<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require '/dujiaoka/vendor/autoload.php';

use App\Exceptions\RuleValidationException;
use App\Events\OrderUpdated as OrderUpdatedEvent;
use App\Http\Controllers\Pay\YipayController;
use App\Jobs\MailSend;
use App\Jobs\OrderExpired;
use App\Models\Carmis;
use App\Models\Goods;
use App\Models\Order;
use App\Service\CarmisService;
use App\Service\OrderProcessService;
use App\Service\OrderService;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcherContract;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Processors\MySqlProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

final class SecurityTestFailure extends RuntimeException
{
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new SecurityTestFailure(
            $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'
        );
    }
}

function assertThrowsRuleValidation(callable $callback, string $message): RuleValidationException
{
    try {
        $callback();
    } catch (RuleValidationException $exception) {
        return $exception;
    }
    throw new SecurityTestFailure($message);
}

final class FakeOrderServiceForYipay
{
    /** @var object|null */
    public $order;

    public function __construct($order)
    {
        $this->order = $order;
    }

    public function detailOrderSN(string $orderSN)
    {
        return $this->order && $this->order->order_sn === $orderSN ? $this->order : null;
    }
}

final class FakePayServiceForYipay
{
    /** @var object|null */
    public $gateway;

    public function __construct($gateway)
    {
        $this->gateway = $gateway;
    }

    public function detail(int $id)
    {
        return $this->gateway && $this->gateway->id === $id ? $this->gateway : null;
    }
}

final class FakeOrderProcessForYipay
{
    /** @var array<int,array<int,mixed>> */
    public $calls = [];

    /** @var Throwable|null */
    public $exception;

    public function completedOrder(string $orderSN, float $amount, string $tradeNo = '')
    {
        $this->calls[] = [$orderSN, $amount, $tradeNo];
        if ($this->exception) {
            throw $this->exception;
        }
        return true;
    }
}

final class TestYipayController extends YipayController
{
    public function __construct($orderService, $payService, $orderProcessService)
    {
        $this->orderService = $orderService;
        $this->payService = $payService;
        $this->orderProcessService = $orderProcessService;
    }
}

function yipaySignature(array $data, string $secret): string
{
    ksort($data);
    $parts = [];
    foreach ($data as $key => $value) {
        if ($key === 'sign' || $key === 'sign_type' || $value === '') {
            continue;
        }
        $parts[] = $key . '=' . $value;
    }
    return md5(implode('&', $parts) . $secret);
}

function validYipayCallback(string $secret): array
{
    $data = [
        'pid' => 'merchant-14',
        'trade_no' => 'TRADE-20260925-1',
        'out_trade_no' => 'ORDER202609250001',
        'type' => 'alipay',
        'name' => 'ORDER202609250001',
        'money' => '10.00',
        'trade_status' => 'TRADE_SUCCESS',
        'sign_type' => 'MD5',
    ];
    $data['sign'] = yipaySignature($data, $secret);
    return $data;
}

function makeYipaySubject(): array
{
    $secret = 'test-signing-secret';
    $order = (object) [
        'order_sn' => 'ORDER202609250001',
        'pay_id' => 14,
    ];
    $gateway = (object) [
        'id' => 14,
        'merchant_id' => 'merchant-14',
        'merchant_pem' => $secret,
        'pay_handleroute' => '/pay/yipay',
        'pay_check' => 'alipay',
        'is_open' => 1,
    ];
    $process = new FakeOrderProcessForYipay();
    $controller = new TestYipayController(
        new FakeOrderServiceForYipay($order),
        new FakePayServiceForYipay($gateway),
        $process
    );
    return [$controller, $process, $secret, $gateway];
}

final class FakeTransactionManager
{
    public $begins = 0;
    public $commits = 0;
    public $rollbacks = 0;
    public $active = false;
    public $level = 0;
    /** @var Throwable|null */
    public $commitException;

    public function beginTransaction(): void
    {
        ++$this->begins;
        ++$this->level;
        $this->active = true;
    }

    public function commit(): void
    {
        ++$this->commits;
        if ($this->commitException) {
            throw $this->commitException;
        }
        $this->level = max(0, $this->level - 1);
        $this->active = $this->level > 0;
    }

    public function rollBack(): void
    {
        ++$this->rollbacks;
        $this->level = max(0, $this->level - 1);
        $this->active = $this->level > 0;
    }

    public function transactionLevel(): int
    {
        return $this->level;
    }
}

final class FakeKeyValueStore
{
    public function get($key, $default = null)
    {
        return $key === 'system-setting' ? [] : $default;
    }
}

final class FakeTranslator
{
    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        return $key;
    }
}

final class FakeBusDispatcher
{
    public $jobs = [];
    public $dispatches = 0;
    public $throwOnDispatchNumber;
    public $activeAtDispatch = [];
    /** @var FakeTransactionManager|null */
    private $transactions;

    public function __construct(?FakeTransactionManager $transactions = null)
    {
        $this->transactions = $transactions;
    }

    public function dispatch($job)
    {
        ++$this->dispatches;
        $this->activeAtDispatch[] = $this->transactions ? $this->transactions->active : false;
        if ($this->throwOnDispatchNumber === $this->dispatches) {
            throw new RuntimeException('synthetic post-commit dispatch failure');
        }
        $this->jobs[] = $job;
        return $job;
    }
}

final class FakeEventDispatcher implements EventDispatcherContract
{
    public $events = [];
    public $activeAtDispatch = [];
    public $mailOnOrderUpdated = false;
    /** @var FakeTransactionManager */
    private $transactions;

    public function __construct(FakeTransactionManager $transactions)
    {
        $this->transactions = $transactions;
    }

    public function listen($events, $listener)
    {
    }

    public function hasListeners($eventName)
    {
        return false;
    }

    public function subscribe($subscriber)
    {
    }

    public function until($event, $payload = [])
    {
        return $this->dispatch($event, $payload, true);
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        if (is_object($event)) {
            $this->events[] = $event;
            $this->activeAtDispatch[] = $this->transactions->active;
            if ($this->mailOnOrderUpdated && $event instanceof OrderUpdatedEvent) {
                MailSend::dispatch($event->order->email, 'Status update', 'Order status changed');
            }
        }
        return [];
    }

    public function push($event, $payload = [])
    {
    }

    public function flush($event)
    {
    }

    public function forget($event)
    {
    }

    public function forgetPushed()
    {
    }
}

final class FakeLockedOrderService
{
    /** @var TestOrderRecord */
    public $order;
    public $lockedLookups = 0;
    public $unlockedLookups = 0;
    /** @var FakeTransactionManager */
    private $transactions;

    public function __construct(TestOrderRecord $order, FakeTransactionManager $transactions)
    {
        $this->order = $order;
        $this->transactions = $transactions;
    }

    public function detailOrderSN(string $orderSN)
    {
        ++$this->unlockedLookups;
        return $orderSN === $this->order->order_sn ? $this->order : null;
    }

    public function detailOrderSNForUpdate(string $orderSN)
    {
        if (!$this->transactions->active) {
            throw new SecurityTestFailure('order lock was requested outside a transaction');
        }
        ++$this->lockedLookups;
        return $orderSN === $this->order->order_sn ? $this->order : null;
    }
}

final class FakeCarmisServiceForOrder
{
    public $selects = 0;
    public $soldCalls = [];
    /** @var Throwable|null */
    public $exception;

    public function withGoodsByAmountAndStatusUnsold(int $goodsID, int $buyAmount)
    {
        ++$this->selects;
        if ($this->exception) {
            throw $this->exception;
        }
        return [['id' => 501, 'carmi' => 'CARD-SECRET-501']];
    }

    public function soldByIDS(array $ids): bool
    {
        $this->soldCalls[] = $ids;
        return true;
    }
}

final class FakeGoodsServiceForOrder
{
    public $salesCalls = [];
    public $stockCalls = [];
    /** @var Throwable|null */
    public $salesException;

    public function salesVolumeIncr(int $goodsID, int $buyAmount): bool
    {
        if ($this->salesException) {
            throw $this->salesException;
        }
        $this->salesCalls[] = [$goodsID, $buyAmount];
        return true;
    }

    public function inStockDecr(int $goodsID, int $buyAmount): bool
    {
        $this->stockCalls[] = [$goodsID, $buyAmount];
        return true;
    }
}

final class FakeEmailTemplateService
{
    public function detailByToken(string $token): array
    {
        return ['tpl_name' => 'Cards', 'tpl_content' => '{ord_info}'];
    }
}

final class FakeCouponServiceForOrder
{
}

final class FakeExpirationOrderService
{
    /** @var Order */
    public $order;
    public $expirationResult;
    public $expirationCalls = 0;

    public function __construct(Order $order, bool $expirationResult)
    {
        $this->order = $order;
        $this->expirationResult = $expirationResult;
    }

    public function detailOrderSN(string $orderSN)
    {
        return $orderSN === $this->order->order_sn ? $this->order : null;
    }

    public function expiredOrderSN(string $orderSN): bool
    {
        ++$this->expirationCalls;
        return $this->expirationResult;
    }
}

final class RecordingMySqlConnection extends Connection
{
    public $selectQueries = [];
    public $updateQueries = [];
    public $updateBindings = [];

    public function __construct()
    {
        parent::__construct(null, '', '', []);
        $this->setQueryGrammar(new MySqlGrammar());
        $this->setPostProcessor(new MySqlProcessor());
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        $this->selectQueries[] = $query;
        if (stripos($query, 'from `orders`') !== false) {
            return [(object) [
                'id' => 1,
                'order_sn' => 'ORDER202609250001',
                'goods_id' => 7,
                'coupon_id' => 0,
                'pay_id' => 14,
                'status' => Order::STATUS_WAIT_PAY,
                'deleted_at' => null,
            ]];
        }
        if (stripos($query, 'from `carmis`') === false) {
            return [];
        }
        return [(object) [
            'id' => 501,
            'goods_id' => 7,
            'status' => Carmis::STATUS_UNSOLD,
            'is_loop' => 0,
            'carmi' => 'CARD-SECRET-501',
            'created_at' => null,
            'updated_at' => null,
            'deleted_at' => null,
        ]];
    }

    public function update($query, $bindings = [])
    {
        $this->updateQueries[] = $query;
        $this->updateBindings[] = $bindings;
        return 1;
    }
}

final class SingleConnectionResolver implements ConnectionResolverInterface
{
    /** @var Connection */
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function connection($name = null)
    {
        return $this->connection;
    }

    public function getDefaultConnection()
    {
        return 'testing';
    }

    public function setDefaultConnection($name)
    {
    }
}

final class TestOrderRecord extends Order
{
    public $saveCalls = 0;
    /** @var Throwable|null */
    public $saveException;

    public function save(array $options = [])
    {
        ++$this->saveCalls;
        if ($this->saveException) {
            throw $this->saveException;
        }
        $this->fireModelEvent('updated', false);
        return true;
    }
}

function setPrivateProperty(object $subject, string $property, $value): void
{
    $reflection = new ReflectionProperty(OrderProcessService::class, $property);
    $reflection->setAccessible(true);
    $reflection->setValue($subject, $value);
}

function makeOrderProcessSubject(int $status, int $type): array
{
    $container = new Container();
    Container::setInstance($container);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);

    $transactions = new FakeTransactionManager();
    $cache = new FakeKeyValueStore();
    $config = new FakeKeyValueStore();
    $translator = new FakeTranslator();
    $bus = new FakeBusDispatcher($transactions);
    $events = new FakeEventDispatcher($transactions);
    $container->instance('db', $transactions);
    $container->instance('cache', $cache);
    $container->instance('config', $config);
    $container->instance('translator', $translator);
    $container->instance(Illuminate\Contracts\Bus\Dispatcher::class, $bus);
    $container->instance('events', $events);
    $container->instance(EventDispatcherContract::class, $events);
    Order::setEventDispatcher($events);

    $order = new TestOrderRecord();
    $order->order_sn = 'ORDER202609250001';
    $order->status = $status;
    $order->actual_price = '10.00';
    $order->trade_no = $status === Order::STATUS_WAIT_PAY ? '' : 'TRADE-20260925-1';
    $order->type = $type;
    $order->goods_id = 7;
    $order->buy_amount = 1;
    $order->email = 'buyer@example.test';
    $order->title = 'Security test product x 1';
    $order->info = 'Manual order details';
    $goods = new Goods();
    $goods->gd_name = 'Security test product';
    $order->setRelation('goods', $goods);

    $orderService = new FakeLockedOrderService($order, $transactions);
    $carmisService = new FakeCarmisServiceForOrder();
    $goodsService = new FakeGoodsServiceForOrder();
    $serviceReflection = new ReflectionClass(OrderProcessService::class);
    /** @var OrderProcessService $service */
    $service = $serviceReflection->newInstanceWithoutConstructor();
    setPrivateProperty($service, 'couponService', new FakeCouponServiceForOrder());
    setPrivateProperty($service, 'orderService', $orderService);
    setPrivateProperty($service, 'carmisService', $carmisService);
    setPrivateProperty($service, 'emailtplService', new FakeEmailTemplateService());
    setPrivateProperty($service, 'goodsService', $goodsService);
    $container->instance('Service\\GoodsService', $goodsService);

    return [
        'service' => $service,
        'order' => $order,
        'orders' => $orderService,
        'carmis' => $carmisService,
        'goods' => $goodsService,
        'transactions' => $transactions,
        'bus' => $bus,
        'events' => $events,
    ];
}

function makeAutomaticOrderProcessSubject(int $status = Order::STATUS_WAIT_PAY): array
{
    return makeOrderProcessSubject($status, Order::AUTOMATIC_DELIVERY);
}

function makeManualOrderProcessSubject(): array
{
    return makeOrderProcessSubject(Order::STATUS_WAIT_PAY, Order::MANUAL_PROCESSING);
}

$tests = [];
$tests['Yipay rejects a boolean signature instead of accepting PHP loose comparison'] = function (): void {
    [$controller, $process, $secret] = makeYipaySubject();
    $data = validYipayCallback($secret);
    $data['sign'] = true;

    $response = $controller->notifyUrl(Request::create('/pay/yipay/notify_url', 'POST', $data));

    assertSameValue('fail', $response, 'non-string signature must fail closed');
    assertSameValue(0, count($process->calls), 'invalid signature must not fulfil an order');
};

$tests['Yipay rejects a merchant mismatch even when the callback is correctly signed'] = function (): void {
    [$controller, $process, $secret] = makeYipaySubject();
    $data = validYipayCallback($secret);
    $data['pid'] = 'attacker-merchant';
    $data['sign'] = yipaySignature($data, $secret);

    $response = $controller->notifyUrl(Request::create('/pay/yipay/notify_url', 'POST', $data));

    assertSameValue('fail', $response, 'callback merchant must match the order gateway');
    assertSameValue(0, count($process->calls), 'merchant mismatch must not fulfil an order');
};

$tests['Yipay rejects a payment type mismatch even when the callback is correctly signed'] = function (): void {
    [$controller, $process, $secret] = makeYipaySubject();
    $data = validYipayCallback($secret);
    $data['type'] = 'wxpay';
    $data['sign'] = yipaySignature($data, $secret);

    $response = $controller->notifyUrl(Request::create('/pay/yipay/notify_url', 'POST', $data));

    assertSameValue('fail', $response, 'callback type must match the order gateway');
    assertSameValue(0, count($process->calls), 'payment type mismatch must not fulfil an order');
};

$tests['Yipay rejects an unsuccessful trade status even when the callback is correctly signed'] = function (): void {
    [$controller, $process, $secret] = makeYipaySubject();
    $data = validYipayCallback($secret);
    $data['trade_status'] = 'WAIT_BUYER_PAY';
    $data['sign'] = yipaySignature($data, $secret);

    $response = $controller->notifyUrl(Request::create('/pay/yipay/notify_url', 'POST', $data));

    assertSameValue('fail', $response, 'only a successful provider trade may fulfil an order');
    assertSameValue(0, count($process->calls), 'unsuccessful trade status must not fulfil an order');
};

$tests['Yipay rejects callbacks for a disabled gateway'] = function (): void {
    [$controller, $process, $secret, $gateway] = makeYipaySubject();
    $gateway->is_open = 0;
    $data = validYipayCallback($secret);

    $response = $controller->notifyUrl(Request::create('/pay/yipay/notify_url', 'POST', $data));

    assertSameValue('fail', $response, 'disabled gateways must fail closed');
    assertSameValue(0, count($process->calls), 'disabled gateway callback must not fulfil an order');
};

$tests['Yipay rejects a non-scalar order number before any lookup'] = function (): void {
    [$controller, $process, $secret] = makeYipaySubject();
    $data = validYipayCallback($secret);
    $data['out_trade_no'] = ['ORDER202609250001'];

    $response = $controller->notifyUrl(Request::create('/pay/yipay/notify_url', 'POST', $data));

    assertSameValue('fail', $response, 'array callback fields must fail closed');
    assertSameValue(0, count($process->calls), 'malformed callback must not fulfil an order');
};

$tests['Yipay rejects non-decimal money encodings before fulfilment'] = function (): void {
    [$controller, $process, $secret] = makeYipaySubject();
    $data = validYipayCallback($secret);
    $data['money'] = '1e1';
    $data['sign'] = yipaySignature($data, $secret);

    $response = $controller->notifyUrl(Request::create('/pay/yipay/notify_url', 'POST', $data));

    assertSameValue('fail', $response, 'money must be a plain two-decimal-compatible value');
    assertSameValue(0, count($process->calls), 'malformed money must not fulfil an order');
};

$tests['Yipay rejects unsupported signature types'] = function (): void {
    [$controller, $process, $secret] = makeYipaySubject();
    $data = validYipayCallback($secret);
    $data['sign_type'] = 'HMAC-SHA256';
    $data['sign'] = yipaySignature($data, $secret);

    $response = $controller->notifyUrl(Request::create('/pay/yipay/notify_url', 'POST', $data));

    assertSameValue('fail', $response, 'only the configured MD5 callback protocol is supported');
    assertSameValue(0, count($process->calls), 'unsupported signature type must not fulfil an order');
};

$tests['Yipay rejects a callback whose signed product name is not the order number'] = function (): void {
    [$controller, $process, $secret] = makeYipaySubject();
    $data = validYipayCallback($secret);
    $data['name'] = 'DIFFERENT-ORDER';
    $data['sign'] = yipaySignature($data, $secret);

    $response = $controller->notifyUrl(Request::create('/pay/yipay/notify_url', 'POST', $data));

    assertSameValue('fail', $response, 'signed callback name must remain bound to the order');
    assertSameValue(0, count($process->calls), 'mismatched callback name must not fulfil an order');
};

$tests['Yipay accepts one valid signed callback and passes its bound values to fulfilment'] = function (): void {
    [$controller, $process, $secret] = makeYipaySubject();
    $data = validYipayCallback($secret);

    $response = $controller->notifyUrl(Request::create('/pay/yipay/notify_url', 'POST', $data));

    assertSameValue('success', $response, 'valid signed callback must be acknowledged');
    assertSameValue(
        [['ORDER202609250001', 10.0, 'TRADE-20260925-1']],
        $process->calls,
        'valid callback must fulfil exactly the bound order, amount, and provider transaction'
    );
};

$tests['Yipay rejects money with more precision than the order currency'] = function (): void {
    [$controller, $process, $secret] = makeYipaySubject();
    $data = validYipayCallback($secret);
    $data['money'] = '10.001';
    $data['sign'] = yipaySignature($data, $secret);

    $response = $controller->notifyUrl(Request::create('/pay/yipay/notify_url', 'POST', $data));

    assertSameValue('fail', $response, 'sub-cent amounts must not compare equal after truncation');
    assertSameValue(0, count($process->calls), 'over-precise money must not fulfil an order');
};

$tests['Yipay rejects an overlong provider transaction identifier'] = function (): void {
    [$controller, $process, $secret] = makeYipaySubject();
    $data = validYipayCallback($secret);
    $data['trade_no'] = str_repeat('T', 201);
    $data['sign'] = yipaySignature($data, $secret);

    $response = $controller->notifyUrl(Request::create('/pay/yipay/notify_url', 'POST', $data));

    assertSameValue('fail', $response, 'provider transaction must fit the order record');
    assertSameValue(0, count($process->calls), 'overlong provider transaction must not fulfil an order');
};

$tests['Yipay fails closed when fulfilment rejects the order transition'] = function (): void {
    [$controller, $process, $secret] = makeYipaySubject();
    $process->exception = new RuleValidationException('invalid order state');
    $data = validYipayCallback($secret);

    $response = $controller->notifyUrl(Request::create('/pay/yipay/notify_url', 'POST', $data));

    assertSameValue('fail', $response, 'rejected fulfilment must not be acknowledged as paid');
    assertSameValue(1, count($process->calls), 'verified callback should reach fulfilment once');
};

$tests['Fulfilment acknowledges an exact duplicate callback without consuming stock twice'] = function (): void {
    $subject = makeAutomaticOrderProcessSubject();

    $first = $subject['service']->completedOrder('ORDER202609250001', 10.00, 'TRADE-20260925-1');
    $second = $subject['service']->completedOrder('ORDER202609250001', 10.00, 'TRADE-20260925-1');

    assertSameValue($subject['order'], $first, 'first callback must return the fulfilled order');
    assertSameValue($subject['order'], $second, 'exact duplicate must acknowledge the existing fulfilment');
    assertSameValue(1, $subject['carmis']->selects, 'stock must be selected once');
    assertSameValue([[501]], $subject['carmis']->soldCalls, 'card must be sold once');
    assertSameValue([[7, 1]], $subject['goods']->salesCalls, 'sales volume must increment once');
};

$tests['Fulfilment treats a matching callback for a pending manual-style state as an idempotent retry'] = function (): void {
    $subject = makeAutomaticOrderProcessSubject(Order::STATUS_PENDING);

    $result = $subject['service']->completedOrder('ORDER202609250001', 10.00, 'TRADE-20260925-1');

    assertSameValue($subject['order'], $result, 'matching paid-state retry must be acknowledged');
    assertSameValue(0, $subject['carmis']->selects, 'paid-state retry must not select stock again');
    assertSameValue([], $subject['goods']->salesCalls, 'paid-state retry must not increment sales again');
};

$tests['Fulfilment obtains the order through a transaction-scoped row lock'] = function (): void {
    $subject = makeAutomaticOrderProcessSubject();

    $subject['service']->completedOrder('ORDER202609250001', 10.00, 'TRADE-20260925-1');

    assertSameValue(1, $subject['orders']->lockedLookups, 'order must be read with a row lock');
    assertSameValue(0, $subject['orders']->unlockedLookups, 'unlocked order lookup must not drive fulfilment');
};

$tests['Fulfilment rejects a late callback after the order has expired'] = function (): void {
    $subject = makeAutomaticOrderProcessSubject(Order::STATUS_EXPIRED);

    assertThrowsRuleValidation(function () use ($subject): void {
        $subject['service']->completedOrder('ORDER202609250001', 10.00, 'TRADE-LATE-1');
    }, 'expired order must reject a late payment transition');

    assertSameValue(0, $subject['carmis']->selects, 'expired callback must not select stock');
    assertSameValue([], $subject['goods']->salesCalls, 'expired callback must not increment sales');
    assertSameValue(1, $subject['transactions']->rollbacks, 'expired callback must roll back its transaction');
};

$tests['Fulfilment rejects a different transaction on an already-paid order'] = function (): void {
    $subject = makeAutomaticOrderProcessSubject(Order::STATUS_COMPLETED);

    assertThrowsRuleValidation(function () use ($subject): void {
        $subject['service']->completedOrder('ORDER202609250001', 10.00, 'DIFFERENT-TRADE');
    }, 'different provider transaction must not be treated as an idempotent retry');

    assertSameValue(0, $subject['carmis']->selects, 'mismatched retry must not select stock');
    assertSameValue([], $subject['goods']->salesCalls, 'mismatched retry must not increment sales');
};

$tests['Automatic fulfilment selects card rows with a write lock'] = function (): void {
    $connection = new RecordingMySqlConnection();
    Carmis::setConnectionResolver(new SingleConnectionResolver($connection));
    try {
        $cards = (new CarmisService())->withGoodsByAmountAndStatusUnsold(7, 1);
    } finally {
        Carmis::unsetConnectionResolver();
    }

    assertSameValue(1, count($cards), 'one card fixture must be selected');
    assertSameValue(1, count($connection->selectQueries), 'card selection must execute one query');
    assertSameValue(
        true,
        stripos($connection->selectQueries[0], 'for update') !== false,
        'card selection query must lock rows until fulfilment commits'
    );
};

$tests['Fulfilment rolls back and wraps engine errors raised during stock allocation'] = function (): void {
    $subject = makeAutomaticOrderProcessSubject();
    $subject['carmis']->exception = new TypeError('synthetic stock allocation failure');

    assertThrowsRuleValidation(function () use ($subject): void {
        $subject['service']->completedOrder('ORDER202609250001', 10.00, 'TRADE-20260925-1');
    }, 'engine errors must be converted to a rejected fulfilment');

    assertSameValue(1, $subject['transactions']->rollbacks, 'engine error must roll back the transaction');
    assertSameValue(false, $subject['transactions']->active, 'failed transaction must not remain open');
};

$tests['Expiration only transitions an order that is still waiting for payment'] = function (): void {
    $connection = new RecordingMySqlConnection();
    Order::setConnectionResolver(new SingleConnectionResolver($connection));
    try {
        $reflection = new ReflectionClass(OrderService::class);
        /** @var OrderService $orders */
        $orders = $reflection->newInstanceWithoutConstructor();
        $updated = $orders->expiredOrderSN('ORDER202609250001');
    } finally {
        Order::unsetConnectionResolver();
    }

    assertSameValue(true, $updated, 'fixture update must report success');
    assertSameValue(1, count($connection->updateQueries), 'expiration must issue one atomic update');
    assertSameValue(Order::STATUS_EXPIRED, $connection->updateBindings[0][0], 'expiration must write expired status');
    assertSameValue('ORDER202609250001', $connection->updateBindings[0][2], 'expiration must target the order');
    assertSameValue(4, count($connection->updateBindings[0]), 'expiration update must include a prior-state binding');
    assertSameValue(
        Order::STATUS_WAIT_PAY,
        $connection->updateBindings[0][3],
        'expiration update must require the prior wait-pay state'
    );
};

$tests['Expiration job does not return a coupon when the atomic expiration lost a payment race'] = function (): void {
    $container = new Container();
    Container::setInstance($container);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    $bus = new FakeBusDispatcher();
    $container->instance(Illuminate\Contracts\Bus\Dispatcher::class, $bus);

    $order = new TestOrderRecord();
    $order->order_sn = 'ORDER202609250001';
    $order->status = Order::STATUS_WAIT_PAY;
    $orders = new FakeExpirationOrderService($order, false);
    $container->instance('Service\\OrderService', $orders);

    (new OrderExpired('ORDER202609250001'))->handle();

    assertSameValue(1, $orders->expirationCalls, 'job must attempt one atomic expiration');
    assertSameValue([], $bus->jobs, 'lost expiration race must not dispatch coupon return');
};

$tests['Order service compiles the fulfilment lookup as SELECT FOR UPDATE'] = function (): void {
    $connection = new RecordingMySqlConnection();
    Order::setConnectionResolver(new SingleConnectionResolver($connection));
    try {
        $reflection = new ReflectionClass(OrderService::class);
        /** @var OrderService $orders */
        $orders = $reflection->newInstanceWithoutConstructor();
        $order = $orders->detailOrderSNForUpdate('ORDER202609250001');
    } finally {
        Order::unsetConnectionResolver();
    }

    assertSameValue('ORDER202609250001', $order->order_sn, 'order fixture must be loaded');
    assertSameValue(
        true,
        stripos($connection->selectQueries[0], 'for update') !== false,
        'fulfilment order query must hold a write lock'
    );
};

$tests['Fulfilment rejects an amount that differs from the locked order'] = function (): void {
    $subject = makeAutomaticOrderProcessSubject();

    assertThrowsRuleValidation(function () use ($subject): void {
        $subject['service']->completedOrder('ORDER202609250001', 9.99, 'TRADE-20260925-1');
    }, 'underpayment must reject fulfilment');

    assertSameValue(0, $subject['carmis']->selects, 'amount mismatch must not select stock');
    assertSameValue([], $subject['goods']->salesCalls, 'amount mismatch must not increment sales');
    assertSameValue(1, $subject['transactions']->rollbacks, 'amount mismatch must roll back');
};

$tests['Expiration job returns the coupon after a successful atomic expiration'] = function (): void {
    $container = new Container();
    Container::setInstance($container);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    $bus = new FakeBusDispatcher();
    $container->instance(Illuminate\Contracts\Bus\Dispatcher::class, $bus);

    $order = new TestOrderRecord();
    $order->order_sn = 'ORDER202609250001';
    $order->status = Order::STATUS_WAIT_PAY;
    $orders = new FakeExpirationOrderService($order, true);
    $container->instance('Service\\OrderService', $orders);

    (new OrderExpired('ORDER202609250001'))->handle();

    assertSameValue(1, $orders->expirationCalls, 'job must atomically expire once');
    assertSameValue(1, count($bus->jobs), 'successful expiration must dispatch one coupon return');
    assertSameValue(App\Jobs\CouponBack::class, get_class($bus->jobs[0]), 'dispatched job must return the coupon');
};

$tests['Automatic fulfilment queues nothing when a later transactional write fails'] = function (): void {
    $subject = makeAutomaticOrderProcessSubject();
    $subject['goods']->salesException = new RuntimeException('synthetic post-dispatch database failure');

    assertThrowsRuleValidation(function () use ($subject): void {
        $subject['service']->completedOrder('ORDER202609250001', 10.00, 'TRADE-20260925-1');
    }, 'a later database failure must reject fulfilment');

    assertSameValue([], $subject['bus']->jobs, 'rolled-back fulfilment must not leak card mail to the queue');
    assertSameValue([], $subject['events']->events, 'rolled-back fulfilment must not publish an order update');
    assertSameValue(1, $subject['transactions']->rollbacks, 'later database failure must roll back');
};

$tests['Commit failure publishes neither deferred mail nor deferred order events'] = function (): void {
    $subject = makeAutomaticOrderProcessSubject();
    $subject['transactions']->commitException = new RuntimeException('synthetic commit failure');

    assertThrowsRuleValidation(function () use ($subject): void {
        $subject['service']->completedOrder('ORDER202609250001', 10.00, 'TRADE-20260925-1');
    }, 'commit failure must reject fulfilment');

    assertSameValue([], $subject['bus']->jobs, 'failed commit must not queue card delivery');
    assertSameValue([], $subject['events']->events, 'failed commit must not publish an order update');
    assertSameValue(1, $subject['transactions']->rollbacks, 'failed commit must roll back the open transaction');
    assertSameValue(
        $subject['events'],
        Order::getEventDispatcher(),
        'quiet model persistence must restore the global Eloquent dispatcher'
    );
};

$tests['Manual fulfilment runs its status listener and both mail submissions after commit'] = function (): void {
    $subject = makeManualOrderProcessSubject();
    $subject['events']->mailOnOrderUpdated = true;

    $result = $subject['service']->completedOrder('ORDER202609250001', 10.00, 'TRADE-20260925-1');

    assertSameValue($subject['order'], $result, 'manual fulfilment must return the pending order');
    assertSameValue(
        [OrderUpdatedEvent::class],
        array_map('get_class', $subject['events']->events),
        'manual fulfilment must publish exactly one modeled OrderUpdated listener event'
    );
    assertSameValue([false], $subject['events']->activeAtDispatch, 'OrderUpdated must be published after commit');
    assertSameValue(
        [MailSend::class, MailSend::class, App\Jobs\ApiHook::class],
        array_map('get_class', $subject['bus']->jobs),
        'status listener mail, manager mail, and API hook must each be submitted once'
    );
    assertSameValue(
        [false, false, false],
        $subject['bus']->activeAtDispatch,
        'manual listener and manager emails must not be submitted inside the transaction'
    );
};

$tests['Fulfilment rejects an ambient transaction instead of dispatching before its outer commit'] = function (): void {
    $subject = makeAutomaticOrderProcessSubject();
    $subject['transactions']->beginTransaction();

    try {
        assertThrowsRuleValidation(function () use ($subject): void {
            $subject['service']->completedOrder('ORDER202609250001', 10.00, 'TRADE-20260925-1');
        }, 'fulfilment must not create an unsafe nested transaction');

        assertSameValue(1, $subject['transactions']->begins, 'service must not begin a nested transaction');
        assertSameValue(0, $subject['transactions']->commits, 'service must leave the caller transaction untouched');
        assertSameValue([], $subject['bus']->jobs, 'nested call must not submit notifications');
        assertSameValue([], $subject['events']->events, 'nested call must not publish model events');
    } finally {
        while ($subject['transactions']->transactionLevel() > 0) {
            $subject['transactions']->rollBack();
        }
    }
};

$tests['Fulfilment does not roll back an already-committed order when post-commit dispatch fails'] = function (): void {
    $subject = makeAutomaticOrderProcessSubject();
    $subject['bus']->throwOnDispatchNumber = 2;

    assertThrowsRuleValidation(function () use ($subject): void {
        $subject['service']->completedOrder('ORDER202609250001', 10.00, 'TRADE-20260925-1');
    }, 'post-commit dispatch error must be surfaced for provider retry');

    assertSameValue(1, $subject['transactions']->commits, 'fulfilment must already be committed');
    assertSameValue(0, $subject['transactions']->rollbacks, 'committed transaction must not be rolled back');
    assertSameValue(Order::STATUS_COMPLETED, (int) $subject['order']->status, 'committed order remains fulfilled');
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS: {$name}\n");
    } catch (Throwable $exception) {
        ++$failures;
        fwrite(STDOUT, "FAIL: {$name}\n  " . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    }
}

fwrite(STDOUT, "Runtime: PHP " . PHP_VERSION . "\n");
fwrite(STDOUT, sprintf("%d test(s), %d failure(s)\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);
