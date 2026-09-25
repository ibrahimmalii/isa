#!/usr/bin/env bash
# Copies the LOCAL store (database + uploads + plugins + language packs) to the server,
# replacing what's there. Run from the repo root on your Mac:
#
#   SERVER=ubuntu@<ip> SSH_KEY=~/.ssh/isa_key DOMAIN=isa-skin.com bash server/push-site.sh
#
# The code (theme, mu-plugin, scripts) is synced too. Destroys the server's current content,
# so only use it before launch, or when you deliberately want local to win.
set -euo pipefail

SERVER="${SERVER:?set SERVER=ubuntu@<ip>}"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/isa_key}"
DOMAIN="${DOMAIN:-isa-skin.com}"
LOCAL_URL="${LOCAL_URL:-http://localhost:8090}"
SSH="ssh -i $SSH_KEY -o StrictHostKeyChecking=accept-new"
OUT=backups/push-$(date +%Y%m%d-%H%M%S)
mkdir -p "$OUT"

echo "▸ Export local database"
docker compose exec -T db mariadb-dump -uisa -pisa-local-only --single-transaction --default-character-set=utf8mb4 isa > "$OUT/isa.sql"

echo "▸ Pack uploads, plugins, Storefront, language packs"
docker compose exec -T wordpress tar -C /var/www/html/wp-content -czf - uploads plugins languages themes/storefront > "$OUT/wp-content.tgz"

echo "▸ Sync code to /opt/isa"
rsync -az --delete -e "$SSH" --exclude backups/ --exclude products/images/ ./ "$SERVER:/tmp/isa-repo/"
$SSH "$SERVER" 'sudo rsync -a --delete /tmp/isa-repo/ /opt/isa/ && sudo chown -R root:root /opt/isa'

echo "▸ Upload data"
scp -q -i "$SSH_KEY" "$OUT/isa.sql" "$OUT/wp-content.tgz" "$SERVER:/tmp/"

echo "▸ Import on server"
$SSH "$SERVER" "sudo bash -s" <<REMOTE
set -euo pipefail
WEB=/var/www/isa
cd "\$WEB/wp-content"
rm -rf uploads plugins languages themes/storefront
tar -xzf /tmp/wp-content.tgz
chown -R www-data:www-data uploads plugins languages themes/storefront
WP="sudo -u www-data wp --path=\$WEB"
\$WP db import /tmp/isa.sql --quiet
\$WP search-replace '$LOCAL_URL' 'https://$DOMAIN' --all-tables --precise --skip-columns=guid --quiet
\$WP search-replace 'localhost:8090' '$DOMAIN' --all-tables --precise --skip-columns=guid --quiet
\$WP option update blog_public 1 --quiet
\$WP rewrite flush --quiet
\$WP cache flush --quiet
rm -rf /var/cache/nginx/isa/*
rm -f /tmp/isa.sql /tmp/wp-content.tgz
echo "imported: \$(\$WP option get siteurl)"
REMOTE
echo "▸ Done. Local copy of what was pushed: $OUT/"
