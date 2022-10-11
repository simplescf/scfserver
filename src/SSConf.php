<?php
/*
 * @Author: tsr
 * @Date: 2020-02-03 12:03:51
 * @LastEditTime: 2021-10-21 22:08:54
 * @LastEditors: tsr
 * @Description: 读取解析配置文件
 */
namespace sscf;

class SSConf
{
    private $conpath;
    private $dbPath;

    
    /**
     * 配置文件路径
     */
    function __construct(){        
        $this->conpath = getcwd()."/config.json";
        $this->dbPath = getcwd()."/db.json";
    }

    public function loadDb()
    {
        $scf = new SScf();
        $app = json_decode(
            trim(
                file_get_contents($this->conpath)
            ),
            JSON_OBJECT_AS_ARRAY
        );

        $key = "remote_db";
        if ($scf->isScf()) {
            $key = "local_db";
        }
        if (getenv("DB_REMOTE")) {
            $key = "remote_db";
        }
        if (array_key_exists($key, $app)) {
            return $app[$key];
        } else {
            return FALSE;
        }
    }

    public function loadXcx()
    {
        $app = json_decode(
            trim(
                file_get_contents($this->conpath)
            ),
            JSON_OBJECT_AS_ARRAY
        );
        $key = "wxxcx";
        if (array_key_exists($key, $app)) {
            return $app[$key];
        } else {
            return FALSE;
        }
    }

    /**
     * 读取key对应的配置选项
     */
    public function loadByKey($key)
    {
        $url = $this->conpath;
        //老配置文件兼容
        if($key=='db'){
            $url = $this->dbPath;
        }
        $app = json_decode(
            trim(
                file_get_contents($url)
            ),
            JSON_OBJECT_AS_ARRAY
        );
        if(is_null($app)){
            SSLog::error("配置文件格式错误,{$url}");
            return FALSE;    
        }
        
        if (array_key_exists($key, $app)) {
            return $app[$key];
        } else {
            SSLog::error("未配置{$key}环境变量");
            return FALSE;
        }
    }
}
