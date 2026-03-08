#!/usr/bin/env python3
"""Quick test script for the vision-agent Worker."""
import base64
import json
import urllib.request
import ssl
import sys

# Use the MBFD logo as test image (a real PNG file available on VPS)
image_path = '/root/mbfd-hub/public/images/mbfd_app_icon_48.png'

with open(image_path, 'rb') as f:
    b64 = base64.b64encode(f.read()).decode()

payload = json.dumps({'image': b64}).encode()
req = urllib.request.Request(
    'https://vision-agent.pdarleyjr.workers.dev',
    data=payload,
    headers={'Content-Type': 'application/json'},
    method='POST'
)
ctx = ssl.create_default_context()
try:
    with urllib.request.urlopen(req, context=ctx, timeout=30) as resp:
        result = resp.read().decode()
    print('SUCCESS:', result)
except Exception as e:
    print('ERROR:', str(e), file=sys.stderr)
    sys.exit(1)
