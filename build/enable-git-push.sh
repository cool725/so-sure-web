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
EOF

chmod 755 .git/hooks/pre-push
