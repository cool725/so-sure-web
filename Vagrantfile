$script = <<SCRIPT
set -e
export CACHE_DIR=/dev/shm/cache

# make sure paramters.yml exists
if [ ! -f /vagrant/app/config/parameters.yml ]; then
  cp /vagrant/app/config/parameters.yml.dist /vagrant/app/config/parameters.yml
  echo "    kernel.logs_dir: /tmp" >> /vagrant/app/config/parameters.yml
  echo "    kernel.cache_dir: $CACHE_DIR" >> /vagrant/app/config/parameters.yml
else
  CORRECT_CACHE_DIR=`grep "kernel.cache_dir: $CACHE_DIR" /vagrant/app/config/parameters.yml | wc -l`
  if [ "$CORRECT_CACHE_DIR" == "0" ]; then
    echo "kernel.cache_dir is not set to $CACHE_DIR in parameters.yml and is required to be set as such."
    exit 1
  fi
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

apt-get install -y git

if [ ! -d /var/ops ]; then
  sudo mkdir /var/ops
  sudo chown vagrant /var/ops
  cd /var/ops
  git remote add origin git@github.com:so-sure/ops.git
fi

cd /var/ops
git fetch
git checkout master
git pull origin master

SCRIPT

$deploy = <<SCRIPT
set -e
/var/ops/scripts/deploy.sh -u vagrant -n /vagrant vagrant
SCRIPT

Vagrant.configure("2") do |config|
  config.vm.define "dev", primary: true, autostart: true do |dev_config|
    dev_config.vm.box = "ubuntu/trusty64"
    dev_config.vm.network "forwarded_port", guest: 80, host: 40080 # apache sosure website
    dev_config.vm.network "forwarded_port", guest: 27017, host: 47017 # mongodb
    dev_config.vm.network "private_network", ip: "10.0.4.2"
    #dev_config.vm.synced_folder ".", "/vagrant", owner: "www-data"
    dev_config.vm.synced_folder ".", "/vagrant", nfs: true
    #dev_config.vm.synced_folder ".", "/vagrant"
    dev_config.ssh.forward_agent = true
    dev_config.vm.provision "shell",
    	inline: $script

    dev_config.vm.provision "shell",
    	inline: $github_ops,
		privileged: false

    # Patch for https://github.com/mitchellh/vagrant/issues/6793
    dev_config.vm.provision "shell" do |s|
        s.inline = '[[ ! -f $1 ]] || grep -F -q "$2" $1 || sed -i "/__main__/a \\    $2" $1'
        s.args = ['/usr/bin/ansible-galaxy', "if sys.argv == ['/usr/bin/ansible-galaxy', '--help']: sys.argv.insert(1, 'info')"]
    end
	
    dev_config.vm.provision "ansible_local" do |a|
        a.playbook = "vagrant.yml"
        a.provisioning_path = "/var/ops/ansible"
        a.inventory_path = "/var/ops/ansible/vagrant_inventory"
        a.limit = "vagrant"
        a.install = false
    end

    dev_config.vm.provision "shell",
    	inline: $deploy
    	
    dev_config.vm.provider "virtualbox" do |v|
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

  config.vm.define "dev_nonfs", primary: false, autostart: false do |dev_nonfs_config|
    dev_nonfs_config.vm.box = "ubuntu/trusty64"
    dev_nonfs_config.vm.network "forwarded_port", guest: 80, host: 40080 # apache sosure website
    dev_nonfs_config.vm.network "forwarded_port", guest: 27017, host: 47017 # mongodb
    dev_nonfs_config.vm.network "private_network", ip: "10.0.4.2"
    dev_nonfs_config.vm.synced_folder ".", "/vagrant"
    dev_nonfs_config.ssh.forward_agent = true

    dev_nonfs_config.vm.provision "shell",
    	inline: $script

    # Patch for https://github.com/mitchellh/vagrant/issues/6793
    dev_nonfs_config.vm.provision "shell" do |s|
        s.inline = '[[ ! -f $1 ]] || grep -F -q "$2" $1 || sed -i "/__main__/a \\    $2" $1'
        s.args = ['/usr/bin/ansible-galaxy', "if sys.argv == ['/usr/bin/ansible-galaxy', '--help']: sys.argv.insert(1, 'info')"]
    end

    dev_nonfs_config.vm.provision "ansible_local" do |a|
        a.playbook = "vagrant.yml"
        a.provisioning_path = "/vagrant/ops/ansible"
        a.inventory_path = "/vagrant/ops/ansible/vagrant_inventory"
        a.limit = "vagrant"
        a.install = false
    end

    dev_nonfs_config.vm.provision "shell",
    	inline: $deploy

    dev_nonfs_config.vm.provider "virtualbox" do |v|
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

  config.vm.define "ubuntu1404", autostart: false do |ubuntu1404_config|
    ubuntu1404_config.vm.box = "ubuntu/trusty64"
    ubuntu1404_config.ssh.forward_agent = true
  end
end

# load extra Vagrantfile which can be used to specify user specific configurations
begin
  load 'VagrantfileExtra.rb'
rescue LoadError
  # ignore
end
