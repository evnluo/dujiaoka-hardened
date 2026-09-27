<?php

namespace App\Filament\Support {
    // Authorization is covered by the application suite. Only this boundary is stubbed;
    // InventoryOperations, Eloquent, transactions and row locks below are production code.
    final class AdminAccess
    {
        public static function authorize(): void {}
    }
}

namespace {
    require __DIR__ . '/../vendor/autoload.php';

    use App\Filament\Support\InventoryOperations;
    use App\Models\Carmis;
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\Events\QueryExecuted;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Translation\ArrayLoader;
    use Illuminate\Translation\Translator;
    use Illuminate\Validation\Factory;
    use Illuminate\Validation\ValidationException;

    if (getenv('DB_HOST') !== 'dujiaoka-inventory-test-db' || getenv('DB_DATABASE') !== 'dujiaoka_inventory_test') {
        fwrite(STDERR, "Refusing non-test database\n");
        exit(2);
    }

    $app = new Container();
    Container::setInstance($app);
    $db = new Capsule($app);
    $connection = [
        'driver' => 'mysql', 'host' => getenv('DB_HOST'), 'database' => getenv('DB_DATABASE'),
        'username' => 'test', 'password' => 'test-only-password',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
    ];
    $db->addConnection($connection);
    // The observer only reads lock metadata on this disposable database service.
    $db->addConnection(array_replace($connection, ['username' => 'root', 'password' => 'test-root-only']), 'observer');
    $db->setEventDispatcher(new Dispatcher($app));
    $db->setAsGlobal();
    $db->bootEloquent();
    $app->instance('db', $db->getDatabaseManager());
    $app->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $app));
    Facade::setFacadeApplication($app);

    DB::statement('CREATE TABLE goods (id int PRIMARY KEY, type int NOT NULL, created_at datetime NULL, updated_at datetime NULL, deleted_at datetime NULL) ENGINE=InnoDB');
    DB::statement('CREATE TABLE carmis (id bigint AUTO_INCREMENT PRIMARY KEY, goods_id int NOT NULL, status tinyint NOT NULL DEFAULT 1, is_loop tinyint NOT NULL DEFAULT 0, carmi text NOT NULL, created_at datetime NULL, updated_at datetime NULL, deleted_at datetime NULL, KEY idx_goods_id (goods_id)) ENGINE=InnoDB');

    function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw new RuntimeException($message);
        }
    }

    function seed(): void
    {
        DB::table('carmis')->delete();
        DB::table('goods')->delete();
        DB::table('goods')->insert(['id' => 7, 'type' => 1]);
        DB::table('carmis')->insert([
            ['id' => 501, 'goods_id' => 7, 'carmi' => 'SYNTHETIC-ORIGINAL-501'],
            ['id' => 502, 'goods_id' => 7, 'carmi' => 'SYNTHETIC-ORIGINAL-502'],
        ]);
    }

    function send($socket, array $message): void
    {
        $line = json_encode($message, JSON_THROW_ON_ERROR) . "\n";
        check(fwrite($socket, $line) === strlen($line), 'worker channel write failed');
    }

    function receive($socket): array
    {
        $line = fgets($socket);
        check($line !== false, 'worker channel closed or timed out');

        return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    }

    function worker(string $operation, int $cardId, bool $pauseAfterWrite, bool $staleSnapshot): array
    {
        // No fork may inherit a live database socket from the coordinator.
        DB::disconnect();
        DB::disconnect('observer');
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        check($sockets !== false, 'socket pair failed');
        $pid = pcntl_fork();
        check($pid >= 0, 'fork failed');
        if ($pid !== 0) {
            fclose($sockets[1]);
            stream_set_timeout($sockets[0], 20);

            return ['pid' => $pid, 'socket' => $sockets[0]];
        }

        fclose($sockets[0]);
        $socket = $sockets[1];
        stream_set_timeout($socket, 20);
        pcntl_alarm(25);
        try {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
            check(DB::selectOne('SELECT @@tx_isolation AS isolation_level')->isolation_level === 'REPEATABLE-READ', 'wrong isolation level');
            if ($staleSnapshot) {
                DB::beginTransaction();
                DB::table('carmis')->count(); // Establish the read view before the other writer commits.
            }
            send($socket, ['stage' => 'ready', 'connection' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id]);
            check(receive($socket)['stage'] === 'go', 'missing worker start');
            if ($pauseAfterWrite) {
                DB::listen(function (QueryExecuted $query) use ($socket): void {
                    if (preg_match('/^(update|insert into) `carmis`/i', $query->sql)) {
                        check(DB::transactionLevel() > 0, 'inventory write escaped transaction');
                        send($socket, ['stage' => 'written']);
                        check(receive($socket)['stage'] === 'commit', 'missing commit release');
                    }
                });
            }
            if ($operation === 'import') {
                $result = ['outcome' => 'imported', 'counts' => InventoryOperations::import(7, 'SYNTHETIC-SHARED-SECRET')];
            } else {
                InventoryOperations::update($cardId, ['carmi' => 'SYNTHETIC-SHARED-SECRET']);
                $result = ['outcome' => 'updated'];
            }
            if ($staleSnapshot) {
                DB::commit();
            }
        } catch (ValidationException $e) {
            $result = ['outcome' => 'rejected', 'errors' => $e->errors()];
        } catch (Throwable $e) {
            $result = ['outcome' => 'error', 'error' => get_class($e) . ': ' . $e->getMessage()];
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        send($socket, ['stage' => 'result', 'result' => $result]);
        fclose($socket);
        exit(0);
    }

    function race(string $firstOperation, string $secondOperation, bool $staleSnapshot): void
    {
        seed();
        $first = worker($firstOperation, 501, true, false);
        $second = worker($secondOperation, 502, false, $staleSnapshot);
        try {
            $firstReady = receive($first['socket']);
            $secondReady = receive($second['socket']);
            check($firstReady['stage'] === 'ready' && $secondReady['stage'] === 'ready', 'workers did not initialize');
            send($first['socket'], ['stage' => 'go']);
            $written = receive($first['socket']);
            check($written['stage'] === 'written', 'first worker did not reach its uncommitted write: ' . json_encode($written));
            send($second['socket'], ['stage' => 'go']);

            // Wait for a real lock wait or completion, not an assumed scheduler delay.
            $secondResult = null;
            $deadline = microtime(true) + 8;
            do {
                $read = [$second['socket']];
                $write = $except = [];
                if (stream_select($read, $write, $except, 0, 0) > 0) {
                    $secondResult = receive($second['socket']);
                    break;
                }
                $waiting = DB::connection('observer')->selectOne(
                    'SELECT COUNT(*) AS n FROM information_schema.INNODB_LOCK_WAITS w '
                    . 'JOIN information_schema.INNODB_TRX waiter ON waiter.trx_id = w.requesting_trx_id '
                    . 'JOIN information_schema.INNODB_TRX blocker ON blocker.trx_id = w.blocking_trx_id '
                    . 'WHERE waiter.trx_mysql_thread_id = ? AND blocker.trx_mysql_thread_id = ?',
                    [$secondReady['connection'], $firstReady['connection']]
                );
                if ((int) $waiting->n > 0) {
                    break;
                }
                check(microtime(true) < $deadline, 'second worker neither completed nor waited for the first writer');
                // InnoDB lock metadata needs a quiet interval to refresh its snapshot.
                usleep(200000);
            } while (true);

            send($first['socket'], ['stage' => 'commit']);
            $firstResult = receive($first['socket']);
            $secondResult ??= receive($second['socket']);
            check($firstResult['stage'] === 'result' && $secondResult['stage'] === 'result', 'missing worker result');
            $firstResult = $firstResult['result'];
            $secondResult = $secondResult['result'];

            DB::disconnect();
            $copies = Carmis::withTrashed()->where('goods_id', 7)->where('carmi', 'SYNTHETIC-SHARED-SECRET')->count();
            check($copies === 1, "duplicate credential persisted ($copies copies): " . json_encode([$firstResult, $secondResult]));
            $expectedFirst = $firstOperation === 'import'
                ? ['outcome' => 'imported', 'counts' => ['added' => 1, 'skipped' => 0]]
                : ['outcome' => 'updated'];
            check($firstResult === $expectedFirst, 'first writer failed: ' . json_encode($firstResult));
            if ($secondOperation === 'import') {
                check($secondResult === ['outcome' => 'imported', 'counts' => ['added' => 0, 'skipped' => 1]], 'competing import did not skip duplicate: ' . json_encode($secondResult));
            } else {
                check($secondResult === ['outcome' => 'rejected', 'errors' => ['carmi' => ['该商品已有此卡密（包括已售出及归档记录）。']]], 'competing replacement did not reject duplicate: ' . json_encode($secondResult));
                check(Carmis::findOrFail(502)->carmi === 'SYNTHETIC-ORIGINAL-502', 'rejected replacement changed its original secret');
            }
            check(Carmis::count() === ($firstOperation === 'import' ? 3 : 2), 'unexpected inventory count');
        } finally {
            $statuses = [];
            foreach ([$first, $second] as $child) {
                fclose($child['socket']);
                pcntl_waitpid($child['pid'], $status);
                $statuses[] = $status;
            }
        }
        foreach ($statuses as $status) {
            check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'worker process failed');
        }
    }

    $tests = [];
    foreach ([false, true] as $snapshot) {
        foreach ([['update', 'update'], ['import', 'update'], ['update', 'import'], ['import', 'import']] as [$first, $second]) {
            $name = "$first / $second" . ($snapshot ? ' with an older RR snapshot' : ' with a fresh transaction');
            $tests[$name] = fn () => race($first, $second, $snapshot);
        }
    }
    $tests['sold and archived credentials remain unavailable to imports and replacements'] = function (): void {
        seed();
        DB::table('carmis')->insert([
            ['goods_id' => 7, 'carmi' => 'SYNTHETIC-SOLD', 'status' => 2, 'deleted_at' => null],
            ['goods_id' => 7, 'carmi' => 'SYNTHETIC-ARCHIVED', 'status' => 1, 'deleted_at' => '2020-01-01 00:00:00'],
        ]);
        check(InventoryOperations::import(7, "SYNTHETIC-SOLD\nSYNTHETIC-ARCHIVED") === ['added' => 0, 'skipped' => 2], 'historical secrets were imported');
        foreach (['SYNTHETIC-SOLD', 'SYNTHETIC-ARCHIVED'] as $secret) {
            try {
                InventoryOperations::update(501, ['carmi' => $secret]);
                throw new RuntimeException('historical secret was accepted as a replacement');
            } catch (ValidationException $e) {
                check(isset($e->errors()['carmi']), 'wrong validation field');
            }
        }
        check(Carmis::findOrFail(501)->carmi === 'SYNTHETIC-ORIGINAL-501', 'historical duplicate rejection modified stock');
        check(Carmis::withTrashed()->count() === 4, 'historical inventory was changed');
    };
    $tests['unchanged credentials and identical credentials on different products are allowed'] = function (): void {
        seed();
        InventoryOperations::update(501, ['carmi' => 'SYNTHETIC-ORIGINAL-501']);
        DB::table('goods')->insert(['id' => 8, 'type' => 1]);
        check(InventoryOperations::import(8, 'SYNTHETIC-ORIGINAL-501') === ['added' => 1, 'skipped' => 0], 'duplicate check escaped its product');
        InventoryOperations::update(502, ['carmi' => 'SYNTHETIC-UNIQUE-REPLACEMENT']);
        check(Carmis::findOrFail(502)->carmi === 'SYNTHETIC-UNIQUE-REPLACEMENT', 'valid replacement did not persist');
    };
    $failures = 0;
    foreach ($tests as $name => $run) {
        try {
            $run();
            echo "PASS: $name\n";
        } catch (Throwable $e) {
            $failures++;
            echo "FAIL: $name: {$e->getMessage()}\n";
        }
    }
    echo count($tests) . " MariaDB inventory tests, $failures failures\n";
    exit($failures ? 1 : 0);
}
