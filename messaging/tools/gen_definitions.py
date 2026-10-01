#!/usr/bin/env python3
"""Genera las definiciones de RabbitMQ del estándar de mensajería Maya (topology.yaml).

La MISMA topología para todos los entornos; solo cambian el vhost y de dónde salen las contraseñas.

  --passwords env     contraseñas de RABBITMQ_PASSWORD_<SUFIJO> (maya-dms → DMS, n8n → N8N) y
                      RABBITMQ_ADMIN_PASSWORD; se guardan como hash (K3s de desarrollo)
  --passwords vault   "password": "{{ .Data.data.RABBITMQ_PASSWORD_<SUFIJO> }}" para la plantilla
                      del Vault Agent (producción, values/rabbitmq/definitions.json)
  --scope full        usuarios + vhost + permisos + topología (fichero load_definitions)
  --scope vhost       solo topología del vhost (exchanges, colas, bindings y políticas), para
                      POST /api/definitions/<vhost> (slots de maya_infra)
  --permissions       en lugar de JSON, una línea por servicio: usuario<TAB>configure<TAB>write<TAB>read
  --check             solo valida el catálogo

Ejemplos:
  gen_definitions.py --passwords env --admin > definitions.json            # K3s de desarrollo
  gen_definitions.py --passwords vault                                      # producción (/maya)
  gen_definitions.py --scope vhost --vhost /mi-slot > topology.vhost.json  # maya_infra
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import standard  # noqa: E402


def password_hash(password: str) -> str:
    """rabbit_password_hashing_sha256: base64(sal(4) + sha256(sal + contraseña))."""
    salt = os.urandom(4)
    return base64.b64encode(salt + hashlib.sha256(salt + password.encode()).digest()).decode()


def user_entry(name: str, var: str, tags: list[str], mode: str) -> dict:
    entry: dict = {"name": name, "hashing_algorithm": "rabbit_password_hashing_sha256", "tags": tags, "limits": {}}
    if mode == "vault":
        entry["password"] = "{{ .Data.data.%s }}" % var
    else:
        value = os.environ.get(var, "")
        if not value:
            sys.exit(f"falta la variable {var}")
        entry["password_hash"] = password_hash(value)
    return entry


def topology(t: standard.Topology, vhost: str) -> dict:
    d = t.queue_defaults
    exchanges = [{"name": n, "vhost": vhost, "type": e["type"], "durable": True, "auto_delete": False,
                  "internal": False, "arguments": {}} for n, e in sorted(t.exchanges.items())]
    exchanges.append({"name": t.dlx, "vhost": vhost, "type": "direct", "durable": True, "auto_delete": False,
                      "internal": False, "arguments": {}})
    queues, bindings, policies = [], [], []
    for name, q in sorted(t.queues.items()):
        for qname in (name, t.dlq(name)):
            queues.append({"name": qname, "vhost": vhost, "durable": True, "auto_delete": False,
                           "arguments": {"x-queue-type": d.get("type", "classic")}})
        for key in q["bindings"]:
            bindings.append({"source": q["exchange"], "vhost": vhost, "destination": name,
                             "destination_type": "queue", "routing_key": key, "arguments": {}})
        bindings.append({"source": t.dlx, "vhost": vhost, "destination": t.dlq(name),
                         "destination_type": "queue", "routing_key": name, "arguments": {}})
        # Política por cola CON dead-letter-routing-key: sin ella el mensaje rechazado conserva su
        # clave original, no casa con los bindings de maya.dlx (direct) y se pierde.
        policies.append({
            "vhost": vhost, "name": f"maya-dlx-{name}", "pattern": "^" + name.replace(".", "\\.") + "$",
            "apply-to": "queues", "priority": standard.POLICY_PRIORITY,
            "definition": {
                "dead-letter-exchange": t.dlx,
                "dead-letter-routing-key": name,
                "message-ttl": d.get("message_ttl_ms", 604800000),
                "max-length": d.get("max_length", 100000),
                "overflow": d.get("overflow", "drop-head"),
            },
        })
    return {"exchanges": exchanges, "queues": queues, "bindings": bindings, "policies": policies}


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--topology", help="ruta de topology.yaml (por defecto, la del estándar)")
    p.add_argument("--vhost", help="vhost (por defecto, el del catálogo: /maya)")
    p.add_argument("--passwords", choices=["env", "vault"], default="env")
    p.add_argument("--scope", choices=["full", "vhost"], default="full")
    p.add_argument("--admin", action="store_true", help="incluir el usuario admin (broker propio, no compartido)")
    p.add_argument("--permissions", action="store_true", help="imprimir permisos por servicio (TSV)")
    p.add_argument("--check", action="store_true", help="solo validar el catálogo")
    a = p.parse_args()

    try:
        t = standard.load(a.topology)
    except standard.StandardError as e:
        sys.exit(str(e))
    vhost = a.vhost or t.vhost
    if a.check:
        print(f"catálogo válido: {len(t.exchanges)} exchanges, {len(t.queues)} colas, {len(t.services)} servicios")
        return
    if a.permissions:
        for s in t.services.values():
            perm = s.permissions()
            print("\t".join([s.user, perm["configure"], perm["write"], perm["read"]]))
        return

    out = topology(t, vhost)
    if a.scope == "full":
        users, permissions = [], []
        if a.admin:
            var = "RABBITMQ_DEFAULT_PASS" if a.passwords == "vault" else "RABBITMQ_ADMIN_PASSWORD"
            users.append(user_entry("admin", var, ["administrator"], a.passwords))
            permissions.append({"user": "admin", "vhost": vhost, "configure": ".*", "write": ".*", "read": ".*"})
        for s in t.services.values():
            users.append(user_entry(s.user, f"RABBITMQ_PASSWORD_{s.env_suffix}", [], a.passwords))
            permissions.append({"user": s.user, "vhost": vhost, **s.permissions()})
        out = {"rabbit_version": "3.13", "users": users, "vhosts": [{"name": vhost}],
               "permissions": permissions, **out}
    json.dump(out, sys.stdout, indent=2, ensure_ascii=False)
    sys.stdout.write("\n")


if __name__ == "__main__":
    main()
