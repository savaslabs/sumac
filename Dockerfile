FROM composer/composer:1.1-alpine
MAINTAINER Kosta Harlan <kosta@savaslabs.com>

COPY . /usr/src/sumac
WORKDIR /usr/src/sumac

RUN composer install -n --prefer-dist --working-dir=/usr/src/sumac

ENTRYPOINT ["php", "sumac.php"]
