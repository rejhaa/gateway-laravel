#!/usr/bin/env python3
"""Check the sender demo case against CASE-FORMAT.md and against what make_sender_demo.py meant.

Run after make_sender_demo.py: python3 check_sender_demo.py. It rebuilds the
case in memory, runs the spike validator's checks of the format and the PDF
text layers over the case on disk, and adds the case's own: each message
carries the sender and the Authentication-Results header its story needs,
and no message names another message's order (the gateway's rule tier would
refuse the earlier one then). Exits non-zero when anything is wrong.
"""

import email
import json
import sys
from email import policy
from email.utils import parseaddr

import make_sender_demo as demo
import validate as v

ROOT = demo.OUT / demo.CASE_ID
spike = demo.spike

TAI_YUAN_DOMAIN = spike.MEI_LING[1].split("@")[1]
SAIGON_DOMAIN = spike.LINH[1].split("@")[1]

# file: the sender's address, and whether the mailbox's server verified it
SENDERS = {
    "01-order-confirmation-p00101.eml": (spike.MEI_LING[1], True),
    "02-order-confirmation-p00102.eml": (f"kevin.huang@{TAI_YUAN_DOMAIN}", True),
    "03-revised-confirmation-p00103.eml": (spike.MEI_LING[1], False),
    "04-order-confirmation-p00104.eml": ("export@saigon-grip.example", True),
}


def senders() -> list[str]:
    errors = []
    for file, (address, verified) in SENDERS.items():
        msg = email.message_from_bytes((ROOT / "messages" / file).read_bytes(), policy=policy.default)
        domain = address.split("@")[1]
        results = " ".join(str(msg["Authentication-Results"]).split())
        if parseaddr(msg["From"])[1] != address:
            errors.append(f"{file}: From is not {address}")
        if not results.startswith(f"{spike.AUTHSERV_ID};"):
            errors.append(f"{file}: Authentication-Results is not the mailbox server's")
        if (f"dmarc=pass header.from={domain}" in results) != verified:
            errors.append(f"{file}: DMARC should {'pass' if verified else 'not pass'} for {domain}")
        if not verified and f"dmarc=fail header.from={domain}" not in results:
            errors.append(f"{file}: DMARC should fail for {domain}")
    if SENDERS["02-order-confirmation-p00102.eml"][0] in (spike.MEI_LING[1], spike.LINH[1]):
        errors.append("the colleague's address is one Odoo holds")
    if SENDERS["04-order-confirmation-p00104.eml"][0].split("@")[1] == SAIGON_DOMAIN:
        errors.append("the lookalike sender is at Saigon Grip Works' own domain")
    return errors


def one_order_each(case: dict) -> list[str]:
    errors = []
    orders = [(p["name"], p["vendor_reference"]) for p in case["setup"]["purchase_orders"]]
    for file, own in zip(case["messages"], orders):
        raw = (ROOT / "messages" / file).read_bytes()
        msg = email.message_from_bytes(raw, policy=policy.default)
        text = msg["Subject"] + msg.get_body(preferencelist=("plain",)).get_content()
        if not any(name in text for name in own):
            errors.append(f"{file} does not name {own[0]}")
        for other in orders:
            if other != own and any(name in text for name in other):
                errors.append(f"{file} names {other[0]}, which belongs to another message")
    return errors


def main() -> int:
    on_disk = json.loads((ROOT / "case.json").read_text(encoding="utf-8"))
    case, messages = demo.build_case()
    errors = []
    if on_disk != json.loads(json.dumps(case, ensure_ascii=False)):
        errors.append("case.json differs from what make_sender_demo.py writes; run make_sender_demo.py again")
    errors += v.check_case(demo.CASE_ID, messages, out=demo.OUT)
    errors += senders()
    errors += one_order_each(on_disk)
    print(f"{'ok  ' if not errors else 'FAIL'} {demo.CASE_ID} (today {on_disk['today']})")
    for e in errors:
        print(f"     {e}")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
