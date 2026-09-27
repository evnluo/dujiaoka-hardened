<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

// Geetest v3 protocol: https://github.com/GeeTeam/gt3-php-sdk
// Unlike the legacy SDK, provider outages fail closed (no local failback bypass).
class GeetestCaptcha
{
    public function register(Request $request): array
    {
        abort_unless(dujiaoka_config_get('is_open_geetest'), 404);
        $id = (string) dujiaoka_config_get('geetest_id', '');
        $key = (string) dujiaoka_config_get('geetest_key', '');
        abort_if($id === '' || $key === '', 503, 'Captcha is not configured.');
        $response = Http::connectTimeout(2)->timeout(5)->get('https://api.geetest.com/register.php', [
            'gt' => $id, 'new_captcha' => 1, 'user_id' => $request->session()->getId(),
            'client_type' => 'web', 'ip_address' => $request->ip(),
        ])->throw();
        $raw = trim($response->body());
        abort_unless(preg_match('/\A[0-9a-f]{32}\z/i', $raw), 503, 'Captcha provider unavailable.');
        $challenge = md5($raw.$key);
        $request->session()->put('geetest.challenge', $challenge);
        return ['success' => 1, 'gt' => $id, 'challenge' => $challenge, 'new_captcha' => 1];
    }

    public function validate(Request $request): bool
    {
        $challenge = $request->input('geetest_challenge');
        $validate = $request->input('geetest_validate');
        $seccode = $request->input('geetest_seccode');
        if (!$request->hasSession()) return false;
        $expected = $request->session()->pull('geetest.challenge');
        if (!is_string($expected) || !is_string($challenge) || !is_string($validate) || !is_string($seccode)
            || !hash_equals($expected, $challenge)
            || !hash_equals(md5((string) dujiaoka_config_get('geetest_key').'geetest'.$challenge), $validate)) return false;
        try {
            $response = Http::asForm()->connectTimeout(2)->timeout(5)->post('https://api.geetest.com/validate.php', [
                'seccode' => $seccode, 'challenge' => $challenge, 'captchaid' => dujiaoka_config_get('geetest_id'),
                'json_format' => 1, 'sdk' => 'php_3.0.0', 'timestamp' => time(),
                'user_id' => $request->session()->getId(), 'client_type' => 'web', 'ip_address' => $request->ip(),
            ])->throw();
            $code = $response->json('seccode');
            return is_string($code) && hash_equals(md5($seccode), $code);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
