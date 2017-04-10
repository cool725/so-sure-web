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

echo "Upserting feature flags for $ENV environment"
sudo app/console --env=$ENV doctrine:mongodb:fixtures:load --no-interaction --fixtures=src/AppBundle/DataFixtures/MongoDB/d/Feature --append
