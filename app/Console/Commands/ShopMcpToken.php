<?php

namespace App\Console\Commands;

use App\Mcp\TokenManager;
use Illuminate\Console\Command;

final class ShopMcpToken extends Command
{
    protected $signature = 'shop:mcp-token {action : import or revoke} {--admin= : Administrator username} {--name= : Attributable client label} {--scopes= : Comma-separated exact scopes} {--days=30 : Expiry 1 to 365 days} {--id= : Revoke token ID} {--revoke= : Atomically revoke old owned ID when importing}';
    protected $description = 'Local authenticated MCP token management; stdin JSON holds password and token. Never outputs secrets.';

    public function handle(TokenManager $manager): int
    {
        try {
            if (function_exists('stream_isatty') && stream_isatty(STDIN)) throw new \RuntimeException();
            $raw = stream_get_contents(STDIN, 16385);
            if (strlen($raw) > 16384) throw new \RuntimeException();
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ! is_string($data['password'] ?? null)) throw new \RuntimeException();
            $username = (string) $this->option('admin');
            if ($this->argument('action') === 'revoke') {
                $id = filter_var($this->option('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (! $id) throw new \RuntimeException();
                $manager->revoke($username, $data['password'], $id);
                $this->line(json_encode(['id' => $id, 'revoked' => true]));
            } elseif ($this->argument('action') === 'import') {
                if (! is_string($data['token'] ?? null)) throw new \RuntimeException();
                $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
                $revoke = $this->option('revoke') === null ? null : filter_var($this->option('revoke'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (! $days || $revoke === false) throw new \RuntimeException();
                $id = $manager->import($username, $data['password'], $data['token'], explode(',', (string) $this->option('scopes')), (string) $this->option('name'), $days, $revoke);
                $this->line(json_encode(['id' => $id, 'imported' => true]));
            } else throw new \RuntimeException();
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Token operation rejected. Check stdin shape, administrator credentials, exact scopes, expiry and owned token ID. No secrets logged.');
            return self::FAILURE;
        }
    }
}
