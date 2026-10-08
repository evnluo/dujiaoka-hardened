<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

final class Turnstile
{
    public function configured(): bool
    {
        foreach (['site_key', 'secret_key', 'hostname'] as $key) {
            $value = config('turnstile.'.$key);
            if (!is_string($value) || trim($value) === '') {
                return false;
            }
        }
        // Official dummy keys are useful in isolated tests, never in a live shop.
        return app()->environment(['local', 'testing'])
            || (str_starts_with(config('turnstile.site_key'), '0x')
                && str_starts_with(config('turnstile.secret_key'), '0x'));
    }

    public function validate(mixed $token): bool
    {
        if (!$this->configured() || !is_string($token) || $token === '' || strlen($token) > 2048) {
            return false;
        }
        try {
            // Single attempt: a timeout may have consumed the token. Ask the browser for a new one.
            // Omit remoteip rather than trusting a forwarded header. Never log the token or payload.
            $response = Http::asForm()->connectTimeout(2)->timeout(6)->post(
                'https://challenges.cloudflare.com/turnstile/v0/siteverify',
                ['secret' => config('turnstile.secret_key'), 'response' => $token],
            );
            $result = $response->json();
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return false;
        }

        return $response->successful() && is_array($result)
            && ($result['success'] ?? null) === true
            && ($result['hostname'] ?? null) === config('turnstile.hostname')
            && ($result['action'] ?? null) === 'create_order';
    }
}
