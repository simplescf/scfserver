<?php

namespace sscf;

require_once __DIR__ . "/ssautoload.php";
require_once __DIR__ . "/lib/wxpay/lib/WxPay.Api.php";
require_once __DIR__ . "/lib/wxpay/pay/WxPay.JsApiPay.php";
require_once __DIR__ . '/lib/wxpay/pay/WxPay.Config.php';

use GuzzleHttp\Exception\RequestException;
use WechatPay\GuzzleMiddleware\Util\PemUtil;
use WechatPay\GuzzleMiddleware\WechatPayMiddleware;

/**
 * 微信开发相关
 */
class SSWechat
{

    private $scf;
    public function __construct()
    {
        date_default_timezone_set("PRC");
        $this->scf = new SScf();
    }
    

    /**
     * 解析"微信回传给商户server的支付通知"信息(统一下单的回传)
     * 1. 验证回传格式
     * 2. 向微信查询订单信息
     * 此函数需要向微信服务器再次查询订单信息,因此需要外网访问权限
     * @param str $rev 接收到的支付通知
     */
    public function valUnifiedorderRev($rev)
    {
        require_once __DIR__ . '/lib/wxpay/pay/notify.php';
        $config = new \WxPayConfig();
        $notify = new \PayNotifyCallBack();
        $rs = $notify->Handle($config, $rev, false);
        return [
            "isok" => $rs["isok"],
            "order" => $rs["order"],
            "returnxml" =>
            [
                "isBase64Encoded" => false,
                "statusCode" => 200,
                "headers" => ["Content-Type" => "text/xml"],
                "body" => $rs["returnxml"],
            ],
        ];
    }

    /**
     * 获取微信的AK
     */
    public function getAk()
    {
        $conf = new SSConf();
        $xcx = $conf->loadXcx();
        return json_decode(SSUtil::get("https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$xcx['appid']}&secret={$xcx['appsec']}"), JSON_OBJECT_AS_ARRAY);
    }

    /**
     * analysis.getVisitPage 被访问过的页面数据
     */
    public function getVisitPage($ak, $begin, $end)
    {
        $bd = date("Ymd", strtotime($begin));
        $ed = date("Ymd", strtotime($end));

        $ss = SSUtil::post(
            "https://api.weixin.qq.com/datacube/getweanalysisappidvisitpage?access_token=" . $ak,
            ["begin_date" => $bd, 'end_date' => $ed]
        );
        return json_decode($ss, JSON_OBJECT_AS_ARRAY);
    }

    /**
     * analysis.getDailyVisitTrend
     * 获取用户访问小程序数据日趋势
     */
    public function getDailyVisitTrend($day)
    {
        $date = date("Ymd", strtotime($day));
        $ak = $this->scf->getOwnOpt("ak");
        $ss = SSUtil::post(
            "https://api.weixin.qq.com/datacube/getweanalysisappiddailyvisittrend?access_token=" . $ak,
            ["begin_date" => $date, 'end_date' => $date]
        );
        $rs = json_decode($ss, JSON_OBJECT_AS_ARRAY);
        if (!isset($rs["list"])) {
            SSLog::error($rs);
            return $rs;
        }
        return $rs["list"][0];
    }

    /**
     * 获取用户小程序访问分布数据
     * analysis.getVisitDistribution
     */
    public function getVisitDistribution($ak, $day)
    {
        $date = date("Ymd", strtotime($day));
        $ss = SSUtil::post(
            "https://api.weixin.qq.com/datacube/getweanalysisappidvisitdistribution?access_token=" . $ak,
            ["begin_date" => $date, 'end_date' => $date]
        );
        return json_decode($ss, JSON_OBJECT_AS_ARRAY);
    }

    /**
     * wxacode.getUnlimited
     * 通过该接口生成的小程序码，永久有效，数量暂无限制
     * @return string base64图片编码
     */
    public function getUnlimitedQR($ak, $path, $para)
    {
        $ss = SSUtil::post(
            "https://api.weixin.qq.com/wxa/getwxacodeunlimit?access_token=" . $ak,
            ["page" => $path, "scene" => $para]
        );
        if (preg_match("/^{/", $ss)) {
            return json_decode($ss, JSON_OBJECT_AS_ARRAY);
        }
        return "data:image/jpg;base64," . base64_encode($ss);
    }

    /**
     * 获取小程序码，适用于需要的码数量较少的业务场景。通过该接口生成的小程序码，永久有效，有数量限制
     */
    public function getWxAcode($ak, $path)
    {        
        $ss = SSUtil::post(
            "https://api.weixin.qq.com/wxa/getwxacode?access_token=" . $ak,
            ["path" => $path]
        );
        if (preg_match("/^{/", $ss)) {
            return json_decode($ss, JSON_OBJECT_AS_ARRAY);
        }
        return "data:image/jpg;base64," . base64_encode($ss);
    }


    /**
     * analysis.getVisitPage 全部页面的访问数据, 无数据的为0
     * @param str date $begin 查询开始日期
     * @param str date $end 查询结束日期
     * @return 额外增加了name变量, 为该path的文字描述
     */
    public function getAllPage($ak, $begin, $end)
    {
        $conf = new SSConf();

        $bd = date("Ymd", strtotime($begin));
        $ed = date("Ymd", strtotime($end));
        $ss = SSUtil::post(
            "https://api.weixin.qq.com/datacube/getweanalysisappidvisitpage?access_token=" . $ak,
            ["begin_date" => $bd, 'end_date' => $ed]
        );
        $ds = json_decode($ss, JSON_OBJECT_AS_ARRAY);

        if (isset($ds["errcode"])) {
            return $ds;
        }
        $vs = $ds["list"];
        $ps = $conf->loadByKey("xcxpage");
        $res = [];
        foreach ($ps as $key => $val) {
            $inx = SSUtil::isValInArray($key, "page_path", $vs);
            if ($inx === false) {
                array_push($res, ["page_path" => $key, "page_visit_pv" => 0, "page_visit_uv" => 0, "page_staytime_pv" => 0, "entrypage_pv" => 0, "exitpage_pv" => 0, "page_share_pv" => 0, "page_share_uv" => 0, "name" => $val]);
            } else {
                $vs[$inx]["name"] = $val;
                array_push($res, $vs[$inx]);
            }
        }
        return $res;
    }

    /**
     * security.msgSecCheck
     * 检查一段文本是否含有违法违规内容。
     */
    public function msgSecCheck($txt)
    {
        $conf = new SSConf();
        $ak = $this->scf->getOwnOpt("ak", "sign", "admin");
        $ss = SSUtil::post(
            "https://api.weixin.qq.com/wxa/msg_sec_check?access_token=" . $ak,
            ["content" => $txt]
        );
        return json_decode($ss, JSON_OBJECT_AS_ARRAY);
    }

    /**
     * 小程序登陆获取openid unionid session_key
     */
    public function xcxLogin($code){
        $scf = new SScf();
        $conf = new SSConf();
        $wx = $conf->loadByKey("wxxcx");
        $url = "https://api.weixin.qq.com/sns/jscode2session?appid={$wx['appid']}&secret={$wx['appsec']}&js_code={$code}&grant_type=authorization_code";

        $sk = json_decode(SSUtil::get($url), JSON_OBJECT_AS_ARRAY);
        if(isset($sk['errcode'])){
            return $this->scf->formatError($sk['errcode']." ".$sk['errmsg']);
        }
        return $this->scf->formatSuccess($sk);
    }

    /**
     * 检验数据的真实性，并且获取解密后的明文.
     * @param $code string 登录的code
     * @param $encryptedData string 加密的用户数据
     * @param $iv string 与用户数据一同返回的初始向量
     *
     * @return int 成功0，失败返回对应的错误码
     */
    public function decryptData($code, $encryptedData, $iv)
    {
        $scf = new SScf();
        $conf = new SSConf();
        $wx = $conf->loadByKey("wxxcx");
        $url = "https://api.weixin.qq.com/sns/jscode2session?appid={$wx['appid']}&secret={$wx['appsec']}&js_code={$code}&grant_type=authorization_code";

        $sk = json_decode(SSUtil::get($url), JSON_OBJECT_AS_ARRAY);
        if (isset($sk["session_key"])) {
            $sessionKey = $sk["session_key"];
        } else {
            SSLog::error($sk);
            return $scf->formatError("session_key获取失败," . $sk["errmsg"]);
        }

        if (strlen($sessionKey) != 24) {
            return $scf->formatError("encodingAesKey 非法" . strlen($sessionKey));
        }
        $aesKey = base64_decode($sessionKey);

        if (strlen($iv) != 24) {
            return $scf->formatError("iv 非法");
        }
        $aesIV = base64_decode($iv);

        $aesCipher = base64_decode($encryptedData);

        $result = openssl_decrypt($aesCipher, "AES-128-CBC", $aesKey, 1, $aesIV);

        SSLog::info($result);
        $dataObj = json_decode($result, JSON_OBJECT_AS_ARRAY);
        SSLog::info($dataObj);
        if ($dataObj == null) {
            return $scf->formatError("aes 解密失败1");
        }

        if ($dataObj['watermark']['appid'] != $wx['appid']) {
            return $scf->formatError("aes 解密失败2");
        }
        return $scf->formatSuccess($dataObj);
    }

    /**
     * 获取当前帐号下的特定模板消息
     * ,框架在微信错误代码增加了自定义
     * @param string $tmplid 要获取的订阅消息模板id
     * @return array ["errcode", "errmsg", "data"],
     * errcode:0为正确,其他值为出现错误, errmsg:错误信息 data:模板消息,模板消息的content重新进行了格式化
     */
    public function getTemplateList($tmplid)
    {
        $ak = $this->scf->getOwnOpt("ak", "sign", "admin");
        $rep = SSUtil::get('https://api.weixin.qq.com/wxaapi/newtmpl/gettemplate?access_token=' . $ak);
        $list = json_decode($rep, JSON_OBJECT_AS_ARRAY);
        if ($list["errcode"] != 0) {
            return $list;
        }
        foreach ($list["data"] as $tmpl) {
            if ($tmpl["priTmplId"] == $tmplid) {
                //将模板信息的content格式化为 [title=>key]样式, title:模板字段的汉字描述信息, key 对应的字段id
                //如[thing1=>"测试字符串"]
                $lines = explode(PHP_EOL, $tmpl["content"]);
                $tmps = [];
                foreach ($lines as $line) {
                    if ($line == "") {
                        continue;
                    }
                    $ls = explode(":", $line);
                    $tmps[$ls[0]] = str_replace('.DATA', '', str_replace('}', '', str_replace('{', '', $ls[1])));
                }
                $tmpl["content"] = $tmps;
                return ["errcode" => 0, "data" => $tmpl];
            }
        }
        return ["errcode" => 1, "errmsg" => "无此模板消息"];
    }

    /**
     * 下发小程序订阅消息,相比直接调用,增加了易用性和更多的提前错误判断
     * @param array ["openid","tmplid","data","page"]
     * tmplid:消息模板id
     * data:数组格式键值对(key=>value),key为模板详细内容的汉字描述 value为对应的要发送的值
     * page:路径
     * @return array [errcode, errmsg]融合微信服务器返回信息,增加了框架专属错误代码1-100
     */
    public function sendSubscribeMessage($para)
    {
        $tmplid = $para["tmplid"];
        $data = $para["data"];
        $openid = $para["openid"];

        //查询对应的模板id消息
        $tmpl = $this->getTemplateList($tmplid);
        if ($tmpl["errcode"] != 0) {
            return $tmpl;
        }

        //生成下发订阅消息的data字段格式
        $senddata = [];
        //为校验信息生成的专门格式
        $valdata = [];
        $contents = $tmpl["data"]["content"];
        foreach ($contents as $key => $val) {
            if (!array_key_exists($key, $data)) {
                SSLog::info($tmpl);
                return ["errcode" => 1, "errmsg" => "订阅消息字段不全"];
            }
            $senddata[$val] = ['value' => $data[$key]];
            $valdata[$val] = ['value' => $data[$key], 'key' => $key];
        }
        $tmpval = $this->valSubscribeMessage($valdata);
        SSLog::info(['endval', $tmpval]);

        if ($tmpval["errcode"] != 0) {
            SSLog::info($tmpl);
            return $tmpval;
        }

        //生成订阅消息格式并发送
        $sendpara = [
            "touser" => $openid,
            "template_id" => $tmplid,
            "data" => $senddata,
        ];
        //判断页面路径合法性
        if (isset($para["page"])) {
            if (preg_match("/^\//", $para["page"])) {
                SSLog::info($tmpl);
                return ["errcode" => 1, "errmsg" => "页面路径不能斜线开头"];
            }
            $sendpara["page"] = $para["page"];
        }
        if (isset($para["version"])) {
            $sendpara["miniprogram_state"] = $para["version"];
        }

        $ak = $this->scf->getOwnOpt("ak", "sign", "admin");
        SSLog::info($sendpara);
        $ss = SSUtil::post(
            "https://api.weixin.qq.com/cgi-bin/message/subscribe/send?access_token=" . $ak,
            $sendpara
        );
        $res = json_decode($ss, JSON_OBJECT_AS_ARRAY);
        SSLog::info($res);
        if ($res["errcode"] == 47003 || $res["errcode"] == 41030) {
            SSLog::error("订阅消息和实际不匹配,请注意开发适配");
        }
        return $res;
    }

    /**
     * 校验并格式化模板消息的格式合法性
     * @return array [errcode, errmsg] errcode=0为合法
     */
    private function valSubscribeMessage($para)
    {

        foreach ($para as $key => $value) {
            $content = $value["value"];
            if (!preg_match('/(.*)(\d+$)/', $key, $match)) {
                return ["errcode" => 1, "errmsg" => "{$value['key']}格式非法"];
            }
            if (mb_strlen($content) == 0 || $content == '') {
                return ["errcode" => 1, "errmsg" => "{$value['key']}不能为空"];
            }

            switch ($match[1]) {
                case 'thing':
                    if (mb_strlen($content) > 20) {
                        return ["errcode" => 1, "errmsg" => "{$value['key']}为thing格式最长20位,且不能为空"];
                    }
                    break;
                case 'number':
                    if (!preg_match("/^[0-9|\.]{1,32}$/", $content)) {
                        return ["errcode" => 1, "errmsg" => "{$value['key']}为number格式只能数字，可带小数,最长32位"];
                    }
                    break;
                case 'letter':
                    if (!preg_match("/^[a-zA-Z|\.]{1,32}$/", $content)) {
                        return ["errcode" => 1, "errmsg" => "{$value['key']}为letter格式只能字母，最长32位"];
                    }
                    break;
                case 'symbol':
                    if (strlen($content) > 5) {
                        return ["errcode" => 1, "errmsg" => "{$value['key']}为symbol格式最长5位"];
                    }
                    break;
                case 'character_string':
                    if (strlen($content) > 32) {
                        return ["errcode" => 1, "errmsg" => "{$value['key']}为character_string格式,最长32位"];
                    }
                    break;
                case 'time':
                    break;
                case 'date':
                    break;
                case 'amount':
                    if (mb_strlen($content) > 11) {
                        return ["errcode" => 1, "errmsg" => "{$value['key']}为amount格式,1个币种符号+10位以内纯数字，可带小数，结尾可带元"];
                    }
                    break;
                case 'phone_number':
                    if (mb_strlen($content) > 17) {
                        return ["errcode" => 1, "errmsg" => "{$value['key']}为phone_number格式,要求17位以内，数字、符号"];
                    }
                    break;
                case 'car_number':
                    if (mb_strlen($content) > 8) {
                        return ["errcode" => 1, "errmsg" => "{$value['key']}为car_number格式,要求8位以内，第一位与最后一位可为汉字，其余为字母或数字"];
                    }
                    break;
                case 'name':
                    if (mb_strlen($content) > 20) {
                        return ["errcode" => 1, "errmsg" => "{$value['key']}为name格式,要求10个以内纯汉字或20个以内纯字母或符号"];
                    }
                    break;
                case 'phrase':
                    SSLog::info(["val phrase", $content, mb_strlen($content)]);
                    if (mb_strlen($content) > 5) {
                        return ["errcode" => 1, "errmsg" => "{$value['key']}为phrase格式,要求5个以内汉字"];
                    }
                    break;
            }
        }
        return ["errcode" => 0];
    }

    /**
     * 获取用户电话号码
     */
    public function getPhoneNumber($ak, $code){
        $ss = SSUtil::post(
            "https://api.weixin.qq.com/wxa/business/getuserphonenumber?access_token=" . $ak,
            ["code" => $code]
        );
        return json_decode($ss, JSON_OBJECT_AS_ARRAY);        
    }
}
