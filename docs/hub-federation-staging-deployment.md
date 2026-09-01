# Hub federation staging deployment

This is a dedicated authentication-federation environment, not a production
promotion path. It uses the `mbfd-hub-staging-*` containers, volumes, network,
Redis cache, PostgreSQL database, session cookie, logs, and Cloudflare Tunnel.

## Runtime secrets

Create `/opt/mbfd/staging/hub-federation/app.env` from
`docker/staging/runtime.env.example` and
`/opt/mbfd/staging/hub-federation/compose.env` from
`docker/staging/compose.env.example`, both mode `0600`. Generate a unique
`APP_KEY`, database password, Cloudflare staging-tunnel token, Bid reader token,
and three staging-only test passwords. Do not copy any production secret. The
tunnel token belongs only in `compose.env`, never in the Laravel app container.

The Hub `BID_READER_TOKEN` and Bid staging `PORTAL_BID_READER` must be the same
new random value. The staging file must retain only
`https://staging.bid.mbfdhub.com/api/auth/callback` in `BID_AUTH_CALLBACKS`.

## Deploy

From a clean checkout of the exact intended SHA on the staging host, execute:

```bash
HUB_STAGING_APP_ENV=/opt/mbfd/staging/hub-federation/app.env \
HUB_STAGING_COMPOSE_ENV=/opt/mbfd/staging/hub-federation/compose.env \
  scripts/staging/deploy-hub-federation-staging.sh
```

The script rejects a non-staging app URL, production-style session/cache names,
queue workers, portal writeback, a dirty checkout, or a missing source-SHA
label. It migrates only the isolated staging database, seeds only the three
staging identities, and prints the deployed SHA without printing secrets.

## Required verification

Verify the deployed SHA label, `/up`, registered routes, unauthenticated
redirect behavior, exact callback/client rejection, server-side reader-token
protection, single-use code consumption, and one-success concurrent redemption.
Never run this file with the production compose project or environment file.
