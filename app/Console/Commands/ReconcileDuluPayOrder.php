<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Service\OrderProcessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ReconcileDuluPayOrder extends Command
{
    protected $signature = 'payment:reconcile-dulupay {order} {--trade= : Independently verified provider trade} {--actor= : Operator audit label} {--execute : Explicitly authorize this one recovery}';
    protected $description = 'Requery DuluPay and reconcile one expired automatic or manual order without coupons (server operators only; manual remains pending)';

    public function handle(OrderProcessService $service): int
    {
        if (!$this->option('execute') || !$this->option('trade') || !$this->option('actor')) {
            $this->error('Explicit --execute, --trade and --actor are required. No changes made.');
            return self::FAILURE;
        }
        $orderSN = (string) $this->argument('order');
        try {
            $before = Order::where('order_sn', $orderSN)->first();
            $order = $service->reconcileDuluPayOrder($orderSN, (string) $this->option('trade'), (string) $this->option('actor'));
            $manualPending = (int) $order->type === Order::MANUAL_PROCESSING
                && in_array((int) $order->status, [Order::STATUS_PENDING, Order::STATUS_PROCESSING], true);
            $this->line(json_encode([
                'order_hash' => hash('sha256', $orderSN), 'status' => (int) $order->status,
                'trade_recorded' => hash_equals((string) $this->option('trade'), $order->trade_no),
                'delivery_complete' => (int) $order->status === Order::STATUS_COMPLETED,
                'manual_attention_required' => $manualPending || (int) $order->status === Order::STATUS_ABNORMAL,
                'previously_paid' => $before && (string) $before->trade_no !== '',
            ], JSON_THROW_ON_ERROR));
            return (int) $order->status === Order::STATUS_COMPLETED || $manualPending ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable) {
            // Never echo transport/SQL errors, which can include payment or card data.
            Log::warning('payment_reconciliation_failed', ['order_hash' => hash('sha256', $orderSN)]);
            $this->error('Recovery failed or a post-commit notification failed. Inspect order state and safe logs before retrying; never reset paid state.');
            return self::FAILURE;
        }
    }
}
