#!/usr/bin/env bash
# Security hardening, added after the 2026-09-26 break-in (a bot guessed the admin
# password, uploaded a file-manager plugin and planted web shells). Safe to re-run.
#   sudo bash /opt/isa/server/harden.sh
#
# - wp-login.php: max ~6 attempts a minute per visitor (nginx returns 429 after that)
# - No plugin/theme installs or updates from wp-admin (DISALLOW_FILE_MODS): that upload
#   form is how the shell got in. Install plugins with WP-CLI on the server instead:
#     sudo -u www-data wp --path=/var/www/isa plugin install <slug> --activate
# - Since wp-admin can't update anything now, WP-CLI applies minor/security updates nightly.
set -euo pipefail
WEB=/var/www/isa
CONF=/etc/nginx/sites-available/isa
W="sudo -u www-data /usr/local/bin/wp --path=$WEB"

# ------------------------------------------------------------------ login rate limit
cat > /etc/nginx/conf.d/01-isa-login.conf <<'NGX'
limit_req_zone $binary_remote_addr zone=isa_login:10m rate=6r/m;
limit_req_status 429;
NGX
if ! grep -q "zone=isa_login" "$CONF"; then
	sed -i 's|^\tlocation ~ \\\.php\$ {|\tlocation = /wp-login.php {\n\t\tlimit_req zone=isa_login burst=4 nodelay;\n\t\tinclude snippets/fastcgi-php.conf;\n\t\tfastcgi_pass unix:/run/php/php8.3-fpm.sock;\n\t}\n\n&|' "$CONF"
fi
nginx -t -q
systemctl reload nginx

# ------------------------------------------------------------------ WordPress
$W config set DISALLOW_FILE_MODS true --raw --type=constant --quiet
$W config set DISALLOW_FILE_EDIT true --raw --type=constant --quiet

# ------------------------------------------------------------------ nightly updates
cat > /usr/local/bin/isa-update <<'SH'
#!/usr/bin/env bash
# Nightly: WordPress + plugin minor/security updates, then drop the page cache.
export PATH=/usr/local/bin:/usr/bin:/bin
W="sudo -u www-data wp --path=/var/www/isa --quiet"
$W core update --minor
$W plugin update --all --minor
$W language core update
$W language plugin update --all
find /var/cache/nginx/isa -mindepth 1 -delete
SH
chmod +x /usr/local/bin/isa-update

# Backups: cron's PATH has no /usr/local/bin, so `wp` was never found and no backup ran.
grep -q "^export PATH" /usr/local/bin/isa-backup ||
	sed -i 's|^set -e$|set -e\nexport PATH=/usr/local/bin:/usr/bin:/bin|' /usr/local/bin/isa-backup

cat > /etc/cron.d/isa <<'CRON'
*/5 * * * * www-data /usr/local/bin/wp --path=/var/www/isa cron event run --due-now --quiet >/dev/null 2>&1
30 3 * * * root /usr/local/bin/isa-backup >/dev/null 2>&1
15 4 * * * root /usr/local/bin/isa-update >/dev/null 2>&1
CRON

echo "hardened: login rate limit, no wp-admin file changes, nightly updates, backups fixed"
