#!/bin/bash
echo "Content-type: text/html"
echo ""
nameroot=`echo "$QUERY_STRING" | awk '{split($0,array,"&")} END{print array[1]}' | awk '{split($0,array,"=")} END{print array[2]}' | tr [:upper:] [:lower:]`
name=`echo "$QUERY_STRING" | awk '{split($0,array,"&")} END{print array[2]}' | awk '{split($0,array,"=")} END{print array[2]}' | tr [:upper:] [:lower:]`
password=`echo "$QUERY_STRING" | awk '{split($0,array,"&")} END{print array[3]}' | awk '{split($0,array,"=")} END{print array[2]}'`
email=`echo "$QUERY_STRING" | awk '{split($0,array,"&")} END{print array[4]}' | awk '{split($0,array,"=")} END{print array[2]}'`
wa=`echo "$QUERY_STRING" | awk '{split($0,array,"&")} END{print array[5]}' | awk '{split($0,array,"=")} END{print array[2]}'`
cek=`echo "$QUERY_STRING" | awk '{split($0,array,"&")} END{print array[6]}' | awk '{split($0,array,"=")} END{print array[2]}'`
line=$(head -n 1 acak201.txt)
port=$(head -n 1 port.txt)
portweb=$(head -n 1 portweb.txt)
tanggal=$(date +%d-%m-%Y)
random=$(tr -dc a-z0-9 </dev/urandom | head -c 13 ; echo '')
function urldecode() { : "${*//+/ }"; echo -e "${_//%/\x}"; }
email=$(printf '%b' "${email//%/\\x}")
if [[ "${nameroot}" =~ [^a-z0-9.] ]]; then
echo "Username hanya boleh huruf kecil dan angka, harus ada titik"
else
if [[ "${name}" =~ [^a-z0-9] ]]; then
echo "Username hanya boleh huruf kecil dan angka"
else
if [[ "${password}" =~ [^a-z0-9] ]]; then
echo "Password hanya boleh huruf kecil dan angka"
else
if [[ "${email}" =~ [^a-z0-9.@] ]]; then
echo "Hanya mendukung format e-mail"
else
if [[ $email =~ "@peykesabz.com" ]]; then
echo "Registrasi hanya mendukung peykesabz.com"
else
if [ -n "$(ls /home/checkdata/$email | xargs -n 1 basename)" ]; then
echo "E-mail ini sudah digunakan, silahkan gunakan e-mail yang lain"
else
if ! grep -Fxq "$cek" vouchers.txt; then
echo "Kode voucher tersebut sudah digunakan, silahkan menggunakan voucher lain"
else
sed -i "/^$cek$/d" vouchers.txt
echo "username $name password $password. Salam dari kami yaa" | mail -s "Informasi username dan password akun" $email
echo $email > /home/checkdata/$email
echo $name, $password, $email, $wa, $tanggal, $port. > /home/checkdata2/$email
acak=$(tr -dc 2-5 </dev/urandom | head -c 10 ; echo '')
echo $acak > acak201.txt
convert \
    -size 725x100 \
    xc:lightblue \
    -font Bookman-DemiItalic \
    -pointsize 18 \
    -fill blue \
    -gravity center \
    -draw "text 0,0 $acak" \
    image.png
convert \
    -size 725x100 \
    xc:lightblue \
    -font Bookman-DemiItalic \
    -pointsize 18 \
    -fill blue \
    -gravity center \
    -draw "text 0,0 $acak" \
    image.png
convert \
    -size 725x100 \
    xc:lightblue \
    -font Bookman-DemiItalic \
    -pointsize 18 \
    -fill blue \
    -gravity center \
    -draw "text 0,0 $acak" \
    image.png
convert image.png -background white -wave 4x55 image.png
sudo cp /usr/lib/cgi-bin/acak201.txt /var/www/html/xcodekey.txt
if [[ "${wa}" =~ [^a-z0-9] ]]; then
echo "Nomor WA hanya boleh angka"
else
if [ -z "$(ls -A /home/$name)" ]; then
sudo mkdir /home/$name
sudo touch /home/$name/locked
cp /usr/lib/cgi-bin/image.png /var/www/html/
echo $name, $password, $email, $wa, $tanggal $port. > /home/rambutan/$name.$tanggal
echo $name, $password, $email, $wa, $tanggal $port. > /home/recovery/$name.$random
sudo mcrypt /home/recovery/$name.$random -k $wa
cp /home/xcodehoster/subdata.conf /etc/apache2/sites-available/$name.conf
cp /home/xcodehoster/data.conf /etc/apache2/sites-available/$nameroot.conf
sed -i "s/sample/$name/g" /etc/apache2/sites-available/$name.conf
sed -i "s/nomor/$portweb/g" /etc/apache2/sites-available/$name.conf
sed -i "s/contoh/$nameroot/g" /etc/apache2/sites-available/$nameroot.conf
sed -i "s/nomor/$portweb/g" /etc/apache2/sites-available/$nameroot.conf
sudo a2ensite $name.conf
sudo a2ensite $nameroot.conf
sudo systemctl reload apache2
sudo cp /usr/lib/cgi-bin/aktivasi3.sh /usr/lib/cgi-bin/aktivasi4.sh
sed -i "s/unik/$name/g" /usr/lib/cgi-bin/aktivasi4.sh
chmod 777 aktivasi4.sh
./aktivasi4.sh
rm aktivasi4.sh
cp /usr/lib/cgi-bin/running100.php  /usr/lib/cgi-bin/running200.php
sed -i "s/unikport/$port/g" /usr/lib/cgi-bin/running200.php
sed -i "s/numberdata/$portweb/g" /usr/lib/cgi-bin/running200.php
sed -i "s/unikuser/$name/g" /usr/lib/cgi-bin/running200.php
sed -i "s/unikpass/$password/g" /usr/lib/cgi-bin/running200.php
mkdir /home/$name
sudo chmod 777 /home/$name
sudo php running200.php
cp /usr/lib/cgi-bin/running2.php /usr/lib/cgi-bin/list/running$port.php
a=1
port=$((port+a))
echo $port > port.txt
cp port.txt /var/www/html
port2=$(head -n 1 port.txt)
b=1
port2=$((port-b))
x=1
portweb=$((portweb+x))
echo $portweb > portweb.txt
cp portweb.txt /var/www/html
portweb2=$(head -n 1 portweb.txt)
y=1
portweb2=$((portweb-y))
cat <<EOT
<!DOCTYPE html>
<html>
<head>
<title>Xcodehoster</title>
</head>
<body>
<h2>Welcome $name </h1>
<br />
Alamat website anda</br>
Alamat : $nameroot ( Arahkan ke 192.168.19.156 )<br />
Alamat sub : https://$name.tugaspkl.my.id<br />
<br />
Akses SSH anda:<br />
Host : konek.tugaspkl.my.id<br />
Port : $port2<br />
Username : root <br />
Password : $password<br />
<br />
<br/>
<br>
</body>
</html>
EOT
else
echo "Alamat Sub yang anda masukkan sudah ada pemiliknya"
fi
fi
fi
fi
fi
fi
fi
fi
fi

