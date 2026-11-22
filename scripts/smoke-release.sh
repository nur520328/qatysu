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
# Интернетсіз жұмыс (PWA): Service Worker мен манифест сайттың түбірінен берілуі тиіс.
curl -s "$BASE/sw.js" | grep -q "precacheAndRoute" || fail "Service Worker берілмеді"
curl -s "$BASE/manifest.webmanifest" | grep -q '"start_url"' || fail "манифест берілмеді"
curl -s "$BASE/" | grep -q "registerSW.js" || fail "басты бетте Service Worker тіркелмеген"
curl -s "$BASE/kezekshi" | grep -q '<div id="root">' || fail "кезекші беті интерфейсті қайтармады"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/icons/icon-192.png")" = 200 ] || fail "белгіше берілмеді"
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

echo "== Кезекші: код беру, кодпен кіру, сыныпты белгілеу"
# Тексеріс кез келген күні, кез келген сағатта жүруі үшін: бүгін оқу күні, түстен кейінгі смена 23:00-ге дейін.
if [ "$(TZ=Asia/Almaty date +%H)" -lt 22 ]; then
  TODAY="$(TZ=Asia/Almaty date +%F)"
  YEAR_ID="$(mariadb -uroot -N qatysu_smoke -e "select id from academic_years limit 1")"
  [ "$(json PUT "/api/admin/academic-years/$YEAR_ID/calendar" "{\"from\":\"$TODAY\",\"to\":\"$TODAY\",\"is_school_day\":true,\"note\":null}")" = 200 ] || fail "күнтізбе өзгермеді"
  [ "$(json PATCH /api/admin/settings '{"afternoon_shift_end":1380}')" = 200 ] || fail "смена соңы сақталмады"
  DUTY_CODE="$(curl -s -b "$JAR" -c "$JAR" -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Origin: $BASE" -H "X-XSRF-TOKEN: $(xsrf)" -d "{\"date\":\"$TODAY\",\"shift\":\"afternoon\"}" "$BASE/api/admin/duty-codes" | python3 -c "import json,sys; print(json.load(sys.stdin)['code'])")" || fail "код берілмеді"
  curl -s -b "$JAR" -H 'Accept: application/json' -H "Origin: $BASE" "$BASE/api/admin/duty-codes" | grep -q "\"code\":\"$DUTY_CODE\"" || fail "завуч кодты қайта көре алмады"
  mariadb -uroot -N qatysu_smoke -e "select coalesce(code_encrypted,'') from duty_codes" | grep -q "$DUTY_CODE" && fail "код дерекқорда ашық мәтінмен жатыр" || true
  # Планшет: бөлек браузер (бөлек cookie).
  TAB="$WORK/tablet.txt"
  tab() { curl -s -o "$WORK/tab.json" -w '%{http_code}' -b "$TAB" -c "$TAB" -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Origin: $BASE" "$@"; }
  txsrf() { python3 -c "import urllib.parse; [print(urllib.parse.unquote(l.split('\t')[6].strip())) for l in open('$TAB') if 'XSRF-TOKEN' in l]"; }
  tab "$BASE/sanctum/csrf-cookie" >/dev/null
  [ "$(tab -X POST -H "X-XSRF-TOKEN: $(txsrf)" -d '{"code":"000000"}' "$BASE/api/duty/login")" = 422 ] || fail "қате код қабылданды"
  [ "$(tab -X POST -H "X-XSRF-TOKEN: $(txsrf)" -d "{\"code\":\"$DUTY_CODE\"}" "$BASE/api/duty/login")" = 200 ] || fail "кодпен кіру сәтсіз"
  CLASS_ID="$(python3 -c "import json; print(json.load(open('$WORK/tab.json'))['classes'][-1]['id'])")"
  [ "$(tab "$BASE/api/duty/classes/$CLASS_ID")" = 200 ] || fail "сынып тізімі ашылмады"
  STUDENT_ID="$(python3 -c "import json; print(json.load(open('$WORK/tab.json'))['students'][0]['id'])")"
  [ "$(tab -X PUT -H "X-XSRF-TOKEN: $(txsrf)" -d "{\"client_uuid\":\"11111111-1111-4111-8111-111111111111\",\"absent_student_ids\":[$STUDENT_ID]}" "$BASE/api/duty/classes/$CLASS_ID/round")" = 200 ] || fail "аралау сақталмады"
  grep -q '"absent_count":1' "$WORK/tab.json" || fail "аралау нәтижесі қате"
  [ "$(tab "$BASE/api/admin/classes")" = 401 ] || fail "кезекшіге әкімші API ашық"
  [ "$(mariadb -uroot -N qatysu_smoke -e "select count(*) from attendance_records")" = 2 ] || fail "қатысу жазбаларының саны қате"
  # Мұғалім мен жетекші (6-кезең): кешігуді тіркеу, себеп қою, тарих.
  OTHER_ID="$(mariadb -uroot -N qatysu_smoke -e "select student_id from attendance_records where student_id <> $STUDENT_ID limit 1")"
  [ "$(json POST "/api/teacher/students/$OTHER_ID/leave" '{"lesson":4}')" = 200 ] || fail "кетіп қалу тіркелмеді"
  [ "$(json PATCH "/api/homeroom/classes/$CLASS_ID/days/$TODAY/students/$STUDENT_ID" '{"absence_reason":"sick"}')" = 200 ] || fail "себеп қойылмады"
  [ "$(json PATCH "/api/homeroom/classes/$CLASS_ID/days/$TODAY/students/$OTHER_ID" '{"absence_reason":"sick"}')" = 422 ] || fail "сәйкессіз себеп қабылданды"
  curl -s -b "$JAR" -H 'Accept: application/json' -H "Origin: $BASE" "$BASE/api/homeroom/pending" | grep -q '"kind":"permission"' || fail "анықталмағандар тізімі қате"
  curl -s -b "$JAR" -H 'Accept: application/json' -H "Origin: $BASE" "$BASE/api/homeroom/classes/$CLASS_ID/days/$TODAY/students/$STUDENT_ID/history" | grep -q '"field":"absence_reason"' || fail "тарих жазылмады"
  # Күнделікті жиынтық (7-кезең): бір сынып аралынды, бір оқушы ауырды, бір оқушы кетіп қалды.
  curl -s -b "$JAR" -H 'Accept: application/json' -H "Origin: $BASE" "$BASE/api/admin/daily-summary" > "$WORK/summary.json"
  python3 - "$WORK/summary.json" <<'PY' || fail "күнделікті жиынтық сандары қате"
import json, sys
shifts = json.load(open(sys.argv[1]))["shifts"]
total = lambda key: sum(s[key] for s in shifts)
assert total("classes_surveyed") == 1 and total("absent") == 1 and total("left") == 1 and total("late") == 0, shifts
assert sum(s["absent_by_reason"]["sick"] for s in shifts) == 1 and sum(s["left_by_permission"]["pending"] for s in shifts) == 1
PY
else
  echo "   (22:00-ден кейін: кезекші тексерісі өткізілмеді, смена уақыты жетпейді)"
fi
# Жиынтық беті кез келген уақытта ашылады; болашақ күн мен кірмеген адамға ашылмайды.
[ "$(curl -s -o "$WORK/summary.json" -w '%{http_code}' -b "$JAR" -H 'Accept: application/json' -H "Origin: $BASE" "$BASE/api/admin/daily-summary")" = 200 ] || fail "күнделікті жиынтық ашылмады"
grep -q '"shifts":\[' "$WORK/summary.json" || fail "күнделікті жиынтық жауабы қате"
[ "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -H 'Accept: application/json' -H "Origin: $BASE" "$BASE/api/admin/daily-summary?date=2099-01-01")" = 422 ] || fail "болашақ күннің жиынтығы ашылды"
[ "$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$BASE/api/admin/daily-summary")" = 401 ] || fail "жиынтық кірмеген адамға ашық"
# Апталық есеп (8-кезең): бет дерегі, Excel-дің екі нұсқасы, үлгі файл, бекіту командасы.
[ "$(curl -s -o "$WORK/weekly.json" -w '%{http_code}' -b "$JAR" -H 'Accept: application/json' -H "Origin: $BASE" "$BASE/api/admin/reports/weekly")" = 200 ] || fail "апталық есеп ашылмады"
grep -q '"class_days_expected"' "$WORK/weekly.json" || fail "апталық есеп жауабы қате"
for names in 1 0; do
  [ "$(curl -s -o "$WORK/weekly-$names.xlsx" -w '%{http_code}' -b "$JAR" -H "Origin: $BASE" "$BASE/api/admin/reports/weekly/export?names=$names")" = 200 ] || fail "апталық есептің Excel файлы жүктелмеді (names=$names)"
  python3 -c "import sys, zipfile; z = zipfile.ZipFile(sys.argv[1]); assert z.testzip() is None; assert len([n for n in z.namelist() if n.startswith('xl/worksheets/sheet')]) == int(sys.argv[2])" "$WORK/weekly-$names.xlsx" "$([ "$names" = 1 ] && echo 5 || echo 3)" || fail "апталық есептің Excel файлы бүлінген (names=$names)"
done
[ "$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$BASE/api/admin/reports/weekly/export")" = 401 ] || fail "есеп файлы кірмеген адамға ашық"
# Айлық есеп (9-кезең) және сыныптың айлық қорытындысы.
[ "$(curl -s -o "$WORK/monthly.json" -w '%{http_code}' -b "$JAR" -H 'Accept: application/json' -H "Origin: $BASE" "$BASE/api/admin/reports/monthly")" = 200 ] || fail "айлық есеп ашылмады"
grep -q '"grades":\[' "$WORK/monthly.json" || fail "айлық есепте параллельдер жоқ"
[ "$(curl -s -o "$WORK/monthly.xlsx" -w '%{http_code}' -b "$JAR" -H "Origin: $BASE" "$BASE/api/admin/reports/monthly/export")" = 200 ] || fail "айлық есептің Excel файлы жүктелмеді"
python3 -c "import sys, zipfile; z = zipfile.ZipFile(sys.argv[1]); assert z.testzip() is None; assert len([n for n in z.namelist() if n.startswith('xl/worksheets/sheet')]) == 7" "$WORK/monthly.xlsx" || fail "айлық есептің Excel файлы бүлінген"
ANY_CLASS="$(mariadb -uroot -N qatysu_smoke -e "select id from classes order by id limit 1")"
[ "$(curl -s -o "$WORK/class-month.json" -w '%{http_code}' -b "$JAR" -H 'Accept: application/json' -H "Origin: $BASE" "$BASE/api/homeroom/classes/$ANY_CLASS/month")" = 200 ] || fail "сыныптың айлық қорытындысы ашылмады"
grep -q '"students":\[' "$WORK/class-month.json" || fail "сыныптың айлық қорытындысы жауабы қате"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/templates/oqushylar-ulgi.xlsx")" = 200 ] || fail "оқушылар үлгісі жоқ"
php artisan qatysu:freeze-reports >/dev/null || fail "есепті бекіту командасы құлады"
php artisan schedule:list | grep -q "qatysu:freeze-reports" || fail "есепті бекіту кестеде жоқ"

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
# Сынақ дерегін тазалау: растаусыз тоқтайды; растаумен көшірме жасап, мектеп дерегін өшіреді, завуч қалады.
sleep 1
(php artisan qatysu:clear-data || true) | grep -q "ЕШТЕҢЕ ӨШІРІЛГЕН ЖОҚ" || fail "растаусыз тазалау тоқтамады"
[ "$(mariadb -uroot -N qatysu_smoke -e "select count(*) from students")" = 2 ] || fail "растаусыз тазалау дерекке тиді"
BEFORE_CLEAR="$(ls storage/app/backups/*.sql.gz | wc -l)"
php artisan qatysu:clear-data --yes | grep -q "ТАЗАЛАНДЫ" || fail "тазалау сәтсіз"
[ "$(ls storage/app/backups/*.sql.gz | wc -l)" = "$((BEFORE_CLEAR + 1))" ] || fail "тазалау алдында көшірме жасалмады"
[ "$(mariadb -uroot -N qatysu_smoke -e "select (select count(*) from students) + (select count(*) from classes) + (select count(*) from attendance_records) + (select count(*) from users where role <> 'admin')")" = 0 ] || fail "тазалаудан кейін мектеп дерегі қалды"
[ "$(mariadb -uroot -N qatysu_smoke -e "select count(*) from users where role = 'admin'")" -ge 1 ] || fail "тазалау завуч аккаунтын өшірді"
[ "$(mariadb -uroot -N qatysu_smoke -e "select count(*) from academic_years")" = 1 ] || fail "тазалау оқу жылын өшірді"
php artisan schedule:list | grep -q "qatysu:backup" || fail "түнгі көшірме кестеде жоқ"
php artisan schedule:run >/dev/null || fail "schedule:run іске қосылмады"
[ -s storage/logs/install-history.log ] || fail "орнату күнделігі жазылмады"

grep -q "production.ERROR" storage/logs/*.log 2>/dev/null && fail "серверлік журналда қате бар" || true
# Журнал тексерілгеннен кейін: бұл команда әдейі қатемен аяқталуы тиіс.
php artisan db:seed --class=DemoSeeder --force >/dev/null 2>&1 && fail "жасанды дерек өндірістік ортада жүктелді" || true
echo "== Архив тексерістен өтті: $(basename "$ZIP")"
