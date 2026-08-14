#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import os
import random
import re
import sqlite3
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
DB_PATH = ROOT / "storage" / "blogs.sqlite"
PREFERRED_MODELS = [
    "qwen3.5:9b",
    "qwen3:8b",
    "qwen3.6:latest",
    "gemma4:31b-cloud",
    "tinyllama:latest",
]


def env_value(name: str) -> str | None:
    value = os.environ.get(name)
    if value:
        return value.strip().strip('"').strip("'")

    env_path = ROOT / ".env"
    if not env_path.exists():
        return None

    for line in env_path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, raw = line.split("=", 1)
        if key.strip() == name:
            return raw.strip().strip('"').strip("'")

    return None


def ollama_base_url() -> str:
    return (env_value("OLLAMA_HOST") or "http://127.0.0.1:11434").rstrip("/")


def installed_models() -> list[str]:
    try:
        with urllib.request.urlopen(f"{ollama_base_url()}/api/tags", timeout=10) as response:
            payload = json.loads(response.read().decode("utf-8"))
    except Exception:
        return []

    models = payload.get("models", [])
    return [item["name"] for item in models if isinstance(item, dict) and isinstance(item.get("name"), str)]


def default_model() -> str:
    models = installed_models()
    for preferred in PREFERRED_MODELS:
        if preferred in models:
            return preferred
    return models[0] if models else "qwen3.5:9b"


def random_rtp() -> float:
    return random.randint(7000, 9900) / 100


def random_volatility() -> str:
    return random.choice(["low", "medium", "high"])


def normalized_existing_volatility(value: Any) -> str:
    text = str(value or "").strip().lower()
    return text if text in {"low", "medium", "high"} else ""


def clamp_short_description(value: str, max_length: int = 159) -> str:
    value = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", "", value)).strip()
    if len(value) <= max_length:
        return value
    return value[: max_length - 1].rstrip(" \t\n\r.,;:") + "."


def game_theme_text(game: sqlite3.Row) -> str:
    try:
        themes = json.loads(game["themes"] or "[]")
    except json.JSONDecodeError:
        themes = []
    if not isinstance(themes, list):
        return ""
    return ", ".join(str(theme) for theme in themes[:5] if str(theme).strip())


def game_context(game: sqlite3.Row, rtp: float, volatility: str) -> str:
    lines = [
        f"Name: {game['name']}",
        f"Provider: {game['provider'] or 'Unknown'}",
        f"Type: {game['type'] or 'Slot'}",
    ]
    themes = game_theme_text(game)
    if themes:
        lines.append(f"Themes: {themes}")
    lines.append(f"RTP: {rtp:.2f}%")
    lines.append(f"Volatility: {volatility}")
    if game["reels"]:
        lines.append(f"Reels: {game['reels']}")
    if game["paylines"]:
        lines.append(f"Paylines: {game['paylines']}")
    return "\n".join(lines)


def fallback_generated_copy(game: sqlite3.Row, rtp: float, volatility: str) -> dict[str, str]:
    name = (game["name"] or "This game").strip() or "This game"
    provider = (game["provider"] or "the provider").strip() or "the provider"
    game_type = (game["type"] or "slot").strip() or "slot"
    themes = game_theme_text(game)
    theme_sentence = (
        f"Its theme profile includes {themes}, giving the round structure a clear visual direction."
        if themes
        else "Its presentation is built around clear reels, readable symbols, and a straightforward play flow."
    )
    mechanics = []
    if game["reels"]:
        mechanics.append(f"{game['reels']} reels")
    if game["paylines"]:
        mechanics.append(f"{game['paylines']} paylines")
    mechanic_sentence = (
        "The saved game data lists "
        + " and ".join(mechanics)
        + ", which helps players understand the basic layout before opening the demo."
        if mechanics
        else "The layout should be reviewed in demo mode first, since reel count, paylines, and feature triggers can vary by title."
    )
    short = clamp_short_description(
        f"{name} is a {game_type} from {provider} with {rtp:.2f}% RTP and {volatility.lower()} volatility."
    )
    paragraphs = [
        "Overview & Game Mechanics",
        f"{name} is an informational game listing for players who want to understand the basic feel of the title before opening it in demo mode. The game is categorized as a {game_type} and is associated with {provider}. While the exact symbol set and bonus behavior should always be confirmed inside the live game client, the available data gives enough context to outline how players can approach the experience responsibly. {theme_sentence}",
        f"From a mechanics perspective, the first thing to check is how the base game presents its rounds. Most modern casino games use a repeating spin or instant-win cycle where the player chooses a stake, starts a round, and waits for the result animation to resolve. {mechanic_sentence} A demo session is useful because it lets players inspect controls, bet increments, audio settings, autoplay options, and feature screens without using real funds.",
        f"The estimated RTP for this listing is {rtp:.2f}%. RTP, or return to player, is a long-term mathematical indicator rather than a short-session prediction. A title with this RTP can still produce uneven outcomes over a small number of rounds. That is why RTP should be read together with volatility. This game is marked as {volatility.lower()} volatility, which describes how payouts may be distributed. Lower volatility usually points toward steadier but smaller results, while higher volatility can mean longer quiet stretches mixed with larger feature potential.",
        f"The practical way to evaluate {name} is to start with the free demo. In demo mode, players can watch how frequently special symbols appear, how quickly rounds resolve, and whether the feature pacing feels comfortable. The goal is not to predict a win, but to understand the rhythm of play. If the game includes bonus rounds, multipliers, respins, free spins, or collection mechanics, those features should be treated as entertainment elements rather than guaranteed value.",
        "Bankroll pacing matters even when a title looks simple. A sensible approach is to choose a stake that allows many rounds instead of placing a large amount into only a few spins. This gives a clearer picture of the game flow and reduces the pressure of short-term variance. Players should also check whether quick spin or autoplay options are enabled, because those settings can make a balance move faster than expected.",
        "On mobile, readability and control spacing are especially important. Before playing for real, users should confirm that the buttons are easy to tap, the paytable can be opened clearly, and the game frame fits the screen without hiding important controls. A smooth mobile demo is a good sign that the title will be easier to understand during longer sessions.",
        f"Overall, {name} should be approached as a game to inspect first and play carefully if moving beyond demo mode. Review the paytable, understand the stake controls, note the volatility level, and avoid treating any single round as representative of the long-term math. The best use of this page is to preview the mechanics, compare the title with other games, and decide whether its pacing matches your preferred style of casino entertainment.",
    ]
    return {"short_description": short, "long_description": "\n\n".join(paragraphs)}


def build_prompt(game: sqlite3.Row, rtp: float, volatility: str) -> str:
    return (
        "Do not use thinking mode. Return the final answer only as valid JSON.\n"
        "Create original informational casino game copy from the facts below.\n\n"
        f"{game_context(game, rtp, volatility)}\n\n"
        "Return only valid JSON with exactly these keys:\n"
        "- short_description: under 160 characters, one sentence, no hype claims.\n"
        '- long_description: 650 to 800 words, written under the topic "Overview & Game Mechanics". '
        "Explain likely gameplay flow, symbols/features in general terms, RTP, volatility, demo play, bankroll pacing, and mobile experience. "
        "Do not promise winnings. Do not mention that you are an AI. Do not invent official license details.\n"
    )


def decode_generated_json(content: str) -> dict[str, Any] | None:
    content = content.strip()
    if not content:
        return None
    try:
        decoded = json.loads(content)
        return decoded if isinstance(decoded, dict) else None
    except json.JSONDecodeError:
        pass
    fenced = re.search(r"```(?:json)?\s*(\{.*?\})\s*```", content, re.I | re.S)
    if fenced:
        try:
            decoded = json.loads(fenced.group(1))
            return decoded if isinstance(decoded, dict) else None
        except json.JSONDecodeError:
            pass
    start, end = content.find("{"), content.rfind("}")
    if start != -1 and end > start:
        try:
            decoded = json.loads(content[start : end + 1])
            return decoded if isinstance(decoded, dict) else None
        except json.JSONDecodeError:
            return None
    return None


def ollama_generate(url: str, model: str, prompt: str, timeout: int) -> dict[str, Any]:
    payload = {
        "model": model,
        "prompt": prompt,
        "stream": False,
        "format": "json",
        "think": False,
        "options": {"temperature": 0.75, "top_p": 0.9, "num_predict": 1400},
    }
    request = urllib.request.Request(
        url,
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            raw = response.read().decode("utf-8")
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"Ollama returned HTTP {exc.code}: {body[:300]}") from exc

    if not raw:
        raise RuntimeError("Ollama returned an empty response.")
    decoded = json.loads(raw)
    content = str(decoded.get("response", "")).strip()
    thinking = decoded.get("thinking")
    if not content and isinstance(thinking, str):
        raise RuntimeError(f"Ollama finished thinking but returned an empty final response. Thinking chars: {len(thinking)}")
    result = decode_generated_json(content)
    if result is None:
        raise RuntimeError(f"Ollama response was not valid content JSON: {content[:300]}")
    return result


def ollama_generate_with_retries(url: str, model: str, prompt: str, timeout: int, retries: int) -> dict[str, Any]:
    last_error: Exception | None = None
    for attempt in range(1, max(1, retries + 1) + 1):
        try:
            return ollama_generate(url, model, prompt, timeout)
        except Exception as exc:
            last_error = exc
            if attempt <= retries:
                print(f"Ollama attempt {attempt} failed: {exc}", file=sys.stderr)
                time.sleep(min(5, attempt))
    raise RuntimeError(str(last_error or "Ollama generation failed."))


def normalize_long_description(value: str) -> str:
    value = value.strip()
    if not value:
        return ""
    if not value.lower().startswith("overview & game mechanics"):
        value = "Overview & Game Mechanics\n\n" + value
    return value


def select_games(conn: sqlite3.Connection, args: argparse.Namespace) -> list[sqlite3.Row]:
    where = []
    params: dict[str, Any] = {}
    if not args.overwrite:
        where.append(
            """(
                rtp IS NULL OR rtp = "" OR
                volatility IS NULL OR volatility = "" OR LOWER(volatility) NOT IN ("low", "medium", "high") OR
                short_description IS NULL OR short_description = "" OR
                long_description IS NULL OR long_description = ""
            )"""
        )
    if args.slug:
        where.append("slug = :slug")
        params["slug"] = args.slug.strip()
    where_sql = "WHERE " + " AND ".join(where) if where else ""
    sql = f"""
        SELECT id, name, slug, provider, type, themes, reels, paylines, rtp, volatility,
               short_description, long_description
        FROM games
        {where_sql}
        ORDER BY updated_at DESC, id DESC
        LIMIT :limit OFFSET :offset
    """
    params["limit"] = args.limit
    params["offset"] = args.offset
    return list(conn.execute(sql, params))


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Enrich games with RTP, volatility, and descriptions.")
    parser.add_argument("--limit", type=int, default=25)
    parser.add_argument("--offset", type=int, default=0)
    parser.add_argument("--timeout", type=int, default=420)
    parser.add_argument("--retries", type=int, default=1)
    parser.add_argument("--model", default=env_value("OLLAMA_MODEL") or default_model())
    parser.add_argument("--ollama-url", default=(env_value("OLLAMA_GENERATE_URL") or f"{ollama_base_url()}/api/generate"))
    parser.add_argument("--slug")
    parser.add_argument("--overwrite", action="store_true", help="Rewrite existing RTP, volatility, and descriptions.")
    parser.add_argument("--regenerate", action="store_true", help="Alias for --overwrite.")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--skip-errors", action="store_true")
    parser.add_argument("--fallback-only", action="store_true")
    parser.add_argument("--once", action="store_true", help="Process one batch only, using --limit and --offset.")
    parser.add_argument("--verbose", action="store_true", help="Print each game slug while processing.")
    return parser.parse_args()


def process_batch(conn: sqlite3.Connection, args: argparse.Namespace) -> tuple[int, int]:
    games = select_games(conn, args)
    print(f"Found {len(games)} game(s) to enrich.")

    updated = 0
    for game in games:
        rtp = random_rtp() if args.overwrite or game["rtp"] in (None, "") else float(game["rtp"])
        existing_volatility = normalized_existing_volatility(game["volatility"])
        volatility = random_volatility() if args.overwrite or existing_volatility == "" else existing_volatility
        if args.verbose:
            print(f"Generating: {game['slug']}")

        generated: dict[str, Any] | None = None
        if not args.fallback_only:
            try:
                generated = ollama_generate_with_retries(
                    args.ollama_url,
                    args.model,
                    build_prompt(game, rtp, volatility),
                    args.timeout,
                    args.retries,
                )
            except Exception as exc:
                if args.skip_errors:
                    print(f"Skipped {game['slug']}: {exc}", file=sys.stderr)
                    continue
                print(f"Using fallback for {game['slug']}: {exc}", file=sys.stderr)

        if generated is None:
            generated = fallback_generated_copy(game, rtp, volatility)

        short = (
            clamp_short_description(str(generated.get("short_description", "")))
            if args.overwrite or not str(game["short_description"] or "").strip()
            else str(game["short_description"])
        )
        long = (
            normalize_long_description(str(generated.get("long_description", "")))
            if args.overwrite or not str(game["long_description"] or "").strip()
            else str(game["long_description"])
        )
        if not short or not long:
            raise RuntimeError(f"Could not generate required descriptions for {game['slug']}.")

        if args.dry_run:
            print(f"Dry run: RTP {rtp:.2f}, volatility {volatility}, short {len(short)} chars.")
            continue

        conn.execute(
            """
            UPDATE games
            SET rtp = :rtp,
                volatility = :volatility,
                short_description = :short_description,
                long_description = :long_description,
                updated_at = :updated_at
            WHERE id = :id
            """,
            {
                "rtp": rtp,
                "volatility": volatility,
                "short_description": short,
                "long_description": long,
                "updated_at": int(time.time()),
                "id": game["id"],
            },
        )
        conn.commit()
        updated += 1

    print(f"Batch updated {updated} of {len(games)} game(s).")
    return len(games), updated


def main() -> int:
    args = parse_args()
    if args.regenerate:
        args.overwrite = True
    args.limit = max(1, min(args.limit, 500))
    args.offset = max(0, args.offset)
    args.timeout = max(30, min(args.timeout, 3600))
    args.retries = max(0, min(args.retries, 5))

    conn = sqlite3.connect(DB_PATH, timeout=30)
    conn.row_factory = sqlite3.Row

    print(f"Using Ollama model {args.model} at {args.ollama_url}")
    print(
        f"Timeout {args.timeout}s, retries {args.retries}"
        + (", skip errors on" if args.skip_errors else "")
        + (", fallback only on" if args.fallback_only else "")
        + (", regenerate on" if args.regenerate else "")
        + (", once on" if args.once else "")
        + "."
    )

    total_updated = 0
    batch = 1
    run_once = args.once or args.dry_run or bool(args.slug) or args.overwrite or args.offset > 0
    while True:
        if not run_once:
            args.offset = 0
        print(f"Batch {batch}")
        found, updated = process_batch(conn, args)
        total_updated += updated

        if run_once or found == 0:
            break
        if updated == 0:
            print("Stopping because this batch did not update any rows.")
            break

        batch += 1

    print(f"Updated {total_updated} game(s).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
