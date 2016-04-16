<?php
class Base 
{
    private $shm_id;
    public function __construct($shmid) {
        if ($shmid) {
            $this->shm_id = $shmid;
        } else {
            $key = ftok(__DIR__, 'a');
            $this->shm_id = shm_attach($key, 1024, 777);
        }
    }

    /**
     * getDistance
     * 计算两点间的距离
     * @ return array
     **/
    public function getDistance($x1, $y1, $x2, $y2) {
        $a = abs($x1-$x2);
        $b = abs($y1-$y2);
        return (float)sqrt($a*$a + $b*$b);
    }

    public function readShare() {
        return shm_get_var($this->shm_id, 111);
    }

    public function writeShare($value) {
        shm_put_var($this->shm_id, 111, $value);
    }

    public function createSocket() {
    }

    public function sendSocket() {
    }

    public function revSocket() {
    }
}
