#!/usr/bin/env bash
# Жиналған архивті таза қалтаға ашып, сервердегідей орнатып, шынымен жұмыс істейтінін тексереді.
# Қолданылуы: scripts/smoke-release.sh dist/qatysu-<нұсқа>.zip
set -euo pipefail

ZIP="$(realpath "$1")"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d /tmp/qatysu-smoke.XXXXXX)"
PORT=8010
BASE="http://127.0.0.1:$PORT"
JAR="$WORK/cookies.txt"

"$ROOT/scripts/dev-db.sh" >/dev/null
mariadb -uroot -e "drop database if exists qatysu_smoke; create database qatysu_smoke character set utf8mb4 collate utf8mb4_unicode_ci; grant all on qatysu_smoke.* to 'qatysu'@'127.0.0.1'; grant all on qatysu_smoke.* to 'qatysu'@'localhost';"

mkdir "$WORK/site" && cd "$WORK/site" && unzip -q "$ZIP"

# Сервердегі қадам: үлгіден .env жасап, дерекқор мәндерін толтыру.
# HTTPS жоқ жергілікті тексеріс болғандықтан cookie-дің «тек HTTPS» талабы өшіріледі.
sed -e "s|^APP_URL=.*|APP_URL=$BASE|" \
    -e "s|^DB_HOST=.*|DB_HOST=127.0.0.1|" \
    -e "s|^DB_DATABASE=.*|DB_DATABASE=qatysu_smoke|" \
    -e "s|^DB_USERNAME=.*|DB_USERNAME=qatysu|" \
    -e "s|^DB_PASSWORD=.*|DB_PASSWORD=devonly|" \
    -e "s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=false|" \
    .env.example.production > .env

echo "== Орнату командасы (1-рет)"
php artisan qatysu:install zavuch "Сынақ Завуч" | tee "$WORK/install.log"
PASSWORD="$(sed -n 's/^Бір реттік пароль: //p' "$WORK/install.log")"
[ -n "$PASSWORD" ] || { echo "ҚАТЕ: бір реттік пароль шықпады" >&2; exit 1; }

echo "== Орнату командасы (қайталау қауіпсіз болуы тиіс)"
php artisan qatysu:install | grep -E "ОРНАТУ СӘТТІ|Әкімші аккаунты бар"

php artisan serve --host=127.0.0.1 --port="$PORT" >"$WORK/server.log" 2>&1 &
trap 'pkill -f "127.0.0.1:$PORT" 2>/dev/null || true; rm -rf "$WORK"' EXIT
sleep 3

fail() { echo "ҚАТЕ: $1" >&2; exit 1; }
code() { curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -H 'Accept: application/json' -H "Origin: $BASE" "$@"; }
xsrf() { python3 -c "import urllib.parse; [print(urllib.parse.unquote(l.split('\t')[6].strip())) for l in open('$JAR') if 'XSRF-TOKEN' in l]"; }

echo "== HTTP тексерістері"
curl -s "$BASE/" | grep -q '<div id="root">' || fail "басты бет интерфейсті қайтармады"
curl -s "$BASE/kez-kelgen/bet" | grep -q '<div id="root">' || fail "ішкі мекенжай интерфейсті қайтармады"
ASSET="$(curl -s "$BASE/" | grep -o '/assets/[^"]*\.js' | head -1)"
[ "$(code "$BASE$ASSET")" = 200 ] || fail "интерфейс файлы ($ASSET) берілмеді"
[ "$(code "$BASE/api/auth/me")" = 401 ] || fail "кірмеген адамға 401 қайтпады"
[ "$(code "$BASE/api/zhoq")" = 404 ] || fail "жоқ API жолына 404 қайтпады"
# Жасырын файлдар сырттан оқылмауы тиіс (мекенжай интерфейс бетін қайтарса, бұл қалыпты).
for secret in .env .env.example.production composer.json storage/logs/laravel.log; do
  if curl -s "$BASE/$secret" | grep -qE 'DB_PASSWORD|DB_USERNAME|"require"|production\.'; then
    fail "$secret файлы сырттан оқылып тұр"
  fi
done

code "$BASE/sanctum/csrf-cookie" >/dev/null
BODY="$(python3 -c "import json,sys; print(json.dumps({'login':'zavuch','password':sys.argv[1]}))" "$PASSWORD")"
[ "$(code -X POST -H 'Content-Type: application/json' -H "X-XSRF-TOKEN: $(xsrf)" -d "$BODY" "$BASE/api/auth/login")" = 200 ] || fail "бір реттік парольмен кіру сәтсіз"

echo "== Парольді қайта беру"
NEW="$(php artisan qatysu:reset-password zavuch | sed -n 's/^Бір реттік пароль: //p')"
[ -n "$NEW" ] && [ "$NEW" != "$PASSWORD" ] || fail "жаңа бір реттік пароль шықпады"
[ "$(code "$BASE/api/auth/me")" = 401 ] || fail "пароль қайта берілгеннен кейін ескі сеанс жабылмады"
BODY="$(python3 -c "import json,sys; print(json.dumps({'login':'zavuch','password':sys.argv[1]}))" "$NEW")"
code "$BASE/sanctum/csrf-cookie" >/dev/null
[ "$(code -X POST -H 'Content-Type: application/json' -H "X-XSRF-TOKEN: $(xsrf)" -d "$BODY" "$BASE/api/auth/login")" = 200 ] || fail "жаңа бір реттік парольмен кіру сәтсіз"
curl -s -b "$JAR" -H 'Accept: application/json' -H "Origin: $BASE" "$BASE/api/auth/me" | grep -q '"must_change_password":true' || fail "кіргеннен кейін пайдаланушы қайтпады"

grep -q "production.ERROR" storage/logs/*.log 2>/dev/null && fail "серверлік журналда қате бар" || true
echo "== Архив тексерістен өтті: $(basename "$ZIP")"
