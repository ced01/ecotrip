<?php

declare(strict_types=1);

// Exclusively local, never overwrites existing credentials.
$path = dirname(__DIR__).'/.env.local';
if (file_exists($path)) {
    fwrite(STDERR, ".env.local already exists; retained.\n");
    exit(0);
}

$uid = getenv('LOCAL_UID');
$gid = getenv('LOCAL_GID');
$uid = $uid !== false && ctype_digit($uid) ? $uid : '1000';
$gid = $gid !== false && ctype_digit($gid) ? $gid : '1000';

$contents = implode("\n", [
    'HTTP_PORT=18081',
    'DEV_HTTP_PORT=18082',
    'LOCAL_UID='.$uid,
    'LOCAL_GID='.$gid,
    'APP_SECRET='.bin2hex(random_bytes(32)),
    'POSTGRES_PASSWORD='.bin2hex(random_bytes(24)),
    'TRUSTED_PROXIES=',
    '',
]);
$old = umask(0077);
if (file_put_contents($path, $contents, LOCK_EX) === false) {
    throw new RuntimeException('Unable to create .env.local.');
}
umask($old);
echo "Created ignored local configuration.\n";
