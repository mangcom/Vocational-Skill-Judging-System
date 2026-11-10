<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/migration_runner.php';

run_migrations(db());
