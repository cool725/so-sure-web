#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR/..

ENV="vagrant"
while getopts "e:h" opt; do
  case $opt in
    e)
      ENV=$OPTARG
      ;;
    h)
      echo "Usage: $0 [-e environment=$ENV]"
      ;;
  esac
done
shift $((OPTIND-1))

IS_PRODUCTION=`grep mongodb_url app/config/parameters.yml | grep prod.so-sure.net | wc -l`
if [ "$IS_PRODUCTION" != "0" ]; then
    echo "This is a production server.  Do not run!"
    exit 1
else
    echo "Non-production server - safe to run"
fi

echo "Reloading db for $ENV environment"
sudo app/console --env=$ENV doctrine:mongodb:fixtures:load --no-interaction --fixtures=src/AppBundle/DataFixtures/MongoDB/a
sudo app/console --env=$ENV doctrine:mongodb:fixtures:load --no-interaction --fixtures=src/AppBundle/DataFixtures/MongoDB/b --append
sudo app/console --env=$ENV doctrine:mongodb:fixtures:load --no-interaction --fixtures=src/AppBundle/DataFixtures/MongoDB/c --append
sudo app/console --env=$ENV doctrine:mongodb:fixtures:load --no-interaction --fixtures=src/AppBundle/DataFixtures/MongoDB/d --append
