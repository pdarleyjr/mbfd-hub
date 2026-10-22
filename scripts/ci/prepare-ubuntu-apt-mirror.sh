#!/usr/bin/env bash
set -euo pipefail

# Keep the runner's apt suites, components and signature verification intact.
mirror_list=/etc/apt/apt-mirrors.txt
if test -f "$mirror_list" && grep -q '^http://azure\.archive\.ubuntu\.com/ubuntu\b' "$mirror_list"; then
  curl --fail --silent --show-error --location --connect-timeout 10 --max-time 30 \
    --output /dev/null https://archive.ubuntu.com/ubuntu/dists/noble/InRelease
  sudo sed -i 's|^http://azure\.archive\.ubuntu\.com/ubuntu\b|https://archive.ubuntu.com/ubuntu|' "$mirror_list"
fi
