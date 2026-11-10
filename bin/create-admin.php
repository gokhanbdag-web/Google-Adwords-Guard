<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\\Core\\Database;
use App\\Core\\Env;

Env::load(BASE_PATH . '/.env');

$email = filter_var($argv[1] ?? '', FILTER_VALIDATE_EMAIL);
if (!$email) {
    fwrite(STDERR, "Usage: php bin/create-admin.php user@example.com\n");
    exit(1);
}

$statement = Database::connection()->prepare(
    "UPDATE users SET role = 'admin' WHERE email = :email"
);
$statement->execute(['email' => $email]);

if ($statement->rowCount() !== 1) {
    fwrite(STDERR, "No matching user was promoted. Check the email address.\n");
    exit(1);
}

fwrite(STDOUT, "Admin role granted to {$email}.\n");
