"""The binary files of the hard cases: PDFs rendered from HTML, a scanned PDF
without a text layer, a phone photo of a delivery note, and signature logos.

Everything is deterministic. LibreOffice's random document id and run
checksum are fixed before qpdf rewrites the file with a deterministic id and
without the info dictionary and XMP metadata. ImageMagick's noise uses a
fixed seed, and the PNG and JPEG files carry no dates or EXIF data.
"""

import hashlib
import os
import re
import subprocess
import tempfile
from pathlib import Path

FONT_BOLD = "/usr/share/fonts/liberation/LiberationSans-Bold.ttf"
FONT_REGULAR = "/usr/share/fonts/liberation/LiberationSans-Regular.ttf"
FONT_HAND = "/usr/share/fonts/gsfonts/Z003-MediumItalic.otf"
PNG_PLAIN = ["-strip", "-define", "png:exclude-chunks=date,time,tEXt,zTXt,iTXt"]


# ImageMagick's noise is seeded per thread, so one thread keeps it the same from run to run.
ENV = {**os.environ, "MAGICK_THREAD_LIMIT": "1"}


def run(*args: str) -> None:
    subprocess.run(list(args), check=True, capture_output=True, env=ENV)


def _fix_ids(raw: bytes, key: str) -> bytes:
    """Replace a PDF's random /ID with a hash of key, and blank LibreOffice's run checksum."""
    raw = re.sub(rb"/DocChecksum /[0-9A-F]{32}", b"/DocChecksum /" + b"0" * 32, raw)
    # qpdf's --remove-info keeps the modification date, which ImageMagick sets to the run's time.
    raw = re.sub(rb"/(ModDate|CreationDate) ?\(D:\d{14}", lambda m: m.group(0)[:-14] + b"20261001000000", raw)
    fixed = hashlib.md5(key.encode()).hexdigest().upper().encode()
    raw, _ = re.subn(rb"/ID\s*\[\s*<[0-9A-Fa-f]+>\s*<[0-9A-Fa-f]+>\s*\]", b"/ID [ <" + fixed + b"> <" + fixed + b"> ]", raw)
    return raw


def _qpdf(src: Path, dst: Path, key: str) -> bytes:
    src.write_bytes(_fix_ids(src.read_bytes(), key))
    run("qpdf", "--deterministic-id", "--remove-info", "--remove-metadata", str(src), str(dst))
    return dst.read_bytes()


def render_pdfs(pages: dict[str, str]) -> dict[str, bytes]:
    """Render HTML documents (key to HTML) to PDF bytes with LibreOffice, then qpdf."""
    out = {}
    with tempfile.TemporaryDirectory(prefix="hard-pdf-") as tmp:
        tmp = Path(tmp)
        html_dir, pdf_dir, final_dir = tmp / "html", tmp / "pdf", tmp / "final"
        for p in (html_dir, pdf_dir, final_dir):
            p.mkdir()
        files = []
        for key, html in pages.items():
            f = html_dir / f"{key}.html"
            f.write_text(html, encoding="utf-8")
            files.append(str(f))
        run("soffice", f"-env:UserInstallation=file://{tmp}/profile", "--headless",
            "--infilter=HTML (StarWriter)", "--convert-to", "pdf:writer_pdf_Export",
            "--outdir", str(pdf_dir), *files)
        for key in pages:
            out[key] = _qpdf(pdf_dir / f"{key}.pdf", final_dir / f"{key}.pdf", key)
    return out


def logo_png(text: str, color: str, mark: str, width: int = 260, height: int = 60) -> bytes:
    """A small signature logo: a coloured badge with initials and the company name beside it."""
    with tempfile.TemporaryDirectory(prefix="hard-logo-") as tmp:
        out = Path(tmp) / "logo.png"
        r = height // 2 - 4
        run("magick", "-size", f"{width}x{height}", "xc:white",
            "-fill", color, "-draw", f"roundrectangle 4,4 {height - 4},{height - 4} 9,9",
            "-font", FONT_BOLD, "-pointsize", str(r), "-fill", "white",
            "-gravity", "west", "-annotate", f"+{10 if len(mark) > 1 else 18}+0", mark,
            "-fill", color, "-pointsize", "16", "-annotate", f"+{height + 6}+0", text,
            *PNG_PLAIN, f"PNG8:{out}")
        return out.read_bytes()


def page_png(pdf: bytes, dpi: int, workdir: Path, name: str) -> Path:
    src = workdir / f"{name}.pdf"
    src.write_bytes(pdf)
    run("pdftoppm", "-r", str(dpi), "-png", "-singlefile", "-f", "1", "-l", "1", str(src), str(workdir / name))
    return workdir / f"{name}.png"


def words(pdf: bytes, workdir: Path) -> list[tuple[str, float, float, float, float]]:
    """The words of page 1 with their boxes in points: (text, xMin, yMin, xMax, yMax)."""
    src = workdir / "words.pdf"
    src.write_bytes(pdf)
    html = subprocess.run(["pdftotext", "-bbox", "-f", "1", "-l", "1", str(src), "-"],
                          check=True, capture_output=True, text=True).stdout
    found = []
    for m in re.finditer(r'<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">([^<]*)</word>', html):
        found.append((m.group(5), float(m.group(1)), float(m.group(2)), float(m.group(3)), float(m.group(4))))
    return found


def scanned_pdf(pdf: bytes, *, key: str, marks) -> bytes:
    """Page 1 of pdf as an office scanner makes it: an image only, grey, a little crooked and noisy.

    marks(words, scale) returns ImageMagick drawing arguments applied to the 150 dpi page before it
    is degraded (the signature and the company stamp), given the page's words in points and the
    pixels per point.
    """
    with tempfile.TemporaryDirectory(prefix="hard-scan-") as tmp:
        tmp = Path(tmp)
        page = page_png(pdf, 150, tmp, "page")
        marked = tmp / "marked.png"
        run("magick", str(page), *marks(words(pdf, tmp), 150 / 72), str(marked))
        scan = tmp / "scan.png"
        run("magick", str(marked), "-background", "#f2f2f2", "-rotate", "0.6",
            "-gravity", "center", "-extent", "1240x1754",
            "-colorspace", "Gray", "-seed", "4711", "-attenuate", "0.35", "+noise", "Gaussian",
            "-blur", "0x0.45", "-level", "6%,94%", "-depth", "8", *PNG_PLAIN, str(scan))
        raw = tmp / "raw.pdf"
        run("magick", str(scan), "-alpha", "off", "-units", "PixelsPerInch", "-density", "150",
            "-compress", "JPEG", "-quality", "62", str(raw))
        return _qpdf(raw, tmp / "final.pdf", key)


def phone_photo(pdf: bytes, *, annotate) -> bytes:
    """A phone photo of page 1 of pdf lying on a desk, with handwriting added.

    annotate(words, scale) returns ImageMagick drawing arguments for the handwriting, given the
    page's words in points and the pixels per point.
    """
    dpi = 110
    with tempfile.TemporaryDirectory(prefix="hard-photo-") as tmp:
        tmp = Path(tmp)
        page = page_png(pdf, dpi, tmp, "page")
        written = tmp / "written.png"
        run("magick", str(page), *annotate(words(pdf, tmp), dpi / 72), str(written))
        # The paper, lit unevenly from the top left, then laid at a slight angle on a grey desk.
        lit = tmp / "lit.png"
        run("magick", str(written),
            "(", "-size", "910x1287", "radial-gradient:#ffffff-#9e978b", "-roll", "-140-210", ")",
            "-compose", "multiply", "-composite", str(lit))
        photo = tmp / "photo.jpg"
        w, h = 910, 1287
        run("magick", "-size", "1100x1460", "xc:#6b665f",
            "-seed", "77", "-attenuate", "1.6", "+noise", "Gaussian", "-blur", "0x2",
            "(", str(lit), "-alpha", "set", "-virtual-pixel", "transparent",
            "-distort", "Perspective",
            f"0,0 26,18  {w},0 {w - 6},40  0,{h} 0,{h - 22}  {w},{h} {w - 34},{h - 2}",
            "-background", "none", "-rotate", "-2.2", ")",
            "-gravity", "center", "-geometry", "+6+10", "-compose", "over", "-composite",
            "-seed", "78", "-attenuate", "0.5", "+noise", "Gaussian", "-blur", "0x0.6",
            "-modulate", "98,92,100", "-fill", "#ffd9a0", "-colorize", "7%",
            "-strip", "-sampling-factor", "2x2", "-quality", "82", str(photo))
        return photo.read_bytes()


def pdf_text(pdf: bytes) -> str:
    with tempfile.NamedTemporaryFile(suffix=".pdf") as f:
        f.write(pdf)
        f.flush()
        return subprocess.run(["pdftotext", "-layout", f.name, "-"], check=True, capture_output=True, text=True).stdout


def pdf_pages(pdf: bytes) -> int:
    with tempfile.NamedTemporaryFile(suffix=".pdf") as f:
        f.write(pdf)
        f.flush()
        info = subprocess.run(["pdfinfo", f.name], check=True, capture_output=True, text=True).stdout
    return int(re.search(r"^Pages:\s+(\d+)", info, re.M).group(1))
