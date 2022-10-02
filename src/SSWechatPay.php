<?php
/**
 * 微信支付功能
 */
namespace sscf;

require_once __DIR__ . "/ssautoload.php";
require_once __DIR__ . "/lib/wxpay/lib/WxPay.Api.php";
require_once __DIR__ . "/lib/wxpay/pay/WxPay.JsApiPay.php";
require_once __DIR__ . '/lib/wxpay/pay/WxPay.Config.php';

require_once __DIR__ . '/lib/wxpay/lib/WxPay.Exception.php';
require_once __DIR__ . '/lib/wxpay/lib/WxPay.Config.Interface.php';
require_once __DIR__ . '/lib/wxpay/lib/WxPay.Data.php';

use GuzzleHttp\Exception\RequestException;
use WechatPay\GuzzleMiddleware\Util\PemUtil;
use WechatPay\GuzzleMiddleware\WechatPayMiddleware;

class SSWechatPay
{

    private $scf;
    public function __construct()
    {
        date_default_timezone_set("PRC");
        $this->scf = new SScf();
    }

    /**
     * 验证微信支付通知的合法性(V3版本API),APP/小程序/JSAPI等全部支付的通知
     * 验证逻辑和次序:
     * 1. 动态获取微信平台证书列表并解密
     * 2. 验证报文和平台证书序列号的情况
     * 3. 验证报文签名信息
     * 具体算法逻辑参见:https://pay.weixin.qq.com/wiki/doc/apiv3/wechatpay/wechatpay4_1.shtml
     * @return SSRes 成功: ['towx'=>通知应答,请将此参数直接返回给微信平台,'resource'=>解密后的敏感信息,格式见https://pay.weixin.qq.com/wiki/doc/apiv3/apis/chapter3_2_5.shtml], 失败:返回字符串的故障描述
     */
    public function validateV3PayNotify($event)
    {
        SSLog::info($event);
        $conf = new SSConf();
        $certs = $this->getV3Certificates($conf->loadByKey("pay")['app_wechat']);
        if ($certs == false) {
            return SSRes::formatError('获取微信平台证书失败');
        }
        if (sizeof($certs) == 0) {
            return SSRes::formatError('未查询到任何微信平台证书');
        }

        //寻找匹配的证书
        $valPem = false;
        foreach ($certs as $cert) {
            if ($cert['serial_no'] == $event->headers->{'wechatpay-serial'}) {
                $valPem = $cert['pem'];
            }
        }
        if ($valPem === false) {
            return SSRes::formatError('验证平台证书序列号失败');
        }

        //验签所需信息
        $publicKeyResource = openssl_get_publickey($valPem);
        $t = $event->headers->{'wechatpay-timestamp'};
        $n = $event->headers->{'wechatpay-nonce'};
        $b = $event->body;
        $signature = base64_decode($event->headers->{'wechatpay-signature'});

        //验证签名
        $val = openssl_verify($t . "\n" . $n . "\n" . $b . "\n",
            $signature,
            $publicKeyResource,
            "SHA256");
        if ($val === 0) {
            return SSRes::formatError('支付通知信息签名不匹配');
        }
        if ($val === -1 || $val === false) {
            return SSRes::formatError('支付通知签名验证异常');
        }

        //解码回传的敏感信息
        $body = json_decode($b, JSON_UNESCAPED_UNICODE);
        $conf = new SSConf();
        $aesKey = $conf->loadByKey("pay")['app_wechat']['aes_v3_key'];
        $dec = $this->decodeCertificate($body['resource']['ciphertext'], $body['resource']['associated_data'], $body['resource']['nonce'], $aesKey);

        $res = [
            'resource' => json_decode($dec, JSON_UNESCAPED_UNICODE),
            'towx' => [
                "statusCode" => 200,
                "headers" => ["Content-Type" => "text/json"],
                "body" => '{"code": "SUCCESS","message": "成功"}',
            ],
        ];
        return SSRes::formatSuccess($res);
    }

    /**
     * 获取解密后的商户当前可用的微信平台证书列表
     *  @return array|bool 解密后的商户平台证书列表信息['pem'=>'解密后的证书内容', 'serial_no'=>'证书序号'],失败返回false
     */
    private function getV3Certificates($payset)
    {
        $cert = $this->requestWithSign('GET', 'https://api.mch.weixin.qq.com/v3/certificates', [], $payset);
        if ($cert['statusCode'] != 200) {
            SSLog::error($cert, false);
            return false;

        }

        $certs = $cert['body']['data'];

        $decCerts = [];
        $conf = new SSConf();
        $aesKey = $conf->loadByKey("pay")['app_wechat']['aes_v3_key'];

        foreach ($certs as $cert) {
            $ciphertext = $cert['encrypt_certificate']['ciphertext'];
            $associatedData = $cert['encrypt_certificate']['associated_data'];
            $nonceStr = $cert['encrypt_certificate']['nonce'];
            $res = $this->decodeCertificate($ciphertext, $associatedData, $nonceStr, $aesKey);
            if ($res === false) {
                return false;
            }
            array_push($decCerts, ['pem' => $res, "serial_no" => $cert['serial_no']]);
        }
        return $decCerts;
    }

    /**
     * 解析/v3/certificates返回的证书
     */
    private function decodeCertificate($ciphertext, $associatedData, $nonceStr, $aesKey)
    {

        $authLen = 16;

        $ciphertext = \base64_decode($ciphertext);
        if (strlen($ciphertext) <= $authLen) {
            SSLog::error('v3/certificates返回的证书异常');
            return false;
        }

        // openssl (PHP >= 7.1 support AEAD)
        if (PHP_VERSION_ID >= 70100 && in_array('aes-256-gcm', openssl_get_cipher_methods())) {
            $ctext = substr($ciphertext, 0, -$authLen);
            $authTag = substr($ciphertext, -$authLen);

            $pem = openssl_decrypt($ctext, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $nonceStr,
                $authTag, $associatedData);
            if ($pem == false) {
                SSLog::error('解密报文失败');
            }
            return $pem;
        }
        SSLog::error('AEAD_AES_256_GCM需要PHP 7.1以上');

        return false;
    }

    /**
     * @description: 向微信服务器发起POST/GET请求(自动附带签名, v3版本API)
     * @param {string} $method  POST/GET 请求方法
     * @param {*} $url 请求URL
     * @param {*} $options 请求参数
     * @return {array} ['statusCode'=>错误代码/HTTP代码,'body'=>["code"=>"业务错误代码","message"=>'业务错误描述]]
     */
    private function requestWithSign($method, $url, $reqOptions, $payset)
    {
        $conf = new SSConf();
        /*
        $payset = $conf->loadByKey("pay")['app_wechat'];
        $merchantId = $payset['mchid']; // 商户号
        $merchantSerialNumber = $payset['mchserialnum']; // 商户API证书序列号
        */
        $merchantId = $payset['mchid'];
        $merchantSerialNumber = $payset['mchserialnum']; // 商户API证书序列号

        $apiPrivatePem = '';
        $wechatPem = '';
        if ($this->scf->isScf()) {
            $wechatpem = $payset['wechatpem']['remote'];
            $apiPrivatePem = $payset['privatepem']['remote'];
        } else {
            $wechatpem = $payset['wechatpem']['local'];
            $apiPrivatePem = $payset['privatepem']['local'];
        }
        if (!file_exists($wechatpem)) {
            return ['statusCode' => 10000, 'body' => ['code' => 'PEM_NOEXIST', 'message' => '未检测到微信支付平台证书']];
        }
        if (!file_exists($apiPrivatePem)) {
            return ['statusCode' => 10000, 'body' => ['code' => 'PEM_NOEXIST', 'message' => '未检测到商户API私钥证书文件']];
        }
        SSLog::info($apiPrivatePem, $wechatpem);

        try {
            $merchantPrivateKey = PemUtil::loadPrivateKey($apiPrivatePem); // 商户私钥
            $wechatpayCertificate = PemUtil::loadCertificate($wechatpem); // 微信支付平台证书
        } catch (\Exception$e) {
            return ['statusCode' => '10000', 'body' => ["code" => "PEM_ERROR", "message" => '加载商户私钥或微信支付平台证书失败:' . $e->getMessage()]];
        }

        // 构造一个WechatPayMiddleware
        $wechatpayMiddleware = WechatPayMiddleware::builder()
            ->withMerchant($merchantId, $merchantSerialNumber, $merchantPrivateKey) // 传入商户相关配置
            ->withWechatPay([$wechatpayCertificate]) // 可传入多个微信支付平台证书，参数类型为array
            ->build();

        // 将WechatPayMiddleware添加到Guzzle的HandlerStack中
        $stack = \GuzzleHttp\HandlerStack::create();
        $stack->push($wechatpayMiddleware, 'wechatpay');

        // 创建Guzzle HTTP Client时，将HandlerStack传入
        $client = new \GuzzleHttp\Client(['handler' => $stack]);
        $resp = [];
        // 接下来，正常使用Guzzle发起API请求，WechatPayMiddleware会自动地处理签名和验签
        try {
            $req = [
                'headers' => ['Accept' => 'application/json'],
            ];
            if (sizeof($reqOptions) > 0) {
                $req['json'] = $reqOptions;
            }
            $resp = $client->request($method, $url, $req);
            return ['statusCode' => $resp->getStatusCode(), 'body' => json_decode($resp->getBody(), JSON_UNESCAPED_UNICODE)];
        } catch (RequestException $e) {
            // 进行错误处理
            SSLog::error($e->getMessage());
            SSLog::info($e->getResponse()->getStatusCode());
            SSLog::info($e->getResponse()->getBody());
            if ($e->hasResponse()) {
                return ['statusCode' => $e->getResponse()->getStatusCode(), 'body' => json_decode($e->getResponse()->getBody()->getContents(), JSON_UNESCAPED_UNICODE)];
            }
            return ['statusCode' => '10000', 'body' => ["code" => "REQ_ERROR", "message" => $e->getMessage()]];
        } catch (\Exception$e) {
            return ['statusCode' => '10000', 'body' => ["code" => "ERROR", "message" => $e->getMessage()]];
        }
    }

    /**
     * 发起退款
     * @param array $para 退款参数
     * @return array
     * 失败返回['errorCode'=>0,'errorMessage'=>'失败原因字符串描述']
     * 成功返回:['errorCode'=>0,'data'=>退款数据(https://pay.weixin.qq.com/wiki/doc/apiv3/apis/chapter3_2_9.shtml)],
     */
    public function refunds($para)
    {
        $transaction_id = $para['transaction_id'];
        $notify_url = $para['notify_url'];
        $reason = $para['reason'];
        $refund = $para['refund'];
        $total = $para['total'];
        $out_refund_no = $para['out_refund_no'];

        $conf = new SSConf();
        $payset = $conf->loadByKey("pay")['app_wechat'];
        $merchantId = $payset['mchid']; // 商户号
        $appId = $conf->loadByKey("openwechat")['appid'];

        $rsp = $this->requestWithSign('POST', 'https://api.mch.weixin.qq.com/v3/refund/domestic/refunds',
            [
                'transaction_id' => $transaction_id,
                'out_refund_no' => $out_refund_no,
                'reason' => $reason,
                'notify_url' => $notify_url,
                'amount' => [
                    'refund' => $refund,
                    'total' => $total,
                    'currency' => "CNY",
                ],
            ],
            $payset
        );
        if ($rsp['statusCode'] == 200) {
            return $this->scf->formatSuccess($rsp['body']);
        } else {
            $code = $rsp['body']['code'];
            $msg = $rsp['body']['message'];
            return $this->scf->formatError("code:{$code}, message:{$msg}");
        }

        SSLog::info($rsp);
    }

    /**
     * @description: 生成拉起APP微信支付的所需参数(V3版本)
     * 步骤:
     * 1. 自动"统一下单"
     * 2. 生成拉起微信支付的APP所需参数
     * @param {array} $para   ['expireMin'=>'订单超时分钟数', 'desc'=>'商品描述', 'tradeNum'=>'商户订单号', 'attach'=>'附加数据，在查询API和支付通知中原样返回，可作为自定义参数使用', 'total'=>'订单金额', 'notify'=>通知URL必须为直接可访问的URL，不允许携带查询串, 'goods'=>[id, name, quantity, price]]
     * @return { array } 返回拉起APP微信支付的所需参数, ['errorCode','data'], 失败返回:['errorCode','errorMessage'=>['code', 'message']]
     */
    public function getAppPayParaV3($para)
    {
        $sec = time() + $para['expireMin'] * 60;
        $desc = $para["desc"];
        $tradenum = $para['tradeNum'];
        $expire = date("Y-m-d\TH:i:s", $sec) . '+08:00';
        $attach = $para['attach'];
        $fee = $para['total'];
        $notify = $para['notify'];

        // $goods = [];
        // foreach ($para['goods'] as $good) {
        //     array_push($goods, [
        //         "merchant_goods_id" => $good['id'],
        //         "goods_name" => $good['name'],
        //         "quantity" => $good['quantity'],
        //         "unit_price" => $good['price'],
        //     ]);
        // }
        // $detail = ['goods_detail' => $goods];

        $conf = new SSConf();
        $payset = $conf->loadByKey("pay")['app_wechat'];
        $merchantId = $payset['mchid']; // 商户号
        $appId = $conf->loadByKey("openwechat")['appid'];

        $rsp = $this->requestWithSign('POST', 'https://api.mch.weixin.qq.com/v3/pay/transactions/app',
            [
                'appid' => $appId,
                'mchid' => $merchantId,
                'description' => $desc,
                'out_trade_no' => $tradenum,
                'time_expire' => $expire,
                'attach' => $attach,
                'notify_url' => $notify,
                'amount' => [
                    'total' => $fee,
                    'currency' => 'CNY',
                ],
            ],
            $conf->loadByKey("pay")['app_wechat']
        );

        if ($rsp['statusCode'] == 200) {
            $privatepem = '';
            if ($this->scf->isScf()) {
                $privatepem = $payset['privatepem']['remote'];
            } else {
                $privatepem = $payset['privatepem']['local'];
            }
            $dec = $this->encodeAppV3Sign($appId, $rsp['body']['prepay_id'], $privatepem, $merchantId);
            if ($dec === false) {
                return $this->scf->formatError(['code' => 'SIGNERROR', 'message' => '生成APP支付参数签名失败']);
            }
            return $this->scf->formatSuccess($dec);
        } else {
            return $this->scf->formatError(['code' => $rsp['body']['code'], 'message' => $rsp['body']['message']]);
        }
    }

    public function getXcxPayParaV3($para)
    {
        $sec = time() + $para['expireMin'] * 60;
        $desc = $para["desc"];
        $tradenum = $para['tradeNum'];
        $expire = date("Y-m-d\TH:i:s", $sec) . '+08:00';
        $attach = $para['attach'];
        $fee = $para['total'];
        $notify = $para['notify'];
        $openid = $para['openid'];

        $conf = new SSConf();
        $payset = $conf->loadByKey("pay")['xcx'];
        $merchantId = $conf->loadByKey("pay")['xcx']['mchid']; // 商户号
        $appId = $conf->loadByKey("wxxcx")['appid'];

        $rsp = $this->requestWithSign('POST', 'https://api.mch.weixin.qq.com/v3/pay/transactions/jsapi',
            [
                'appid' => $appId,
                'mchid' => $merchantId,
                'description' => $desc,
                'out_trade_no' => $tradenum,
                'attach' => $attach,
                'notify_url' => $notify,
                'payer' => [
                    'openid' => $openid,
                ],
                'amount' => [
                    'total' => $fee,
                    'currency' => 'CNY',
                ],
            ],
            $conf->loadByKey("pay")['xcx']
        );
        SSLog::info($rsp);

        if ($rsp['statusCode'] == 200) {
            $privatepem = '';
            if ($this->scf->isScf()) {
                $privatepem = $payset['privatepem']['remote'];
            } else {
                $privatepem = $payset['privatepem']['local'];
            }
            $dec = $this->encodeXcxV3Sign($appId, $rsp['body']['prepay_id'], $privatepem, $merchantId);
            if ($dec === false) {
                return $this->scf->formatError(['code' => 'SIGNERROR', 'message' => '生成APP支付参数签名失败']);
            }
            return $this->scf->formatSuccess($dec);
        } else {
            return $this->scf->formatError(['code' => $rsp['body']['code'], 'message' => $rsp['body']['message']]);
        }
    }

    /**
     * 加密并生成拉起V3版本的APP支付所需的参数信息
     */
    private function encodeAppV3Sign($appid, $preid, $privatepem, $merchantId)
    {
        $time = time();
        $nonStr = \WxPayApi::getNonceStr();

        $paysign = ['appId' => $appid,
            'timeStamp' => $time,
            'nonceStr' => $nonStr,
            'prepayid' => $preid,
        ];
        $str = implode("\n", $paysign) . "\n";
        $key = openssl_get_privatekey(file_get_contents($privatepem));
        if ($key === false) {
            return false;
        }
        openssl_sign($str, $res, $key, OPENSSL_ALGO_SHA256);
        $sign = base64_encode($res);
        return ['appid' => $appid,
            'partnerid' => $merchantId,
            'prepayid' => $preid,
            'package' => 'WXPay',
            'noncestr' => $nonStr,
            'timestamp' => $time,
            'sign' => $sign];
    }

    private function encodeXcxV3Sign($appid, $preid, $privatepem, $merchantId)
    {
        $time = time();
        $nonStr = \WxPayApi::getNonceStr();

        $paysign = [
            'appId' => $appid,
            'timeStamp' => $time,
            'nonceStr' => $nonStr,
            'package' => "prepay_id=" . $preid,
        ];

        $str = implode("\n", $paysign) . "\n";
        $key = openssl_get_privatekey(file_get_contents($privatepem));
        if ($key === false) {
            return false;
        }
        openssl_sign($str, $res, $key, OPENSSL_ALGO_SHA256);
        $sign = base64_encode($res);
        SSLog::info($sign);

        return [
            'signType' => "RSA",
            'package' => 'prepay_id=' . $preid,
            'nonceStr' => $nonStr,
            'timeStamp' => $time,
            'paySign' => $sign];
    }

    private static function getMillisecond()
    {
        //获取毫秒的时间戳
        $time = explode(" ", microtime());
        $time = $time[1] . ($time[0] * 1000);
        $time2 = explode(".", $time);
        $time = $time2[0];
        return $time;
    }

/**
 * 支付到微信账户
 */
    public function paytowx($openid, $trade_no, $money, $desc)
    {
        $conf = new SSConf();
        $xcx = $conf->loadByKey("wxxcx");

        $data = array(
            'mch_appid' => $xcx['appid'],
            'mchid' => $xcx['mchid'],
            'nonce_str' => $this->getNonceStr(), //随机字符串
            'partner_trade_no' => $trade_no, //商户订单号，需要唯一
            'openid' => $openid,
            'check_name' => 'NO_CHECK', //OPTION_CHECK不强制校验真实姓名, FORCE_CHECK：强制 NO_CHECK：
            'amount' => $money, //付款金额单位为分
            'desc' => $desc,
        );

        //生成签名
        $data['sign'] = $this->makeSign($data);
        //构造XML数据
        $xmldata = $this->arrToXml($data);
        // 请求URL
        $url = 'https://api.mch.weixin.qq.com/mmpaymkttransfers/promotion/transfers';
        //发送post请求
        $res = $this->curl_post_ssl_pem($url, $xmldata);
        return json_decode(json_encode(simplexml_load_string($res, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
    }

    /**
     * 签名
     * @param $data
     * @return string
     */
    private function makeSign($data)
    {
        $conf = new SSConf();
        // 关联排序
        ksort($data);
        // 字典排序
        $str = http_build_query($data);
        // 添加商户密钥
        $str .= '&key=' . $conf->loadByKey("wxxcx")['mchkey'];
        // 清理空格  非常恶心调了半天
        $str = urldecode($str);
        $str = md5($str);
        // 转换大写
        $result = strtoupper($str);
        return $result;
    }

    /**
     * 数组转XML
     * @param $data
     * @return string
     */
    private static function arrToXml($data)
    {
        $xml = "<xml>";
        //  遍历组合
        foreach ($data as $k => $v) {
            $xml .= '<' . $k . '>' . $v . '</' . $k . '>';
        }
        $xml .= '</xml>';
        return $xml;
    }

    /**
     * 企业付款发起请求
     * 此函数来自:https://pay.weixin.qq.com/wiki/doc/api/download/cert.zip
     */
    public function curl_post_ssl_pem($url, $xmldata, $second = 30, $aHeader = array())
    {
        $ch = curl_init();
        //超时时间
        curl_setopt($ch, CURLOPT_TIMEOUT, $second);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        //这里设置代理，如果有的话
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        //以下两种方式需选择一种

        //第一种方法，cert 与 key 分别属于两个.pem文件
        //默认格式为PEM，可以注释
        $conf = new SSConf();
        $xcx = $conf->loadByKey("pay")['app_wechat'];
        $sslcert_path = $xcx['certpem']['local'];
        $sslkey_path = $xcx['privatepem']['local'];
        if ($this->scf->isScf()) {
            $sslcert_path = $xcx['certpem']['remote'];
            $sslkey_path = $xcx['privatepem']['remote'];
        }

        curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM');
        curl_setopt($ch, CURLOPT_SSLCERT, $sslcert_path);
        //默认格式为PEM，可以注释
        curl_setopt($ch, CURLOPT_SSLKEYTYPE, 'PEM');
        curl_setopt($ch, CURLOPT_SSLKEY, $sslkey_path);

        if (count($aHeader) >= 1) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $aHeader);
        }

        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xmldata);
        $data = curl_exec($ch);
        if ($data) {
            curl_close($ch);
            return $data;
        } else {
            $error = curl_errno($ch);
            echo "call faild, errorCode:$error\n";
            curl_close($ch);
            return false;
        }
    }

    /**
     * 随机字符串
     * @param int $length
     * @return string
     */
    private static function getNonceStr($length = 32)
    {
        $chars = "abcdefghijklmnopqrstuvwxyz0123456789";
        $str = "";
        for ($i = 0; $i < $length; $i++) {
            $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        }
        return $str;
    }
}
