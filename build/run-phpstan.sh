#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR/..
./vendor/phpstan/phpstan/bin/phpstan analyse -c phpstan.neon -l 4 src
