<?php
class WaterDrop extends Base
{

    private $revInfo;      // 客户端指令数据
    private $socket;
    private $resInfo;      // 返回客户端数据
    private $userInfo;     //玩家(水滴)数据
    private $lasTime;      // 上次返回数据时间(ms)
    private $dropTypeList = [
        'red',
        'green',
        'blue',
        ];

    const SOCKET_HOST = '127.0.0.1';
    const SOCKET_PORT = '9880';

    const SHARE_CLEAR = NULL;

    const DROP_STATUS_DEAD = 0;
    const DROP_STATUS_LIVE = 1;

    const EAT_MEAT_SUCCESS = 1;
    const EAT_DROP_SUCCESS = 2;
    const EAT_DROP_FAILED  = 3;
    
    const ORDER_MOVE    = 1; // 移动指令
    const ORDER_CONNECT = 1; // 移动指令

    const CONF_DROP_POWER       = 10;    //初始能量
    const CONF_DROP_TOWARDS     = '1,0'; //初始方向
    const CONF_DROP_SPEED       = 30;    //初始速度
    const CONF_DROP_RADIUS      = 5;     //水滴半径
    const CONF_DROP_SPECTIVE    = 10;    //视野半径
    const CONF_DROP_STATUS      = 1;     //水滴状态
    const CONF_CRON_TIME        = 30;    //返回数据时间间隔
    const CONF_MAP_COORD_X      = 200;   //横坐标最大值
    const CONF_MAP_COORD_Y      = 200;   //纵坐标最大值
    const CONF_MEAT_INIT_NUM    = 30;    //初始中立生物数量
    const CONF_MEAT_REFRESH_NUM = 30;    //中立生物刷新数量条件阈值


    public function __construct() {
        $this->socket = socket_create(AF_INET,SOCK_STREAM,0);
        if ($this->socket < 0)
            return false;
        $result = @socket_connect($this->socket, self::SOCKET_HOST, self::SOCKET_PORT);
        if ($result == false)
            return false;

        $cronTime = 0;
        $shmid= '';
        $this->initMeatMap();
        parent::__construct($shmid);
    }

    public function run() {
        while(true) {
            // 从共享内存获取数据，使用信号量保证原子操作
            $getInfo = $this->readShare();
            if ($getInfo) { 
                $this->revInfo = $getInfo;
                $this->writeShare(self::CLEAR_SHARE);
            }

            $this->synOrder();

            $this->cronDeal();
        }
    }

    /**
     * cronDeal
     * 定时计算并返回数据
     * @ return null
     **/
    private function cronDeal() {
        $now = microtime();
        $timeDiff = $now-$this->lastTime;
        if ($timeDiff >= self::CONF_CRON_TIME) {
            $this->lastTime = $now;

            // TODO compute and send
            $mapInfo = $this->getUserMapInfo();
            foreach ($this->userInfo as $userId => $user) {
                $resInfo[$userId] = [
                    'user_info' => [
                        'towards' => $user['towards'],
                        'speed'   => self::CONF_DROP_SPEED,
                        'x'       => $newCoord['x'],
                        'y'       => $newCoord['y'],
                        'color'   => $user['color'],
                        'power'   => $user['power'],
                        'color'   => $user['color'],
                        'status'  => $user['status'],
                    ],
                    'map_info'  => $mapInfo[$userInfo],
                ];
            }

            $this->updateUserInfo();

            $this->send();
            $this->revInfo = [];
        }

        if ($timeDiff >= self::CONF_MEAT_REFRESH_TIME) {
            $this->refreshMeat();
        }
    }

    /**
     * refreshMeat
     * 刷新中立生物
     * @ return null
     **/
    private function refreshMeat() {
        if (count($this->meatList) <=  self::CONF_MEAT_REFRESH_NUM) {
            $map = [];
            $refreshNum = self::CONF_MEAT_INIT_NUM - count($this->meatList);
            for ($x = 0; $x < self::CONF_MAP_COORD_X; $x++) {
                for ($y = 0; $y < self::CONF_MAP_COORD_Y; $y++) {
                    $map[$x.','.$y] = 0;
                }
            }
            foreach ($this->meatList as $meat) {
                unset($map[$meat['x'].','.$meat['y']]);
            }
        }

        $randMap = array_rand($map, $refreshNum);

        foreach ($randMap as $coord => $rand) {
            $arr = explode(',', $coord);
            $this->meatList[] = [
                'x' => $arr[0],
                'y' => $arr[1],
            ];
        }
    }

    /**
     * initMeatMap
     * 初始化中立生物
     * @ return null
     **/
    private function initMeatMap() {
        $x = 0;
        $y = 0;
        $map = [];
        for ($x; $x < self::CONF_MAP_COORD_X; $x++) {
            $mapX[] = $x;
        }
        for ($y; $y < self::CONF_MAP_COORD_Y; $y++) {
            $mapY[] = $y;
        }

        $randX = array_rand($mapX, self::CONF_MEAT_INIT_NUM);
        $randY = array_rand($mapY, self::CONF_MEAT_INIT_NUM);

        for ($i = 0; $i < self::CONF_MEAT_INIT_NUM; $i++) {
            $this->meatList[] = [
                'x' => $mapX[$i],
                'y' => $mapY[$i],
            ];
        }
    }

    /**
     * updateUserMapInfo
     * 更新地图信息
     * @ return null
     **/
    private function updateUserInfo() {
        // 更新水滴状态
        foreach ($this->eatRecord as $userId => $record) {
            switch ($record['status']) {
                case self::EAT_DROP_SUCCESS :
                    $this->userInfo[$userId] += self::EAT_DROP_SUCCESS_POWER;
                    break;
                case self::EAT_DROP_FAILED :
                    $this->userInfo[$userId] -= self::EAT_DROP_FAILED_POWER;
                    if ($this->userInfo[$userId] <= 0) {
                        $this->initDropInfo($userId);
                    }
                    break;
                case self::EAT_MEAT_SUCCESS :
                    $this->userInfo[$userId] += self::EAT_MEAT_SUCCESS_POWER;
                    unset($this->meatList[$record['enemyId']]);
                    break;
                default :
                    break;
            }
        }
        $this->eatRecord = [];

        // 更新水滴坐标和方向
        foreach ($this->userInfo as &$user) {
            $order = $user['order'];
            if (!$order) {
                continue;
            }
            switch ($order['cmd']) {
                case self::ORDER_MOVE :
                    $this->updateDropCoord($user);
                    break;
                default :
                    break;
            }
        }
    }

    /**
     * updateDropCoord
     * 更新水滴坐标和方向
     * @ return null
     **/
    private function updateDropCoord(&$user) {
        $diffX = $user['order']['x'] - $user['x'];
        $diffY = $user['order']['y'] - $user['y'];

        if ($tanX != 0 || $tanY != 0) {
            $distance = $this->getDistance($user['order']['x'], $user['order']['y'], $user['x'], $user['y']);
            $towards = implode(',', $diffX, $diffY);
            $coordX = $user['order']['x'] + self::CONF_DROP_SPEED*$diffX/$distance;
            $coordY = $user['order']['y'] + self::CONF_DROP_SPEED*$diffY/$distance;

            $coordX = ($coordX < 0) ? 0 : $coordX;
            $coordX = ($coordX > self::CONF_MAP_COORD_X) ? self::CONF_MAP_COORD_X : $coordX;
            $coordY = ($coordY < 0) ? 0 : $coordY;
            $coordY = ($coordY > self::CONF_MAP_COORD_Y) ? self::CONF_MAP_COORD_Y : $coordY;

            $user['towards'] = $towards;
            $user['x'] = $coordX;
            $user['y'] = $coordY;
        }
    }

    /**
     * checkMater
     * 判断对象是否能吃
     * @ return bool
     **/
    private function checkMaster($mainColor, $color) {
        switch ($mainColor) {
            case 'red'   :
                return $color == 'green' ? false : true;
            case 'green' :
                return $color == 'blue' ? false : true;
            case 'blue'  :
                return $color == 'red' ? false : true;
            default      :
                break;
        }
    }

    /**
     * getEatResult
     * 判断对象是否能吃
     * @ return bool
     **/
    private function getEatResult($mainId, $id) {
        $isMaster = $this->checkMaster($this->userInfo[$mainId]['color'], $this->userInfo[$id]['color']); 

        if ($isMaster && $this->userInfo[$mainId]['power'] * 2 > $this->userInfo[$Id]['power'] 
                || $this->userInfo[$mainId]['power'] >= $this->userInfo[$id]['power'] * 2) {
            return self::EAT_DROP_SUCCESS;
        } else {
            return self::EAT_DROP_FAILED;
        }
    }

    /**
     * getUserMeatInfo
     * 获取水滴视野内中立生物
     * @ return array
     **/
    private function getUserMeatInfo($userId) {
        $res = [];
        foreach ($this->meatList as $meatId => $meat) {
            $distance = $this->getDistance(
                    $this->userInfo[$userId]['x'], $this->userInfo[$userId]['y'],
                    $meat['x'], $meat['y']);

            if ($distance <= $this->userInfo[$userId]['radius']) {
                $this->eatRecord[$mainId] = [
                    'status' => self::EAT_MEAT_SUCCESS,
                    'enemyId'  => $meatId,
                ];
            }

            if ($distance < self::CONF_DROP_SPECTIVE) {
                $res[] = $meat;
            }
        }

        return $res;
    }

    /**
     * getUserMapInfo
     * 获取水滴视野地图信息
     * @ return array
     **/
    private function getUserMapInfo() {
        foreach ($this->userInfo as $mainId => $mainUser) {
            $res[$mainId] = [
                'users' => [],
                'meats' => $this->getUserMeatInfo($mainId),
            ];
            foreach ($this->userInfo as $id => $user) {
                if ($user['status'] == self::DROP_STATUS_LIVE && $mainId != $id) {
                    $distance = $this->getDistance(
                            $mainUser['x'], $mainUser['y'], 
                            $user['x'], $user['y']);
                    // eat action
                    if ($distance <= $mainUser['radius']+$user['radius']) {
                        $this->eatRecord[$mainId] = [
                            'status' => $this->getEatResult($mainId, $id),
                            'enemyId'  => $id,
                        ];
                    }

                    if ($distance <= self::CONF_DROP_SPECTIVE) {
                        $res[$mainId]['users'][] = [
                            'user_id' => $user['id'],
                            'color' => $user['color'],
                            'power' => $user['power'],
                            'x' => $user['x'],
                            'y' => $user['y'],
                        ];
                    }
                }
            }
        }
    }

    /**
     * synOrder
     * 同步指令信息
     * @ return null
     **/
    private function synOrder() {
        if (empty($this->revInfo)) {
            return;
        }
        switch ($this->revInfo['cmd']) {
            case self::ORDER_MOVE :
                $this->userInfo[$this->revInfo['user_id']]['order'] = [
                    'time' => $this->revInfo['time'],
                    'type' => $this->revInfo['type'],
                    'x' => $this->revInfo['x'],
                    'y' => $this->revInfo['y'],
                ];
                break;
            case self::ORDER_CONNECT :
                if (!isset($this->userInfo[$this->revInfo['user_id']])) {
                    $this->userInfo[$this->revInfo['user_id']] = $this->initUserInfo();
                }
                break;
            default :
                break;
        }
    }

    /**
     * initUserInfo
     * 初始化水滴数据
     * @ return null
     **/
    private function initUserInfo() {
        $types = $this->dropTypeList;
        $exist = [];

        if (count($this->userInfo) >= self::CONF_DROP_NUM) {
            return;
        }

        foreach ($this->userInfo as $user) {
            if (in_array($user['color'], $types)) {
                $exist[] = $user['color'];
            }
        }
        $res = array_diff($types, $exist);

        $this->userInfo[$this->revInfo['user_id']] = [
            'color'    => $res[0],
            'x'        => rand(0, self::CONF_MAP_COORD_X),
            'y'        => rand(0, self::CONF_MAP_COORD_Y),
            'power'    => self::CONF_DROP_POWER,
            'towards'  => self::CONF_DROP_TOWARDS,
            'speed'    => self::CONF_DROP_SPEED,
            'radius'   => self::CONF_DROP_RADIUS,
            'spective' => self::CONF_DROP_SPECTIVE,
            'status'   => self::CONF_DROP_STATUS,
        ];
    }

    /**
     * send
     * 通过socket发送返回数据
     * @ return null
     **/
    private function send() {
        $sendInfo = json_encode($this->resInfo);
        socket_write($this->socket, $sendInfo, strlen($sendInfo));
    }
}
