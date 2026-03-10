"""
SysMon Agent — Main Loop
Punto de entrada principal. Orquesta collector → alerts → sender.
"""

import logging
import signal
import sys
import time
from pathlib import Path

# Asegurar que el paquete raíz esté en el path cuando se ejecuta directamente
sys.path.insert(0, str(Path(__file__).parent.parent))

from sysmon.config    import Config
from sysmon.collector import collect_all
from sysmon.sender    import MetricSender
from sysmon.alerts    import AlertChecker, AlertRule


# ------------------------------------------------------------------
# Logging
# ------------------------------------------------------------------

def setup_logging(level: str, log_file: str | None):
    fmt = "%(asctime)s [%(levelname)s] %(name)s — %(message)s"
    datefmt = "%Y-%m-%d %H:%M:%S"
    handlers: list[logging.Handler] = [logging.StreamHandler(sys.stdout)]

    if log_file:
        Path(log_file).parent.mkdir(parents=True, exist_ok=True)
        handlers.append(logging.FileHandler(log_file))

    logging.basicConfig(level=getattr(logging, level, logging.INFO),
                        format=fmt, datefmt=datefmt, handlers=handlers)


# ------------------------------------------------------------------
# Graceful shutdown
# ------------------------------------------------------------------

_running = True

def _handle_signal(sig, frame):
    global _running
    logging.getLogger("sysmon").info(f"Señal {sig} recibida — deteniendo agente…")
    _running = False

signal.signal(signal.SIGTERM, _handle_signal)
signal.signal(signal.SIGINT,  _handle_signal)


# ------------------------------------------------------------------
# Main
# ------------------------------------------------------------------

def main(config_path: str | None = None):
    # 1. Cargar config
    cfg = Config(env_file=config_path)
    setup_logging(cfg.log_level, cfg.log_file)
    log = logging.getLogger("sysmon")

    log.info("=" * 60)
    log.info("  SysMon Agent arrancando")
    log.info(f"  API URL   : {cfg.api_url}")
    log.info(f"  Intervalo : {cfg.interval_seconds}s")
    log.info(f"  Log level : {cfg.log_level}")
    log.info("=" * 60)

    # 2. Inicializar sender
    sender = MetricSender(
        api_url     = cfg.api_url,
        agent_token = cfg.agent_token,
        timeout     = cfg.request_timeout,
        max_queue   = cfg.max_queue,
    )

    # 3. Inicializar alert checker con umbrales de la config
    checker = AlertChecker()
    checker.update_rule("cpu_warning",  threshold=cfg.cpu_warn_threshold)
    checker.update_rule("cpu_critical", threshold=cfg.cpu_critical_threshold)
    checker.update_rule("ram_warning",  threshold=cfg.ram_warn_threshold)
    checker.update_rule("ram_critical", threshold=cfg.ram_critical_threshold)

    # Callback: las alertas se incluyen en el propio payload al backend
    # (el backend las guarda en tabla alerts)

    # 4. Bucle principal
    cycle = 0
    while _running:
        cycle += 1
        t_start = time.monotonic()

        try:
            log.debug(f"Ciclo #{cycle} — recopilando métricas…")

            # Recopilar
            payload = collect_all(include_processes=cfg.send_processes)

            # Añadir nombre de agente si está configurado
            if cfg.agent_name:
                payload["agent_name"] = cfg.agent_name

            # Evaluar alertas localmente
            fired_alerts = checker.check(payload)
            if fired_alerts:
                payload["alerts"] = fired_alerts
                log.warning(f"{len(fired_alerts)} alerta(s) en ciclo #{cycle}")

            # Enviar
            ok = sender.send(payload)
            elapsed = time.monotonic() - t_start

            if ok:
                log.info(
                    f"Ciclo #{cycle} OK — "
                    f"CPU {payload['cpu']['usage_percent']:.1f}% | "
                    f"RAM {payload['ram']['usage_percent']:.1f}% | "
                    f"Enviado en {elapsed:.2f}s"
                )
            else:
                log.warning(f"Ciclo #{cycle} — envío fallido, encolado. Cola: {sender.queue_size}")

        except Exception as e:
            log.error(f"Error en ciclo #{cycle}: {e}", exc_info=True)

        # Esperar hasta el próximo intervalo (restando el tiempo que tomó el ciclo)
        elapsed = time.monotonic() - t_start
        sleep_time = max(0, cfg.interval_seconds - elapsed)
        if sleep_time > 0 and _running:
            time.sleep(sleep_time)

    log.info("Agente detenido correctamente.")


if __name__ == "__main__":
    import argparse
    parser = argparse.ArgumentParser(description="SysMon Agent")
    parser.add_argument("--config", "-c", help="Ruta al archivo agent.env", default=None)
    args = parser.parse_args()
    main(config_path=args.config)
