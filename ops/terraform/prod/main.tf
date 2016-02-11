variable "aws_region" {
  description = "AWS region to launch servers."
  default = "eu-west-1"
}

variable "aws_az_a" {
  description = "First AWS availability zone"
  default = "eu-west-1a"
}

variable "aws_az_b" {
  description = "Second AWS availability zone"
  default = "eu-west-1b"
}


# Specify the provider and access details
provider "aws" {
  region = "${var.aws_region}"
}

# Create a VPC to launch our instances into
resource "aws_vpc" "default" {
  cidr_block = "10.0.0.0/16"
}

# Create an internet gateway to give our subnet access to the outside world
resource "aws_internet_gateway" "default" {
  vpc_id = "${aws_vpc.default.id}"
}

# Grant the VPC internet access on its main route table
resource "aws_route" "internet_access" {
  route_table_id         = "${aws_vpc.default.main_route_table_id}"
  destination_cidr_block = "0.0.0.0/0"
  gateway_id             = "${aws_internet_gateway.default.id}"
}

# Create a subnet to launch our instances into
resource "aws_subnet" "dmz_a" {
  vpc_id                  = "${aws_vpc.default.id}"
  cidr_block              = "10.0.1.0/24"
  map_public_ip_on_launch = false
  availability_zone       = "${var.aws_az_a}"
}
resource "aws_subnet" "dmz_b" {
  vpc_id                  = "${aws_vpc.default.id}"
  cidr_block              = "10.0.2.0/24"
  map_public_ip_on_launch = false
  availability_zone       = "${var.aws_az_b}"
}

resource "aws_subnet" "dmz_pub_a" {
  vpc_id                  = "${aws_vpc.default.id}"
  cidr_block              = "10.0.21.0/24"
  map_public_ip_on_launch = true
  availability_zone       = "${var.aws_az_a}"
}
resource "aws_subnet" "dmz_pub_b" {
  vpc_id                  = "${aws_vpc.default.id}"
  cidr_block              = "10.0.22.0/24"
  map_public_ip_on_launch = true
  availability_zone       = "${var.aws_az_b}"
}

resource "aws_subnet" "db_a" {
  vpc_id                  = "${aws_vpc.default.id}"
  cidr_block              = "10.0.11.0/24"
  map_public_ip_on_launch = false
  availability_zone       = "${var.aws_az_a}"
}
resource "aws_subnet" "db_b" {
  vpc_id                  = "${aws_vpc.default.id}"
  cidr_block              = "10.0.12.0/24"
  map_public_ip_on_launch = false
  availability_zone       = "${var.aws_az_b}"
}

resource "aws_network_acl" "dmz" {
    vpc_id = "${aws_vpc.default.id}"
    subnet_ids = ["${aws_subnet.dmz_a.id}","${aws_subnet.dmz_b.id}"]
    
    # Standard rules
    ingress {
        protocol = "icmp"
        rule_no = 10
        action = "allow"
        cidr_block =  "10.0.0.0/16"
        from_port = 0
        to_port = 0
    }
    ingress {
        protocol = "tcp"
        rule_no = 20
        action = "allow"
        cidr_block =  "10.0.0.0/16"
        from_port = 22
        to_port = 22
    }
    ingress {
        protocol = "tcp"
        rule_no = 30
        action = "allow"
        cidr_block =  "0.0.0.0/0"
        from_port = 32768
        to_port = 61000
    }
    ingress {
        protocol = "udp"
        rule_no = 40
        action = "allow"
        cidr_block =  "0.0.0.0/0"
        from_port = 32768
        to_port = 61000
    }
    egress {
        protocol = -1
        rule_no = 10
        action = "allow"
        cidr_block =  "0.0.0.0/0"
        from_port = 0
        to_port = 0
    }

    # Custom rules
    ingress {
        protocol = "tcp"
        rule_no = 50
        action = "allow"
        cidr_block =  "0.0.0.0/0"
        from_port = 80
        to_port = 80
    }
    ingress {
        protocol = "tcp"
        rule_no = 60
        action = "allow"
        cidr_block =  "0.0.0.0/0"
        from_port = 443
        to_port = 443
    }
}

resource "aws_network_acl" "dmz_pub" {
    vpc_id = "${aws_vpc.default.id}"
    subnet_ids = ["${aws_subnet.dmz_pub_a.id}","${aws_subnet.dmz_pub_b.id}"]
    
    # Standard rules
    ingress {
        protocol = "icmp"
        rule_no = 10
        action = "allow"
        cidr_block =  "10.0.0.0/16"
        from_port = 0
        to_port = 0
    }
    ingress {
        protocol = "tcp"
        rule_no = 20
        action = "allow"
        cidr_block =  "0.0.0.0/0"
        from_port = 22
        to_port = 22
    }
    ingress {
        protocol = "tcp"
        rule_no = 30
        action = "allow"
        cidr_block =  "0.0.0.0/0"
        from_port = 32768
        to_port = 61000
    }
    ingress {
        protocol = "udp"
        rule_no = 40
        action = "allow"
        cidr_block =  "0.0.0.0/0"
        from_port = 32768
        to_port = 61000
    }
    egress {
        protocol = -1
        rule_no = 10
        action = "allow"
        cidr_block =  "0.0.0.0/0"
        from_port = 0
        to_port = 0
    }

    ingress {
        protocol = "tcp"
        rule_no = 60
        action = "allow"
        cidr_block =  "0.0.0.0/0"
        from_port = 443
        to_port = 443
    }
}


resource "aws_network_acl" "db" {
    vpc_id = "${aws_vpc.default.id}"
    subnet_ids = ["${aws_subnet.db_a.id}","${aws_subnet.db_b.id}"]
    
    # Standard rules
    ingress {
        protocol = "icmp"
        rule_no = 10
        action = "allow"
        cidr_block =  "10.0.0.0/16"
        from_port = 0
        to_port = 0
    }
    ingress {
        protocol = "tcp"
        rule_no = 20
        action = "allow"
        cidr_block =  "0.0.0.0/0"
        from_port = 22
        to_port = 22
    }
    ingress {
        protocol = "tcp"
        rule_no = 30
        action = "allow"
        cidr_block =  "0.0.0.0/0"
        from_port = 32768
        to_port = 61000
    }
    ingress {
        protocol = "udp"
        rule_no = 40
        action = "allow"
        cidr_block =  "0.0.0.0/0"
        from_port = 32768
        to_port = 61000
    }
    egress {
        protocol = -1
        rule_no = 10
        action = "allow"
        cidr_block =  "0.0.0.0/0"
        from_port = 0
        to_port = 0
    }

    # Custom rules
    ingress {
        protocol = "tcp"
        rule_no = 50
        action = "allow"
        cidr_block =  "10.0.0.0/16"
        from_port = 27017
        to_port = 27017
    }
}

# A security group for the ELB so it is accessible via the web
resource "aws_security_group" "elb" {
  name        = "prod_elb"
  description = "Production ELB"
  vpc_id      = "${aws_vpc.default.id}"

  # HTTP access from anywhere
  ingress {
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  # HTTP access from anywhere
  ingress {
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  # outbound internet access
  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }
}

# Our default security group to access
# the instances over SSH and HTTP
resource "aws_security_group" "web" {
  name        = "web_sg"
  description = "Web Server Security Group"
  vpc_id      = "${aws_vpc.default.id}"

  # SSH access from anywhere
  ingress {
    from_port   = 22
    to_port     = 22
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  # HTTP access from the VPC
  ingress {
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = ["10.0.0.0/16"]
  }

  # outbound internet access
  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }
}

resource "aws_security_group" "db" {
  name        = "db_sg"
  description = "MongoDb Server Security Group"
  vpc_id      = "${aws_vpc.default.id}"

  # SSH access from anywhere
  ingress {
    from_port   = 22
    to_port     = 22
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  # Mongodb access from the VPC
  ingress {
    from_port   = 27017
    to_port     = 27017
    protocol    = "tcp"
    cidr_blocks = ["10.0.0.0/16"]
  }

  # outbound internet access
  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }
}

resource "aws_security_group" "build" {
  name        = "build_sg"
  description = "Build Server Security Group"
  vpc_id      = "${aws_vpc.default.id}"

  # SSH access from anywhere
  ingress {
    from_port   = 22
    to_port     = 22
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  # HTTPS access from anywhere
  ingress {
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  # outbound internet access
  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }
}

resource "aws_elb" "web" {
  name = "prod-elb"

  subnets         = ["${aws_subnet.dmz_a.id}","${aws_subnet.dmz_b.id}"]
  security_groups = ["${aws_security_group.elb.id}"]

  listener {
    instance_port     = 80
    instance_protocol = "http"
    lb_port           = 80
    lb_protocol       = "http"
  }

  access_logs {
    bucket = "log.so-sure.com"
    bucket_prefix = "elb"
    interval = 60
  }
}

resource "aws_launch_configuration" "prod_web" {
    name_prefix = "web-v0-lc-"
    image_id = "ami-0b03b378"
    instance_type = "t2.micro"
    security_groups = ["${aws_security_group.web.id}"]
    iam_instance_profile = "prod-web"
    user_data = "#!/bin/bash\ncd /var/sosure/current\ngit pull origin master\ncd /var/sosure/current/ops/scripts\n./deploy.sh /var/sosure/current prod"
    associate_public_ip_address = false

    lifecycle {
      create_before_destroy = true
    }
}

resource "aws_autoscaling_group" "prod_web" {
    name = "web-v0-asg"
    launch_configuration = "${aws_launch_configuration.prod_web.name}"
    min_size = 1
    max_size = 1
    vpc_zone_identifier = ["${aws_subnet.dmz_a.id}","${aws_subnet.dmz_b.id}"]
    load_balancers =  ["${aws_elb.web.id}"]

    lifecycle {
      create_before_destroy = true
    }

    tag {
      key = "env"
      value = "prod"
      propagate_at_launch = true
    }

    tag {
      key = "role"
      value = "web"
      propagate_at_launch = true
    }
}

resource "aws_launch_configuration" "prod_db" {
    name_prefix = "db-v0-lc-"
    image_id = "ami-5101b122"
    instance_type = "t2.micro"
    security_groups = ["${aws_security_group.db.id}"]
    iam_instance_profile = "prod-db"
    user_data = "#!/bin/bash\n/usr/local/bin/tagged-route53.py so-sure.com"
    associate_public_ip_address = false

    lifecycle {
      create_before_destroy = true
    }
}

resource "aws_autoscaling_group" "prod_db" {
    name = "db-v0-asg"
    launch_configuration = "${aws_launch_configuration.prod_db.name}"
    min_size = 3
    max_size = 3
    vpc_zone_identifier = ["${aws_subnet.db_a.id}","${aws_subnet.db_b.id}"]

    lifecycle {
      create_before_destroy = true
    }

    tag {
      key = "env"
      value = "prod"
      propagate_at_launch = true
    }

    tag {
      key = "role"
      value = "db"
      propagate_at_launch = true
    }
}

resource "aws_launch_configuration" "prod_build" {
    name_prefix = "build-v0-lc-"
    image_id = "ami-3b07b748"
    instance_type = "t2.micro"
    security_groups = ["${aws_security_group.build.id}"]
    iam_instance_profile = "prod-build"
    user_data = "#!/bin/bash\n/usr/local/bin/tagged-route53.py --public-ip --name build so-sure.com"

    lifecycle {
      create_before_destroy = true
    }
}

resource "aws_autoscaling_group" "prod_build" {
    name = "build-v0-asg"
    launch_configuration = "${aws_launch_configuration.prod_build.name}"
    min_size = 1
    max_size = 1
    vpc_zone_identifier = ["${aws_subnet.dmz_pub_a.id}"]

    lifecycle {
      create_before_destroy = true
    }

    tag {
      key = "env"
      value = "prod"
      propagate_at_launch = true
    }

    tag {
      key = "role"
      value = "build"
      propagate_at_launch = true
    }
}
