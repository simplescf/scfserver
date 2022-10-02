<?php
namespace sscf;
require_once __DIR__ . "/ssautoload.php";
require_once __DIR__ . "/lib/wxpay/lib/WxPay.Api.php";
require_once __DIR__ . "/lib/wxpay/pay/WxPay.JsApiPay.php";
require_once __DIR__ . '/lib/wxpay/pay/WxPay.Config.php';
/**
 * 公众号-微信网页开发
 */
class SSWechatWeb
{

    private $scf;
    function __construct(){
        $scf = new SScf();
    }

    /**
     * 获取后台业务的ak
     */
    public function getServerAk(){
        $conf = new SSConf();
        $xcx = $conf->loadByKey("wxmp");
        $url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$xcx['appid']}&secret={$xcx['appsec']}";
        return json_decode(SSUtil::get($url), JSON_OBJECT_AS_ARRAY);
    }

    /**
     * 查看用户的详细信息-
     * 用户管理->用户基本信息
     */
    public function getMpUserInfo($openid){
        $scf = new SScf();
        $ak = $scf->getOwnOpt("ak", "getuserinfo");
        $url = "https://api.weixin.qq.com/cgi-bin/user/info?access_token={$ak}&openid={$openid}&lang=zh_CN";
        return json_decode(SSUtil::get($url), JSON_OBJECT_AS_ARRAY);
    }

    /**
     * 
     * 网页授权获取ak
     */
    public function getWebAk($code){
        $conf = new SSConf();
        $xcx = $conf->loadByKey("wxmp");
        $url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid={$xcx['appid']}&secret={$xcx['appsec']}&code={$code}&grant_type=authorization_code";
        return json_decode(SSUtil::get($url), JSON_OBJECT_AS_ARRAY);
    }

    /**
     * 查询用户信息
     */
    public function getUserInfo($openid, $ak){
        $conf = new SSConf();
        $xcx = $conf->loadByKey("wxmp");
        $url = "https://api.weixin.qq.com/sns/userinfo?access_token={$ak}&openid={$openid}&lang=zh_CN";
        return json_decode(SSUtil::get($url), JSON_OBJECT_AS_ARRAY);
    }

    public function downImgFromWX($serverId, $ak){
        $url = "http://file.api.weixin.qq.com/cgi-bin/media/get?access_token={$ak}&media_id={$serverId}";
        file_put_contents("/tmp/{$serverId}.jpg", SSUtil::get($url));
        return "/tmp/{$serverId}.jpg";
    }

    /**
    *分享需要签名的加密信息
    */
    public function shareSign($ak, $noncestr, $shareurl){
        $conf = new SSConf();
        $xcx = $conf->loadByKey("wxmp");
        $url = "https://api.weixin.qq.com/cgi-bin/ticket/getticket?access_token={$ak}&type=jsapi";
        $ti = json_decode(SSUtil::get($url), JSON_OBJECT_AS_ARRAY);
        if($ti["errcode"]!=0){
            SSLog::error($ti);
            return $ti;
        }
        $tick = $ti["ticket"];
        $tm = time();

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $aaurl = "$protocol$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        SSLog::info($aaurl);

        $str = "jsapi_ticket={$tick}&noncestr={$noncestr}&timestamp={$tm}&url={$shareurl}";
        return [
            "timestamp"=>$tm,
            "nonceStr"=>$noncestr,
            "signature"=>sha1($str),
            "url"=>$shareurl,
            "rawString"=>$str,
            "aaurl"=>$aaurl
        ];

    }
}
