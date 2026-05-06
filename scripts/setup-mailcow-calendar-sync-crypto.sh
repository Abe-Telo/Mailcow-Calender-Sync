#!/usr/bin/env bash
set -euo pipefail

MAILCOW_DIR="${1:-/opt/mailcow-dockerized}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

ENV_TEMPLATE="${REPO_DIR}/mailcow-calendar-sync.env.example"
OVERRIDE_TEMPLATE="${REPO_DIR}/docker-compose.override.yml.example"
ENV_FILE="${MAILCOW_DIR}/mailcow-calendar-sync.env"
OVERRIDE_FILE="${MAILCOW_DIR}/docker-compose.override.yml"
MERGE_SNIPPET_FILE="${MAILCOW_DIR}/docker-compose.override.calendar-sync-snippet.yml"

fail() {
  echo "ERROR: $*" >&2
  exit 1
}

info() {
  echo "[calendar-sync] $*"
}

require_file() {
  local path="$1"
  [[ -f "${path}" ]] || fail "Required file not found: ${path}"
}

require_command() {
  local name="$1"
  command -v "${name}" >/dev/null 2>&1 || fail "Required command not found: ${name}"
}

set_crypto_key() {
  local key
  key="$(openssl rand -base64 32 | tr -d '\r\n')"

  if grep -q '^MC_CALSYNC_CRYPTO_KEY=' "${ENV_FILE}"; then
    sed -i.bak "s#^MC_CALSYNC_CRYPTO_KEY=.*#MC_CALSYNC_CRYPTO_KEY=${key}#" "${ENV_FILE}"
    rm -f "${ENV_FILE}.bak"
  else
    printf '\nMC_CALSYNC_CRYPTO_KEY=%s\n' "${key}" >> "${ENV_FILE}"
  fi
}

ensure_env_file() {
  if [[ ! -f "${ENV_FILE}" ]]; then
    cp "${ENV_TEMPLATE}" "${ENV_FILE}"
    info "Created ${ENV_FILE}"
  fi

  if ! grep -q '^MC_CALSYNC_CRYPTO_KEY=' "${ENV_FILE}" || grep -q '^MC_CALSYNC_CRYPTO_KEY=replace-with-' "${ENV_FILE}"; then
    set_crypto_key
    info "Generated MC_CALSYNC_CRYPTO_KEY in ${ENV_FILE}"
  else
    info "MC_CALSYNC_CRYPTO_KEY already present in ${ENV_FILE}"
  fi
}

ensure_override_file() {
  if [[ ! -f "${OVERRIDE_FILE}" ]]; then
    cp "${OVERRIDE_TEMPLATE}" "${OVERRIDE_FILE}"
    info "Created ${OVERRIDE_FILE}"
    return 0
  fi

  if grep -q 'mailcow-calendar-sync.env' "${OVERRIDE_FILE}"; then
    info "${OVERRIDE_FILE} already references mailcow-calendar-sync.env"
    return 0
  fi

  cat > "${MERGE_SNIPPET_FILE}" <<'EOF'
services:
  php-fpm-mailcow:
    env_file:
      - ./mailcow-calendar-sync.env
EOF

  info "Existing ${OVERRIDE_FILE} does not reference mailcow-calendar-sync.env."
  info "Created merge snippet: ${MERGE_SNIPPET_FILE}"
  info "Merge that env_file block into the php-fpm-mailcow service, then rerun this script."
  exit 2
}

verify_container_env() {
  local result
  result="$(docker compose exec -T php-fpm-mailcow sh -lc 'if [ -n "${MC_CALSYNC_CRYPTO_KEY:-}" ]; then echo MC_CALSYNC_CRYPTO_KEY_PRESENT; else echo MC_CALSYNC_CRYPTO_KEY_MISSING; fi')"

  if [[ "${result}" != "MC_CALSYNC_CRYPTO_KEY_PRESENT" ]]; then
    fail "php-fpm-mailcow did not receive MC_CALSYNC_CRYPTO_KEY"
  fi

  info "Verified MC_CALSYNC_CRYPTO_KEY inside php-fpm-mailcow"
}

main() {
  [[ -d "${MAILCOW_DIR}" ]] || fail "Mailcow directory not found: ${MAILCOW_DIR}"

  require_file "${ENV_TEMPLATE}"
  require_file "${OVERRIDE_TEMPLATE}"
  require_command openssl
  require_command docker

  ensure_env_file
  ensure_override_file

  cd "${MAILCOW_DIR}"
  info "Restarting php-fpm-mailcow and nginx-mailcow"
  docker compose up -d php-fpm-mailcow nginx-mailcow
  verify_container_env

  info "Done. Reopen calendar_sync.html as Mailcow admin and save the provider setup again."
}

main "$@"
