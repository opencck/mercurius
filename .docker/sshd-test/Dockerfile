# An SSH server for integration tests only (compose profile "test"): "tester" logs in with a password or with the
# ed25519 key in its home (the tests read the key over a password login, then log in with it). Forwarding is on for
# the jump test; sshd takes the first value of a setting, so ours go before the stock file. tester may sudo with its
# password: the test of how the password reaches sudo alone.
FROM alpine:3.20
RUN apk add --no-cache openssh-server openssh-keygen coreutils sudo \
    && printf 'tester ALL=(ALL) ALL\n' > /etc/sudoers.d/tester && chmod 440 /etc/sudoers.d/tester \
    && ssh-keygen -A \
    && adduser -D -s /bin/sh tester \
    && echo 'tester:tester-password-1' | chpasswd \
    && mkdir -p /home/tester/.ssh \
    && ssh-keygen -q -t ed25519 -N '' -f /home/tester/.ssh/id_ed25519 \
    && cp /home/tester/.ssh/id_ed25519.pub /home/tester/.ssh/authorized_keys \
    && chown -R tester:tester /home/tester/.ssh && chmod 700 /home/tester/.ssh && chmod 600 /home/tester/.ssh/* \
    && { printf 'PasswordAuthentication yes\nPubkeyAuthentication yes\nPermitRootLogin no\nAllowTcpForwarding yes\nDisableForwarding no\n'; cat /etc/ssh/sshd_config; } > /tmp/sshd_config \
    && mv /tmp/sshd_config /etc/ssh/sshd_config
EXPOSE 22
CMD ["/usr/sbin/sshd", "-D", "-e"]
