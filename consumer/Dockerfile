FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    librabbitmq-dev \
    libssl-dev \
    && pecl install amqp \
    && docker-php-ext-enable amqp \
    && apt-get clean

WORKDIR /app
COPY consumer.php .

CMD ["php", "consumer.php"]