FROM wordpress:7.1-php8.5-fpm

RUN apt-get update && apt-get install -y openssl zip exif imagemagick

COPY --from=wordpress:cli-php8.5 /usr/local/bin/wp /usr/local/bin/wp
COPY --from=composer /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER 1

# RUN apt-get update && apt-get install -y libmemcached-dev libssl-dev zlib1g-dev \
# 	&& pecl install memcached-3.2.0 \
# 	&& docker-php-ext-enable memcached

RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

WORKDIR /var/www/html

ARG WEBUSER=${WEBUSER:-mariux}
ARG WEBGROUP=${WEBGROUP:-mariux}
ARG UID=${UID:-1000}
ARG GID=${GID:-1000}

# create matching user and group
RUN groupadd -g ${GID} ${WEBGROUP}
RUN useradd -u ${UID} -g ${WEBGROUP} -m -s /bin/bash ${WEBUSER}
RUN chown -R ${WEBUSER}:${WEBGROUP} .

# update PHP-FPM user
RUN sed -ri -e "s!user = www-data!user = ${WEBUSER}!g" /usr/local/etc/php-fpm.d/www.conf
RUN sed -ri -e "s!group = www-data!group = ${WEBGROUP}!g" /usr/local/etc/php-fpm.d/www.conf

RUN echo "Front-End Ready!"

RUN wp cli cache clear --allow-root && wp cli update --allow-root
RUN wp cli cache prune --allow-root
#RUN wp core update --allow-root && wp core update-db --allow-root
#RUN wp cache flush --allow-root
#RUN wp core check-update --allow-root
#RUN wp language core update --allow-root && wp plugin --all upgrade --allow-root && wp theme --all upgrade --allow-root
