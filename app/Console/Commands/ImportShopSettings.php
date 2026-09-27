<?php

namespace App\Console\Commands;

use App\Support\ShopSettings;
use Illuminate\Console\Command;

class ImportShopSettings extends Command
{
    protected $signature = 'shop:import-settings {--from-json= : Protected JSON backup file, never inline credentials}';
    protected $description = 'Persist legacy shop settings without deleting cache data or replacing existing database settings';

    public function handle(ShopSettings $settings): int
    {
        try {
            $path = $this->option('from-json');
            $backup = $path ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : null;
            if ($path && !is_array($backup)) throw new \RuntimeException('Backup must contain a settings object.');
            $count = $settings->importLegacy($backup);
            $this->info("Verified durable shop settings: {$count} keys. Legacy cache was not modified.");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Settings import failed. Verify the protected backup, database, and legacy cache configuration.');
            return self::FAILURE;
        }
    }
}
