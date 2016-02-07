#!/bin/bash

echo "Running: $0 $@"

set -e # Exit on error
USER_OWNER=www-data
COMPOSER=1
while getopts "hu:C" opt; do
  case $opt in
    C)
      COMPOSER=0
      ;;
    u)
      USER_OWNER=$OPTARG
      ;;
    h)
      echo "Usage: $0 [-u user=$USER_OWNER] [-C skip composer install] clone_dir environment"
      exit 3
      ;;
    \?)
      echo "Invalid option: -$OPTARG" >&2
      ;;
  esac
done
shift $(($OPTIND -1))

CLONE_DIR=$1
ENVIRONMENT=$2

if [ "$ENVIRONMENT" == "" ]; then
    echo "Error: See $0 -h for usage"
    exit 1
fi

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR

if [ "$COMPOSER" == "1" ]; then
  ./install-vendors.sh $CLONE_DIR $ENVIRONMENT
fi
./create-folders.sh -u "$USER_OWNER" $CLONE_DIR $ENVIRONMENT
./clear-cache.sh $CLONE_DIR $ENVIRONMENT
./run-assetic.sh $CLONE_DIR $ENVIRONMENT
