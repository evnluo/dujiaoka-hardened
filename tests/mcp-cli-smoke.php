<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (getenv('MCP_SYNTHETIC') !== '1' || getenv('APP_ENV') !== 'testing' || Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'dujiaoka_mcp_test') exit(2);
function invoke(array $args, array $input): array {
    $process = proc_open([PHP_BINARY, __DIR__.'/../artisan', 'shop:mcp-token', ...$args], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
    fwrite($pipes[0], json_encode($input)); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $out, $err];
}
$token = bin2hex(random_bytes(32));
[$code,$out,$err] = invoke(['import','--admin=sdk-admin','--name=CLI-smoke','--scopes=read','--days=1'], ['password'=>'synthetic-password','token'=>$token]);
$id = json_decode($out, true)['id'] ?? null;
if ($code !== 0 || !$id || str_contains($out.$err, $token)) { fwrite(STDERR,"CLI import failed\n"); exit(1); }
$row = Illuminate\Support\Facades\DB::table('shop_mcp_tokens')->find($id);
if ($row->token_hash !== hash('sha256',$token)) exit(1);
[$code,$out,$err] = invoke(['revoke','--admin=sdk-admin','--id='.$id], ['password'=>'synthetic-password']);
if ($code !== 0 || !Illuminate\Support\Facades\DB::table('shop_mcp_tokens')->find($id)->revoked_at) exit(1);
[$code,$out,$err] = invoke(['import','--admin=sdk-admin','--name=CLI-smoke','--scopes=*'], ['password'=>'synthetic-password','token'=>$token]);
if ($code === 0 || str_contains($out.$err,$token)) exit(1);
print("CLI stdin import/hash/revoke/reject passed\n");
