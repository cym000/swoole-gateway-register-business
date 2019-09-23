<?php
/**
 * Created by PhpStorm.
 * User: CYM 601780673@qq.com
 * Date: 19-9-21
 * Time: 下午1:39
 */

/**
 * businessWorker Server 可以是 Tcp Server
 * 处理gateway数据及业务处理，转发给gateway去发送客户端
 * 开子进程维护 分布式中各个链接
 */

echo "BusinessWorkerServer Start \n";

$host = '127.0.0.1';
$tcpPort = 9503;
$gateway = [];

$server = new swoole_server($host, $tcpPort);
$server->set(array(
    'worker_num' => 2,    //worker process num
));


// 对Register 链接
$server->addProcess(new swoole_process(function ($process) use ($server){

    global $host, $tcpPort;

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
        'ip' => $host,
        'port' => $tcpPort,
        'server' => 'businessWorker', // gateway, businessWorker
        'action' => 'connect', // connect, heartbeat, close
    ]));

    $worker_num = $server->setting['worker_num'];
    while(1)
    {
        $data = $cli->recv();
        if(empty($data)) continue;
        var_dump($data);

        for($i = 0; $i < $worker_num; $i++)
        {
            $server->sendMessage($data, $i);
        }

    }

}, false, 1, true));


$server->on('WorkerStart', function ($serv, $worker_id){
    // register
//    global $host, $tcpPort, $register;
//    $client = new \Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);
//    $client->connect('127.0.0.1', 9501);
//
//    $client->send(json_encode([
//        'ip' => $host,
//        'port' => $tcpPort,
//        'server' => 'businessWorker', // gateway, businessWorker
//        'action' => 'connect', // connect, heartbeat, close
//    ]));
//
//    while (true)
//    {
//        $str = $client->recv(60);
//        if(empty($str)) return;
//        $data = json_decode($str,1);
//        if(empty($data) || empty($data['action'])) continue;
//        $serv->sendMessage($data, $worker_id);
//    }


    // gateway
//    \Swoole\Timer::tick(3000, function () use ($serv){
//        $client = new \Coroutine\Client(SWOOLE_SOCK_TCP);
//        $client->connect('127.0.0.1', 9501, 0.5);
//    });
});

function receiveGateWayData($client, $data){
    echo "接收GateWay 发过来的数据\n";
    $ret = $client->send('发送BusinessWorker处理后数据');
}

$server->on('pipeMessage', function ($server, $work_id, $msg){

    $data = json_decode($msg,1);

    if(empty($data['action']) || empty($data['ipAndPort'])) return;

    $ipAndPort = $data['ipAndPort'];
    if($data['action'] === 'connect')
    {
        go(function () use ($server, $ipAndPort){
            echo "connect \n";
            global $register;
            $client = new \Co\Client(SWOOLE_SOCK_TCP);

            list($ip, $port) = explode(':', $ipAndPort);

            $client->connect($ip, $port, 0.5);

            $client->send('businessWorkerClient 发送测试数据！');
            $register[$ipAndPort] = $client;
            while (true)
            {
                if(empty($register[$ipAndPort])) break;

                try
                {
                    $str = $client->recv(60);
                }
                catch (\Throwable $throwable)
                {
                    // webSocketServer 死掉
                    echo $throwable->getMessage().PHP_EOL;
                    continue;
                }

                if(empty($str)) continue;

                receiveGateWayData($client, $str);
            }

        });
    }
    else
    {
        go(function () use ($server, $ipAndPort){
            global $register;

            if(empty($register[$ipAndPort])) return;

            $register[$ipAndPort] = null;
            unset($register[$ipAndPort]);
        });
    }


});

// 对BusinessWorker 链接
$server->on('receive', function ($server, $fd, $reactor_id, $data) {
//    $server->send($fd, "Swoole: {$data}");
//    $server->close($fd);
});

$server->on('connect', function ($server, $fd){
//    echo "connection open: {$fd}\n";
});


$server->on('close', function($server, $fd) {
//    echo "connection close: {$fd}\n";
});


$server->start();