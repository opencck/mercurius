# An SSH server for integration tests only (compose profile "test"): password login for "tester".
FROM alpine:3.20
RUN apk add --no-cache openssh-server coreutils \
    && ssh-keygen -A \
    && adduser -D -s /bin/sh tester \
    && echo 'tester:tester-password-1' | chpasswd \
    && printf 'PasswordAuthentication yes\nPermitRootLogin no\nAllowTcpForwarding yes\n' >> /etc/ssh/sshd_config
EXPOSE 22
CMD ["/usr/sbin/sshd", "-D", "-e"]
