"""
SysMon Agent — Sender
Envía el payload al backend Laravel vía HTTP POST con reintentos y backoff.
"""

import json
import logging
import time
from typing import Optional

import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

logger = logging.getLogger("sysmon.sender")


class MetricSender:
    """
    Gestiona el envío de métricas al backend.
    - Autenticación por Bearer token (API token del agente).
    - Reintentos automáticos con backoff exponencial.
    - Compresión gzip opcional.
    - Cola local en memoria si el servidor no responde (max_queue).
    """

    def __init__(
        self,
        api_url: str,
        agent_token: str,
        timeout: int = 10,
        max_retries: int = 3,
        backoff_factor: float = 1.5,
        max_queue: int = 50,
        compress: bool = False,
    ):
        self.api_url     = api_url.rstrip("/") + "/api/agent/metrics"
        self.agent_token = agent_token
        self.timeout     = timeout
        self.max_queue   = max_queue
        self.compress    = compress
        self._queue: list[dict] = []  # payloads pendientes de envío

        # Sesión con reintentos automáticos en errores de red
        retry_strategy = Retry(
            total=max_retries,
            backoff_factor=backoff_factor,
            status_forcelist=[429, 500, 502, 503, 504],
            allowed_methods=["POST"],
            raise_on_status=False,
        )
        adapter = HTTPAdapter(max_retries=retry_strategy)
        self.session = requests.Session()
        self.session.mount("https://", adapter)
        self.session.mount("http://",  adapter)
        self.session.headers.update({
            "Authorization": f"Bearer {self.agent_token}",
            "Content-Type":  "application/json",
            "Accept":        "application/json",
            "X-Agent-Version": "1.0.0",
        })

    # ------------------------------------------------------------------
    # Envío principal
    # ------------------------------------------------------------------

    def send(self, payload: dict) -> bool:
        """
        Envía un payload. Si falla, lo encola para reintento posterior.
        Retorna True si el envío fue exitoso.
        """
        # Intentar vaciar la cola antes de enviar el nuevo payload
        self._flush_queue()

        success = self._post(payload)
        if not success:
            self._enqueue(payload)

        return success

    def _post(self, payload: dict) -> bool:
        """Realiza el POST. Devuelve True si el servidor aceptó (2xx)."""
        try:
            body = json.dumps(payload, default=str)

            resp = self.session.post(
                self.api_url,
                data=body,
                timeout=self.timeout,
            )

            if resp.status_code in (200, 201, 202):
                logger.debug(f"Payload enviado OK — {resp.status_code}")
                return True
            elif resp.status_code == 401:
                logger.error("Token inválido o expirado (401). Verifica AGENT_TOKEN.")
                return False
            elif resp.status_code == 422:
                logger.warning(f"Payload rechazado por validación (422): {resp.text[:200]}")
                return False  # No reencolar — el servidor no lo quiere
            else:
                logger.warning(f"Respuesta inesperada {resp.status_code}: {resp.text[:200]}")
                return False

        except requests.exceptions.ConnectionError:
            logger.warning(f"No se pudo conectar con {self.api_url}")
            return False
        except requests.exceptions.Timeout:
            logger.warning(f"Timeout tras {self.timeout}s")
            return False
        except Exception as e:
            logger.error(f"Error inesperado al enviar: {e}")
            return False

    # ------------------------------------------------------------------
    # Cola local
    # ------------------------------------------------------------------

    def _enqueue(self, payload: dict):
        """Guarda el payload en la cola en memoria."""
        if len(self._queue) >= self.max_queue:
            dropped = self._queue.pop(0)
            logger.warning(
                f"Cola llena ({self.max_queue}). Descartando payload de {dropped.get('timestamp')}"
            )
        self._queue.append(payload)
        logger.info(f"Payload encolado. Cola: {len(self._queue)}/{self.max_queue}")

    def _flush_queue(self):
        """Intenta reenviar los payloads pendientes."""
        if not self._queue:
            return

        logger.info(f"Intentando vaciar cola ({len(self._queue)} payloads)…")
        still_pending = []

        for queued_payload in self._queue:
            if self._post(queued_payload):
                logger.info(f"Payload recuperado de cola: {queued_payload.get('timestamp')}")
            else:
                still_pending.append(queued_payload)
                break  # Si uno falla, no seguir intentando

        self._queue = still_pending

    @property
    def queue_size(self) -> int:
        return len(self._queue)
