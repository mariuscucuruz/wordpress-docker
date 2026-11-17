#!/bin/bash

CURRENT_DIR="$(dirname "$(readlink -f "$0")")"
APP_ROOT_DIR="${CURRENT_DIR}/../../"

if [ -f "${APP_ROOT_DIR}/.env" ]; then
  echo "Source all environment variables from .env file..."
  set -o allexport
  source .env
  set +o allexport
else
  echo "Cannot find the .env file. Exiting..."
  exit 1
fi

docker exec database /usr/bin/mysqlcheck -u root --password=${MYSQL_ROOT_PASSWORD} --auto-repair --check --analyze ${DB_NAME}

docker exec database /usr/bin/mysql -u root --password=${MYSQL_ROOT_PASSWORD} \
 -e "SELECT CONCAT('CHECK TABLE ', table_name, ';') FROM information_schema.tables WHERE table_schema = '${DB_NAME}';" -sN \
 -e "SELECT CONCAT('ANALYZE TABLE ', table_name, ';') FROM information_schema.tables WHERE table_schema = '${DB_NAME}';" -sN \
 -e "SELECT CONCAT('OPTIMIZE TABLE ', table_name, ';') FROM information_schema.tables WHERE table_schema = '${DB_NAME}';" -sN \
 -e "SELECT CONCAT('REPAIR TABLE ', table_name, ';') FROM information_schema.tables WHERE table_schema = '${DB_NAME}';" -sN

docker exec database /usr/bin/mysqldump -u root --password=${MYSQL_ROOT_PASSWORD} ${DB_NAME} \
 --single-transaction --set-gtid-purged=OFF  > db/backup-$(date +%F).sql

