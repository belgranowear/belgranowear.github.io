#!/bin/bash

set -e; # quit on error

composer install;

php application app:update-availability-options;
php application app:update-schedule;
php application app:update-train-stations-map;
php application app:update-holidays-list;
php application app:update-hash-list;