<?php
/*
 * @Author: your name
 * @Date: 2020-12-13 12:20:03
 * @LastEditTime: 2021-10-27 19:29:31
 * @LastEditors: Please set LastEditors
 * @Description: In User Settings Edit
 * @FilePath: /simplescf/src/SSRsa.php
 */

namespace sscf;

require_once __DIR__ . "/ssautoload.php";


class SSRsa
{
    private $rsa = [];
    function __construct(){
        $this->rsa = (new SSConf())->loadByKey('rsa');
    }

    /**
     * @description: 加密
     * @param {string} 被加密的数据
     * @return {mix} false 加密失败/密钥无效, 成功返回string 
     */    
    public function encrypt($data){
        if($this->rsa==FALSE){
            return FALSE;
        }
        $pu_key = openssl_pkey_get_public(implode("\n", $this->rsa['public']));
        if(openssl_public_encrypt($data, $encrypted, $pu_key)){
            return base64_encode($encrypted); 
        }
        return false;
        
    }

    /**
     * @description: 解密
     * @param {string} 要解密的加密字符串
     * @return string|bool false解密失败,成功返回string
     */    
    public function decrypt($data){
        if($this->rsa==FALSE){
            return FALSE;
        }
        $pi_key = openssl_pkey_get_private(implode("\n", $this->rsa['private']) );
        if(openssl_private_decrypt(base64_decode($data), $decrypted, $pi_key)){
            return $decrypted;
        }
        return false;
    }
}
