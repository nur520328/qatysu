#!/usr/bin/env bash
# Backend тесттерін іске қосып, қысқа қорытынды шығарады (сәтсіз тесттің аты мен бірінші жолы).
set -uo pipefail
cd "$(dirname "$0")/../backend"
../scripts/dev-db.sh >/dev/null
php artisan test 2>&1 | python3 -c '
import sys, json
raw = sys.stdin.read()
start = raw.rfind("{\"tool\":\"phpunit\"")
if start < 0:
    print(raw[-1500:]); sys.exit(1)
r = json.loads(raw[start:])
print("backend: %s, %d/%d тест өтті, %d тексеру" % (r["result"], r["passed"], r["tests"], r["assertions"]))
for f in r.get("failures", []) + r.get("error_details", []):
    message = (f.get("message") or "").strip().splitlines() or [""]
    print(" ✗ %s:%s  %s" % (f.get("test", "").split("\\")[-1], f.get("line"), message[0][:200]))
sys.exit(0 if r["result"] == "passed" else 1)
'
