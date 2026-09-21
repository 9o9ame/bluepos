<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo 'APP_ENV='.(string) $app->environment().PHP_EOL;
echo 'DB_CONNECTION='.(string) config('database.default').PHP_EOL;
echo 'DB_DATABASE='.(string) config('database.connections.pgsql.database').PHP_EOL;
echo 'DB_PASSWORD_SET='.(((string) config('database.connections.pgsql.password')) !== '' ? 'yes' : 'no').PHP_EOL;
echo 'CONFIG_CACHED='.($app->configurationIsCached() ? 'yes' : 'no').PHP_EOL;
