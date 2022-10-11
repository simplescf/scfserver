<?php
/*!
 * Qcloud SCF core
 * 
 * Version 0.01
 *
 * Copyright 2020, tsr
 * Released under the MIT license
 */

namespace sscf;

require_once __DIR__ . "/ssautoload.php";

use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Scf\V20180416\ScfClient;
use TencentCloud\Scf\V20180416\Models\UpdateFunctionConfigurationRequest;
use TencentCloud\Scf\V20180416\Models\GetFunctionRequest;
use TencentCloud\Scf\V20180416\Models\ListFunctionsRequest;
use TencentCloud\Scf\V20180416\Models\PublishLayerVersionRequest;


class SScf
{
    private $ssconf;
    function __construct(){
        $this->ssconf = new SSConf();
    }

    /**
     * 获取post请求的数据
     */
    public function getPostData($event)
    {
        return json_decode($event->body, JSON_OBJECT_AS_ARRAY|JSON_UNESCAPED_UNICODE);
    }

    /**
     * 确认是否是SCF真实执行环境
     */
    public function isScf()
    {
        $env = getenv("TENCENTCLOUD_RUNENV");
        if ($env === FALSE) {
            return FALSE;
        }
        return TRUE;
    }

    /**
     * @description: 获取请求SCF的源IP, 只有通过API网关请求的才有此值
     * @param {*}
     * @return {mix} false获取失败
     */    
    public function getSourceIp($event){
        if($this->isScf()){
            if(isset($event->requestContext->sourceIp)){
                return $event->requestContext->sourceIp;
            }
            return false;
        }{
            $externalContent = file_get_contents('http://myip.ipip.net//');
            if(preg_match('/IP：\[?([:.0-9a-fA-F]+)\]?/', $externalContent, $m)){
                return $m[1];   
            }
        }
        return false;
    }


    /**
     * 模拟初始化post数据
     */
    public function initPostData($data)
    {
        if (is_string($data)) {
            $data = json_decode($data, JSON_UNESCAPED_UNICODE);
        } else if (!is_array($data)) {
            return [];
        }
        $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        $para = json_encode(['body' => $data], JSON_UNESCAPED_UNICODE);
        return json_decode($para);
    }

    /**
     * 校验参数是否包含完整
     * @para para 需要被校验的数组
     * @para avgs 需要校验的参数key
     */
    public function valPara($para, $avgs)
    {
        foreach ($avgs as $avg) {
            if (!array_key_exists($avg, $para)) {
                return "参数不合法";
            }
        }
        return false;
    }

    /**
     * 格式化错误信息
     */
    public function formatError($info, $code = 1)
    {
        return ["errorCode" => $code, "errorMessage" => $info];
    }

    /**
     * 格式化正确信息
     */
    public function formatSuccess($info)
    {
        return ["errorCode" => 0, "data" => $info];
    }

    /**
     * 获取指定scf函数配置的环境变量
     * @param $fun 函数名
     * @param $ns  命名空间
     * @return [] 出现异常返回空数组
     */
    public function getFunOpt($fun, $ns)
    {
        try {
            $qq = $this->ssconf->loadByKey("qcloudkey");
            $scf = $this->ssconf->loadByKey("scf");
            $cred = new Credential($qq["secid"], $qq["seckey"]);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("scf.tencentcloudapi.com");
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new ScfClient($cred, $scf["region"], $clientProfile);
            $req = new GetFunctionRequest();
            $params = '{"FunctionName":"' . $fun . '"}';
            $params = [
                "FunctionName"=>$fun,
                "Namespace"=>$ns
            ];

            $req->fromJsonString(json_encode($params));
            $resp = $client->GetFunction($req);
            $detail = json_decode($resp->toJsonString(), JSON_UNESCAPED_UNICODE);
            if(!isset($detail["Environment"]["Variables"])){
                return false;
            }
            $ps = $detail["Environment"]["Variables"];

            $tmps = [];
            foreach($ps as $para){
                $tmps[$para["Key"]]= $para["Value"];
            }

            return $tmps;
        } catch (TencentCloudSDKException $e) {
            SSLog::error($e);
            return false;
        }
        return [];
    }

    /**
     * 获取本函数的某个参数变量
     * @param string $key 要获取的参数
     * @param string $fun 测试状态下,手动指定运行的函数名,以便于远程获取函数参数
     * @return string 未找到对应的变量 返回false
     */
    public function getOwnOpt($key, $fun = "", $ns="default")
    {
        if ($this->isScf()) {
            return getenv($key);
        }
        $ps = $this->getFunOpt($fun, $ns);
        SSLog::info($ps);
        if(array_key_exists($key, $ps)){
            return $ps[$key];
        }
        return false;
    }

    /**
     * 获取scf中部署了的所有函数
     * $ns 名称空间
     */
    public function getScfFunctions($ns="default")
    {
        try {
            $qq = $this->ssconf->loadByKey("qcloudkey");
            $scf = $this->ssconf->loadByKey("scf");
            $cred = new Credential($qq["secid"], $qq["seckey"]);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("scf.tencentcloudapi.com");
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new ScfClient($cred, $scf["region"], $clientProfile);
            $req = new ListFunctionsRequest();            
            $req->fromJsonString(json_encode(["Limit"=>100]));
            $resp = $client->ListFunctions($req);
            $funs = json_decode($resp->toJsonString(), JSON_UNESCAPED_UNICODE);
            return $funs["Functions"];            
        } catch (TencentCloudSDKException $e) {
            echo $e;
        }
        return [];
    }

    public function getFunState($fun){
        $key = $this->ssconf->loadByKey("qcloudkey");
        $scf = $this->ssconf->loadByKey("scf");
        try {

            $cred = new Credential($key["secid"], $key["seckey"]);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("scf.tencentcloudapi.com");
              
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new ScfClient($cred, $scf["region"], $clientProfile);
        
            $req = new GetFunctionRequest();
            
            $req->fromJsonString(json_encode(["FunctionName"=>$fun]));
        
            $resp = $client->GetFunction($req);
        
            return json_decode($resp->toJsonString(), JSON_UNESCAPED_UNICODE);
        }
        catch(TencentCloudSDKException $e) {
            SSLog::error($e);
        }
        return [];
        
    }


    /**
     * 给SCF函数动态增加环境变量
     * @param string $function 要增加的scf函数
     * @param array $para 要增加的环境变量[key=>val]
     * @param string $ns 命名空间
     */
    public function setScfOpt($function, $para, $ns="default")
    {
        $key = $this->ssconf->loadByKey("qcloudkey");
        $scf = $this->ssconf->loadByKey("scf");
        try {
            $cred = new Credential($key["secid"], $key["seckey"]);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("scf.tencentcloudapi.com");

            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new ScfClient($cred, $scf["region"], $clientProfile);

            $req = new UpdateFunctionConfigurationRequest();
            SSLog::info($para);
            //新老环境变量汇总, 将不存在的环境变量新增进去
            $oldps = $this->getFunOpt($function, $ns);
            foreach($oldps as $okey => $oval){
                if(!array_key_exists($okey, $para)){
                    $para[$okey] = $oval;
                }
            }

            $ps = [];
            foreach ($para as $key => $val) {
                array_push($ps, ["Key" => $key, "Value" => strval($val)]);
            }

            $paratxt = json_encode($ps, JSON_UNESCAPED_UNICODE);
            $params = '{"Namespace":"'.$ns.'","FunctionName":"' . $function . '","Environment":{"Variables":' . $paratxt . '}}';

            $req->fromJsonString($params);
            $resp = $client->UpdateFunctionConfiguration($req);

            return json_decode($resp->toJsonString(), JSON_UNESCAPED_UNICODE);
        } catch (TencentCloudSDKException $e) {
            echo $e;
            SSLog::error($e);
        }
        return [];
    }

   

}

