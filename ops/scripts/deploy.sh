#!/bin/bash

echo "Running: $0 $@"

set -e # Exit on error
USER_OWNER=www-data
COMPOSER=1
SET_OWNER_OPTION=""
while getopts "hu:Cn" opt; do
  case $opt in
    n)
      SET_OWNER_OPTION="-n"
      ;;
    C)
      COMPOSER=0
      ;;
    u)
      USER_OWNER=$OPTARG
      ;;
    h)
      echo "Usage: $0 [-u user=$USER_OWNER] [-C skip composer install] [-n Skip setting owner/group] clone_dir environment"
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
  ./install-vendors.sh -u "$USER_OWNER" $SET_OWNER_OPTION $CLONE_DIR $ENVIRONMENT
fi
./create-folders.sh -u "$USER_OWNER" $SET_OWNER_OPTION $CLONE_DIR $ENVIRONMENT
./clear-cache.sh $CLONE_DIR $ENVIRONMENT
./run-assetic.sh $CLONE_DIR $ENVIRONMENT
