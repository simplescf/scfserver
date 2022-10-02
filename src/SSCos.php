<?php

namespace sscf;

require_once __DIR__ . "/ssautoload.php";

use Qcloud\Cos\Client;

class SSCos
{
    function upCos($path, $tofile)
    {
        $conf = new SSConf();

        $cos = $conf->loadByKey("qcloudcos");
        $key = $conf->loadByKey("qcloudkey");

        try {
            $secretId = $key["secid"]; //"云 API 密钥 SecretId";
            $secretKey = $key["seckey"]; //"云 API 密钥 SecretKey";
            $region = $cos["region"]; //设置一个默认的存储桶地域
            $cosClient = new Client(
                array(
                    'region' => $region,
                    'schema' => 'https', //协议头部，默认为http
                    'credentials' => array(
                        'secretId'  => $secretId,
                        'secretKey' => $secretKey
                    )
                )
            );

            $bucket = $cos["bucket"];
            $file = fopen($path, "rb");
            if ($file) {
                $result = $cosClient->putObject(array(
                    'Bucket' => $bucket,
                    'Key' => $tofile,
                    'Body' => $file
                ));
                return "https://".$result["Location"];
            }

            return false;
        } catch (\Exception $e) {
            SSLog::error($e);
            return false;
        }
    }
    /**
     * 从cos下载文件
     * @return 失败返回false 否则返回保存的本地路径
     */
    function downCos($cospath)
    {
        $conf = new SSConf();

        $cos = $conf->loadByKey("qcloudcos");
        $key = $conf->loadByKey("qcloudkey");

        try {
            $secretId = $key["secid"]; //"云 API 密钥 SecretId";
            $secretKey = $key["seckey"]; //"云 API 密钥 SecretKey";
            $region = $cos["region"]; //设置一个默认的存储桶地域
            $cosClient = new Client(
                array(
                    'region' => $region,
                    'schema' => 'https', //协议头部，默认为http
                    'credentials' => array(
                        'secretId'  => $secretId,
                        'secretKey' => $secretKey
                    )
                )
            );

            $bucket = $cos["bucket"];
            $key = $cospath;
            preg_match("/(.*)\/(.*)/", $cospath, $math);
            $localPath = "/tmp/" . $math[2];
            $result = $cosClient->getObject(array(
                'Bucket' => $bucket,
                'Key' => $key,
                'SaveAs' => $localPath
            ));
            $cln = get_class($result);
            if ($cln == "GuzzleHttp\Command\Result") {
                return $localPath;
            }
            return false;
        } catch (\Exception $e) {
            SSLog::error($e);
            return false;
        }
    }
}
