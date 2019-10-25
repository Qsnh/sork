<?php

use Swoole\Coroutine\Client;

$config = json_decode(file_get_contents('./client.json'), true);

go(function () use ($config) {
    $client = new Client(SWOOLE_SOCK_TCP);
    if (!$client->connect($config['server']['host'], $config['server']['port'], 0.5)) {
        exit("connect server failed. Error: {$client->errCode}\n");
    }
    $client->set([
        'open_eof_check' => true,
        'package_eof' => PHP_EOL,
        'open_eof_split' => true,
    ]);
    while ('' !== $data = $client->recv(-1)) {
        echo "收到服务端消息：{$data}\n";
        $data = json_decode($data, true);
        if ($data['action'] === 'new') {
            $fd = $data['fd'];
            // 主动发起连接
            go(function () use ($config, $fd) {

                // 连接到tunnel
                $client = new Client(SWOOLE_SOCK_TCP);
                if (!$client->connect($config['tunnel']['host'], $config['tunnel']['port'], 0.5)) {
                    exit("connect tunnel failed. Error: {$client->errCode}\n");
                }
                // 发送fd注册当前conn
                $client->send($fd);

                // 连接到local
                $localClient = new Client(SWOOLE_SOCK_TCP);
                if (!$localClient->connect($config['local']['host'], $config['local']['port'], 0.5)) {
                    exit("connect local failed. Error: {$localClient->errCode}\n");
                }

                // 监听tunnel数据
                go(function () use ($client, $localClient) {
                    while ('' !== $data = $client->recv(-1)) {
                        // 转发到本地
                        echo "隧道收到来自服务端的数据\n";
                        $localClient->send($data);
                    }
                    $client->close();
                });
                // 监听local数据
                go(function () use ($client, $localClient) {
                    while ('' !== $data = $localClient->recv(-1)) {
                        // 转发到本地
                        echo "隧道收到本地端的数据\n";
                        $client->send($data);
                    }
                    $localClient->close();
                });
            });
        }
    }
    $client->close();
});