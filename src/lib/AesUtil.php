<?php
/*
 * @Author: 微信官方
 * @Date: 2021-05-06 21:10:04
 * @LastEditTime: 2021-05-07 13:39:52
 * @LastEditors: Please set LastEditors
 * @Description: 解密来自https://api.mch.weixin.qq.com/v3/certificates的微信商户平台密钥pem
 * @FilePath: /simplescf/src/lib/AesUtil.php
 */
class AesUtil{
    /**
      * AES key
      *
      * @var string
      */
  
   
  
    /**
      * Constructor
      */

  
    /**
      * Decrypt AEAD_AES_256_GCM ciphertext
      *
      * @param string    $associatedData     AES GCM additional authentication data
      * @param string    $nonceStr           AES GCM nonce
      * @param string    $ciphertext         AES GCM cipher text
      *
      * @return string|bool      Decrypted string on success or FALSE on failure
      */
    public function decryptToString($associatedData, $nonceStr, $ciphertext, $aesKey)
    {
        $authLen = 16;

        if (strlen($aesKey) != 32) {
            throw new InvalidArgumentException('无效的ApiV3Key，长度应为32个字节');
        }
        $this->aesKey = $aesKey;
        
        $ciphertext = \base64_decode($ciphertext);
        if (strlen($ciphertext) <= $authLen) {
            return false;
        }
  
        // openssl (PHP >= 7.1 support AEAD)
        if (PHP_VERSION_ID >= 70100 && in_array('aes-256-gcm', \openssl_get_cipher_methods())) {
            $ctext = substr($ciphertext, 0, -$authLen);
            $authTag = substr($ciphertext, -$authLen);
  
            return \openssl_decrypt($ctext, 'aes-256-gcm', $aesKey, \OPENSSL_RAW_DATA, $nonceStr,
                $authTag, $associatedData);
        }
  
        throw new \RuntimeException('AEAD_AES_256_GCM需要PHP 7.1以上或者安装libsodium-php');
    }
  };