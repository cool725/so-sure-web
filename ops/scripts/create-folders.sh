#!/bin/bash

echo "Running: $0 $@"

set -e # Exit on error

USER_OWNER=www-data
GROUP_OWNER=www-data
SET_OWNER=1
while getopts "Phu:g:n" opt; do
  case $opt in
    n)
      SET_OWNER=0
      ;;
    P)
      PERMISSIONS=0
      ;;
    u)
      USER_OWNER=$OPTARG
      ;;
    g)
      GROUP_OWNER=$OPTARG
      ;;
    h)
      echo "Usage: $0 [-u user=$USER_OWNER] [-g group=$GROUP_OWNER] [-n Skip setting owner/group] clone_dir environment"
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

cd $CLONE_DIR

# create folders and update owner/permissions
if [ ! -d web/CACHE ]; then
  mkdir web/CACHE
fi
if [ ! -d web/css ]; then
  mkdir web/css 
fi
if [ ! -d web/js ]; then
  mkdir web/js 
fi
if [ ! -d app/cache ]; then
  mkdir app/cache 
fi
if [ ! -d app/cache/$ENVIRONMENT ]; then
  mkdir app/cache/$ENVIRONMENT
fi
if [ ! -d app/logs ]; then
  mkdir app/logs 
fi
if [ ! -d app/spool ]; then
  mkdir app/spool 
fi

# Apache needs execute permissions
chmod 775 -R app/cache app/cache/$ENVIRONMENT web/CACHE web/js app/logs app/spool web/css

if [ "$SET_OWNER" == "1" ]; then
  chown -R $USER_OWNER:$GROUP_OWNER .

  # http://symfony.com/doc/current/book/installation.html - Permissions
  if [ -f /usr/bin/setfacl ]; then
	setfacl -R -m u:$USER_OWNER:rwX -m u:root:rwX app/cache app/logs
	setfacl -dR -m u:$USER_OWNER:rwX -m u:root:rwX app/cache app/logs
  fi
fi
