#!/usr/bin/env bash

set -Eeuo pipefail

readonly remote_host="${XSERVER_STAGING_HOST:-xserver-groovy}"
readonly remote_app_dir="/home/xs662848/groovy-shift-system/staging"
readonly remote_public_dir="/home/xs662848/dev-smarty.com/public_html/groovy.dev-smarty.com"
readonly php_bin="/usr/bin/php8.4"
readonly composer_bin="/home/xs662848/bin/composer"

if [[ "$(git branch --show-current)" != "main" ]]; then
    echo 'Deploy aborted: switch to the main branch first.' >&2
    exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
    echo 'Deploy aborted: the Git working tree is not clean.' >&2
    exit 1
fi

ssh "${remote_host}" "test -d '${remote_public_dir}'" || {
    echo 'Deploy aborted: create groovy.dev-smarty.com in the Xserver panel first.' >&2
    exit 1
}

ssh "${remote_host}" "mkdir -p '${remote_app_dir}'"

docker compose run --rm node npm run build

maintenance_enabled=false

restore_application() {
    if [[ "${maintenance_enabled}" == 'true' ]]; then
        ssh "${remote_host}" "cd '${remote_app_dir}' && '${php_bin}' artisan up" >/dev/null 2>&1 || true
    fi
}

trap restore_application EXIT

if ssh "${remote_host}" "test -f '${remote_app_dir}/artisan' && test -f '${remote_app_dir}/.env'"; then
    ssh "${remote_host}" "cd '${remote_app_dir}' && '${php_bin}' artisan down --retry=60"
    maintenance_enabled=true
fi

rsync -az --delete \
    --exclude='.git/' \
    --exclude='.github/' \
    --exclude='.env' \
    --exclude='.env.*' \
    --exclude='deploy/' \
    --exclude='docker/' \
    --exclude='docs/' \
    --exclude='node_modules/' \
    --exclude='scripts/' \
    --exclude='storage/' \
    --exclude='tests/' \
    --exclude='vendor/' \
    --exclude='bootstrap/cache/*' \
    --exclude='compose.yaml' \
    --exclude='Dockerfile' \
    ./ "${remote_host}:${remote_app_dir}/"

ssh "${remote_host}" "
    set -eu
    mkdir -p \
        '${remote_app_dir}/bootstrap/cache' \
        '${remote_app_dir}/storage/app/private' \
        '${remote_app_dir}/storage/app/public' \
        '${remote_app_dir}/storage/framework/cache/data' \
        '${remote_app_dir}/storage/framework/sessions' \
        '${remote_app_dir}/storage/framework/views' \
        '${remote_app_dir}/storage/logs'
    chmod -R u+rwX '${remote_app_dir}/bootstrap/cache' '${remote_app_dir}/storage'
    '${php_bin}' '${composer_bin}' install \
        --working-dir='${remote_app_dir}' \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --optimize-autoloader
"

if ! ssh "${remote_host}" "test -f '${remote_app_dir}/.env'"; then
    echo "Deploy paused: create ${remote_app_dir}/.env from deploy/xserver/.env.staging.example." >&2
    exit 1
fi

rsync -az --delete \
    --exclude='.htaccess' \
    --exclude='index.php' \
    --exclude='storage' \
    public/ "${remote_host}:${remote_public_dir}/"

rsync -az deploy/xserver/public-index.php "${remote_host}:${remote_public_dir}/index.php"

ssh "${remote_host}" "
    set -eu
    if ! grep -q 'RewriteRule ^ index.php' '${remote_public_dir}/.htaccess' 2>/dev/null; then
        cp '${remote_app_dir}/public/.htaccess' '${remote_public_dir}/.htaccess'
    fi
    ln -sfn '${remote_app_dir}/storage/app/public' '${remote_public_dir}/storage'
    cd '${remote_app_dir}'
    '${php_bin}' artisan migrate --force
    '${php_bin}' artisan optimize:clear
    '${php_bin}' artisan config:cache
    '${php_bin}' artisan route:cache
    '${php_bin}' artisan view:cache
    '${php_bin}' artisan up
"

maintenance_enabled=false

echo 'Staging deployment completed: https://groovy.dev-smarty.com'
