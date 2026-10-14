<?php

namespace App\Service;

use App\Mcp\Domains\SettingsDomain;
use App\Mcp\OperationException;
use App\Support\ShopSettings;
use Illuminate\Support\Facades\Mail;

class AdminTestMail
{
    public static function validateSettings(): array
    {
        $s = app(ShopSettings::class)->getAll();
        if (blank($s['host'] ?? '') || ! filter_var($s['from_address'] ?? '', FILTER_VALIDATE_EMAIL)) throw new OperationException('smtp_configuration_required');
        SettingsDomain::assertHost($s['host']);
        return $s;
    }
    public static function transport(array $s): \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport
    {
        $tls = ($s['encryption'] ?? 'tls') === 'ssl';
        $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport($s['address'], (int) ($s['port'] ?? 587), $tls);
        $transport->setRequireTls(($s['encryption'] ?? 'tls') === 'tls');
        $transport->setUsername($s['username'] ?? '')->setPassword($s['password'] ?? '');
        $transport->getStream()->setTimeout(15)->setStreamOptions(['ssl' => ['peer_name' => $s['host'], 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        return $transport;
    }
    public function send(array $p, ?\Closure $guard = null): array
    {
        $s = \Illuminate\Support\Facades\DB::transaction(function () use ($guard): array {
            if ($guard) $guard();
            return self::validateSettings();
        });
        try {
            $s['address'] = app(ProductHookDelivery::class)->address($s['host']);
            Mail::extend('mcp_pinned_smtp', fn (array $config) => self::transport($config));
            config(['mail.mailers.mcp_test' => ['transport' => 'mcp_pinned_smtp', ...\Illuminate\Support\Arr::only($s, ['host', 'address', 'port', 'encryption', 'username', 'password'])]]);
            Mail::purge('mcp_test');
            Mail::mailer('mcp_test')->raw($p['body'], fn ($message) => $message->from($s['from_address'], $s['from_name'] ?? 'Shop')->to($p['to'])->subject($p['subject']));
            return ['submission' => 'accepted_not_delivery_confirmed'];
        } catch (\Throwable) { throw new OperationException('mail_outcome_unknown'); }
        finally { Mail::purge('mcp_test'); config(['mail.mailers.mcp_test' => null]); }
    }
}
