<?php
declare(strict_types=1);

/** Screenshot links use random bearer IDs, expire in 30 days, and need no GitHub Contents permission. */
final class FeedbackMedia
{
    private static function directory(): string
    {
        $dir=PULL_ROOT.'/admin/account/data/feedback-media';
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Не удалось сохранить скриншот.');
        return $dir;
    }

    public static function attach(array $files, string $base): string
    {
        if (!is_array($files['name']??null)) return '';
        $types=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp']; $images=[];
        foreach ($files['name'] as $i=>$name) {
            if (($files['error'][$i]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) continue;
            if (count($images)>=5 || ($files['error'][$i]??-1)!==UPLOAD_ERR_OK || ($files['size'][$i]??0)>5*1024*1024) throw new RuntimeException('Не более 5 скриншотов до 5 МБ каждый.');
            $path=(string)$files['tmp_name'][$i];
            $dimensions=@getimagesize($path); $mime=$dimensions['mime']??'';
            if (!isset($types[$mime]) || !$dimensions || $dimensions[0]>12000 || $dimensions[1]>12000) throw new RuntimeException('Разрешены только изображения PNG, JPEG и WebP до 12000 пикселей.');
            $images[]=['path'=>$path,'ext'=>$types[$mime]];
        }
        if (!$images) return '';
        if (!preg_match('~^https?://[^\s?#]+$~D',$base)) throw new RuntimeException('Настройте FEEDBACK_PUBLIC_BASE_URL — HTTPS-адрес панели администратора.');
        if (strpos($base,'https://')!==0 && !preg_match('~^http://(?:127\.0\.0\.1|localhost)(?::\d+)?/~',$base)) throw new RuntimeException('Для скриншотов требуется HTTPS.');
        $dir=self::directory(); $total=0;
        foreach (glob($dir.'/*')?:[] as $old) { if (filemtime($old)<time()-30*86400) @unlink($old); else $total+=(int)filesize($old); }
        if ($total>250*1024*1024) throw new RuntimeException('Хранилище скриншотов заполнено. Удалите старые вложения или повторите без них.');
        $out="\n\n### Скриншоты\nСсылки доступны получателям задачи в течение 30 дней.\n";
        foreach ($images as $image) {
            $id=bin2hex(random_bytes(24)).'.'.$image['ext'];
            if (!copy($image['path'],$dir.'/'.$id)) throw new RuntimeException('Не удалось сохранить скриншот; обращение не отправлено.');
            @chmod($dir.'/'.$id,0600);
            $out.="\n![Скриншот](".$base.'?feedback_image='.$id.")\n";
        }
        return $out;
    }

    public static function serve(string $id): void
    {
        $path=self::directory().'/'.$id;
        if (!preg_match('/^[a-f0-9]{48}\.(png|jpg|webp)$/D',$id) || !is_file($path) || filemtime($path)<time()-30*86400) { http_response_code(404); exit; }
        $ext=pathinfo($id,PATHINFO_EXTENSION);
        header('Content-Type: '.(['png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp'][$ext]));
        header('Content-Length: '.filesize($path)); header('Content-Disposition: inline; filename="screenshot.'.$ext.'"');
        header('Cache-Control: private, no-store'); header('X-Robots-Tag: noindex, nofollow, noarchive'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        readfile($path); exit;
    }
}
