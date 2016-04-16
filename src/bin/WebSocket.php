<?php
require __DIR__ . '/../../vendor/autoload.php';
use Pheanstalk\Pheanstalk;

class WebSocket extends Base
{
    private $sockets;
    private $users;
    private $master;
    private $shm_id;
    private $beanstalk;

    public function __construct($address, $port){
        $key = ftok(__DIR__, 'a');
        //$this->shm_id = shmop_open($key, "c", 0644, 1024);
        $this->shm_id = shm_attach($key, 1024, 777);
        $this->master = $this->WebSocket($address, $port);
        $this->sockets = array('s' => $this->master);
        $this->beanstalk = new Pheanstalk('127.0.0.1');
    }

    public function run() {
        while (true) {
            $write = NULL;
            $except = NULL;
            echo 'sss';
            socket_select($this->sockets, $write, $except, NULL);

            foreach ($this->sockets as $sock){
                if ($sock == $this->master) {
                    $client = socket_accept($this->master);
                    $this->sockets[] = $client;
                    $this->users[] = array(
                            'socket'=>$client,
                            'shou'=>false
                            );
                    echo "connect success!\n";
                } else {
                    $len = @socket_recv($sock,$buffer,2048,0);
                    $k = $this->search($sock);

                    if ($len == 0) {
                        continue;
                    }

                    if (!$this->users[$k]['shou']) {
                        $this->woshou($k,$buffer);
                        //向共享内存更新用户信息
                        //shmop_write($this->shm_id, json_encode($this->users), 0);
                        shm_put_var($this->shm_id, 122, $this->users);
                        var_dump($this->users);
                        var_dump(json_encode($this->users));
                        echo "woshou success!\n";
                    } else {
                        $data = $this->uncode($buffer);
                        $this->saveData($data);
                        echo $data;
                    }
                }
            }
        }
    }

    /**
     * 将用户数据入接收数据队列
     */
    private function saveData($data) {
        $this->beanstalk->putInTube('recieve', json_encode($data));
    }

    private function close($sock) {
        $k = array_search($sock, $this->sockets);
        socket_close($sock);
        unset($this->sockets[$k]);
        unset($this->users[$k]);
        //log
    }

    private function search($sock) {
        foreach ($this->users as $k => $v) {
            if ($sock == $v['socket']) {
                return $k;
            }
        }

        return false;
    }

    private function WebSocket($address, $port) {
        $server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($server, $address, $port);
        socket_listen($server);
        //log
        return $server;
    }

    private function woshou($k, $buffer){
        $buf = substr($buffer, strpos($buffer, 'Sec-WebSocket-Key:')+18);
        $key = trim(substr($buf, 0, strpos($buf, "\r\n")));
        $new_key = base64_encode(sha1($key."258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
        $new_message = "HTTP/1.1 101 Switching Protocols\r\n";
        $new_message .= "Upgrade: websocket\r\n";
        $new_message .= "Sec-WebSocket-Version: 13\r\n";
        $new_message .= "Connection: Upgrade\r\n";
        $new_message .= "Sec-WebSocket-Accept: " . $new_key . "\r\n\r\n";
        socket_write($this->users[$k]['socket'], $new_message, strlen($new_message));
        $this->users[$k]['shou']=true;
        return true;
    }

    private function uncode($str){
        $mask = array(); 
        $data = ''; 
        $msg = unpack('H*', $str); 
        $head = substr($msg[1], 0, 2); 
        if (hexdec($head{1}) === 8) { 
            $data = false; 
        } elseif (hexdec($head{1}) === 1){ 
            $mask[] = hexdec(substr($msg[1],4,2)); 
            $mask[] = hexdec(substr($msg[1],6,2)); 
            $mask[] = hexdec(substr($msg[1],8,2)); 
            $mask[] = hexdec(substr($msg[1],10,2)); 
            $s = 12; 
            $e = strlen($msg[1])-2; 
            $n = 0; 
            for ($i=$s; $i<= $e; $i+= 2) { 
                $data .= chr($mask[$n%4]^hexdec(substr($msg[1],$i,2)));
                $n++; 
            } 
        } 

        return $data;
    }

    private function code($msg){
        $msg = preg_replace(array('/\r$/', '/\n$/', '/\r\n$/', ), '', $msg);
        $frame = array(); 
        $frame[0] = '81'; 
        $len = strlen($msg); 
        $frame[1] = $len<16?'0'.dechex($len):dechex($len); 
        $frame[2] = $this->ord_hex($msg); 
        $data = implode('', $frame); 

        return pack("H*", $data); 
    }
}

$test = new WebSocket('127.0.0.1', '9880');
$test->run();
