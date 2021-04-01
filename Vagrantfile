$script = <<SCRIPT
set -e
export CACHE_DIR=/dev/shm/cache

sudo apt-get install ifupdown

# make sure paramters.yml exists
if [ ! -f /vagrant/app/config/parameters.yml ]; then
  cp /vagrant/app/config/parameters.yml.dist /vagrant/app/config/parameters.yml
fi

if [ ! -f /usr/bin/ansible-playbook ]; then
  sudo sudo add-apt-repository ppa:ansible/ansible
  sudo apt-get update
  sudo apt-get install -y python-pip ansible
fi

SCRIPT

$github_ops = <<SCRIPT
FIND_HOST=github.com
FIND_IP=`dig +short $FIND_HOST | grep -v ";;"`
ssh-keygen -R $FIND_HOST
ssh-keygen -R $FIND_IP
ssh-keygen -R $FIND_HOST,$FIND_IP
ssh-keyscan -H $FIND_HOST,$FIND_IP >> ~/.ssh/known_hosts
ssh-keyscan -H $FIND_IP >> ~/.ssh/known_hosts
ssh-keyscan -H $FIND_HOST >> ~/.ssh/known_hosts

sudo apt-get install -y git

if [ ! -d /var/ops ]; then
  sudo mkdir /var/ops
fi

sudo chown vagrant /var/ops
cd /var/ops

if [ ! -d /var/ops/.git ]; then
  git init
fi

if [ `git remote | wc -l` == "0" ]; then
  git remote add origin git@github.com:so-sure/ops.git
fi

git fetch
git checkout master
git pull origin master

SCRIPT

$deploy = <<SCRIPT
set -e
/var/ops/scripts/deploy.sh -d -u vagrant -n /vagrant vagrant
SCRIPT

Vagrant.configure("2") do |config|
  # will fail the first time. Run the following:
  # vagrant ssh; sudo apt-get install ifupdown
  # exit; vagrant halt; vagrant up --provision
  #
  # there may be an additional error about unable to read from remote git repo
  # copy your ssh id_rsa key to source folder; then vagrant ssh; cp /vagrant/id_rsa ~/.ssh/id_rsa
  # exit; vagrant halt; vagrant up --provision
  config.vm.define "dev1804", primary: true, autostart: true do |dev1804_config|
    dev1804_config.vm.box = "geerlingguy/ubuntu1804"
    dev1804_config.vm.box_check_update = false
    dev1804_config.vm.network "forwarded_port", guest: 80, host: 40080 # apache sosure website
    dev1804_config.vm.network "forwarded_port", guest: 8008, host: 40088 # apache sosure website
    dev1804_config.vm.network "forwarded_port", guest: 27017, host: 47017 # mongodb
    dev1804_config.vm.network "private_network", ip: "10.0.4.2"
    #dev1804_config.vm.synced_folder ".", "/vagrant", owner: "www-data"
    dev1804_config.vm.synced_folder ".", "/vagrant", nfs: true, mount_options: ['rw,vers=3,tcp,fsc,actimeo=1']
    # Comment above and uncomment below for macos!
    #uncomment for mac: dev1804_config.vm.synced_folder ".", "/vagrant", owner: "www-data"
    #uncomment for mac: dev1804_config.vm.synced_folder "/System/Volumes/Data" + Dir.pwd, '/[DIR-NAME]', nfs: true, mount_options: ['rw,vers=3,tcp,fsc,actimeo=1']
    #dev1804_config.vm.synced_folder ".", "/vagrant"
    dev1804_config.ssh.forward_agent = true
    dev1804_config.vm.provision "shell",
        inline: $script

    dev1804_config.vm.provision "shell",
        inline: $github_ops,
        privileged: false

    # Patch for https://github.com/mitchellh/vagrant/issues/6793
    dev1804_config.vm.provision "shell" do |s|
        s.inline = '[[ ! -f $1 ]] || grep -F -q "$2" $1 || sed -i "/__main__/a \\    $2" $1'
        s.args = ['/usr/bin/ansible-galaxy', "if sys.argv == ['/usr/bin/ansible-galaxy', '--help']: sys.argv.insert(1, 'info')"]
    end

    dev1804_config.vm.provision "ansible_local" do |a|
        a.playbook = "vagrant1804.yml"
        a.provisioning_path = "/var/ops/ansible"
        a.inventory_path = "/var/ops/ansible/vagrant_inventory"
        a.limit = "vagrant"
        a.install = false
    end

    dev1804_config.vm.provision "shell",
        inline: $deploy

    dev1804_config.vm.provider "virtualbox" do |v|
      v.customize ["modifyvm", :id, "--memory", 1200]
      v.customize ["modifyvm", :id, "--cpus", 1]

      # Virtualbox has issues with symlinks - https://www.virtualbox.org/ticket/10085#comment:12
      v.customize ["setextradata", :id, "VBoxInternal2/SharedFoldersEnableSymlinksCreate/vagrant", "1"]

      # if you ever need the gui
      # v.gui = true

      # This setting makes it so that network access from the vagrant guest is able to
      # resolve connections using the hosts VPN connection
      # it means we can DNS resolve internal.vpn domains
      v.customize ["modifyvm", :id, "--natdnshostresolver1", "on"]
      v.customize ["modifyvm", :id, "--natdnsproxy1", "on"]
    end
  end

  config.vm.define "dev1804_nonfs", primary: false, autostart: false do |dev1804_nonfs_config|
    dev1804_nonfs_config.vm.box = "geerlingguy/ubuntu1804"
    dev1804_nonfs_config.vm.network "forwarded_port", guest: 80, host: 40080 # apache sosure website
    dev1804_nonfs_config.vm.network "forwarded_port", guest: 8008, host: 40088 # apache sosure website
    dev1804_nonfs_config.vm.network "forwarded_port", guest: 27017, host: 47017 # mongodb
    dev1804_nonfs_config.vm.network "private_network", ip: "10.0.4.2"
    dev1804_nonfs_config.vm.synced_folder ".", "/vagrant" , mount_options: ["dmode=777,fmode=777"]
    dev1804_nonfs_config.ssh.forward_agent = true

    dev1804_nonfs_config.vm.provision "shell",
        inline: $script

    # Patch for https://github.com/mitchellh/vagrant/issues/6793
    dev1804_nonfs_config.vm.provision "shell" do |s|
        s.inline = '[[ ! -f $1 ]] || grep -F -q "$2" $1 || sed -i "/__main__/a \\    $2" $1'
        s.args = ['/usr/bin/ansible-galaxy', "if sys.argv == ['/usr/bin/ansible-galaxy', '--help']: sys.argv.insert(1, 'info')"]
    end

    dev1804_nonfs_config.vm.provision "ansible_local" do |a|
        a.playbook = "vagrant1804.yml"
        a.provisioning_path = "/var/ops/ansible"
        a.inventory_path = "/var/ops/ansible/vagrant_inventory"
        a.limit = "vagrant"
        a.install = false
    end

    dev1804_nonfs_config.vm.provision "shell",
        inline: $deploy

    dev1804_nonfs_config.vm.provider "virtualbox" do |v|
      v.customize ["modifyvm", :id, "--memory", 1200]
      v.customize ["modifyvm", :id, "--cpus", 1]

      # Virtualbox has issues with symlinks - https://www.virtualbox.org/ticket/10085#comment:12
      v.customize ["setextradata", :id, "VBoxInternal2/SharedFoldersEnableSymlinksCreate/vagrant", "1"]

      # if you ever need the gui
      # v.gui = true

      # This setting makes it so that network access from the vagrant guest is able to
      # resolve connections using the hosts VPN connection
      # it means we can DNS resolve internal.vpn domains
      v.customize ["modifyvm", :id, "--natdnshostresolver1", "on"]
      v.customize ["modifyvm", :id, "--natdnsproxy1", "on"]
    end
  end

  config.vm.define "ubuntu1804", autostart: false do |ubuntu1804_config|
    ubuntu1804_config.vm.box = "geerlingguy/ubuntu1804"
    ubuntu1804_config.ssh.forward_agent = true
  end
end

# load extra Vagrantfile which can be used to specify user specific configurations
begin
  load 'VagrantfileExtra.rb'
rescue LoadError
  # ignore
end
