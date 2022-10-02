<?php
/*
 * @Author: your name
 * @Date: 2021-06-20 20:41:27
 * @LastEditTime: 2021-06-21 17:08:23
 * @LastEditors: Please set LastEditors
 * @Description: In User Settings Edit
 * @FilePath: /simplescf/src/SSWechatApp.php
 */
namespace sscf;
require_once __DIR__ . "/ssautoload.php";
require_once __DIR__ . "/lib/wxpay/lib/WxPay.Api.php";
require_once __DIR__ . "/lib/wxpay/pay/WxPay.JsApiPay.php";
require_once __DIR__ . '/lib/wxpay/pay/WxPay.Config.php';
/**
 * 公众号-微信网页开发
 */
class SSWechatApp
{

    private $scf;
    function __construct(){
        $scf = new SScf();
    }

   

    /**
     * 
     * 授权获取ak
     * @return array 成功返回['access_token','openid','unionid','expires_in',''refresh_token','scope'],\
     * 失败返回[errcode, errmsg]
     */
    public function getAk($code){
        $conf = new SSConf();
        $app = $conf->loadByKey("openwechat");
        $url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid={$app['appid']}&secret={$app['appsec']}&code={$code}&grant_type=authorization_code";
        $data = SSUtil::get($url);
        return json_decode($data, JSON_OBJECT_AS_ARRAY);
    }

    /**
     * 查询用户信息
     */
    public function getUserInfo($openid, $ak){
        $conf = new SSConf();
        $url = "https://api.weixin.qq.com/sns/userinfo?access_token={$ak}&openid={$openid}&lang=zh_CN";
        return json_decode(SSUtil::get($url), JSON_OBJECT_AS_ARRAY);
    }

    /**
     * 根据APP端的code获取用户信息
     */
    public function getUserInfoByCode($code){
        $code = $this->getAk($code);
        if(isset($code['errcode'])){
            return $code;
        }
        return $this->getUserInfo($code['openid'], $code['access_token']);
    }

    
}
