"""Bounded subprocess tree for PDF parsing (POSIX process group / Windows Job Object)."""
import ctypes
import os
import signal
import subprocess


def windows_job(process):
    from ctypes import wintypes as w
    class Basic(ctypes.Structure):
        _fields_ = [("ProcessTime", ctypes.c_int64), ("JobTime", ctypes.c_int64), ("Flags", w.DWORD),
                    ("MinWorkingSet", ctypes.c_size_t), ("MaxWorkingSet", ctypes.c_size_t),
                    ("ActiveProcessLimit", w.DWORD), ("Affinity", ctypes.c_size_t),
                    ("PriorityClass", w.DWORD), ("SchedulingClass", w.DWORD)]
    class IO(ctypes.Structure):
        _fields_ = [(name, ctypes.c_uint64) for name in ["ReadOps", "WriteOps", "OtherOps", "ReadBytes", "WriteBytes", "OtherBytes"]]
    class Extended(ctypes.Structure):
        _fields_ = [("Basic", Basic), ("IO", IO), ("ProcessMemory", ctypes.c_size_t),
                    ("JobMemory", ctypes.c_size_t), ("PeakProcessMemory", ctypes.c_size_t), ("PeakJobMemory", ctypes.c_size_t)]
    kernel = ctypes.WinDLL("kernel32", use_last_error=True)
    kernel.CreateJobObjectW.argtypes = [ctypes.c_void_p, w.LPCWSTR]
    kernel.CreateJobObjectW.restype = w.HANDLE
    kernel.SetInformationJobObject.argtypes = [w.HANDLE, ctypes.c_int, ctypes.c_void_p, w.DWORD]
    kernel.AssignProcessToJobObject.argtypes = [w.HANDLE, w.HANDLE]
    kernel.CloseHandle.argtypes = [w.HANDLE]
    handle = kernel.CreateJobObjectW(None, None)
    if not handle:
        raise OSError("Cannot create worker process budget")
    info = Extended()
    # KILL_ON_JOB_CLOSE | JOB_MEMORY | ACTIVE_PROCESS, inherited by OCR descendants.
    info.Basic.Flags = 0x2000 | 0x200 | 0x8
    info.Basic.ActiveProcessLimit = 4
    info.JobMemory = 1536 * 1024**2
    if not kernel.SetInformationJobObject(handle, 9, ctypes.byref(info), ctypes.sizeof(info)) or not kernel.AssignProcessToJobObject(handle, w.HANDLE(int(process._handle))):
        kernel.CloseHandle(handle)
        raise OSError("Cannot enforce worker process budget")
    return lambda: kernel.CloseHandle(handle)


def start_child(command, stdout, embedding=False):
    # No DB or provider credentials reach the PDF parser/OCR subprocess.
    allowed = {"PATH", "SYSTEMROOT", "WINDIR", "TEMP", "TMP", "HOME", "LANG", "LC_ALL", "TESSDATA_PREFIX"}
    if embedding:
        allowed |= {"GEMINI_API_KEY", "GEMINI_EMBEDDING_MODEL", "GEMINI_EMBEDDING_DIM",
                    "HTTP_PROXY", "HTTPS_PROXY", "NO_PROXY", "http_proxy", "https_proxy", "no_proxy",
                    "SSL_CERT_FILE", "SSL_CERT_DIR"}
    env = {key: value for key, value in os.environ.items() if key.upper() in allowed}
    env["OMP_THREAD_LIMIT"] = "1"
    process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=stdout, stderr=subprocess.DEVNULL,
                               env=env, start_new_session=os.name != "nt")
    close_job = None
    try:
        if os.name == "nt":
            close_job = windows_job(process)
        # Child waits before importing parser or reading PDF until the OS budget is attached.
        process.stdin.write(b"G")
        process.stdin.close()
    except Exception:
        process.kill()
        process.wait()
        if close_job:
            close_job()
        raise
    closed = False
    def stop():
        nonlocal closed
        if closed:
            return
        closed = True
        if close_job:
            close_job()
        else:
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
        process.wait()
    return process, stop
