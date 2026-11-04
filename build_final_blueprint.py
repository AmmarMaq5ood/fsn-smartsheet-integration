"""Build the final LP -> Smartsheet scenario (find-or-create) as a Make blueprint.

Input : LP to Smartsheet BUILD.blueprint.json  (export of the BUILD scenario,
        taken AFTER the ID columns exist and the Smartsheet modules were refreshed)
Output: LP to Smartsheet FINAL.blueprint.json

Flow:
  13 Webhook
  15 Search Rows by LP Opportunity ID          -> 16 Array aggregator
  17 Search Rows by School Name (fallback)     -> 18 Array aggregator
  20 Router
     R1  found by opportunity   -> 21 Update a Row
     R2  found by school name   -> 22 Update a Row (also stamps the opportunity ID)
     R3  not found, SOW event   -> 23 Create a Row (sets School Name)
     R4  contact edit, no opp   -> 24 Search Rows by LP Contact ID -> 25 Update a Row
"""
import json, sys, copy

SRC = "LP to Smartsheet BUILD.blueprint.json"
OUT = "LP to Smartsheet FINAL.blueprint.json"
LIVE_HOOK_ID = 1246579
LIVE_HOOK_LABEL = "My gateway-webhook webhook 2"

OPP_ID_RAW = "13.customData.`LP Opportunity ID`"
OPP_ID = "{{" + OPP_ID_RAW + "}}"
BIZ = "13.customData.`LP Business Name`"
SOW_URL = "{{13.customData.`LP SOW Sent`}}"
SOW_NAME_RAW = "13.customData.`LP SOW Name`"
# school name fallback chain: Official School Name -> "School name:" -> Business Name
SCHOOL_RAW = ("if(13.`Official School Name`; 13.`Official School Name`; "
              "if(13.`School name:`; 13.`School name:`; "
              "if(" + BIZ + "; " + BIZ + "; 13.`Organization Name`)))")
SCHOOL_OR_NONE = "if(" + SCHOOL_RAW + "; " + SCHOOL_RAW + "; \"__none__\")"
STAFF_WORDS = ["contractor", "educator", "independent", "staff"]
# first real row id out of each aggregator (empty when the search found nothing)
ROW_A_RAW = "first(map(16.array; \"id\"))"
ROW_B_RAW = "first(map(18.array; \"id\"))"
ROW_A = "{{" + ROW_A_RAW + "}}"
ROW_B = "{{" + ROW_B_RAW + "}}"

# Smartsheet column label -> value expression (LP fields written on every event)
LP_FIELDS = {
    "LP First Name": "{{13.customData.`LP First Name`}}",
    "LP Middle Name": "{{13.customData.`LP Middle Name`}}",
    "LP Last Name": "{{13.customData.`LP Last Name`}}",
    "LP Email": "{{13.customData.`LP Email`}}",
    "LP Phone": "{{13.customData.`LP Phone`}}",
    "LP Contact Type": "{{13.contact_type}}",
    "LP Contact Source": "{{13.customData.`LP Contact Source`}}",
    "LP Title": "{{13.customData.`LP Title`}}",
    "LP Street Address": "{{13.customData.`LP Street Address`}}",
    "LP City": "{{13.customData.`LP City`}}",
    "LP Country": "{{13.customData.`LP Country`}}",
    "LP State": "{{13.customData.`LP State`}}",
    "LP Postal Code": "{{13.customData.`LP Postal Code`}}",
    "LP Business Name": "{{13.customData.`LP Business Name`}}",
    "LP Website": "{{13.customData.`LP Website`}}",
    "LP Time Zone": "{{13.customData.`LP Time Zone`}}",
    "LP Budget": "{{13.customData.`LP Budget`}}",
    "LP Borough": "{{13.customData.`LP Borough`}}",
    "LP Group (if teacher)": "{{13.customData.`LP Group (if Teacher)`}}",
    "LP Claim form Status": "{{13.customData.`LP Claim form Status`}}",
    "LP Distance (miles)": "{{13.customData.`LP Distance (Miles)`}}",
    "LP Official School Name": "{{13.customData.`LP Official School Name`}}",
    "LP Organization Description": "{{13.customData.`LP Organization Description`}}",
    "LP County Code": "{{13.customData.`LP County Code`}}",
    "LP County": "{{13.customData.`LP County`}}",
    "LP School District Code": "{{13.customData.`LP School District Code`}}",
    "LP School District Description": "{{13.customData.`LP School District Description`}}",
    "LP SED Code": "{{13.customData.`LP SED Code`}}",
    "LP Grade Code": "{{13.customData.`LP Grade Code`}}",
    "LP Product Category": "{{13.customData.`LP Product Category`}}",
    "LP Product": "{{13.customData.`LP Product`}}",
    "LP Contract Start Date": "{{13.customData.`LP Contract Start Date`}}",
    "LP Contract End Date": "{{13.customData.`LP Contract End Date`}}",
    "LP SOW Sent": "{{13.customData.`LP SOW Sent`}}",
    "LP Contact ID": "{{13.contact_id}}",
}
OPP_ONLY = {"LP Opportunity ID": OPP_ID}
CREATE_ONLY = {
    "School Name": "{{" + SCHOOL_RAW + "}}",
}


def main():
    bp = json.load(open(SRC, encoding="utf-8"))
    hook = [m for m in bp["flow"] if m["module"] == "gateway:CustomWebHook"][0]
    create = [m for m in bp["flow"] if m["module"] == "smartsheet:CreateRow"][0]
    search = [m for m in bp["flow"] if m["module"] == "smartsheet:searchRows"][0]
    update = [m for m in bp["flow"] if m["module"] == "smartsheet:UpdateRow"][0]

    col_spec = [s for s in update["metadata"]["expect"] if s["name"] == "columns"][0]["spec"]
    by_label = {c["label"].strip(): c for c in col_spec}
    need = ["LP Opportunity ID", "LP Contact ID"] + list(LP_FIELDS) + list(CREATE_ONLY)
    missing = [n for n in need if n not in by_label]
    if missing:
        sys.exit("Columns missing in the sheet export: " + ", ".join(missing))

    def cid(label):
        return by_label[label]["name"]

    sheet_id = update["mapper"]["sheetId"]

    def columns(mapping):
        return {str(cid(l)): {"value": v, "sendAs": "text"} for l, v in mapping.items()}

    def col_restore(mapping):
        return {"nested": {str(cid(l)): {"nested": {"sendAs": {"label": "Text"}}} for l in mapping}}

    def search_mod(mid, x, y, filt, limit, filt_name=None, filt_cond=None):
        m = copy.deepcopy(search)
        m["id"] = mid
        m["mapper"] = {"sheetId": sheet_id, "include": [], "skip": "", "limit": str(limit), "filter": filt}
        m["metadata"]["designer"] = {"x": x, "y": y}
        if filt_name:
            m["filter"] = {"name": filt_name, "conditions": filt_cond}
        return m

    def agg_mod(mid, feeder, x, y):
        return {
            "id": mid, "module": "builtin:BasicAggregator", "version": 1,
            "parameters": {"feeder": feeder},
            "mapper": {"id": "{{%d.id}}" % feeder},
            "metadata": {
                "designer": {"x": x, "y": y},
                "restore": {"extra": {"feeder": {"label": "Smartsheet - Search Rows [%d]" % feeder}}},
                "expect": [{"name": "id", "type": "text", "label": "id"}],
            },
        }

    def update_mod(mid, x, y, row_id, mapping, filt_name=None, filt_cond=None):
        m = copy.deepcopy(update)
        m["id"] = mid
        m["mapper"] = {"sheetId": sheet_id, "rowId": row_id, "columns": columns(mapping)}
        m["metadata"]["designer"] = {"x": x, "y": y}
        m["metadata"]["restore"]["expect"]["columns"] = col_restore(mapping)
        if filt_name:
            m["filter"] = {"name": filt_name, "conditions": filt_cond}
        return m

    def create_mod(mid, x, y, mapping, filt_name, filt_cond):
        m = copy.deepcopy(create)
        m["id"] = mid
        m["mapper"] = {"sheetId": sheet_id, "location": "bottom", "columns": columns(mapping)}
        m["metadata"]["designer"] = {"x": x, "y": y}
        m["metadata"]["restore"]["expect"]["columns"] = col_restore(mapping)
        m["filter"] = {"name": filt_name, "conditions": filt_cond}
        return m

    # --- searches ---------------------------------------------------------
    opp_or_none = "{{if(" + OPP_ID_RAW + "; " + OPP_ID_RAW + "; \"__none__\")}}"
    f_by_opp = [[{"a": cid("LP Opportunity ID"), "o": "text:equal", "b": opp_or_none}]]

    school_or_none = ("{{if(" + ROW_A_RAW + "; \"__none__\"; if(" + OPP_ID_RAW + "; " + SCHOOL_OR_NONE + "; \"__none__\"))}}")
    f_by_school = [[{"a": cid("School Name"), "o": "text:equal", "b": school_or_none},
                    {"a": cid("LP Opportunity ID"), "o": "notexist"}]]

    contact_or_none = "{{if(" + OPP_ID_RAW + "; \"__none__\"; 13.contact_id)}}"
    f_by_contact = [[{"a": cid("LP Contact ID"), "o": "text:equal", "b": contact_or_none}]]

    # --- route filter conditions -----------------------------------------
    opp_exists = {"a": OPP_ID, "o": "exist"}
    opp_missing = {"a": OPP_ID, "o": "notexist"}
    foundA = {"a": ROW_A, "o": "exist"}
    noneA = {"a": ROW_A, "o": "notexist"}
    foundB = {"a": ROW_B, "o": "exist"}
    noneB = {"a": ROW_B, "o": "notexist"}
    sow_exists = {"a": SOW_URL, "o": "exist"}
    # route 5 (no opportunity ID): contact-id search -> school-name search
    ROW_C = "{{first(map(27.array; \"id\"))}}"
    ROW_D = "{{first(map(29.array; \"id\"))}}"
    foundC = {"a": ROW_C, "o": "exist"}
    noneC = {"a": ROW_C, "o": "notexist"}
    foundD = {"a": ROW_D, "o": "exist"}
    noneD = {"a": ROW_D, "o": "notexist"}
    f_by_contact_plain = [[{"a": cid("LP Contact ID"), "o": "text:equal", "b": "{{13.contact_id}}"}]]
    school_if_no_contact = "{{if(first(map(27.array; \"id\")); \"__none__\"; " + SCHOOL_OR_NONE + ")}}"
    # staff gate: document name must not look like a staff/contractor agreement (empty name passes)
    not_staff = [{"a": "{{lower(" + SOW_NAME_RAW + ")}}", "o": "text:notcontain", "b": w} for w in STAFF_WORDS]
    f_by_school_no_contact = [[{"a": cid("School Name"), "o": "text:equal", "b": school_if_no_contact},
                               {"a": cid("LP Contact ID"), "o": "notexist"}]]

    hook["metadata"]["designer"] = {"x": 0, "y": 0}
    # pin the LIVE hook so importing into the live scenario keeps LP's URL
    hook["parameters"]["hook"] = LIVE_HOOK_ID
    hook["metadata"]["restore"]["parameters"]["hook"]["label"] = LIVE_HOOK_LABEL
    base_map = dict(LP_FIELDS)
    with_opp = {**base_map, **OPP_ONLY}
    create_map = {**base_map, **OPP_ONLY, **CREATE_ONLY}

    router = {
        "id": 20, "module": "builtin:BasicRouter", "version": 1, "mapper": None,
        "metadata": {"designer": {"x": 1500, "y": 0}},
        "routes": [
            {"flow": [update_mod(21, 1800, -450, ROW_A, with_opp,
                                 "Found by opportunity ID", [[opp_exists, foundA]])]},
            {"flow": [update_mod(22, 1800, -150, ROW_B, with_opp,
                                 "Found by school name", [[opp_exists, noneA, foundB] + not_staff])]},
            {"flow": [create_mod(23, 1800, 150, create_map,
                                 "New program row", [[opp_exists, noneA, noneB, sow_exists] + not_staff])]},
            {"flow": [search_mod(24, 1800, 450, f_by_contact, 100,
                                 "Contact edit (no opportunity)", [[opp_missing]]),
                      update_mod(25, 2100, 450, "{{24.id}}", base_map,
                                 "Row found", [[{"a": "{{24.id}}", "o": "exist"}]])]},
            {"flow": [search_mod(26, 1800, 750, f_by_contact_plain, 1,
                                 "SOW sent, no opportunity", [[opp_missing, sow_exists] + not_staff]),
                      agg_mod(27, 26, 2100, 750),
                      search_mod(28, 2400, 750, f_by_school_no_contact, 1),
                      agg_mod(29, 28, 2700, 750),
                      {"id": 30, "module": "builtin:BasicRouter", "version": 1, "mapper": None,
                       "metadata": {"designer": {"x": 3000, "y": 750}},
                       "routes": [
                           {"flow": [update_mod(31, 3300, 600, ROW_D, base_map,
                                                "Found by school name", [[noneC, foundD]])]},
                           {"flow": [create_mod(32, 3300, 900, {**base_map, **CREATE_ONLY},
                                                "New program row (SOW)", [[noneC, noneD]])]},
                       ]}]},
        ],
    }

    bp["flow"] = [
        hook,
        search_mod(15, 300, 0, f_by_opp, 1),
        agg_mod(16, 15, 600, 0),
        search_mod(17, 900, 0, f_by_school, 1),
        agg_mod(18, 17, 1200, 0),
        router,
    ]
    bp["name"] = "Getting Contact Data from LP (2026-27) v2"
    json.dump(bp, open(OUT, "w", encoding="utf-8"), indent=4, ensure_ascii=False)
    print("Wrote", OUT)
    for l in ["LP Opportunity ID", "LP Contact ID", "School Name", "LP SOW Sent"]:
        print("  %-20s -> column %s" % (l, cid(l)))


if __name__ == "__main__":
    main()
