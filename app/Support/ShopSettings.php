<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ShopSettings
{
    public const SLUG = 'dujiaoka_config';

    public function getAll(): array
    {
        $value = DB::table('admin_settings')->where('slug', self::SLUG)->value('value');
        if ($value !== null) {
            return $this->decode($value);
        }
        $legacy = Cache::get('system-setting', []);
        return is_array($legacy) ? $legacy : [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->getAll()[$key] ?? $default;
    }

    public function setMany(array $values): void
    {
        $this->validate($values);
        DB::transaction(function () use ($values): void {
            $row = DB::table('admin_settings')->where('slug', self::SLUG)->lockForUpdate()->first();
            $current = $row ? $this->decode($row->value) : $this->getAll();
            $merged = array_replace($current, $values);
            DB::table('admin_settings')->updateOrInsert(['slug' => self::SLUG], [
                'value' => serialize($merged), 'updated_at' => now(),
            ]);
        });
    }

    // Explicit, idempotent import. Never delete or flush the old cache.
    public function importLegacy(?array $backup = null): int
    {
        return DB::transaction(function () use ($backup): int {
            $row = DB::table('admin_settings')->where('slug', self::SLUG)->lockForUpdate()->first();
            if ($row) return count($this->decode($row->value));
            $values = $backup ?? Cache::get('system-setting');
            if (!is_array($values) || $values === []) {
                throw new RuntimeException('Legacy settings are missing; restore the protected settings backup before importing.');
            }
            $this->validate($values);
            DB::table('admin_settings')->insert([
                'slug' => self::SLUG, 'value' => serialize($values), 'created_at' => now(), 'updated_at' => now(),
            ]);
            return count($values);
        });
    }

    private function decode(string $value): array
    {
        $decoded = @unserialize($value, ['allowed_classes' => false]);
        if (!is_array($decoded)) throw new RuntimeException('The persisted shop settings are not a valid serialized array.');
        $this->validate($decoded);
        return $decoded;
    }

    private function validate(array $values): void
    {
        foreach ($values as $value) {
            if (is_array($value)) $this->validate($value);
            elseif (!is_scalar($value) && $value !== null) throw new RuntimeException('Settings may only contain arrays and scalar values.');
        }
    }
}
