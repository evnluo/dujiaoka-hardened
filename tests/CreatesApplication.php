<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    public function createApplication()
    {
        foreach ([
            'APP_ENV'=>'testing', 'APP_KEY'=>'base64:'.base64_encode(str_repeat('s', 32)),
            'APP_URL'=>'http://localhost', 'ADMIN_ROUTE_PREFIX'=>'evansadmin',
            'DB_CONNECTION'=>'sqlite', 'DB_DATABASE'=>':memory:', 'CACHE_DRIVER'=>'array',
            'SESSION_DRIVER'=>'array', 'QUEUE_CONNECTION'=>'sync', 'LOG_CHANNEL'=>'stderr',
        ] as $key=>$value) { putenv("$key=$value"); $_ENV[$key] = $_SERVER[$key] = $value; }
        foreach (['framework/views', 'framework/cache/data', 'framework/sessions', 'logs'] as $path) {
            if (!is_dir(__DIR__.'/../storage/'.$path)) mkdir(__DIR__.'/../storage/'.$path, 0775, true);
        }
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->loadEnvironmentFrom('.env.synthetic-tests-do-not-read-production');
        $app->make(Kernel::class)->bootstrap();
        return $app;
    }
}
