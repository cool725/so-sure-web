#!/bin/bash

echo "Running: $0 $@"

set -e # Exit on error

RUN_USER=www-data
WARMUP="1"
CACHE_DEBUG=0

while getopts "hr:w:d" opt; do
  case $opt in
    d)
      CACHE_DEBUG=1
      ;;
    r)
      RUN_USER=$OPTARG
      ;;
    w)
      WARMUP=$OPTARG
      ;;
    h)
      echo "Usage: $0 [-d cache debug mode] [-w cache warmup=$WARMUP] [-r user=$RUN_USER] clone_dir environment"
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

DEBUG_OPTION="--no-debug"
if [ "$CACHE_DEBUG" == "1" ]; then
  DEBUG_OPTION=""
fi

cd $CLONE_DIR

# manual file delete + cache:warmup is ~2x faster than cache:clear
rm -rf app/cache/$ENVIRONMENT

# May want to skip warmup for low-memory instances
if [ "$WARMUP" == "0" ]; then
    echo "Skipping cache warmup"
    exit 0
fi

set +e
sudo -u $RUN_USER /usr/bin/time -v env TERM=dumb php app/console $DEBUG_OPTION --env=$ENVIRONMENT cache:warmup
set -e