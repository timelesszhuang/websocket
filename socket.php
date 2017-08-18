<?php
//socket.php
set_time_limit(0);//永不超时
class socket
{
    private $host = '192.168.0.101';
    private $port = 8080;
    private $maxuser = 10;
    public $accept = array(); //连接的客户端
    private $cycle = array(); //循环连接池
    private $isHand = array();//握手信息


    //加载socket配置
    function __construct($host, $port, $max)
    {
        $this->host = $host;
        $this->port = $port;
        $this->maxuser = $max;
    }

    //挂起socket
//socket_accept()- Accepts a connection on a socket  接受一个socket连接
//socket_bind()- 给套接字绑定名字
//socket_connect()- 开启一个套接字连接
//socket_create()- 创建一个套接字（通讯节点）
//socket_strerror()- Return a string describing a socket error
    public function start_server()
    {
        //创建一个socket
        //socket_create
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        //配置socket
        //SO_REUSEADDR报告是否本地地址可以重复使用
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, TRUE);
        //绑定接口，最多10个人连接，超过的客户端连接会返回WSAECONNREFUSED错误
        //给套接字绑定名字
        //绑定 address 到 socket。 该操作必须是在使用 socket_connect() 或者 socket_listen() 建立一个连接之前。
        socket_bind($this->socket, $this->host, $this->port);
        //监听接口 侦听套接字上的连接
        socket_listen($this->socket, $this->maxuser);
        while (TRUE) {
            //获取所有socket连接
            $this->cycle = $this->accept;
            $this->cycle[] = $this->socket;

            //阻塞用，有新连接时才会结束
            socket_select($this->cycle, $write, $except, null);
            foreach ($this->cycle as $k => $v) {
                //当socket运行到连接池最后一位时，开始添加连接
                if ($v === $this->socket) {
                    //如果socket连接失败，跳出本次循环
                    //socket_accept接受一个socket连接
                   // 如果成功返回一个新的套接字资源，或FALSE误差。实际的错误代码可以通过调用socket_last_error()。此错误代码可以通过socket_strerror()得到错误的文本解释。
                    if (($accept = socket_accept($v)) < 0) {
                        continue;
                    }
                    //连接成功加入连接池
                    $this->add_accept($accept);
                    continue;
                }

                //在连接池中搜索socket连接ID
                $acceptId = array_search($v, $this->accept);
                //如果连接没有保存至连接池，跳出本次循环
                if ($acceptId === NULL) {
                    continue;
                }
                //没消息的socket就断开
                if (!@socket_recv($v, $data, 1024, 0) || !$data) {
                    $this->close($v);
                    continue;
                }
                //检查是否握手
                if (!$this->isHand[$acceptId]) {
                    //进行握手
                    $this->upgrade($v, $data, $acceptId);
                    continue;
                }
                //将数据进行解码
                $data = $this->decode($data);
                //将信息返回给客户端
                $this->send_to_user($v, $data);
            }
            sleep(1);
        }
    }

    /**
     * 新建一个连接
     * @param $accept 套接号
     */
    private function add_accept($accept)
    {
        $this->accept[] = $accept;
        $accept = array_keys($this->accept);
        //获取新连接的key值，用于跟握手信息绑定
        $acceptId = end($accept);
        //添加初次连接用户握手信息
        $this->isHand[$acceptId] = FALSE;
    }


    /**
     * 关闭一个连接
     * @param $accept 套接号
     */
    private function close($accept)
    {
        //在连接池中搜索套接号
        $acceptId = array_search($accept, $this->accept);
        //断开socket连接
        socket_close($accept);
        //销毁变量
        unset($this->cycle[$acceptId]);
        unset($this->accept[$acceptId]);
        unset($this->isHand[$acceptId]);
    }

    /**
     * 响应升级协议，与websocket进行握手
     * @param $accept 套接号
     * @param $data websocket发送的数据
     * @param $acceptId 与套接号绑定的Id
     */
    private function upgrade($accept, $data, $acceptId)
    {
        //用正则表达式获取websocket传输过来的key值
        if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $data, $match)) {
            //服务端生成对应key值返回
            $key = base64_encode(sha1($match[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            $upgrade = "HTTP/1.1 101 Switching Protocol\r\n" .
                "Upgrade: websocket\r\n" .
                "Connection: Upgrade\r\n" .
                "Sec-WebSocket-Accept: " . $key . "\r\n\r\n";  //必须以两个回车结尾
            //向套接口写入数据
            socket_write($accept, $upgrade, strlen($upgrade));
            $this->isHand[$acceptId] = TRUE;
        }
    }

    /**
     * 编码信息
     * @param $data 需要编码的数据
     */
    private function frame($data)
    {
        //将数据转为数组，一个元素长度最高为125
        $array = str_split($data, 125);
        //添加头文件信息，不然前台无法接受
        if (count($array) == 1) {
            return "\x81" . chr(strlen($array[0])) . $array[0];
        }
        $ns = "";
        foreach ($data as $v) {
            $ns .= "\x81" . chr(strlen($v)) . $v;
        }
        return $ns;
    }

    /**
     * 按照websocket协议进行解码
     * @param $buffer 需要解码的数据
     */
    private function decode($buffer)
    {
        $len = $masks = $data = $decoded = null;
        //获取传递过来数据长度
        $len = ord($buffer[1]) & 127;
        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);

        } else if ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        //
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }
        return $decoded;
    }

    /**
     * 向所有连接至socket的用户发送消息
     * @param $accept 套接号
     * @param $data 发送的数据
     */
    private function send_to_user($accept, $data)
    {
        //添加头文件信息
        $data = $this->frame($data);
        //写入socket从给定的缓冲区
        socket_write($accept, $data, strlen($data));

    }
}


?>
