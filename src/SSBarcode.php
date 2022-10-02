<?php

namespace sscf;

require_once __DIR__ . "/ssautoload.php";
require_once __DIR__ . '/lib/packages/barcode-common/src/BCGColor.php';
require_once __DIR__ . '/lib/packages/barcode-common/src/Drawer/BCGDraw.php';
require_once __DIR__ . '/lib/packages/barcode-common/src/Drawer/BCGDrawPNG.php';

require_once __DIR__ . '/lib/packages/barcode-common/src/BCGDrawing.php';

require_once __DIR__ . '/lib/packages/barcode-common/src/BCGFont.php';

require_once __DIR__ . '/lib/packages/barcode-common/src/BCGFontPhp.php';

require_once __DIR__ . '/lib/packages/barcode-common/src/BCGLabel.php';


require_once __DIR__ . '/lib/packages/barcode-common/src/BCGBarcode.php';

require_once __DIR__ . '/lib/packages/barcode-common/src/BCGBarcode1D.php';


require_once __DIR__ . '/lib/packages/barcode-1d/src/BCGcode128.php';

use BarcodeBakery\Common\BCGColor;
use BarcodeBakery\Common\BCGDrawing;
use BarcodeBakery\Common\BCGFontFile;
use BarcodeBakery\Common\BCGLabel;
use BarcodeBakery\Barcode\BCGcode128;



class SSBarcode
{
    public function qr($str)
    {
        require_once __DIR__ . "/lib/phpqrcode.php";
        $errorCorrectionLevel = 'L';
        $matrixPointSize = 20;  
        //生成二维码图片
        $filename = '/tmp/'.$str.'.png';
        \QRcode::png($str, $filename, $errorCorrectionLevel, $matrixPointSize, 0);
        $b6 = base64_encode(file_get_contents($filename));
        return "data:image/png;base64," . $b6; 
    }

    public function barcode($str)
    {

        $colorFront = new BCGColor(0, 0, 0);
        $colorBack = new BCGColor(255, 255, 255);
        $code = new BCGcode128();
        $code->setScale(2); // Resolution
        $code->setThickness(30); // Thickness
        $code->setForegroundColor($colorFront); // Color of bars
        $code->setBackgroundColor($colorBack); // Color of spaces
        $code->parse($str); // Text

        $drawing = new BCGDrawing("/tmp/{$str}.png", $colorBack);
        $drawing->setBarcode($code);
        $drawing->draw();
        $drawing->finish(BCGDrawing::IMG_FORMAT_PNG);
        $b6 = base64_encode(file_get_contents("/tmp/{$str}.png"));
        return "data:image/png;base64," . $b6;
    }
}
