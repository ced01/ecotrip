#!/usr/bin/env python3
"""Black-box smoke test for the exact HTTP artifact exposed by Compose."""
from __future__ import annotations

import json
import os
import re
import sys
import urllib.error
import urllib.request
from typing import Any

BASE = os.environ.get("BASE_URL", "http://127.0.0.1:18081").rstrip("/")


def request(path: str, *, method: str = "GET", payload: Any = None, content_type: str | None = None):
    data = None if payload is None else (payload if isinstance(payload, bytes) else json.dumps(payload).encode())
    headers = {"Accept": "application/json, text/html;q=0.9"}
    if data is not None:
        headers["Content-Type"] = content_type or "application/json"
    req = urllib.request.Request(BASE + path, data=data, headers=headers, method=method)
    try:
        return urllib.request.urlopen(req, timeout=10)
    except urllib.error.HTTPError as error:
        return error


def check(path: str, status: int, media: str, **kwargs):
    response = request(path, **kwargs)
    body = response.read()
    actual_media = response.headers.get_content_type()
    assert response.status == status, f"{path}: expected {status}, got {response.status}: {body[:300]!r}"
    assert actual_media == media, f"{path}: expected {media}, got {actual_media}"
    return body, response.headers


def json_check(path: str, status: int = 200, **kwargs):
    body, headers = check(path, status, "application/problem+json" if status >= 400 else "application/json", **kwargs)
    return json.loads(body), headers


def main() -> None:
    homepage, _ = check("/", 200, "text/html")
    html = homepage.decode()
    assert "Ecotrip" in html

    health, _ = json_check("/health")
    assert health == {"status": "ok", "service": "ecotrip"}

    capabilities, _ = json_check("/api/v1/capabilities")
    assert capabilities["mode"] == "demo"
    assert capabilities["limits"]["bookingAvailable"] is False
    assert any("aucune bascule" in warning.lower() for warning in capabilities["warnings"])

    places, _ = json_check("/api/v1/places?q=lyon")
    assert [place["id"] for place in places["items"]] == ["demo-lyon"]
    assert places["items"][0]["provenance"]["status"] == "demo"

    methodology, _ = json_check("/api/v1/methodology")
    assert methodology["status"] == "demo"

    accommodations, _ = json_check("/api/v1/accommodations?destinationId=demo-lyon&publicTransportNearby=unknown")
    assert accommodations["items"]
    assert all(item["dataStatus"] == "demo" for item in accommodations["items"])

    journey, _ = json_check(
        "/api/v1/journeys/search",
        method="POST",
        payload={
            "originId": "demo-paris",
            "destinationId": "demo-lyon",
            "departureDate": "2027-01-15",
            "returnDate": None,
            "travelers": 1,
            "modes": ["train", "coach"],
        },
    )
    assert journey["dataMode"] == "demo"
    assert journey["outbound"]["status"] == "complete"

    problem, _ = json_check("/api/v1/does-not-exist", 404)
    assert problem["code"] == "not_found"
    assert not any(marker in json.dumps(problem) for marker in ("/app/", "Traceback", "Stack trace"))

    unsupported, _ = json_check(
        "/api/v1/journeys/search", 415, method="POST", payload=b"{}", content_type="text/plain"
    )
    assert unsupported["code"] == "unsupported_media_type"

    oversized, _ = json_check(
        "/api/v1/journeys/search", 413, method="POST", payload=b" " * 16385
    )
    assert oversized["code"] == "payload_too_large"

    assets = set(re.findall(r'(?:src|href)="([^"?]+)', html))
    css = next((asset for asset in assets if asset.endswith(".css")), None)
    js = next((asset for asset in assets if asset.endswith(".js")), None)
    assert css and js, f"compiled CSS and JS references missing: {sorted(assets)}"
    check(css, 200, "text/css")
    js_response = request(js)
    js_body = js_response.read()
    assert js_response.status == 200
    assert js_response.headers.get_content_type() in ("text/javascript", "application/javascript")
    assert js_body

    print("HTTP smoke: OK (home, health, capabilities, places, methodology, accommodations, journey, errors, assets)")


if __name__ == "__main__":
    try:
        main()
    except (AssertionError, OSError, ValueError) as error:
        print(f"HTTP smoke: FAILED: {error}", file=sys.stderr)
        raise SystemExit(1)
