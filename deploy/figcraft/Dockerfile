# figcraft-server from its GitHub release (linux tarball), checked against SHA256SUMS.txt.
#   docker build --build-arg TAG=v0.14.0 -t figcraft-server .
FROM debian:bookworm-slim AS fetch
ARG TAG
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl && rm -rf /var/lib/apt/lists/*
WORKDIR /fetch
RUN set -eu; \
    test -n "$TAG"; \
    case "$(uname -m)" in x86_64) arch=x86_64 ;; aarch64) arch=aarch64 ;; *) exit 1 ;; esac; \
    base="https://github.com/storytold/figcraft/releases/download/${TAG}"; \
    asset="figcraft-server-${TAG#v}-linux-${arch}.tar.gz"; \
    curl -fsSL --retry 3 "$base/SHA256SUMS.txt" -o SHA256SUMS.txt; \
    curl -fsSL --retry 3 "$base/$asset" -o "$asset"; \
    grep -F " $asset" SHA256SUMS.txt | sha256sum -c -; \
    tar -xzf "$asset"; \
    bin="$(find . -type f -name figcraft-server -perm -u+x | head -n1)"; \
    test -n "$bin"; install -m 0755 "$bin" /figcraft-server

FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl && rm -rf /var/lib/apt/lists/* \
    && useradd --system --uid 10001 --home /data figcraft && mkdir -p /data /etc/figcraft-server && chown figcraft /data
COPY --from=fetch /figcraft-server /usr/local/bin/figcraft-server
USER figcraft
VOLUME /data
EXPOSE 47900
HEALTHCHECK --interval=30s --timeout=3s CMD curl -fsS http://127.0.0.1:47900/healthz || exit 1
ENTRYPOINT ["figcraft-server", "--config", "/etc/figcraft-server/server.toml"]
