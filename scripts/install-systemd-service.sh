#!/usr/bin/env bash
set -euo pipefail

SERVICE_NAME="oracly-docker.service"
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE_FILE="${PROJECT_DIR}/deploy/systemd/${SERVICE_NAME}"
TARGET_FILE="/etc/systemd/system/${SERVICE_NAME}"

if [[ ! -f "${SOURCE_FILE}" ]]; then
  echo "Arquivo de service não encontrado: ${SOURCE_FILE}" >&2
  exit 1
fi

sudo cp "${SOURCE_FILE}" "${TARGET_FILE}"
sudo systemctl daemon-reload
sudo systemctl enable --now "${SERVICE_NAME}"
sudo systemctl status "${SERVICE_NAME}" --no-pager
