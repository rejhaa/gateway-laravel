#!/usr/bin/env python3
"""Check the hard cases against CASE-FORMAT.md and against what generate.py meant.

Run after generate.py: python3 validate.py. It regenerates every file in
memory and requires the files on disk to be byte-identical (the generator is
deterministic), checks each case.json against the format and the base seed,
and checks each .eml: its MIME structure, headers, dates, the forwarded
message, each PDF's pages and text layer (the scan must have none), and the
photo. Prints one line per case and exits non-zero on any problem.
"""

import email
import json
import re
import sys
from datetime import date as Date
from email import policy
from email.utils import parsedate_to_datetime

import generate
import media
from mail import build, to_bytes

OUT = generate.OUT
BASE_SUPPLIERS = {"Tai-Yuan Forge & Shaft Co., Ltd.", "Saigon Grip Works Co., Ltd."}
COMPONENTS = {"VG-HEAD-V1", "VG-SHAFT-STL-S", "VG-GRIP-STD"}
BASE_PRODUCTS = COMPONENTS | {"VG-SET-V1-5PW-S"}
OWNERS = {"supply_chain_lead", "buyer", "purchasing_assistant", "finance", "production_planner", "warehouse"}
LINE_FIELDS = {"ordered_qty", "price_unit", "requested_date", "planned_date", "confirmed_qty", "confirmed_date", "received_qty"}
PO_NAME = re.compile(r"^P00(\d{3})$")
MO_NAME = re.compile(r"^LAH/MO/00(\d{3})$")
DASHES = (chr(0x2014), chr(0x2013))


def check_case(c: dict, pdfs: dict[str, bytes]) -> list[str]:
    errors = []
    err = errors.append
    cid = c["id"]
    root = OUT / cid
    raw = (root / "case.json").read_text(encoding="utf-8")
    case = json.loads(raw)
    if any(x in raw for x in DASHES):
        err("case.json has an em or en dash")

    if list(case) != ["id", "today", "setup", "messages", "lead", "expected"]:
        err(f"top-level keys {list(case)}")
    if case["id"] != cid or not cid.startswith("hard-0"):
        err("id differs from the directory name")
    today = Date.fromisoformat(case["today"])
    if not Date(2026, 10, 20) <= today <= Date(2026, 11, 30):
        err(f"today {today} is not in late October or November 2026")
    s = case["setup"]
    if list(s) != ["suppliers", "products", "vendor_codes", "manufacturing_orders", "purchase_orders"]:
        err(f"setup keys {list(s)}")
    for x in s["suppliers"]:
        if set(x) - {"email"} != {"name", "country", "city", "lead_days"} or x["name"] in BASE_SUPPLIERS:
            err(f"supplier {x}")
    suppliers = BASE_SUPPLIERS | {x["name"] for x in s["suppliers"]}
    for x in s["products"]:
        if set(x) != {"ref", "name", "cost", "supplier"} or x["ref"] in BASE_PRODUCTS or x["supplier"] not in suppliers:
            err(f"product {x}")
    products = BASE_PRODUCTS | {x["ref"] for x in s["products"]}
    for vc in s["vendor_codes"]:
        if set(vc) != {"product", "supplier", "code", "name"} or vc["product"] not in products - {"VG-SET-V1-5PW-S"} \
                or vc["supplier"] not in suppliers:
            err(f"vendor code {vc}")

    mos = {m["name"]: m for m in s["manufacturing_orders"]}
    for m in s["manufacturing_orders"]:
        found = MO_NAME.match(m["name"])
        if not found or not 101 <= int(found.group(1)) <= 999:
            err(f"MO name {m['name']}")
        if not (isinstance(m["sets"], int) and m["sets"] > 0):
            err(f"MO sets {m}")
        Date.fromisoformat(m["planned_start"])

    feeders = {(mo, comp): [] for mo in mos for comp in COMPONENTS}
    lines, bills, names = {}, {}, set()
    first_message = None
    for p in s["purchase_orders"]:
        found = PO_NAME.match(p["name"])
        if not found or not 101 <= int(found.group(1)) <= 999 or p["name"] in names:
            err(f"PO name {p['name']}")
        names.add(p["name"])
        if p["supplier"] not in suppliers:
            err(f"{p['name']} supplier {p['supplier']}")
        if p["warehouse"] not in ("LAH", "RNO") or p["state"] not in ("confirmed", "rfq"):
            err(f"{p['name']} warehouse or state")
        if Date.fromisoformat(p["order_date"]) >= today:
            err(f"{p['name']} ordered on or after today")
        for ln in p["lines"]:
            ref = f"{p['name']}/{ln['key']}"
            if ref in lines:
                err(f"duplicate line {ref}")
            lines[ref] = ln
            if ln["product"] not in products - {"VG-SET-V1-5PW-S"}:
                err(f"{ref} product {ln['product']}")
            if ln["price_unit"] != generate.PRODUCTS[ln["product"]][1]:
                err(f"{ref} price differs from the product cost")
            for f in ("requested_date", "planned_date"):
                Date.fromisoformat(ln[f])
            if (ln["confirmed_qty"] is None) != (ln["confirmed_date"] is None):
                err(f"{ref} confirmed qty and date must both be set or both null")
            if not 0 <= ln["received_qty"] <= ln["qty"]:
                err(f"{ref} received_qty")
            need = 0
            for mo in ln.get("feeds", []):
                if mo not in mos or ln["product"] not in COMPONENTS or p["warehouse"] != "LAH":
                    err(f"{ref} feeds {mo}")
                    continue
                feeders[(mo, ln["product"])].append(ref)
                need += 6 * mos[mo]["sets"]
            if ln["qty"] < need:
                err(f"{ref} qty {ln['qty']} below the MO need {need}")
        for r in p.get("receipts", []):
            ln = lines.get(f"{p['name']}/{r['line']}")
            if ln is None or p["state"] != "confirmed" or not 0 <= r["counted_qty"] <= ln["qty"] - ln["received_qty"]:
                err(f"{p['name']} receipt {r}")
        for b in p.get("bills", []):
            if b["state"] not in ("draft", "posted") or b["vendor_reference"] in bills:
                err(f"bill {b}")
            bills[b["vendor_reference"]] = b
            for bl in b["lines"]:
                if f"{p['name']}/{bl['line']}" not in lines:
                    err(f"bill line {bl}")
    for (mo, comp), refs in feeders.items():
        if len(refs) != 1:
            err(f"{mo} component {comp} fed by {refs}")

    if case["messages"] != [m["file"] for m in c["messages"]]:
        err("messages list differs from the generator")
    on_disk = sorted(x.name for x in (root / "messages").iterdir())
    if on_disk != sorted(case["messages"]):
        err(f"messages directory holds {on_disk}")

    lead = case["lead"]
    if set(lead) != {"default", "overrides"} or lead["default"] not in ("approve", "reject"):
        err("lead")
    for o in lead["overrides"]:
        if list(o) != ["about", "answer", "reason"] or o["answer"] not in ("approve", "reject"):
            err(f"override {o}")

    e = case["expected"]
    if list(e) != ["summary", "after", "with_person", "open_items", "must_not", "safe_alternatives"]:
        err(f"expected keys {list(e)}")
    seen = set()
    for a in e["after"]:
        rec, field = a["record"], a["field"]
        if (rec, field) in seen:
            err(f"after names {rec} {field} twice")
        seen.add((rec, field))
        if rec.startswith("bill:"):
            ok = rec[5:] in bills and field == "dispute" and a["value"] in ("none", "disputed")
        elif rec.endswith("/receipt"):
            ok = rec[: -len("/receipt")] in lines and field in ("validated_qty", "remaining_expected")
        elif rec in lines:
            ok = field in LINE_FIELDS
            ln = lines[rec]
            if field == "confirmed_qty" and not any(x["record"] == rec and x["field"] == "confirmed_date" for x in e["after"]) \
                    and ln["confirmed_date"] is None:
                err(f"{rec}: a confirmed quantity without a confirmed date does not show in the snapshot")
        elif rec in mos:
            ok = field == "planned_start"
        else:
            ok = False
        if not ok:
            err(f"after entry {a}")
        if ok and rec in lines:
            setup_field = {"ordered_qty": "qty", "received_qty": "received_qty"}.get(field, field)
            if lines[rec].get(setup_field) == a["value"]:
                err(f"after entry {a} repeats the setup value")
    for o in e["open_items"]:
        if list(o) != ["owner", "about"] or o["owner"] not in OWNERS or not o["about"]:
            err(f"open item {o}")
    if not e["summary"] or not e["must_not"] or not e["safe_alternatives"]:
        err("summary, must_not or safe_alternatives empty")

    previous = None
    for m in c["messages"]:
        path = root / "messages" / m["file"]
        msg = email.message_from_bytes(path.read_bytes(), policy=policy.default)
        sent = parsedate_to_datetime(msg["Date"])
        if previous and sent <= previous:
            err(f"{m['file']}: not after the previous message")
        previous = sent
        first_message = first_message or sent.date()
        errors += [f"{m['file']}: {x}" for x in check_message(msg, m["checks"], today, pdfs)]
    for p in s["purchase_orders"]:
        if Date.fromisoformat(p["order_date"]) >= first_message:
            err(f"{p['name']} ordered on or after the first message")
    return errors


def walk_types(msg) -> list[str]:
    """Content types in walk order, without descending into a message/rfc822 part."""
    out = [msg.get_content_type()]
    if msg.get_content_type() == "message/rfc822":
        return out
    if msg.is_multipart():
        for part in msg.iter_parts():
            out += walk_types(part)
    return out


def own_parts(msg, content_type: str) -> list:
    """The parts of a type in msg, without descending into a forwarded message/rfc822."""
    found = [msg] if msg.get_content_type() == content_type else []
    if msg.is_multipart() and msg.get_content_type() != "message/rfc822":
        for part in msg.iter_parts():
            found += own_parts(part, content_type)
    return found


def check_message(msg, checks: dict, today: Date, pdfs: dict[str, bytes], inner: bool = False) -> list[str]:
    errors = []
    err = errors.append
    for h in ("From", "To", "Subject", "Date", "Message-ID", "MIME-Version"):
        if msg[h] is None:
            err(f"no {h}")
    if not inner and "purchasing@vuoksigolf.fi" not in str(msg["To"]):
        err("not sent to purchasing@vuoksigolf.fi")
    if parsedate_to_datetime(msg["Date"]).date() >= today:
        err("dated on or after today")
    types = walk_types(msg)
    if types != checks["structure"]:
        err(f"structure {types}")
    plain = msg.get_body(preferencelist=("plain",))
    html = msg.get_body(preferencelist=("html",))
    text = plain.get_content() if plain else ""
    if not text.strip() or html is None or not html.get_content().strip():
        err("no plain text or no HTML body")
    for part in (plain, html):
        if part is not None and any(x in part.get_content() for x in DASHES):
            err("dash in the body")
    for related in own_parts(msg, "multipart/related"):
        imgs = [p for p in related.iter_parts() if p.get_content_type() == "image/png"]
        cids = [p["Content-ID"].strip("<>") for p in imgs]
        if not cids or any(f"cid:{c}" not in html.get_content() for c in cids):
            err("inline logo not referenced by the HTML body")
    for needle in checks.get("body_contains", []):
        if needle not in text:
            err(f"body lacks {needle!r}")
    for needle in checks.get("body_lacks", []):
        if needle in text:
            err(f"body contains {needle!r}")

    attachments = {a.get_filename(): a for a in msg.iter_attachments() if a.get_content_disposition() == "attachment"}
    for name, spec in checks.get("pdfs", {}).items():
        a = attachments.get(name)
        if a is None or a.get_content_type() != "application/pdf":
            err(f"no PDF {name}")
            continue
        data = a.get_content()
        if media.pdf_pages(data) != spec["pages"]:
            err(f"{name}: {media.pdf_pages(data)} pages, not {spec['pages']}")
        layer = media.pdf_text(data)
        if spec.get("no_text"):
            if layer.strip():
                err(f"{name}: the scan has a text layer")
            continue
        if not layer.strip():
            err(f"{name}: no text layer")
        for needle in spec.get("contains", []):
            if needle not in layer:
                err(f"{name}: text layer lacks {needle!r}")
        for needle in spec.get("lacks", []):
            if needle in layer:
                err(f"{name}: text layer contains {needle!r}")
    for name, kind in checks.get("images", {}).items():
        a = attachments.get(name)
        if a is None or not a.get_content().startswith(b"\xff\xd8\xff") or a.get_content_type() != "image/jpeg":
            err(f"no {kind} image {name}")
    for key, needles in checks.get("page_text", {}).items():
        layer = media.pdf_text(pdfs[key])
        for needle in needles:
            if needle not in layer:
                err(f"source page {key} lacks {needle!r}")
    fwd = checks.get("forwarded")
    if fwd:
        parts = [p for p in msg.iter_attachments() if p.get_content_type() == "message/rfc822"]
        if len(parts) != 1:
            err(f"{len(parts)} forwarded messages")
        else:
            forwarded = parts[0].get_content()
            if fwd["from"] not in str(forwarded["From"]):
                err("forwarded message from someone else")
            if parsedate_to_datetime(forwarded["Date"]) >= parsedate_to_datetime(msg["Date"]):
                err("forwarded message not older than the forward")
            errors += [f"forwarded: {x}" for x in check_message(forwarded, fwd, today, pdfs, inner=True)]
    return errors


def main() -> int:
    built, pdfs = generate.materialize()
    failed = False
    for c in built:
        errors = []
        for m in c["messages"]:
            fresh = to_bytes(build(m["mail"], f"{c['id']}/{m['file']}"))
            if (OUT / c["id"] / "messages" / m["file"]).read_bytes() != fresh:
                errors.append(f"{m['file']}: differs from a fresh generation (run generate.py, or the output is not deterministic)")
        errors += check_case(c, pdfs)
        print(f"{'ok  ' if not errors else 'FAIL'} {c['id']}")
        for e in errors:
            print(f"     {e}")
        failed |= bool(errors)
    index = (OUT / "INDEX.md").read_text(encoding="utf-8")
    missing = [c["id"] for c in built if f"`{c['id']}`" not in index]
    if any(x in index for x in DASHES) or missing or len([x for x in index.splitlines() if x.startswith("- ")]) != len(built):
        print(f"FAIL INDEX.md {missing}")
        failed = True
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
