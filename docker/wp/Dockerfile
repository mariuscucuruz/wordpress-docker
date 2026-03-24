FROM wordpress:6.9-php8.3-fpm as base

RUN apt-get update && apt-get install -yq --no-install-recommends \
    less zip exif git imagemagick perl \
    build-essential pkg-config \
    libzip-dev zlib1g-dev libreadline-dev libsqlite3-dev \
    libcurl4-openssl-dev libssl-dev wget ca-certificates \
    libicu-dev libonig-dev \
    libjpeg62-turbo-dev libpng-dev libwebp-dev libfreetype6-dev \
    libcairo2-dev libpango1.0-dev libgif-dev librsvg2-dev \
    libmagickwand-dev \
 && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure pcntl --enable-pcntl && docker-php-ext-install -j"$(nproc)" pcntl
RUN docker-php-ext-install -j"$(nproc)" bcmath sockets pdo pdo_mysql opcache exif zip intl
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && docker-php-ext-install -j"$(nproc)" gd

RUN cp /usr/local/etc/php/php.ini-development /usr/local/etc/php/php.ini
# RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini
COPY docker/php/custom.ini /usr/local/etc/php/conf.d/docker-php.ini
COPY --from=wordpress:cli-php8.3 /usr/local/bin/wp /usr/local/bin/wp
COPY --from=composer /usr/bin/composer /usr/bin/composer

ARG UID
ARG GID
ARG WEBUSER
ARG WEBGROUP

ARG WEBUSER=${WEBUSER:-phpfpm}
ARG WEBGROUP=${WEBGROUP:-phpfmp}
ARG UID=${UID:-1000}
ARG GID=${GID:-1000}

# create matching user and group
RUN groupadd --force -g ${GID} ${WEBGROUP}
RUN useradd -ms /bin/bash --no-user-group -g ${WEBGROUP} -u ${UID} -m ${WEBUSER} && \
    usermod -aG ${WEBUSER} ${WEBGROUP}

# update PHP-FPM user
RUN sed -ri -e "s!user = www-data!user = ${WEBUSER}!g" /usr/local/etc/php-fpm.d/www.conf && \
    sed -ri -e "s!group = www-data!group = ${WEBGROUP}!g" /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

RUN chown -R ${WEBUSER}:${WEBGROUP} /var/www/html

RUN  wp cli cache clear --allow-root \
  && wp cli update --allow-root \
  && wp cli cache prune --allow-root

RUN  wp core download --allow-root --quiet \
  && wp config create --allow-root --force --skip-check \
        --dbname=${DB_NAME:-wordpress} \
        --dbuser=${DB_USERNAME:-wpdbuser} \
        --dbpass=${DB_PASSWORD:-wpdbpassword} \
        --dbhost=${DB_HOST:-database} \
        --dbprefix=${WORDPRESS_TABLE_PREFIX:-wp_} \
        --locale=en_GB \      
  && wp cli cache clear --allow-root \
  && wp cli update --allow-root \
  && wp cli cache prune --allow-root


FROM base

COPY wp/ /var/www/html/wp-content/

RUN mkdir -m 777 -p /var/www/html/wp-content/uploads \
  && find /var/www/html -type f -exec chmod 664 {} + \
  && find /var/www/html -type d -exec chmod 755 {} +

WORKDIR /var/www/html

USER ${WEBUSER}

# # Can only update core and plugins because database may not available at build time
# wp core check-update && wp core update && wp core update-db && wp cache flush && wp cli cache clear && wp cli cache prune
# wp core install --url="${WORDPRESS_DOMAIN:-damimporter.localhost}" --title="${WP_SITE_TITLE:-WordPress}" --admin_user="${WP_SITE_USER:-admin}" --admin_password="${WP_SITE_PASSWORD:-password}" --admin_email="${WP_SITE_EMAIL:-admin@example.com}"

RUN echo "WordPress instance ready!"
