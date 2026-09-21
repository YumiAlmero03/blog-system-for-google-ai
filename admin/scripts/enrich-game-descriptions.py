#!/usr/bin/env python3
# python3 -m scripts.enrich_game_descriptions /help
# --limit 25 = limits the number of games processed per batch
# --offset 0 = skips the first N games in the query 
# --timeout 420 = seconds to wait for Ollama to respond
# --retries 1 = number of times to retry Ollama generation on failure
# --model = Ollama model to use (default: env OLLAMA_MODEL or first installed preferred model)
# --ollama-url = Ollama generate endpoint (default: env OLLAMA_GENERATE
# --slug = process only the game with this slug
# --overwrite = overwrite existing values even if they are present
# --dry-run = do not update the database, just print what would be done
# --skip-errors = skip games that fail Ollama generation instead of stopping the script
# --fallback-only = do not use Ollama, only use the fallback generation method
# --all-games = enrich all publicly eligible rows, respecting rewrite status
# --blog-reset = clear all blog posts and engagements
# --tracker-reset = clear the blog tracker file
# --once = process one batch only, using --limit and --offset
# --verbose = print each game slug while processing
from __future__ import annotations

import argparse
import json
import logging
import os
import random
import re
import sqlite3
import subprocess
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any
from functools import lru_cache


ROOT = Path(__file__).resolve().parents[1]
DB_PATH = ROOT / "storage" / "blogs.sqlite"
LOG_PATH = ROOT / "storage" / "enrich-game-descriptions.log"
LOGGER = logging.getLogger("enrich-game-descriptions")
PREFERRED_MODELS = [
    "qwen3.5:9b",
    "qwen3:8b",
    "qwen3.6:latest",
    "gemma4:31b-cloud",
    "tinyllama:latest",
]


def configure_logging() -> None:
    LOG_PATH.parent.mkdir(parents=True, exist_ok=True)
    handler = logging.FileHandler(LOG_PATH, encoding="utf-8")
    handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
    LOGGER.setLevel(logging.INFO)
    LOGGER.handlers.clear()
    LOGGER.addHandler(handler)
    LOGGER.propagate = False


def prevent_game_directory_writes() -> None:
    forbidden_roots = (ROOT / "game", ROOT / "games")
    for root in forbidden_roots:
        if root.exists() and not root.is_dir():
            raise RuntimeError(f"Refusing to use non-directory game path: {root}")


def log_error(action: str, error: Exception, game: sqlite3.Row | None = None) -> None:
    subject = f" game_id={game['id']} game_name={game['name']!r}" if game is not None else ""
    LOGGER.exception("%s command=%s%s error=%s", action, COMMAND_CONTEXT, subject, error)


COMMAND_CONTEXT = "script " + " ".join(argument.split("=", 1)[0] for argument in sys.argv[1:])
configure_logging()


def env_value(name: str) -> str | None:
    value = os.environ.get(name)
    if value:
        return value.strip().strip('"').strip("'")

    env_path = ROOT / ".env"
    if not env_path.exists():
        return None

    try:
        lines = env_path.read_text(encoding="utf-8", errors="ignore").splitlines()
    except OSError as exc:
        LOGGER.warning("file operation command=%s file=.env error=%s", COMMAND_CONTEXT, exc)
        return None

    for line in lines:
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
    except Exception as exc:
        LOGGER.warning("API call command=%s endpoint=/api/tags error=%s", COMMAND_CONTEXT, exc)
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
        "- long_description: 650 to 800 words of valid HTML content using only <h2>, <h3>, and <p> for structure. "
        "The very first content must be a normal introduction paragraph using <p>. "
        "Do not place any <h2> before the introduction. Do not use an <h1>. Do not use Markdown headings. "
        "Do not generate an Overview heading in any form, including 'Overview & Game Mechanics' or '<h2>Overview of [Game Name]</h2>'. "
        "After the introduction, use natural, relevant <h2> and <h3> sections tailored to the actual game facts, such as how the game works, symbols and features, bonus or special features when supported, RTP and volatility, demo play and bankroll pacing, mobile experience, and final thoughts. "
        "Keep headings specific to the title and avoid repetitive or generic wording. Do not invent game-specific features that are not provided. "
        "Do not promise winnings. Do not mention that you are an AI. Do not invent official license details. "
        "Return valid JSON only, with exactly short_description and long_description and no extra fields.\n"
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
        raise RuntimeError(f"Ollama returned HTTP {exc.code}") from exc

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
        value = "\n\n" + value
    return value


def ensure_rewrite_status_column(conn: sqlite3.Connection) -> None:
    try:
        columns = {row[1] for row in conn.execute("PRAGMA table_info(games)")}
        if "rewrite_status" not in columns:
            conn.execute("ALTER TABLE games ADD COLUMN rewrite_status TEXT NOT NULL DEFAULT 'pending'")
            conn.commit()
    except Exception as exc:
        log_error("database schema operation", exc)
        raise


@lru_cache(maxsize=1)
def public_eligibility_sql() -> dict[str, str]:
    # Execute the same PHP helpers used by slot-list.php, never a Python translation.
    result = subprocess.run(
        ["php", str(ROOT / "scripts" / "game-eligibility-sql.php")],
        capture_output=True, text=True, check=True, timeout=15,
    )
    return json.loads(result.stdout)


def select_games(conn: sqlite3.Connection, args: argparse.Namespace, include_done: bool = True) -> list[sqlite3.Row]:
    where = []
    params: dict[str, Any] = {}
    if not args.overwrite or not include_done:
        where.append("(rewrite_status IS NULL OR rewrite_status <> 'done')")
    if not args.overwrite and not args.all_games:
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
    rules = public_eligibility_sql()
    candidate_where = "WHERE " + " AND ".join(where) if where else ""
    counts = conn.execute(f"""
        SELECT COUNT(*) AS candidates,
            COALESCE(SUM(CASE WHEN {rules['eligible']} THEN 1 ELSE 0 END),0) AS eligible,
            COALESCE(SUM(CASE WHEN is_viewable <> 1 THEN 1 ELSE 0 END),0) AS hidden,
            COALESCE(SUM(CASE WHEN NOT ({rules['provider']}) THEN 1 ELSE 0 END),0) AS unapproved,
            COALESCE(SUM(CASE WHEN NOT ({rules['ph_allowed']}) THEN 1 ELSE 0 END),0) AS ph,
            COALESCE(SUM(CASE WHEN published <> 1 OR done_processing <> 1 THEN 1 ELSE 0 END),0) AS unpublished
        FROM games {candidate_where}
    """, params).fetchone()
    print(f"Eligible games: {counts['eligible']} of {counts['candidates']} candidates; "
          f"skipped hidden: {counts['hidden']}; unapproved provider: {counts['unapproved']}; "
          f"PH restricted: {counts['ph']}; unpublished/unprocessed: {counts['unpublished']} "
          "(skip reasons may overlap).")
    where.append(f"({rules['eligible']})")
    where_sql = "WHERE " + " AND ".join(where)
    limit_sql = "" if args.all_games else "LIMIT :limit OFFSET :offset"
    sql = f"""
        SELECT id, name, slug, provider, type, themes, reels, paylines, rtp, volatility,
             short_description, long_description, rewrite_status
        FROM games
        {where_sql}
        ORDER BY updated_at DESC, id DESC
        {limit_sql}
    """
    if not args.all_games:
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
    parser.add_argument("--overwrite", action="store_true")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--skip-errors", action="store_true")
    parser.add_argument("--fallback-only", action="store_true")
    operations = parser.add_mutually_exclusive_group()
    operations.add_argument("--all-games", action="store_true", help="Enrich all publicly eligible game rows, respecting rewrite status.")
    operations.add_argument("--blog-reset", action="store_true", help="Clear blog posts.")
    operations.add_argument("--tracker-reset", action="store_true", help="Clear the blog tracker.")
    operations.add_argument("--reset", action="store_true", help="Clear blog, tracker, engagement, and game description data.")
    parser.add_argument("--once", action="store_true", help="Process one batch only, using --limit and --offset.")
    parser.add_argument("--verbose", action="store_true", help="Print each game slug while processing.")
    return parser.parse_args()


def reset_blogs(conn: sqlite3.Connection) -> int:
    try:
        engagements = conn.execute("DELETE FROM blog_engagements").rowcount
        posts = conn.execute("DELETE FROM blog_posts").rowcount
        conn.commit()
        print(f"Cleared {posts} blog post(s) and {engagements} engagement(s).")
        return posts
    except Exception as exc:
        log_error("reset blog records", exc)
        raise


def reset_tracker() -> None:
    try:
        storage_dir = env_value("APP_STORAGE_DIR") or str(ROOT / "storage")
        tracker_path = Path(storage_dir) / "playnow-clicks.json"
        tracker_path.parent.mkdir(parents=True, exist_ok=True)
        tracker_path.write_text("{}\n", encoding="utf-8")
        print("Cleared blog tracker.")
    except Exception as exc:
        log_error("reset tracker file", exc)
        raise


def reset_all(conn: sqlite3.Connection) -> None:
    savepoint = "enrichment_reset"
    try:
        conn.execute(f"SAVEPOINT {savepoint}")
        engagements = conn.execute("DELETE FROM blog_engagements").rowcount
        posts = conn.execute("DELETE FROM blog_posts").rowcount
        games = conn.execute(
            "UPDATE games SET short_description = '', long_description = '', rewrite_status = 'pending'"
        ).rowcount
        conn.execute(f"RELEASE SAVEPOINT {savepoint}")
    except Exception:
        error = sys.exc_info()[1]
        conn.execute(f"ROLLBACK TO SAVEPOINT {savepoint}")
        conn.execute(f"RELEASE SAVEPOINT {savepoint}")
        if isinstance(error, Exception):
            log_error("reset database records", error)
        raise

    reset_tracker()
    print(f"Reset complete: cleared {posts} blog post(s), {engagements} engagement(s), {games} game description(s), and tracker records.")


def set_rewrite_status(conn: sqlite3.Connection, game_id: int, status: str) -> None:
    try:
        conn.execute("UPDATE games SET rewrite_status = :status WHERE id = :id", {"status": status, "id": game_id})
        conn.commit()
    except Exception as exc:
        log_error(f"database status update status={status} game_id={game_id}", exc)
        raise


def process_batch(conn: sqlite3.Connection, args: argparse.Namespace, attempt: int = 1) -> tuple[int, int]:
    try:
        games = select_games(conn, args, include_done=attempt == 1)
    except Exception as exc:
        log_error("database game selection", exc)
        raise
    print(f"Found {len(games)} game(s) to enrich.")

    updated = 0
    for game in games:
        game_id = int(game["id"])
        if not args.dry_run:
            set_rewrite_status(conn, game_id, "processing")
        rtp = random_rtp() if args.overwrite or game["rtp"] in (None, "") else float(game["rtp"])
        existing_volatility = normalized_existing_volatility(game["volatility"])
        volatility = random_volatility() if args.overwrite or existing_volatility == "" else existing_volatility
        print(f"Game {game_id} {game['name']} attempt {attempt}")

        try:
            generated = fallback_generated_copy(game, rtp, volatility) if args.fallback_only else ollama_generate_with_retries(
                args.ollama_url,
                args.model,
                build_prompt(game, rtp, volatility),
                args.timeout,
                args.retries,
            )
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
                raise RuntimeError("missing generated description")
        except Exception as exc:
            if not args.dry_run:
                set_rewrite_status(conn, game_id, "failed")
            log_error(f"game enrichment attempt={attempt}", exc, game)
            print(f"Game {game_id} {game['name']} attempt {attempt} failed: {exc}", file=sys.stderr)
            continue

        if args.dry_run:
            print(f"Game {game_id} {game['name']} final status dry-run")
            continue

        try:
            conn.execute(
                """
                UPDATE games
                SET rtp = :rtp,
                    volatility = :volatility,
                    short_description = :short_description,
                    long_description = :long_description,
                    rewrite_status = 'done',
                    updated_at = :updated_at
                WHERE id = :id
                """,
                {
                    "rtp": rtp,
                    "volatility": volatility,
                    "short_description": short,
                    "long_description": long,
                    "updated_at": int(time.time()),
                    "id": game_id,
                },
            )
            conn.commit()
            updated += 1
            print(f"Game {game_id} {game['name']} final status done")
        except Exception as exc:
            conn.rollback()
            try:
                set_rewrite_status(conn, game_id, "failed")
            except Exception:
                pass
            log_error(f"game database save attempt={attempt}", exc, game)
            print(f"Game {game_id} {game['name']} attempt {attempt} failed: {exc}", file=sys.stderr)

    print(f"Batch updated {updated} of {len(games)} game(s).")
    return len(games), updated


def main() -> int:
    prevent_game_directory_writes()
    args = parse_args()
    args.limit = max(1, min(args.limit, 500))
    args.offset = max(0, args.offset)
    args.timeout = max(30, min(args.timeout, 3600))
    args.retries = max(0, min(args.retries, 5))

    conn = sqlite3.connect(DB_PATH, timeout=30)
    conn.row_factory = sqlite3.Row
    ensure_rewrite_status_column(conn)

    if args.blog_reset:
        reset_blogs(conn)
        return 0
    if args.tracker_reset:
        reset_tracker()
        return 0
    if args.reset:
        reset_all(conn)
        return 0

    print(f"Using Ollama model {args.model} at {args.ollama_url}")
    print(
        f"Timeout {args.timeout}s, retries {args.retries}"
        + (", skip errors on" if args.skip_errors else "")
        + (", fallback only on" if args.fallback_only else "")
        + (", once on" if args.once else "")
        + "."
    )

    total_updated = 0
    batch = 1
    run_once = args.all_games or args.once or args.dry_run or bool(args.slug) or args.overwrite or args.offset > 0
    max_attempts = 3 if args.all_games else 1
    while True:
        if not run_once:
            args.offset = 0
        print(f"Batch {batch}")
        found, updated = process_batch(conn, args, batch)
        total_updated += updated

        if run_once and (not args.all_games or batch >= max_attempts):
            break
        if found == 0 or batch >= max_attempts:
            break
        if updated == 0 and not args.all_games:
            print("Stopping because this batch did not update any rows.")
            break

        batch += 1

    print(f"Updated {total_updated} game(s).")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except SystemExit:
        raise
    except Exception as exc:
        log_error("fatal script failure", exc)
        print(f"Fatal error: {exc}", file=sys.stderr)
        raise SystemExit(1) from exc
