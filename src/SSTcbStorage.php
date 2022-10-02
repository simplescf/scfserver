<?php

namespace sscf;

require_once __DIR__ . "/ssautoload.php";

use TencentCloudBase\TCB;

class SSTcbStorage
{
    private $storage;
    function __construct(){
        $conf = new SSConf();
        $tcb = $conf->loadByKey("tcb");
        $qk = $conf->loadByKey("qcloudkey");
        $this->storage = (new Tcb([
            'secretId' => $qk["secid"],
            'secretKey' => $qk["seckey"],
            'env' => $tcb["envid"]
        ]))->getStorage();
    }

    public function uploadFile(){
        $fileContent = fopen('./tests/1.jpg', 'r');
        $cloudPath = 'a|b.jpeg';
        $fileResult = $this->storage->uploadFile(array('cloudPath' => $cloudPath, 'fileContent' => $fileContent));
    }
}
