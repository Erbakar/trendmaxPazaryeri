#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
root="$PWD"
runtime="$root/storage/local"
mkdir -p "$runtime"
pids=()
trap 'if (( ${#pids[@]} )); then kill "${pids[@]}" 2>/dev/null || true; fi' EXIT
trap 'exit 130' INT TERM
if [[ ! -f .env || ! -d "$runtime/mysql/mysql" ]]; then
    echo 'Yerel .env veya veritabanı eksik. README.local.md dosyasına bakın.' >&2
    exit 1
fi
mysql_server="${MYSQLD_BIN:-$(command -v mysqld || true)}"
mysql_admin="${MYSQLADMIN_BIN:-$(command -v mysqladmin || true)}"
if [[ -z "$mysql_server" || -z "$mysql_admin" ]]; then
    echo 'MariaDB sunucusu ve mysqladmin bulunamadı.' >&2
    exit 1
fi
if ! "$mysql_admin" --no-defaults --socket="$runtime/mysql.sock" -u root ping >/dev/null 2>&1; then
    nohup "$mysql_server" --no-defaults --datadir="$runtime/mysql" \
        --socket="$runtime/mysql.sock" --pid-file="$runtime/mysql.pid" \
        --bind-address=127.0.0.1 --port=3307 --log-error="$runtime/mysql-error.log" \
        >"$runtime/mysql-console.log" 2>&1 < /dev/null &
    pids+=("$!")
    ready=false
    for attempt in {1..30}; do
        if "$mysql_admin" --no-defaults --socket="$runtime/mysql.sock" -u root ping >/dev/null 2>&1; then
            ready=true; break
        fi
        sleep 0.2
    done
    if [[ "$ready" != true ]]; then
        echo "MariaDB başlayamadı: $runtime/mysql-error.log" >&2; exit 1
    fi
fi
if ! curl --fail --silent --max-time 2 http://127.0.0.1:8080/login > /dev/null; then
    nohup php -d upload_max_filesize=50M -d post_max_size=55M -S 127.0.0.1:8080 tools/serve-local.php >"$runtime/php-server.log" 2>&1 < /dev/null &
    echo "$!" > "$runtime/php-server.pid"
    pids+=("$!")
    ready=false
    for attempt in {1..30}; do
        if curl --fail --silent --max-time 2 http://127.0.0.1:8080/login > /dev/null; then
            ready=true; break
        fi
        sleep 0.2
    done
    if [[ "$ready" != true ]]; then
        echo "Uygulama başlayamadı: $runtime/php-server.log" >&2; exit 1
    fi
fi
printf '%s\n' 'Yerel uygulama: http://127.0.0.1:8080/login'

# Keep owned services attached to this terminal/session.
if (( ${#pids[@]} )); then
    wait "${pids[@]}"
fi
