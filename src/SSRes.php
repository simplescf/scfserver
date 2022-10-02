<?php
namespace sscf;

/*
 * @Author: tao zhang
 * @Date: 2021-05-07 15:15:32
 * @LastEditTime: 2021-05-07 15:55:16
 * @LastEditors: Please set LastEditors
 * @Description: 统一执行结果
 */

class SSRes
{
    private $errorCode; //结果代码,0成功其他失败
    private $errorMessage; //错误结果信息
    private $data; //成功结果信息

    /**
     * 执行成功信息
     */
    public function success($data)
    {
        $this->errorCode = 0;
        $this->data = $data;
        return $this;
    }

    /**
     * 执行失败信息
     */
    public function error($data)
    {
        $this->errorMessage = $data;
        return $this;
    }

    /**
     * 设置返回代码
     */
    public function setCode($code)
    {
        $this->errorCode = $code;
        return $this;
    }

    /**
     * 执行结果是否为成功
     */
    public function isSuccess(){
        if($this->errorCode==0){
            return true;
        }
        return false;
    }

    /**
     * 获取执行成功/失败的结果信息
     */
    public function getData(){
        if($this->isSuccess()){
            return $this->data;
        }
        return $this->errorMessage;
    }

    /**
     * 成功信息
     */
    public static function formatSuccess($data)
    {
        $res = new SSRes();
        return $res->success($data);
    }

    
    /**
     * @description: 失败信息
     * @param object $data 执行结果信息
     * @param int $code 执行结果代码
     * @return
     */
    public static function formatError($data, $code = 1)
    {
        $res = new SSRes();
        return $res->error($data)->setCode($code);
    }
}
