<?php

namespace Qsnh\Sork;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class Client extends Command
{

    const CONFIG_DEFAULT_PATH = './client.json';

    protected static $defaultName = 'client';

    protected function configure()
    {
        $this->setDescription('运行sork客户端')
            ->setHelp("php sork client [client_config_file_path]")
            ->addArgument('config', InputArgument::OPTIONAL, '客户端配置文件');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $configPath = $input->getArgument('config') ?: self::CONFIG_DEFAULT_PATH;
        if (!file_exists($configPath)) {
            $io->error("{$configPath}配置文件不存在");
            return;
        }
        $config = json_decode(file_get_contents($configPath), true);

        go(function () use ($config, $io) {
            $client = new \Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);
            if (!$client->connect($config['server']['host'], $config['server']['port'], 0.5)) {
                $io->error("connect server failed. Error: {$client->errCode}\n");
                return;
            }
            $io->success('成功连接到服务端');
            $client->set([
                'open_eof_check' => true,
                'package_eof' => PHP_EOL,
                'open_eof_split' => true,
            ]);
            while ('' !== $data = $client->recv(-1)) {
                $data = json_decode($data, true);
                if ($data['action'] === 'new') {
                    $io->note('收到服务端建立tunnel隧道的消息');
                    $fd = $data['fd'];
                    // 主动发起连接
                    go(function () use ($config, $fd, $io) {
                        // 连接到tunnel
                        $client = new \Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);
                        if (!$client->connect($config['tunnel']['host'], $config['tunnel']['port'], 0.5)) {
                            $io->error("connect tunnel server failed. Error: {$client->errCode}\n");
                            return;
                        }
                        // 发送fd注册当前conn
                        $client->send($fd);

                        // 连接到local
                        $localClient = new \Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);
                        if (!$localClient->connect($config['local']['host'], $config['local']['port'], 0.5)) {
                            $io->error("connect local server failed. Error: {$localClient->errCode}\n");
                            return;
                        }

                        // 监听tunnel数据
                        go(function () use ($client, $localClient) {
                            while ('' !== $data = $client->recv(-1)) {
                                // 转发到本地
                                $localClient->send($data);
                            }
                            $client->close();
                        });
                        // 监听local数据
                        go(function () use ($client, $localClient) {
                            while ('' !== $data = $localClient->recv(-1)) {
                                // 转发到本地
                                $client->send($data);
                            }
                            $localClient->close();
                        });
                    });
                }
            }
            $client->close();
        });
    }

}