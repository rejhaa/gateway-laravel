"""Outlook-style mail for the hard cases, built with Python's email package.

A message is multipart/alternative (plain text and HTML). With a signature
logo the alternative sits in a multipart/related next to the inline image,
as Outlook sends it, and with attachments all of it sits in a
multipart/mixed. Replies quote the earlier mail under Outlook's
From/Sent/To/Subject block. Boundaries, Message-IDs and Thread-Index values
are derived from the case and file names, so the output is deterministic.
"""

import base64
import hashlib
from dataclasses import dataclass, field
from datetime import datetime
from email.message import EmailMessage, Message
from email.mime.application import MIMEApplication
from email.mime.image import MIMEImage
from email.mime.message import MIMEMessage
from email.mime.multipart import MIMEMultipart
from email.policy import SMTP
from email.utils import format_datetime, formataddr
from html import escape

PURCHASING = ("Vuoksi Golf purchasing", "purchasing@vuoksigolf.fi")

# The id of the server that receives the purchasing mailbox's mail. It writes the
# Authentication-Results header the gateway reads (gateway.mailbox.authserv_id).
AUTHSERV_ID = "mx.vuoksigolf.example"


@dataclass
class Signature:
    lines: list[str]
    logo: bytes | None = None


@dataclass
class Quoted:
    sender: tuple[str, str]
    to: list[tuple[str, str]]
    sent: datetime
    subject: str
    paragraphs: list[str]
    signature: list[str]
    cc: list[tuple[str, str]] = field(default_factory=list)


@dataclass
class Attachment:
    filename: str
    maintype: str
    subtype: str
    data: bytes


@dataclass
class Mail:
    sender: tuple[str, str]
    to: list[tuple[str, str]]
    sent: datetime
    subject: str
    paragraphs: list[str]
    signature: Signature
    cc: list[tuple[str, str]] = field(default_factory=list)
    quoted: list[Quoted] = field(default_factory=list)
    attachments: list[Attachment] = field(default_factory=list)
    forwarded: "Mail | None" = None
    forwarded_name: str = ""
    language: str = "en-US"
    footer: str = ""  # a client footer such as "Get Outlook for iOS"
    thread_topic: str = ""


def _h(seed: str, n: int) -> str:
    return hashlib.sha1(seed.encode()).hexdigest().upper()[:n]


def _strip_mime_version(part: Message) -> Message:
    del part["MIME-Version"]
    return part


def _text(text: str, subtype: str) -> Message:
    p = EmailMessage(policy=SMTP)
    p.set_content(text, subtype=subtype, charset="utf-8", cte="quoted-printable")
    return _strip_mime_version(p)


def _multi(subtype: str, parts: list[Message], boundary: str, **params) -> Message:
    m = MIMEMultipart(subtype, boundary=boundary, policy=SMTP, **params)
    _strip_mime_version(m)
    for p in parts:
        m.attach(p)
    return m


def _outlook_sent(dt: datetime) -> str:
    hour = dt.hour % 12 or 12
    return f"{dt:%A}, {dt:%B} {dt.day}, {dt.year} {hour}:{dt:%M} {'AM' if dt.hour < 12 else 'PM'}"


def _addr_list(addrs: list[tuple[str, str]]) -> str:
    return "; ".join(f"{n} <{a}>" for n, a in addrs)


def _plain(mail: Mail, cid: str | None) -> str:
    out = list(mail.paragraphs)
    sig = list(mail.signature.lines)
    if cid:
        sig.append(f"[cid:{cid}]")
    text = "\n\n".join(out) + "\n\n" + "\n".join(sig) + "\n"
    if mail.footer:
        text += "\n" + mail.footer + "\n"
    for q in mail.quoted:
        head = [f"From: {q.sender[0]} <{q.sender[1]}>", f"Sent: {_outlook_sent(q.sent)}", f"To: {_addr_list(q.to)}"]
        if q.cc:
            head.append(f"Cc: {_addr_list(q.cc)}")
        head.append(f"Subject: {q.subject}")
        text += "\n" + "\n".join(head) + "\n\n" + "\n\n".join(q.paragraphs) + "\n\n" + "\n".join(q.signature) + "\n"
    return text


STYLE = """<style><!--
@font-face {font-family:Aptos;}
p.MsoNormal, li.MsoNormal, div.MsoNormal {margin:0cm; font-size:11.0pt; font-family:"Aptos",sans-serif;}
a:link, span.MsoHyperlink {color:#467886; text-decoration:underline;}
span.EmailStyle20 {mso-style-type:personal-reply; font-family:"Aptos",sans-serif; color:windowtext;}
.MsoChpDefault {mso-style-type:export-only; font-size:10.0pt;}
@page WordSection1 {size:612.0pt 792.0pt; margin:72.0pt 72.0pt 72.0pt 72.0pt;}
div.WordSection1 {page:WordSection1;}
--></style>"""


def _p(text: str) -> str:
    if not text:
        return '<p class="MsoNormal"><o:p>&nbsp;</o:p></p>'
    return '<p class="MsoNormal">' + "<br>".join(escape(x) for x in text.split("\n")) + "<o:p></o:p></p>"


def _html(mail: Mail, cid: str | None) -> str:
    body = "\n".join(_p(x) + "\n" + _p("") for x in mail.paragraphs)
    sig = mail.signature.lines
    sig_html = f'<p class="MsoNormal"><b><span style="color:#1F3864">{escape(sig[0])}</span></b><o:p></o:p></p>\n'
    sig_html += "\n".join(f'<p class="MsoNormal"><span style="font-size:9.0pt;color:#595959">{escape(x)}</span><o:p></o:p></p>' for x in sig[1:])
    if cid:
        sig_html += f'\n<p class="MsoNormal"><img width="260" height="60" style="width:2.7083in;height:.625in" id="Picture_x0020_1" src="cid:{cid}" alt="logo"><o:p></o:p></p>'
    quoted = ""
    for q in mail.quoted:
        head = [f"<b>From:</b> {escape(q.sender[0])} &lt;{escape(q.sender[1])}&gt;",
                f"<b>Sent:</b> {escape(_outlook_sent(q.sent))}",
                f"<b>To:</b> {escape(_addr_list(q.to))}"]
        if q.cc:
            head.append(f"<b>Cc:</b> {escape(_addr_list(q.cc))}")
        head.append(f"<b>Subject:</b> {escape(q.subject)}")
        quoted += ('\n<div style="border:none;border-top:solid #E1E1E1 1.0pt;padding:3.0pt 0cm 0cm 0cm">\n'
                   '<p class="MsoNormal">' + "<br>\n".join(head) + "<o:p></o:p></p>\n</div>\n" + _p("") + "\n")
        quoted += "\n".join(_p(x) + "\n" + _p("") for x in q.paragraphs)
        quoted += "\n" + _p("\n".join(q.signature))
    footer = f"\n{_p('')}\n{_p(mail.footer)}" if mail.footer else ""
    lang = mail.language.upper()
    return f"""<html xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns:m="http://schemas.microsoft.com/office/2004/12/omml" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="Generator" content="Microsoft Word 15 (filtered medium)">
{STYLE}
</head>
<body lang="{lang}" link="#467886" vlink="#96607D" style="word-wrap:break-word">
<div class="WordSection1">
{body}
{sig_html}{footer}
{_p('')}
{quoted}
</div>
</body>
</html>
"""


def build(mail: Mail, seed: str, *, received: bool = True) -> Message:
    """The message as a Message object, for writing out or for forwarding as an attachment.

    A received message starts with the Authentication-Results header of the purchasing mailbox's
    server (RFC 8601), which verified the sender. A message forwarded as an attachment is the
    sender's own copy and has none.
    """
    cid = f"image001.png@01DD{_h(seed + 'cid', 4)}.{_h(seed + 'cid2', 8)}" if mail.signature.logo else None
    body = _multi("alternative", [_text(_plain(mail, cid), "plain"), _text(_html(mail, cid), "html")],
                  f"_000_{_h(seed + 'alt', 24)}_")
    if cid:
        logo = MIMEImage(mail.signature.logo, "png", policy=SMTP, name="image001.png")
        _strip_mime_version(logo)
        logo.add_header("Content-Description", "image001.png")
        logo.add_header("Content-Disposition", "inline", filename="image001.png")
        logo.add_header("Content-ID", f"<{cid}>")
        body = _multi("related", [body, logo], f"_004_{_h(seed + 'rel', 24)}_", type="multipart/alternative")
    parts = []
    for a in mail.attachments:
        if a.maintype == "image":
            att = MIMEImage(a.data, a.subtype, policy=SMTP, name=a.filename)
        else:
            att = MIMEApplication(a.data, a.subtype, policy=SMTP, name=a.filename)
        _strip_mime_version(att)
        att.add_header("Content-Description", a.filename)
        att.add_header("Content-Disposition", "attachment", filename=a.filename)
        parts.append(att)
    if mail.forwarded is not None:
        inner = build(mail.forwarded, seed + "/fwd", received=False)
        fwd = MIMEMessage(inner, policy=SMTP)
        _strip_mime_version(fwd)
        fwd.add_header("Content-Description", mail.forwarded_name)
        fwd.add_header("Content-Disposition", "attachment", filename=mail.forwarded_name)
        parts.append(fwd)
    top = _multi("mixed", [body, *parts], f"_005_{_h(seed + 'mix', 24)}_") if parts else body

    domain = mail.sender[1].split("@")[1]
    server = {"vuoksigolf.fi": "HE1PR09MB3311.eurprd09.prod.outlook.com",
              "tyforge.com.tw": "TYZPR03MB6824.apcprd03.prod.outlook.com",
              "saigongrip.com.vn": "SG2PR04MB5502.apcprd04.prod.outlook.com"}.get(domain, f"mail.{domain}")
    host = server.split(".")[0]
    headers = []
    if received:
        headers.append(("Authentication-Results",
                        f"{AUTHSERV_ID}; spf=pass smtp.mailfrom={domain}; dkim=pass header.d={domain}; "
                        f"dmarc=pass header.from={domain}"))
    headers += [
        ("From", formataddr(mail.sender)),
        ("To", ", ".join(formataddr(a) for a in mail.to)),
    ]
    if mail.cc:
        headers.append(("Cc", ", ".join(formataddr(a) for a in mail.cc)))
    headers += [
        ("Subject", mail.subject),
        ("Thread-Topic", mail.thread_topic or mail.subject.split(": ", 1)[-1]),
        ("Thread-Index", base64.b64encode(hashlib.sha256((seed + "ti").encode()).digest()[:22]).decode()),
        ("Date", format_datetime(mail.sent)),
        ("Message-ID", f"<{host}{_h(seed + 'mid', 26)}@{server}>"),
    ]
    if mail.quoted:
        q = mail.quoted[0]
        qdomain = q.sender[1].split("@")[1]
        ref = f"<{_h(seed + 'ref', 30)}@{qdomain}>"
        headers += [("References", ref), ("In-Reply-To", ref)]
    headers += [
        ("Accept-Language", f"{mail.language}, en-US"),
        ("Content-Language", mail.language),
        ("X-MS-Has-Attach", "yes" if parts else ""),
        ("X-MS-TNEF-Correlator", ""),
        ("MIME-Version", "1.0"),
    ]
    existing = [(k, v) for k, v in top.items()]
    for k in {k for k, _ in existing}:
        del top[k]
    for k, v in headers:
        top[k] = v
    for k, v in existing:
        top[k] = v
    return top


def to_bytes(message: Message) -> bytes:
    return message.as_bytes(policy=SMTP)
