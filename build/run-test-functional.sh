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

SKIP_POLICY=0
FUNCTIONAL_TEST="test:functional"
while getopts ":snph" opt; do
  case $opt in
    s)
      SKIP_POLICY=1
      ;;
    n)
      FUNCTIONAL_TEST="test:functional:nonet"
      ;;
    p)
      FUNCTIONAL_TEST="test:functional:paid"
      ;;
    h)
      echo "Usage: $0 [-s skip policy] [-n no network test | -p run paid test] [filter e.g. (::Method or namespace - use \\)"
      ;;
  esac
done
shift $((OPTIND-1))

RUN_FILTER=$1

set -e

if [ -f  app/logs/test.log ]; then
  rm app/logs/test.log
fi

if [ -d /dev/shm/cache/test ]; then
  sudo rm -rf /dev/shm/cache/test/
fi

app/console --env=test redis:flushdb --client=default -n
app/console --env=test doctrine:mongodb:schema:drop
if [ "$SKIP_POLICY" == "0" ]; then
  app/console --env=test doctrine:mongodb:fixtures:load --no-interaction
else
  app/console --env=test doctrine:mongodb:fixtures:load --no-interaction --fixtures src/AppBundle/DataFixtures/MongoDB/a/PolicyTerms
  app/console --env=test doctrine:mongodb:fixtures:load --no-interaction --fixtures src/AppBundle/DataFixtures/MongoDB/b/Phone --append
  app/console --env=test doctrine:mongodb:fixtures:load --no-interaction --fixtures src/AppBundle/DataFixtures/MongoDB/b/PlayDevice --append
  app/console --env=test doctrine:mongodb:fixtures:load --no-interaction --fixtures src/AppBundle/DataFixtures/MongoDB/b/User --append
  app/console --env=test sosure:doctrine:index
fi

./vendor/phing/phing/bin/phing -f build/test.xml test:unit
if [ "$RUN_FILTER" == "" ]; then
  ./vendor/phing/phing/bin/phing -f build/test.xml $FUNCTIONAL_TEST
else
  echo ./build/phpunit.sh --filter "$RUN_FILTER" --bootstrap vendor/autoload.php src/AppBundle/    
  ./build/phpunit.sh --filter "$RUN_FILTER" --bootstrap vendor/autoload.php src/AppBundle/    
fi
./vendor/phing/phing/bin/phing force:cs
