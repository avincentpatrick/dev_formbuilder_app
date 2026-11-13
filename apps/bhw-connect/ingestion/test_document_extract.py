"""Synthetic PDF acceptance cases; no provider, DB or real policy files."""
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import fitz
from document_extract import extract, ExtractionError
from document_worker import validate


class ExtractionTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.path = Path(self.temp.name) / "synthetic.pdf"

    def tearDown(self):
        self.temp.cleanup()

    def save(self, doc, **kwargs):
        doc.save(self.path, **kwargs)
        doc.close()

    def scan(self):
        template = fitz.open()
        page = template.new_page()
        page.insert_text((72, 100), "SYNTHETIC SCANNED POLICY", fontsize=20)
        page.insert_text((72, 150), "Every clinic must retain a signed referral record.", fontsize=16)
        return page.get_pixmap(dpi=200).tobytes("png")

    def test_native_unicode_offsets_and_no_provider(self):
        doc = fitz.open()
        for text in ["Synthetic policy: café preserves the referral register. " * 4,
                     "Second physical page: policy text must remain complete. " * 3]:
            page = doc.new_page()
            page.insert_text((72, 72), text)
        self.save(doc)
        with patch("urllib.request.urlopen", side_effect=AssertionError("No provider allowed")):
            result = extract(self.path)
        validate(result)
        self.assertIn("café", result["canonical_text"])
        self.assertEqual(result["page_count"], 2)
        for page in result["page_map"]:
            self.assertTrue(result["canonical_text"][page["char_start"]:page["char_end"]])

    def test_scan_and_same_page_mixed_and_rotated(self):
        doc = fitz.open()
        image = self.scan()
        for index in range(3):
            page = doc.new_page()
            page.insert_image(page.rect, stream=image)
            if index == 1:
                page.insert_text((72, 40), "SELECTABLE HEADER", fontsize=16)
            if index == 2:
                page.set_rotation(90)
        self.save(doc)
        result = extract(self.path)
        validate(result)
        self.assertEqual(result["page_map"][1]["method"], "native+ocr")
        for page in result["page_map"]:
            text = result["canonical_text"][page["char_start"]:page["char_end"]].lower()
            # A rotation may defeat OCR, but it must then be explicitly marked limited.
            self.assertTrue("referral record" in text or page["warnings"])
        self.assertIn("referral record", result["canonical_text"].lower())

    def test_partial_blank_page_is_flagged_and_wholly_blank_fails(self):
        doc = fitz.open()
        doc.new_page().insert_text((72, 72), "Readable synthetic policy source with enough text for native extraction.")
        doc.new_page()
        self.save(doc)
        result = extract(self.path)
        self.assertIn("blank_or_unreadable_page", result["page_map"][1]["warnings"])
        self.path.unlink()
        doc = fitz.open()
        doc.new_page()
        self.save(doc)
        with self.assertRaisesRegex(ExtractionError, "no readable text"):
            extract(self.path)

    def test_encrypted_corrupt_and_page_budget(self):
        self.path.write_bytes(b"%PDF- corrupt")
        with self.assertRaises(ExtractionError):
            extract(self.path)
        self.path.unlink()
        doc = fitz.open()
        doc.new_page()
        self.save(doc, encryption=fitz.PDF_ENCRYPT_AES_256, owner_pw="owner", user_pw="secret")
        with self.assertRaisesRegex(ExtractionError, "Encrypted"):
            extract(self.path)
        self.path.unlink()
        doc = fitz.open()
        for _ in range(301):
            doc.new_page()
        self.save(doc)
        with self.assertRaisesRegex(ExtractionError, "300 pages"):
            extract(self.path)

    def test_table_layout_flag_and_missing_language(self):
        doc = fitz.open()
        page = doc.new_page()
        page.insert_text((72, 72), "Synthetic table, values are layout evidence only. " * 2)
        for y in range(100, 220, 20):
            page.draw_line((72, y), (400, y))
        self.save(doc)
        result = extract(self.path)
        self.assertIn("layout_or_table_unverified", result["page_map"][0]["warnings"])
        self.path.unlink()
        doc = fitz.open()
        page = doc.new_page()
        page.insert_image(page.rect, stream=self.scan())
        self.save(doc)
        with self.assertRaisesRegex(ExtractionError, "language data"):
            extract(self.path, languages="zzz")

    def test_offset_corruption_is_rejected(self):
        with self.assertRaises(ValueError):
            validate({"canonical_text":"café", "page_count":1,
                      "page_map":[{"page":1,"char_start":0,"char_end":5}]})


if __name__ == "__main__":
    unittest.main()
