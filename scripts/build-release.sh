#!/usr/bin/env bash
# Хостингке жүктеуге дайын архив жинайды: dist/qatysu-<нұсқа>.zip
# Архивтің ішінде әзірлеу құралдары, тесттер және құпия мәндер болмайды.
# Қолданылуы: scripts/build-release.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(tr -d '[:space:]' < "$ROOT/VERSION")"
STAGE="$(mktemp -d /tmp/qatysu-release.XXXXXX)"
OUT="$ROOT/dist/qatysu-$VERSION.zip"
export COMPOSER_ALLOW_SUPERUSER=1

echo "== Нұсқа: $VERSION"

echo "== 1/4 Интерфейсті жинау"
cd "$ROOT/frontend"
npm ci --no-audit --no-fund --silent
npm run build --silent

echo "== 2/4 Сервер файлдарын көшіру"
tar -C "$ROOT/backend" \
  --exclude='./.env' --exclude='./.env.example' --exclude='./.env.backup' \
  --exclude='./vendor' --exclude='./node_modules' --exclude='./tests' \
  --exclude='./phpunit.xml' --exclude='./.phpunit*' --exclude='./.editorconfig' \
  --exclude='./.gitattributes' --exclude='./pint.json' \
  --exclude='./.gitignore' --exclude='./.npmrc' --exclude='./.styleci.yml' \
  --exclude='./storage/logs/*.log' \
  --exclude='./storage/app/backups' --exclude='./storage/app/install-marker.json' \
  --exclude='./storage/framework/cache/data/*' \
  --exclude='./storage/framework/sessions/*' \
  --exclude='./storage/framework/views/*' \
  --exclude='./storage/framework/testing' \
  --exclude='./bootstrap/cache/*.php' \
  --exclude='./public/index.html' --exclude='./public/assets' \
  -cf - . | tar -C "$STAGE" -xf -

# Бос қалталар архивте сақталуы үшін (сервер оларға жазады).
for dir in storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache; do
  mkdir -p "$STAGE/$dir"
  [ -e "$STAGE/$dir/.gitignore" ] || printf '*\n!.gitignore\n' > "$STAGE/$dir/.gitignore"
done

echo "== 3/4 Кітапханаларды орнату (әзірлеу құралдарынсыз)"
cd "$STAGE"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet

# Жүктеу тәсіліне қарай кітапханалар толық git тарихымен келуі мүмкін (жүздеген МБ).
# Серверге тарих керек емес, сондықтан ол алынады.
find vendor -name .git -prune -exec rm -rf {} +
find vendor -type d \( -name tests -o -name Tests -o -name docs -o -name .github \) -prune -exec rm -rf {} +
composer dump-autoload --no-dev --optimize --no-interaction --quiet
php artisan --version >/dev/null || { echo "ҚАТЕ: тазалаудан кейін қосымша іске қосылмады" >&2; exit 1; }

cp -R "$ROOT/frontend/dist/." "$STAGE/public/"
cp "$ROOT/VERSION" "$STAGE/VERSION"

# Қауіпсіздік тексерісі: архивке құпия не артық файл түспеуі тиіс.
for forbidden in .env tests phpunit.xml node_modules storage/app/backups storage/app/install-marker.json storage/logs/install-history.log; do
  if [ -e "$STAGE/$forbidden" ]; then
    echo "ҚАТЕ: архивте болмауы тиіс: $forbidden" >&2
    exit 1
  fi
done
if grep -rIl --exclude-dir=vendor -e 'devonly' "$STAGE" >/dev/null; then
  echo "ҚАТЕ: архивте әзірлеу паролі кездесті" >&2
  exit 1
fi
[ -f "$STAGE/public/index.html" ] || { echo "ҚАТЕ: интерфейс файлы жоқ" >&2; exit 1; }
[ -f "$STAGE/vendor/autoload.php" ] || { echo "ҚАТЕ: кітапханалар орнатылмаған" >&2; exit 1; }

echo "== 4/4 Архивтеу"
mkdir -p "$ROOT/dist"
rm -f "$OUT"
(cd "$STAGE" && zip -qr -X "$OUT" .)
rm -rf "$STAGE"

echo "Дайын: $OUT ($(du -h "$OUT" | cut -f1), $(unzip -l "$OUT" | tail -1 | awk '{print $2}') файл)"
