<?php
/*
 * @Author: your name
 * @Date: 2020-02-11 22:28:25
 * @LastEditTime: 2021-08-18 16:47:45
 * @LastEditors: Please set LastEditors
 * @Description: In User Settings Edit
 * @FilePath: /simplescf/src/SSUtil.php
 */

namespace sscf;
require_once __DIR__ . "/ssautoload.php";
class SSGps
{
    public function getGps($address){
        $gps = json_decode(SSUtil::get("https://restapi.amap.com/v3/geocode/geo?address={$address}&output=JSON&key=a80086a12a041f9f06d13d1a1c0da7c1"));
        if ($gps->status == 1) {
            $code = $gps->regeocode->addressComponent->adcode;
            $tmp = SSUtil::getAdcode($code);
            $postCode = [
                'province' => $tmp['provinceAdCode'],
                'city' => $tmp['adcode'],
                'area' => $code,
            ];
            return $postCode;
        }
        return false;
    }

    /**
     * 
     * @return array 参数格式化为数组
     */
    
    public function getPostCodeByGps($lat, $lng)
    {
        $gps = json_decode(SSUtil::get("http://restapi.amap.com/v3/geocode/regeo?key=37d7a9f99414167c8a1ad7f44e7c45fe&location={$lng},{$lat}&poitype=商务写字楼&radius=1000&extensions=base&batch=false&roadlevel=0"));
        if ($gps->status == 1) {
            $code = $gps->regeocode->addressComponent->adcode;
            $tmp = SSUtil::getAdcode($code);
            $postCode = [
                'province' => $tmp['provinceAdCode'],
                'city' => $tmp['adcode'],
                'area' => $code,
            ];
            return $postCode;
        }
        return false;
    }

}
