<?php
require('socket.php');
//设置连接信息
$ws = new socket('192.168.0.101', '8080', 10);
//开启socket服务端
$ws->start_server();