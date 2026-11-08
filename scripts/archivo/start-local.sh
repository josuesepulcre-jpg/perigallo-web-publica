#!/bin/sh
set -eu
cd "$(dirname "$0")/../.."
ARCHIVE_PHP_BIN="${ARCHIVE_PHP_BIN:-$(command -v php)}"
"$ARCHIVE_PHP_BIN" -r '$c=parse_ini_file(".env",false,INI_SCANNER_RAW); if (($c["APP_ENV"]??"")!=="staging" || ($c["DB_DATABASE"]??"")!=="perigallo_archivo_local" || ($c["DB_HOST"]??"")!=="127.0.0.1") {fwrite(STDERR,"Se requiere la configuración local aislada.\n");exit(1);}'
exec "$ARCHIVE_PHP_BIN" -S 127.0.0.1:4195 -d sendmail_path=/usr/bin/false -d upload_max_filesize=50M -d post_max_size=64M -d memory_limit=192M -d display_errors=0 scripts/archivo/router.php
