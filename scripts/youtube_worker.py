import argparse
import json


def fake_items():
    return [
        {
            "source": "youtube",
            "title": "Fake market video",
            "summary": "Transcript cleanup and chunking will be added when real YouTube ingestion is implemented.",
            "topic": "macro",
            "related_symbols": ["QQQ", "2330.TW"],
            "published_at": "2026-06-20T00:00:00+00:00",
        }
    ]


def main():
    parser = argparse.ArgumentParser(description="Normalize YouTube finance video payloads.")
    parser.add_argument("--fake", action="store_true", help="Emit a deterministic fake normalized payload.")
    args = parser.parse_args()

    if args.fake:
        print(json.dumps(fake_items(), ensure_ascii=False))
        return

    raise SystemExit("Only --fake mode is implemented in the foundation MVP.")


if __name__ == "__main__":
    main()
