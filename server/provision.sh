#!/usr/bin/env bash
# One-time server setup for isa-skin.com on a 1 GB Ubuntu 24.04 VM (Oracle VM.Standard.E2.1.Micro).
# Safe to re-run. Run as root:   sudo DOMAIN=isa-skin.com bash server/provision.sh
#
# Stack: nginx (FastCGI page cache for guests) + PHP 8.3-FPM + MariaDB, all tuned for 1 GB RAM,
# 2 GB swap, ufw, unattended security updates, WP-CLI, daily DB + uploads backup (7 kept).
set -euo pipefail

DOMAIN="${DOMAIN:-isa-skin.com}"
WEB=/var/www/isa
REPO=/opt/isa
DB_NAME=isa
DB_USER=isa
say() { printf '\n\033[1;35m▸ %s\033[0m\n' "$*"; }

# ------------------------------------------------------------------ swap
say "Swap (2 GB)"
if ! swapon --show | grep -q /swapfile; then
	fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile
	grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi
sysctl -q vm.swappiness=10 && echo 'vm.swappiness=10' > /etc/sysctl.d/99-isa.conf

# ------------------------------------------------------------------ packages
say "Packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nginx mariadb-server php8.3-fpm php8.3-mysql php8.3-curl php8.3-gd php8.3-intl \
	php8.3-mbstring php8.3-xml php8.3-zip php8.3-imagick php8.3-bcmath php8.3-opcache \
	git unzip curl ufw unattended-upgrades certbot python3-certbot-nginx >/dev/null
if ! command -v wp >/dev/null; then
	curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
	chmod +x /usr/local/bin/wp
fi

# ------------------------------------------------------------------ firewall
say "Firewall"
# Oracle's Ubuntu images ship iptables REJECT rules; ufw replaces them cleanly.
if [ -f /etc/iptables/rules.v4 ]; then
	iptables -F INPUT || true
	iptables -P INPUT ACCEPT || true
	: > /etc/iptables/rules.v4
fi
ufw --force reset >/dev/null
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw allow 22/tcp >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw --force enable >/dev/null

# ------------------------------------------------------------------ MariaDB (small)
say "MariaDB"
cat > /etc/mysql/mariadb.conf.d/60-isa.cnf <<'CNF'
[mysqld]
innodb_buffer_pool_size = 128M
innodb_log_file_size    = 32M
max_connections         = 30
performance_schema      = OFF
table_open_cache        = 400
tmp_table_size          = 16M
max_heap_table_size     = 16M
skip_name_resolve       = ON
CNF
systemctl restart mariadb
if [ ! -f /root/.isa-db-pass ]; then
	openssl rand -hex 24 > /root/.isa-db-pass && chmod 600 /root/.isa-db-pass
fi
DB_PASS="$(cat /root/.isa-db-pass)"
mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;"

# ------------------------------------------------------------------ PHP-FPM (small)
say "PHP-FPM"
cat > /etc/php/8.3/fpm/pool.d/www.conf <<'POOL'
[www]
user = www-data
group = www-data
listen = /run/php/php8.3-fpm.sock
listen.owner = www-data
listen.group = www-data
pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 20s
pm.max_requests = 300
POOL
cat > /etc/php/8.3/fpm/conf.d/99-isa.ini <<'INI'
memory_limit = 256M
upload_max_filesize = 16M
post_max_size = 20M
max_execution_time = 60
opcache.memory_consumption = 64
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 60
expose_php = Off
INI
systemctl restart php8.3-fpm

# ------------------------------------------------------------------ code + WordPress
say "Code + WordPress"
if [ ! -d "$REPO/.git" ]; then
	git clone -q https://github.com/ibrahimmalii/isa.git "$REPO" || {
		echo "Clone failed (private repo?). Copy the repo to $REPO, then re-run."; exit 1; }
fi
mkdir -p "$WEB"
chown -R www-data:www-data "$WEB"
if [ ! -f "$WEB/wp-load.php" ]; then
	sudo -u www-data wp core download --path="$WEB" --quiet
fi
if [ ! -f "$WEB/wp-config.php" ]; then
	sudo -u www-data wp config create --path="$WEB" --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" \
		--dbhost=localhost --dbcharset=utf8mb4 --quiet --extra-php <<PHP
define( 'WP_ENVIRONMENT_TYPE', 'production' );
define( 'DISALLOW_FILE_EDIT', true );
define( 'WP_POST_REVISIONS', 5 );
define( 'WP_MEMORY_LIMIT', '128M' );
define( 'DISABLE_WP_CRON', true );          // real cron below; no cron on page views
if ( isset( \$_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === \$_SERVER['HTTP_X_FORWARDED_PROTO'] ) { \$_SERVER['HTTPS'] = 'on'; }
PHP
fi
# Theme + must-use plugin come from the repo (git pull = deploy)
ln -sfn "$REPO/theme/isa" "$WEB/wp-content/themes/isa"
mkdir -p "$WEB/wp-content/mu-plugins"
ln -sfn "$REPO/mu-plugins/isa-store.php" "$WEB/wp-content/mu-plugins/isa-store.php"
# Paths setup.sh / importer expect (same as the Docker mounts)
ln -sfn "$REPO/bin" /bin-isa
ln -sfn "$REPO/brand" /brand
ln -sfn "$REPO/products" /products
chown -h www-data:www-data "$WEB/wp-content/themes/isa" "$WEB/wp-content/mu-plugins/isa-store.php"

# ------------------------------------------------------------------ nginx
say "nginx (with guest page cache)"
mkdir -p /var/cache/nginx/isa && chown www-data:www-data /var/cache/nginx/isa
cat > /etc/nginx/conf.d/00-isa-cache.conf <<'NGX'
fastcgi_cache_path /var/cache/nginx/isa levels=1:2 keys_zone=ISA:32m max_size=512m inactive=60m use_temp_path=off;
fastcgi_cache_key "$scheme$request_method$host$request_uri";
# Real visitor IP behind Cloudflare
real_ip_header CF-Connecting-IP;
NGX
curl -fsS https://www.cloudflare.com/ips-v4 | sed 's/^/set_real_ip_from /; s/$/;/' >> /etc/nginx/conf.d/00-isa-cache.conf || true
curl -fsS https://www.cloudflare.com/ips-v6 | sed 's/^/set_real_ip_from /; s/$/;/' >> /etc/nginx/conf.d/00-isa-cache.conf || true

cat > /etc/nginx/sites-available/isa <<NGX
server {
	listen 80;
	listen [::]:80;
	server_name $DOMAIN www.$DOMAIN;
	root $WEB;
	index index.php;
	client_max_body_size 20m;

	# www → apex
	if (\$host = www.$DOMAIN) { return 301 \$scheme://$DOMAIN\$request_uri; }

	# Page cache: guests only. Anything with a cart, login, checkout or POST skips it.
	set \$skip 0;
	if (\$request_method = POST) { set \$skip 1; }
	if (\$query_string != "") { set \$skip 1; }
	if (\$request_uri ~* "/(wp-admin|wp-login\\.php|wp-json|xmlrpc\\.php|cart|checkout|my-account|basket)|/(en|ar)/(cart|checkout|my-account)|add-to-cart|wc-ajax|feed|sitemap") { set \$skip 1; }
	if (\$http_cookie ~* "comment_author|wordpress_logged_in|wp-postpass|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session") { set \$skip 1; }

	location = /xmlrpc.php { deny all; }
	location ~ /\\.(?!well-known) { deny all; }
	location ~* /wp-content/uploads/.*\\.php\$ { deny all; }

	location / { try_files \$uri \$uri/ /index.php?\$args; }

	location ~ \\.php\$ {
		include snippets/fastcgi-php.conf;
		fastcgi_pass unix:/run/php/php8.3-fpm.sock;
		fastcgi_cache ISA;
		fastcgi_cache_valid 200 301 10m;
		fastcgi_cache_bypass \$skip;
		fastcgi_no_cache \$skip;
		fastcgi_cache_use_stale error timeout updating;
		add_header X-Isa-Cache \$upstream_cache_status;
	}

	location ~* \\.(css|js|woff2?|ttf|svg|png|jpe?g|gif|webp|avif|ico)\$ {
		expires 30d;
		access_log off;
		add_header Cache-Control "public";
		try_files \$uri =404;
	}
}
NGX
ln -sfn /etc/nginx/sites-available/isa /etc/nginx/sites-enabled/isa
rm -f /etc/nginx/sites-enabled/default
nginx -t -q && systemctl reload nginx

# ------------------------------------------------------------------ cron + backups
say "Cron + backups"
mkdir -p /var/backups/isa
cat > /usr/local/bin/isa-backup <<'SH'
#!/usr/bin/env bash
# Daily: DB dump + uploads archive, keep 7 days.
set -e
export PATH=/usr/local/bin:/usr/bin:/bin
d=$(date +%F)
sudo -u www-data wp --path=/var/www/isa db export - --quiet | gzip > /var/backups/isa/db-$d.sql.gz
tar -czf /var/backups/isa/uploads-$d.tar.gz -C /var/www/isa/wp-content uploads
find /var/backups/isa -type f -mtime +7 -delete
SH
chmod +x /usr/local/bin/isa-backup
cat > /etc/cron.d/isa <<'CRON'
*/5 * * * * www-data /usr/local/bin/wp --path=/var/www/isa cron event run --due-now --quiet >/dev/null 2>&1
30 3 * * * root /usr/local/bin/isa-backup >/dev/null 2>&1
CRON

# ------------------------------------------------------------------ updates
dpkg-reconfigure -f noninteractive unattended-upgrades >/dev/null 2>&1 || true

# ------------------------------------------------------------------ hardening
bash "$REPO/server/harden.sh"

say "Provisioned. Next: copy the site data (server/push-site.sh from your Mac), then TLS."
