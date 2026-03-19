FROM wordpress:6.9-fpm

RUN apt-get update && apt-get install -yq --no-install-recommends less zip exif imagemagick
RUN cp /usr/local/etc/php/php.ini-development /usr/local/etc/php/php.ini
# RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini
COPY --from=wordpress:cli-php8.4 /usr/local/bin/wp /usr/local/bin/wp

ARG UID
ARG GID
ARG WEBUSER
ARG WEBGROUP

ARG WEBUSER=${WEBUSER:-phpfpm}
ARG WEBGROUP=${WEBGROUP:-phpfmp}
ARG UID=${UID:-1000}
ARG GID=${GID:-1000}

# create matching user and group
RUN addgroup --gid ${GID} --system ${WEBUSER}
RUN adduser --disabled-password --gecos '' --uid ${UID} --ingroup ${WEBGROUP} ${WEBUSER}
RUN chown -R ${WEBUSER}:${WEBGROUP} .

# update PHP-FPM user
RUN sed -ri -e "s!user = www-data!user = ${WEBUSER}!g" /usr/local/etc/php-fpm.d/www.conf
RUN sed -ri -e "s!group = www-data!group = ${WEBGROUP}!g" /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

RUN wp cli cache clear --allow-root && wp cli update --allow-root
RUN wp cli cache prune --allow-root

# # Can only update core and plugins because database is not available at build time
# RUN wp core update --allow-root && wp core update-db --allow-root
# RUN wp cache flush --allow-root
# RUN wp core check-update --allow-root

RUN echo "WordPress instance ready!"
