#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR/..

ENV=""
while getopts "e:h" opt; do
  case $opt in
    e)
      ENV=$OPTARG
      ;;
    h)
      echo "Usage: $0 [-e prod|test|vagrant]"
      ;;
  esac
done
shift $((OPTIND-1))

if [ "$ENV" == "" ]; then
    echo "See $0 -h"
    exit 1
fi

# /c/ version of PlayDevice Fixtures will run upset on existing data, so safe to rerun
echo "sudo app/console --env=$ENV doctrine:mongodb:fixtures:load --append --fixtures src/AppBundle/DataFixtures/MongoDB/c/PlayDevice"
sudo app/console --env=$ENV doctrine:mongodb:fixtures:load --append --fixtures src/AppBundle/DataFixtures/MongoDB/c/PlayDevice
