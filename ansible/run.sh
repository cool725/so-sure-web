#!/bin/bash
REFRESH=""
DEBUG_OPTION="-v"
DEPLOY=""
AWS_PROFILE="sosure"
while getopts "rhdspP:" opt; do
  case $opt in
    P)
      AWS_PROFILE=$OPTARG
      ;;
    p)
      DEPLOY="1"
      ;;
    d)
      DEBUG_OPTION="-vvvv"
      ;;
    r)
      REFRESH="1"
      ;;
    h)
      echo "$0 [-P aws profile=$AWS_PROFILE] [-p Prod deploy] [-s show] [-i index=$INDEX] [-r refresh-cache] playbook_to_run.yml"
      exit 3
      ;;
    \?)
      echo "Invalid option: -$OPTARG" >&2
      ;;
  esac
done
shift $(($OPTIND -1))

if [ "$1" == "" ]; then
  echo "Missing playbook. See $0 -h"
  exit 1
fi

if [ "$REFRESH" == "1" ]; then
  AWS_PROFILE=$AWS_PROFILE /etc/ansible/hosts --refresh-cache
fi

AWS_PROFILE=$AWS_PROFILE ansible-playbook $1 $DEBUG_OPTION
#--private-key=~/keys/laptop-urg.pem

if [ "$DEPLOY" == "1" ]; then
#ACCESS_TOKEN=""
ENVIRONMENT=production
LOCAL_USERNAME=`whoami`
REVISION=`git log -n 1 --pretty=format:"%H"`

curl https://api.rollbar.com/api/1/deploy/ \
  -F access_token=$ACCESS_TOKEN \
  -F environment=$ENVIRONMENT \
  -F revision=$REVISION \
  -F local_username=$LOCAL_USERNAME

fi


