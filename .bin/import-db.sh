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

DB_FILE="${APP_ROOT_DIR}/db/backups/backup-xxx.sql"

if [ -f "${DB_FILE}" ]; then
  docker exec -i database /usr/bin/mysql -u root --password=${MYSQL_ROOT_PASSWORD} "${DB_NAME}" < "${DB_FILE}"
else
  echo "Cannot find the DB file. Exiting..."
  exit 1
fi

echo "Data imported from: ${DB_FILE}"
echo "Data pushed to: ${APP_PREFIX}db/${DB_NAME}."
sleep 3
