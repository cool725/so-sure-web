#!/bin/bash

echo "Running: $0 $@"

set -e # Exit on error

DELETE_VENDORS=0
USER_OWNER=www-data
GROUP_OWNER=www-data
HISTORY=""
SET_OWNER=1
# Default composer timeout is 300
COMPOSER_TIMEOUT=300
while getopts "hndu:g:l:t:" opt; do
  case $opt in
    n)
      SET_OWNER=0
      ;;
    d)
      DELETE_VENDORS=1
      ;;
    l)
      HISTORY=$OPTARG
      ;;
    u)
      USER_OWNER=$OPTARG
      ;;
    g)
      GROUP_OWNER=$OPTARG
      ;;
    t)
      COMPOSER_TIMEOUT=$OPTARG
      ;;
    h)
      echo "Usage: $0 [-t composer timeout=$COMPOSER_TIMEOUT] [-d delete vendors folder] [-l history log] [-n Skip setting owner/group] [-u user=$USER_OWNER] [-g group=$GROUP_OWNER] clone_dir "
      exit 3
      ;;
    \?)
      echo "Invalid option: -$OPTARG" >&2
      ;;
  esac
done
shift $(($OPTIND -1))

CLONE_DIR=$1

if [ "$CLONE_DIR" == "" ]; then
    echo "Error: See $0 -h for usage"
    exit 1
fi

cd $CLONE_DIR


# Should we delete the vendors (when the repo changes)
if [ "$DELETE_VENDORS" == "1" ] ; then
    echo "Removing vendors folder"
    rm -rf vendor/*
fi

VENDOR_TIME_LOG=/tmp/peerj-vendor-time-log
# Bad bundle - returns error code when removing
if [ -d vendor/eloquent/composer-npm-bridge ]; then
  set +e
  composer install
  set -e
fi

# Just in case someone manually modifies a vendor bundle on the server, always discard changes
# Although technically it should be a 1 time set and forget, its less like to be overlooked if this is put here next to where its being used
composer config --global discard-changes true

# Set correct owner for the ~/.composer folder (need to account for sudo)
CALLING_USER=`who -m | awk '{print $1;}'`
if [ "$CALLING_USER" == "" ]; then
  # for vagrant, CALLING_USER isn't set during provisioning
  CALLING_USER=$USER_OWNER
fi
CALLING_FOLDER=`eval echo "~$CALLING_USER"`
if [ -d $CALLING_FOLDER/.composer ]; then
  echo "Changing ownership of $CALLING_FOLDER/.composer to $CALLING_USER"
  chown -R $CALLING_USER $CALLING_FOLDER/.composer
fi

export COMPOSER_PROCESS_TIMEOUT=$COMPOSER_TIMEOUT

/usr/bin/time -v HOME=$CALLING_FOLDER composer install --no-interaction --optimize-autoloader --profile 2>&1 | tee $VENDOR_TIME_LOG
cat $VENDOR_TIME_LOG
if [ "$HISTORY" != "" ]; then
    grep "Elapsed (wall clock) time" $VENDOR_TIME_LOG | awk '{ print "Vendor Elapsed Time: " $8 };' >> $HISTORY
fi

# Need to be able to not set for vagrant
if [ "$SET_OWNER" == "1" ]; then
  chown -R $USER_OWNER:$GROUP_OWNER vendor
fi


