"""Rebuild the Make blueprint against the new 2026-27 Smartsheet.

Usage:
  set SMARTSHEET_TOKEN=xxxx          (PowerShell: $env:SMARTSHEET_TOKEN="xxxx")
  python build_2627_blueprint.py [--sheet-id 1234567890]

Finds the sheet named NEW_SHEET_NAME (or uses --sheet-id), pulls its columns,
and writes a new blueprint with every LP field mapped to the same-named column.
"""
import json, os, sys, copy, urllib.request

OLD_BP = "Getting Contact Data from LP.blueprint.json"
NEW_BP = "Getting Contact Data from LP - 2026-27.blueprint.json"
NEW_SHEET_NAME = "1. LEARN | Program Launch Tracker"
API = "https://api.smartsheet.com/2.0"

def get(path, token):
    req = urllib.request.Request(API + path, headers={"Authorization": "Bearer " + token})
    with urllib.request.urlopen(req) as r:
        return json.load(r)

def main():
    token = os.environ.get("SMARTSHEET_TOKEN")
    if not token:
        sys.exit("Set SMARTSHEET_TOKEN first.")
    sheet_id = None
    if "--sheet-id" in sys.argv:
        sheet_id = int(sys.argv[sys.argv.index("--sheet-id") + 1])
    else:
        sheets = get("/sheets?includeAll=true", token)["data"]
        hits = [s for s in sheets if s["name"].strip() == NEW_SHEET_NAME]
        if len(hits) != 1:
            sys.exit(f"Found {len(hits)} sheets named {NEW_SHEET_NAME!r}: {[ (s['id'], s['name']) for s in hits]}")
        sheet_id = hits[0]["id"]
    cols = get(f"/sheets/{sheet_id}/columns?includeAll=true", token)["data"]
    by_title = {c["title"].strip(): c for c in cols}
    print(f"Sheet {sheet_id}: {len(cols)} columns")

    bp = json.load(open(OLD_BP, encoding="utf-8"))
    mod = bp["flow"][1]
    old_labels = {}
    for spec in mod["metadata"]["expect"]:
        if spec["name"] == "columns":
            for c in spec["spec"]:
                old_labels[str(c["name"])] = c.get("label", "").strip()
    # old label -> value expression
    wanted = {old_labels[cid]: v["value"] for cid, v in mod["mapper"]["columns"].items() if v.get("value")}

    new_mapper, new_spec, new_restore, missing = {}, [], {}, []
    for c in cols:
        cid = str(c["id"])
        title = c["title"].strip()
        val = wanted.get(title, "")
        if title in wanted:
            new_mapper[cid] = {"value": val, "sendAs": "text"}
        elif c.get("type") in ("CHECKBOX",) or c.get("formula") or c.get("systemColumnType"):
            continue  # do not send to formula/system columns
        else:
            new_mapper[cid] = {"value": "", "sendAs": "text"}
        new_spec.append({
            "name": c["id"], "label": c["title"],
            "type": "collection",
            "spec": [
                {"name": "sendAs", "type": "select", "label": "Send as", "default": "text",
                 "validate": {"enum": ["text", "number"]}},
                {"name": "value", "type": "text", "label": "Value"},
                {"name": "strict", "type": "boolean", "label": "Strict"},
            ],
        })
        new_restore[cid] = {"nested": {"sendAs": {"label": "Text"}}, "collapsed": not bool(val)}
    for title in wanted:
        if title not in by_title:
            missing.append(title)

    mod["mapper"]["columns"] = new_mapper
    mod["mapper"]["sheetId"] = sheet_id
    for spec in mod["metadata"]["expect"]:
        if spec["name"] == "columns":
            spec["spec"] = new_spec
    mod["metadata"]["restore"]["expect"]["columns"] = {"nested": new_restore}
    mod["metadata"]["restore"]["expect"]["sheetId"] = {"mode": "chose", "label": NEW_SHEET_NAME}
    bp["name"] = "Getting Contact Data from LP (2026-27)"

    json.dump(bp, open(NEW_BP, "w", encoding="utf-8"), indent=4, ensure_ascii=False)
    print(f"Wrote {NEW_BP}: {sum(1 for v in new_mapper.values() if v['value'])} mapped / {len(wanted)} wanted")
    if missing:
        print("NOT FOUND in new sheet:", missing)

if __name__ == "__main__":
    main()
