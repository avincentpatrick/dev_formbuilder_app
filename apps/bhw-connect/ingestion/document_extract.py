"""Generic PDF parser/OCR child. Private JSON output; no database or AI credentials.
PyMuPDF is AGPL/commercial (already used by this repository); Tesseract is Apache-2.0.
See docs/POLICY_DOCUMENT_WORKER.md for deployment/licensing requirements.
"""
import argparse
import json
import os
import re
import subprocess
import tempfile
from pathlib import Path

MAX_BYTES = 25 * 1024 * 1024
MAX_PAGES = 300
MAX_CHARACTERS = 10_000_000
MAX_PIXELS = 12_000_000


class ExtractionError(Exception):
    pass


def extract(path, languages="eng", tesseract="tesseract", progress=lambda *_: None):
    import fitz
    if not re.fullmatch(r"[a-z]{3}(\+[a-z]{3})*", languages):
        raise ExtractionError("Invalid OCR language configuration")
    if Path(path).stat().st_size > MAX_BYTES:
        raise ExtractionError("PDF exceeds 25 MiB")
    try:
        doc = fitz.open(path)
    except Exception as exc:
        raise ExtractionError("PDF could not be opened") from exc
    with doc:
        if not doc.is_pdf:
            raise ExtractionError("Only PDF files are accepted")
        if doc.needs_pass:
            raise ExtractionError("Encrypted PDF is not supported")
        if not 1 <= len(doc) <= MAX_PAGES:
            raise ExtractionError("PDF must contain 1 to 300 pages")
        texts, pages, warnings = [], [], []
        ocr_version = None
        offset = 0
        with tempfile.TemporaryDirectory(prefix="bhw-ocr-") as folder:
            for index, page in enumerate(doc):
                progress("extracting", index, len(doc))
                notes = []
                try:
                    native = page.get_text("text", sort=True).replace("\x00", "\ufffd")
                    # Include mixed pages: selectable text never establishes that images are irrelevant.
                    needs_ocr = bool(page.get_image_info()) or len(native.strip()) < 40 or bool(page.rotation)
                    if len(page.get_drawings()) >= 4:
                        notes.append("layout_or_table_unverified")
                    method = "native"
                    text = native
                    if needs_ocr:
                        progress("ocr", index, len(doc))
                        if ocr_version is None:
                            try:
                                ocr_version = subprocess.check_output([tesseract, "--version"], text=True, timeout=10).splitlines()[0]
                                installed = subprocess.check_output([tesseract, "--list-langs"], text=True, timeout=10).splitlines()[1:]
                            except (OSError, subprocess.SubprocessError) as exc:
                                raise ExtractionError("Local OCR is unavailable") from exc
                            if not set(languages.split("+")).issubset(set(installed)):
                                raise ExtractionError("Required OCR language data is missing")
                        if page.rect.width <= 0 or page.rect.height <= 0:
                            raise ExtractionError("Invalid page dimensions")
                        scale = min(200 / 72, (MAX_PIXELS / (page.rect.width * page.rect.height)) ** .5)
                        image = Path(folder) / "page.png"
                        page.get_pixmap(matrix=fitz.Matrix(scale, scale), colorspace=fitz.csGRAY, alpha=False).save(image)
                        try:
                            result = subprocess.run([tesseract, str(image), "stdout", "-l", languages, "--psm", "3"],
                                                    capture_output=True, text=True, encoding="utf-8", timeout=60,
                                                    env={**os.environ, "OMP_THREAD_LIMIT": "1"}, check=True)
                            ocr = result.stdout.replace("\x00", "\ufffd")
                            # Keep the native layer verbatim, plus OCR for image evidence; no silent deduplication.
                            text = native + ("\n[Local OCR transcription]\n" if native.strip() and ocr.strip() else "") + ocr
                            method = "native+ocr" if native.strip() else "ocr"
                            notes.append("ocr_accuracy_and_reading_order_unverified")
                            if native.strip() and ocr.strip():
                                notes.append("native_and_ocr_may_overlap")
                        except (OSError, subprocess.SubprocessError):
                            # Existing native text may remain searchable, but the page is explicitly partial.
                            notes.append("ocr_failed_page_incomplete")
                            method = "native_partial" if native.strip() else "unreadable"
                    if not text.strip():
                        notes.append("blank_or_unreadable_page")
                except ExtractionError:
                    raise
                except Exception:
                    text, method = "", "unreadable"
                    notes.append("page_extraction_failed")
                end = offset + len(text)
                if end > MAX_CHARACTERS:
                    raise ExtractionError("Extracted text exceeds resource limit")
                pages.append({"page": index + 1, "char_start": offset, "char_end": end,
                              "method": method, "warnings": notes})
                warnings.extend({"page": index + 1, "code": note} for note in notes)
                texts.append(text)
                offset = end + 1  # physical pages separated by one form feed; code-point offsets
        canonical = "\f".join(texts)
        if not canonical.strip():
            raise ExtractionError("PDF contains no readable text")
        return {"canonical_text": canonical, "page_map": pages, "page_count": len(pages),
                "parser_version": f"pymupdf-{fitz.__version__}/bhw-generic-1", "ocr_version": ocr_version,
                "languages": languages.split("+"), "warnings": warnings}


def main():
    import sys
    if sys.stdin.buffer.read(1) != b"G":
        raise SystemExit("Worker handshake required")
    ap = argparse.ArgumentParser()
    ap.add_argument("input")
    ap.add_argument("output")
    ap.add_argument("--languages", default="eng")
    ap.add_argument("--tesseract", default="tesseract")
    args = ap.parse_args()
    # Linux hard address-space cap; Windows job-object limit is set by the parent.
    if os.name != "nt":
        import resource
        resource.setrlimit(resource.RLIMIT_AS, (1536 * 1024**2, 1536 * 1024**2))
    def progress(stage, page, count):
        print(json.dumps({"stage": stage, "progress": int(page * 90 / count)}), flush=True)
    try:
        result = extract(args.input, args.languages, args.tesseract, progress)
        Path(args.output).write_text(json.dumps(result, ensure_ascii=False), encoding="utf-8")
    except ExtractionError as exc:
        # Fixed error messages only, never PDF passages or parser stderr.
        print(json.dumps({"error": str(exc)}), flush=True)
        raise SystemExit(1)


if __name__ == "__main__":
    main()
