<?php
/*!
 * Qcloud SCF core
 * 
 * Version 0.01
 *
 * Copyright 2020, tao zhang
 * Released under the MIT license
 */

namespace sscf;

require_once __DIR__ . "/ssautoload.php";

use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Common\Exception\TencentCloudSDKException;

use TencentCloud\Sms\V20190711\SmsClient;
use TencentCloud\Sms\V20190711\Models\SendSmsRequest;



class SSSms
{
    private $ssconf;
    private $scf;
    function __construct()
    {
        $this->ssconf = new SSConf();
        $this->scf = new SScf();
    }

    /**
     * 根据短信名称检索配置信息
     * @return array|bool 成功返回对应的信息,失败返回false
     */
    private function getSms($name)
    {
        $smsconf = $this->ssconf->loadByKey("qcloudsms");
        $sms = [
            "sdkappid" => $smsconf['sdkappid']
        ];
        $tmpls = $smsconf['templates'];
        foreach ($tmpls as $tmpl) {
            $subs = $tmpl['subtemps'];
            foreach ($subs as $sub) {
                if ($sub['name'] == $name) {
                    $sms['sign'] = $tmpl['sign'];
                    $sms['id'] = $sub['id'];
                    $sms['params'] = $sub['params'];
                    return $sms;
                }
            }
        }
        return false;
    }

    /**
     * 发送短信
     * @param {mix} $phones 电话数组或单个电话
     * @param string $smsname 电信模板名称
     * @param array $paras 短信中的参数信息
     * @return array [errorCode, errorMessage],code==0标识成功,成功返回所有电话发送情况列表
     * 列表:[tel:电话, send:是否发送成功, message:错误信息]
     */
    public function sendSms($phones, $smsname, $paras)
    {
        try {
            $qq = $this->ssconf->loadByKey("qcloudkey");

            $cred = new Credential($qq["secid"], $qq["seckey"]);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("sms.tencentcloudapi.com");
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new SmsClient($cred, '', $clientProfile);
            $req = new SendSmsRequest();
            $smsparas = [];

            //读取对应的短信配置信息
            $sms = $this->getSms($smsname);
            if ($sms == false) {
                return $this->scf->formatError("未找到短信配置信息");
            }

            //格式化并校验
            if (sizeof($sms['params']) != sizeof($paras)) {
                return $this->scf->formatError("短信参数数量错误");
            }

            foreach ($sms['params'] as $key => $val) {
                if (isset($paras[$key])) {
                    array_push($smsparas, '' . $paras[$key]);
                } else {
                    return $this->scf->formatError("您未配置短信参数{$key}=>{$val}");
                }
            }

            //格式化电话信息
            if (!is_array($phones)) {
                $phones = [$phones];
            }

            for ($i = 0; $i < sizeof($phones); ++$i) {
                if (strlen($phones[$i]) == 11) {
                    $phones[$i] = '+86' . $phones[$i];
                }
            }

            $params = [
                "PhoneNumberSet" => $phones,
                "TemplateID" => $sms['id'],
                "SmsSdkAppid" => $sms['sdkappid'],
                "Sign" => $sms['sign']
            ];

            if (sizeof($smsparas) > 0) {
                $params['TemplateParamSet'] = $smsparas;
            }
            $req->fromJsonString(json_encode($params));
            $resp = $client->SendSms($req);
            $detail = json_decode($resp->toJsonString(), JSON_UNESCAPED_UNICODE);
            

            $res = [];
            if (isset($detail['SendStatusSet'])) {
                foreach ($detail['SendStatusSet'] as $set) {
                    if (strtoupper($set['Code']) != 'OK') {
                        array_push($res, [
                            'tel' => $set['PhoneNumber'],
                            'send' => false, 'message' => $set['Message']
                        ]);
                    } else {
                        array_push($res, ['tel' => $set['PhoneNumber'], 'send' => true]);
                    }
                }
            } else {
                SSLog::info($paras);
                return $this->scf->formatError("短信发送失败");
            }
            
            return $this->scf->formatSuccess($res);
        } catch (TencentCloudSDKException $e) {
            SSLog::error($e);
            return $this->scf->formatError("短信发送失败" . $e->getMessage());
        }
        return $this->scf->formatError("短信未发送出");
    }
}
