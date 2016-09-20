FROM php:5.6.26-cli
MAINTAINER Kosta Harlan <kosta@savaslabs.com>

RUN echo "date.timezone = \"America/New_York\"" > /usr/local/etc/php/php.ini
COPY . /usr/src/sumac
WORKDIR /usr/src/sumac

ENTRYPOINT ["php", "sumac.php"]
