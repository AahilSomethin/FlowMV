FROM node:22.19.0-bookworm-slim@sha256:4a4884e8a44826194dff92ba316264f392056cbe243dcc9fd3551e71cea02b90 AS build
WORKDIR /app/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
ARG PUBLIC_ORIGIN
ENV NEXT_PUBLIC_API_URL=$PUBLIC_ORIGIN FLOWMV_SESSION_COOKIE=flowmv-release FLOWMV_BUILD_SINGLE_WORKER=1 NEXT_TELEMETRY_DISABLED=1
RUN npm run build
RUN mkdir -p public
FROM node:22.19.0-bookworm-slim@sha256:4a4884e8a44826194dff92ba316264f392056cbe243dcc9fd3551e71cea02b90
WORKDIR /app/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts
COPY --from=build /app/frontend/.next ./.next
COPY --from=build /app/frontend/public ./public
COPY --from=build /app/frontend/next.config.ts ./next.config.ts
ENV NODE_ENV=production NEXT_TELEMETRY_DISABLED=1 FLOWMV_SESSION_COOKIE=flowmv-release
USER node
EXPOSE 3000
CMD ["node", "node_modules/next/dist/bin/next", "start", "--hostname", "0.0.0.0", "--port", "3000"]
