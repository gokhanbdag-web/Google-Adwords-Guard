<?php
declare(strict_types=1);

// Shared-hosting compatibility: keep the application code in /public,
// but allow the repository directory itself to be the domain/subdomain root.
require __DIR__ . '/public/index.php';
