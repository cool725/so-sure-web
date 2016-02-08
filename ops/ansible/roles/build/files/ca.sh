#!/bin/bash
set -e # Exit on error

DAYS=1024
KEY_FOLDER=/etc/keys/ssl
PROFILE_OPTION=""
while getopts "hp:d:k:" opt; do
  case $opt in
    p)
      PROFILE_OPTION="-p $OPTARG"
      ;;
    k)
      KEY_FOLDER=$OPTARG
      ;;
    d)
      DAYS=$OPTARG
      ;;
    h)
      echo "Usage: $0 [-p credstash profile] [-d days=$DAYS] [-k key_folder=$KEY_FOLDER] domain"
      exit 3
      ;;
    \?)
      echo "Invalid option: -$OPTARG" >&2
      ;;
  esac
done
shift $(($OPTIND -1))

DOMAIN=$1
if [ "$1" == "" ]; then
    echo "See $0 -h"
    exit 1
fi

if [ ! -d $KEY_FOLDER ]; then
  mkdir -p $KEY_FOLDER
fi

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

#CA_FILE=$KEY_FOLDER/SoSure-Root-CA.crt
CA_PEM_FILE=$KEY_FOLDER/SoSure-Root-CA.pem
CA_KEY_FILE=$KEY_FOLDER/SoSure-Root-CA.key

KEY_FILE=$KEY_FOLDER/$DOMAIN.key
CSR_FILE=$KEY_FOLDER/$DOMAIN.csr
CERT_FILE=$KEY_FOLDER/$DOMAIN.crt

# Credstash
echo "-----BEGIN RSA PRIVATE KEY-----" > $CA_KEY_FILE
credstash $PROFILE_OPTION get prod/server/root-ca-key | fold -w 64 >> $CA_KEY_FILE
echo "-----END RSA PRIVATE KEY-----" >> $CA_KEY_FILE

echo "-----BEGIN CERTIFICATE-----" > $CA_PEM_FILE
credstash $PROFILE_OPTION get prod/server/root-ca-pem | fold -w 64 >> $CA_PEM_FILE
echo "-----END CERTIFICATE-----" >> $CA_PEM_FILE

#openssl x509 -inform PEM -in $CA_PEM_FILE -out $CA_FILE -outform DES

# create local key
openssl genrsa -out $KEY_FILE 2048
# create csr
openssl req -new -days $DAYS -sha256 -key $KEY_FILE -out $CSR_FILE -subj "/C=UK/ST=England/L=London/O=So Sure/CN=$DOMAIN"
# sign csr
#openssl ca -batch -config $CONFIG_FILE -in $CSR_FILE -out $CERT_FILE
openssl x509 -sha256 -req -in $CSR_FILE -out $CERT_FILE -CA $CA_PEM_FILE -CAkey $CA_KEY_FILE -CAcreateserial -days $DAYS

rm $CA_KEY_FILE
rm $CA_PEM_FILE

