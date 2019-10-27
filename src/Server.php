<?php


namespace Qsnh\Sork;

use Swoole\Coroutine\Channel;
use swoole_table;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class Server extends Command
{

    const CONFIG_DEFAULT_PATH = './server.json';

    protected static $defaultName = 'server';

    protected function configure()
    {
        $this->setDescription('运行sork服务端')
            ->setHelp('php sork server [server_config_file_path]')
            ->addArgument('config', InputArgument::OPTIONAL, '配置文件路径');
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

        $serverChannel = new Channel(10);
        $localChannel = new Channel(10);
        $tunnelChannel = new Channel(10);

        // 全局注册表
        $register = new swoole_table(1024 * 10);
        $register->column('local_fd', swoole_table::TYPE_INT);
        $register->create();

        $server = new \Swoole\Server($config['listen']['host'], $config['listen']['port'], SWOOLE_SOCK_TCP);
        $server->on('Connect', function ($serv, $fd) use ($serverChannel, $io) {
            $io->note("本地服务有新连接:{$fd}");
            $serverChannel->push(json_encode(['action' => 'new', 'fd' => $fd]));
        });
        $server->on('Receive', function ($server, $fd, $from_id, $data) use ($localChannel, $serverChannel, $tunnelChannel, $io) {
            $io->note("收到本地服务的消息:{$data}");
            $tunnelChannel->push(['fd' => $fd, 'data' => $data]);
        });
        $server->on('close', function ($server, $fd) use ($register, $io) {
            $io->note("本地服务连接已断开:{$fd}");
            // 将隧道关闭
            foreach ($register as $tunnelFd => $localFd) {
                if ($localFd == $fd) {
                    $io->note("隧道连接已关闭:{$tunnelFd}");
                    $server->close($tunnelFd);
                    break;
                }
            }
        });
        $io->success("本地服务已启动:{$config['listen']['host']}:{$config['listen']['port']}");
        go(function () use ($server, $localChannel) {
            while (1) {
                $data = $localChannel->pop();
                $server->send($data['fd'], $data['data']);
            }
        });

        // 监听tunnel
        $tunnelServer = $server->listen($config['tunnel']['host'], $config['tunnel']['port'], SWOOLE_SOCK_TCP);
        $tunnelServer->on('Connect', function ($serv, $fd) use ($io) {
            $io->note("tunnel已建立新连接");
        });
        $tunnelServer->on('Receive', function ($server, $fd, $from_id, $data) use ($localChannel, $tunnelChannel, $register) {
            if (!$register[$fd]['local_fd']) {
                $register[$fd] = ['local_fd' => $data];
            } else {
                $localChannel->push(['fd' => $register[$fd]['local_fd'], 'data' => $data]);
            }
        });
        $tunnelServer->on('close', function ($server, $fd) use ($register, $io) {
            // 将隧道关闭
            $io->note("tunnel连接已断开:{$fd}");
            foreach ($register as $tunnelFd => $localFd) {
                if ($tunnelFd == $fd) {
                    $io->note("本地服务连接已断开:{$localFd}");
                    $server->close($localFd);
                    break;
                }
            }
        });
        $io->success("隧道服务已启动:{$config['tunnel']['host']}:{$config['tunnel']['port']}");
        // 放在外面消费
        go(function () use ($server, $register, $tunnelChannel, $io) {
            while (1) {
                $data = $tunnelChannel->pop();
                $over = false;
                foreach ($register as $tunnelFd => $localFd) {
                    if ($localFd['local_fd'] == $data['fd']) {
                        $io->note("将服务端数据通过隧道传输到客户端");
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
        $mainServer->on('Connect', function ($server, $fd) use ($serverChannel, $io) {
            $io->note("客户端已连接:{$fd}");
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
        $mainServer->on('Close', function ($server, $fd) use ($io) {
            $io->caution("客户端已断开连接:{$fd}");
        });
        $io->success("主服务已启动:{$config['server']['host']}:{$config['server']['port']}");

        $server->start();
    }

}