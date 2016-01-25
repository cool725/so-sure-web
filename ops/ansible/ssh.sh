#!/bin/bash

LAUNCH_GROUP="security_group_launch_sosure"
RUN_GROUP="tag_aws_autoscaling_groupName_sosure"
INDEX=0
REFRESH_CACHE_OPTION=""
SHOW=""
AWS_PROFILE="sosure"
while getopts "i:rhsp:" opt; do
  case $opt in
    s)
      SHOW=1
      ;;
    P)
      AWS_PROFILE=$OPTARG
      ;;
    i)
      INDEX=$OPTARG
      ;;
    r)
      REFRESH_CACHE_OPTION="--refresh-cache"
      ;;
    h)
      echo "$0 [-P aws profile=$AWS_PROFILE] [-s show] [-i index=$INDEX] [-r refresh-cache] run|launch|cron"
      exit 3
      ;;
    \?)
      echo "Invalid option: -$OPTARG" >&2
      ;;
  esac
done

shift $(($OPTIND -1))

if [ "$1" == "run" ]; then
  GROUP=$RUN_GROUP
elif [ "$1" == "launch" ]; then
  GROUP=$LAUNCH_GROUP
else
  echo "Invalid group - see $0 -h"
  exit 1
fi

SSH_SERVER=`AWS_PROFILE=$AWS_PROFILE /etc/ansible/hosts $REFRESH_CACHE_OPTION | python -c 'import json,sys;obj=json.load(sys.stdin);print obj["'$GROUP'"]['$INDEX']'`
if [ "$SHOW" == "1" ]; then
  /etc/ansible/hosts
fi
echo "Connecting to $SSH_SERVER ($GROUP)"
ssh ubuntu@$SSH_SERVER
