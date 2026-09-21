<?php

$drivers = PDO::getAvailableDrivers();
fwrite(STDOUT, 'drivers='.implode(',', $drivers).PHP_EOL);

$host = '127.0.0.1';
$port = '5432';
$users = array_values(array_unique(array_filter([
    getenv('DB_USERNAME') ?: 'postgres',
    getenv('USERNAME') ?: null,
])));

foreach ($users as $user) {
    try {
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=postgres', $host, $port),
            $user,
            '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        fwrite(STDOUT, 'connected user='.$user.' as '.$pdo->query('select current_user')->fetchColumn().PHP_EOL);
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDOUT, 'fail user='.$user.' '.$e->getMessage().PHP_EOL);
    }
}

exit(1);
