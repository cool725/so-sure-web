#!/bin/bash

echo "Running: $0 $@"

set -e # Exit on error


RUN_USER=www-data
ASSETIC_DEBUG=0
CLEAN=0
FORCE_ASSETIC=0
while getopts "hr:dcf" opt; do
  case $opt in
    c)
      CLEAN=1
      ;;
    d)
      ASSETIC_DEBUG=1
      ;;
    f)
      FORCE_ASSETIC=1
      ;;
    r)
      RUN_USER=$OPTARG
      ;;
    h)
      echo "Usage: $0 [-f force assetic dump] [-c clean up web/css and web/js before copy/run] [-d assetic debug mode] [-r user=$RUN_USER] clone_dir environment"
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
if [ "$ASSETIC_DEBUG" == "1" ]; then
  DEBUG_OPTION=""
fi

cd $CLONE_DIR

if [ "$CLEAN" == "1" ]; then
    CLEAN_FOLDERS="$CLONE_DIR/web/js $CLONE_DIR/web/css"
    for CLEAN_FOLDER in $CLEAN_FOLDERS
    do
        echo "Cleaning up $CLEAN_FOLDER (deleting all files)"
        if [ -d $CLEAN_FOLDER ]; then
            rm -rf $CLEAN_FOLDER/*
        fi
    done
fi

echo "Unable to locate assetic files from build"
sudo -u $RUN_USER env TERM=dumb php app/console $DEBUG_OPTION --env=$ENVIRONMENT assetic:dump 
