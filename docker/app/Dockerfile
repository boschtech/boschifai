#################################################
# boschifai-cli build stage — compiles a Linux `boschifai` binary from source
#################################################
# A `boschifai` binary built for a developer's Mac is a macOS Mach-O executable — it cannot run inside
# a Linux container, and there is no published Linux build, so this stage compiles one from
# source. The source is vendored directly into this repo at docker/boschifai-cli/ (previously an
# external sibling checkout referenced via a build-time path — now just part of this image's
# own build context, so no path configuration is needed).
FROM rust:1-bookworm AS boschifai-builder

RUN apt-get update && apt-get install -y --no-install-recommends pkg-config libssl-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /build
COPY docker/boschifai-cli/ .
RUN cargo build --release --package boschifai-cli

#################################################
# docker-cli stage — just a source to copy the `docker` client binary from
#################################################
FROM docker:27-cli AS docker-cli

#################################################
# Main app image
#################################################
FROM php:8.3-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git curl ca-certificates gnupg unzip \
        libzip-dev zip default-mysql-client \
    && docker-php-ext-install pdo_mysql zip bcmath pcntl opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Docker CLI (Docker-outside-of-Docker) — lets a job running in the `worker` container spin up
# a sibling test-runner container (whatever image the target repo's own RepoConfig configures)
# via the host's Docker socket (mounted in docker-compose.yml). This container is never given
# the Docker *daemon*, only the client — see
# app/Services/TestExecution/DockerTestRunner.php for what it's used for and
# config/boschifai.php's `docker.host_var_path` for why the mount paths it builds need
# translating to host paths.
COPY --from=docker-cli /usr/local/bin/docker /usr/local/bin/docker

# Claude Code CLI — official native Linux installer. Headless (`-p`) invocations authenticate
# via ANTHROPIC_API_KEY (set at runtime, never baked into the image) — no interactive login
# is possible or needed in a container. See app/Services/ClaudeRunner/HeadlessClaudeInvoker.php.
ENV HOME=/root
RUN curl -fsSL https://claude.ai/install.sh | bash
ENV PATH="/root/.local/bin:${PATH}"

# `boschifai` binary + its templates (`boschifai init` reads $HOME/.boschifai/templates/manifest.yaml — confirmed by
# reading boschifai-cli's own source, see app/Services/Sandbox/WorktreeManager.php's docblock).
COPY --from=boschifai-builder /build/target/release/boschifai /usr/local/bin/boschifai
COPY docker/boschifai-cli/templates /root/.boschifai/templates

# git operations happen against a mirror clone owned by whatever UID this container runs as;
# without this, git refuses to touch a repo it doesn't consider "safe" once bind-mounted.
RUN git config --global --add safe.directory '*'

WORKDIR /var/www/html

EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
