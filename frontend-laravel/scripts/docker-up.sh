#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if ! docker info >/dev/null 2>&1; then
  echo "Sem acesso ao Docker. Abra um terminal NOVO (ou: newgrp docker) e tente de novo."
  exit 1
fi
docker compose up -d --build
docker compose ps
echo
echo "Painel: http://127.0.0.1:8000/admin/login"
echo "Login:  admin@oracly.local / oraclyadmin"
