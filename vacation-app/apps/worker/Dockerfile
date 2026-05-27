# syntax=docker/dockerfile:1.7
FROM node:22-alpine AS base
ENV PNPM_HOME=/pnpm
ENV PATH=$PNPM_HOME:$PATH
RUN corepack enable

FROM base AS deps
WORKDIR /app
COPY pnpm-workspace.yaml package.json pnpm-lock.yaml ./
COPY apps/api/package.json apps/api/
COPY apps/worker/package.json apps/worker/
COPY apps/web/package.json apps/web/
COPY apps/pin-gate/package.json apps/pin-gate/
COPY packages/db/package.json packages/db/
COPY packages/shared/package.json packages/shared/
RUN pnpm install --frozen-lockfile --filter @mbfd-vacation/worker... --ignore-scripts

FROM deps AS runtime
WORKDIR /app
ENV NODE_ENV=production
COPY tsconfig.base.json ./
COPY apps/worker apps/worker
COPY packages/db packages/db
COPY packages/shared packages/shared
RUN addgroup -S app && adduser -S app -G app && chown -R app:app /app
USER app
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD pgrep -f node || exit 1
CMD ["node", "--import", "tsx/esm", "apps/worker/src/main.ts"]
