FROM 812402538357.dkr.ecr.eu-west-1.amazonaws.com/build:0.7
COPY . /var/sosure
CMD ["/var/sosure/build/run-test-docker.sh"]
