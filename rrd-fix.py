#!/usr/bin/env python3
"""
rrd-fix.py — Corrección de RRD de LibreNMS para un período de caída.

Uso:
    python3 rrd-fix.py --ip <IP> --start "YYYY-MM-DD HH:MM:SS" --end "YYYY-MM-DD HH:MM:SS" [--no-dbm] [--rrd-dir /opt/librenms/rrd]

Ejemplo:
    python3 rrd-fix.py --ip 45.177.21.97 --start "2026-07-09 08:05:28" --end "2026-07-09 09:45:03"
"""

import argparse
import os
import re
import shutil
import socket
import subprocess
import sys
from datetime import datetime, timezone, timedelta

try:
    import pymysql
    _HAS_PYMYSQL = True
except ImportError:
    _HAS_PYMYSQL = False

# ─── Configuración ────────────────────────────────────────────────────────────
TZ_LIMA     = timezone(timedelta(hours=-5))   # America/Lima = UTC-5
RRD_BASE    = "/opt/librenms/rrd"
STEP        = 300                              # segundos por fila RRD
DOWN_DBM    = "-4.0000000000e+01"
RRDCACHED_SOCKET = "/var/run/rrdcached.sock"
BACKUP_SUFFIX = ""
BACKED_UP: set[str] = set()
AVAIL_FILES = [
    ("availability-86400.rrd",   86400),
    ("availability-604800.rrd",  604800),
    ("availability-2592000.rrd", 2592000),
    ("availability-31536000.rrd", 31536000),   # anual (no siempre existe)
]

# Prefijos que se omiten siempre (métricas internas de LibreNMS, no del dispositivo)
_UNUSED = None  # SKIP_PREFIXES eliminado — no se usaba

# ─── Helpers ──────────────────────────────────────────────────────────────────
row_single = re.compile(r'(/\s*(\d+)\s+-->\s*<row><v>)([^<]+)(</v></row>)')
row_multi  = re.compile(r'/\s*(\d+)\s+-->\s*(<row>(?:<v>[^<]*</v>)+</row>)')
rra_block  = re.compile(r'<rra>.*?</rra>', re.DOTALL)

GAUGE_PREFIXES = (
    "sensor-dbm-", "sensor-state-", "sensor-temperature-", "sensor-voltage-",
    "sensor-current-", "sensor-count-", "mempool-", "storage-", "routeros_",
    "netstats-",
)


def run(cmd):
    if len(cmd) >= 3 and cmd[:2] == ["rrdtool", "dump"]:
        ok, detail = rrdcached_command("FLUSH", cmd[2])
        if not ok:
            return subprocess.CompletedProcess(cmd, 1, "", f"rrdcached FLUSH: {detail}")
    return subprocess.run(cmd, capture_output=True, text=True)


def local_to_unix(dt_str: str) -> int:
    """Convierte 'YYYY-MM-DD HH:MM:SS' (Lima) a Unix timestamp."""
    dt = datetime.strptime(dt_str.strip(), "%Y-%m-%d %H:%M:%S")
    return int(dt.replace(tzinfo=TZ_LIMA).timestamp())


def first_rrd_row(unix_ts: int) -> int:
    """Primer múltiplo de STEP estrictamente mayor que unix_ts (= primera fila afectada)."""
    return (unix_ts // STEP + 1) * STEP


def rrdcached_command(command: str, rrd: str) -> tuple[bool, str]:
    """Ejecuta un comando del protocolo rrdcached para un archivo."""
    if not os.path.exists(RRDCACHED_SOCKET):
        return True, "rrdcached no activo"
    try:
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as client:
            client.connect(RRDCACHED_SOCKET)
            stream = client.makefile("rwb", buffering=0)
            stream.write(f"{command} {os.path.realpath(rrd)}\n".encode())
            response = stream.readline().decode().strip()
            status = int(response.split(maxsplit=1)[0])
            if status < 0:
                return False, response
            for _ in range(status):
                stream.readline()
            return True, response
    except (OSError, ValueError) as exc:
        return False, str(exc)


def dump_rrd(rrd: str):
    """Vacía actualizaciones pendientes antes de leer el RRD."""
    return run(["rrdtool", "dump", rrd])


def backup_once(rrd: str) -> tuple[bool, str]:
    """Crea una sola copia por ejecución, justo antes de modificar el archivo."""
    if rrd in BACKED_UP:
        return True, ""
    try:
        shutil.copy2(rrd, f"{rrd}.bak_{BACKUP_SUFFIX}")
        BACKED_UP.add(rrd)
        return True, ""
    except OSError as exc:
        return False, str(exc)


def restore_validated(xml_text: str, rrd: str) -> tuple[bool, str]:
    """Restaura y valida en temporal antes de reemplazar el RRD original."""
    xml = f"/tmp/rrdfix_{os.getpid()}_{os.path.basename(rrd)}.xml"
    temporary_rrd = f"{rrd}.rrdfix_{os.getpid()}.tmp"
    try:
        with open(xml, "w") as xml_file:
            xml_file.write(xml_text)
        restored = run(["rrdtool", "restore", xml, temporary_rrd])
        if restored.returncode:
            return False, restored.stderr.strip()
        checked = run(["rrdtool", "info", temporary_rrd])
        if checked.returncode:
            return False, checked.stderr.strip()
        backed_up, detail = backup_once(rrd)
        if not backed_up:
            return False, f"backup: {detail}"
        flushed, detail = rrdcached_command("FLUSH", rrd)
        if not flushed:
            return False, f"rrdcached FLUSH: {detail}"
        forgotten, detail = rrdcached_command("FORGET", rrd)
        if not forgotten and "No such file or directory" not in detail:
            return False, f"rrdcached FORGET: {detail}"
        os.replace(temporary_rrd, rrd)
        return True, "✓"
    finally:
        for path in (xml, temporary_rrd):
            if os.path.exists(path):
                os.remove(path)


def restore_if_changed(original_xml: str, updated_xml: str, rrd: str) -> tuple[bool, str]:
    """Evita backup y reemplazo cuando la transformación no produjo cambios."""
    if original_xml == updated_xml:
        return True, "sin cambios"
    return restore_validated(updated_xml, rrd)

def historical_fill_xml(xml_text: str, ts_start: int, ts_end: int,
                        max_blocks: int = 8) -> tuple[str, int, int]:
    """Copia por RRA un bloque anterior conservando la hora de cada muestra."""
    filled = 0
    missing = 0
    span = ts_end - ts_start + STEP
    block_seconds = max(86400, ((span + 86399) // 86400) * 86400)

    def replace_rra(rra_match):
        nonlocal filled, missing
        block = rra_match.group(0)
        rows: dict[int, list[str]] = {}
        for row_match in row_multi.finditer(block):
            rows[int(row_match.group(1))] = re.findall(r"<v>([^<]*)</v>", row_match.group(2))

        ordered_timestamps = sorted(rows)

        def valid(value: str) -> bool:
            return "nan" not in value.lower()

        def replace_row(row_match):
            nonlocal filled, missing
            ts = int(row_match.group(1))
            if not (ts_start <= ts <= ts_end):
                return row_match.group(0)
            current = re.findall(r"<v>([^<]*)</v>", row_match.group(2))
            historical: list[str] = []
            for ds_index, current_value in enumerate(current):
                historical_value = None
                for block_back in range(1, max_blocks + 1):
                    candidate = rows.get(ts - block_back * block_seconds)
                    if candidate and ds_index < len(candidate) and valid(candidate[ds_index]):
                        historical_value = candidate[ds_index]
                        break
                if historical_value is None:
                    for source_ts in reversed(ordered_timestamps):
                        candidate = rows[source_ts]
                        if source_ts >= ts_start or ds_index >= len(candidate):
                            continue
                        if valid(candidate[ds_index]):
                            historical_value = candidate[ds_index]
                            break
                if historical_value is None and valid(current_value):
                    historical_value = current_value
                if historical_value is None:
                    historical_value = "0.0000000000e+00"
                    missing += 1
                historical.append(historical_value)
            filled += 1
            new_row = "<row>" + "".join(f"<v>{value}</v>" for value in historical) + "</row>"
            return row_match.group(0).replace(row_match.group(2), new_row)

        return row_multi.sub(replace_row, block)

    return rra_block.sub(replace_rra, xml_text), filled, missing


# ─── Availability ─────────────────────────────────────────────────────────────
def fix_availability(rrd_dir: str, fname: str, window: int,
                     ts_down_start: int, ts_down_end: int, ts_recov: int,
                     avail_fill: int | None = None) -> str:
    """
    avail_fill=None  → comportamiento normal: 0% durante caída, 100% en cola
    avail_fill=0     → fuerza 0% en todo el período (marcar caída manualmente)
    avail_fill=100   → fuerza 100% en todo el período (corregir falsa caída)
    """
    rrd = os.path.join(rrd_dir, fname)
    if not os.path.exists(rrd):
        return f"  {fname}: no existe, omitido"

    tail_end = ts_recov + window
    c0 = [0]; c1 = [0]

    def repl(m):
        ts = int(m.group(2)); val = m.group(3)
        if ts_down_start <= ts <= ts_down_end:
            target = float(avail_fill) if avail_fill is not None else 0.0
            target_str = f"{target:.10e}"
            try:
                if abs(float(val) - target) > 0.001:
                    c0[0] += 1
                    return m.group(1) + target_str + m.group(4)
            except ValueError:
                c0[0] += 1
                return m.group(1) + target_str + m.group(4)
        elif avail_fill in (None, 100) and ts_recov <= ts <= tail_end:
            # corrige cola del rolling window tanto en caída real como en falsa caída
            try:
                if abs(float(val) - 100.0) > 0.001:
                    c1[0] += 1
                    return m.group(1) + "1.0000000000e+02" + m.group(4)
            except ValueError:
                pass
        return m.group(0)

    res = run(["rrdtool", "dump", rrd])
    if res.returncode:
        return f"  {fname}: ERROR dump: {res.stderr.strip()}"
    updated_xml = row_single.sub(repl, res.stdout)
    ok, detail = restore_if_changed(res.stdout, updated_xml, rrd)
    status = detail if ok else f"ERROR: {detail}"
    label = f"→ {avail_fill}%" if avail_fill is not None else "0%/100%"
    return f"  {fname}: {c0[0]} período {label}  {c1[0]} cola → 100%  {status}"


# ─── icmp-perf ────────────────────────────────────────────────────────────────
def fix_icmp(rrd_dir: str, ts_down_start: int, ts_down_end: int) -> str:
    rrd = os.path.join(rrd_dir, "icmp-perf.rrd")
    if not os.path.exists(rrd):
        return "  icmp-perf.rrd: no existe, omitido"

    cnt = [0]

    def repl(m):
        ts = int(m.group(1))
        if not (ts_down_start <= ts <= ts_down_end):
            return m.group(0)
        row = m.group(2)
        vals = re.findall(r"<v>([^<]*)</v>", row)
        if len(vals) != 5:
            return m.group(0)
        cnt[0] += 1
        new_row = (
            "<row>"
            "<v>0.0000000000e+00</v>"
            f"<v>{vals[1]}</v>"          # xmt intacto
            "<v>0.0000000000e+00</v>"
            "<v>0.0000000000e+00</v>"
            "<v>0.0000000000e+00</v>"
            "</row>"
        )
        return m.group(0).replace(row, new_row)

    res = run(["rrdtool", "dump", rrd])
    if res.returncode:
        return f"  icmp-perf.rrd: ERROR dump: {res.stderr.strip()}"
    updated_xml = row_multi.sub(repl, res.stdout)
    ok, detail = restore_if_changed(res.stdout, updated_xml, rrd)
    status = detail if ok else f"ERROR: {detail}"
    return f"  icmp-perf.rrd: {cnt[0]} filas → 0  {status}"


# ─── dBm SFP ──────────────────────────────────────────────────────────────────
def find_dbm_rrd(rrd_dir: str) -> str | None:
    for f in os.listdir(rrd_dir):
        if re.search(r"dbm.*mtxrOptical", f, re.IGNORECASE):
            return os.path.join(rrd_dir, f)
    return None


def get_normal_dbm(rrd: str, ts_recov: int) -> str:
    """Lee el valor post-recuperación para usarlo como referencia normal."""
    res = run(["rrdtool", "fetch", rrd, "AVERAGE",
               "--start", str(ts_recov - 600), "--end", str(ts_recov + 1800)])
    best = None
    for line in res.stdout.splitlines():
        parts = line.split(":")
        if len(parts) != 2:
            continue
        ts_str, val_str = parts
        try:
            ts = int(ts_str.strip())
            v  = float(val_str.strip())
        except ValueError:
            continue
        if ts >= ts_recov and v > -35:   # primer valor normal post-recuperación
            best = v
            break
    if best is None:
        return "-1.7000000000e+01"       # fallback genérico si no se encuentra
    return f"{best:.10e}"


def fix_dbm(rrd_dir: str, ts_down_start: int, ts_down_end: int, ts_recov: int) -> str:
    rrd = find_dbm_rrd(rrd_dir)
    if rrd is None:
        return "  dBm SFP: no encontrado, omitido"

    normal_val = get_normal_dbm(rrd, ts_recov)
    cd = [0]; cr = [0]

    def repl(m):
        ts = int(m.group(2)); val = m.group(3)
        if ts_down_start <= ts <= ts_down_end:
            try:
                if float(val) >= -35.0:      # no tocar valores ya normales en ese rango
                    return m.group(0)
            except ValueError:
                pass
            cd[0] += 1
            return m.group(1) + DOWN_DBM + m.group(4)
        if ts == ts_recov:
            try:
                if float(val) < -35.0 or "nan" in val.lower():
                    cr[0] += 1
                    return m.group(1) + normal_val + m.group(4)
            except ValueError:
                cr[0] += 1
                return m.group(1) + normal_val + m.group(4)
        return m.group(0)

    res = run(["rrdtool", "dump", rrd])
    if res.returncode:
        return f"  {os.path.basename(rrd)}: ERROR dump: {res.stderr.strip()}"
    updated_xml = row_single.sub(repl, res.stdout)
    ok, detail = restore_if_changed(res.stdout, updated_xml, rrd)
    status = detail if ok else f"ERROR: {detail}"
    fname = os.path.basename(rrd)
    return f"  {fname}: {cd[0]} → {DOWN_DBM}  {cr[0]} recup → {normal_val}  {status}"


# ─── dBm TxPower (mismo tratamiento que Rx) ───────────────────────────────────
def fix_dbm_all(rrd_dir: str, ts_down_start: int, ts_down_end: int, ts_recov: int) -> list[str]:
    """Corrige todos los RRD de tipo dBm (Rx y Tx)."""
    results = []
    for f in sorted(os.listdir(rrd_dir)):
        if not re.search(r"^sensor-dbm-.*\.rrd$", f) or f.endswith(".bak"):
            continue
        rrd = os.path.join(rrd_dir, f)
        normal_val = get_normal_dbm(rrd, ts_recov)
        cd = [0]; cr = [0]

        def repl(m, _cd=cd, _cr=cr, _nv=normal_val):
            ts = int(m.group(2)); val = m.group(3)
            if ts_down_start <= ts <= ts_down_end:
                try:
                    if float(val) >= -35.0:
                        return m.group(0)
                except ValueError:
                    pass
                _cd[0] += 1
                return m.group(1) + DOWN_DBM + m.group(4)
            if ts == ts_recov:
                try:
                    if float(val) < -35.0 or "nan" in val.lower():
                        _cr[0] += 1
                        return m.group(1) + _nv + m.group(4)
                except ValueError:
                    _cr[0] += 1
                    return m.group(1) + _nv + m.group(4)
            return m.group(0)

        res = run(["rrdtool", "dump", rrd])
        if res.returncode:
            results.append(f"  {f}: ERROR dump: {res.stderr.strip()}")
            continue
        updated_xml = row_single.sub(repl, res.stdout)
        ok, detail = restore_if_changed(res.stdout, updated_xml, rrd)
        status = detail if ok else f"ERROR: {detail}"
        results.append(f"  {f}: {cd[0]} → {DOWN_DBM}  {cr[0]} recup → {normal_val}  {status}")
    return results


# ─── sensor-state RxLoss ──────────────────────────────────────────────────────
def fix_rxloss(rrd_dir: str, ts_down_start: int, ts_down_end: int, ts_recov: int) -> list[str]:
    """Fija RxLoss=1 durante la caída (señal óptica perdida) y 0 en la recuperación."""
    results = []
    for f in os.listdir(rrd_dir):
        if not re.search(r"sensor-state.*RxLoss.*\.rrd$", f, re.IGNORECASE) or f.endswith(".bak"):
            continue
        rrd = os.path.join(rrd_dir, f)
        cnt = [0]

        def repl(m, _c=cnt):
            ts = int(m.group(2)); val = m.group(3)
            if ts_down_start <= ts <= ts_down_end:
                if "nan" in val.lower():
                    _c[0] += 1
                    return m.group(1) + "1.0000000000e+00" + m.group(4)
            elif ts == ts_recov and "nan" in val.lower():
                _c[0] += 1
                return m.group(1) + "0.0000000000e+00" + m.group(4)
            return m.group(0)

        res = run(["rrdtool", "dump", rrd])
        if res.returncode:
            results.append(f"  {f}: ERROR dump: {res.stderr.strip()}")
            continue
        updated_xml = row_single.sub(repl, res.stdout)
        ok, detail = restore_if_changed(res.stdout, updated_xml, rrd)
        status = detail if ok else f"ERROR: {detail}"
        results.append(f"  {f}: {cnt[0]} → 1 (loss)  {status}")
    return results


# ─── Sensores genéricos ───────────────────────────────────────────────────────
def fix_generic_sensors(rrd_dir: str, ts_down_start: int, ts_down_end: int,
                        zero_fill: bool = False) -> list[str]:
    """Rellena RRD con días anteriores (UP) o sensores NaN con 0 (caída)."""
    # sensor-dbm y sensor-state tienen manejo propio en avail_fill=0
    SKIP_ZERO_PREFIXES = ("sensor-dbm-", "sensor-state-")
    results = []
    for f in sorted(os.listdir(rrd_dir)):
        if f.endswith(".bak") or not f.endswith(".rrd"):
            continue
        if not any(f.startswith(p) for p in GAUGE_PREFIXES):
            continue
        if zero_fill:
            if any(f.startswith(p) for p in SKIP_ZERO_PREFIXES):
                continue
        rrd = os.path.join(rrd_dir, f)

        res = run(["rrdtool", "dump", rrd])
        if res.returncode:
            results.append(f"  {f}: ERROR dump: {res.stderr.strip()}")
            continue
        if zero_fill:
            cnt = [0]

            def repl(m, _c=cnt):
                ts = int(m.group(2)); val = m.group(3)
                if not (ts_down_start <= ts <= ts_down_end): return m.group(0)
                if "nan" not in val.lower(): return m.group(0)
                _c[0] += 1
                return m.group(1) + "0.0000000000e+00" + m.group(4)

            updated_xml = row_single.sub(repl, res.stdout)
            filled = cnt[0]
            missing = 0
        else:
            updated_xml, filled, missing = historical_fill_xml(
                res.stdout, ts_down_start, ts_down_end)
        ok, detail = restore_if_changed(res.stdout, updated_xml, rrd)
        status = detail if ok else f"ERROR: {detail}"
        label = "0" if zero_fill else "días anteriores"
        suffix = f"; {missing} DS con fallback 0" if missing else ""
        results.append(f"  {f}: {filled} filas → {label}{suffix}  {status}")
    return results


# ─── Puertos (DERIVE, multi-DS) ─────────────────────────────────────────────
def fix_ports(rrd_dir: str, ts_down_start: int, ts_down_end: int,
             use_neighbor: bool = False) -> list[str]:
    """Rellena puertos con días anteriores (UP) o NaN con 0 (caída)."""
    results = []
    for f in sorted(os.listdir(rrd_dir)):
        if not re.match(r"^port-id\d+\.rrd$", f): continue
        rrd = os.path.join(rrd_dir, f)
        if use_neighbor:
            res = run(["rrdtool", "dump", rrd])
            if res.returncode:
                results.append(f"  {f}: ERROR dump: {res.stderr.strip()}")
                continue
            updated_xml, filled, missing = historical_fill_xml(
                res.stdout, ts_down_start, ts_down_end)
            ok, detail = restore_if_changed(res.stdout, updated_xml, rrd)
            status = detail if ok else f"ERROR: {detail}"
            suffix = f"; {missing} DS con fallback 0" if missing else ""
            results.append(f"  {f}: {filled} filas → días anteriores{suffix}  {status}")
        else:
            cnt = [0]

            def repl_zero(m, _c=cnt):
                ts = int(m.group(1))
                if not (ts_down_start <= ts <= ts_down_end): return m.group(0)
                row = m.group(2)
                if "nan" not in row.lower(): return m.group(0)
                vals = re.findall(r"<v>([^<]*)</v>", row)
                if not vals: return m.group(0)
                _c[0] += 1
                new_row = "<row>" + "".join("<v>0.0000000000e+00</v>" for _ in vals) + "</row>"
                return m.group(0).replace(row, new_row)

            res = run(["rrdtool", "dump", rrd])
            if res.returncode:
                results.append(f"  {f}: ERROR dump: {res.stderr.strip()}")
                continue
            updated_xml = row_multi.sub(repl_zero, res.stdout)
            ok, detail = restore_if_changed(res.stdout, updated_xml, rrd)
            status = detail if ok else f"ERROR: {detail}"
            results.append(f"  {f}: {cnt[0]} filas NaN → 0  {status}")
    return results


def fix_icmp_neighbor(rrd_dir: str, ts_down_start: int, ts_down_end: int) -> str:
    """Sobreescribe icmp-perf con el bloque anterior a la misma hora."""
    rrd = os.path.join(rrd_dir, "icmp-perf.rrd")
    if not os.path.exists(rrd):
        return "  icmp-perf.rrd: no encontrado"

    res = run(["rrdtool", "dump", rrd])
    if res.returncode:
        return f"  icmp-perf.rrd: ERROR dump: {res.stderr.strip()}"
    updated_xml, filled, missing = historical_fill_xml(
        res.stdout, ts_down_start, ts_down_end)
    ok, detail = restore_if_changed(res.stdout, updated_xml, rrd)
    status = detail if ok else f"ERROR: {detail}"
    suffix = f"; {missing} DS con fallback 0" if missing else ""
    return f"  icmp-perf.rrd: {filled} filas → días anteriores{suffix}  {status}"


# ─── NaN scan y relleno ───────────────────────────────────────────────────────
def scan_and_fill_nan(rrd_dir: str, month_start: int, month_end: int,
                      ts_down_start: int, ts_down_end: int) -> list[str]:
    """Detecta y rellena NaN en availability e icmp-perf fuera del período de caída."""
    results = []
    for fname in ["availability-86400.rrd", "icmp-perf.rrd"]:
        rrd = os.path.join(rrd_dir, fname)
        if not os.path.exists(rrd):
            continue
        res = run(["rrdtool", "fetch", rrd, "AVERAGE",
                   "--start", str(month_start), "--end", str(month_end)])
        nan_ts = []
        for line in res.stdout.splitlines():
            if "nan" not in line.lower():
                continue
            parts = line.split(":")
            if len(parts) < 2:
                continue
            try:
                ts = int(parts[0].strip())
            except ValueError:
                continue
            if not (ts_down_start <= ts <= ts_down_end):
                nan_ts.append(ts)
        if not nan_ts:
            results.append(f"  {fname}: sin NaN fuera de la caída ✓")
            continue
        # Agrupar en bloques consecutivos para loguear
        results.append(f"  {fname}: {len(nan_ts)} filas NaN fuera del período → rellenando con vecinos...")
        results.extend(_fill_nan_with_neighbors(rrd, nan_ts, fname))
    return results


def _fill_nan_with_neighbors(rrd: str, nan_timestamps: list[int], fname: str) -> list[str]:
    """Rellena filas NaN con promedio de vecino anterior y posterior."""
    is_single_ds = "availability" in fname

    if is_single_ds:
        nan_re  = re.compile(r'(/\s*(\d+)\s+-->\s*<row><v>)(nan|NaN|-nan|-NaN)(</v></row>)')
    else:
        nan_re  = re.compile(r'/\s*(\d+)\s+-->\s*(<row>(?:<v>[^<]*</v>)+</row>)')

    nan_set = set(nan_timestamps)

    # Obtener valores vecinos
    neighbor_vals: dict[int, str] = {}
    for ts in nan_timestamps:
        # leer ±2 filas alrededor
        res = run(["rrdtool", "fetch", rrd, "AVERAGE",
                   "--start", str(ts - STEP * 3), "--end", str(ts + STEP * 3)])
        rows = {}
        for line in res.stdout.splitlines():
            parts = line.split(":")
            if len(parts) < 2:
                continue
            try:
                t = int(parts[0].strip())
                v = parts[1].strip()
                if "nan" not in v.lower():
                    rows[t] = v
            except ValueError:
                continue
        before = max((t for t in rows if t < ts), default=None)
        after  = min((t for t in rows if t > ts), default=None)
        if before and after:
            if is_single_ds:
                vb = float(rows[before]); va = float(rows[after])
                neighbor_vals[ts] = f"{(vb + va) / 2:.10e}"
            else:
                vb_parts = rows[before].split(); va_parts = rows[after].split()
                # promedio campo a campo (5 DS en icmp)
                avgs = []
                for vb, va in zip(vb_parts, va_parts):
                    try:
                        avgs.append(f"{(float(vb)+float(va))/2:.10e}")
                    except ValueError:
                        avgs.append(vb)
                neighbor_vals[ts] = " ".join(avgs)
        elif before:
            neighbor_vals[ts] = rows[before]
        elif after:
            neighbor_vals[ts] = rows[after]
        else:
            neighbor_vals[ts] = "1.0000000000e+02" if is_single_ds else None

    cnt = [0]

    def repl_single(m):
        ts = int(m.group(2))
        if ts not in nan_set or ts not in neighbor_vals:
            return m.group(0)
        cnt[0] += 1
        return m.group(1) + neighbor_vals[ts] + m.group(4)

    def repl_multi(m):
        ts = int(m.group(1))
        if ts not in nan_set or not neighbor_vals.get(ts):
            return m.group(0)
        row = m.group(2)
        if "nan" not in row.lower():
            return m.group(0)
        vals = neighbor_vals[ts].split()
        if len(vals) != 5:
            return m.group(0)
        cnt[0] += 1
        new_row = (
            f"<row><v>{vals[0]}</v><v>{vals[1]}</v>"
            f"<v>{vals[2]}</v><v>{vals[3]}</v><v>{vals[4]}</v></row>"
        )
        return m.group(0).replace(row, new_row)

    res = run(["rrdtool", "dump", rrd])
    if res.returncode:
        return [f"    → ERROR dump: {res.stderr.strip()}"]
    if is_single_ds:
        xml_out = nan_re.sub(repl_single, res.stdout)
    else:
        xml_out = nan_re.sub(repl_multi, res.stdout)
    ok, detail = restore_if_changed(res.stdout, xml_out, rrd)
    status = detail if ok else f"ERROR: {detail}"
    return [f"    → {cnt[0]} reemplazos  {status}"]


# ─── Modo interactivo ─────────────────────────────────────────────────────────
def ask(prompt: str, default: str = "") -> str:
    suffix = f" [{default}]" if default else ""
    try:
        val = input(f"{prompt}{suffix}: ").strip()
    except (EOFError, KeyboardInterrupt):
        print(); sys.exit(0)
    return val or default


def parse_datetime_interactive(raw: str) -> str:
    """Acepta HH:MM o YYYY-MM-DD HH:MM y completa con la fecha actual si falta."""
    raw = raw.strip()
    if re.match(r"^\d{2}:\d{2}(:\d{2})?$", raw):
        today = datetime.now(TZ_LIMA).strftime("%Y-%m-%d")
        raw = f"{today} {raw}"
    if re.match(r"^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$", raw):
        raw += ":00"
    return raw


def interactive_mode(rrd_base: str) -> argparse.Namespace:
    print("\n┌─ rrd-fix — corrección interactiva de RRD LibreNMS ─────────────┐")
    args = argparse.Namespace()
    args.rrd_dir = rrd_base

    args.ip = ask("  IP del dispositivo")
    while not os.path.isdir(os.path.join(rrd_base, args.ip)):
        print(f"  ✗ No existe {os.path.join(rrd_base, args.ip)}")
        args.ip = ask("  IP del dispositivo")

    raw_start = ask("  Inicio del período  (YYYY-MM-DD HH:MM  o  HH:MM)")
    raw_end   = ask("  Fin del período     (YYYY-MM-DD HH:MM  o  HH:MM)")
    args.start = parse_datetime_interactive(raw_start)
    args.end   = parse_datetime_interactive(raw_end)

    # avail-fill: default 100
    print("  Modo availability:")
    print("    [1] 100% — corregir período que aparece caído pero estuvo UP  (default)")
    print("    [0]   0% — marcar como caída real")
    choice = ask("  Elige", "1")
    args.avail_fill = 0 if choice.strip() == "0" else 100

    no_dbm = ask("  ¿Omitir corrección dBm SFP? (s/N)", "N").lower()
    args.no_dbm = no_dbm in ("s", "si", "sí", "y", "yes")

    scan = ask("  ¿Buscar y rellenar NaN en el mes? (S/n)", "S").lower()
    args.scan_nan   = scan not in ("n", "no")
    args.scan_start = None
    args.scan_end   = None
    print("└────────────────────────────────────────────────────────────────┘\n")
    return args


# ─── DB helpers ───────────────────────────────────────────────────────────────
def _read_db_config(config_php: str = "/opt/librenms/config.php") -> dict:
    """Extrae credenciales de la DB desde config.php sin ejecutar PHP."""
    cfg = {}
    pattern = re.compile(r"\$config\['(db_(?:host|user|pass|name))'\]\s*=\s*'([^']*)'")
    try:
        with open(config_php) as f:
            for m in pattern.finditer(f.read()):
                cfg[m.group(1)] = m.group(2)
    except OSError:
        pass
    return cfg


def fix_db(ip: str, unix_start: int, unix_end: int, avail_fill: int) -> list[str]:
    """Sincroniza device_outages, eventlog y alert_log según el modo de corrección."""
    if not _HAS_PYMYSQL:
        return ["  [DB] pymysql no disponible — omitido"]
    cfg = _read_db_config()
    if not cfg.get("db_host"):
        return ["  [DB] credenciales no encontradas — omitido"]
    lines = []
    try:
        conn = pymysql.connect(
            host=cfg["db_host"], user=cfg["db_user"],
            password=cfg["db_pass"], database=cfg["db_name"],
            connect_timeout=5,
        )
        dt_start = datetime.fromtimestamp(unix_start, tz=timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        dt_end   = datetime.fromtimestamp(unix_end,   tz=timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        with conn:
            with conn.cursor() as cur:
                cur.execute("SELECT device_id FROM devices WHERE hostname = %s LIMIT 1", (ip,))
                row = cur.fetchone()
                if not row:
                    return [f"  [DB] dispositivo {ip} no encontrado en devices"]
                device_id = row[0]

                if avail_fill == 100:
                    # El período corregido no debe conservar eventos derivados de datos inválidos.
                    cur.execute(
                        "DELETE FROM device_outages WHERE device_id = %s"
                        " AND going_down >= %s AND going_down <= %s",
                        (device_id, unix_start, unix_end),
                    )
                    lines.append(f"  device_outages : {cur.rowcount} eliminado(s)  ✓")

                    cur.execute(
                        "DELETE FROM eventlog WHERE device_id = %s"
                        " AND datetime BETWEEN %s AND %s",
                        (device_id, dt_start, dt_end),
                    )
                    lines.append(f"  eventlog       : {cur.rowcount} evento(s) eliminado(s)  ✓")

                    cur.execute(
                        "DELETE FROM alert_log WHERE device_id = %s"
                        " AND time_logged BETWEEN %s AND %s",
                        (device_id, dt_start, dt_end),
                    )
                    lines.append(f"  alert_log      : {cur.rowcount} eliminado(s)  ✓")

                else:
                    # Dispositivo estuvo DOWN: asegurar que existe un outage y eventos
                    cur.execute(
                        "SELECT COUNT(*) FROM device_outages WHERE device_id = %s"
                        " AND going_down >= %s AND going_down <= %s",
                        (device_id, unix_start, unix_end),
                    )
                    existing = cur.fetchone()[0]
                    if existing == 0:
                        cur.execute(
                            "INSERT INTO device_outages (device_id, going_down, up_again)"
                            " VALUES (%s, %s, %s)",
                            (device_id, unix_start, unix_end),
                        )
                        lines.append(f"  device_outages : 1 caída insertada  ✓")
                    else:
                        lines.append(f"  device_outages : {existing} registro(s) ya existentes  ✓")

                    # Insertar eventos down/up solo si no existen ya
                    cur.execute(
                        "SELECT COUNT(*) FROM eventlog WHERE device_id = %s"
                        " AND datetime BETWEEN %s AND %s AND type = 'down'",
                        (device_id, dt_start, dt_end),
                    )
                    if cur.fetchone()[0] == 0:
                        cur.execute(
                            "INSERT INTO eventlog (device_id, datetime, message, type, severity)"
                            " VALUES (%s, %s, %s, 'down', 5)",
                            (device_id, dt_start, "Device status changed to Down from icmp check."),
                        )
                        cur.execute(
                            "INSERT INTO eventlog (device_id, datetime, message, type, severity)"
                            " VALUES (%s, %s, %s, 'up', 1)",
                            (device_id, dt_end, "Device status changed to Up from icmp check."),
                        )
                        lines.append(f"  eventlog       : eventos down/up insertados  ✓")
                    else:
                        lines.append(f"  eventlog       : eventos ya existentes  ✓")

            conn.commit()
    except Exception as exc:
        lines.append(f"  [DB] error: {exc}")
    return lines


# ─── Main ─────────────────────────────────────────────────────────────────────
def main():
    global BACKUP_SUFFIX
    parser = argparse.ArgumentParser(
        description="Corrige RRD LibreNMS para un período. Sin argumentos: modo interactivo.")
    parser.add_argument("--ip",         help="IP del dispositivo")
    parser.add_argument("--start",      help="Inicio 'YYYY-MM-DD HH:MM:SS' (Lima)")
    parser.add_argument("--end",        help="Fin 'YYYY-MM-DD HH:MM:SS' (Lima)")
    parser.add_argument("--no-dbm",     action="store_true", help="Omitir corrección dBm SFP")
    parser.add_argument("--rrd-dir",    default=RRD_BASE, help=f"Directorio base RRD (default: {RRD_BASE})")
    parser.add_argument("--scan-nan",   action="store_true", help="Buscar y rellenar NaN en el mes del período")
    parser.add_argument("--scan-start", default=None, help="Inicio rango scan NaN 'YYYY-MM-DD HH:MM:SS'")
    parser.add_argument("--scan-end",   default=None, help="Fin rango scan NaN 'YYYY-MM-DD HH:MM:SS'")
    parser.add_argument("--avail-fill", type=int, choices=[0, 100], default=100,
                        help="100 (default) = corregir falsa caída  |  0 = marcar como caída real")
    args = parser.parse_args()

    # Sin --ip lanzar modo interactivo
    if not args.ip:
        args = interactive_mode(args.rrd_dir)

    # --scan-start/--scan-end implican --scan-nan automáticamente
    if args.scan_start or args.scan_end:
        args.scan_nan = True

    rrd_dir = os.path.join(args.rrd_dir, args.ip)
    if not os.path.isdir(rrd_dir):
        sys.exit(f"ERROR: directorio no encontrado: {rrd_dir}")

    unix_start = local_to_unix(args.start)
    unix_end   = local_to_unix(args.end)

    ts_down_start = first_rrd_row(unix_start)   # primera fila afectada
    ts_down_end   = (unix_end // STEP) * STEP   # última fila completamente caída
    ts_recov      = ts_down_end + STEP           # fila de recuperación

    print(f"\n{'─'*60}")
    print(f"  Dispositivo : {args.ip}")
    modo = "100% (corregir falso down)" if args.avail_fill == 100 else "0% (caída real)"
    print(f"  Período     : {args.start}  →  {args.end}  (Lima)")
    print(f"  Modo        : {modo}")
    print(f"  Rows RRD    : {ts_down_start} → {ts_down_end}  recov={ts_recov}")
    print(f"{'─'*60}")

    BACKUP_SUFFIX = datetime.now().strftime("%Y%m%d_%H%M%S")
    BACKED_UP.clear()
    print(f"\n[Backups] bajo demanda, sufijo: .bak_{BACKUP_SUFFIX}")

    # Availability
    print("\n[Availability]")
    for fname, window in AVAIL_FILES:
        print(fix_availability(rrd_dir, fname, window, ts_down_start, ts_down_end, ts_recov,
                               avail_fill=args.avail_fill))

    # Las correcciones siguientes solo aplican cuando hay caída real (avail_fill != 100)
    if args.avail_fill != 100:
        # icmp-perf
        print("\n[icmp-perf]")
        print(fix_icmp(rrd_dir, ts_down_start, ts_down_end))

        # dBm SFP (todos: Rx + Tx)
        if not args.no_dbm:
            print("\n[dBm SFP]")
            for line in fix_dbm_all(rrd_dir, ts_down_start, ts_down_end, ts_recov):
                print(line)

        # sensor-state RxLoss → 1 durante caída
        print("\n[sensor-state RxLoss]")
        lines = fix_rxloss(rrd_dir, ts_down_start, ts_down_end, ts_recov)
        print("\n".join(lines) if lines else "  (ninguno encontrado)")

        # Sensores GAUGE genéricos → 0 durante caída real
        print("\n[Sensores genéricos + memoria + almacenamiento]")
        lines = fix_generic_sensors(rrd_dir, ts_down_start, ts_down_end, zero_fill=True)
        print("\n".join(lines) if lines else "  sin NaN en el período")

        # Puertos → 0 durante caída
        print("\n[Puertos]")
        lines = fix_ports(rrd_dir, ts_down_start, ts_down_end)
        print("\n".join(lines) if lines else "  sin NaN en el período")

    # Scan NaN
    if args.scan_nan:
        if args.scan_start and args.scan_end:
            scan_from = local_to_unix(args.scan_start)
            scan_to   = local_to_unix(args.scan_end)
            label = f"{args.scan_start} → {args.scan_end}"
        elif args.scan_start:
            scan_from = local_to_unix(args.scan_start)
            scan_to   = scan_from + 30 * 86400
            label = f"{args.scan_start} → +30 días"
        elif args.scan_end:
            scan_to   = local_to_unix(args.scan_end)
            scan_from = scan_to - 30 * 86400
            label = f"-30 días → {args.scan_end}"
        else:
            # default: mes completo del período de caída
            dt_s = datetime.strptime(args.start[:7], "%Y-%m").replace(
                day=1, hour=0, minute=0, second=0, tzinfo=TZ_LIMA)
            dt_e = (dt_s + timedelta(days=32)).replace(day=1)
            scan_from = int(dt_s.timestamp())
            scan_to   = int(dt_e.timestamp())
            label = f"mes de la caída ({args.start[:7]})"
        print(f"\n[Scan NaN — {label}]")
        for line in scan_and_fill_nan(rrd_dir, scan_from, scan_to,
                                      ts_down_start, ts_down_end):
            print(line)

    # Cuando el dispositivo estaba UP, reconstruir el período con días anteriores
    if args.avail_fill == 100:
        print("\n[icmp-perf - patrón de días anteriores]")
        print(fix_icmp_neighbor(rrd_dir, ts_down_start, ts_down_end))

        print("\n[Sensores + dBm - patrón de días anteriores]")
        lines = fix_generic_sensors(rrd_dir, ts_down_start, ts_down_end)
        print("\n".join(lines) if lines else "  sin RRD relevantes")

        print("\n[Puertos - patrón de días anteriores]")
        lines = fix_ports(rrd_dir, ts_down_start, ts_down_end, use_neighbor=True)
        print("\n".join(lines) if lines else "  sin RRD de puertos")

    # DB: eliminar (avail_fill=100) o insertar (avail_fill=0)
    print("\n[DB — device_outages / eventlog / alert_log]")
    for line in fix_db(args.ip, unix_start, unix_end, args.avail_fill):
        print(line)

    print(f"\n[Backups] {len(BACKED_UP)} RRD modificados, sufijo: .bak_{BACKUP_SUFFIX}")
    print(f"\n{'─'*60}\n  Corrección completada.\n{'─'*60}\n")

if __name__ == "__main__":
    if os.getuid() == 0:
        # rrdtool restore crea archivos con el dueño del proceso; debe ser librenms
        os.execvp("runuser", ["runuser", "-u", "librenms", "--", sys.executable] + sys.argv)
    main()
