#!/usr/bin/env python3
"""Plugin launcher for the existing LibreNMS RRD repair implementation."""

import runpy


runpy.run_path("/opt/librenms/.github/skills/rrd-fix/scripts/rrd-fix.py", run_name="__main__")