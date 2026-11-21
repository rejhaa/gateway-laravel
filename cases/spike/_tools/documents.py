"""HTML layouts of the supplier and forwarder documents in the spike cases.

Each function returns one HTML page. generate.py renders the pages to PDF
with LibreOffice and attaches them to the mail. The parties are invented.
"""

from html import escape

CUSTOMER = [
    "Vuoksi Golf Oy",
    "Attn. Riku Lindgren, purchasing",
    "Karjalankatu 45",
    "15150 Lahti, Finland",
    "purchasing@vuoksigolf.fi",
]

LAHTI_DELIVERY = [
    "Vuoksi Golf Oy, Lahti warehouse",
    "Karjalankatu 45",
    "15150 Lahti, Finland",
]

TAI_YUAN = {
    "name": "Tai-Yuan Forge &amp; Shaft Co., Ltd.",
    "lines": [
        "No. 88, Linhai 3rd Road, Xiaogang District, Kaohsiung 812, Taiwan",
        "Tel +886 7 871 2290 &middot; orders@taiyuan-forge.example &middot; Tax ID 84149738",
    ],
    "color": "#7a1f1f",
    "footer": "Payment 30 days from invoice date. Incoterms 2020 DAP Lahti. "
    "Please check this confirmation and tell us of any difference within 3 working days.",
}

SAIGON = {
    "name": "Saigon Grip Works Co., Ltd.",
    "lines": [
        "Lot C3, Tan Thuan Export Processing Zone, Tan Thuan Dong Ward, District 7, Ho Chi Minh City, Vietnam",
        "Tel +84 28 3770 4152 &middot; export@saigongrip.example &middot; Tax code 0315829464",
    ],
    "color": "#1f4f3a",
    "footer": "Payment 30 days from invoice date. Incoterms 2020 CPT Lahti. "
    "Saigon Grip Works Co., Ltd. is registered in Ho Chi Minh City.",
}

NORDIC_FREIGHT = {
    "name": "Nordic Freight Partners Oy",
    "lines": [
        "Tikkurilantie 136, 01530 Vantaa, Finland",
        "Tel +358 9 4247 1800 &middot; ops@nordicfreight.example &middot; Business ID 3189442-6",
    ],
    "color": "#1d3c6e",
    "footer": "All services are performed under the General Conditions of the Nordic Association "
    "of Freight Forwarders (NSAB 2015). Dates marked estimated or planned may change.",
}

STYLE = """
body { font-family: 'Liberation Sans', sans-serif; font-size: 9.5pt; color: #222; }
td, th { font-family: 'Liberation Sans', sans-serif; font-size: 9.5pt; vertical-align: top; }
.small { font-size: 8pt; color: #555; }
.title { font-size: 15pt; font-weight: bold; }
"""


def _letterhead(party: dict) -> str:
    lines = "<br>".join(party["lines"])
    return f"""
<table width="100%" cellpadding="0" cellspacing="0">
<tr><td>
<font size="5" color="{party['color']}"><b>{party['name']}</b></font><br>
<font size="1" color="#555555">{lines}</font>
</td></tr>
</table>
<hr size="2" color="{party['color']}" noshade>
"""


def _meta_table(title: str, meta: list[tuple[str, str]], left_title: str, left: list[str],
                right_title: str | None = None, right: list[str] | None = None) -> str:
    """The addresses on the left and the document data on the right, as one flat table."""
    cells = [f"<font color='#555555'>{escape(left_title)}</font>"] + [escape(x) for x in left]
    if right_title and right:
        cells += ["&nbsp;", f"<font color='#555555'>{escape(right_title)}</font>"] + [escape(x) for x in right]
    rows = max(len(cells), len(meta))
    cells += [""] * (rows - len(cells))
    meta = list(meta) + [("", "")] * (rows - len(meta))
    body = "".join(
        f"<tr><td width='50%'>{c}</td>"
        f"<td width='20%'><font color='#555555'>{escape(k)}</font></td>"
        f"<td width='30%'><b>{escape(v)}</b></td></tr>"
        for c, (k, v) in zip(cells, meta)
    )
    return f"""
<p class="title">{escape(title)}</p>
<table width="100%" cellpadding="0" cellspacing="0">{body}</table>
<br>
"""


def _lines_table(color: str, headers: list[str], rows: list[list[str]], align: list[str],
                 total_row: list[str] | None = None) -> str:
    keep = [i for i in range(len(headers)) if any(r[i] for r in rows) or headers[i] not in ("Item",)]
    headers = [headers[i] for i in keep]
    align = [align[i] for i in keep]
    rows = [[r[i] for i in keep] for r in rows]
    if total_row:
        total_row = [total_row[i] for i in keep]
    head = "".join(
        f"<th align='{a}' bgcolor='{color}'><font color='#ffffff'>{escape(h)}</font></th>"
        for h, a in zip(headers, align)
    )
    body = ""
    for i, row in enumerate(rows):
        bg = "#f3f3f3" if i % 2 else "#ffffff"
        body += "<tr>" + "".join(
            f"<td valign='top' align='{a}' bgcolor='{bg}'>{escape(c)}</td>" for c, a in zip(row, align)
        ) + "</tr>"
    if total_row:
        body += "<tr>" + "".join(
            f"<td align='{a}'><b>{escape(c)}</b></td>" for c, a in zip(total_row, align)
        ) + "</tr>"
    return f"""
<table width="100%" cellpadding="4" cellspacing="0" border="1" bordercolor="#bbbbbb">
<tr>{head}</tr>
{body}
</table>
"""


def _remarks(items: list[str]) -> str:
    if not items:
        return ""
    lis = "".join(f"<li>{escape(x)}</li>" for x in items)
    return f"<br><p><b>Remarks</b></p><ol>{lis}</ol>"


def _page(party: dict, inner: str, signature: list[str]) -> str:
    sig = "<br>".join(escape(x) for x in signature)
    return f"""<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>{STYLE}</style></head>
<body>
{_letterhead(party)}
{inner}
<br>
<p>{sig}</p>
<hr size="1" color="#bbbbbb" noshade>
<p class="small">{party['footer']}</p>
</body></html>
"""


def money(x: float) -> str:
    return f"{x:,.2f}"


def order_confirmation(party: dict, *, document_no: str, date: str, our_order: str,
                       supplier_order: str | None, lines: list[dict], remarks: list[str],
                       signer: list[str], fmt_date) -> str:
    """lines: {item, description, qty, unit_price, date}."""
    meta = [("Confirmation no.", document_no), ("Date", fmt_date(date)), ("Your order no.", our_order)]
    if supplier_order:
        meta.append(("Our sales order", supplier_order))
    meta.append(("Currency", "EUR"))
    rows = []
    total = 0.0
    total_qty = 0
    for i, ln in enumerate(lines, start=1):
        amount = ln["qty"] * ln["unit_price"]
        total += amount
        total_qty += ln["qty"]
        rows.append([
            str(i * 10), ln["item"], ln["description"], f"{ln['qty']}", "pcs",
            money(ln["unit_price"]), money(amount), fmt_date(ln["date"]),
        ])
    table = _lines_table(
        party["color"],
        ["Pos.", "Item", "Description", "Qty", "Unit", "Unit price", "Amount", "Delivery date"],
        rows,
        ["left", "left", "left", "right", "left", "right", "right", "left"],
        ["", "", "Total", f"{total_qty}", "pcs", "", money(total), ""],
    )
    inner = _meta_table("ORDER CONFIRMATION", meta, "Customer", CUSTOMER, "Deliver to", LAHTI_DELIVERY)
    inner += "<p>We confirm your purchase order as follows.</p>" + table + _remarks(remarks)
    return _page(party, inner, signer)


def packing_list(party: dict, *, document_no: str, date: str, our_order: str,
                 supplier_order: str | None, shipped_date: str, transport: list[tuple[str, str]],
                 lines: list[dict], cartons: list[tuple[str, str, str, str]], signer: list[str],
                 fmt_date) -> str:
    """lines: {item, description, qty}. cartons: (carton no, contents, net kg, gross kg)."""
    meta = [("Packing list no.", document_no), ("Date", fmt_date(date)), ("Your order no.", our_order)]
    if supplier_order:
        meta.append(("Our sales order", supplier_order))
    meta.append(("Shipped on", fmt_date(shipped_date)))
    inner = _meta_table("PACKING LIST", meta, "Consignee", LAHTI_DELIVERY)
    trows = "".join(
        f"<tr><td width='30%'><font color='#555555'>{escape(k)}</font></td><td>{escape(v)}</td></tr>"
        for k, v in transport
    )
    inner += f"<p><b>Shipment</b></p><table width='100%' cellpadding='2' cellspacing='0'>{trows}</table><br>"
    total_qty = sum(ln["qty"] for ln in lines)
    inner += _lines_table(
        party["color"],
        ["Pos.", "Item", "Description", "Qty shipped", "Unit"],
        [[str(i * 10), ln["item"], ln["description"], f"{ln['qty']}", "pcs"] for i, ln in enumerate(lines, start=1)],
        ["left", "left", "left", "right", "left"],
        ["", "", "Total", f"{total_qty}", "pcs"],
    )
    inner += "<br><p><b>Cartons</b></p>"
    inner += _lines_table(
        party["color"],
        ["Carton", "Contents", "Net weight", "Gross weight"],
        [list(c) for c in cartons],
        ["left", "left", "right", "right"],
    )
    return _page(party, inner, signer)


def delivery_notice(party: dict, *, document_no: str, date: str, our_order: str, shipment: str,
                    details: list[tuple[str, str]], milestones: list[tuple[str, str, str, str]],
                    text: str, signer: list[str], fmt_date) -> str:
    """milestones: (event, place, date, status)."""
    meta = [("Notice no.", document_no), ("Date", fmt_date(date)), ("Shipment", shipment),
            ("Customer reference", our_order)]
    inner = _meta_table("DELIVERY NOTICE", meta, "Consignee", CUSTOMER)
    drows = "".join(
        f"<tr><td width='30%'><font color='#555555'>{escape(k)}</font></td><td>{escape(v)}</td></tr>"
        for k, v in details
    )
    inner += f"<table width='100%' cellpadding='2' cellspacing='0'>{drows}</table><br>"
    inner += f"<p>{escape(text)}</p>"
    inner += _lines_table(
        party["color"],
        ["Milestone", "Place", "Date", "Status"],
        [[m[0], m[1], fmt_date(m[2]), m[3]] for m in milestones],
        ["left", "left", "left", "left"],
    )
    return _page(party, inner, signer)
