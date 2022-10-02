<?php
/*
 * @Author: your name
 * @Date: 2021-08-22 15:21:04
 * @LastEditTime: 2021-08-22 16:00:39
 * @LastEditors: Please set LastEditors
 * @Description: In User Settings Edit
 * @FilePath: /antnewserver/vendor/sscf/simplescf/src/SSVms.php
 */

namespace sscf;

require_once __DIR__ . "/ssautoload.php";

use TencentCloud\Common\Credential;
// 导入要请求接口对应的 Request 类
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
// 导入可选配置类
use TencentCloud\Vms\V20200902\Models\SendTtsVoiceRequest;
use TencentCloud\Vms\V20200902\VmsClient;

class SSVms
{
    private $ssconf;
    function __construct(){
        $this->ssconf = new SSConf();
    }
    
    /**
     * 拨打电话
     * $para 拨打参数
     * 
     */
    public function send($tel, $tmpid, $para)
    {
        $qq = $this->ssconf->loadByKey("qcloudkey");
        try {
            /* 必要步骤：
             * 实例化一个认证对象，入参需要传入腾讯云账户密钥对 secretId 和 secretKey
             * 本示例采用从环境变量读取的方式，需要预先在环境变量中设置这两个值
             * 您也可以直接在代码中写入密钥对，但需谨防泄露，不要将代码复制、上传或者分享给他人
             * CAM 密钥查询：https://console.cloud.tencent.com/cam/capi
             */
            $cred = new Credential($qq["secid"], $qq["seckey"]);
            //$cred = new Credential(getenv("TENCENTCLOUD_SECRET_ID"), getenv("TENCENTCLOUD_SECRET_KEY"));
            // 实例化一个 http 选项，可选，无特殊需求时可以跳过
            $httpProfile = new HttpProfile();
            $httpProfile->setReqMethod("POST"); // POST 请求（默认为 POST 请求）
            $httpProfile->setReqTimeout(30); // 请求超时时间，单位为秒（默认60秒）
            $httpProfile->setEndpoint("vms.tencentcloudapi.com"); // 指定接入地域域名（默认就近接入）
            // 实例化一个 client 选项，可选，无特殊需求时可以跳过
            $clientProfile = new ClientProfile();
            $clientProfile->setSignMethod("TC3-HMAC-SHA256"); // 指定签名算法（默认为 TC3-HMAC-SHA256）
            $clientProfile->setHttpProfile($httpProfile);
            /* 实例化 VMS 的 client 对象，clientProfile 是可选的
             * 第二个参数是地域信息，可以直接填写字符串ap-guangzhou，或者引用预设的常量
             */
            $client = new VmsClient($cred, "ap-guangzhou", $clientProfile);
            // 实例化一个 VMS 发送短信请求对象，每个接口都会对应一个 request 对象。
            $req = new SendTtsVoiceRequest();
            /* 填充请求参数，这里 request 对象的成员变量即对应接口的入参
             * 您可以通过官网接口文档或跳转到 request 对象的定义处查看请求参数的定义
             * 基本类型的设置:
             * 帮助链接：
             * 语音消息控制台：https://console.cloud.tencent.com/vms
             * vms helper：https://cloud.tencent.com/document/product/1128/37720 */
            // 模板 ID，必须填写在控制台审核通过的模板 ID，可登陆 [语音消息控制台] 查看模板 ID
            $req->TemplateId = $tmpid;
            // 模板参数，若模板没有参数，请提供为空数组
            $req->TemplateParamSet = $para;
            /* 被叫手机号码，采用 e.164 标准，格式为+[国家或地区码][用户号码]
             * 例如：+8613711112222，其中前面有一个+号，86为国家码，13711112222为手机号 */
            $req->CalledNumber = "+86".$tel;
            // 在语音控制台添加应用后生成的实际SdkAppid，示例如1400006666
            $req->VoiceSdkAppid = "1400524779";
            // 播放次数，可选，最多3次，默认2次
            $req->PlayTimes = 2;
            // 用户的 session 内容，腾讯 server 回包中会原样返回
            $req->SessionContext = "pre";
            // 通过 client 对象调用 SendTtsVoice 方法发起请求。注意请求方法名与请求对象是对应的
            $resp = $client->SendTtsVoice($req);
            // 输出 JSON 格式的字符串回包
            print_r($resp->toJsonString());
            // 可以取出单个值，您可以通过官网接口文档或跳转到 response 对象的定义处查看返回字段的定义
            print_r($resp->RequestId);
        } catch (TencentCloudSDKException $e) {
            echo $e;
        }

    }
}
