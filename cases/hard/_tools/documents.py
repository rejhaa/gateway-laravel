"""HTML layouts of the supplier documents in the hard cases.

Each function returns one HTML document. generate.py renders them to PDF
with LibreOffice; the scan and the phone photo start from such a PDF too.
The parties and products are invented.
"""

from html import escape

VUOKSI = [
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

KESTREL = [
    "Kestrel Sports GmbH",
    "Einkauf, Herr Jonas Becker",
    "Am Holzhafen 12",
    "28217 Bremen, Germany",
]

KESTREL_DELIVERY = [
    "Kestrel Sports GmbH, Zentrallager",
    "Senator-Bortscheller-Str. 7",
    "27711 Osterholz-Scharmbeck, Germany",
]

TAI_YUAN = {
    "name": "Tai-Yuan Forge &amp; Shaft Co., Ltd.",
    "lines": [
        "No. 88, Linhai 3rd Road, Xiaogang District, Kaohsiung 812, Taiwan",
        "Tel +886 7 871 2290 &middot; sales@tyforge.com.tw &middot; Tax ID 84149738",
    ],
    "color": "#7a1f1f",
    "footer": "Payment 30 days from invoice date. Incoterms 2020 as stated in the order. "
    "Please check this confirmation and tell us of any difference within 3 working days.",
}

SAIGON = {
    "name": "Saigon Grip Works Co., Ltd.",
    "lines": [
        "Lot C3, Tan Thuan Export Processing Zone, Tan Thuan Dong Ward, District 7, Ho Chi Minh City, Vietnam",
        "Tel +84 28 3770 4152 &middot; export@saigongrip.com.vn &middot; Tax code 0315829464",
    ],
    "color": "#1f4f3a",
    "footer": "Payment 30 days from invoice date. Incoterms 2020 CPT Lahti. "
    "Saigon Grip Works Co., Ltd. is registered in Ho Chi Minh City.",
}

EVERBRIGHT = {
    "name": "Xiamen Everbright Sporting Goods Co., Ltd.",
    "lines": [
        "No. 166 Xinglin Industrial Park, Jimei District, Xiamen, Fujian 361022, China",
        "Tel +86 592 621 7735 &middot; export@everbright-sports.cn",
    ],
    "color": "#0f4c81",
    "footer": "Terms of sale: Incoterms 2020 DAP Lahti, Finland. Payment 30% deposit, 70% against copy of "
    "bill of lading. ETD and ETA are the carrier's schedule and may change.",
}

STYLE = """
body { font-family: 'Liberation Sans', sans-serif; font-size: 9.5pt; color: #222; }
td, th { font-family: 'Liberation Sans', sans-serif; font-size: 9.5pt; vertical-align: top; }
.small { font-size: 8pt; color: #555; }
.title { font-size: 15pt; font-weight: bold; }
"""


def money(x: float) -> str:
    return f"{x:,.2f}"


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


WRAP = {"Description", "Contents"}


def _table(color: str, headers: list[str], rows: list[list[str]], align: list[str],
           total_row: list[str] | None = None) -> str:
    head = "".join(
        f"<th align='{a}' bgcolor='{color}'><font color='#ffffff'>{escape(h)}</font></th>"
        for h, a in zip(headers, align)
    )
    body = ""
    for i, row in enumerate(rows):
        bg = "#f3f3f3" if i % 2 else "#ffffff"
        body += "<tr>" + "".join(
            f"<td valign='top' align='{a}' bgcolor='{bg}'{'' if h in WRAP else ' nowrap'}>{escape(c)}</td>"
            for c, a, h in zip(row, align, headers)
        ) + "</tr>"
    if total_row:
        body += "<tr>" + "".join(f"<td align='{a}'><b>{escape(c)}</b></td>" for c, a in zip(total_row, align)) + "</tr>"
    return f"""
<table width="100%" cellpadding="4" cellspacing="0" border="1" bordercolor="#bbbbbb">
<tr>{head}</tr>
{body}
</table>
"""


def _kv(rows: list[tuple[str, str]]) -> str:
    body = "".join(
        f"<tr><td width='32%'><font color='#555555'>{escape(k)}</font></td><td>{escape(v)}</td></tr>"
        for k, v in rows
    )
    return f"<table width='100%' cellpadding='2' cellspacing='0'>{body}</table>"


def _remarks(items: list[str], title: str = "Remarks") -> str:
    if not items:
        return ""
    lis = "".join(f"<li>{escape(x)}</li>" for x in items)
    return f"<br><p><b>{escape(title)}</b></p><ol>{lis}</ol>"


def _html(inner: str) -> str:
    return f"""<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>{STYLE}</style></head>
<body>
{inner}
</body></html>
"""


def _closing(party: dict, signature: list[str]) -> str:
    sig = "<br>".join(escape(x) for x in signature)
    return f"""
<br>
<p>{sig}</p>
<hr size="1" color="#bbbbbb" noshade>
<p class="small">{party['footer']}</p>
"""


def order_confirmation(party: dict, *, title: str = "ORDER CONFIRMATION", meta: list[tuple[str, str]],
                       customer: list[str] = VUOKSI, deliver_to: list[str] = LAHTI_DELIVERY,
                       intro: str, lines: list[dict], show_ordered: bool, remarks: list[str],
                       signer: list[str], fmt_date, shipment: list[tuple[str, str]] | None = None,
                       page_two: list[str] | None = None, date_header: str = "Delivery date") -> str:
    """lines: {item, description, ordered, qty, unit_price, date}. qty is the confirmed quantity.

    page_two: paragraphs printed after a page break, after the remarks (general terms).
    """
    headers = ["Pos.", "Item", "Description"]
    align = ["left", "left", "left"]
    if show_ordered:
        headers += ["Ordered", "Confirmed"]
        align += ["right", "right"]
    else:
        headers += ["Qty"]
        align += ["right"]
    headers += ["Unit", "Unit price", "Amount", date_header]
    align += ["left", "right", "right", "left"]
    rows, total, total_qty = [], 0.0, 0
    for i, ln in enumerate(lines, start=1):
        amount = ln["qty"] * ln["unit_price"]
        total += amount
        total_qty += ln["qty"]
        row = [str(i * 10), ln["item"], ln["description"]]
        row += [str(ln["ordered"]), str(ln["qty"])] if show_ordered else [str(ln["qty"])]
        row += ["pcs", money(ln["unit_price"]), money(amount), fmt_date(ln["date"]) if ln["date"] else "see below"]
        rows.append(row)
    total_row = ["", "", "Total"] + (["", str(total_qty)] if show_ordered else [str(total_qty)]) + ["pcs", "", money(total), ""]
    inner = _letterhead(party)
    inner += _meta_table(title, meta, "Customer", customer, "Deliver to", deliver_to)
    inner += f"<p>{escape(intro)}</p>" + _table(party["color"], headers, rows, align, total_row)
    if shipment:
        inner += "<br><p><b>Shipment</b></p>" + _kv(shipment)
    if page_two:
        inner += "<br><p class='small'>Continued on page 2.</p>"
        inner += "<p style='page-break-before: always'></p>" + _letterhead(party)
        inner += _remarks(remarks)
        inner += "<br><p><b>General conditions of sale (extract)</b></p>"
        inner += "".join(f"<p class='small'>{escape(p)}</p>" for p in page_two)
    else:
        inner += _remarks(remarks)
    inner += _closing(party, signer)
    return _html(inner)


def packing_list(party: dict, *, meta: list[tuple[str, str]], transport: list[tuple[str, str]],
                 lines: list[dict], cartons: list[tuple[str, str, str, str]], signer: list[str]) -> str:
    """A delivery note and packing list. lines: {item, description, qty}."""
    inner = _letterhead(party)
    inner += _meta_table("DELIVERY NOTE / PACKING LIST", meta, "Consignee", LAHTI_DELIVERY)
    inner += "<p><b>Shipment</b></p>" + _kv(transport) + "<br>"
    total_qty = sum(ln["qty"] for ln in lines)
    inner += _table(
        party["color"],
        ["Pos.", "Item", "Description", "Qty shipped", "Unit"],
        [[str(i * 10), ln["item"], ln["description"], str(ln["qty"]), "pcs"] for i, ln in enumerate(lines, start=1)],
        ["left", "left", "left", "right", "left"],
        ["", "", "Total", str(total_qty), "pcs"],
    )
    inner += "<br><p><b>Cartons</b></p>"
    inner += _table(party["color"], ["Carton", "Contents", "Net weight", "Gross weight"],
                    [list(c) for c in cartons], ["left", "left", "right", "right"])
    inner += "<br><p>Received in good order and condition:</p><p>Name ______________________&nbsp;&nbsp; Date __________&nbsp;&nbsp; Signature ______________________</p>"
    inner += _closing(party, signer)
    return _html(inner)
