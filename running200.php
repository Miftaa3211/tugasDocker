<?php
system("docker run -d --name server12146 -e ROOT_PASSWORD=baseball -p 12146:22 -p 21146:80 --restart=always xcodedata")
?>
