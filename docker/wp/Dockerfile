FROM wordpress:6.4-fpm

RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

WORKDIR /var/www/html

# ARG UID
# ARG GID
# ARG WEBUSER
# ARG WEBGROUP

# ARG WEBUSER=${WEBUSER:-phpfpm}
# ARG WEBGROUP=${WEBGROUP:-phpfmp}
# ARG UID=${UID:-1000}
# ARG GID=${GID:-1000}

# # create matching user and group
# RUN addgroup --gid ${GID} --system ${WEBUSER}
# RUN adduser --disabled-password --gecos '' --uid ${UID} --ingroup ${WEBGROUP} ${WEBUSER}
# RUN chown -R ${WEBUSER}:${WEBGROUP} .

# # update PHP-FPM user
# RUN sed -ri -e "s!user = www-data!user = ${WEBUSER}!g" /usr/local/etc/php-fpm.d/www.conf
# RUN sed -ri -e "s!group = www-data!group = ${WEBGROUP}!g" /usr/local/etc/php-fpm.d/www.conf

RUN echo "Front-End Ready!"
