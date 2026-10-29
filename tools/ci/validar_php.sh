#!/usr/bin/env bash
# Valida sintaxe de todos os .php (mesmo padrao do ERP). Uso: docker run --rm -v "$PWD:/app" -w /app php:7.4-cli bash tools/ci/validar_php.sh
set -euo pipefail
falhas=0
while IFS= read -r -d '' f; do
  if ! php -l "$f" >/dev/null; then
    echo "ERRO de sintaxe: $f"
    falhas=$((falhas + 1))
  fi
done < <(find index.php sistema tools -name '*.php' -print0)
if [ "$falhas" -gt 0 ]; then
  echo "$falhas arquivo(s) com erro."
  exit 1
fi
echo "Sintaxe PHP OK."
