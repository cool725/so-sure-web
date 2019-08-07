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

SKIP_POLICY=1
SKIP_DB=0
SKIP_PHING=0
SKIP_FLUSH_REDIS=0
COVER=0
GROUP=""
FUNCTIONAL_TEST="test:functional"
DEBUG=""
while getopts ":snpdDhcClrg:" opt; do
  case $opt in
    s)
      SKIP_POLICY=0
      ;;
    d)
      SKIP_DB=1
      ;;
    D)
      DEBUG="--debug"
      ;;
    l)
      SKIP_PHING=1
      ;;
    r)
      SKIP_FLUSH_REDIS=1
      ;;
    c)
      FUNCTIONAL_TEST="test:functional:cover"
      COVER=1
      ;;
    C)
      FUNCTIONAL_TEST="test:functional:nonet:cover"
      COVER=1
      ;;
    g)
      GROUP="$OPTARG"
      ;;
    n)
      FUNCTIONAL_TEST="test:functional:nonet"
      ;;
    p)
      FUNCTIONAL_TEST="test:functional:paid"
      ;;
    m)
      FUNCTIONAL_TEST="test:functional:picsureml"
      ;;
    h)
      echo "Usage: $0 [-d skip db refresh] [-D debug] [-g=group test group to run] [-s populate sample policy data] [-n no network test | -p run paid test | -m picsure ml test | -c run coverage | -C run coverage no network] [-l keep logs (skip force:cs check)] [-r do not flush redis after running] [filter e.g. (::Method or namespace - use \\)"
      exit 1
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
  rm -rf /dev/shm/cache/test/
fi

function init {
SKIP_DB=$1
SKIP_POLICY=$2
if [ "$SKIP_DB" == "0" ]; then
  app/console --env=test redis:flushdb --client=default -n
  app/console --env=test doctrine:mongodb:schema:drop

  if [ "$SKIP_POLICY" == "0" ]; then
    app/console --env=test doctrine:mongodb:fixtures:load --no-interaction
  else
    app/console --env=test doctrine:mongodb:fixtures:load --no-interaction --fixtures src/AppBundle/DataFixtures/MongoDB/a/PolicyTerms
    app/console --env=test doctrine:mongodb:fixtures:load --no-interaction --fixtures src/AppBundle/DataFixtures/MongoDB/b/Phone --append
    app/console --env=test doctrine:mongodb:fixtures:load --no-interaction --fixtures src/AppBundle/DataFixtures/MongoDB/b/User --append
    app/console --env=test doctrine:mongodb:fixtures:load --no-interaction --fixtures src/AppBundle/DataFixtures/MongoDB/d/Feature --append
    app/console --env=test doctrine:mongodb:fixtures:load --no-interaction --fixtures=src/AppBundle/DataFixtures/MongoDB/d/Oauth2 --append
    app/console --env=test sosure:doctrine:index
  fi
fi

# keep in sync with load.sh
for feature in "renewal" "picsure" "bacs" "rate-limiting"
do
  app/console --env=test sosure:feature $feature true
done
}

init $SKIP_DB $SKIP_POLICY

if [ "$RUN_FILTER" == "" ]; then
    if [ "$COVER" == "0" ]; then
        # for some reason, some tests do not do work as expected unless run individually
        echo "Running test cases that need to be run individually :("
        ./build/phpunit.sh --filter "::testUserCreateNoChangeEmail" --bootstrap vendor/autoload.php src/
    
        echo "Wiping db again"
        init $SKIP_DB $SKIP_POLICY
    fi

    echo "Running test suite"
  ./vendor/phing/phing/bin/phing -f build/test.xml $FUNCTIONAL_TEST
else
  ./vendor/phing/phing/bin/phing -f build/test.xml test:unit
  if [ "$GROUP" == "" ]; then
    ./build/phpunit.sh $DEBUG --filter "$RUN_FILTER" --bootstrap vendor/autoload.php
  else
    ./build/phpunit.sh $DEBUG --filter "$RUN_FILTER" --bootstrap vendor/autoload.php --group "$GROUP"
  fi
fi
if [ "$SKIP_FLUSH_REDIS" == "0" ]; then
  echo "Flushing redis"
  app/console --env=test redis:flushdb -n
fi
if [ "$SKIP_PHING" == "0" ]; then
  ./vendor/phing/phing/bin/phing force:cs
  casperjs test /var/ops/scripts/monitor/casper/sosure.js --url="http://localhost"
fi
