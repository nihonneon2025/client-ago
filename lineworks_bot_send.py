"""
LINE WORKS Bot API v2 メッセージ送信スクリプト
Playwright不使用・純粋HTTP API方式

使い方:
  チャンネル一覧確認: python lineworks_bot_send.py --list-channels
  メッセージ送信:    python lineworks_bot_send.py <channel_id> <message.txt>

依存ライブラリ:
  pip install pyjwt cryptography requests
"""

import json
import sys
import time
from pathlib import Path

try:
    import requests
except ImportError:
    print("[ERROR] pip install requests が必要です")
    sys.exit(1)

try:
    import jwt
except ImportError:
    print("[ERROR] pip install pyjwt cryptography が必要です")
    sys.exit(1)

BASE_DIR     = Path(__file__).parent
CONFIG_FILE  = BASE_DIR / "lineworks-bot-config.json"
PRIVKEY_FILE = BASE_DIR / "lineworks-bot-privatekey.pem"
AUTH_URL     = "https://auth.worksmobile.com/oauth2/v2.0/token"
API_BASE     = "https://www.worksapis.com/v1.0"


def load_config() -> dict:
    if not CONFIG_FILE.exists():
        print(f"[ERROR] 設定ファイルが見つかりません: {CONFIG_FILE}")
        sys.exit(1)
    return json.loads(CONFIG_FILE.read_text(encoding="utf-8"))


def get_access_token(config: dict) -> str:
    if not PRIVKEY_FILE.exists():
        print(f"[ERROR] 秘密鍵が見つかりません: {PRIVKEY_FILE}")
        sys.exit(1)

    private_key = PRIVKEY_FILE.read_text(encoding="utf-8")
    now = int(time.time())

    assertion = jwt.encode(
        {
            "iss": config["client_id"],
            "sub": config["service_account_id"],
            "iat": now,
            "exp": now + 3600,
        },
        private_key,
        algorithm="RS256",
    )

    resp = requests.post(
        AUTH_URL,
        data={
            "grant_type": "urn:ietf:params:oauth:grant-type:jwt-bearer",
            "assertion": assertion,
            "client_id": config["client_id"],
            "client_secret": config["client_secret"],
            "scope": "bot",
        },
        timeout=15,
    )

    if not resp.ok:
        print(f"[ERROR] トークン取得失敗 {resp.status_code}: {resp.text}")
        sys.exit(1)

    return resp.json()["access_token"]


def list_channels(token: str, bot_id: str) -> dict:
    resp = requests.get(
        f"{API_BASE}/bots/{bot_id}/channels",
        headers={"Authorization": f"Bearer {token}"},
        timeout=10,
    )
    if not resp.ok:
        print(f"[ERROR] チャンネル一覧取得失敗 {resp.status_code}: {resp.text}")
        sys.exit(1)
    return resp.json()


def send_message(token: str, bot_id: str, channel_id: str, message: str) -> dict:
    resp = requests.post(
        f"{API_BASE}/bots/{bot_id}/channels/{channel_id}/messages",
        headers={
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/json",
        },
        json={"content": {"type": "text", "text": message}},
        timeout=15,
    )
    if not resp.ok:
        print(f"[ERROR] 送信失敗 {resp.status_code}: {resp.text}")
        sys.exit(1)
    return resp.json()


if __name__ == "__main__":
    args = sys.argv[1:]
    config = load_config()

    if "--list-channels" in args:
        token = get_access_token(config)
        result = list_channels(token, config["bot_id"])
        print(json.dumps(result, ensure_ascii=False, indent=2))
        sys.exit(0)

    if len(args) < 2:
        print("使い方:")
        print("  チャンネル一覧: python lineworks_bot_send.py --list-channels")
        print("  メッセージ送信: python lineworks_bot_send.py <channel_id> <message.txt>")
        sys.exit(1)

    channel_id = args[0]
    msg_path = Path(args[1])
    if not msg_path.exists():
        print(f"[ERROR] メッセージファイルが見つかりません: {msg_path}")
        sys.exit(1)

    message = msg_path.read_text(encoding="utf-8")
    token = get_access_token(config)
    result = send_message(token, config["bot_id"], channel_id, message)
    print(f"[OK] 送信完了: {result}")
