#!/bin/bash
if [ "$1" == "" ]; then
  echo "Usage: $0 swapsize_mb"
  exit 1
fi

REQUESTED_SWAP_SIZE=$1
CURRENT_SWAP_SIZE=`free -m | grep Swap | awk '{print $2}'`
MEM_SIZE=`free -m | grep Mem | awk ' { print $2 }'`

if [ "$CURRENT_SWAP_SIZE" -lt "$REQUESTED_SWAP_SIZE" ]; then
  FILE_SWAP_SIZE=`expr $REQUESTED_SWAP_SIZE - $CURRENT_SWAP_SIZE`
  echo "Creating swap of $FILE_SWAP_SIZE mb"
  SWAP_FILE=/mnt/swap-$FILE_SWAP_SIZE
  if [ -f $SWAP_FILE ]; then
    SWAP_FILE=$SWAP_FILE".1"
  fi
  sudo /bin/dd if=/dev/zero of=$SWAP_FILE bs=1M count=$FILE_SWAP_SIZE
  sudo /sbin/mkswap $SWAP_FILE
  sudo /sbin/swapon $SWAP_FILE
else
  echo "Swap already has $CURRENT_SWAP_SIZE MB - more than the requested $REQUESTED_SWAP_SIZE MB"
fi

