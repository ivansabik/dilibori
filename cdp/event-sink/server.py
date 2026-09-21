#!/usr/bin/env python3
"""Local webhook sink for RudderStack. Inspect events without a warehouse."""

from __future__ import annotations

import json
import os
import threading
from datetime import UTC, datetime
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse

PORT = int(os.environ.get("EVENT_SINK_PORT", "8090"))
DATA_PATH = Path(os.environ.get("EVENT_SINK_DATA", "/data/events.jsonl"))
WORKSPACE_CONFIG_PATH = Path(
    os.environ.get(
        "EVENT_SINK_WORKSPACE_CONFIG",
        "/etc/rudderstack/workspaceConfig.json",
    )
)
MAX_EVENTS = int(os.environ.get("EVENT_SINK_MAX_EVENTS", "500"))
LOCK = threading.Lock()


def ensure_data_file() -> None:
    DATA_PATH.parent.mkdir(parents=True, exist_ok=True)
    if not DATA_PATH.exists():
        DATA_PATH.touch()


def load_workspace_config() -> dict:
    if not WORKSPACE_CONFIG_PATH.exists():
        return {}
    try:
        return json.loads(WORKSPACE_CONFIG_PATH.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}


def source_config_for_write_key(write_key: str) -> dict | None:
    """Shape expected by the RudderStack JS SDK (/sourceConfig)."""
    workspace = load_workspace_config()
    for source in workspace.get("sources") or []:
        if source.get("writeKey") != write_key:
            continue
        return {
            "source": source,
            "metadata": {"version": "1.0.2"},
        }
    return None


def query_param(query: str, name: str) -> str | None:
    values = parse_qs(query, keep_blank_values=True).get(name)
    if not values:
        return None
    return values[0]


def append_events(payload: object) -> int:
    """Normalize RudderStack webhook payloads into one JSONL line per event."""
    now = datetime.now(UTC).isoformat()
    items: list[object]
    if isinstance(payload, list):
        items = payload
    elif isinstance(payload, dict):
        if isinstance(payload.get("batch"), list):
            items = payload["batch"]
        else:
            items = [payload]
    else:
        items = [{"raw": payload}]

    written = 0
    with LOCK:
        with DATA_PATH.open("a", encoding="utf-8") as fh:
            for item in items:
                record = {"received_at": now, "event": item}
                fh.write(json.dumps(record, default=str) + "\n")
                written += 1
        # Trim file to last MAX_EVENTS lines
        lines = DATA_PATH.read_text(encoding="utf-8").splitlines()
        if len(lines) > MAX_EVENTS:
            DATA_PATH.write_text(
                "\n".join(lines[-MAX_EVENTS:]) + "\n",
                encoding="utf-8",
            )
    return written


def read_events(limit: int = 100) -> list[dict]:
    with LOCK:
        if not DATA_PATH.exists():
            return []
        text = DATA_PATH.read_text(encoding="utf-8")
        lines = [ln for ln in text.splitlines() if ln.strip()]
    events: list[dict] = []
    for line in lines[-limit:]:
        try:
            events.append(json.loads(line))
        except json.JSONDecodeError:
            events.append({"raw": line})
    events.reverse()
    return events


HTML = """<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Dilibori CDP: Event Inspector</title>
  <style>
    :root {
      color-scheme: light dark;
      font-family: ui-sans-serif, system-ui, sans-serif;
    }
    body { margin: 1.5rem; max-width: 960px; }
    h1 { font-size: 1.25rem; margin: 0 0 .25rem; }
    p.meta { color: #666; margin: 0 0 1rem; font-size: .9rem; }
    .toolbar {
      display: flex; gap: .75rem; align-items: center; margin-bottom: 1rem;
    }
    button { cursor: pointer; }
    pre {
      background: #111; color: #e8e8e8; padding: .75rem 1rem; border-radius: 6px;
      overflow: auto; font-size: .8rem; line-height: 1.4; margin: 0 0 .75rem;
    }
    .empty { color: #888; }
    .card {
      border: 1px solid #4444; border-radius: 8px;
      padding: .75rem; margin-bottom: .75rem;
    }
    .label {
      font-size: .75rem; text-transform: uppercase;
      letter-spacing: .04em; color: #888;
    }
  </style>
</head>
<body>
  <h1>Event inspector</h1>
  <p class="meta">
    RudderStack webhook sink · auto-refreshes every 3s ·
    <a href="/events.json">/events.json</a>
  </p>
  <div class="toolbar">
    <button type="button" onclick="location.reload()">Refresh</button>
    <form method="post" action="/clear" style="margin:0">
      <button type="submit">Clear</button>
    </form>
    <span id="count" class="meta"></span>
  </div>
  <div id="list" class="empty">Waiting for events…</div>
  <script>
    async function load() {
      const res = await fetch('/events.json?limit=100');
      const data = await res.json();
      const list = document.getElementById('list');
      const count = document.getElementById('count');
      count.textContent = data.length + ' recent';
      if (!data.length) {
        list.className = 'empty';
        list.textContent = 'Waiting for events…';
        return;
      }
      list.className = '';
      list.innerHTML = data.map((row) => {
        const ev = row.event || row;
        const type = ev.type || '?';
        const name = ev.event || ev.name || '';
        const title = [type, name].filter(Boolean).join(' · ');
        return '<div class="card"><div class="label">' +
          (row.received_at || '') + ' · ' + title +
          '</div><pre>' + JSON.stringify(row, null, 2) + '</pre></div>';
      }).join('');
    }
    load();
    setInterval(load, 3000);
  </script>
</body>
</html>
"""


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt: str, *args) -> None:
        print(f"[event-sink] {self.address_string()} {fmt % args}")

    def _send(self, code: int, body: bytes, content_type: str) -> None:
        self.send_response(code)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Access-Control-Allow-Origin", "*")
        self.end_headers()
        self.wfile.write(body)

    def do_OPTIONS(self) -> None:  # noqa: N802
        self.send_response(204)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "*")
        self.end_headers()

    def do_GET(self) -> None:  # noqa: N802
        parsed = urlparse(self.path)
        path = parsed.path
        if path in ("/", "/index.html"):
            self._send(200, HTML.encode("utf-8"), "text/html; charset=utf-8")
            return
        if path == "/health":
            self._send(200, b'{"ok":true}\n', "application/json")
            return
        if path in ("/sourceConfig", "/sourceConfig/"):
            write_key = query_param(parsed.query, "writeKey") or ""
            payload = source_config_for_write_key(write_key) if write_key else None
            if not payload:
                self._send(404, b'{"error":"unknown writeKey"}\n', "application/json")
                return
            body = json.dumps(payload).encode("utf-8")
            self._send(200, body, "application/json")
            return
        if path == "/events.json":
            limit = 100
            raw_limit = query_param(parsed.query, "limit")
            if raw_limit:
                try:
                    limit = max(1, min(MAX_EVENTS, int(raw_limit)))
                except ValueError:
                    # Invalid limit keeps the default of 100.
                    pass
            body = json.dumps(read_events(limit), default=str).encode("utf-8")
            self._send(200, body, "application/json")
            return
        self._send(404, b'{"error":"not found"}\n', "application/json")

    def do_POST(self) -> None:  # noqa: N802
        path = urlparse(self.path).path
        length = int(self.headers.get("Content-Length", "0") or "0")
        raw = self.rfile.read(length) if length else b""

        if path == "/clear":
            with LOCK:
                DATA_PATH.write_text("", encoding="utf-8")
            self.send_response(303)
            self.send_header("Location", "/")
            self.end_headers()
            return

        if path not in ("/rudder", "/"):
            self._send(404, b'{"error":"not found"}\n', "application/json")
            return

        try:
            payload = json.loads(raw.decode("utf-8") or "null")
        except json.JSONDecodeError:
            payload = {"raw": raw.decode("utf-8", errors="replace")}

        written = append_events(payload)
        body = json.dumps({"ok": True, "written": written}).encode("utf-8")
        self._send(200, body, "application/json")


def main() -> None:
    ensure_data_file()
    server = ThreadingHTTPServer(("0.0.0.0", PORT), Handler)
    print(f"[event-sink] listening on :{PORT} → {DATA_PATH}")
    print(f"[event-sink] sourceConfig from {WORKSPACE_CONFIG_PATH}")
    server.serve_forever()


if __name__ == "__main__":
    main()
