#!/usr/bin/env bash
set -euo pipefail

OUT_DIR="${1:-dist}"
OUT_FILE="${2:-tacam-static-app.zip}"

mkdir -p "$OUT_DIR"

zip -r "$OUT_DIR/$OUT_FILE" \
  index.html app.js styles.css delivery-core.js \
  manifest.webmanifest register-sw.js sw.js .htaccess \
  assets README.md \
  storage-sync.php brevo-email.php \
  -x "*.DS_Store"

echo "Paquete generado en: $OUT_DIR/$OUT_FILE"
