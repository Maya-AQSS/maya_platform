"""Estándar de mensajería Maya v1: carga y valida el catálogo (topology.yaml) y deriva de él
usuarios y permisos. Lo usan gen_definitions.py y check_app.py; las reglas están explicadas en
ESTANDAR.md.

Reglas de nombres (aplican a todo lo que entra en el catálogo):
  exchange      maya.<dominio>                      maya.logs, maya.notifications
  cola          <dominio>.<propósito>               logs.ingest, notifications.email
  DLQ           dlq.<cola>                          dlq.logs.ingest
  DLX           maya.dlx (único, tipo direct, clave = nombre de la cola)
  servicio      minúsculas, dígitos y guiones       maya-dms, n8n
  app Maya      maya-<nombre>                       (= applications.slug = MAYA_MESSAGING_APP)
  usuario       id del servicio con `-` → `_`       maya_dms
  política      maya-dlx-<cola>, prioridad 20
"""
from __future__ import annotations

import os
import re
from dataclasses import dataclass, field
from pathlib import Path

import yaml

TOPOLOGY = Path(__file__).resolve().parent.parent / "topology.yaml"

RE_EXCHANGE = re.compile(r"^maya\.[a-z][a-z0-9_]*$")
RE_QUEUE = re.compile(r"^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$")
RE_SERVICE = re.compile(r"^[a-z][a-z0-9]*(-[a-z0-9]+)*$")
# Un solo segmento: el slug acaba en nombres de Kubernetes (maya-<slug>), de PostgreSQL
# (<slug>_db) y de usuario RabbitMQ (maya_<slug>). Misma regla que maya_template/bin/scaffold.sh
# y build-app.yml.
RE_APP = re.compile(r"^maya-[a-z][a-z0-9]*$")
RE_BINDING = re.compile(r"^(\*|#|[a-z0-9_-]+)(\.(\*|#|[a-z0-9_-]+))*$")
POLICY_PRIORITY = 20


class StandardError(ValueError):
    """El catálogo no cumple el estándar."""


@dataclass
class Service:
    id: str
    kind: str
    publishes: list[str]
    consumes: list[str]
    logs_to_rabbit: bool = True

    @property
    def user(self) -> str:
        return self.id.replace("-", "_")

    @property
    def env_suffix(self) -> str:
        """Sufijo de las variables de contraseña: maya-dms → DMS, n8n → N8N."""
        return self.id.removeprefix("maya-").replace("-", "_").upper()

    def permissions(self) -> dict[str, str]:
        def alt(names: list[str]) -> str:
            return "^$" if not names else "^(" + "|".join(re.escape(n) for n in sorted(names)) + ")$"
        return {"configure": "^$", "write": alt(self.publishes), "read": alt(self.consumes)}


@dataclass
class Topology:
    vhost: str
    connection: dict
    dlx: str
    dlq_prefix: str
    queue_defaults: dict
    exchanges: dict[str, dict]
    queues: dict[str, dict]
    services: dict[str, Service] = field(default_factory=dict)

    def dlq(self, queue: str) -> str:
        return self.dlq_prefix + queue


def load(path: str | os.PathLike | None = None) -> Topology:
    raw = yaml.safe_load(Path(path or TOPOLOGY).read_text(encoding="utf-8"))
    errors: list[str] = []
    if raw.get("version") != 1:
        errors.append("version debe ser 1")
    exchanges = raw.get("exchanges") or {}
    queues = raw.get("queues") or {}
    services_raw = raw.get("services") or {}
    dlx = raw.get("dead_letter_exchange", "maya.dlx")
    if dlx != "maya.dlx":
        errors.append("dead_letter_exchange debe ser maya.dlx")
    if dlx in exchanges:
        errors.append("maya.dlx no se declara en `exchanges`: lo genera la herramienta")

    for name, ex in exchanges.items():
        if not RE_EXCHANGE.match(name):
            errors.append(f"exchange '{name}': debe ser maya.<dominio>")
        if ex.get("type") not in ("topic", "direct", "fanout"):
            errors.append(f"exchange '{name}': type debe ser topic, direct o fanout")
    for name, q in queues.items():
        if not RE_QUEUE.match(name):
            errors.append(f"cola '{name}': debe ser <dominio>.<propósito> en minúsculas")
        if name.startswith(raw.get("dead_letter_queue_prefix", "dlq.")):
            errors.append(f"cola '{name}': las dlq.* las genera la herramienta")
        if q.get("exchange") not in exchanges:
            errors.append(f"cola '{name}': exchange '{q.get('exchange')}' no está en `exchanges`")
        binds = q.get("bindings") or []
        if not binds:
            errors.append(f"cola '{name}': sin bindings")
        for b in binds:
            if not RE_BINDING.match(str(b)):
                errors.append(f"cola '{name}': binding no válido '{b}'")
        if q.get("consumer") not in services_raw:
            errors.append(f"cola '{name}': consumidor '{q.get('consumer')}' no está en `services`")

    services: dict[str, Service] = {}
    for sid, s in services_raw.items():
        s = s or {}
        kind = s.get("kind")
        if not RE_SERVICE.match(sid):
            errors.append(f"servicio '{sid}': solo minúsculas, dígitos y guiones")
        if kind not in ("app", "service"):
            errors.append(f"servicio '{sid}': kind debe ser app o service")
        if kind == "app" and not RE_APP.match(sid):
            errors.append(f"app '{sid}': debe llamarse maya-<nombre>")
        if kind == "app":
            publishes = sorted(n for n, e in exchanges.items() if e.get("apps_publish"))
        else:
            publishes = sorted(s.get("publishes") or [])
        for ex in publishes:
            if ex not in exchanges:
                errors.append(f"servicio '{sid}': publica en '{ex}', que no está en `exchanges`")
        consumes = sorted(q for q, d in queues.items() if d.get("consumer") == sid)
        services[sid] = Service(sid, kind, publishes, consumes, bool(s.get("logs_to_rabbit", True)))

    consumers_of_logs = [q["consumer"] for n, q in queues.items() if q.get("exchange") == "maya.logs"]
    for sid in consumers_of_logs:
        if sid in services and services[sid].logs_to_rabbit:
            errors.append(f"servicio '{sid}': consume maya.logs y debe llevar logs_to_rabbit: false (bucle)")

    if errors:
        raise StandardError("El catálogo no cumple el estándar:\n  - " + "\n  - ".join(errors))
    return Topology(
        vhost=raw.get("vhost", "/maya"),
        connection=raw.get("connection") or {},
        dlx=dlx,
        dlq_prefix=raw.get("dead_letter_queue_prefix", "dlq."),
        queue_defaults=raw.get("queue_defaults") or {},
        exchanges=exchanges,
        queues=queues,
        services=services,
    )
