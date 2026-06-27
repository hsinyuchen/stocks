import argparse
import json
import re
import sys
import urllib.request


FAKE = [
    {
        "source": "youtube",
        "title": "Fake market video",
        "summary": "Deterministic fake normalized payload.",
        "topic": "macro",
        "related_symbols": ["QQQ", "2330.TW"],
        "published_at": "2026-06-20T00:00:00+00:00",
        "url": "https://www.youtube.com/watch?v=FAKE",
        "language": "en",
        "market": "US",
        "kind": "video",
    }
]


def discover(channel_id, limit):
    url = f"https://www.youtube.com/feeds/videos.xml?channel_id={channel_id}"
    req = urllib.request.Request(
        url, headers={"User-Agent": "Mozilla/5.0 (compatible; StockRadar/1.0)"}
    )
    data = urllib.request.urlopen(req, timeout=20).read().decode("utf-8", "ignore")
    out = []
    for e in re.findall(r"<entry>(.*?)</entry>", data, re.S)[:limit]:
        vid = re.search(r"<yt:videoId>([^<]+)</yt:videoId>", e)
        if not vid:
            continue
        title = re.search(r"<media:title>([^<]+)</media:title>", e) or re.search(
            r"<title>([^<]+)</title>", e
        )
        pub = re.search(r"<published>([^<]+)</published>", e)
        link = re.search(r'<link rel="alternate" href="([^"]+)"', e)
        desc = re.search(r"<media:description>([^<]*)</media:description>", e, re.S)
        out.append(
            {
                "video_id": vid.group(1),
                "title": (title.group(1).strip() if title else ""),
                "url": (
                    link.group(1)
                    if link
                    else f"https://www.youtube.com/watch?v={vid.group(1)}"
                ),
                "published_at": (pub.group(1) if pub else ""),
                "description": (desc.group(1).strip() if desc else ""),
            }
        )
    return out


def transcript(video_id, languages):
    from youtube_transcript_api import YouTubeTranscriptApi

    fetched = YouTubeTranscriptApi().fetch(video_id, languages=languages)
    return " ".join(s.text for s in fetched)


def run(payload):
    limit = int(payload.get("per_channel_limit", 5))
    languages = payload.get("languages", ["zh-TW", "zh-Hant", "zh", "en"])
    excerpt = int(payload.get("excerpt_chars", 1500))
    items = []
    for ch in payload.get("channels", []):
        try:
            vids = discover(ch["channel_id"], limit)
        except Exception:
            continue
        for v in vids:
            summary = v["description"]
            try:
                t = transcript(v["video_id"], languages)
                if t:
                    summary = t[:excerpt]
            except Exception:
                pass  # no captions -> keep description
            items.append(
                {
                    "source": ch.get("name", "youtube"),
                    "title": v["title"],
                    "summary": summary,
                    "topic": "macro",
                    "related_symbols": [],
                    "published_at": v["published_at"],
                    "url": v["url"],
                    "language": ch.get("language", "zh-TW"),
                    "market": ch.get("market"),
                    "kind": "video",
                }
            )
    return items


def main():
    parser = argparse.ArgumentParser(
        description="Normalize YouTube finance video payloads."
    )
    parser.add_argument(
        "--fake",
        action="store_true",
        help="Emit a deterministic fake normalized payload.",
    )
    args = parser.parse_args()

    if args.fake:
        print(json.dumps(FAKE, ensure_ascii=False))
        return

    print(json.dumps(run(json.load(sys.stdin)), ensure_ascii=False))


if __name__ == "__main__":
    main()
