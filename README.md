# Get started

1. Copy `example.env` as `.env` and update contents as needed.

    Pay extra attention to `APP_PORT` and `APP_HOST`, as well as all the `APP_USER_*` variables.

3. Run `docker compose build` to build Docker images.

    If anything could go wrong, it would happen at this stage.

5. Run `docker compose up -d --force-recreate --build --remove-orphans` to start containers.

    Log into WordPress admin dashboard at `https://127.0.0.1:{APP_PORT}/wp-admin`.


## Notes

Because the directory `/wp/` will effectively become `/wp-content/` directory inside the WordPress container, any uploads, themes, and plugins you install could be versioned or ignored as needed (see `.gitignore` rules).

Update `image` names for `frontend` or `database` (in `docker-compose.yml` file) to build and push your own Docker images as needed.


### `WP-CLI` is available

Examples:

- `docker exec -it frontend bash -c 'wp core check-update --allow-root'`
- `docker exec -it frontend bash -c 'wp core update --allow-root && wp core update-db --allow-root'`
- `docker exec -it frontend bash -c 'wp language core update --allow-root && wp plugin --all upgrade --allow-root && wp theme --all upgrade --allow-root'`
- `docker exec -it frontend bash -c 'wp cache flush --allow-root'`


## Extra

- Run `./.bin/backup-db.sh` to dump a backup of the database to `db/bkp/{date}.sql` file.

- Run `./.bin/import-db.sh` to import to DB; ensure you understand and edit `.bin/import-db.sh` before running it.

- You'll need to mount `make-ssl-certs.sh` in a volume on the **proxy service** (`docker-compose.yml`), then run it inside the container to issue new self-signed SSL certificates for localhost.
