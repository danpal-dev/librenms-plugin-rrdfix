#!/usr/bin/env bash
# ============================================================================
# LibreNMS Plugin · RrdFix — instalador de release desde tarball
# Ejecución de 1-comando EN EL SERVIDOR DESTINO (p.ej. moni2):
#
#   bash <(curl -fsSL https://raw.githubusercontent.com/danpal-dev/librenms-plugin-rrdfix/master/scripts/install-release.sh)
#
# O si prefiere FULL (con .git para git pull posteriores):
#
#   FLAVOR=full bash <(curl -fsSL https://raw.githubusercontent.com/danpal-dev/librenms-plugin-rrdfix/master/scripts/install-release.sh)
# ============================================================================
set -euo pipefail

# -------- CONFIGURACIÓN -------------------------------------------------------
OWNER="danpal-dev"
REPO="librenms-plugin-rrdfix"
VERSION="${VERSION:-v1.1.1}"
FLAVOR="${FLAVOR:-lean}"   # "lean" (sin .git) ó "full" (con .git)
PLUGIN_NAME="RrdFix"
LIBRENMS_DIR="${LIBRENMS_DIR:-/opt/librenms}"
ASSET="rrdfix-RrdFix-${VERSION}-${FLAVOR}.tar.gz"
URL="https://github.com/${OWNER}/${REPO}/releases/download/${VERSION}/${ASSET}"
SHA_URL="https://github.com/${OWNER}/${REPO}/releases/download/${VERSION}/${ASSET}.sha256"

# -------- HELPERS ------------------------------------------------------------
info()  { printf '\033[1;34m[INFO]\033[0m  %s\n' "$*"; }
ok()    { printf '\033[1;32m[ OK ]\033[0m  %s\n' "$*"; }
warn()  { printf '\033[1;33m[WARN]\033[0m  %s\n' "$*"; }
err()   { printf '\033[1;31m[ERR ]\033[0m  %s\n' "$*" >&2; }

require_root_or_librenms() {
    local uid
    uid=$(id -u)
    if [[ "$uid" -eq 0 ]]; then
        return 0
    fi
    local librenms_uid
    librenms_uid=$(id -u librenms 2>/dev/null || echo "")
    if [[ -n "$librenms_uid" && "$uid" == "$librenms_uid" ]]; then
        return 0
    fi
    err "Ejecuta este script como root o como usuario 'librenms'."
    exit 1
}

run_as_librenms() {
    if [[ "$(id -u)" -eq 0 ]]; then
        sudo -u librenms -- "$@"
    else
        "$@"
    fi
}

# -------- CHECKS INICIALES ---------------------------------------------------
info "RrdFix release installer · version=${VERSION} flavor=${FLAVOR}"
require_root_or_librenms

if ! command -v curl >/dev/null 2>&1; then
    err "Instala 'curl' antes de continuar."
    exit 2
fi

if ! command -v php >/dev/null 2>&1; then
    err "PHP no está disponible en PATH. Asegúrate de estar en un servidor LibreNMS."
    exit 2
fi

if [[ ! -d "$LIBRENMS_DIR/app/Plugins" ]]; then
    err "No existe ${LIBRENMS_DIR}/app/Plugins. Define LIBRENMS_DIR=/ruta/a/librenms si la ruta no es la estándar."
    exit 2
fi

if ! id librenms >/dev/null 2>&1; then
    err "Usuario 'librenms' no existe en el sistema."
    exit 2
fi

TMPDIR=$(mktemp -d -t rrdfix-install-XXXXXX)
trap 'rm -rf "$TMPDIR"' EXIT

# -------- DESCARGAR -----------------------------------------------------------
info "Descargando ${URL} ..."
curl -fSL --retry 3 --retry-delay 1 --max-time 120 "$URL" -o "$TMPDIR/$ASSET"
ok "Descargado $(du -h "$TMPDIR/$ASSET" | cut -f1)"

if curl -fSL --retry 2 --max-time 30 "$SHA_URL" -o "$TMPDIR/$ASSET.sha256" 2>/dev/null; then
    info "Verificando SHA256 ..."
    EXPECTED=$(awk '{print $1}' "$TMPDIR/$ASSET.sha256")
    ACTUAL=$(sha256sum "$TMPDIR/$ASSET" | awk '{print $1}')
    if [[ "$EXPECTED" != "$ACTUAL" ]]; then
        err "SHA256 no coincide. Esperado=${EXPECTED}  Actual=${ACTUAL}"
        exit 3
    fi
    ok "Checksum OK"
else
    warn "No se pudo descargar checksum — se continúa sin verificación."
fi

# -------- EXTRAER ------------------------------------------------------------
DEST="${LIBRENMS_DIR}/app/Plugins"
if [[ -d "${DEST}/${PLUGIN_NAME}" ]]; then
    BACKUP="${DEST}/${PLUGIN_NAME}.bak-$(date +%Y%m%d-%H%M%S)"
    warn "Ya existe ${DEST}/${PLUGIN_NAME}. Copiando backup a ${BACKUP} ..."
    cp -a "${DEST}/${PLUGIN_NAME}" "$BACKUP"
    rm -rf "${DEST}/${PLUGIN_NAME}"
fi

info "Extrayendo ${ASSET} en ${DEST}/${PLUGIN_NAME} ..."
tar -xzf "$TMPDIR/$ASSET" -C "$DEST"
# Nos aseguramos que la carpeta resultante se llama exactamente RrdFix
if [[ -d "${DEST}/RrdFixLean" ]]; then
    mv "${DEST}/RrdFixLean" "${DEST}/${PLUGIN_NAME}"
fi

chown -R librenms:librenms "${DEST}/${PLUGIN_NAME}"
if [[ -x "${DEST}/${PLUGIN_NAME}/rrd-fix.py" ]]; then
    chmod +x "${DEST}/${PLUGIN_NAME}/rrd-fix.py"
fi
ok "Archivos instalados con ownership correcto."

# -------- ACTIVAR EN BASE DE DATOS -------------------------------------------
info "Activando plugin en la base de datos LibreNMS ..."
run_as_librenms php "$LIBRENMS_DIR/artisan" tinker --execute="
    \App\Models\Plugin::updateOrCreate(
        ['plugin_name' => 'RrdFix', 'version' => 2],
        ['plugin_active' => 1, 'settings' => []]
    );
    echo 'OK - RrdFix activado\n';
"
ok "Plugin activado (version=2 · plugin_active=1)."

# -------- LIMPIAR CACHÉS -----------------------------------------------------
info "Limpiando cachés Laravel/LibreNMS ..."
run_as_librenms php "$LIBRENMS_DIR/artisan" optimize:clear

PHP_FPM=""
for fpm in php8.4-fpm php8.3-fpm php8.2-fpm php8.1-fpm php-fpm; do
    if systemctl is-active --quiet "$fpm" 2>/dev/null; then
        PHP_FPM="$fpm"
        break
    fi
done
if [[ -n "$PHP_FPM" ]]; then
    info "Recargando PHP-FPM: ${PHP_FPM}"
    if [[ "$(id -u)" -eq 0 ]]; then
        systemctl reload "$PHP_FPM" || warn "No se pudo recargar ${PHP_FPM} (ignorar)."
    else
        warn "No eres root — no se recargó ${PHP_FPM}. Haz: sudo systemctl reload ${PHP_FPM}"
    fi
fi

# -------- VERIFICAR ----------------------------------------------------------
echo
info "=== VERIFICACIÓN ==="
ACT=$(run_as_librenms php "$LIBRENMS_DIR/artisan" tinker --execute="
    \$p = \App\Models\Plugin::where('plugin_name','RrdFix')->first();
    echo (\$p?->plugin_active ?? 'NA') . PHP_EOL;
" 2>&1 | tail -n1)
if [[ "$ACT" == "1" ]]; then
    ok "plugin_active = 1"
else
    warn "plugin_active = ${ACT:-?} — revisa el log de LibreNMS si persiste."
fi

info "Test rápido autónomo del plugin:"
if [[ -x "${DEST}/${PLUGIN_NAME}/tests/run.php" ]] || [[ -f "${DEST}/${PLUGIN_NAME}/tests/run.php" ]]; then
    run_as_librenms php "${DEST}/${PLUGIN_NAME}/tests/run.php" 2>&1 | tail -n 5 || warn "tests/run.php devolvió error no bloqueante."
fi

echo
ok "INSTALACIÓN TERMINADA ✅"
info "Abre:  https://TU-LIBRENMS/plugin/RrdFix"
info "Si aparece 'Vista faltante' de nuevo — espera 1 minuto y recarga OPcache."
