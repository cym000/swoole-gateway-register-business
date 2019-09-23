<?php
/**
 * Created by PhpStorm.
 * User: CYM 601780673@qq.com
 * Date: 19-9-21
 * Time: 下午1:39
 */

/**
 * gateway Server 可以是 webSocket\Tcp Server
 * 主要维护客户端链接 及 转发数据
 * 开子进程维护 分布式中各个链接
 */
require 'vendor/autoload.php';

$type = 'webSocket'; // webSocket tcp
$host = '127.0.0.1';
$port = 9502;
$tcpPort = 9504;
$redis = [
    'host'          => '127.0.0.1',
    'port'          => '6379',
    'auth'          => '',
    'db'            => 1,//选择数据库,默认为0
    'intervalCheckTime'    => 30 * 1000,//定时验证对象是否可用以及保持最小连接的间隔时间
    'maxIdleTime'          => 15,//最大存活时间,超出则会每$intervalCheckTime/1000秒被释放
    'maxObjectNum'         => 20,//最大创建数量
    'minObjectNum'         => 5,//最小创建数量 最小创建数量不能大于等于最大创建
];

echo "GatewayServer Start \n";

if($type === 'webSocket')
{
    $server = new swoole_websocket_server($host, $port);
    $tcpServer = $server->addListener($host, $tcpPort, SWOOLE_SOCK_TCP);
    $tcpServer->set(array());
}
else
{
    $server = new swoole_server($host, $tcpPort);
}

// 对Register 链接
$server->addProcess(new swoole_process(function ($process) use ($server, $host, $tcpPort){

    $cli = new \Co\Client(SWOOLE_SOCK_TCP);

    if (!$cli->connect('127.0.0.1', 9501, 0.5))
    {
//        exit("connect failed. Error: {$cli->errCode}\n");
    }

    // 把 $process->pipe 底层的reactor事件监听中
    swoole_event_add($process->pipe, function () use ($process, $cli){
        // 可以做主子进程间的通讯
        $recv = $process->read();
        echo 'process '.$recv;
        try {
            $cli->send($recv);
        } catch (Exception $e) {
            // webSocketServer 死掉
            echo $e->getMessage().PHP_EOL;
        }

    });
    // 信号监听
    swoole_process::signal(SIGTERM,function ()use($process){
        echo "signal \n";
        swoole_event_del($process->pipe);
        swoole_process::signal(SIGTERM, null);
        swoole_event_exit();
    });
    // 注册一个会在PHP中止时执行的函数
    register_shutdown_function(function () {
        echo "schedule \n";
        $schedule = new \Co\Scheduler(); // 协程调度器类
        $schedule->add(function (){
            echo "register_shutdown_function \n";
            // 进程退出时 收尾工作
        });
        $schedule->start();
    });

    // 定时器 心跳机制
    swoole_timer_tick(2000, function () use ($cli, $process){
        try {
//            $ret = $cli->send('PING');
//            $process->exit(); //进程主动退出
        } catch (Exception $e) {
            echo "swoole_timer_tick \n";
        }
    });

    $cli->send(json_encode([
        'server' => 'gateway', // gateway, businessWorker
        'action' => 'connect', // connect, heartbeat, close
        'ip'    => $host,
        'port' => $tcpPort
    ]));
    /*
    while(1)
    {
        $data = $cli->recv();
        if(!empty($data)) var_dump($data);

        co::sleep(1);
    }
    */
}, false, 1, true));

$config = new \EasySwoole\RedisPool\Config($redis);
// $config->setOptions(['serialize'=>true]);
/**
 */
$poolConf = \EasySwoole\RedisPool\Redis::getInstance()->register('redis',$config);
$poolConf->setMaxObjectNum($redis['maxObjectNum']);
$poolConf->setMinObjectNum($redis['minObjectNum']);

$server->on('WorkerStart', function ($serv, $worker_id){

});

// 对BusinessWorker 链接

if($type === 'webSocket')
{
    $server->on('open', function($server, $req) {
        global $host, $tcpPort;

        if(empty($req->get['uid']))
        {
            $server->close($req->fd);
            return;
        }
//        $fdInfo = $server->getClientInfo($req->fd);
        $server->bind($req->fd, $req->get['uid']);
        $redis = \EasySwoole\RedisPool\Redis::defer('redis');

        $redis->set($req->get['uid'], json_encode([
            'ip' => $host,
            'port' => $tcpPort,
            'fd' => $req->fd
        ]));

//        $data = $redis->get($req->get['uid']);
//        var_dump($data);

        echo "connection open: {$req->fd}\n";
    });

    $server->on('message', function($server, $frame) {
        global $host, $tcpPort;
        $fdInfo = $server->getClientInfo($frame->fd);
        $data = json_decode($frame->data, 1);

        if(empty($fdInfo['uid']))
        {
            if(empty($data['uid']))
            {
                $server->close($req->fd);
                return;
            }
            else
            {
                $server->bind($frame->fd, $data['uid']);
                $redis = \EasySwoole\RedisPool\Redis::defer('redis');
                $redis->set($data['uid'], json_encode([
                    'ip' => $host,
                    'port' => $tcpPort,
                    'fd' => $frame->fd
                ]));
            }
        }

//        echo "received message: {$frame->data}\n";
//        $server->push($frame->fd, json_encode(["hello", "world"]));
    });

    /*
    $server->on('handshake', function($request, $response) {
        //
        $response->status(101);
        $response->end();

    });
    */

    $tcpServer->on('connect', function ($server, $fd){
        echo "businessWorkerClient 连接 \n";
    });

    $tcpServer->on('receive', function ($server, $fd, $reactor_id, $data){
        echo "businessWorkerClient". $data . PHP_EOL;
    });
}
else
{
    $server->on('receive', function ($server, $fd, $reactor_id, $data) {
        global $host, $tcpPort;
        $fdInfo = $server->getClientInfo($fd);
        $data = json_decode($data, 1);

        if(empty($fdInfo['uid']))
        {
            if(empty($data['uid']))
            {
                $server->close();
                return;
            }
            else
            {
                $server->bind($fd, $data['uid']);
                $redis = \EasySwoole\RedisPool\Redis::defer('redis');
                $redis->set($data['uid'], json_encode([
                    'ip' => $host,
                    'port' => $tcpPort,
                    'fd' => $fd
                ]));
            }
        }

    });
}

//$server->on('receive', function ($server, $fd, $reactor_id, $data) {
//    echo "tcpServerClient Receive1". $data . PHP_EOL;
//});
/*
$server->on('connect', function ($server, $fd){
    echo "connection open: {$fd}\n";
});
*/

$server->on('close', function($server, $fd) {

    $fdInfo = $server->getClientInfo($fd);
    if(empty($fdInfo['uid'])) return;

    // 清除 uid -> fd 绑定
    $redis = \EasySwoole\RedisPool\Redis::defer('redis');
    $redis->delete($fdInfo['uid']);

    echo "connection close: {$fd}\n";
});


$server->start();