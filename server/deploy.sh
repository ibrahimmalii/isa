#!/usr/bin/env bash
# Deploy code (theme, mu-plugin, scripts) from GitHub main to the server. Content is untouched.
#   SERVER=ubuntu@130.162.236.161 bash server/deploy.sh
set -euo pipefail
SERVER="${SERVER:-ubuntu@130.162.236.161}"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/isa_key}"
ssh -i "$SSH_KEY" "$SERVER" 'set -e
cd /opt/isa
sudo git fetch -q origin
sudo git reset -q --hard origin/main
sudo -u www-data wp --path=/var/www/isa i18n make-mo /opt/isa/theme/isa/languages --quiet 2>/dev/null || true
sudo systemctl reload php8.3-fpm               # OPcache would otherwise serve old PHP for up to 60 s
sudo find /var/cache/nginx/isa -mindepth 1 -delete
echo "deployed $(sudo git log --oneline -1)"'
