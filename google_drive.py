"""
Google Drive 操作スクリプト
使い方:
  python google_drive.py upload   --file /path/to/file.pdf --folder-id FOLDER_ID
  python google_drive.py download --file-id FILE_ID --output /path/to/save
  python google_drive.py mkdir    --name フォルダ名 --parent-id PARENT_FOLDER_ID
  python google_drive.py list     --folder-id FOLDER_ID
  python google_drive.py url      --file-id FILE_ID

サービスアカウント鍵ファイルの場所:
  C:\\Users\\Administrator\\Desktop\\AI版AGO\\google-service-account.json
"""

import sys, os, json, argparse, mimetypes
from googleapiclient.discovery import build
from googleapiclient.http import MediaFileUpload, MediaIoBaseDownload
import io

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, BASE_DIR)
from google_auth import get_credentials

def get_service():
    return build("drive", "v3", credentials=get_credentials())

def cmd_upload(args):
    service = get_service()
    if not os.path.exists(args.file):
        print(f"ERROR: ファイルが見つかりません: {args.file}", file=sys.stderr)
        sys.exit(1)
    mime, _ = mimetypes.guess_type(args.file)
    mime = mime or "application/octet-stream"
    name = args.name or os.path.basename(args.file)
    meta = {"name": name}
    if args.folder_id:
        meta["parents"] = [args.folder_id]
    media = MediaFileUpload(args.file, mimetype=mime, resumable=True)
    f = service.files().create(body=meta, media_body=media, fields="id,name,webViewLink").execute()
    print(json.dumps({"id": f["id"], "name": f["name"], "url": f.get("webViewLink", "")}, ensure_ascii=False))

def cmd_download(args):
    service = get_service()
    out = args.output or os.path.join(os.getcwd(), args.file_id)
    request = service.files().get_media(fileId=args.file_id)
    with open(out, "wb") as fh:
        downloader = MediaIoBaseDownload(fh, request)
        done = False
        while not done:
            _, done = downloader.next_chunk()
    print(json.dumps({"saved": out}, ensure_ascii=False))

def cmd_mkdir(args):
    service = get_service()
    meta = {
        "name": args.name,
        "mimeType": "application/vnd.google-apps.folder"
    }
    if args.parent_id:
        meta["parents"] = [args.parent_id]
    f = service.files().create(body=meta, fields="id,name,webViewLink").execute()
    print(json.dumps({"id": f["id"], "name": f["name"], "url": f.get("webViewLink", "")}, ensure_ascii=False))

def cmd_list(args):
    service = get_service()
    q = f"'{args.folder_id}' in parents and trashed=false" if args.folder_id else "trashed=false"
    results = service.files().list(
        q=q, pageSize=50,
        fields="files(id,name,mimeType,webViewLink,modifiedTime)"
    ).execute()
    files = results.get("files", [])
    print(json.dumps(files, ensure_ascii=False, indent=2))

def cmd_url(args):
    service = get_service()
    f = service.files().get(fileId=args.file_id, fields="id,name,webViewLink").execute()
    print(json.dumps({"id": f["id"], "name": f["name"], "url": f.get("webViewLink", "")}, ensure_ascii=False))

def main():
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="cmd")

    p = sub.add_parser("upload")
    p.add_argument("--file",      required=True)
    p.add_argument("--folder-id", default="")
    p.add_argument("--name",      default="")

    p = sub.add_parser("download")
    p.add_argument("--file-id",  required=True)
    p.add_argument("--output",   default="")

    p = sub.add_parser("mkdir")
    p.add_argument("--name",      required=True)
    p.add_argument("--parent-id", default="")

    p = sub.add_parser("list")
    p.add_argument("--folder-id", default="")

    p = sub.add_parser("url")
    p.add_argument("--file-id", required=True)

    args = parser.parse_args()
    if not args.cmd:
        parser.print_help()
        sys.exit(1)

    {"upload": cmd_upload, "download": cmd_download,
     "mkdir":  cmd_mkdir,  "list":    cmd_list,
     "url":    cmd_url}[args.cmd](args)

if __name__ == "__main__":
    main()
