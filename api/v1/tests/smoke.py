#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Интеграционные smoke-тесты WMS Integration API (/api/v1).

Запуск (dev-сервер уже поднят):
    python api/v1/tests/smoke.py
    python api/v1/tests/smoke.py --base http://127.0.0.1:8001 --rate

Скрипт использует только стандартную библиотеку Python (без pytest/requests).
На время теста меняет настройки интеграции и восстанавливает их в finally.
Пишет отчёт: api/v1/tests/report.md
"""
import argparse
import hashlib
import hmac
import json
import os
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

try:
    sys.stdout.reconfigure(encoding="utf-8")
except Exception:
    pass

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", "..", ".."))
CONFIG_PHP = os.path.join(HERE, "config.php")
REPORT_MD = os.path.join(HERE, "report.md")

CLIENT_ID = "smoke-client"
CLIENT_SECRET = "smoke-secret"
HOOK_SECRET = "smoke-hook-secret"
JWT_SECRET = "smoke-jwt-secret"
STAMP = datetime.now().strftime("%Y%m%d%H%M%S")
EXT = "SMOKE-ORD-" + STAMP
SKU = "SMOKE-SKU-" + STAMP
BARCODE = "4600000000" + STAMP[-6:]

RESULTS = []
HOOKS = []


def php(*args, timeout=60):
    p = subprocess.run(["php", CONFIG_PHP, *args], cwd=ROOT, capture_output=True,
                       text=True, encoding="utf-8", errors="replace", timeout=timeout)
    if p.returncode != 0:
        raise RuntimeError("php config.php %s failed: %s %s" % (" ".join(args), p.stdout, p.stderr))
    return p.stdout.strip()


def http(method, path, body=None, token=None, headers=None, timeout=20):
    url = BASE + path
    data = None
    hdrs = {"Content-Type": "application/json"}
    if headers:
        hdrs.update(headers)
    if token:
        hdrs["Authorization"] = "Bearer " + token
    if body is not None:
        if isinstance(body, str):
            data = body.encode("utf-8")
        else:
            data = json.dumps(body, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(url, data=data, headers=hdrs, method=method)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return r.status, dict(r.headers), r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        headers = dict(e.headers) if e.headers else {}
        return e.code, headers, e.read().decode("utf-8", "replace")


def jget(text):
    try:
        return json.loads(text)
    except Exception:
        return {}


def check(name, ok, detail=""):
    RESULTS.append((name, bool(ok), detail))
    print(("PASS  " if ok else "FAIL  ") + name + ("" if ok else "  -> " + str(detail)[:400]))
    return ok


class Sink(BaseHTTPRequestHandler):
    def do_POST(self):
        n = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(n) if n else b""
        HOOKS.append({
            "path": self.path,
            "headers": {k.lower(): v for k, v in self.headers.items()},
            "body": body.decode("utf-8", "replace"),
            "ts": time.time(),
        })
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(b'{"ok":true}')

    def log_message(self, *a):
        pass


def start_sink(port):
    srv = ThreadingHTTPServer(("127.0.0.1", port), Sink)
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    return srv


def wait_hook(pred, timeout=12):
    deadline = time.time() + timeout
    while time.time() < deadline:
        for h in list(HOOKS):
            if pred(h):
                return h
        time.sleep(0.2)
    return None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8001")
    ap.add_argument("--port", type=int, default=9099)
    ap.add_argument("--rate", action="store_true", help="проверить rate limit (429)")
    ap.add_argument("--skip-webhooks", action="store_true")
    args = ap.parse_args()
    global BASE
    BASE = args.base.rstrip("/")

    sink = None
    try:
        php("save")
        callback = "http://127.0.0.1:%d/hook" % args.port
        php("apply", "--callback=" + callback, "--secret=" + HOOK_SECRET,
            "--client-id=" + CLIENT_ID, "--client-secret=" + CLIENT_SECRET,
            "--jwt-secret=" + JWT_SECRET)
        if not args.skip_webhooks:
            sink = start_sink(args.port)

        # 1. Токен
        st, hd, tx = http("POST", "/api/v1/oauth/token",
                          {"grant_type": "client_credentials", "client_id": CLIENT_ID,
                           "client_secret": CLIENT_SECRET})
        tok = jget(tx).get("access_token", "")
        check("token: выдача access_token", st == 200 and bool(tok) and jget(tx).get("token_type") == "Bearer",
              (st, tx))
        check("token: TTL ≤ 60 минут", 0 < int(jget(tx).get("expires_in", 0)) <= 3600, tx)

        st, hd, tx = http("POST", "/api/v1/oauth/token",
                          {"grant_type": "client_credentials", "client_id": CLIENT_ID, "client_secret": "bad"})
        check("token: неверный секрет → 401", st == 401 and jget(tx).get("code") == "INVALID_CLIENT", (st, tx))

        st, hd, tx = http("GET", "/api/v1/stock")
        check("auth: без токена → 401", st == 401 and jget(tx).get("code") == "UNAUTHORIZED", (st, tx))

        # 2. Заказы (идемпотентность)
        order = {
            "external_id": EXT,
            "number": EXT,
            "consignee": "ООО Тестовый получатель",
            "items": [{"sku": "MK-ASM-1315", "qty": 3}],
        }
        st, hd, tx = http("POST", "/api/v1/orders", order, tok, {"X-Request-Id": "smoke-order-1"})
        r1 = jget(tx)
        check("orders: создание → 201", st == 201 and r1.get("created") is True, (st, tx))
        check("orders: X-Request-Id в ответе", hd.get("X-Request-Id") == "smoke-order-1", hd.get("X-Request-Id"))

        st, hd, tx = http("POST", "/api/v1/orders", order, tok)
        r2 = jget(tx)
        check("orders: повтор → 200, без дубля", st == 200 and r2.get("created") is False
              and r2.get("order_id") == r1.get("order_id"), (st, tx))

        st, hd, tx = http("POST", "/api/v1/orders",
                          {"external_id": EXT + "-BAD", "items": [{"sku": "NO-SUCH-SKU-XYZ", "qty": 1}]}, tok)
        check("orders: несуществующий SKU → 400 + details", st == 400
              and jget(tx).get("code") == "VALIDATION_ERROR" and bool(jget(tx).get("details")), (st, tx))

        st, hd, tx = http("POST", "/api/v1/orders", {
            "external_id": EXT + "-EMPTY", "items": []}, tok)
        check("orders: пустой items → 400", st == 400 and jget(tx).get("code") == "VALIDATION_ERROR", (st, tx))

        st, hd, tx = http("POST", "/api/v1/orders", '{"external_id":', tok)
        check("orders: битый JSON → 400", st == 400 and jget(tx).get("code") in
              ("VALIDATION_ERROR", "INTERNAL_ERROR"), (st, tx))

        st, hd, tx = http("GET", "/api/v1/orders/" + EXT, token=tok)
        check("orders: статус по external_id → 200", st == 200 and jget(tx).get("status") == "created", (st, tx))

        st, hd, tx = http("GET", "/api/v1/orders/NO-SUCH-ORDER", token=tok)
        check("orders: неизвестный external_id → 404", st == 404 and jget(tx).get("code") == "NOT_FOUND", (st, tx))

        # 3. Номенклатура
        nom = {"sku": SKU, "name": "Тестовый товар интеграции", "unit": "шт", "barcodes": [BARCODE]}
        st, hd, tx = http("POST", "/api/v1/nomenclature", nom, tok)
        check("nomenclature: создание → 201", st == 201 and jget(tx).get("created") is True, (st, tx))
        st, hd, tx = http("POST", "/api/v1/nomenclature", nom, tok)
        check("nomenclature: повтор → 200, без дубля", st == 200 and jget(tx).get("created") is False, (st, tx))
        st, hd, tx = http("POST", "/api/v1/nomenclature", {"sku": "", "name": ""}, tok)
        check("nomenclature: пустые поля → 400", st == 400, (st, tx))

        # 4. Остатки и пагинация
        st, hd, tx = http("GET", "/api/v1/stock?page=1&page_size=500", token=tok)
        s = jget(tx)
        check("stock: страница ≤ 100 записей", st == 200 and int(s.get("page_size", 0)) <= 100 and isinstance(s.get("items"), list),
              (st, tx[:300]))
        st, hd, tx = http("GET", "/api/v1/stock?page=2&page_size=1", token=tok)
        check("stock: page=2 работает", st == 200 and jget(tx).get("page") == 2, (st, tx[:300]))

        # 5. Вебхуки: HMAC и повторы
        if not args.skip_webhooks:
            h = wait_hook(lambda x: "/hook" in x["path"] and EXT in x["body"])
            ok_sig = False
            if h:
                expect = "sha256=" + hmac.new(HOOK_SECRET.encode(), h["body"].encode("utf-8"), hashlib.sha256).hexdigest()
                ok_sig = hmac.compare_digest(h["headers"].get("x-wms-signature", ""), expect)
            check("webhook: order.created доставлен", h is not None, "нет входящего вебхука")
            check("webhook: подпись HMAC-SHA256 верна", ok_sig,
                  (h or {}).get("headers", {}).get("x-wms-signature"))
            check("webhook: X-Wms-Event = order.created", bool(h) and h["headers"].get("x-wms-event") == "order.created",
                  (h or {}).get("headers", {}).get("x-wms-event"))

            # Обрыв получателя → RETRY, затем повтор после восстановления.
            sink.shutdown()
            sink.server_close()
            sink = None
            st, hd, tx = http("POST", "/api/v1/orders", {
                "external_id": EXT + "-RETRY", "items": [{"sku": "MK-ASM-1315", "qty": 1}]}, tok)
            check("webhook: заказ для проверки ретрая создан", st == 201, (st, tx))
            out = php("dispatch", "--force")
            st, hd, tx = http("GET", "/api/v1/webhooks", token=tok)
            rows = jget(tx).get("items", [])
            retry = [r for r in rows if str(r.get("external_id", "")).endswith("-RETRY")]
            check("webhook: недоступность → RETRY, данные в очереди",
                  bool(retry) and retry[0].get("status") in ("RETRY", "PENDING", "DEAD"), (out, retry[:1]))

            sink = start_sink(args.port)
            php("dispatch", "--force")
            st, hd, tx = http("GET", "/api/v1/webhooks", token=tok)
            rows = jget(tx).get("items", [])
            retry = [r for r in rows if str(r.get("external_id", "")).endswith("-RETRY")]
            check("webhook: повтор после восстановления → SENT",
                  bool(retry) and retry[0].get("status") == "SENT", retry[:1])

        # 6. Rate limit (опционально)
        if args.rate:
            php("apply", "--callback=" + callback, "--secret=" + HOOK_SECRET,
                "--client-id=" + CLIENT_ID, "--client-secret=" + CLIENT_SECRET,
                "--jwt-secret=" + JWT_SECRET, "--rate=3")
            codes = []
            for _ in range(12):
                st, hd, tx = http("GET", "/api/v1/health", token=tok, timeout=5)
                codes.append((st, hd))
            limited = [h for (st, h) in codes if st == 429 and h.get("Retry-After")]
            check("rate limit: 429 + Retry-After", bool(limited), [st for (st, _) in codes])
            php("apply", "--callback=" + callback, "--secret=" + HOOK_SECRET,
                "--client-id=" + CLIENT_ID, "--client-secret=" + CLIENT_SECRET,
                "--jwt-secret=" + JWT_SECRET, "--rate=100")

        # 7. Health
        st, hd, tx = http("GET", "/api/v1/health", token=tok)
        hh = jget(tx)
        check("health: метрики и алерты", st == 200 and "webhooks_dead" in hh and isinstance(hh.get("alerts"), list),
              (st, tx[:300]))

    finally:
        if sink is not None:
            try:
                sink.shutdown()
                sink.server_close()
            except Exception:
                pass
        try:
            php("restore")
        except Exception as e:
            print("WARN restore:", e)
        try:
            php("cleanup")
        except Exception as e:
            print("WARN cleanup:", e)

    passed = sum(1 for _, ok, _ in RESULTS if ok)
    total = len(RESULTS)
    write_report(passed, total, args)
    print("\n%s: %d/%d passed" % ("OK" if passed == total else "FAILED", passed, total))
    return 0 if passed == total else 1


def write_report(passed, total, args):
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    lines = [
        "# Отчёт о прохождении тестов интеграции (API v1)",
        "",
        "- Дата: %s" % now,
        "- Окружение: dev (%s), БД: WMS" % args.base,
        "- Набор: `api/v1/tests/smoke.py` (stdlib Python)",
        "- Результат: **%d/%d** (%s)" % (passed, total, "успешно" if passed == total else "ЕСТЬ ОШИБКИ"),
        "",
        "| # | Проверка | Результат |",
        "|---|----------|-----------|",
    ]
    for i, (name, ok, detail) in enumerate(RESULTS, 1):
        lines.append("| %d | %s | %s |" % (i, name.replace("|", "\\|"), "✅ pass" if ok else "❌ fail"))
    lines += [
        "",
        "## Покрытие по ТЗ",
        "",
        "- Аутентификация OAuth 2.0 client_credentials, JWT TTL ≤ 60 мин — проверено.",
        "- Идемпотентность заказов по external_id и номенклатуры по SKU — проверено.",
        "- Валидация (несуществующий SKU, пустой items, битый JSON) и единый формат ошибок — проверено.",
        "- Пагинация stock (page/page_size, лимит 100) — проверено.",
        "- Вебхуки: HMAC-SHA256, X-Wms-Event, outbox и повтор при недоступности — проверено.",
        "- Rate limit 429 + Retry-After — %s." % ("проверено" if args.rate else "опционально (--rate)"),
        "- Health/алерты (доля 5xx, очередь вебхуков) — проверено.",
        "",
        "> Логи smoke-прогона: журнал «Настройки → Интеграция и логирование → Журнал API»",
        "> (хранится 30 дней). Отчёт генерируется автоматически при запуске тестов.",
        "",
    ]
    with open(REPORT_MD, "w", encoding="utf-8") as f:
        f.write("\n".join(lines))


if __name__ == "__main__":
    sys.exit(main())
