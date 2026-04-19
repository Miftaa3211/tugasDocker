#!/bin/bash
#Progammer : Kurniawan. admin@xcodetraining.com. xcode.or.id.
#Program ini dapat digunakan untuk personal ataupun komersial.
#X-code Media - xcode.or.id / xcode.co.id
apt update
apt install apt-transport-https ca-certificates curl software-properties-common -y
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt update
sudo apt-get install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable docker
apt install apache2
sudo a2enmod proxy
sudo a2enmod proxy_http
sudo a2enmod proxy_balancer
sudo a2enmod lbmethod_byrequests
sudo apt-get install zip unzip php-zip
sudo a2enmod ssl
service apache2 restart
sudo apt install php
sudo mkdir /home/root
sudo touch /home/root/locked
sudo mkdir /home/pma
sudo touch /home/pma/locked
sudo mkdir /home/www
sudo touch /home/www/locked
sudo mkdir /home/datauser
sudo mkdir /home/alamat
sudo touch /home/alamat/locked
sudo touch /home/datauser
sudo mkdir /home/xcodehoster
sudo touch /home/datauser/locked
sudo mkdir /home/datapengguna
sudo touch /home/datapengguna/locked
sudo mkdir /home/checkdata
sudo touch /home/checkdata/locked
sudo mkdir /home/checkdata2
sudo touch /home/checkdata2/locked
sudo chmod 777 /home/datauser
sudo mkdir /home/rambutan
sudo a2enmod cgi
sudo chmod 777 /usr/lib/cgi-bin
sudo chmod 777 /usr/lib/cgi-bin/*
sudo chmod 777 /home
sudo chmod 777 /etc/apache2/sites-available
sudo mkdir /etc/apache2/ssl
sudo chmod 777 /etc/apache2/ssl
sudo apt install jq
sudo sudo apt install imagemagick
sudo mkdir /etc/apache2/ssl
sudo service apache2 restart
echo -n "Masukkan nama domain : "
read domain
sudo cp subdata.conf /home/xcodehoster/subdata.conf
sudo cp data.conf /home/xcodehoster/data.conf
sed -i "s/domain/$domain/g" /home/xcodehoster/data.conf
sed -i "s/domain/$domain/g" /home/xcodehoster/subdata.conf
sed -i "s/subalamat/$domain/g" form.sh
sed -i "s/domain/$domain/g" aktivasi3.sh
sed -i "s/domain/$domain/g" index.html
sed -i "s/domain/$domain/g" aksesrun2.sh
cp index.html /var/www/html
sudo chmod 777 /home/xcodehoster/*
sudo chmod 777 /home/xcodehoster
sudo sed -i "/more/i\www-data ALL=(ALL) NOPASSWD: ALL" /etc/sudoers
echo -n "Masukkan Zone ID cloudflare : "
read zoneid
sed -i "s/zoneid/$zoneid/g" aktivasi3.sh
echo -n "Masukkan e-mail cloudflare : "
read email
sed -i "s/email/$email/g" aktivasi3.sh
echo -n "Masukkan Global API Key cloudflare : "
read globalapikey
sed -i "s/globalapikey/$globalapikey/g" aktivasi3.sh
echo -n "Masukkan ip publik server : "
read ipserver
echo $ipserver > /usr/lib/cgi-bin/ip.txt
sed -i "s/ipserver/$ipserver/g" aktivasi3.sh
sed -i "s/ipserver/$ipserver/g" aksesrun2.sh
for i in {1..1000}; do < /dev/urandom tr -dc 'A-Za-z0-9' | head -c 8; echo; done > /usr/lib/cgi-bin/vouchers.txt
echo "Instalasi berhasil, buat subdomain dengan nama konek di DNS domain cloudflare, set DNS only, arahkan ke ip server, selain itu edit Dockerfile dan script.sh lalu docker build -t xcodedata ."
