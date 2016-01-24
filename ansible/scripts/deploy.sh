#!/bin/bash

echo "Running: $0 $@"

set -e # Exit on error

while getopts "h" opt; do
  case $opt in
    h)
      echo "Usage: $0 clone_dir environment"
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

./install-vendors.sh $CLONE_DIR $ENVIRONMENT
./create-folders.sh $CLONE_DIR $ENVIRONMENT
./clear-cache.sh $CLONE_DIR $ENVIRONMENT
./run-assetic.sh $CLONE_DIR $ENVIRONMENT