<?php
/*
 * @Author: tsr
 * @Date: 2021-10-24 10:45:58
 * @LastEditTime: 2022-10-11
 * @LastEditors: tsr
 * @Description: OCR文字识别
 * @FilePath: /simplescf/src/SSOcr.php
 */


namespace sscf;

require_once __DIR__ . "/ssautoload.php";

use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Ocr\V20181119\OcrClient;
use TencentCloud\Ocr\V20181119\Models\GeneralBasicOCRRequest;


class SSOcr
{
    private $ssconf;
    private $scf;
    function __construct()
    {
        $this->ssconf = new SSConf();
        $this->scf = new SScf();
    }

    public function basic($base64){
        try {
            $qq = $this->ssconf->loadByKey("qcloudkey");

            $cred = new Credential($qq["secid"], $qq["seckey"]);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("ocr.tencentcloudapi.com");
              
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new OcrClient($cred, 'ap-guangzhou', $clientProfile);
        
            $req = new GeneralBasicOCRRequest();
            
            $params = array(
                'ImageBase64'=> $base64
            );

            $req->fromJsonString(json_encode($params));
        
            $resp = $client->GeneralBasicOCR($req);
        
            $res = json_decode($resp->toJsonString(), JSON_UNESCAPED_UNICODE);
            
            return $this->scf->formatSuccess($res);
        }
        catch(TencentCloudSDKException $e) {
            echo $e;
        }
        return $this->scf->formatError('识别异常');
        
    }
}
