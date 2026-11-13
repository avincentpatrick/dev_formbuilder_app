"""Real disposable-DB worker tests. All fixtures synthetic and removed afterward."""
import hashlib
import json
from types import SimpleNamespace
import os
import tempfile
import subprocess
import sys
import time
import unittest
import uuid
from pathlib import Path
from unittest.mock import patch
from urllib.parse import urlparse

import fitz
import psycopg2
from document_worker import claim, heartbeat, process, publish, LostLease
from document_embed import process_embedding

DATABASE = os.environ.get("BHW_TEST_DATABASE_URL")


@unittest.skipUnless(DATABASE, "Set BHW_TEST_DATABASE_URL to the local disposable development database")
class WorkerTests(unittest.TestCase):
    def setUp(self):
        if urlparse(DATABASE).hostname not in ("127.0.0.1", "localhost"):
            self.skipTest("Worker fixtures require a loopback-only disposable database")
        self.conn = psycopg2.connect(DATABASE)
        self.temp = tempfile.TemporaryDirectory()
        self.actor, self.catalog, self.version = [str(uuid.uuid4()) for _ in range(3)]
        self.key = f"{uuid.uuid4()}.pdf"
        folder = Path(self.temp.name) / "documents"
        folder.mkdir()
        doc = fitz.open()
        doc.new_page().insert_text((72,72), "Synthetic café policy: preserve all referral records for review.")
        data = doc.tobytes()
        self.hash = hashlib.sha256(data).hexdigest()
        (folder/self.key).write_bytes(data)
        with self.conn, self.conn.cursor() as cur:
            cur.execute("insert into admin_users values(%s,'admin')", (self.actor,))
            cur.execute("insert into document_catalog(id,title,created_by) values(%s,'Synthetic worker policy',%s)", (self.catalog,self.actor))
            cur.execute("insert into document_file(sha256,storage_key,byte_count) values(%s,%s,%s)", (self.hash,self.key,len(data)))
            cur.execute("insert into document_version(id,document_id,file_sha256,uploaded_by) values(%s,%s,%s,%s)", (self.version,self.catalog,self.hash,self.actor))
        self.enqueue()

    def enqueue(self):
        with self.conn, self.conn.cursor() as cur:
            cur.execute("select enqueue_document_job(%s,%s)", (self.version,self.actor))
            return cur.fetchone()[0]

    def tearDown(self):
        with self.conn, self.conn.cursor() as cur:
            cur.execute("select source_id from document_extraction where version_id=%s", (self.version,))
            sources = [r[0] for r in cur.fetchall()]
            cur.execute("update document_version set current_extraction_id=null where id=%s", (self.version,))
            cur.execute("delete from document_job where version_id=%s and kind='embed'", (self.version,))
            cur.execute("update document_extraction set job_id=null where version_id=%s", (self.version,))
            cur.execute("delete from document_job where version_id=%s", (self.version,))
            cur.execute("delete from document_extraction where version_id=%s", (self.version,))
            cur.execute("delete from document_audit_event where version_id=%s", (self.version,))
            cur.execute("delete from document_version where id=%s", (self.version,))
            cur.execute("delete from document_file where sha256=%s", (self.hash,))
            cur.execute("delete from document_catalog where id=%s", (self.catalog,))
            cur.execute("delete from doc_chunk where doc_id=any(%s)", (sources,))
            cur.execute("delete from doc_source where doc_id=any(%s)", (sources,))
            cur.execute("delete from doc_embedding_model where model=%s", (f"synthetic-{self.actor}",))
            cur.execute("delete from admin_users where user_id=%s", (self.actor,))
        self.conn.close()
        self.temp.cleanup()

    def state(self):
        with self.conn, self.conn.cursor() as cur:
            cur.execute("select availability,embedding_status from document_version where id=%s", (self.version,))
            return cur.fetchone()

    def test_end_to_end_offsets_keyword_search_embedding_failure_and_reprocess(self):
        job = claim(self.conn)
        self.assertTrue(process(self.conn, job, self.temp.name))
        self.assertEqual(self.state(), ("available", "pending"))
        with self.conn, self.conn.cursor() as cur:
            cur.execute("""select c.content,c.char_start,c.char_end,e.canonical_text,c.chunk_id
                from doc_chunk c join document_extraction e on e.source_id=c.doc_id where e.version_id=%s""", (self.version,))
            content, start, end, text, old_chunk = cur.fetchone()
            self.assertIn("café", content)
            self.assertEqual(content, text[start:end])
            cur.execute("select count(*) from doc_chunk where content ilike '%referral%'")
            self.assertGreater(cur.fetchone()[0],0)
            # Search through the same RPC as the assistant, without any vectors/provider.
            cur.execute("select count(*) from search_documents(p_query=>'referral',p_doc_key=>%s)", (f"upload:{self.current_extraction()}",))
            self.assertGreater(cur.fetchone()[0],0)
        embedding = claim(self.conn, "embed")
        with patch.dict(os.environ, {"GEMINI_API_KEY":"", "GEMINI_EMBEDDING_MODEL":""}):
            self.assertFalse(process_embedding(self.conn, embedding))
        self.assertEqual(self.state(), ("available", "failed"))
        self.enqueue()
        self.assertTrue(process(self.conn, claim(self.conn), self.temp.name))
        with self.conn, self.conn.cursor() as cur:
            cur.execute("select count(*) from document_extraction where version_id=%s", (self.version,))
            self.assertEqual(cur.fetchone()[0],2)
            cur.execute("select content from doc_chunk where chunk_id=%s", (old_chunk,))
            self.assertEqual(cur.fetchone()[0],content)
            cur.execute("select count(*) from doc_source s join document_extraction e on e.source_id=s.doc_id where e.version_id=%s and s.status='approved'", (self.version,))
            self.assertEqual(cur.fetchone()[0],1)

    def current_extraction(self):
        with self.conn.cursor() as cur:
            cur.execute("select current_extraction_id from document_version where id=%s", (self.version,))
            return cur.fetchone()[0]

    def test_astral_unicode_offsets_round_trip_in_postgresql(self):
        text = "Synthetic café 🌿 policy"
        job = claim(self.conn)
        publish(self.conn, job, {"canonical_text": text, "page_count": 1,
            "page_map": [{"page": 1, "char_start": 0, "char_end": len(text), "method": "native", "warnings": []}],
            "parser_version": "synthetic-test", "ocr_version": None, "languages": [], "warnings": []})
        with self.conn, self.conn.cursor() as cur:
            cur.execute("select c.content,c.char_end-c.char_start,length(c.content) from doc_chunk c join document_extraction e on e.source_id=c.doc_id where e.version_id=%s", (self.version,))
            self.assertEqual(cur.fetchone(), (text,len(text),len(text)))

    def test_embedding_registry_uses_measured_dimensions_without_provider_calls(self):
        self.assertTrue(process(self.conn, claim(self.conn), self.temp.name))
        job = claim(self.conn, "embed")
        def fake_provider_child(command, log, embedding=False):
            chunks = json.loads(Path(command[2]).read_text(encoding="utf-8"))
            Path(command[3]).write_text(json.dumps({"model": f"synthetic-{self.actor}", "provider": "gemini",
                "dim": 3, "distance": "cosine", "vectors": {str(c["chunk_index"]): [1.,0.,0.] for c in chunks}}), encoding="utf-8")
            return SimpleNamespace(poll=lambda:0, returncode=0), lambda:None
        with patch("document_embed.start_child", fake_provider_child):
            self.assertTrue(process_embedding(self.conn, job))
        self.assertEqual(self.state(), ("available", "ready"))
        with self.conn, self.conn.cursor() as cur:
            cur.execute("select dim from doc_embedding_model where model=%s", (f"synthetic-{self.actor}",))
            self.assertEqual(cur.fetchone()[0],3)

    def test_lease_recovery_and_stale_writer_cannot_publish(self):
        self.assertEqual(self.enqueue(), self.enqueue())
        first = claim(self.conn)
        self.assertIsNone(claim(self.conn))  # no double claim
        with self.conn, self.conn.cursor() as cur:
            cur.execute("update document_job set lease_until=now()-interval '1 second' where id=%s", (first["id"],))
        second = claim(self.conn)
        self.assertEqual(second["attempts"],2)
        self.assertNotEqual(first["lease_token"],second["lease_token"])
        with self.assertRaises(LostLease):
            heartbeat(self.conn, first)
        self.assertTrue(process(self.conn, second, self.temp.name))
        self.assertFalse(process(self.conn, first, self.temp.name))
        self.assertEqual(self.state()[0],"available")

    def test_failures_retry_three_times_and_never_activate(self):
        (Path(self.temp.name)/"documents"/self.key).write_bytes(b"corrupt")
        for attempt in range(3):
            job=claim(self.conn)
            self.assertFalse(process(self.conn,job,self.temp.name))
            with self.conn, self.conn.cursor() as cur:
                cur.execute("update document_job set retry_at=now() where id=%s",(job["id"],))
        self.assertIsNone(claim(self.conn))
        self.assertEqual(self.state(),("unavailable","pending"))
        with self.conn, self.conn.cursor() as cur:
            cur.execute("select stage,attempts from document_job where version_id=%s",(self.version,))
            self.assertEqual(cur.fetchone(),('failed',3))

    def test_timeout_stops_child_and_cleans_temporary_files(self):
        job = claim(self.conn)
        with patch("document_worker.ATTEMPT_SECONDS", 0), patch("tempfile.tempdir", self.temp.name):
            self.assertFalse(process(self.conn, job, self.temp.name))
        self.assertEqual(list(Path(self.temp.name).glob("bhw-extract-*")), [])
        self.assertEqual(self.state()[0], "unavailable")

    def test_locked_job_is_skipped_by_a_second_worker(self):
        other = psycopg2.connect(DATABASE)
        try:
            with self.conn.cursor() as cur:
                cur.execute("select id from document_job where version_id=%s for update", (self.version,))
            self.assertIsNone(claim(other))
            self.conn.rollback()
            self.assertIsNotNone(claim(other))
        finally:
            self.conn.rollback()
            other.close()

    def test_worker_sigterm_leaves_recoverable_lease(self):
        # Exercise the actual CLI process lifecycle against the real queue.
        env = {**os.environ, "BHW_WORKER_DATABASE_URL": DATABASE, "BHW_STORAGE_DIR": self.temp.name}
        child = subprocess.Popen([sys.executable, str(Path(__file__).with_name("document_worker.py"))],
                                 env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            deadline = time.monotonic() + 10
            stage = None
            while time.monotonic() < deadline:
                with self.conn, self.conn.cursor() as cur:
                    cur.execute("select stage from document_job where version_id=%s and kind='extract'", (self.version,))
                    stage = cur.fetchone()[0]
                if stage != "queued":
                    break
                time.sleep(.02)
            self.assertNotEqual(stage, "queued")
            child.terminate()
            child.wait(timeout=10)
            # If already complete, restarting cannot duplicate chunks. Otherwise recover the lease.
            if self.state()[0] == "unavailable":
                with self.conn, self.conn.cursor() as cur:
                    cur.execute("update document_job set lease_until=now()-interval '1 second' where version_id=%s", (self.version,))
                self.assertTrue(process(self.conn, claim(self.conn), self.temp.name))
            self.assertEqual(self.state()[0], "available")
        finally:
            if child.poll() is None:
                child.kill()
                child.wait()

    def test_editor_cannot_enqueue_and_revoked_admin_cannot_run(self):
        with self.conn, self.conn.cursor() as cur:
            cur.execute("update admin_users set role='editor' where user_id=%s",(self.actor,))
        with self.assertRaises(psycopg2.errors.InsufficientPrivilege):
            self.enqueue()
        self.assertIsNone(claim(self.conn))
        self.assertEqual(self.state()[0],"unavailable")

    def test_expired_final_attempt_is_terminal(self):
        job=claim(self.conn)
        with self.conn, self.conn.cursor() as cur:
            cur.execute("update document_job set attempts=3,lease_until=now()-interval '1 second' where id=%s",(job['id'],))
        self.assertIsNone(claim(self.conn))
        with self.conn, self.conn.cursor() as cur:
            cur.execute("select stage from document_job where id=%s",(job['id'],))
            self.assertEqual(cur.fetchone()[0],"failed")


if __name__ == "__main__":
    unittest.main()
