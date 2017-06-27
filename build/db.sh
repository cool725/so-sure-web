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
      echo "Usage: $0 [-e environment=$ENV] AdditionsFolder"
      ;;
  esac
done
shift $((OPTIND-1))
if [ "$1" == "" ]; then
  echo "Usage $0 -h"
  exit 1
fi
FOLDER=src/AppBundle/DataFixtures/MongoDB/b/Phone/$1

if [ ! -d $FOLDER ]; then
    echo "Unable to find $FOLDER"
    exit 1
fi

echo "Reloading db for $ENV environment"
sudo -u www-data app/console --env=$ENV doctrine:mongodb:fixtures:load  --append --fixtures=$FOLDER
