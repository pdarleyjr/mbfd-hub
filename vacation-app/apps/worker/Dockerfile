# syntax=docker/dockerfile:1.7
FROM node:22-alpine AS base
ENV PNPM_HOME=/pnpm
ENV PATH=$PNPM_HOME:$PATH
RUN corepack enable

FROM base AS deps
WORKDIR /app
COPY pnpm-workspace.yaml package.json ./
COPY apps/worker/package.json apps/worker/
COPY packages/db/package.json packages/db/
COPY packages/shared/package.json packages/shared/
RUN pnpm install --frozen-lockfile=false

FROM deps AS build
COPY tsconfig.base.json ./
COPY apps/worker apps/worker
COPY packages/db packages/db
COPY packages/shared packages/shared
RUN pnpm --filter @mbfd-vacation/db build \
  && pnpm --filter @mbfd-vacation/shared build \
  && pnpm --filter @mbfd-vacation/worker build

FROM base AS runtime
WORKDIR /app
ENV NODE_ENV=production
COPY --from=build /app/node_modules ./node_modules
COPY --from=build /app/apps/worker ./apps/worker
COPY --from=build /app/packages/db ./packages/db
COPY --from=build /app/packages/shared ./packages/shared
COPY --from=build /app/package.json /app/pnpm-workspace.yaml ./
RUN addgroup -S app && adduser -S app -G app && chown -R app:app /app
USER app
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD pgrep -f node || exit 1
CMD ["node", "apps/worker/dist/main.js"]
