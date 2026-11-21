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

echo "== Завуч жұмысы (3-кезең): жыл, сыныптар, Excel-ден жүктеу"
json() { code -X "$1" -H 'Content-Type: application/json' -H "X-XSRF-TOKEN: $(xsrf)" -d "$3" "$BASE$2"; }
[ "$(code "$BASE/api/admin/classes")" = 403 ] || fail "бір реттік парольмен әкімші API ашық"
BODY="$(python3 -c "import json,sys; print(json.dumps({'current_password':sys.argv[1],'new_password':'smoke-parol-7'}))" "$NEW")"
[ "$(json POST /api/auth/password "$BODY")" = 200 ] || fail "пароль ауыспады"
[ "$(json POST /api/admin/academic-years '{"name":"2026–2027","start_date":"2026-09-01","end_date":"2027-05-25"}')" = 201 ] || fail "оқу жылы ашылмады"
[ "$(json POST /api/admin/classes/bulk '{"grade":7,"letters":["А","Ә"],"shift":"afternoon"}')" = 201 ] || fail "сыныптар ашылмады"
python3 - "$WORK" <<'PY'
import sys
from openpyxl import Workbook
wb = Workbook(); ws = wb.active; ws.title = '7Ә'
ws.append(['№', 'Тегі', 'Аты', 'Әкесінің аты', 'Оқу тілі', 'Сынып'])
ws.append([1, 'Сынақов', 'Бірінші', None, 'қазақ', '7Ә'])
ws.append([2, 'Әлібеков', 'Екінші', 'Үлгіұлы', 'қазақ', '7Ә'])
wb.save(sys.argv[1] + '/oqushylar.xlsx')
wb = Workbook(); ws = wb.active
ws.append(['№', 'Тегі', 'Аты', 'Әкесінің аты', 'Жетекші сыныбы', 'Логин'])
ws.append([1, 'Жасандыева', 'Айгүл', None, '7Ә', None])
wb.save(sys.argv[1] + '/mugalimder.xlsx')
PY
upload() { curl -s -b "$JAR" -c "$JAR" -H 'Accept: application/json' -H "Origin: $BASE" -H "X-XSRF-TOKEN: $(xsrf)" "$@"; }
digest() { python3 -c "import json,sys; print(json.load(sys.stdin)['digest'])"; }
D="$(upload -F "file=@$WORK/oqushylar.xlsx" "$BASE/api/admin/import/students" | digest)" || fail "оқушылар файлы оқылмады (Excel кітапханасы архивте жұмыс істемейді?)"
upload -F "file=@$WORK/oqushylar.xlsx" --form-string "digest=$D" "$BASE/api/admin/import/students" | grep -q '"imported":2' || fail "оқушылар жүктелмеді"
D="$(upload -F "file=@$WORK/mugalimder.xlsx" "$BASE/api/admin/import/teachers" | digest)" || fail "мұғалімдер файлы оқылмады"
upload -F "file=@$WORK/mugalimder.xlsx" --form-string "digest=$D" "$BASE/api/admin/import/teachers" | grep -q '"login":"zhasandyeva.a"' || fail "мұғалім жүктелмеді"
curl -s -b "$JAR" -H 'Accept: application/json' -H "Origin: $BASE" "$BASE/api/admin/classes" | grep -q '"student_count":2' || fail "сыныпта оқушы саны қате"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/templates/mugalimder-ulgi.xlsx")" = 200 ] || fail "мұғалімдер үлгі файлы берілмеді"

echo "== Сақтық көшірме: жасау, жүктеу, қалпына келтіру, кесте"
php artisan qatysu:backup | grep -q "КӨШІРМЕ ЖАСАЛДЫ" || fail "көшірме жасалмады"
BACKUP="$(curl -s -b "$JAR" -H 'Accept: application/json' -H "Origin: $BASE" "$BASE/api/admin/backups" | python3 -c "import json,sys; d=json.load(sys.stdin); assert d['stale'] is False; print(d['backups'][0]['name'])")" || fail "көшірмелер тізімі қате"
curl -s -b "$JAR" -H "Origin: $BASE" "$BASE/api/admin/backups/$BACKUP/download" | gunzip -c | head -1 | grep -q "^-- qatysu-backup " || fail "көшірме жүктелмеді"
[ "$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$BASE/api/admin/backups/$BACKUP/download")" = 401 ] || fail "көшірме кірмеген адамға ашық"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/storage/app/backups/$BACKUP")" != 200 ] || fail "көшірме файлы тікелей мекенжаймен ашылды"
[ "$(json POST /api/admin/classes/bulk '{"grade":8,"letters":["А"],"shift":"afternoon"}')" = 201 ] || fail "сынып ашылмады"
sleep 1
# Растаусыз команда әдейі қате кодымен аяқталады.
(php artisan qatysu:restore "$BACKUP" || true) | grep -q "ЕШТЕҢЕ ӨЗГЕРТІЛГЕН ЖОҚ" || fail "растаусыз қалпына келтіру тоқтамады"
php artisan qatysu:restore "$BACKUP" --yes | grep -q "ҚАЛПЫНА КЕЛТІРІЛДІ" || fail "қалпына келтіру сәтсіз"
[ "$(mariadb -uroot -N qatysu_smoke -e "select count(*) from classes")" = 2 ] || fail "қалпына келтіруден кейін сынып саны қате"
[ "$(mariadb -uroot -N qatysu_smoke -e "select count(*) from students")" = 2 ] || fail "қалпына келтіруден кейін оқушы саны қате"
php artisan schedule:list | grep -q "qatysu:backup" || fail "түнгі көшірме кестеде жоқ"
php artisan schedule:run >/dev/null || fail "schedule:run іске қосылмады"
[ -s storage/logs/install-history.log ] || fail "орнату күнделігі жазылмады"

grep -q "production.ERROR" storage/logs/*.log 2>/dev/null && fail "серверлік журналда қате бар" || true
# Журнал тексерілгеннен кейін: бұл команда әдейі қатемен аяқталуы тиіс.
php artisan db:seed --class=DemoSeeder --force >/dev/null 2>&1 && fail "жасанды дерек өндірістік ортада жүктелді" || true
echo "== Архив тексерістен өтті: $(basename "$ZIP")"
