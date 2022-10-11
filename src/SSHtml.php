<?php
/*
 * @Author: tsr
 * @Date: 2020-02-11 22:28:25
 * @LastEditTime: 2021-11-04 22:46:32
 * @FilePath: /simplescf/src/SSUtil.php
 */

namespace sscf;

require_once __DIR__ . "/ssautoload.php";
require_once __DIR__ . "/lib/simple_html_dom.php";

class SSHtml
{


    /**
     * 解析出html中所有的img标签
     */
    public function htmlImg($html)
    {
        
        $html = str_get_html($html);
        $imgs = $html->find('img');
        $atts = [];
        foreach ($imgs as $img) {
            array_push($atts, $img->getAllAttributes());
        }
        return $atts;
    }

    public function getElementsByTag($html, $tag)
    {   
        $html = str_get_html($html);
        return $html->find($tag);
    }

    
}
