<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    db()->query('SELECT 1');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
