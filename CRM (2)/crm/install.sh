#!/usr/bin/env bash
# =====================================================================
#  Автоустановка CRM в чистый контейнер Ubuntu 22.04 / 24.04 (Proxmox LXC)
#
#  Установка:   bash install.sh crm.вашдомен.com admin@вашдомен.com
#  Без домена:  bash install.sh            (откроется по http://IP — только для теста)
#  Обновление:  bash install.sh --update   (заменит файлы, база и config.php не трогаются)
#
#  Доступ только с нужных IP (по желанию):
#     ALLOW_IPS="1.2.3.4 5.6.7.8" bash install.sh crm.вашдомен.com admin@вашдомен.com
# =====================================================================
set -euo pipefail

APP_DIR=/var/www/crm
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DIR=/var/backups/crm
CRED_FILE=/root/crm-credentials.txt

c_ok()   { echo -e "\e[32m✔ $*\e[0m"; }
c_info() { echo -e "\e[36m➜ $*\e[0m"; }
c_warn() { echo -e "\e[33m! $*\e[0m"; }
die()    { echo -e "\e[31m✖ $*\e[0m" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Запустите от root."
[[ -f "$SRC_DIR/index.php" && -f "$SRC_DIR/inc/schema.sql" ]] || die "Скрипт должен лежать в папке с файлами CRM."

copy_files() {
    mkdir -p "$APP_DIR"
    rsync -a --delete \
        --exclude 'config.php' --exclude 'install.lock' \
        --exclude 'install.sh' --exclude 'README.md' \
        "$SRC_DIR"/ "$APP_DIR"/
    chown -R root:www-data "$APP_DIR"
    find "$APP_DIR" -type d -exec chmod 750 {} \;
    find "$APP_DIR" -type f -exec chmod 640 {} \;
}

# ------------------------- Режим обновления -------------------------
if [[ "${1:-}" == "--update" ]]; then
    [[ -f "$APP_DIR/config.php" ]] || die "CRM ещё не установлена в $APP_DIR."
    c_info "Бэкап базы перед обновлением…"
    /etc/cron.daily/crm-backup || c_warn "Бэкап не удался — продолжаю."
    copy_files
    rm -f "$APP_DIR/install.php" "$APP_DIR/install_cli.php"
    # новые таблицы/поля (CREATE TABLE IF NOT EXISTS — существующие данные не трогает)
    DBN=$(php -r '$c=require "'"$APP_DIR"'/config.php"; echo $c["db_name"];')
    mysql "$DBN" < "$APP_DIR/inc/schema.sql"
    systemctl reload nginx
    c_ok "Обновлено."
    exit 0
fi

# ------------------------- Установка -------------------------
[[ -f "$APP_DIR/install.lock" ]] && die "CRM уже установлена. Для обновления: bash install.sh --update"

DOMAIN="${1:-}"
EMAIL="${2:-}"
if [[ -n "$DOMAIN" && -z "$EMAIL" ]]; then die "Укажите email для сертификата: bash install.sh $DOMAIN admin@$DOMAIN"; fi

echo
echo "=== Главный администратор CRM ==="
read -rp "Имя (как будет видно в CRM) [Администратор]: " ADMIN_NAME
ADMIN_NAME=${ADMIN_NAME:-Администратор}
read -rp "Логин [admin]: " ADMIN_LOGIN
ADMIN_LOGIN=${ADMIN_LOGIN:-admin}
[[ "$ADMIN_LOGIN" =~ ^[a-zA-Z0-9_.-]{3,64}$ ]] || die "Логин: 3–64 символа, латиница, цифры, _ . -"
read -rsp "Пароль (Enter — сгенерировать): " ADMIN_PASS; echo
if [[ -z "$ADMIN_PASS" ]]; then
    ADMIN_PASS=$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 14); GEN_PASS=1
fi
[[ ${#ADMIN_PASS} -ge 8 ]] || die "Пароль — минимум 8 символов."

c_info "Устанавливаю пакеты (nginx, MariaDB, PHP)…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nginx mariadb-server php-fpm php-mysql php-mbstring php-cli rsync curl ca-certificates >/dev/null
[[ -n "$DOMAIN" ]] && apt-get install -y -qq certbot python3-certbot-nginx >/dev/null
systemctl enable --now mariadb nginx >/dev/null

PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
FPM_SOCK="/run/php/php${PHPV}-fpm.sock"
systemctl enable --now "php${PHPV}-fpm" >/dev/null
c_ok "PHP $PHPV, MariaDB, nginx"

# PHP: часовой пояс, лимиты, без версии в заголовках
cat > "/etc/php/${PHPV}/fpm/conf.d/90-crm.ini" <<EOF
date.timezone = Europe/Berlin
expose_php = Off
upload_max_filesize = 20M
post_max_size = 25M
memory_limit = 256M
session.cookie_httponly = 1
session.use_strict_mode = 1
EOF
systemctl restart "php${PHPV}-fpm"

c_info "Создаю базу данных…"
DB_NAME=crm
DB_USER=crm
DB_PASS=$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24)
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
DELETE FROM mysql.user WHERE User='';
DROP DATABASE IF EXISTS test;
FLUSH PRIVILEGES;
SQL
# MariaDB слушает только локально
sed -i 's/^bind-address.*/bind-address = 127.0.0.1/' /etc/mysql/mariadb.conf.d/50-server.cnf 2>/dev/null || true
systemctl restart mariadb
c_ok "База $DB_NAME"

c_info "Копирую файлы в $APP_DIR…"
copy_files
php "$APP_DIR/install_cli.php" 127.0.0.1 "$DB_NAME" "$DB_USER" "$DB_PASS" "$ADMIN_LOGIN" "$ADMIN_NAME" "$ADMIN_PASS"
chown root:www-data "$APP_DIR/config.php" "$APP_DIR/install.lock"
chmod 640 "$APP_DIR/config.php"
rm -f "$APP_DIR/install.php" "$APP_DIR/install_cli.php"
c_ok "CRM установлена, установщик удалён"

c_info "Настраиваю nginx…"
ALLOW_BLOCK=""
if [[ -n "${ALLOW_IPS:-}" ]]; then
    for ip in $ALLOW_IPS; do ALLOW_BLOCK+="    allow $ip;"$'\n'; done
    ALLOW_BLOCK+="    deny all;"
fi
SERVER_NAME=${DOMAIN:-_}
cat > /etc/nginx/sites-available/crm <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $SERVER_NAME;
    root $APP_DIR;
    index index.php;
    client_max_body_size 25m;
    server_tokens off;

$ALLOW_BLOCK

    add_header X-Frame-Options SAMEORIGIN always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy same-origin always;

    location ~ /\. { deny all; }
    location ~ ^/(inc|config\.php|config\.sample\.php|install\.lock|install_cli\.php|README\.md|install\.sh) { deny all; return 404; }
    location /assets/ { expires 7d; access_log off; }
    location / { try_files \$uri \$uri/ =404; }
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$FPM_SOCK;
    }
}
EOF
ln -sf /etc/nginx/sites-available/crm /etc/nginx/sites-enabled/crm
rm -f /etc/nginx/sites-enabled/default
nginx -t -q && systemctl reload nginx
c_ok "nginx"

if [[ -n "$DOMAIN" ]]; then
    c_info "Получаю HTTPS-сертификат для $DOMAIN…"
    if certbot --nginx -d "$DOMAIN" -m "$EMAIL" --agree-tos --non-interactive --redirect -q; then
        c_ok "HTTPS включён (сертификат продлевается автоматически)"
    else
        c_warn "Сертификат не получен. Проверьте, что A-запись $DOMAIN указывает на IP этого контейнера и порт 80 открыт,"
        c_warn "затем выполните: certbot --nginx -d $DOMAIN -m $EMAIL --agree-tos --redirect"
    fi
fi

c_info "Ежедневный бэкап базы…"
mkdir -p "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR"
cat > /etc/cron.daily/crm-backup <<EOF
#!/bin/sh
# Бэкап базы CRM, храним 14 дней
mysqldump --single-transaction $DB_NAME | gzip > $BACKUP_DIR/crm-\$(date +%F_%H%M).sql.gz
find $BACKUP_DIR -name 'crm-*.sql.gz' -mtime +14 -delete
EOF
chmod 700 /etc/cron.daily/crm-backup
/etc/cron.daily/crm-backup && c_ok "Бэкапы: $BACKUP_DIR (каждый день, 14 дней)"

IP=$(hostname -I | awk '{print $1}')
URL=${DOMAIN:+https://$DOMAIN}
URL=${URL:-http://$IP}
cat > "$CRED_FILE" <<EOF
CRM — данные установки ($(date '+%F %T'))
Адрес:          $URL
Админ логин:    $ADMIN_LOGIN
Админ пароль:   $ADMIN_PASS
База данных:    $DB_NAME / пользователь $DB_USER / пароль $DB_PASS
Файлы:          $APP_DIR
Бэкапы:         $BACKUP_DIR
EOF
chmod 600 "$CRED_FILE"

echo
c_ok "Готово!  Откройте: $URL"
echo "   Логин: $ADMIN_LOGIN"
[[ -n "${GEN_PASS:-}" ]] && echo "   Пароль: $ADMIN_PASS   (сгенерирован — смените после входа: ⚙ Настройки)"
echo "   Все данные сохранены в $CRED_FILE (доступ только root)."
[[ -z "$DOMAIN" ]] && c_warn "Без домена CRM работает по HTTP — для реальной работы подключите домен с HTTPS."
exit 0
