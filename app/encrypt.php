<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Config;

$value = $argv[1] ?? '';
if ($value === '') {
    echo "Использование: php encrypt.php \"секретное_значение\"" . PHP_EOL;
    exit(1);
}

$encrypted = Config::encrypt($value);
echo "Зашифрованное значение: {$encrypted}" . PHP_EOL;
echo "Расшифровано обратно:   " . Config::decrypt($encrypted) . PHP_EOL;
