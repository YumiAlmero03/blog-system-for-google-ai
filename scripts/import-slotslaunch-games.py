#!/usr/bin/env python3
"""Import and finalize up to 100 SlotsLaunch games for cron runs."""
from __future__ import annotations

import json
import logging
import os
import sqlite3
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import zlib
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
DB_PATH = ROOT / "storage" / "blogs.sqlite"
LOG_PATH = ROOT / "storage" / "import-slotslaunch-games.log"
SLOTSLAUNCH_DEFAULT_GAMES_URL = "https://slotslaunch.com/api/games"
MAX_GAMES_PER_RUN = 100
LOGGER = logging.getLogger("import-slotslaunch-games")


def configure_logging() -> None:
    LOG_PATH.parent.mkdir(parents=True, exist_ok=True)
    handler = logging.FileHandler(LOG_PATH, encoding="utf-8")
    handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
    LOGGER.setLevel(logging.INFO)
    LOGGER.handlers.clear()
    LOGGER.addHandler(handler)
    LOGGER.propagate = False


def env_value(name: str) -> str | None:
    value = os.environ.get(name)
    if value:
        return value.strip().strip('"').strip("'")
    env_path = ROOT / ".env"
    if not env_path.exists():
        return None
    for line in env_path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            key, raw = line.split("=", 1)
            if key.strip() == name:
                return raw.strip().strip('"').strip("'")
    return None


def api_url() -> str:
    url = env_value("SLOTSLAUNCH_GAMES_API_URL") or SLOTSLAUNCH_DEFAULT_GAMES_URL
    token = env_value("SLOTSLAUNCH_API_TOKEN") or env_value("SLOTSLAUNCH_TOKEN") or env_value("GAMES_API_TOKEN")
    if not token:
        raise RuntimeError("Missing SlotsLaunch token. Add SLOTSLAUNCH_API_TOKEN to .env.")
    parsed = urllib.parse.urlsplit(url)
    query = urllib.parse.parse_qsl(parsed.query, keep_blank_values=True)
    if not any(key == "token" for key, _ in query):
        query.append(("token", token))
    return urllib.parse.urlunsplit(parsed._replace(query=urllib.parse.urlencode(query)))


def fetch_json(url: str) -> dict[str, Any] | list[Any]:
    request = urllib.request.Request(
        url,
        headers={
            "Accept": "application/json",
            "User-Agent": "SlotsLaunch Importer",
            "Origin": env_value("SLOTSLAUNCH_ORIGIN") or "https://slotslaunch.com",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=45) as response:
            payload = json.loads(response.read().decode("utf-8"))
    except (OSError, urllib.error.URLError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"SlotsLaunch request failed: {exc}") from exc
    if not isinstance(payload, (dict, list)):
        raise RuntimeError("SlotsLaunch API returned an invalid response.")
    if isinstance(payload, dict) and payload.get("error"):
        raise RuntimeError(f"SlotsLaunch API error: {payload['error']}")
    return payload


def response_games(payload: dict[str, Any] | list[Any]) -> list[dict[str, Any]]:
    if isinstance(payload, list):
        values = payload
    else:
        values: Any = payload
        for key in ("games", "data", "items", "results"):
            if isinstance(values, dict) and isinstance(values.get(key), list):
                values = values[key]
                break
        if isinstance(values, dict):
            for key in ("games", "items", "results"):
                if isinstance(values.get(key), list):
                    values = values[key]
                    break
    return [item for item in values if isinstance(item, dict)] if isinstance(values, list) else []


def next_url(payload: dict[str, Any] | list[Any], current: str) -> str | None:
    if not isinstance(payload, dict):
        return None
    meta = payload.get("meta") if isinstance(payload.get("meta"), dict) else payload
    current_page = meta.get("current_page", meta.get("page"))
    last_page = meta.get("last_page", meta.get("total_pages", meta.get("pages")))
    if str(current_page).isdigit() and str(last_page).isdigit():
        if int(current_page) >= int(last_page):
            return None
        return with_page(current, int(current_page) + 1)
    candidate = payload.get("next") or payload.get("next_url") or payload.get("next_page_url")
    if isinstance(payload.get("links"), dict):
        candidate = candidate or payload["links"].get("next")
    if not isinstance(candidate, str) or not candidate.strip():
        return None
    return urllib.parse.urljoin(current, candidate.strip())


def with_page(url: str, page: int) -> str:
    parsed = urllib.parse.urlsplit(url)
    query = urllib.parse.parse_qsl(parsed.query, keep_blank_values=True)
    replaced = False
    for index, (key, value) in enumerate(query):
        if key == "page":
            query[index] = (key, str(page))
            replaced = True
    if not replaced:
        query.append(("page", str(page)))
    return urllib.parse.urlunsplit(parsed._replace(query=urllib.parse.urlencode(query)))


def fetch_games(url: str) -> list[dict[str, Any]]:
    games: list[dict[str, Any]] = []
    seen: set[str] = set()
    current: str | None = url
    while current and current not in seen:
        seen.add(current)
        payload = fetch_json(current)
        games.extend(response_games(payload))
        current = next_url(payload, current)
    return games


def text(value: Any) -> str:
    return str(value).strip() if value is not None and not isinstance(value, (dict, list)) else ""


def first(game: dict[str, Any], keys: tuple[str, ...]) -> Any:
    for key in keys:
        if game.get(key) not in (None, ""):
            return game[key]
    return None


def integer(value: Any) -> int:
    try:
        return int(value)
    except (TypeError, ValueError):
        return 0


def number(value: Any) -> float | None:
    try:
        return float(str(value).replace(",", "").replace("%", ""))
    except (TypeError, ValueError):
        return None


def boolean(value: Any) -> int:
    return int(value is True or str(value).lower().strip() in {"1", "true", "yes", "on"})


def related_name(value: Any) -> tuple[int, str]:
    if isinstance(value, dict):
        name = text(first(value, ("name", "title")))
        identifier = integer(first(value, ("id", "api_id", "provider_id", "type_id")))
    else:
        name = text(value)
        identifier = 0
    if not identifier and name:
        identifier = zlib.crc32(name.lower().encode("utf-8"))
    return identifier, name


def slugify(value: str) -> str:
    result = "".join(char.lower() if char.isalnum() else "-" for char in value)
    while "--" in result:
        result = result.replace("--", "-")
    return result.strip("-")[:160]


def ensure_schema(conn: sqlite3.Connection) -> None:
    columns = {row[1] for row in conn.execute("PRAGMA table_info(games)")}
    if "done_processing" not in columns:
        conn.execute("ALTER TABLE games ADD COLUMN done_processing INTEGER NOT NULL DEFAULT 1")
        conn.commit()


def upsert_lookup(conn: sqlite3.Connection, table: str, value: Any) -> tuple[int | None, str]:
    identifier, name = related_name(value)
    if not identifier or not name:
        return None, name
    conn.execute(
        f"INSERT INTO {table} (api_id, name, created_at, updated_at) VALUES (?, ?, ?, ?) "
        "ON CONFLICT(api_id) DO UPDATE SET name = excluded.name, updated_at = excluded.updated_at",
        (identifier, name, int(time.time()), int(time.time())),
    )
    row = conn.execute(f"SELECT id FROM {table} WHERE api_id = ?", (identifier,)).fetchone()
    return (int(row[0]) if row else None), name


def normalize_game(game: dict[str, Any]) -> dict[str, Any]:
    api_id = integer(first(game, ("id", "api_id", "game_id")))
    name = text(first(game, ("name", "title")))
    if not api_id or not name:
        raise ValueError("game is missing a stable API id or name")
    provider = first(game, ("provider", "provider_name"))
    game_type = first(game, ("type", "category", "type_name"))
    provider_id, provider_name = related_name(provider)
    type_id, type_name = related_name(game_type)
    themes = first(game, ("themes", "theme")) or []
    if not isinstance(themes, list):
        themes = [themes]
    now = int(time.time())
    return {
        "api_id": api_id, "name": name, "slug": text(first(game, ("slug",))) or slugify(name),
        "url": text(first(game, ("url", "game_url", "launch_url", "iframe_url"))),
        "thumb": text(first(game, ("thumb", "thumbnail", "image", "icon"))),
        "provider_id": provider_id, "provider": provider_name, "provider_slug": slugify(provider_name),
        "type_id": type_id, "type": type_name, "type_slug": slugify(type_name),
        "themes": json.dumps([text(item) for item in themes if text(item)], ensure_ascii=False),
        "megaways": boolean(first(game, ("megaways",))), "bonus_buy": boolean(first(game, ("bonus_buy", "buy_bonus"))),
        "progressive": boolean(first(game, ("progressive", "jackpot"))), "featured": boolean(first(game, ("featured",))),
        "release": text(first(game, ("release", "released", "release_date"))), "reels": text(first(game, ("reels",))),
        "rtp": number(first(game, ("rtp",))), "volatility": text(first(game, ("volatility", "variance"))),
        "currencies": json.dumps(first(game, ("currencies",)) or []), "languages": json.dumps(first(game, ("languages", "langs")) or []),
        "land_based": boolean(first(game, ("land_based",))), "markets": json.dumps(first(game, ("markets",)) or []),
        "paylines": text(first(game, ("paylines", "lines"))), "max_exposure": text(first(game, ("max_exposure",))),
        "min_bet": number(first(game, ("min_bet", "minimum_bet"))), "max_bet": number(first(game, ("max_bet", "maximum_bet"))),
        "max_win_per_spin": number(first(game, ("max_win_per_spin", "max_win"))), "autoplay": boolean(first(game, ("autoplay",))),
        "quickspin": boolean(first(game, ("quickspin", "quick_spin"))), "tumbling_reels": boolean(first(game, ("tumbling_reels", "tumble"))),
        "increasing_multipliers": boolean(first(game, ("increasing_multipliers", "increasing_multiplier"))),
        "orientation": text(first(game, ("orientation",))), "restrictions": json.dumps(first(game, ("restrictions",)) or []),
        "upcoming": boolean(first(game, ("upcoming",))), "published": boolean(first(game, ("published", "active", "enabled"))),
        "created_at": now, "updated_at": now,
    }


def save_game(conn: sqlite3.Connection, data: dict[str, Any]) -> int:
    columns = list(data)
    values = [data[column] for column in columns]
    assignments = ", ".join(f"{column} = excluded.{column}" for column in columns if column not in {"api_id", "created_at"})
    conn.execute(
        f"INSERT INTO games ({', '.join(columns)}, done_processing) VALUES ({', '.join('?' for _ in values)}, 0) "
        f"ON CONFLICT(api_id) DO UPDATE SET {assignments}, done_processing = 0",
        values,
    )
    row = conn.execute("SELECT id FROM games WHERE api_id = ?", (data["api_id"],)).fetchone()
    if not row:
        raise RuntimeError("game row was not saved")
    conn.execute("UPDATE games SET done_processing = 1 WHERE id = ?", (int(row[0]),))
    return int(row[0])


def main() -> int:
    configure_logging()
    conn = sqlite3.connect(DB_PATH, timeout=30)
    conn.row_factory = sqlite3.Row
    ensure_schema(conn)
    unfinished = [row for row in conn.execute("SELECT api_id FROM games WHERE done_processing = 0 ORDER BY updated_at ASC, id ASC LIMIT ?", (MAX_GAMES_PER_RUN,))]
    existing_ids = {int(row[0]) for row in conn.execute("SELECT api_id FROM games")}
    games = fetch_games(api_url())
    by_id = {integer(first(game, ("id", "api_id", "game_id"))): game for game in games}
    queued_ids = [int(row["api_id"]) for row in unfinished if int(row["api_id"]) in by_id]
    queued_ids += [integer(first(game, ("id", "api_id", "game_id"))) for game in games if integer(first(game, ("id", "api_id", "game_id"))) not in existing_ids]
    queued_ids = list(dict.fromkeys(identifier for identifier in queued_ids if identifier))[:MAX_GAMES_PER_RUN]
    completed = failed = imported = 0
    for api_id in queued_ids:
        try:
            conn.execute("BEGIN")
            save_game(conn, normalize_game(by_id[api_id]))
            conn.commit()
            completed += 1
            imported += int(api_id not in existing_ids)
        except Exception as exc:
            conn.rollback()
            failed += 1
            LOGGER.exception("game processing failed api_id=%s", api_id)
    remaining = conn.execute("SELECT COUNT(*) FROM games WHERE done_processing = 0").fetchone()[0]
    print(f"Existing unfinished processed: {len(unfinished)}")
    print(f"New games imported: {imported}")
    print(f"Completed: {completed}")
    print(f"Failed: {failed}")
    print(f"Remaining: {remaining}")
    return 1 if failed else 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        configure_logging()
        LOGGER.exception("fatal importer failure")
        print(f"Fatal error: {exc}", file=sys.stderr)
        raise SystemExit(1) from exc
