<?php
/**
 * Created by PhpStorm.
 * User: CYM 601780673@qq.com
 * Date: 19-9-21
 * Time: 上午10:54
 */

/*
 * Register Server 使用Tcp Server 作为注册中心
 * 进程数据共享 swoole_table
 * 一般内网服务器
 */

echo "RegisterServer Start \n";

$server = new swoole_server("127.0.0.1", 9501);
/*
$server->set([
    'heartbeat_idle_time' => 600,
    'heartbeat_check_interval' => 60,
]);
*/

$table = new \Swoole\Table(1024);
$table->column('fd', swoole_table::TYPE_INT, 4);
$table->column('type', swoole_table::TYPE_STRING, 14);
$table->create();

$server->table = $table;

$table1 = new \Swoole\Table(1024);
$table1->column('ip_port', swoole_table::TYPE_STRING, 24);
$table1->create();

$server->table1 = $table1;


$server->on('connect', function ($server, $fd){
//    echo "connection open: {$fd}\n";
});
$server->on('receive', function ($server, $fd, $reactor_id, $data) {

    if($data === 'PING') return;

    $data = json_decode($data, true);
    if(!isset($data['action']) || !isset($data['server'])) // 非法请求
    {
        echo "1\n";
        $server->close($fd);
        return ;
    }

    if(!in_array($data['server'], ['gateway', 'businessWorker']) || !in_array($data['action'], ['connect', 'close', 'heartbeat']))
    {
        echo "2\n";
        $server->close($fd);
        return;
    }

//    $fdinfo = $server->getClientInfo($fd);
    $ipAndPort = $data['ip'].":".$data['port'];

    if($data['action'] === 'connect') // 上线通知请其他server链接
    {
        $server->table->set($ipAndPort, ['fd' => $fd, 'type' => $data['server']]);
        $server->table1->set($fd, ['ip_port' => $ipAndPort]);
        noticeServer($server, $data['server'] === 'gateway' ? 'businessWorker' : 'gateway', json_encode([
            'action' => $data['action'],
            'ipAndPort' => $ipAndPort
        ]));
    }
    elseif($data['action'] === 'close') // 下线通知请其他server链接
    {
        $ipAndPort = $server->table1->get($fd, 'ip_port');
        if(!empty($ipAndPort)){
            $server->table1->del($fd);
            $type = $server->table->get($ipAndPort, 'type');
            $server->table->del($ipAndPort);
            $type = $type === 'gateway' ? 'businessWorker' : 'gateway';
        }else{
            $type = 'default';
        }

        noticeServer($server, $type, json_encode([
            'action' => $data['action'],
            'ipAndPort' => $ipAndPort
        ]));
    }

});
$server->on('close', function ($server, $fd) {

    $ipAndPort = $server->table1->get($fd, 'ip_port');
    if(!empty($ipAndPort)){
        $server->table1->del($fd);
        $type = $server->table->get($ipAndPort, 'type');
        $server->table->del($ipAndPort);
        $type = $type === 'gateway' ? 'businessWorker' : 'gateway';
    }else{
        $type = 'default';
    }

    noticeServer($server, $type, json_encode([
        'action' => 'close',
        'ipAndPort' => $ipAndPort
    ]));
});

function noticeServer($server, $type, $msg)
{
    $table = $server->table;

    $type1 = $type === 'gateway' ? 'businessWorker' : 'gateway';
    echo $type1.' '.$msg.PHP_EOL;

    if($type === 'gateway') return;

    foreach ($table as $value)
    {
        if($value['type'] === $type)
        {
            $server->send($value['fd'], $msg);
        }
        elseif($value['type'] === 'default')
        {
            $server->send($value['fd'], $msg);
        }

    }
}

$server->start();