#!/usr/bin/env bash
# Uji tampilan BIAYA & AKTIVASI (Playwright) memakai SALINAN aplikasi di /tmp.
set -eu
DIR="$(cd "$(dirname "$0")" && pwd)"
KERJA=/tmp/cbt_akt_ui
PORT="${CBT_UI_PORT:-}"
if [ -z "$PORT" ]; then
  for p in $(seq 8841 8870); do
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$p") 2>/dev/null; then PORT=$p; break; fi
    exec 3<&- 2>/dev/null || true
  done
fi
BASE="http://127.0.0.1:$PORT"
SANDI=uji_ui_akt

echo "== Menyiapkan salinan aplikasi di $KERJA =="
rm -rf "$KERJA"; mkdir -p "$KERJA"
cp -r "$DIR"/. "$KERJA"/
rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
mkdir -p "$KERJA/data/sessions"; chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true
CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null
# sandi Super Admin ditetapkan pada SALINAN saja + keadaan sekolah dibuat tidak permanen
# supaya formulir kode aktivasi ikut teruji.
CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$KERJA"'/inc/init.php";
q("UPDATE users SET password = ? WHERE username = ?", [password_hash("'"$SANDI"'", PASSWORD_DEFAULT), "admin"]);
q("UPDATE sekolah SET aktivasi = ?, aktif_sampai = ?, aktivasi_pada = NULL, aktivasi_ke = 1 WHERE id = 1",
  ["percobaan", date("Y-m-d H:i:s", time() + 30 * 86400)]);'

php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > /tmp/cbt_akt_ui.log 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null || true' EXIT
for i in $(seq 1 25); do
  curl -s -o /dev/null "$BASE/login.php" && break
  sleep 0.4
done

gagal=0
BASE_URL="$BASE" CBT_SANDI_ADMIN="$SANDI" node "$DIR/check_cbt_aktivasi_biaya_ui.js" || gagal=1
grep -iE 'PHP (Warning|Notice|Fatal)|Uncaught' /tmp/cbt_akt_ui.log | head -5 || true
exit "$gagal"
