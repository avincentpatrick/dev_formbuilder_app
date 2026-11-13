"""Durable local PDF worker. Run --once from a scheduler, or poll until stopped.
Only administrative infrastructure may launch this process; there is no public worker API.
"""
import argparse
import hashlib
from contextlib import ExitStack
import json
import os
import re
import signal
import sys
import tempfile
import time
import uuid
from pathlib import Path

import psycopg2
from psycopg2.extras import Json, RealDictCursor
from document_process import start_child

LEASE_SECONDS = 45
ATTEMPT_SECONDS = 600
MAX_TEXT_BYTES = 64 * 1024 * 1024
KEY = re.compile(r"[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.pdf")


class LostLease(Exception):
    pass


def claim(conn, kind="extract"):
    with conn, conn.cursor(cursor_factory=RealDictCursor) as cur:
        # Exhausted abandoned leases are visible failures, never silently stuck active.
        cur.execute("""with exhausted as (
            update document_job set stage='failed',safe_error='Processing attempts exhausted',
              lease_token=null,lease_until=null,updated_at=now()
              where stage in ('extracting','ocr','indexing') and lease_until < now() and attempts >= 3
              returning version_id,extraction_id,kind)
            update document_version v set embedding_status='failed' from exhausted e
              where e.kind='embed' and v.id=e.version_id and v.current_extraction_id=e.extraction_id""")
        cur.execute("""select j.* from document_job j
            where kind=%s and ((stage='queued' and (retry_at is null or retry_at <= now()))
              or (stage in ('extracting','ocr','indexing') and lease_until < now())) and attempts < 3
            order by created_at for update skip locked limit 1""", (kind,))
        job = cur.fetchone()
        if not job:
            return None
        cur.execute("select 1 from admin_users where user_id=%s and role='admin'", (job["requested_by"],))
        if not cur.fetchone():
            cur.execute("update document_job set stage='failed',safe_error='Requesting admin is no longer authorized',lease_token=null,lease_until=null where id=%s", (job["id"],))
            return None
        token = str(uuid.uuid4())
        cur.execute("""update document_job set stage='extracting',attempts=attempts+1,progress=0,
            lease_token=%s,lease_until=now()+interval '45 seconds',heartbeat_at=now(),updated_at=now(),safe_error=null
            where id=%s returning *""", (token, job["id"]))
        return dict(cur.fetchone())


def heartbeat(conn, job, stage="extracting", progress=0):
    with conn, conn.cursor() as cur:
        cur.execute("""update document_job set stage=%s,progress=%s,heartbeat_at=now(),
            lease_until=now()+interval '45 seconds',updated_at=now()
            where id=%s and lease_token=%s and lease_until > now()
            and stage in ('extracting','ocr','indexing')""", (stage, progress, job["id"], job["lease_token"]))
        if cur.rowcount != 1:
            raise LostLease()


def fail(conn, job, message):
    with conn, conn.cursor() as cur:
        cur.execute("""update document_job set stage=case when attempts<3 then 'queued' else 'failed' end,
            retry_at=now()+interval '1 minute',safe_error=%s,lease_token=null,lease_until=null,updated_at=now()
            where id=%s and lease_token=%s and lease_until > now()""", (message, job["id"], job["lease_token"]))
        if cur.rowcount and job.get("kind") == "embed":
            cur.execute("update document_version set embedding_status='failed' where id=%s and current_extraction_id=%s",
                        (job["version_id"], job["extraction_id"]))


def validate(result):
    text, pages = result["canonical_text"], result["page_map"]
    if not text.strip() or len(text) > 10_000_000 or "\x00" in text or not 1 <= len(pages) <= 300 or result["page_count"] != len(pages):
        raise ValueError("Invalid extraction")
    offset = 0
    for number, page in enumerate(pages, 1):
        start, end = page["char_start"], page["char_end"]
        if page["page"] != number or start != offset or not start <= end <= len(text):
            raise ValueError("Invalid page offsets")
        if number < len(pages) and text[end:end+1] != "\f":
            raise ValueError("Invalid page separator")
        offset = end + 1
    if offset != len(text) + 1:
        raise ValueError("Incomplete page map")


def publish(conn, job, result):
    validate(result)
    with conn, conn.cursor(cursor_factory=RealDictCursor) as cur:
        cur.execute("select * from document_job where id=%s and lease_token=%s and lease_until>now() for update", (job["id"], job["lease_token"]))
        if not cur.fetchone():
            raise LostLease()
        cur.execute("select warnings from document_extraction where job_id=%s", (job["id"],))
        existing = cur.fetchone()
        if existing:
            cur.execute("update document_job set stage=%s,progress=100,lease_token=null,lease_until=null where id=%s",
                        ("ready_with_warnings" if existing["warnings"] else "ready", job["id"]))
            return  # unique job_id is also a database backstop
        cur.execute("""select v.*,c.title,c.issuer,f.storage_key from document_version v
            join document_catalog c on c.id=v.document_id join document_file f on f.sha256=v.file_sha256
            where v.id=%s for update of v""", (job["version_id"],))
        version = cur.fetchone()
        extraction = str(uuid.uuid4())
        text = result["canonical_text"]
        cur.execute("""insert into doc_source(key,title,source_path,source_sha256,page_count,char_count,
            issuer,as_of,extractor,status) values(%s,%s,%s,%s,%s,%s,%s,%s,%s,'approved') returning doc_id""",
                    (f"upload:{extraction}", version["title"], f"documents/{version['storage_key']}",
                     version["file_sha256"], result["page_count"], len(text), version["issuer"],
                     version["publication_date"], result["parser_version"]))
        source = cur.fetchone()["doc_id"]
        cur.execute("select coalesce(max(revision),0)+1 as n from document_extraction where version_id=%s", (version["id"],))
        revision = cur.fetchone()["n"]
        cur.execute("""insert into document_extraction(id,version_id,revision,canonical_text,page_map,page_count,
            parser_version,ocr_version,languages,warnings,source_id,activation_origin,job_id)
            values(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'successful_upload',%s)""",
                    (extraction, version["id"], revision, text, Json(result["page_map"]), result["page_count"],
                     result["parser_version"], result["ocr_version"], result["languages"], Json(result["warnings"]), source, job["id"]))
        chunk_index = 0
        for page in result["page_map"]:
            # Bounded passages, not one entire slide/page. Offsets count Unicode code points.
            for start in range(page["char_start"], page["char_end"], 2400):
                end = min(start + 2400, page["char_end"])
                content = text[start:end]
                if not content.strip():
                    continue
                cur.execute("""insert into doc_chunk(doc_id,chunk_index,page_from,page_to,char_start,char_end,content,content_sha256)
                    values(%s,%s,%s,%s,%s,%s,%s,%s)""", (source, chunk_index, page["page"], page["page"], start, end,
                     content, hashlib.sha256(content.encode("utf-8")).hexdigest()))
                chunk_index += 1
        # Old sources remain addressable by chunk ID, but only the current revision is searchable.
        cur.execute("""update doc_source set status='rejected' where doc_id in
            (select source_id from document_extraction where version_id=%s and id<>%s)""", (version["id"], extraction))
        # Embedding is separately claimable and never runs in the PDF transaction.
        cur.execute("insert into document_job(version_id,requested_by,kind,extraction_id) values(%s,%s,'embed',%s)",
                    (version["id"], job["requested_by"], extraction))
        warned = bool(result["warnings"])
        cur.execute("""update document_version set current_extraction_id=%s,availability=%s,embedding_status='pending' where id=%s""",
                    (extraction, "available_with_warnings" if warned else "available", version["id"]))
        if version["archived"]:
            cur.execute("update doc_source set status='rejected' where doc_id=%s", (source,))
        cur.execute("""update document_job set stage=%s,progress=100,lease_token=null,lease_until=null,
            safe_error=null,updated_at=now() where id=%s""", ("ready_with_warnings" if warned else "ready", job["id"]))
        cur.execute("insert into document_audit_event(actor_id,version_id,event,details) values(%s,%s,'extraction_completed',%s)",
                    (job["requested_by"], version["id"], Json({"extraction_id": extraction, "warnings": len(result["warnings"])})))


def process(conn, job, storage, languages="eng", tesseract="tesseract"):
    stop = None
    try:
        with conn, conn.cursor(cursor_factory=RealDictCursor) as cur:
            cur.execute("""select f.* from document_file f join document_version v on v.file_sha256=f.sha256 where v.id=%s""", (job["version_id"],))
            original = cur.fetchone()
        if not original or not KEY.fullmatch(original["storage_key"]):
            raise ValueError("Invalid original")
        folder = (Path(storage) / "documents").resolve()
        path = (folder / original["storage_key"]).resolve()
        if path.parent != folder or not 0 < path.stat().st_size <= 25 * 1024**2:
            raise ValueError("Invalid original")
        data = path.read_bytes()
        if len(data) != original["byte_count"] or hashlib.sha256(data).hexdigest() != original["sha256"]:
            raise ValueError("Original content mismatch")
        with tempfile.TemporaryDirectory(prefix="bhw-extract-") as temp:
            root = Path(temp)
            # Immutable private snapshot avoids any path/file race during parsing.
            pdf, output, progress_file = root / "original.pdf", root / "text.json", root / "progress.jsonl"
            pdf.write_bytes(data)
            del data
            command = [sys.executable, str(Path(__file__).with_name("document_extract.py")), str(pdf), str(output),
                       "--languages", languages, "--tesseract", tesseract]
            with progress_file.open("w", encoding="utf-8") as log, ExitStack() as children:
                child, stop = start_child(command, log)
                children.callback(stop)
                started, last_heartbeat = time.monotonic(), 0
                while child.poll() is None:
                    now = time.monotonic()
                    if now - started > ATTEMPT_SECONDS:
                        raise TimeoutError()
                    if now - last_heartbeat >= 10:
                        lines = progress_file.read_text(encoding="utf-8").splitlines()
                        latest = {}
                        for line in reversed(lines):
                            try:
                                latest = json.loads(line)
                                break
                            except json.JSONDecodeError:
                                continue
                        stage = latest.get("stage", "extracting")
                        if stage not in ("extracting", "ocr"):
                            stage = "extracting"
                        heartbeat(conn, job, stage, min(90, max(0, int(latest.get("progress", 0)))))
                        last_heartbeat = now
                    time.sleep(.2)
                if child.returncode != 0:
                    # No parser stderr or document passages in job errors.
                    raise ValueError("Local extraction failed; PDF may be empty, encrypted, corrupt or OCR unavailable")
                if output.stat().st_size > MAX_TEXT_BYTES:
                    raise ValueError("Extracted text exceeds resource limit")
                result = json.loads(output.read_text(encoding="utf-8"))
                heartbeat(conn, job, "indexing", 95)
                publish(conn, job, result)
    except LostLease:
        return False
    except TimeoutError:
        fail(conn, job, "Extraction exceeded 600 seconds")
        return False
    except Exception:
        fail(conn, job, "Local extraction failed; check PDF integrity, limits and OCR installation")
        return False
    finally:
        if stop:
            stop()
    return True


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--once", action="store_true")
    ap.add_argument("--languages", default="eng")
    ap.add_argument("--embed", action="store_true", help="Only process separate embedding jobs; consumes provider tokens")
    ap.add_argument("--tesseract", default="tesseract")
    args = ap.parse_args()
    database, storage = os.environ.get("BHW_WORKER_DATABASE_URL"), os.environ.get("BHW_STORAGE_DIR")
    if not database or not storage or not Path(storage).is_absolute():
        ap.error("Set BHW_WORKER_DATABASE_URL and an absolute persistent BHW_STORAGE_DIR")
    def shutdown(*_):
        raise KeyboardInterrupt()
    signal.signal(signal.SIGTERM, shutdown)
    if hasattr(signal, "SIGBREAK"):
        signal.signal(signal.SIGBREAK, shutdown)
    conn = psycopg2.connect(database, connect_timeout=10)
    try:
        with conn, conn.cursor() as cur:
            cur.execute("set statement_timeout='15s'")
        while True:
            job = claim(conn, "embed" if args.embed else "extract")
            if job:
                if args.embed:
                    from document_embed import process_embedding
                    outcome = process_embedding(conn, job)
                else:
                    outcome = process(conn, job, storage, args.languages, args.tesseract)
                print(json.dumps({"job": job["id"], "ok": outcome}), flush=True)
            if args.once:
                break
            if not job:
                time.sleep(2)
    except KeyboardInterrupt:
        pass  # finally blocks stop parser/OCR; unfinished lease becomes reclaimable
    finally:
        conn.close()


if __name__ == "__main__":
    main()
