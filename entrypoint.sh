#!/bin/bash

set -e; # quit on error

composer install;

mkdir -p storage/app;

cp -rfv docs/* storage/app;

php application app:update-availability-options;
php application app:update-schedule;
php application app:update-train-stations-map;
php application app:update-holidays-list;
php application app:update-hash-list;

cp -rfv storage/app/* docs/;