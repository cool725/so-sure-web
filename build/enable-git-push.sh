#!/bin/bash
if [ -f .git/hooks/pre-push ]; then
  echo "Pre push hook already exists. Exiting"
  exit 0
fi

cat > .git/hooks/pre-push << EOF
#!/bin/bash

set -e

./build/phing.sh force:cs
./build/run-phpstan.sh

protected_branch='master'
current_branch=$(git symbolic-ref HEAD | sed -e 's,.*/\(.*\),\1,')

if [ $protected_branch = $current_branch ]
then
    echo "${protected_branch} is a protected branch, create PR to merge"
    exit 1 # push will not execute
else
    exit 0 # push will execute
fi

chmod 755 .git/hooks/pre-push
