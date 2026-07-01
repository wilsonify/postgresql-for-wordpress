#!/usr/bin/env bash
set -e
find pg4wp/ -name '*.php' -exec php -l {} \;
find pg4wp/rewriters/ -name '*.php' -exec php -l {} \;
