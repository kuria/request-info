<?php declare(strict_types=1);

use phpmock\phpunit\PHPMock;

require __DIR__ . '/../vendor/autoload.php';

(function () {
    // workaround for https://bugs.php.net/bug.php?id=64346
    $namespaceToMockedFunctions = [
        'Kuria\\RequestInfo\\Helper' => ['extension_loaded', 'inet_pton'],
    ];

    foreach ($namespaceToMockedFunctions as $namespace => $mockedFunctions) {
        foreach ($mockedFunctions as $mockedFunction) {
            PHPMock::defineFunctionMock($namespace, $mockedFunction);
        }
    }
})();
