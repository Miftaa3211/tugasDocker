FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=Asia/Jakarta
ENV ROOT_PASSWORD=root

RUN apt update && apt install -y tzdata nano curl net-tools && apt clean

RUN mkdir /run/sshd && \
    apt install -y openssh-server && \
    sed -i 's/^#\(PermitRootLogin\) .*/\1 yes/' /etc/ssh/sshd_config && \
    sed -i 's/^\(UsePAM yes\)/# \1/' /etc/ssh/sshd_config && \
    apt clean

RUN apt install -y apache2 php php-mysql libapache2-mod-php && apt clean

RUN apt install -y mysql-server && apt clean

RUN apt install -y phpmyadmin && \
    echo "Include /etc/phpmyadmin/apache.conf" >> /etc/apache2/apache2.conf && \
    apt clean

RUN { \
    echo '#!/bin/bash -eu'; \
    echo 'ln -fs /usr/share/zoneinfo/${TZ} /etc/localtime'; \
    echo 'echo "root:${ROOT_PASSWORD}" | chpasswd'; \
    echo 'exec "$@"'; \
    } > /usr/local/bin/entry_point.sh && \
    chmod +x /usr/local/bin/entry_point.sh

EXPOSE 22
EXPOSE 80

ENTRYPOINT ["entry_point.sh"]
COPY script.sh script.sh
CMD ["./script.sh"]
