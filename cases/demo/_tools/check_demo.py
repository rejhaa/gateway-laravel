#!/usr/bin/env python3
"""Check the demo case against CASE-FORMAT.md and against what make_demo.py meant.

Run after make_demo.py: python3 check_demo.py. It rebuilds the case in memory
for the today in case.json, runs the spike validator's checks of the format
and the PDF text layers over it, and adds the demo's own: the case on disk is
what the generator writes for that today, each spike's facts hold in the
merged setup, and no message later than a confirmation the rule records names
that confirmation's order (the gateway's rule tier refuses the confirmation
then). Exits non-zero when anything is wrong.
"""

import email
import json
import subprocess
import sys
import tempfile
from datetime import date as Date, timedelta
from email import policy
from email.utils import parsedate_to_datetime

import make_demo as demo
import validate as v

ROOT = demo.OUT / demo.CASE_ID


def text_of(path) -> str:
    """The message as the inbox shows it to the agent: the body, any forwarded body, and each PDF's text layer."""
    msg = email.message_from_bytes(path.read_bytes(), policy=policy.default)
    out = []
    for part in msg.walk():
        if part.get_content_type() == "text/plain" and not part.is_attachment():
            out.append(part.get_content())
        elif part.get_content_type() == "application/pdf":
            with tempfile.NamedTemporaryFile(suffix=".pdf") as f:
                f.write(part.get_content())
                f.flush()
                out.append(subprocess.run(["pdftotext", "-layout", f.name, "-"], check=True,
                                          capture_output=True, text=True).stdout)
    return "\n".join(out)


def check_message(path, spec: dict, today: Date) -> list[str]:
    if spec["kind"] == "spike":
        return v.check_message(path, spec, today)
    errors = []
    err = errors.append
    msg = email.message_from_bytes(path.read_bytes(), policy=policy.default)
    if msg["To"] is None or "purchasing@vuoksigolf.fi" not in msg["To"]:
        err(f"{path.name}: To")
    for h in ("From", "Subject", "Date", "Message-ID"):
        if msg[h] is None:
            err(f"{path.name}: no {h}")
    if parsedate_to_datetime(msg["Date"]).date() > today:
        err(f"{path.name}: dated after today")
    body = msg.get_body(preferencelist=("plain",))
    text = body.get_content() if body else ""
    if not text.strip():
        err(f"{path.name}: no text/plain body")
    atts = list(msg.iter_attachments())
    if spec["kind"] == "newsletter" and msg.get_body(preferencelist=("html",)) is None:
        err(f"{path.name}: the newsletter has no HTML part")
    if spec["kind"] == "forward":
        if len(atts) != 1 or atts[0].get_content_type() != "message/rfc822":
            err(f"{path.name}: a forward carries one message/rfc822 part")
        else:
            inner = atts[0].get_content()
            if not inner.get_body(preferencelist=("plain",)).get_content().strip():
                err(f"{path.name}: the forwarded message has no body")
    elif atts:
        err(f"{path.name}: {len(atts)} attachments")
    return errors


def facts(case: dict) -> list[str]:
    """The facts each spike's outcome rests on, read back from the merged setup."""
    errors = []
    lines = {f"{p['name']}/{ln['key']}": ln | {"_po": p} for p in case["setup"]["purchase_orders"] for ln in p["lines"]}
    mos = {m["name"]: m for m in case["setup"]["manufacturing_orders"]}
    after = {(a["record"], a["field"]): a["value"] for a in case["expected"]["after"]}
    day = Date.fromisoformat
    today = day(case["today"])

    def need(ok: bool, what: str):
        if not ok:
            errors.append(f"fact: {what}")

    # spike-02: 100 of 120 heads, 14 days after the requested date, gaps of 12 and 7 days to the two MOs.
    heads, planned = lines["P00104/L1"], day(after[("P00104/L1", "planned_date")])
    need(heads["qty"] == 120 and after[("P00104/L1", "confirmed_qty")] == 100, "P00104 confirms 100 of 120 heads")
    need((planned - day(heads["requested_date"])).days == 14, "P00104 slips 14 days")
    need(heads["feeds"] == ["LAH/MO/00101", "LAH/MO/00102"], "two MOs wait on the heads")
    need([(planned - day(mos[m]["planned_start"])).days for m in heads["feeds"]] == [12, 7], "gaps of 12 and 7 days")
    # Confirmations the rule records: the ordered quantity on the planned date.
    for ref in ("P00103/L1", "P00105/L1", "P00107/L1"):
        ln = lines[ref]
        need(after[(ref, "confirmed_qty")] == ln["qty"] and after[(ref, "confirmed_date")] == ln["planned_date"],
             f"{ref} is confirmed as ordered")
    # spike-03: the warehouse date is 6 days after the requested date, nothing waits.
    shafts = lines["P00102/L1"]
    need((day(after[("P00102/L1", "planned_date")]) - day(shafts["requested_date"])).days == 6 and not shafts.get("feeds"),
         "P00102 moves to 6 days after its request with nothing waiting")
    # spike-04: 100 shipped and billed, 96 counted, the line due before today.
    grips = lines["P00101/L1"]
    po = grips["_po"]
    need(grips["qty"] == 100 and po["receipts"] == [{"line": "L1", "counted_qty": 96}]
         and po["bills"][0]["lines"] == [{"line": "L1", "qty": 100, "price_unit": 4.5}]
         and day(grips["requested_date"]) < today, "P00101: 100 billed, 96 counted, due before today")
    # The cut request: 200 grips for Reno that nothing waits on, and nothing may change on it.
    reno = lines["P00108/L1"]
    need(reno["qty"] == 200 and reno["_po"]["warehouse"] == "RNO" and not reno.get("feeds"), "P00108 is 200 grips for Reno")
    need(not any(a["record"].startswith("P00108") for a in case["expected"]["after"]), "nothing changes on P00108")
    # The base seed's own draft bill is SGW-2026-1187; the case's must differ.
    need(all(b["vendor_reference"] != "SGW-2026-1187" for p in case["setup"]["purchase_orders"] for b in p.get("bills", [])),
         "no case bill repeats the base seed's SGW-2026-1187")
    return errors


def rule_tier(case: dict, messages: list[dict]) -> list[str]:
    """No message later than a rule-recorded confirmation names its order (inbound-compose.ts, ruleFor)."""
    errors = []
    orders = {p["name"]: p for p in case["setup"]["purchase_orders"]}
    by_file = {m["file"]: m for m in messages}
    rule = {"P00103": "spike-01-confirmation-matches", "P00102": "spike-03-forwarder-new-arrival",
            "P00105": "spike-05-statement-about-other-orders", "P00107": "spike-09-instruction-to-agent"}
    texts = {f: text_of(ROOT / "messages" / f) for f in case["messages"]}
    for name, sid in rule.items():
        source = next(m for m in messages if m.get("spike") == sid)
        names = [name] + ([orders[name]["vendor_reference"]] if orders[name].get("vendor_reference") else [])
        if not any(n in texts[source["file"]] for n in names):
            errors.append(f"{source['file']} does not name {name}")
        for f, t in texts.items():
            if by_file[f]["sent"] > source["sent"] and any(n in t for n in names):
                errors.append(f"{f} is later than {source['file']} and names {name}, so the rule would refuse it")
    return errors


def main() -> int:
    on_disk = json.loads((ROOT / "case.json").read_text(encoding="utf-8"))
    today = Date.fromisoformat(on_disk["today"])
    case, messages = demo.build_case(today)
    errors = []
    if on_disk != json.loads(json.dumps(case, ensure_ascii=False)):
        errors.append("case.json differs from what make_demo.py writes for its today; run make_demo.py again")
    errors += v.check_case(demo.CASE_ID, messages, out=demo.OUT, check=check_message)
    errors += facts(on_disk)
    errors += rule_tier(on_disk, messages)
    everything = (ROOT / "case.json").read_text(encoding="utf-8") + "".join(text_of(ROOT / "messages" / f) for f in on_disk["messages"])
    for word in ("Takomo", chr(0x2014), chr(0x2013)):
        if word in everything:
            errors.append(f"the case or its mail contains {word!r}")
    for f in on_disk["messages"]:
        sent = parsedate_to_datetime(email.message_from_bytes((ROOT / "messages" / f).read_bytes(), policy=policy.default)["Date"])
        if not today - timedelta(21) <= sent.date() <= today:
            errors.append(f"{f}: sent {sent.date()}, more than three weeks before today")
    print(f"{'ok  ' if not errors else 'FAIL'} {demo.CASE_ID} (today {today})")
    for e in errors:
        print(f"     {e}")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
