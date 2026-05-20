"""
Google スプレッドシート操作スクリプト
使い方:
  python google_sheets.py read    --sheet-id SHEET_ID --range "Sheet1!A1:Z100"
  python google_sheets.py write   --sheet-id SHEET_ID --range "Sheet1!A1" --data "値1,値2,値3"
  python google_sheets.py append  --sheet-id SHEET_ID --range "Sheet1!A:Z" --data "値1,値2,値3"
  python google_sheets.py rows    --sheet-id SHEET_ID --range "Sheet1!A1:Z100"  # 行数確認

--data は「値1,値2,値3」形式（1行）または JSON 形式 [["値1","値2"],["値3","値4"]]（複数行）

サービスアカウント鍵ファイルの場所:
  C:\\Users\\Administrator\\Desktop\\AI版AGO\\google-service-account.json
"""

import sys, os, json, argparse

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, BASE_DIR)
from google_auth import get_credentials
from googleapiclient.discovery import build

def get_service():
    return build("sheets", "v4", credentials=get_credentials())

def parse_data(data_str):
    """データ文字列をリストに変換（CSV1行 or JSON配列）"""
    s = data_str.strip()
    if s.startswith("["):
        return json.loads(s)
    return [s.split(",")]

def cmd_read(args):
    service = get_service()
    result = service.spreadsheets().values().get(
        spreadsheetId=args.sheet_id,
        range=args.range
    ).execute()
    values = result.get("values", [])
    print(json.dumps(values, ensure_ascii=False, indent=2))

def cmd_write(args):
    service = get_service()
    values = parse_data(args.data)
    body = {"values": values}
    result = service.spreadsheets().values().update(
        spreadsheetId=args.sheet_id,
        range=args.range,
        valueInputOption="USER_ENTERED",
        body=body
    ).execute()
    print(json.dumps({"updated_cells": result.get("updatedCells", 0)}, ensure_ascii=False))

def cmd_append(args):
    service = get_service()
    values = parse_data(args.data)
    body = {"values": values}
    result = service.spreadsheets().values().append(
        spreadsheetId=args.sheet_id,
        range=args.range,
        valueInputOption="USER_ENTERED",
        insertDataOption="INSERT_ROWS",
        body=body
    ).execute()
    print(json.dumps({"updated_cells": result.get("updates", {}).get("updatedCells", 0)}, ensure_ascii=False))

def cmd_rows(args):
    service = get_service()
    result = service.spreadsheets().values().get(
        spreadsheetId=args.sheet_id,
        range=args.range
    ).execute()
    values = result.get("values", [])
    print(json.dumps({"row_count": len(values), "col_count": max((len(r) for r in values), default=0)}, ensure_ascii=False))

def main():
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="cmd")

    p = sub.add_parser("read")
    p.add_argument("--sheet-id", required=True)
    p.add_argument("--range",    required=True)

    p = sub.add_parser("write")
    p.add_argument("--sheet-id", required=True)
    p.add_argument("--range",    required=True)
    p.add_argument("--data",     required=True)

    p = sub.add_parser("append")
    p.add_argument("--sheet-id", required=True)
    p.add_argument("--range",    required=True)
    p.add_argument("--data",     required=True)

    p = sub.add_parser("rows")
    p.add_argument("--sheet-id", required=True)
    p.add_argument("--range",    required=True)

    args = parser.parse_args()
    if not args.cmd:
        parser.print_help()
        sys.exit(1)

    {"read": cmd_read, "write": cmd_write,
     "append": cmd_append, "rows": cmd_rows}[args.cmd](args)

if __name__ == "__main__":
    main()
