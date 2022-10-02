<?php

namespace sscf;

class SSLog
{
    public static function debug()
    {
        SSLog::printinfo(SSLog::getArgs(func_get_args()), 'DEBUG');
    }
    public static function debugTrace()
    {
        SSLog::printinfo(SSLog::getArgs(func_get_args()), 'DEBUG');
        SSLog::printTrace();
    }

    public static function info()
    {
        SSLog::printinfo(SSLog::getArgs(func_get_args()), 'INFO');
    }
    public static function infoTrace()
    {
        SSLog::printinfo(SSLog::getArgs(func_get_args()), 'INFO');
        SSLog::printTrace();
    }

    public static function warn()
    {
        SSLog::printinfo(SSLog::getArgs(func_get_args()), 'WARN');
    }

    public static function warnTrace()
    {
        SSLog::printinfo(SSLog::getArgs(func_get_args()), 'WARN');
        SSLog::printTrace();
    }



    public static function error()
    {
        SSLog::printinfo(SSLog::getArgs(func_get_args()), 'ERROR');
    }

    public static function errorTrace()
    {
        SSLog::printinfo(SSLog::getArgs(func_get_args()), 'ERROR');
        SSLog::printTrace();
    }

    private static function getArgs($args){
        switch (sizeof($args)) {
            case 0:
                return "";
            case 1:
                return $args[0];
                break;
            default:
                return $args;
        }
    }


    /**
     * 打印信息
     * @param traces 调用栈信息
     * @param level 日志级别
     * @param msg 日志信息
     * @param trace 是否打印调用栈
     */
    public static function printinfo($msg, $level)
    {
        $traces = SSLog::simple_trace(debug_backtrace());
        date_default_timezone_set('Asia/Shanghai');
        $time = date("Y-m-d H:i:s", time());
        $trace = $traces[1];
        preg_match('/.*\/(.*)/', $trace['file'], $fs);
        echo PHP_EOL . "{$time} [{$level}]:[{$fs[1]}][{$trace['line']}]-";

        $scf = new SScf();
        if($scf->isScf()){
            if(is_object($msg)||is_array($msg)){
                print_r(json_encode($msg, JSON_UNESCAPED_UNICODE));
            }else{
                print_r($msg);
            }
        }else{
            print_r($msg);
        }
        
        // echo "\r\n";
        return;

    }

    public static function printTrace()
    {
        $traces = SSLog::simple_trace(debug_backtrace());
        date_default_timezone_set('Asia/Shanghai');
        $time = date("Y-m-d H:i:s", time());
        for ($i = 0; $i < sizeof($traces); ++$i) {
            if ($i == 0) {
                continue;
            }
            $trace = $traces[$i];
            $fun = "";
            if ($i < sizeof($traces) - 1) {
                //当前函数名在上一个
                $fun = $traces[$i + 1]['fun'];
            }
            preg_match('/.*\/(.*)/', $trace['file'], $fs);
            $fn = $fs[1];
            if ($i == 1) {

                //调试信息
                echo PHP_EOL . "------------------------ 调用栈 ---------------------";
                $firline = PHP_EOL."[{$fn}][{$trace['line']}][{$fun}]-";
                echo $firline;

            }
            //调用栈列表
            $argtxt = json_encode($trace['arg'], JSON_UNESCAPED_UNICODE);
            echo PHP_EOL . "[{$fn}][{$trace['line']}]{$trace['fun']}-参数:";
            if (sizeof($trace['arg']) > 0) {
                print_r($trace['arg']);
            } else {
                echo '{}';
            }

            if ($i == sizeof($traces) - 1) {
                echo PHP_EOL . "--------------------------------------------------" . PHP_EOL;
            }
        }
    }

    public static function simple_trace($traces)
    {
        $loginfos = [];
        // $split = "/";
        // if (strpos(PHP_OS, "W") === 0 || strpos(PHP_OS, "w") === 0) {
        //     $split = "\\";
        // }

        foreach ($traces as $trace) {
            $tmp = [
                "file" => $trace["file"],
                "line" => $trace['line'],
                "fun" => $trace['function'],
                "arg" => $trace['args'],
            ];
            array_push($loginfos, $tmp);
        }
        return $loginfos;
    }
}
