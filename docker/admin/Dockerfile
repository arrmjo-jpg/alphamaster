# The Admin image: a static bundle and a server that hands it out.
#
# Two stages, and the first one does not survive. Node, npm and the whole
# dependency tree exist only to produce dist/; what ships is nginx and a directory
# of files. That keeps the runtime image small and, more to the point, keeps a
# build toolchain off the network the proxy publishes on.
#
# Nothing is configured per environment. The application addresses the API at a
# relative path (ADR 0042), so the same image is correct in development, staging
# and production and there is no VITE_API_URL to get wrong.

# ─── Stage: build ───
FROM node:22-alpine AS build

WORKDIR /app

# The manifest and the lockfile alone, so the dependency layer is reused whenever
# only source has changed.
COPY admin/package.json admin/package-lock.json ./

# `npm ci` rather than `npm install`: it installs exactly the lockfile and fails if
# the two disagree, which is the property that makes a build reproducible.
RUN npm ci --no-audit --no-fund

COPY admin/ ./

# `npm run build` typechecks before it bundles, so a type error fails the image
# build rather than shipping.
RUN npm run build

# ─── Stage: runtime ───
FROM nginx:alpine AS runtime

# curl for the healthcheck, matching the proxy image.
RUN apk add --no-cache curl

COPY docker/admin/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
