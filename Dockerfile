FROM php:8.2

# Install the Composer PHP package manager
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Install git
RUN apt-get update && apt-get install -y --no-install-recommends git &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# Install unzip
RUN apt-get update && apt-get install -y --no-install-recommends unzip &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

WORKDIR /var/www

ENTRYPOINT [ "./entrypoint.sh" ]