config = """tunnel: 89429799-7028-4df2-870d-f2fb858a49d7
credentials-file: /etc/cloudflared/creds.json
ingress:
  - hostname: www.mbfdhub.com
    service: http://nocobase:80
  - hostname: mbfdhub.com
    service: http://nocobase:80
  - service: http_status:404
"""
with open('/root/mbfd-hub/cloudflared/config.yml', 'w') as f:
    f.write(config)
print('Config updated with both hostnames')
