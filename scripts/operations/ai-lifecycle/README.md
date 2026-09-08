# Lane C lifecycle guard

This directory contains the secret-free, non-mutating guard for the authorized
OpenWebUI / Office / OnlyOffice AI retirement. The operational sequence and
rollback rules are in
[`docs/operations/mbfd-ai-lane-c-retirement.md`](../../../docs/operations/mbfd-ai-lane-c-retirement.md).

The guard has four commands:

```bash
python3 lane_c_lifecycle.py capture --output /root/lane-c.json
python3 lane_c_lifecycle.py validate --phase preflight
python3 lane_c_lifecycle.py plan \
  --archive-root /mnt/mbfd-storage/backups/on-demand/mbfd-ai-lane-c-YYYYMMDDTHHMMSSZ
python3 lane_c_lifecycle.py validate --phase postcheck
```

`scrub-onlyoffice-compose` writes a separate candidate and refuses in-place
mutation. A Release Captain must validate and install that candidate. No command
in this helper stops a service, changes a credential, edits a firewall, removes
a volume, or modifies production.
