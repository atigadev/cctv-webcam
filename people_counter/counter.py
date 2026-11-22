"""
GIIAS People Counter - worker penghitung orang dari kamera Agent DVR.

Cara kerja:
  1. Baca daftar Hall dari data/config.json. Hall yang mengaktifkan "hitung orang"
     (field peopleCount.enabled) diambil URL DVR-nya dari iframeUrl.
  2. Tiap interval, ambil snapshot JPEG tiap kamera (Agent DVR: /grab.jpg?oid=N).
  3. Deteksi orang dengan model YOLO (ONNX, CPU) lalu hitung.
  4. Tulis hasil ke data/people_count.json, riwayat per menit ke
     data/people_history.json, dan snapshot berkotak ke data/people/.

Jalankan:  .venv\\Scripts\\python.exe counter.py          (loop terus)
           .venv\\Scripts\\python.exe counter.py --once   (sekali, untuk uji)
           .venv\\Scripts\\python.exe counter.py --test gambar.jpg
"""
from __future__ import annotations

import argparse
import json
import logging
import os
import re
import signal
import statistics
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from collections import deque
from concurrent.futures import ThreadPoolExecutor
from logging.handlers import RotatingFileHandler
from pathlib import Path

import cv2
import numpy as np
import onnxruntime as ort

BASE_DIR = Path(__file__).resolve().parent
ROOT_DIR = BASE_DIR.parent
DATA_DIR = ROOT_DIR / "data"
SNAP_DIR = DATA_DIR / "people"
CONFIG_FILE = DATA_DIR / "config.json"
OUT_FILE = DATA_DIR / "people_count.json"
HISTORY_FILE = DATA_DIR / "people_history.json"
SETTINGS_FILE = BASE_DIR / "settings.json"

DEFAULT_SETTINGS = {
    "model": "models/yolo11s_1920.onnx",
    "interval": 5,            # detik antar putaran (minimal; tiap kamera ±3 dtk di CPU)
    "confidence": 0.30,       # ambang skor deteksi orang
    "iou": 0.50,              # ambang NMS
    "smoothing": 3,           # median dari N hitungan terakhir (redam kedip)
    "snapshot_width": 1920,   # resolusi snapshot yang diminta ke DVR
    "fetch_timeout": 8,
    "camera_list_ttl": 60,    # detik cache daftar kamera DVR
    "history_hours": 24,
    "threads": 0,             # 0 = otomatis
    "save_snapshots": True,
}

log = logging.getLogger("people")


# --------------------------------------------------------------------------- util

def load_settings() -> dict:
    s = dict(DEFAULT_SETTINGS)
    if SETTINGS_FILE.exists():
        try:
            s.update(json.loads(SETTINGS_FILE.read_text(encoding="utf-8")))
        except Exception as exc:  # noqa: BLE001
            log.warning("settings.json tidak valid, pakai default: %s", exc)
    return s


def write_json_atomic(path: Path, data) -> None:
    """Tulis ke file sementara lalu ganti; ulangi jika file sedang dibuka PHP."""
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(json.dumps(data, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    for _ in range(20):
        try:
            os.replace(tmp, path)
            return
        except PermissionError:
            time.sleep(0.05)
    log.warning("Gagal mengganti %s (terkunci)", path.name)


def write_bytes_atomic(path: Path, data: bytes) -> None:
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_bytes(data)
    for _ in range(20):
        try:
            os.replace(tmp, path)
            return
        except PermissionError:
            time.sleep(0.05)


def dvr_base(url: str) -> str | None:
    """http://192.168.1.50:8090/?viewIndex=1#Live -> http://192.168.1.50:8090"""
    try:
        p = urllib.parse.urlsplit(url.strip())
    except ValueError:
        return None
    if p.scheme not in ("http", "https") or not p.hostname:
        return None
    return f"{p.scheme}://{p.netloc}"


def http_get(url: str, timeout: float) -> bytes:
    req = urllib.request.Request(url, headers={"User-Agent": "giias-people-counter/1.0"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return resp.read()


def parse_cam_ids(value) -> list[int]:
    """'1, 2' -> [1, 2]; kosong -> [] (= semua kamera)."""
    if isinstance(value, list):
        items = value
    else:
        items = re.split(r"[\s,;]+", str(value or ""))
    out = []
    for it in items:
        try:
            n = int(str(it).strip())
        except ValueError:
            continue
        if n > 0 and n not in out:
            out.append(n)
    return out


# --------------------------------------------------------------------------- detector

class PersonDetector:
    def __init__(self, model_path: Path, conf: float, iou: float, threads: int = 0):
        opts = ort.SessionOptions()
        if threads:
            opts.intra_op_num_threads = threads
        opts.graph_optimization_level = ort.GraphOptimizationLevel.ORT_ENABLE_ALL
        self.session = ort.InferenceSession(str(model_path), opts, providers=["CPUExecutionProvider"])
        inp = self.session.get_inputs()[0]
        self.input_name = inp.name
        self.size = int(inp.shape[2]) if isinstance(inp.shape[2], int) else 960
        self.conf = conf
        self.iou = iou
        self.lock = threading.Lock()

    def detect(self, img: np.ndarray) -> list[tuple[int, int, int, int, float]]:
        h, w = img.shape[:2]
        s = self.size
        r = min(s / w, s / h)
        nw, nh = int(round(w * r)), int(round(h * r))
        px, py = (s - nw) // 2, (s - nh) // 2
        canvas = np.full((s, s, 3), 114, dtype=np.uint8)
        canvas[py:py + nh, px:px + nw] = cv2.resize(img, (nw, nh), interpolation=cv2.INTER_LINEAR)
        blob = cv2.dnn.blobFromImage(canvas, 1 / 255.0, swapRB=True)

        with self.lock:
            out = self.session.run(None, {self.input_name: blob})[0]  # (1, 84, N)
        pred = out[0]
        scores = pred[4]  # kelas 0 = person (COCO)
        keep = scores >= self.conf
        if not np.any(keep):
            return []
        cx, cy, bw, bh = pred[0][keep], pred[1][keep], pred[2][keep], pred[3][keep]
        sc = scores[keep]
        x1 = (cx - bw / 2 - px) / r
        y1 = (cy - bh / 2 - py) / r
        bw_o, bh_o = bw / r, bh / r
        boxes = np.stack([x1, y1, bw_o, bh_o], axis=1)
        idx = cv2.dnn.NMSBoxes(boxes.tolist(), sc.tolist(), self.conf, self.iou)
        result = []
        for i in np.array(idx).flatten():
            bx, by, bw_i, bh_i = boxes[i]
            x0, y0 = max(0, int(bx)), max(0, int(by))
            x2, y2 = min(w - 1, int(bx + bw_i)), min(h - 1, int(by + bh_i))
            if x2 - x0 < 4 or y2 - y0 < 8:
                continue
            result.append((x0, y0, x2, y2, float(sc[i])))
        return result


def draw_boxes(img: np.ndarray, dets, count: int, max_w: int = 960) -> bytes:
    h, w = img.shape[:2]
    scale = min(1.0, max_w / w)
    vis = cv2.resize(img, (int(w * scale), int(h * scale))) if scale < 1 else img.copy()
    t = max(1, int(round(2 * scale * 1.5)))
    for x0, y0, x2, y2, _ in dets:
        cv2.rectangle(vis, (int(x0 * scale), int(y0 * scale)), (int(x2 * scale), int(y2 * scale)), (60, 200, 255), t)
    label = f"{count} orang"
    (tw, th), _ = cv2.getTextSize(label, cv2.FONT_HERSHEY_SIMPLEX, 0.9, 2)
    cv2.rectangle(vis, (8, vis.shape[0] - th - 22), (8 + tw + 16, vis.shape[0] - 8), (0, 0, 0), -1)
    cv2.putText(vis, label, (16, vis.shape[0] - 16), cv2.FONT_HERSHEY_SIMPLEX, 0.9, (255, 255, 255), 2, cv2.LINE_AA)
    ok, buf = cv2.imencode(".jpg", vis, [cv2.IMWRITE_JPEG_QUALITY, 78])
    return buf.tobytes() if ok else b""


# --------------------------------------------------------------------------- worker

class Counter:
    def __init__(self, settings: dict):
        self.s = settings
        model_path = (BASE_DIR / settings["model"]).resolve()
        if not model_path.exists():
            raise SystemExit(f"Model tidak ditemukan: {model_path}")
        self.detector = PersonDetector(model_path, settings["confidence"], settings["iou"], settings["threads"])
        self.model_name = model_path.name
        self._last_halls: list[dict] = []
        self.camera_cache: dict[str, tuple[float, list[dict]]] = {}
        self.recent: dict[str, deque] = {}
        self.history = self._load_history()
        self.minute_acc: dict[str, list[int]] = {}
        self.current_minute = int(time.time() // 60)
        self.started = int(time.time())
        SNAP_DIR.mkdir(parents=True, exist_ok=True)
        log.info("Model %s, input %dpx", self.model_name, self.detector.size)

    # ---- konfigurasi
    def halls(self) -> list[dict]:
        try:
            data = json.loads(CONFIG_FILE.read_text(encoding="utf-8"))
        except Exception as exc:  # noqa: BLE001
            # bisa terjadi saat admin sedang menyimpan; pakai daftar terakhir
            log.debug("Gagal membaca config.json: %s", exc)
            return self._last_halls
        out = []
        for h in data.get("halls", []):
            pc = h.get("peopleCount") or {}
            if not pc.get("enabled"):
                continue
            base = dvr_base(h.get("iframeUrl", ""))
            if not base:
                continue
            out.append({"id": int(h.get("id", 0)), "name": h.get("name", ""), "base": base,
                        "cams": parse_cam_ids(pc.get("cams"))})
        self._last_halls = out
        return out

    def dvr_cameras(self, base: str) -> list[dict]:
        now = time.time()
        cached = self.camera_cache.get(base)
        if cached and now - cached[0] < self.s["camera_list_ttl"]:
            return cached[1]
        raw = http_get(base + "/command/getObjects", self.s["fetch_timeout"])
        data = json.loads(raw.decode("utf-8", "replace"))
        cams = [{"oid": int(o["id"]), "name": o.get("name", f"Kamera {o['id']}"),
                 "online": bool((o.get("data") or {}).get("online", True))}
                for o in data.get("objectList", []) if o.get("typeID") == 2]
        self.camera_cache[base] = (now, cams)
        return cams

    # ---- satu kamera
    def process_camera(self, base: str, cam: dict) -> dict:
        key = f"{urllib.parse.urlsplit(base).netloc}_{cam['oid']}".replace(":", "-")
        res = {"oid": cam["oid"], "name": cam["name"], "key": key, "count": None, "raw": None,
               "at": None, "error": "", "ms": None}
        if not cam.get("online", True):
            res["error"] = "Kamera offline di DVR"
            return res
        w = int(self.s["snapshot_width"])
        url = f"{base}/grab.jpg?oid={cam['oid']}&size={w}x{w * 9 // 16}"
        t0 = time.perf_counter()
        try:
            buf = http_get(url, self.s["fetch_timeout"])
            img = cv2.imdecode(np.frombuffer(buf, np.uint8), cv2.IMREAD_COLOR)
            if img is None:
                raise ValueError("snapshot bukan gambar")
        except (urllib.error.URLError, TimeoutError, OSError, ValueError) as exc:
            res["error"] = f"Gagal ambil snapshot: {getattr(exc, 'reason', exc)}"
            return res
        dets = self.detector.detect(img)
        raw = len(dets)
        q = self.recent.setdefault(key, deque(maxlen=max(1, int(self.s["smoothing"]))))
        q.append(raw)
        count = int(round(statistics.median(q)))
        res.update(count=count, raw=raw, at=int(time.time()), ms=int((time.perf_counter() - t0) * 1000))
        if self.s["save_snapshots"]:
            jpg = draw_boxes(img, dets, raw)
            if jpg:
                write_bytes_atomic(SNAP_DIR / f"{key}.jpg", jpg)
        return res

    # ---- satu putaran
    def run_once(self) -> dict:
        halls = self.halls()
        jobs: dict[tuple[str, int], dict] = {}
        hall_cams: dict[int, list[tuple[str, int]]] = {}
        hall_err: dict[int, str] = {}
        for h in halls:
            try:
                cams = self.dvr_cameras(h["base"])
            except Exception as exc:  # noqa: BLE001
                hall_err[h["id"]] = f"DVR tidak dapat dijangkau ({h['base']})"
                log.debug("getObjects %s: %s", h["base"], exc)
                continue
            chosen = [c for c in cams if not h["cams"] or c["oid"] in h["cams"]]
            if not chosen:
                hall_err[h["id"]] = "Kamera yang dipilih tidak ada di DVR"
            for c in chosen:
                jobs[(h["base"], c["oid"])] = c
            hall_cams[h["id"]] = [(h["base"], c["oid"]) for c in chosen]

        results: dict[tuple[str, int], dict] = {}
        if jobs:
            with ThreadPoolExecutor(max_workers=min(4, len(jobs))) as ex:
                futs = {k: ex.submit(self.process_camera, k[0], c) for k, c in jobs.items()}
                for k, f in futs.items():
                    try:
                        results[k] = f.result()
                    except Exception as exc:  # noqa: BLE001
                        log.exception("Kamera %s gagal", k)
                        results[k] = {"oid": k[1], "name": "", "count": None, "error": str(exc)}

        now = int(time.time())
        out_halls = {}
        total = 0
        for h in halls:
            cams = [results[k] for k in hall_cams.get(h["id"], []) if k in results]
            counts = [c["count"] for c in cams if c["count"] is not None]
            count = sum(counts) if counts else None
            err = hall_err.get(h["id"], "")
            if not err and cams and not counts:
                err = cams[0].get("error") or "Tidak ada data"
            out_halls[str(h["id"])] = {"name": h["name"], "count": count, "at": now if counts else None,
                                       "error": err, "cams": cams}
            if count is not None:
                total += count
                self.minute_acc.setdefault(str(h["id"]), []).append(count)

        state = {
            "updatedAt": now,
            "worker": {"pid": os.getpid(), "startedAt": self.started, "model": self.model_name,
                       "interval": self.s["interval"]},
            "total": total,
            "halls": out_halls,
        }
        write_json_atomic(OUT_FILE, state)
        self._roll_history(now)
        return state

    # ---- riwayat per menit
    def _load_history(self) -> dict:
        try:
            data = json.loads(HISTORY_FILE.read_text(encoding="utf-8"))
            if isinstance(data.get("halls"), dict):
                return data
        except Exception:  # noqa: BLE001
            pass
        return {"halls": {}}

    def _roll_history(self, now: int) -> None:
        minute = now // 60
        if minute == self.current_minute:
            return
        ts = self.current_minute * 60
        cutoff = now - int(self.s["history_hours"]) * 3600
        for hid, vals in self.minute_acc.items():
            if vals:
                rows = self.history["halls"].setdefault(hid, [])
                rows.append([ts, round(sum(vals) / len(vals), 1), max(vals)])
        for hid in list(self.history["halls"]):
            rows = [r for r in self.history["halls"][hid] if r[0] >= cutoff]
            if rows:
                self.history["halls"][hid] = rows
            else:
                del self.history["halls"][hid]
        self.minute_acc = {}
        self.current_minute = minute
        write_json_atomic(HISTORY_FILE, self.history)


# --------------------------------------------------------------------------- main

def setup_logging(verbose: bool) -> None:
    fmt = logging.Formatter("%(asctime)s %(levelname)s %(message)s")
    log.setLevel(logging.DEBUG if verbose else logging.INFO)
    fh = RotatingFileHandler(BASE_DIR / "counter.log", maxBytes=1_000_000, backupCount=2, encoding="utf-8")
    fh.setFormatter(fmt)
    log.addHandler(fh)
    if sys.stdout and sys.stdout.isatty():
        sh = logging.StreamHandler(sys.stdout)
        sh.setFormatter(fmt)
        log.addHandler(sh)


_instance_lock = None


def acquire_single_instance() -> bool:
    """Kunci file selama proses hidup supaya worker tidak jalan dobel."""
    global _instance_lock
    path = BASE_DIR / "counter.lock"
    fh = open(path, "a+")
    try:
        if os.name == "nt":
            import msvcrt
            fh.seek(0)
            msvcrt.locking(fh.fileno(), msvcrt.LK_NBLCK, 1)
        else:
            import fcntl
            fcntl.flock(fh, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        fh.close()
        return False
    (BASE_DIR / "counter.pid").write_text(str(os.getpid()), encoding="ascii")
    _instance_lock = fh
    return True


def main() -> None:
    ap = argparse.ArgumentParser(description="GIIAS people counter")
    ap.add_argument("--once", action="store_true", help="jalankan satu putaran lalu keluar")
    ap.add_argument("--test", metavar="JPG", help="hitung orang pada satu file gambar")
    ap.add_argument("--model", help="override path model")
    ap.add_argument("-v", "--verbose", action="store_true")
    args = ap.parse_args()

    setup_logging(args.verbose)
    settings = load_settings()
    if args.model:
        settings["model"] = args.model

    if args.test:
        det = PersonDetector((BASE_DIR / settings["model"]).resolve(), settings["confidence"], settings["iou"],
                             settings["threads"])
        img = cv2.imread(args.test)
        det.detect(img)  # pemanasan
        t0 = time.perf_counter()
        dets = det.detect(img)
        ms = (time.perf_counter() - t0) * 1000
        print(json.dumps({"count": len(dets), "ms": round(ms)}))
        out = Path(args.test).with_name(Path(args.test).stem + "_det.jpg")
        out.write_bytes(draw_boxes(img, dets, len(dets), 1280))
        return

    if not args.once and not acquire_single_instance():
        log.error("Worker lain sudah berjalan, keluar.")
        sys.exit(1)

    counter = Counter(settings)
    if args.once:
        print(json.dumps(counter.run_once(), ensure_ascii=False, indent=1))
        return

    stop = threading.Event()
    signal.signal(signal.SIGINT, lambda *_: stop.set())
    signal.signal(signal.SIGTERM, lambda *_: stop.set())
    log.info("Worker mulai (pid %d)", os.getpid())
    while not stop.is_set():
        t0 = time.time()
        try:
            st = counter.run_once()
            log.debug("total=%s dalam %.1fs", st["total"], time.time() - t0)
        except Exception:  # noqa: BLE001
            log.exception("Putaran gagal")
        stop.wait(max(0.5, float(settings["interval"]) - (time.time() - t0)))
    log.info("Worker berhenti")


if __name__ == "__main__":
    main()
