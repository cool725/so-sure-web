#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR/..
php -d memory_limit=768M ./vendor/phpstan/phpstan/bin/phpstan analyse -c phpstan.neon -l 7 src
