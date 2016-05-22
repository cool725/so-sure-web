#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR/..

IS_PRODUCTION=`grep mongodb_url app/config/parameters.yml | grep prod.so-sure.net | wc -l`
if [ "$IS_PRODUCTION" != "0" ]; then
    echo "This is a production server.  Do not run!"
    exit 1
else
    echo "Non-production server - safe to run"
fi

set -e

if [ -f  app/logs/test.log ]; then
  rm app/logs/test.log
fi

if [ -d /dev/shm/cache/test ]; then
  sudo rm -rf /dev/shm/cache/test/
fi

app/console --env=test redis:flushdb --client=default -n
app/console --env=test doctrine:mongodb:schema:drop
app/console --env=test doctrine:mongodb:fixtures:load --no-interaction
app/console --env=test sosure:doctrine:index
./vendor/phing/phing/bin/phing -f build/test.xml test:unit
if [ "$1" == "" ]; then
  ./vendor/phing/phing/bin/phing -f build/test.xml test:functional
else
  echo ./build/phpunit.sh --filter "$1" --bootstrap vendor/autoload.php src/AppBundle/    
  ./build/phpunit.sh --filter "$1" --bootstrap vendor/autoload.php src/AppBundle/    
fi
./vendor/phing/phing/bin/phing force:cs
