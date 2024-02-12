#!/bin/bash

set -e; # quit on error

composer install;

php application app:update-availability-options;
php application app:update-schedule;