#!/bin/bash
service ssh start
service apache2 start
service mysql start
tail -f /dev/null
