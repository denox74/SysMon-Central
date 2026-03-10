"""
SysMon Agent — Alert Checker
Evalúa umbrales localmente antes de enviar, para generar alertas inmediatas
sin esperar a que el backend las procese.
Los umbrales se leen desde la config y pueden actualizarse en caliente.
"""

import logging
from dataclasses import dataclass, field
from typing import Callable, Optional

logger = logging.getLogger("sysmon.alerts")


@dataclass
class AlertRule:
    """Define un umbral de alerta."""
    name:        str         # Identificador, e.g. "cpu_critical"
    metric_path: str         # Ruta en el payload, e.g. "cpu.usage_percent"
    threshold:   float       # Valor límite
    operator:    str         # "gt" | "lt" | "gte" | "lte"
    severity:    str         # "warning" | "critical"
    message_tpl: str         # Template, usa {value} y {threshold}
    cooldown_s:  int = 300   # Segundos entre alertas del mismo tipo
    enabled:     bool = True

    # Estado interno
    _last_fired: float = field(default=0.0, init=False, repr=False)

    def evaluate(self, value: float) -> bool:
        ops = {
            "gt":  lambda v, t: v >  t,
            "gte": lambda v, t: v >= t,
            "lt":  lambda v, t: v <  t,
            "lte": lambda v, t: v <= t,
        }
        fn = ops.get(self.operator)
        return fn(value, self.threshold) if fn else False

    def should_fire(self, now: float) -> bool:
        return self.enabled and (now - self._last_fired) >= self.cooldown_s

    def mark_fired(self, now: float):
        self._last_fired = now

    def format_message(self, value: float) -> str:
        return self.message_tpl.format(value=round(value, 2), threshold=self.threshold)


class AlertChecker:
    """
    Evalúa un payload de métricas contra las reglas configuradas.
    Cuando se dispara una alerta llama a los callbacks registrados.
    """

    def __init__(self, rules: list[AlertRule] | None = None):
        self.rules: list[AlertRule] = rules or self._default_rules()
        self._callbacks: list[Callable] = []

    # ------------------------------------------------------------------
    # Reglas por defecto
    # ------------------------------------------------------------------

    @staticmethod
    def _default_rules() -> list[AlertRule]:
        return [
            AlertRule(
                name="cpu_warning",
                metric_path="cpu.usage_percent",
                threshold=75.0,
                operator="gte",
                severity="warning",
                message_tpl="CPU al {value}% (umbral: {threshold}%)",
                cooldown_s=300,
            ),
            AlertRule(
                name="cpu_critical",
                metric_path="cpu.usage_percent",
                threshold=90.0,
                operator="gte",
                severity="critical",
                message_tpl="CPU CRÍTICA: {value}% (umbral: {threshold}%)",
                cooldown_s=120,
            ),
            AlertRule(
                name="ram_warning",
                metric_path="ram.usage_percent",
                threshold=80.0,
                operator="gte",
                severity="warning",
                message_tpl="RAM al {value}% (umbral: {threshold}%)",
                cooldown_s=300,
            ),
            AlertRule(
                name="ram_critical",
                metric_path="ram.usage_percent",
                threshold=95.0,
                operator="gte",
                severity="critical",
                message_tpl="RAM CRÍTICA: {value}% (umbral: {threshold}%)",
                cooldown_s=120,
            ),
            AlertRule(
                name="swap_high",
                metric_path="ram.swap_percent",
                threshold=60.0,
                operator="gte",
                severity="warning",
                message_tpl="SWAP al {value}% — posible presión de memoria",
                cooldown_s=600,
            ),
            AlertRule(
                name="load_high",
                metric_path="cpu.load_5m",
                threshold=4.0,   # Ajusta según nº de cores
                operator="gte",
                severity="warning",
                message_tpl="Load average (5m): {value} (umbral: {threshold})",
                cooldown_s=300,
            ),
        ]

    # ------------------------------------------------------------------
    # API pública
    # ------------------------------------------------------------------

    def on_alert(self, callback: Callable):
        """Registra un callback(rule, value, message) que se llama al dispararse una alerta."""
        self._callbacks.append(callback)

    def check(self, payload: dict) -> list[dict]:
        """
        Evalúa todas las reglas contra el payload.
        Devuelve lista de alertas disparadas en este ciclo.
        """
        import time
        now = time.time()
        fired = []

        for rule in self.rules:
            if not rule.enabled:
                continue

            value = self._extract(payload, rule.metric_path)
            if value is None:
                continue

            if rule.evaluate(value) and rule.should_fire(now):
                rule.mark_fired(now)
                message = rule.format_message(value)
                alert = {
                    "rule":     rule.name,
                    "severity": rule.severity,
                    "metric":   rule.metric_path,
                    "value":    value,
                    "threshold": rule.threshold,
                    "message":  message,
                    "hostname": payload.get("system", {}).get("hostname", "unknown"),
                }
                fired.append(alert)
                logger.warning(f"[{rule.severity.upper()}] {message}")

                for cb in self._callbacks:
                    try:
                        cb(rule, value, message, alert)
                    except Exception as e:
                        logger.error(f"Error en callback de alerta: {e}")

        # Alertas de temperatura (dinámicas, no saben el sensor de antemano)
        fired += self._check_temperatures(payload, now)

        return fired

    def update_rule(self, name: str, **kwargs):
        """Actualiza un umbral en caliente, e.g. update_rule('cpu_critical', threshold=85.0)."""
        for rule in self.rules:
            if rule.name == name:
                for k, v in kwargs.items():
                    if hasattr(rule, k):
                        setattr(rule, k, v)
                logger.info(f"Regla '{name}' actualizada: {kwargs}")
                return
        logger.warning(f"Regla '{name}' no encontrada")

    def add_disk_rule(self, mountpoint: str, threshold: float = 85.0):
        """Añade una regla dinámica para un punto de montaje concreto."""
        # Las reglas de disco se chequean manualmente en check_disks
        self._disk_rules = getattr(self, "_disk_rules", {})
        self._disk_rules[mountpoint] = threshold

    # ------------------------------------------------------------------
    # Internos
    # ------------------------------------------------------------------

    @staticmethod
    def _extract(payload: dict, path: str):
        """Navega el payload por 'a.b.c' y devuelve el valor o None."""
        parts = path.split(".")
        obj = payload
        for p in parts:
            if isinstance(obj, dict):
                obj = obj.get(p)
            else:
                return None
        return obj if isinstance(obj, (int, float)) else None

    def _check_temperatures(self, payload: dict, now: float) -> list[dict]:
        """Evalúa temperaturas de todos los sensores (umbrales: 80°C warn, 90°C critical)."""
        fired = []
        temps = payload.get("temperatures", {})
        hostname = payload.get("system", {}).get("hostname", "unknown")

        for sensor_name, readings in temps.items():
            for reading in readings:
                current = reading.get("current")
                label   = reading.get("label", "?")
                if current is None:
                    continue

                critical_limit = reading.get("critical") or 90.0
                high_limit     = reading.get("high")     or 80.0

                if current >= critical_limit:
                    fired.append({
                        "rule":      f"temp_critical_{sensor_name}_{label}",
                        "severity":  "critical",
                        "metric":    f"temperatures.{sensor_name}.{label}",
                        "value":     current,
                        "threshold": critical_limit,
                        "message":   f"Temperatura CRÍTICA en {sensor_name}/{label}: {current}°C",
                        "hostname":  hostname,
                    })
                elif current >= high_limit:
                    fired.append({
                        "rule":      f"temp_warning_{sensor_name}_{label}",
                        "severity":  "warning",
                        "metric":    f"temperatures.{sensor_name}.{label}",
                        "value":     current,
                        "threshold": high_limit,
                        "message":   f"Temperatura alta en {sensor_name}/{label}: {current}°C",
                        "hostname":  hostname,
                    })

        return fired
