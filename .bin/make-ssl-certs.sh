!#/bin/sh

if [ -f .env ]
then
  echo "Source all environment variables from .env file..."
  set -o allexport
  source .env
  set +o allexport
else
  echo "Cannot find the .env file. Exiting..."
  exit 1
fi

openssl req -x509 -nodes -newkey rsa:2048 -sha256 \
 -keyout /ssl/localhost.crt \
 -out /ssl/localhost.key \
 -subj "/CN=${APP_HOST}" \
 -days 365 -subj "/CN=${APP_HOST}"
