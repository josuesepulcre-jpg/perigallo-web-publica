<?php
declare(strict_types=1);
namespace Perigallo\Ticketing;
use InvalidArgumentException;
use RuntimeException;
final class ArchiveMedia
{
    public static function upload(Ticketing $ticketing, array $file, string $kind): array
    {
        if (!in_array($kind,['image','video'],true)) throw new InvalidArgumentException('Tipo de recurso inválido.');
        if ($kind==='video') return $ticketing->adminUploadImage($file,'video');
        if (!extension_loaded('gd') || !function_exists('imagewebp')) throw new RuntimeException('La optimización de imágenes requiere GD con WebP.',503);
        $tmp=(string)($file['tmp_name'] ?? '');
        $size=$tmp!=='' ? @getimagesize($tmp) : false;
        if (!$size || $size[0]*$size[1]>16000000) throw new InvalidArgumentException('Usa una imagen de hasta 16 megapíxeles y 5 MB.');
        $resource=$ticketing->adminUploadImage($file,'gallery');
        $source=dirname(__DIR__,2).$resource['url'];
        $image=@imagecreatefromstring(file_get_contents($source));
        if (!$image) throw new InvalidArgumentException('No se pudo interpretar la imagen.');
        if ($resource['mime_type']==='image/jpeg' && function_exists('exif_read_data')) {
            $exif=@exif_read_data($source);$orientation=(int)($exif['Orientation'] ?? 1);
            if (in_array($orientation,[2,4,5,7],true)) imageflip($image,IMG_FLIP_HORIZONTAL);
            $angle=match($orientation){3,4=>180,5,6=>-90,7,8=>90,default=>0};
            if ($angle) {$rotated=imagerotate($image,$angle,0);$image=$rotated;}
        }
        $w=imagesx($image);$h=imagesy($image);$ratio=min(1,2000/max($w,$h));
        $resized=imagecreatetruecolor(max(1,(int)round($w*$ratio)),max(1,(int)round($h*$ratio)));
        imagealphablending($resized,false);imagesavealpha($resized,true);
        imagecopyresampled($resized,$image,0,0,0,0,imagesx($resized),imagesy($resized),$w,$h);
        $url=preg_replace('/\.[^.]+$/','-editorial.webp',$resource['url']);
        if (!imagewebp($resized,dirname(__DIR__,2).$url,84)) throw new RuntimeException('No se pudo guardar la imagen optimizada.');
        $result=['url'=>$url,'original_url'=>$resource['url'],'media_type'=>'image','mime_type'=>'image/webp','width'=>imagesx($resized),'height'=>imagesy($resized)];
        unset($image,$resized);return $result;
    }
}
