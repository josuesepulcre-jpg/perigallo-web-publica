<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/src/bootstrap.php';
use Perigallo\Ticketing\AdminAuth;
use Perigallo\Ticketing\Database;
use Perigallo\Ticketing\ExperienceArchive;
$preview=isset($_GET['preview']);
if ($preview) { AdminAuth::require(); header('Cache-Control: private, no-store'); }
else header('Cache-Control: no-store');
try {
    $repository=new ExperienceArchive(Database::pdo());
    $entry=$repository->get((string)($_GET['slug'] ?? ''),$preview);
} catch (Throwable $e) { http_response_code(503); header('Retry-After: 60'); exit('El archivo no está disponible. Inténtalo de nuevo en unos instantes.'); }
if (!$entry) { http_response_code(404); readfile(dirname(__DIR__).'/404.html'); exit; }
$d=$entry['document'];
function ae(mixed $text): string { return htmlspecialchars((string)$text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function ap(string $text): string { return implode('',array_map(static fn($p)=>'<p>'.nl2br(ae($p)).'</p>',array_filter(explode("\n\n",$text),static fn($p)=>trim($p)!==''))); }
function av(array $item, string $poster=''): string {
    $url=$item['url'];
    $poster=$poster ?: ($item['poster'] ?? '');
    if (str_starts_with($url,'https://')) return '<a class="archive-video-link" href="'.ae($url).'" target="_blank" rel="noopener noreferrer">Ver vídeo en '.(str_contains($url,'youtu')?'YouTube':'Vimeo').' <span aria-hidden="true">↗</span></a>';
    return '<video class="archive-video" controls playsinline preload="none"'.($poster?' poster="'.ae($poster).'"':'').' aria-label="'.ae($item['alt'] ?: 'Vídeo de la experiencia').'"><source src="'.ae($url).'" type="'.(str_ends_with($url,'.webm')?'video/webm':'video/mp4').'"><a href="'.ae($url).'">Abrir vídeo</a></video>';
}
function am(array $media): string {
    $html='<div class="archive-mosaic">';
    foreach ($media as $item) {
        $class='archive-frame'.(!empty($item['portrait'])?' is-portrait':'').(!empty($item['featured'])?' is-featured':'');
        $html.='<figure class="'.ae($class).'">';
        if ($item['kind']==='video') $html.=av($item);
        else $html.='<button type="button" data-archive-image="'.ae($item['url']).'" data-caption="'.ae($item['caption']).'" aria-label="Ampliar: '.ae($item['alt']).'"><img src="'.ae($item['url']).'" alt="'.ae($item['alt']).'" loading="lazy" decoding="async"><span class="archive-zoom" aria-hidden="true">Ampliar ↗</span></button>';
        if ($item['caption']) $html.='<figcaption>'.ae($item['caption']).'</figcaption>';
        $html.='</figure>';
    }
    return $html.'</div>';
}
$canonical='https://perigallo.com/experiencias/vividas/'.$entry['slug'].'/';
$date=$d['date'] ? (new IntlDateFormatter('es_ES',IntlDateFormatter::LONG,IntlDateFormatter::NONE,'Europe/Madrid'))->format(new DateTimeImmutable($d['date'])) : '';
$noindex=$preview || env_value('APP_ENV')==='staging';
if ($noindex) header('X-Robots-Tag: noindex, nofollow');
define('PERIGALLO_ARCHIVE_VIEW',true);require __DIR__.'/archive-shell.php';
$title=$d['seo_title'] ?: $d['title'].' — '.$d['subtitle'].' | Archivo Perigallo';
$description=$d['seo_description'] ?: $d['description'];
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=ae($title)?></title><meta name="description" content="<?=ae($description)?>"><meta name="robots" content="<?=$noindex?'noindex,nofollow':'index,follow'?>"><link rel="canonical" href="<?=ae($canonical)?>"><meta property="og:type" content="article"><meta property="og:title" content="<?=ae($title)?>"><meta property="og:description" content="<?=ae($description)?>"><meta property="og:url" content="<?=ae($canonical)?>"><?php if ($d['cover_image']):?><meta property="og:image" content="<?=ae('https://perigallo.com'.$d['cover_image'])?>"><meta property="og:image:alt" content="<?=ae($d['cover_alt'])?>"><?php endif?><meta name="twitter:card" content="summary_large_image"><link rel="icon" href="/media/identidad/perigallo-tres-patas.svg"><link rel="stylesheet" href="/brand/fonts.css"><link rel="stylesheet" href="/brand/web-tokens.css"><link rel="stylesheet" href="/editorial.css"><link rel="stylesheet" href="/identity-lockup.css"><link rel="stylesheet" href="/journey.css"><link rel="stylesheet" href="/assets/css/experience-archive.css?v=archivo-87e553fd34"></head><body class="journey-page archive-detail"><?=$archiveHeader?>
<?php if ($preview):?><div class="archive-preview-bar" role="status">Vista previa · <?=ae($entry['status']==='published'?'Cambios sin publicar':'Borrador privado')?> <a href="/admin/experiencias/?id=<?=$entry['id']?>">Volver al editor ↗</a></div><?php endif?>
<main id="contenido"><section class="archive-hero <?=$d['cover_image']?'':'is-unillustrated'?>"><?php if ($d['cover_image']):?><img class="archive-hero-image" src="<?=ae($d['cover_image'])?>" alt="<?=ae($d['cover_alt'])?>" fetchpriority="high" decoding="async"><?php endif?><?php if ($d['cover_video'] && !str_starts_with($d['cover_video'],'https://')):?><video class="archive-hero-video" muted loop playsinline preload="none" poster="<?=ae($d['cover_image'])?>" data-archive-hero-video aria-label="Ambiente de <?=ae($d['title'])?>"><source src="<?=ae($d['cover_video'])?>" type="<?=str_ends_with($d['cover_video'],'.webm')?'video/webm':'video/mp4'?>"></video><button class="archive-hero-video-toggle" type="button" data-archive-video-toggle>Reproducir vídeo de portada</button><?php endif?><div class="archive-hero-copy"><a href="/experiencias/#vividas" class="eyebrow archive-back">← El archivo Perigallo</a><p class="eyebrow"><?=ae($d['edition']?'Edición '.$d['edition']:'Experiencias vividas')?><?php if ($date):?> <span> / </span><time datetime="<?=ae($d['date'])?>"><?=ae($date)?></time><?php endif?></p><h1><?=ae($d['title'])?><span><?=ae($d['subtitle'])?></span></h1><p><?=ae($d['description'])?></p><a href="#historia" class="archive-down" aria-label="Descubrir la historia">↓</a></div></section>
<section class="archive-story section paper" id="historia"><p class="eyebrow">01 / La historia</p><div><h2><?=ae($d['subtitle'] ?: 'Así lo vivimos.')?></h2><div class="archive-prose"><?=ap($d['story'])?></div><?php if ($d['legacy_url']):?><a class="archive-text-link" href="<?=ae($d['legacy_url'])?>">Explorar el relato inmersivo original <span aria-hidden="true">↗</span></a><?php endif?></div></section>
<?php if ($d['cover_video']):?><section class="section archive-section" id="pelicula"><p class="eyebrow">La experiencia en movimiento</p><?=av(['url'=>$d['cover_video'],'alt'=>'Vídeo principal de '.$d['title']],$d['cover_image'])?></section><?php endif?>
<?php foreach ($d['blocks'] as $index=>$block): if (!$preview && !$block['text'] && !$block['media']) continue;?><section class="section archive-section archive-block-<?=ae($block['type'])?><?=$block['media']?' has-media':''?><?=count($block['media'])===1?' has-single-media':''?><?=$index%2?' is-media-reversed':''?>"><div class="archive-section-copy"><div class="archive-section-heading"><p class="eyebrow"><?=str_pad((string)($index+2),2,'0',STR_PAD_LEFT)?> / <?=ae(['gallery'=>'Los momentos','story'=>'El relato','gastronomy'=>'Gastronomía','highlights'=>'Lo inolvidable','video'=>'En movimiento'][$block['type']])?></p><h2><?=ae($block['title'])?></h2></div><?php if ($block['text']):?><div class="archive-prose"><?=ap($block['text'])?></div><?php endif?></div><?=am($block['media'])?><?php if ($preview && !$block['media'] && in_array($block['type'],['gallery','gastronomy','highlights','video'],true)):?><div class="archive-media-pending"><span aria-hidden="true">＋</span><p><?=ae($block['type']==='video'?'El vídeo de esta historia está por incorporar.':'Aquí irán las fotografías de esta edición.')?></p><small>Este espacio solo aparece en la vista previa.</small></div><?php endif?></section><?php endforeach?>
<section class="section paper archive-collection"><div class="archive-heading"><div><p class="eyebrow">La historia continúa</p><h2>Otros capítulos.</h2></div><a class="archive-text-link" href="/experiencias/#agenda">Descubrir lo próximo ↗</a></div><div data-archive-collection data-exclude="<?=ae($entry['slug'])?>" class="archive-grid" aria-live="polite"></div></section></main>
<?=$archiveFooter?><dialog class="archive-lightbox" aria-label="Visor de fotografías"><div class="archive-lightbox-toolbar"><p data-viewer-counter></p><button type="button" data-viewer-close aria-label="Cerrar fotografía">Cerrar ×</button></div><div class="archive-lightbox-stage"><button type="button" data-viewer-prev aria-label="Fotografía anterior">←</button><figure><img data-viewer-photo alt=""><figcaption data-viewer-caption></figcaption></figure><button type="button" data-viewer-next aria-label="Fotografía siguiente">→</button></div></dialog>
<script type="application/ld+json"><?=json_encode(['@context'=>'https://schema.org','@type'=>'Article','headline'=>$title,'description'=>$description,'url'=>$canonical,'image'=>$d['cover_image']?'https://perigallo.com'.$d['cover_image']:null,'about'=>['@type'=>'Event','name'=>$d['title'],'startDate'=>$d['date']],'publisher'=>['@type'=>'Organization','name'=>'Perigallo']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?></script><script src="/journey.js" defer></script><script src="/assets/js/experience-archive.js?v=archivo-5940d99cb6" defer></script></body></html>
