<?php
// Exclusively local, never overwrites existing credentials.
$path = dirname(__DIR__).'/.env.local';
if (file_exists($path)) { fwrite(STDERR, ".env.local already exists; retained.\n"); exit(0); }
$old = umask(0077);
file_put_contents($path, 'APP_SECRET='.bin2hex(random_bytes(32))."\nPOSTGRES_PASSWORD=".bin2hex(random_bytes(24))."\n");
umask($old);
echo "Created ignored local configuration.\n";
