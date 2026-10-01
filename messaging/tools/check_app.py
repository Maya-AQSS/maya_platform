#!/usr/bin/env python3
"""Comprueba que una app Maya cumple el estándar de mensajería (ESTANDAR.md, topology.yaml).

Se ejecuta en el CI de cada app (build-app.yml) y en local:

  python3 maya_platform/messaging/tools/check_app.py ../maya_dms
  python3 maya_platform/messaging/tools/check_app.py ../maya_template --template

Errores (salida 1): lo que rompe el sistema común (identidad, usuario, vhost, permisos, colas,
canal de logs, publicar sin el paquete). Avisos: lo que conviene corregir sin romper nada.
"""
from __future__ import annotations

import argparse
import os
import re
import sys
from pathlib import Path

import yaml

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import standard  # noqa: E402

DIRECT_AMQP = re.compile(r"\b(AMQPStreamConnection|AMQPSSLConnection|AMQPLazyConnection)\b|"
                         r"->(basic_publish|exchange_declare|queue_declare|queue_bind)\(")
QUEUE_LITERAL = re.compile(r"""['"]([a-z][a-z0-9_]*\.(?:ingest|email|[a-z][a-z0-9_]*))['"]""")


def env_file(path: Path) -> dict[str, str]:
    out = {}
    if path.is_file():
        for line in path.read_text(encoding="utf-8").splitlines():
            m = re.match(r"^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$", line)
            if m:
                out[m.group(1)] = m.group(2).strip('"').strip("'")
    return out


class Report:
    def __init__(self) -> None:
        self.errors: list[str] = []
        self.warnings: list[str] = []

    def error(self, msg: str) -> None:
        self.errors.append(msg)

    def warn(self, msg: str) -> None:
        self.warnings.append(msg)


def check(app_dir: Path, t: standard.Topology, template: bool, r: Report) -> str:
    backend = app_dir / "backend"
    chart_dir = app_dir / "deploy" / "helm"
    env = env_file(backend / ".env.example")

    # ── Identidad: MAYA_MESSAGING_APP = nombre del chart = applications.slug ─────
    app_id = None
    if (chart_dir / "Chart.yaml").is_file():
        app_id = yaml.safe_load((chart_dir / "Chart.yaml").read_text())["name"]
    app_id = app_id or env.get("MAYA_MESSAGING_APP", "")
    if not standard.RE_APP.match(app_id or ""):
        r.error(f"identificador de app '{app_id}': debe ser maya-<nombre> (MAYA_MESSAGING_APP)")
        return app_id
    svc = t.services.get(app_id)
    if svc is None and not template:
        r.error(f"'{app_id}' no está en messaging/topology.yaml (services): añadir su entrada")
    user = app_id.replace("-", "_")
    logs_to_rabbit = svc.logs_to_rabbit if svc else True
    consumes = set(svc.consumes) if svc else set()

    # ── backend/.env.example (desarrollo local) ────────────────────────────────
    if env:
        if env.get("MAYA_MESSAGING_APP") != app_id:
            r.error(f".env.example: MAYA_MESSAGING_APP={env.get('MAYA_MESSAGING_APP')!r}, debe ser {app_id}")
        if env.get("RABBITMQ_USER") != user:
            r.error(f".env.example: RABBITMQ_USER={env.get('RABBITMQ_USER')!r}, debe ser {user}")
        if not env.get("RABBITMQ_VHOST"):
            r.warn(".env.example: falta RABBITMQ_VHOST (en local, el vhost del slot)")
        stack = env.get("LOG_STACK", "").split(",")
        if logs_to_rabbit and "rabbit" not in stack:
            r.error(".env.example: LOG_STACK debe incluir `rabbit` (los logs van a maya.logs)")
        if not logs_to_rabbit and "rabbit" in stack:
            r.error(".env.example: LOG_STACK no debe incluir `rabbit` (esta app consume logs.ingest: bucle)")
        if env.get("QUEUE_CONNECTION") not in (None, "redis"):
            r.warn(f".env.example: QUEUE_CONNECTION={env.get('QUEUE_CONNECTION')}; el estándar es redis "
                   "(RabbitMQ solo para mensajes entre apps; las apps no pueden declarar colas)")
    else:
        r.error("falta backend/.env.example")

    # ── Chart de producción (deploy/helm/values.yaml) ──────────────────────────
    values_path = chart_dir / "values.yaml"
    if values_path.is_file():
        values = yaml.safe_load(values_path.read_text()) or {}
        cfg = {k: str(v) for k, v in (values.get("config") or {}).items()}
        expected = {
            "MAYA_MESSAGING_APP": app_id,
            "RABBITMQ_USER": user,
            "RABBITMQ_VHOST": t.vhost,
            "RABBITMQ_HOST": t.connection.get("host"),
            "RABBITMQ_PORT": str(t.connection.get("port")),
            "QUEUE_CONNECTION": "redis",
        }
        for key, want in expected.items():
            if cfg.get(key) != want:
                r.error(f"values.yaml: config.{key}={cfg.get(key)!r}, debe ser {want!r}")
        stack = cfg.get("LOG_STACK", "").split(",")
        if logs_to_rabbit and "rabbit" not in stack:
            r.error("values.yaml: LOG_STACK debe incluir `rabbit` (p. ej. stderr,rabbit)")
        if not logs_to_rabbit and "rabbit" in stack:
            r.error("values.yaml: LOG_STACK no debe incluir `rabbit` (consume logs.ingest: bucle)")
        if "RABBITMQ_PASSWORD" not in ((values.get("vault") or {}).get("keys") or []):
            r.error("values.yaml: vault.keys debe incluir RABBITMQ_PASSWORD")
    elif not template:
        r.warn("sin deploy/helm/values.yaml: no se comprueba la configuración de producción")

    # ── Configuración y código Laravel ────────────────────────────────────────
    composer = backend / "composer.json"
    if composer.is_file() and "ceedcv-maya/shared-messaging-laravel" not in composer.read_text():
        r.error("composer.json: falta ceedcv-maya/shared-messaging-laravel (único cliente permitido)")
    logging_php = backend / "config" / "logging.php"
    if logging_php.is_file():
        text = logging_php.read_text()
        if "'rabbit'" not in text or "RabbitMQLogChannel" not in text:
            r.error("config/logging.php: falta el canal 'rabbit' (driver custom, RabbitMQLogChannel)")
    messaging_php = backend / "config" / "messaging.php"
    if messaging_php.is_file():
        text = messaging_php.read_text()
        for key in ("connection", "exchanges", "queues"):
            if re.search(rf"['\"]{key}['\"]\s*=>", text):
                r.error(f"config/messaging.php: no redefinir '{key}' (sustituye entero el del paquete "
                        "y rompe el estándar); usar las variables de entorno del paquete")
    app_code = backend / "app"
    for php in sorted(app_code.rglob("*.php")) if app_code.is_dir() else []:
        text = php.read_text(errors="ignore")
        if DIRECT_AMQP.search(text):
            r.error(f"{php.relative_to(app_dir)}: usa AMQP directamente; publicar/consumir solo con "
                    "shared-messaging-laravel")
        if "ConsumeQueueCommand" in text:
            for q in set(QUEUE_LITERAL.findall(text)):
                if q not in t.queues:
                    r.error(f"{php.relative_to(app_dir)}: cola '{q}' no está en topology.yaml")
                elif q not in consumes and not template:
                    r.error(f"{php.relative_to(app_dir)}: consume '{q}', pero su consumidor en "
                            f"topology.yaml es '{t.queues[q]['consumer']}'")
    for q in sorted(consumes):
        found = any(q in p.read_text(errors="ignore") for p in app_code.rglob("*.php")) if app_code.is_dir() else False
        if not found:
            r.warn(f"topology.yaml dice que {app_id} consume '{q}', pero no hay consumidor en app/")
    return app_id


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("apps", nargs="+", help="directorios de las apps (raíz del repo)")
    p.add_argument("--template", action="store_true", help="plantilla: no exige estar en el catálogo")
    p.add_argument("--topology", help="ruta de topology.yaml (por defecto, la del estándar)")
    a = p.parse_args()
    try:
        t = standard.load(a.topology)
    except standard.StandardError as e:
        sys.exit(str(e))
    failed = False
    for path in a.apps:
        r = Report()
        app_id = check(Path(path).resolve(), t, a.template, r)
        status = "✔" if not r.errors else "✘"
        print(f"{status} {app_id or path}: {len(r.errors)} errores, {len(r.warnings)} avisos")
        for e in r.errors:
            print(f"    ERROR  {e}")
        for w in r.warnings:
            print(f"    aviso  {w}")
        failed |= bool(r.errors)
    sys.exit(1 if failed else 0)


if __name__ == "__main__":
    main()
