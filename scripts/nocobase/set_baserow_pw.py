#!/usr/bin/env python3
import subprocess, psycopg2

# Generate Django password hash for "admin123" using Baserow's venv
result = subprocess.run(
    ['/baserow/venv/bin/python', '-c',
     'from django.contrib.auth.hashers import make_password; print(make_password("admin123"))'],
    capture_output=True, text=True,
    env={
        'SECRET_KEY': 'de58dc00ed09edb62db931a09fa39f6917f7009f381270e3b100934a97b25533',
        'DJANGO_SETTINGS_MODULE': 'baserow.config.settings.base',
        'DATABASE_URL': 'postgresql://postgres@localhost/baserow',
        'PATH': '/baserow/venv/bin:/usr/bin:/bin'
    }
)
hash_val = result.stdout.strip()
print(f'Hash generated: {hash_val[:40]}...')

conn = psycopg2.connect(host='localhost', dbname='baserow', user='postgres')
cur = conn.cursor()
cur.execute('UPDATE auth_user SET password=%s WHERE email=%s RETURNING id', (hash_val, 'admin@darleyplex.com'))
conn.commit()
print(f'Updated {cur.rowcount} row(s)')
conn.close()
