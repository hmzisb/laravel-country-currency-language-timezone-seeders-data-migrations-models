<?php

use Hmzisb\ReferenceData\Support\ReferenceData;

// Validate the bundled snapshot without booting Laravel or connecting to a database.
require dirname(__DIR__).'/src/Support/ReferenceData.php';

try {
    $directory = $argv[1] ?? dirname(__DIR__).'/database/data/reference';
    $catalogs = (new ReferenceData())->load($directory);

    foreach ($catalogs as $name => $rows) {
        fwrite(STDOUT, $name.': '.count($rows).PHP_EOL);
    }

    fwrite(STDOUT, 'Validation passed; no database changes were made.'.PHP_EOL);
} catch (\Throwable $exception) {
    fwrite(STDERR, 'Validation failed: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
