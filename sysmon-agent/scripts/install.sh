#!/usr/bin/env bash
# ============================================================
#  SysMon Agent — Script de instalación
#  Ejecutar como root: sudo bash install.sh
# ============================================================

set -euo pipefail

# ---- Colores ----
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

info()    { echo -e "${CYAN}[INFO]${NC} $*"; }
success() { echo -e "${GREEN}[OK]${NC}   $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC} $*"; }
error()   { echo -e "${RED}[ERR]${NC}  $*"; exit 1; }

# ---- Verificar root ----
[[ $EUID -ne 0 ]] && error "Este script debe ejecutarse como root (sudo bash install.sh)"

INSTALL_DIR="/opt/sysmon"
CONFIG_DIR="/etc/sysmon"
LOG_DIR="/var/log/sysmon"
SERVICE_NAME="sysmon-agent"
SERVICE_USER="sysmon"
PYTHON_MIN="3.10"

echo -e ""
echo -e "${BOLD}╔══════════════════════════════════════╗${NC}"
echo -e "${BOLD}║     SysMon Agent — Instalación       ║${NC}"
echo -e "${BOLD}╚══════════════════════════════════════╝${NC}"
echo ""

# ---- Verificar Python ----
info "Verificando Python ${PYTHON_MIN}+…"
PYTHON_BIN=$(command -v python3 || true)
[[ -z "$PYTHON_BIN" ]] && error "Python 3 no encontrado. Instala con: apt install python3"

PY_VER=$("$PYTHON_BIN" -c "import sys; print(f'{sys.version_info.major}.{sys.version_info.minor}')")
info "Python encontrado: $PY_VER"

# Comparar versiones
python3 -c "
import sys
v = sys.version_info
if (v.major, v.minor) < (3, 10):
    print('ERROR: Se requiere Python 3.10+')
    sys.exit(1)
" || error "Actualiza Python a 3.10 o superior"

# ---- Dependencias del sistema ----
info "Instalando dependencias del sistema…"
apt-get update -qq
apt-get install -y -qq python3-pip python3-venv lm-sensors || warn "Algunos paquetes no se instalaron"

# Detectar sensores de temperatura
if command -v sensors-detect &>/dev/null; then
    info "Ejecutando sensors-detect (no interactivo)…"
    sensors-detect --auto > /dev/null 2>&1 || true
fi

# ---- Crear usuario del sistema ----
if ! id "$SERVICE_USER" &>/dev/null; then
    info "Creando usuario del sistema '$SERVICE_USER'…"
    useradd --system --no-create-home --shell /usr/sbin/nologin "$SERVICE_USER"
    success "Usuario '$SERVICE_USER' creado"
else
    info "Usuario '$SERVICE_USER' ya existe"
fi

# ---- Directorios ----
info "Creando directorios…"
mkdir -p "$INSTALL_DIR" "$CONFIG_DIR" "$LOG_DIR"
chown -R "$SERVICE_USER:$SERVICE_USER" "$LOG_DIR"
chown root:"$SERVICE_USER" "$CONFIG_DIR"
chmod 750 "$CONFIG_DIR"

# ---- Copiar código fuente ----
info "Copiando agente a ${INSTALL_DIR}…"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cp -r "$SCRIPT_DIR/sysmon" "$INSTALL_DIR/"
chown -R "$SERVICE_USER:$SERVICE_USER" "$INSTALL_DIR"

# ---- Entorno virtual ----
info "Creando entorno virtual Python…"
python3 -m venv "$INSTALL_DIR/venv"

info "Instalando dependencias Python…"
"$INSTALL_DIR/venv/bin/pip" install --quiet --upgrade pip
"$INSTALL_DIR/venv/bin/pip" install --quiet psutil requests

success "Dependencias instaladas"

# ---- Configuración ----
if [[ ! -f "$CONFIG_DIR/agent.env" ]]; then
    info "Copiando configuración de ejemplo…"
    cp "$SCRIPT_DIR/agent.env.example" "$CONFIG_DIR/agent.env"
    chown root:"$SERVICE_USER" "$CONFIG_DIR/agent.env"
    chmod 640 "$CONFIG_DIR/agent.env"
    echo ""
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${YELLOW}  ACCIÓN REQUERIDA${NC}"
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "  Edita el archivo de configuración:"
    echo -e "  ${BOLD}sudo nano ${CONFIG_DIR}/agent.env${NC}"
    echo -e ""
    echo -e "  Configura al menos:"
    echo -e "    ${CYAN}API_URL${NC}     = URL de tu panel Laravel"
    echo -e "    ${CYAN}AGENT_TOKEN${NC} = Token generado desde el panel"
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""
else
    info "Configuración existente en ${CONFIG_DIR}/agent.env — no se sobreescribe"
fi

# ---- Servicio systemd ----
info "Instalando servicio systemd…"
cp "$SCRIPT_DIR/systemd/sysmon-agent.service" "/etc/systemd/system/${SERVICE_NAME}.service"
systemctl daemon-reload
systemctl enable "$SERVICE_NAME"
success "Servicio '${SERVICE_NAME}' habilitado en el arranque"

# ---- Resultado ----
echo ""
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}  Instalación completada ✓${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo -e "  Próximos pasos:"
echo -e "  1. Edita la config:  ${BOLD}sudo nano /etc/sysmon/agent.env${NC}"
echo -e "  2. Inicia el agente: ${BOLD}systemctl start sysmon-agent${NC}"
echo -e "  3. Ver logs:         ${BOLD}journalctl -u sysmon-agent -f${NC}"
echo -e "  4. Estado:           ${BOLD}systemctl status sysmon-agent${NC}"
echo ""
