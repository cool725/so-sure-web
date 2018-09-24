#!/bin/bash
TMP_FILE=/tmp/validate_urls.txt
RUN_FILE=/tmp/validate_urls.sh

cat > $RUN_FILE << EOF
#!/bin/bash
function url {
  URL=\$1
  curl -f -s --head \$URL > /dev/null
  if [ "\$?" != "0" ]; then
    echo "ERROR \$URL"
    exit 1
  fi
}
EOF

grep -R 'cdn_url ' src/ | awk -F 'cdn_url ' '{ print $2 }' | awk -F '"' '{print $1}' | sed 's/\}\}/https:\/\/cdn.so-sure.com/g' > $TMP_FILE
grep -R 'cdn_url}}' src/ | awk -F 'cdn_url}}' '{ print $2 }' | awk -F '"' '{print $1}' | sed 's/\}\}/https:\/\/cdn.so-sure.com/g' >> $TMP_FILE
grep -R 'cdn_url ' src/ | grep '~' | awk -F 'cdn_url ' '{ print $2 }' | awk -F "'" '{print $2}' | awk -F '"' '{print $1}' >> $TMP_FILE

grep -R 'cdn_url ' app/Resources/ | awk -F 'cdn_url ' '{ print $2 }' | awk -F '"' '{print $1}' | sed 's/\}\}/https:\/\/cdn.so-sure.com/g' >> $TMP_FILE
grep -R 'cdn_url}}' app/Resources/ | awk -F 'cdn_url}}' '{ print $2 }' | awk -F '"' '{print $1}' | sed 's/\}\}/https:\/\/cdn.so-sure.com/g' >> $TMP_FILE
grep -R 'cdn_url ' app/Resources/ | grep '~' | awk -F 'cdn_url ' '{ print $2 }' | awk -F "'" '{print $2}' | awk -F '"' '{print $1}'  >> $TMP_FILE

cat $TMP_FILE | uniq | grep 'cdn' | grep -v '{{' | awk -F '_' '{print "url \""$0"\""}' >> $RUN_FILE

chmod 755 $RUN_FILE
/bin/bash $RUN_FILE

