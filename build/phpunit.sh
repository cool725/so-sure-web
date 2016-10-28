#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR/..
php vendor/phpunit/phpunit/phpunit --configuration app/phpunit.xml.dist $@
