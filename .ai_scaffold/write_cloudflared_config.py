#!/usr/bin/env python3
import os, json

os.makedirs('/root/mbfd-hub/cloudflared', exist_ok=True)

config = """tunnel: 89429799-7028-4df2-870d-f2fb858a49d7
credentials-file: /etc/cloudflared/creds.json
ingress:
  - hostname: www.mbfdhub.com
    service: http://nocobase:80
  - service: http_status:404
"""

creds = {
    "AccountTag": "265122b6d6f29457b0ca950c55f3ac6e",
    "TunnelID": "89429799-7028-4df2-870d-f2fb858a49d7",
    "TunnelName": "mbfdhub-nocobase",
    "TunnelSecret": "bWJmZGh1YnNlY3JldDIwMjYhISEhMTIzNA=="
}

with open('/root/mbfd-hub/cloudflared/config.yml', 'w') as f:
    f.write(config)

with open('/root/mbfd-hub/cloudflared/creds.json', 'w') as f:
    json.dump(creds, f)

print('Config files written:')
print(' - /root/mbfd-hub/cloudflared/config.yml')
print(' - /root/mbfd-hub/cloudflared/creds.json')
