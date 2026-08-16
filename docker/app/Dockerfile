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
# GitHub MCP server — vendored as a plain binary, not `docker run`
#################################################
# Copies just the compiled binary out of GitHub's own published image at build time (multi-stage
# COPY --from can pull from any reachable image, not only earlier stages of this Dockerfile) —
# `worker` ends up with a plain executable and never needs Docker-socket access to launch it,
# which running the upstream image via `docker run` normally would. See
# app/Services/ClaudeRunner/HeadlessClaudeInvoker.php and docker/app/mcp-config.json for how it's
# wired into `claude`'s own MCP config; the PAT it authenticates with is never baked into this
# image (see GITHUB_MCP_PAT in docker-compose.yml/.env.example).
FROM ghcr.io/github/github-mcp-server:latest AS github-mcp-server

#################################################
# Main app image
#################################################
FROM php:8.3-fpm-bookworm

# gd/exif aren't needed by Boschifai's own app — they're here because the `test-runner` service
# (same image, different command — see docker-compose.yml) runs a connected repo's own
# `composer install` + test suite directly with THIS image's PHP (see LocalTestRunner's
# docblock). RAMS's own dependency tree (phpoffice/phpspreadsheet, spatie/image,
# spatie/laravel-medialibrary, maatwebsite/excel) needs both — confirmed by a real
# local_execution failure. Any other connected repo could need different extensions; this list
# will likely need to keep growing.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git curl ca-certificates gnupg unzip \
        libzip-dev zip default-mysql-client \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql zip bcmath pcntl opcache gd exif \
    # PCOV, not Xdebug: `test-runner` only needs a coverage DRIVER for `php artisan test
    # --coverage-html` (LocalTestRunner's requirement-mode PHP/Laravel command), never step
    # debugging — PCOV is purpose-built for that (much lower overhead, no debug-protocol
    # surface to worry about on a container that already runs an untrusted connected repo's
    # own code, per this file's own incident note above).
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Node.js/npm — needed by `test-runner` (same image, different command) to run a connected
# repo's own JS/TS test suite whenever coverage mode's dynamically-authored execution recipe
# targets that stack (LocalTestRunner's own docblock: "the target repo can be any stack
# /boschifai-gen-component detects"). Confirmed as a real gap live: a JS/TS-stack coverage-mode
# run got all the way through code generation correctly, then local_execution failed outright
# with "npm: not found" — this image had PHP tooling but no Node.js at all. NodeSource's own
# setup script (not Debian bookworm's default apt package) for a current LTS release, matching
# how the Claude Code CLI below is also installed via its own official script rather than an
# apt package.
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Claude Code CLI — official native Linux installer. Headless (`-p`) invocations authenticate
# via ANTHROPIC_API_KEY (set at runtime, never baked into the image) — no interactive login
# is possible or needed in a container. See app/Services/ClaudeRunner/HeadlessClaudeInvoker.php.
ENV HOME=/root
RUN curl -fsSL https://claude.ai/install.sh | bash
ENV PATH="/root/.local/bin:${PATH}"

# `boschifai` binary + its templates (`boschifai init` reads $HOME/.boschifai/templates/manifest.yaml — confirmed by
# reading boschifai-cli's own source, see app/Services/Sandbox/RunWorkspaceManager.php's docblock).
COPY --from=boschifai-builder /build/target/release/boschifai /usr/local/bin/boschifai
COPY docker/boschifai-cli/templates /root/.boschifai/templates

# GitHub MCP server binary + its (secret-free) Claude Code config — see the builder stage above.
COPY --from=github-mcp-server /server/github-mcp-server /usr/local/bin/github-mcp-server
COPY docker/app/mcp-config.json /opt/boschifai/mcp-config.json

# PHP's own defaults (upload_max_filesize=2M, post_max_size=8M) are too small for the "attach
# supporting files" upload on the run-create form — a handful of screenshots/spec docs clears 2M
# easily. Raised here (not just in a Laravel-level validation rule) because PHP enforces its own
# ceiling before Laravel/the request even sees the upload; a bigger validation rule alone would
# just fail confusingly at the PHP layer instead.
RUN echo "upload_max_filesize = 10M\npost_max_size = 55M\nmax_file_uploads = 5" > /usr/local/etc/php/conf.d/boschifai-uploads.ini

# git operations happen against a checkout owned by whatever UID this container runs as; without
# this, git refuses to touch a repo it doesn't consider "safe" once bind-mounted.
RUN git config --global --add safe.directory '*'

# BranchAndCommitService's own commits (real generated-test commits, pushed under a human's
# Gate 2 approval) need SOME author identity — confirmed as a real gap live: with nothing set,
# `git commit` fails outright ("Author identity unknown"), regardless of which push path runs it.
# A fixed bot identity, not a real person's, since every such commit is machine-generated —
# same convention as GitHub Actions' own commit bot.
RUN git config --global user.name "Boschifai" \
    && git config --global user.email "boschifai-bot@noreply.local"

# The `test-runner` service's entire command — baked into the image (not bind-mounted) because
# that service deliberately does NOT mount the rest of this project (no `.env`, no `vendor/`,
# see docker-compose.yml's own comment on that service): a connected repo's own test suite runs
# here specifically so it can never inherit Boschifai's own DB/queue/API-key credentials, even
# indirectly. See the script's own docblock for the full incident this is a response to.
COPY docker/test-runner/poll.php /opt/boschifai/test-runner-poll.php

WORKDIR /var/www/html

EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
