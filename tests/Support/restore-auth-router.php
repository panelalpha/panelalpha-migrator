<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Restore/RestoreHttpHandler.php';

(new \WordpressMigrator\Restore\RestoreHttpHandler('secret'))->authenticate();

header('Content-Type: application/json');
echo '{"ok":true}';
