"""
SysMon Agent — Config
Lee la configuración desde /etc/sysmon/agent.env (o ruta personalizada).
Soporta valores por defecto y validación básica.
"""

import os
import sys
import logging
from pathlib import Path
from typing import Optional

logger = logging.getLogger("sysmon.config")

# Rutas donde buscar el archivo de configuración (en orden)
CONFIG_SEARCH_PATHS = [
    Path("/etc/sysmon/agent.env"),
    Path.home() / ".config" / "sysmon" / "agent.env",
    Path(__file__).parent.parent / "agent.env",  # junto al proyecto (dev)
]


class Config:
    """Configuración del agente leída desde archivo .env o variables de entorno."""

    # Campos requeridos
    REQUIRED = ["API_URL", "AGENT_TOKEN"]

    def __init__(self, env_file: Optional[str] = None):
        self._data: dict = {}
        self._load(env_file)
        self._validate()

    # ------------------------------------------------------------------
    # Carga
    # ------------------------------------------------------------------

    def _load(self, env_file: Optional[str]):
        """Carga el archivo .env y luego sobreescribe con variables de entorno."""
        path = self._find_config(env_file)

        if path:
            logger.info(f"Cargando config desde: {path}")
            self._parse_env_file(path)
        else:
            logger.warning("No se encontró archivo de config — usando sólo variables de entorno")

        # Las variables de entorno del sistema tienen mayor prioridad
        for key in list(self._data.keys()) + self.REQUIRED + self._optional_keys():
            env_val = os.environ.get(f"SYSMON_{key}")
            if env_val is not None:
                self._data[key] = env_val

    def _find_config(self, env_file: Optional[str]) -> Optional[Path]:
        if env_file:
            p = Path(env_file)
            return p if p.exists() else None

        for path in CONFIG_SEARCH_PATHS:
            if path.exists():
                return path

        return None

    def _parse_env_file(self, path: Path):
        with open(path) as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#"):
                    continue
                if "=" not in line:
                    continue
                key, _, value = line.partition("=")
                # Quitar comillas opcionales
                value = value.strip().strip('"').strip("'")
                self._data[key.strip()] = value

    # ------------------------------------------------------------------
    # Validación
    # ------------------------------------------------------------------

    def _validate(self):
        missing = [k for k in self.REQUIRED if not self._data.get(k)]
        if missing:
            logger.critical(f"Configuración incompleta. Faltan: {', '.join(missing)}")
            logger.critical("Edita /etc/sysmon/agent.env o configura las variables SYSMON_*")
            sys.exit(1)

    @staticmethod
    def _optional_keys() -> list:
        return [
            "INTERVAL_SECONDS", "LOG_LEVEL", "LOG_FILE",
            "SEND_PROCESSES", "PROCESSES_LIMIT",
            "CPU_WARN_THRESHOLD", "CPU_CRITICAL_THRESHOLD",
            "RAM_WARN_THRESHOLD", "RAM_CRITICAL_THRESHOLD",
            "TEMP_WARN_THRESHOLD", "TEMP_CRITICAL_THRESHOLD",
            "DISK_WARN_THRESHOLD",
            "MAX_QUEUE", "REQUEST_TIMEOUT",
            "AGENT_NAME",
        ]

    # ------------------------------------------------------------------
    # Accesores tipados
    # ------------------------------------------------------------------

    @property
    def api_url(self) -> str:
        return self._data["API_URL"]

    @property
    def agent_token(self) -> str:
        return self._data["AGENT_TOKEN"]

    @property
    def agent_name(self) -> str:
        """Nombre descriptivo del agente (se muestra en el panel)."""
        return self._data.get("AGENT_NAME", "")

    @property
    def interval_seconds(self) -> int:
        return int(self._data.get("INTERVAL_SECONDS", 30))

    @property
    def log_level(self) -> str:
        return self._data.get("LOG_LEVEL", "INFO").upper()

    @property
    def log_file(self) -> Optional[str]:
        return self._data.get("LOG_FILE") or None  # None → solo stdout

    @property
    def send_processes(self) -> bool:
        return self._data.get("SEND_PROCESSES", "true").lower() in ("true", "1", "yes")

    @property
    def processes_limit(self) -> int:
        return int(self._data.get("PROCESSES_LIMIT", 10))

    @property
    def cpu_warn_threshold(self) -> float:
        return float(self._data.get("CPU_WARN_THRESHOLD", 75.0))

    @property
    def cpu_critical_threshold(self) -> float:
        return float(self._data.get("CPU_CRITICAL_THRESHOLD", 90.0))

    @property
    def ram_warn_threshold(self) -> float:
        return float(self._data.get("RAM_WARN_THRESHOLD", 80.0))

    @property
    def ram_critical_threshold(self) -> float:
        return float(self._data.get("RAM_CRITICAL_THRESHOLD", 95.0))

    @property
    def temp_warn_threshold(self) -> float:
        return float(self._data.get("TEMP_WARN_THRESHOLD", 80.0))

    @property
    def temp_critical_threshold(self) -> float:
        return float(self._data.get("TEMP_CRITICAL_THRESHOLD", 90.0))

    @property
    def disk_warn_threshold(self) -> float:
        return float(self._data.get("DISK_WARN_THRESHOLD", 85.0))

    @property
    def max_queue(self) -> int:
        return int(self._data.get("MAX_QUEUE", 50))

    @property
    def request_timeout(self) -> int:
        return int(self._data.get("REQUEST_TIMEOUT", 10))

    def __repr__(self):
        safe = {k: v for k, v in self._data.items() if "TOKEN" not in k}
        return f"Config({safe})"
