"""
Google OAuth2 認証ヘルパー
初回実行時にブラウザが開いてGoogleログインを求めます。
認証後はトークンをgoogle-token.jsonに保存し、以降は自動更新されます。

使い方:
  python google_auth.py        # 初回認証・接続テスト
"""

import os, sys
from google_auth_oauthlib.flow import InstalledAppFlow
from google.oauth2.credentials import Credentials
from google.auth.transport.requests import Request
from googleapiclient.discovery import build

BASE_DIR    = os.path.dirname(os.path.abspath(__file__))
CLIENT_FILE = os.path.join(BASE_DIR, "google-oauth-client.json")
TOKEN_FILE  = os.path.join(BASE_DIR, "google-token.json")

SCOPES = [
    "https://www.googleapis.com/auth/drive",
    "https://www.googleapis.com/auth/spreadsheets",
]

def get_credentials():
    creds = None
    if os.path.exists(TOKEN_FILE):
        creds = Credentials.from_authorized_user_file(TOKEN_FILE, SCOPES)
    if not creds or not creds.valid:
        if creds and creds.expired and creds.refresh_token:
            creds.refresh(Request())
        else:
            if not os.path.exists(CLIENT_FILE):
                print(f"ERROR: 鍵ファイルが見つかりません: {CLIENT_FILE}")
                sys.exit(1)
            flow = InstalledAppFlow.from_client_secrets_file(CLIENT_FILE, SCOPES)
            creds = flow.run_local_server(port=0)
        with open(TOKEN_FILE, "w") as f:
            f.write(creds.to_json())
        print(f"トークンを保存しました: {TOKEN_FILE}")
    return creds

if __name__ == "__main__":
    print("Google認証を開始します。ブラウザが開いたらAGOのGoogleアカウントでログインしてください...")
    creds = get_credentials()
    # 接続テスト
    drive = build("drive", "v3", credentials=creds)
    about = drive.about().get(fields="user").execute()
    user  = about.get("user", {})
    print(f"\n✅ 接続成功！")
    print(f"   アカウント: {user.get('displayName', '')} ({user.get('emailAddress', '')})")
    print(f"\nこれでDriveとスプレッドシートへの操作が使えるようになりました。")
