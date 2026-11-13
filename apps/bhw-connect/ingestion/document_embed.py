"""Separate optional embedding stage. PDF/OCR never imports or invokes providers."""
from contextlib import ExitStack
import json
import math
import os
import sys
import tempfile
import time
from pathlib import Path

from document_process import start_child


def process_embedding(conn, job):
    from document_worker import LostLease, heartbeat, fail
    stop = None
    try:
        from psycopg2.extras import RealDictCursor
        with conn, conn.cursor(cursor_factory=RealDictCursor) as cur:
            cur.execute("select source_id from document_extraction where id=%s and version_id=%s", (job["extraction_id"], job["version_id"]))
            source = cur.fetchone()["source_id"]
            cur.execute("select chunk_index,page_from,content from doc_chunk where doc_id=%s order by chunk_index", (source,))
            chunks = [dict(row) for row in cur.fetchall()]
            cur.execute("update document_version set embedding_status='running' where id=%s and current_extraction_id=%s", (job["version_id"], job["extraction_id"]))
        with tempfile.TemporaryDirectory(prefix="bhw-embed-") as temp:
            root = Path(temp)
            incoming, outgoing = root / "chunks.json", root / "vectors.json"
            incoming.write_text(json.dumps(chunks), encoding="utf-8")
            with (root / "status.log").open("w") as log, ExitStack() as children:
                child, stop = start_child([sys.executable, str(Path(__file__)), str(incoming), str(outgoing)], log, embedding=True)
                children.callback(stop)
                started, last = time.monotonic(), 0
                while child.poll() is None:
                    now = time.monotonic()
                    if now - started > 600:
                        raise TimeoutError()
                    if now - last >= 10:
                        heartbeat(conn, job, "indexing", 50)
                        last = now
                    time.sleep(.2)
                if child.returncode or outgoing.stat().st_size > 128 * 1024**2:
                    raise ValueError("Embedding failed")
                result = json.loads(outgoing.read_text(encoding="utf-8"))
        heartbeat(conn, job, "indexing", 95)
        with conn, conn.cursor() as cur:
            cur.execute("select 1 from document_job where id=%s and lease_token=%s and lease_until>now() for update", (job["id"], job["lease_token"]))
            if not cur.fetchone():
                raise LostLease()
            cur.execute("insert into doc_embedding_model(model,provider,dim,distance) values(%s,%s,%s,%s) on conflict(model) do nothing", (result["model"], result["provider"], result["dim"], result["distance"]))
            cur.execute("select dim from doc_embedding_model where model=%s for share", (result["model"],))
            if cur.fetchone()[0] != result["dim"]:
                raise ValueError("Embedding model dimension changed")
            expected = {str(c["chunk_index"]) for c in chunks}
            if set(result["vectors"]) != expected:
                raise ValueError("Incomplete embedding batch")
            for index, vector in result["vectors"].items():
                if len(vector) != result["dim"] or not all(math.isfinite(v) for v in vector):
                    raise ValueError("Invalid embedding vector")
                cur.execute("""insert into doc_chunk_embedding(chunk_id,model,dim,embedding)
                    select chunk_id,%s,%s,%s::extensions.vector from doc_chunk where doc_id=%s and chunk_index=%s
                    on conflict(chunk_id,model) do nothing""", (result["model"], result["dim"], json.dumps(vector), source, int(index)))
            cur.execute("update document_version set embedding_status='ready' where id=%s and current_extraction_id=%s", (job["version_id"], job["extraction_id"]))
            cur.execute("update document_job set stage='ready',progress=100,lease_token=null,lease_until=null,safe_error=null,updated_at=now() where id=%s", (job["id"],))
        return True
    except LostLease:
        return False
    except Exception:
        fail(conn, job, "Embedding failed; keyword search remains available")
        return False
    finally:
        if stop:
            stop()


if __name__ == "__main__":
    if sys.stdin.buffer.read(1) != b"G":
        raise SystemExit(1)
    if os.name != "nt":
        import resource
        resource.setrlimit(resource.RLIMIT_AS, (1536 * 1024**2, 1536 * 1024**2))
    from ingest_documents import embed_chunks
    payload = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
    embedded = embed_chunks(payload)
    Path(sys.argv[2]).write_text(json.dumps(embedded), encoding="utf-8")
