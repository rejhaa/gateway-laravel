#!/usr/bin/env python3
"""Check the spike cases against CASE-FORMAT.md and against what generate.py meant.

Run after generate.py: python3 validate.py. Prints one line per case and
exits non-zero when any case has a problem.
"""

import email
import json
import re
import subprocess
import sys
import tempfile
from datetime import date as Date
from email import policy
from email.utils import parsedate_to_datetime
from pathlib import Path

import generate

OUT = generate.OUT
BASE_SUPPLIERS = {"Tai-Yuan Forge & Shaft Co., Ltd.", "Saigon Grip Works Co., Ltd."}
COMPONENTS = {"VG-HEAD-V1", "VG-SHAFT-STL-S", "VG-GRIP-STD"}
BASE_PRODUCTS = COMPONENTS | {"VG-SET-V1-5PW-S"}
OWNERS = {"supply_chain_lead", "buyer", "purchasing_assistant", "finance", "production_planner", "warehouse"}
LINE_FIELDS = {"ordered_qty", "price_unit", "requested_date", "planned_date", "confirmed_qty",
               "confirmed_date", "received_qty"}
RECEIPT_FIELDS = {"validated_qty", "remaining_expected"}
BILL_FIELDS = {"dispute"}
MO_FIELDS = {"planned_start"}
PO_NAME = re.compile(r"^P00[1-9]\d\d$")
MO_NAME = re.compile(r"^LAH/MO/00[1-9]\d\d$")
DASHES = (chr(0x2014), chr(0x2013))


def check_case(cid: str, spec_messages: list[dict], out: Path = OUT, check=None) -> list[str]:
    """out is the directory holding the case; check(path, spec, today) checks one message."""
    errors = []
    err = errors.append
    root = out / cid
    raw = (root / "case.json").read_text(encoding="utf-8")
    case = json.loads(raw)
    if any(x in raw for x in DASHES):
        err("case.json has an em or en dash")

    if set(case) != {"id", "today", "setup", "messages", "lead", "expected"}:
        err(f"top-level keys {sorted(case)}")
    if case["id"] != cid:
        err("id differs from the directory name")
    today = Date.fromisoformat(case["today"])
    s = case["setup"]
    if set(s) != {"suppliers", "products", "vendor_codes", "manufacturing_orders", "purchase_orders"}:
        err(f"setup keys {sorted(s)}")

    suppliers = BASE_SUPPLIERS | {x["name"] for x in s["suppliers"]}
    products = BASE_PRODUCTS | {x["ref"] for x in s["products"]}
    for vc in s["vendor_codes"]:
        if vc["product"] not in products or vc["supplier"] not in suppliers or not vc["code"] or not vc["name"]:
            err(f"vendor code {vc}")

    mos = {m["name"]: m for m in s["manufacturing_orders"]}
    for m in s["manufacturing_orders"]:
        if not MO_NAME.match(m["name"]):
            err(f"MO name {m['name']}")
        if not (isinstance(m["sets"], int) and m["sets"] > 0):
            err(f"MO sets {m}")
        Date.fromisoformat(m["planned_start"])

    feeders = {(mo, c): [] for mo in mos for c in COMPONENTS}
    lines = {}
    bills = {}
    names = set()
    for p in s["purchase_orders"]:
        if not PO_NAME.match(p["name"]) or p["name"] in names:
            err(f"PO name {p['name']}")
        names.add(p["name"])
        if p["supplier"] not in suppliers:
            err(f"{p['name']} supplier {p['supplier']}")
        if p["warehouse"] not in ("LAH", "RNO") or p["state"] not in ("confirmed", "rfq"):
            err(f"{p['name']} warehouse or state")
        if Date.fromisoformat(p["order_date"]) > today:
            err(f"{p['name']} ordered after today")
        keys = set()
        for ln in p["lines"]:
            ref = f"{p['name']}/{ln['key']}"
            if ln["key"] in keys:
                err(f"duplicate line key {ref}")
            keys.add(ln["key"])
            lines[ref] = ln
            if ln["product"] not in products:
                err(f"{ref} product {ln['product']}")
            for f in ("requested_date", "planned_date"):
                Date.fromisoformat(ln[f])
            if (ln["confirmed_qty"] is None) != (ln["confirmed_date"] is None):
                err(f"{ref} confirmed qty and date must both be set or both null")
            if not 0 <= ln["received_qty"] <= ln["qty"]:
                err(f"{ref} received_qty")
            need = 0
            for mo in ln.get("feeds", []):
                if mo not in mos:
                    err(f"{ref} feeds unknown {mo}")
                    continue
                if ln["product"] not in COMPONENTS:
                    err(f"{ref} feeds {mo} with a non-component")
                    continue
                if p["warehouse"] != "LAH":
                    err(f"{ref} feeds a Lahti MO from {p['warehouse']}")
                feeders[(mo, ln["product"])].append(ref)
                need += 6 * mos[mo]["sets"]
            if ln["qty"] < need:
                err(f"{ref} qty {ln['qty']} below the MO need {need}")
        for r in p.get("receipts", []):
            ln = lines.get(f"{p['name']}/{r['line']}")
            if ln is None or not 0 <= r["counted_qty"] <= ln["qty"] - ln["received_qty"]:
                err(f"{p['name']} receipt {r}")
        for b in p.get("bills", []):
            if b["state"] not in ("draft", "posted"):
                err(f"bill state {b}")
            bills[b["vendor_reference"]] = b
            for bl in b["lines"]:
                if f"{p['name']}/{bl['line']}" not in lines:
                    err(f"bill line {bl}")
    for (mo, c), refs in feeders.items():
        if len(refs) != 1:
            err(f"{mo} component {c} fed by {refs}")

    if case["messages"] != [m["file"] for m in spec_messages]:
        err("messages list differs from the generator")
    on_disk = sorted(x.name for x in (root / "messages").iterdir())
    if on_disk != sorted(case["messages"]):
        err(f"messages directory holds {on_disk}")

    lead = case["lead"]
    if lead["default"] not in ("approve", "reject"):
        err("lead default")
    for o in lead["overrides"]:
        if set(o) != {"about", "answer", "reason"} or o["answer"] not in ("approve", "reject"):
            err(f"override {o}")

    e = case["expected"]
    if set(e) != {"summary", "after", "with_person", "open_items", "must_not", "safe_alternatives"}:
        err(f"expected keys {sorted(e)}")
    for a in e["after"]:
        rec, field = a["record"], a["field"]
        if rec.startswith("bill:"):
            ok = rec[5:] in bills and field in BILL_FIELDS and a["value"] in ("none", "disputed")
        elif rec.endswith("/receipt"):
            ok = rec[: -len("/receipt")] in lines and field in RECEIPT_FIELDS
        elif rec in lines:
            ok = field in LINE_FIELDS
        elif rec in mos:
            ok = field in MO_FIELDS
        else:
            ok = False
        if not ok:
            err(f"after entry {a}")
    for o in e["open_items"]:
        if o["owner"] not in OWNERS or not o["about"]:
            err(f"open item {o}")

    for m in spec_messages:
        errors += (check or check_message)(root / "messages" / m["file"], m, today)
    return errors


def check_message(path: Path, spec: dict, today: Date) -> list[str]:
    errors = []
    err = errors.append
    msg = email.message_from_bytes(path.read_bytes(), policy=policy.default)
    if msg["To"] is None or "purchasing@vuoksigolf.fi" not in msg["To"]:
        err(f"{path.name}: To")
    for h in ("From", "Subject", "Date", "Message-ID"):
        if msg[h] is None:
            err(f"{path.name}: no {h}")
    sent = parsedate_to_datetime(msg["Date"])
    if sent.date() > today:
        err(f"{path.name}: dated after today")
    body = msg.get_body(preferencelist=("plain",))
    text = body.get_content() if body else ""
    if not text.strip():
        err(f"{path.name}: no text/plain body")
    if any(x in text for x in DASHES):
        err(f"{path.name}: dash in the body")
    atts = list(msg.iter_attachments())
    if spec["attachment"] is None:
        if atts:
            err(f"{path.name}: has an attachment it should not have")
        if "attached" not in text:
            err(f"{path.name}: the body should refer to the missing attachment")
        return errors
    if len(atts) != 1:
        return errors + [f"{path.name}: {len(atts)} attachments"]
    att = atts[0]
    if att.get_content_type() != "application/pdf" or att.get_filename() != spec["attachment"][0]:
        err(f"{path.name}: attachment {att.get_content_type()} {att.get_filename()}")
    with tempfile.NamedTemporaryFile(suffix=".pdf") as f:
        f.write(att.get_content())
        f.flush()
        layer = subprocess.run(["pdftotext", "-layout", f.name, "-"], check=True,
                               capture_output=True, text=True).stdout
    if not layer.strip():
        err(f"{path.name}: the PDF has no text layer")
    for needle in spec.get("pdf_must_contain", []):
        if needle not in layer:
            err(f"{path.name}: PDF text lacks {needle!r}")
    for needle in spec.get("pdf_must_not_contain", []):
        if needle in layer:
            err(f"{path.name}: PDF text contains {needle!r}")
    return errors


def main() -> int:
    failed = False
    for fn in generate.CASES:
        cid, _, _, messages, _ = fn()
        errors = check_case(cid, messages)
        print(f"{'ok  ' if not errors else 'FAIL'} {cid}")
        for e in errors:
            print(f"     {e}")
        failed |= bool(errors)
    index = (OUT / "INDEX.md").read_text(encoding="utf-8")
    missing = [fn()[0] for fn in generate.CASES if fn()[0] not in index]
    if any(x in index for x in DASHES) or missing:
        print(f"FAIL INDEX.md {missing}")
        failed = True
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
