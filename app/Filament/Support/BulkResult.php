<?php

namespace App\Filament\Support;

use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

final class BulkResult
{
    public int $changed = 0;
    public int $unchanged = 0;
    public int $skipped = 0;
    public array $reasons = [];
    public int $notificationFailures = 0;

    public static function ids(array $ids): array
    {
        if (count($ids) > 1000 || ! $ids) {
            throw ValidationException::withMessages(['selection' => '每次请选择 1–1,000 条记录；超出时请分批操作，本次未执行。']);
        }
        foreach ($ids as $id) {
            if ((! is_int($id) && ! is_string($id)) || ! preg_match('/\A[1-9][0-9]*\z/D', (string) $id) || (string) (int) $id !== (string) $id) {
                throw ValidationException::withMessages(['selection' => '选择包含无效记录，请刷新后重新选择。']);
            }
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    public function skip(string $reason): void
    {
        $this->skipped++;
        $this->reasons[$reason] = ($this->reasons[$reason] ?? 0) + 1;
    }

    public function send(): void
    {
        $body = [];
        foreach ($this->reasons as $reason => $count) {
            $body[] = "{$count} 条：{$reason}";
        }
        if ($this->notificationFailures) {
            $body[] = "{$this->notificationFailures} 条状态已保存，但通知提交失败。请核查邮件队列；重试状态操作不会补发。";
        }
        Notification::make()->title("已更改 {$this->changed} 条 · 未变化 {$this->unchanged} 条 · 跳过 {$this->skipped} 条")
            ->body(implode("\n", $body))->status($this->skipped || $this->notificationFailures ? 'warning' : 'success')->persistent()->send();
    }
}
