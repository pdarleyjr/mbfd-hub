#!/bin/bash
# Fix /daily/index.html icon references

cd /root/mbfd-hub
docker compose exec -T laravel.test bash -c "cat public/daily/index.html | sed 's|/vite.svg|/daily/icons/icon-192.png|g' | sed 's|href=\"/icons/icon-192.png\"|href=\"/daily/icons/icon-192.png\"|g' > /tmp/index.html.tmp && mv /tmp/index.html.tmp public/daily/index.html"

echo "Fixed index.html"
