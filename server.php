<?php

use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Server;
use Swoole\Coroutine\Server\Connection;

$config = json_decode(file_get_contents('./server.json'), true);

$serverChannel = new Channel(10);
$localChannel = new Channel(10);
$tunnelChannel = new Channel(10);

// 注册表
$register = new swoole_table(1024 * 10);
$register->column('local_fd', swoole_table::TYPE_INT);
$register->create();

$server = new \Swoole\Server($config['listen']['host'], $config['listen']['port'], SWOOLE_SOCK_TCP);
$server->on('Connect', function ($serv, $fd) use ($serverChannel) {
    echo "本地服务有新连接\n";
    $serverChannel->push(json_encode(['action' => 'new', 'fd' => $fd]));
});
$server->on('Receive', function ($server, $fd, $from_id, $data) use ($localChannel, $serverChannel, $tunnelChannel) {
    $tunnelChannel->push(['fd' => $fd, 'data' => $data]);
});
$server->on('close', function ($server, $fd) use ($register) {
    // 将隧道关闭
    foreach ($register as $tunnelFd => $localFd) {
        if ($localFd == $fd) {
            $server->close($tunnelFd);
            break;
        }
    }
});
echo "listen:{$config['listen']['host']}:{$config['listen']['port']}\n";
go(function () use ($server, $localChannel) {
    while (1) {
        $data = $localChannel->pop();
        var_dump($data);
        $server->send($data['fd'], $data['data']);
    }
});

// 监听tunnel
$tunnelServer = $server->listen($config['tunnel']['host'], $config['tunnel']['port'], SWOOLE_SOCK_TCP);
$tunnelServer->on('Connect', function ($serv, $fd) {
    echo "隧道有新连接\n";
});
$tunnelServer->on('Receive', function ($server, $fd, $from_id, $data) use ($localChannel, $tunnelChannel, $register) {
    echo "收到消息：{$data}\n";
    if (!$register[$fd]['local_fd']) {
        $register[$fd] = ['local_fd' => $data];
    } else {
        $localChannel->push(['fd' => $register[$fd]['local_fd'], 'data' => $data]);
    }
});
$tunnelServer->on('close', function ($server, $fd) use ($register) {
    // 将隧道关闭
    foreach ($register as $tunnelFd => $localFd) {
        if ($tunnelFd == $fd) {
            $server->close($localFd);
            break;
        }
    }
});
echo "tunnel:{$config['tunnel']['host']}:{$config['tunnel']['port']}\n";

// 放在外面消费
go(function () use ($server, $register, $tunnelChannel) {
    while (1) {
        $data = $tunnelChannel->pop();
        $over = false;
        foreach ($register as $tunnelFd => $localFd) {
            if ($localFd['local_fd'] == $data['fd']) {
                echo "将服务端数据通过隧道传输到客户端\n";
                $over = true;
                $server->send($tunnelFd, $data['data']);
                break;
            }
        }
        if ($over === false) {
            // 重新塞回channel
            $tunnelChannel->push($data);
            \Swoole\Coroutine::sleep(0.2);
        }
    }
});

// 主服务
$mainServer = $server->listen($config['server']['host'], $config['server']['port'], SWOOLE_SOCK_TCP);
$mainServer->set([
    'open_eof_check' => true,
    'package_eof' => PHP_EOL,
    'open_eof_split' => true,
]);
$mainServer->on('Connect', function ($server, $fd) use ($serverChannel) {
    echo "客户端已连接\n";
    go(function () use ($server, $fd, $serverChannel) {
        while (true) {
            $data = $serverChannel->pop();
            $server->send($fd, $data . PHP_EOL);
        }
    });
});
$mainServer->on('Receive', function ($server, $fd, $from_id, $data) use ($serverChannel, $register) {
// todo
});
echo "server:{$config['server']['host']}:{$config['server']['port']}\n";

$server->start();