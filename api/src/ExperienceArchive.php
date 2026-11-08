<?php
declare(strict_types=1);
namespace Perigallo\Ticketing;
use PDO;
use InvalidArgumentException;
use RuntimeException;

/** Editorial snapshots are deliberately independent from ticket/payment state. */
final class ExperienceArchive
{
    public function __construct(private PDO $pdo) {}

    public static function text(mixed $value, int $max = 6000): string
    {
        if (!is_string($value) || mb_strlen($value) > $max) throw new InvalidArgumentException('Texto inválido o demasiado largo.');
        return trim($value);
    }

    public static function mediaUrl(mixed $value, bool $video = false): string
    {
        $url = self::text($value, 600);
        if ($url === '') return '';
        // Local media only; no scripts, SVG, protocol-relative URLs or traversal.
        if (preg_match('#^/(?:assets/(?:images|uploads)|media|la-perigalla-01/media)/[a-zA-Z0-9_./%-]+\.(?:jpe?g|png|webp|avif|mp4|webm)$#i', $url)
            && !str_contains(rawurldecode($url), '..') && !str_contains(rawurldecode($url), '\\')) {
            $isVideo = (bool) preg_match('/\.(mp4|webm)$/i', $url);
            if ($isVideo !== $video) throw new InvalidArgumentException('El recurso no corresponde al tipo de imagen o vídeo.');
            return $url;
        }
        if ($video && filter_var($url, FILTER_VALIDATE_URL)) {
            $p = parse_url($url);
            if (($p['scheme'] ?? '') === 'https' && !isset($p['user']) && !isset($p['pass']) && !isset($p['port'])
                && in_array(strtolower($p['host'] ?? ''), ['www.youtube.com','youtube.com','youtu.be','vimeo.com','www.vimeo.com'], true)) return $url;
        }
        throw new InvalidArgumentException('Usa una imagen local o un vídeo MP4/WebM, YouTube o Vimeo.');
    }

    private function document(array $input): array
    {
        $doc = [];
        foreach (['title'=>180,'subtitle'=>240,'edition'=>80,'description'=>500,'story'=>12000,'cover_alt'=>250,'seo_title'=>180,'seo_description'=>300] as $key=>$max) $doc[$key]=self::text($input[$key] ?? '',$max);
        if ($doc['title']==='') throw new InvalidArgumentException('Indica el título de la experiencia.');
        $date=self::text($input['date'] ?? '',10);
        if ($date!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || \DateTimeImmutable::createFromFormat('!Y-m-d',$date)?->format('Y-m-d')!==$date)) throw new InvalidArgumentException('Fecha de celebración inválida.');
        $doc['date']=$date;
        $doc['cover_image']=self::mediaUrl($input['cover_image'] ?? '');
        $doc['cover_video']=self::mediaUrl($input['cover_video'] ?? '',true);
        // The original immersive edition is preserved as a stable optional link.
        $doc['legacy_url']=($input['legacy_url'] ?? '')==='/la-perigalla-01/' ? '/la-perigalla-01/' : '';
        $blocks=$input['blocks'] ?? [];
        if (!is_array($blocks) || !array_is_list($blocks) || count($blocks)>60) throw new InvalidArgumentException('Máximo 60 bloques por historia.');
        $doc['blocks']=[];
        foreach ($blocks as $block) {
            if (!is_array($block) || !in_array($block['type'] ?? '',['story','gallery','gastronomy','highlights','video'],true)) throw new InvalidArgumentException('Tipo de bloque no permitido.');
            $b=['type'=>$block['type'],'title'=>self::text($block['title'] ?? '',180),'text'=>self::text($block['text'] ?? '',12000),'media'=>[]];
            $media=$block['media'] ?? [];
            if (!is_array($media) || !array_is_list($media) || count($media)>40) throw new InvalidArgumentException('Máximo 40 recursos por bloque.');
            foreach ($media as $item) {
                if (!is_array($item)) throw new InvalidArgumentException('Recurso inválido.');
                $kind=($item['kind'] ?? 'image')==='video'?'video':'image';
                $url=self::mediaUrl($item['url'] ?? '',$kind==='video');
                if ($url==='') continue;
                $b['media'][]=['kind'=>$kind,'url'=>$url,'alt'=>self::text($item['alt'] ?? '',250),'caption'=>self::text($item['caption'] ?? '',500),'poster'=>$kind==='video'?self::mediaUrl($item['poster'] ?? ''):'','portrait'=>($item['portrait'] ?? false)===true,'featured'=>($item['featured'] ?? false)===true];
            }
            $doc['blocks'][]=$b;
        }
        if (strlen(json_encode($doc))>350000) throw new InvalidArgumentException('La historia es demasiado extensa.');
        return $doc;
    }

    public function list(bool $admin=false): array
    {
        $rows=$this->pdo->query('SELECT * FROM experience_archive'.($admin?'':" WHERE status='published' AND published_json IS NOT NULL").' ORDER BY id DESC')->fetchAll();
        $result=[];
        foreach ($rows as $row) {
            $doc=json_decode($row[$admin?'draft_json':'published_json'],true);
            if (!$admin && (($doc['date'] ?? '')==='' || $doc['date']>date('Y-m-d'))) continue;
            $row['document']=array_intersect_key($doc,array_flip(['title','subtitle','edition','date','description','cover_image','cover_alt']));unset($row['draft_json'],$row['published_json']);
            $row['id']=(int)$row['id'];$row['revision']=(int)$row['revision'];
            $row['phase']=($doc['date'] ?? '')!=='' && $doc['date']<=date('Y-m-d')?'finished':'upcoming';
            if (!$admin) unset($row['revision'],$row['event_id']);
            $result[]=$row;
        }
        usort($result,static fn($a,$b)=>strcmp($b['document']['date'],$a['document']['date']));
        return $result;
    }

    public function get(int|string $id, bool $admin=false): ?array
    {
        $s=$this->pdo->prepare('SELECT * FROM experience_archive WHERE '.(is_int($id)?'id':'slug').'=?'.($admin?'':" AND status='published' AND published_json IS NOT NULL"));
        $s->execute([$id]);$row=$s->fetch();if (!$row) return null;
        $doc=json_decode($row[$admin?'draft_json':'published_json'],true);
        if (!$admin && (!$doc['date'] || $doc['date']>date('Y-m-d'))) return null;
        $row['document']=$doc;unset($row['draft_json'],$row['published_json']);
        $row['id']=(int)$row['id'];$row['revision']=(int)$row['revision'];
        $row['phase']=$doc['date'] && $doc['date']<=date('Y-m-d')?'finished':'upcoming';return $row;
    }

    public function save(array $input, ?int $id=null): array
    {
        $doc=$this->document($input['document'] ?? []);
        $eventId=empty($input['event_id'])?null:(int)$input['event_id'];
        if ($eventId!==null) { $s=$this->pdo->prepare('SELECT id FROM events WHERE id=?');$s->execute([$eventId]);if (!$s->fetchColumn()) throw new InvalidArgumentException('El evento vinculado no existe.'); }
        if ($id===null) {
            $slug=self::text($input['slug'] ?? '',140);
            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug)) throw new InvalidArgumentException('Usa una URL con letras minúsculas, números y guiones.');
            $stmt=$this->pdo->prepare('INSERT INTO experience_archive (slug,draft_json,event_id) VALUES (?,?,?)');
            try {$stmt->execute([$slug,json_encode($doc,JSON_UNESCAPED_UNICODE),$eventId]);} catch (\PDOException $e) {if (($e->errorInfo[1] ?? 0)===1062) throw new RuntimeException('La URL o el evento vinculado ya tienen una historia.',409);throw $e;}
            $id=(int)$this->pdo->lastInsertId();
        } else {
            $stmt=$this->pdo->prepare('UPDATE experience_archive SET draft_json=?,event_id=?,revision=revision+1 WHERE id=? AND revision=?');
            try { $stmt->execute([json_encode($doc,JSON_UNESCAPED_UNICODE),$eventId,$id,(int)($input['revision'] ?? 0)]); } catch (\PDOException $e) { if (($e->errorInfo[1] ?? 0)===1062) throw new RuntimeException('Ese evento ya tiene una historia vinculada.',409);throw $e; }
            if (!$stmt->rowCount()) throw new RuntimeException('Otra persona ha modificado esta historia. Recarga antes de guardar.',409);
        }
        return $this->get($id,true);
    }

    public function transition(int $id, string $action, int $revision): array
    {
        $this->pdo->beginTransaction();
        try {
            $s=$this->pdo->prepare('SELECT * FROM experience_archive WHERE id=? FOR UPDATE');$s->execute([$id]);$row=$s->fetch();
            if (!$row || (int)$row['revision']!==$revision) throw new RuntimeException('La historia ha cambiado. Recarga antes de continuar.',409);
            $d=json_decode($row['draft_json'],true);
            if ($action==='publish') {
                if (!$d['date'] || $d['date']>date('Y-m-d') || !$d['description'] || !$d['story'] || !$d['cover_image'] || !$d['cover_alt']) throw new InvalidArgumentException('Para publicar añade fecha pasada, descripción, historia, portada y texto alternativo.');
                $count=0;
                foreach ($d['blocks'] as $b) foreach ($b['media'] as $item) if ($item['kind']==='image' && $item['alt']!=='') $count++;
                if (!$count) throw new InvalidArgumentException('Añade al menos una fotografía con su texto alternativo a los momentos.');
                $resources=[['url'=>$d['cover_image'],'kind'=>'image','alt'=>$d['cover_alt']]];
                foreach ($d['blocks'] as $block) foreach ($block['media'] as $item) $resources[]=$item;
                if ($d['cover_video']) $resources[]=['url'=>$d['cover_video'],'kind'=>'video','alt'=>'Vídeo principal'];
                foreach ($resources as $item) {
                    if (!empty($item['poster']) && !is_file(dirname(__DIR__,2).rawurldecode($item['poster']))) throw new InvalidArgumentException('La imagen previa del vídeo no está disponible.');
                    if ($item['kind']==='image' && !$item['alt']) throw new InvalidArgumentException('Añade un texto alternativo a cada fotografía antes de publicar.');
                    if (str_starts_with($item['url'],'/') && !is_file(dirname(__DIR__,2).rawurldecode($item['url']))) throw new InvalidArgumentException('Hay un recurso que todavía no está disponible. Revisa los archivos antes de publicar.');
                }

                $s=$this->pdo->prepare("UPDATE experience_archive SET status='published',published_json=draft_json,published_at=NOW(),revision=revision+1 WHERE id=?");
            } elseif (in_array($action,['unpublish','archive','restore'],true)) {
                $status=$action==='archive'?'archived':'draft';
                $s=$this->pdo->prepare("UPDATE experience_archive SET status='$status',revision=revision+1 WHERE id=?");
            } else throw new InvalidArgumentException('Acción desconocida.');
            $s->execute([$id]);$this->pdo->commit();return $this->get($id,true);
        } catch (\Throwable $e) {$this->pdo->rollBack();throw $e;}
    }

    /** Safe to schedule hourly: idempotent, never publishes, never changes ticket events. */
    public function syncCompleted(): int
    {
        $rows=$this->pdo->query("SELECT id,slug,title,subtitle,starts_at,ends_at,description,image_url FROM events WHERE status IN ('published','sold_out','finished','archived','scheduled') AND (status<>'scheduled' OR publication_at<=NOW()) AND is_test=0 AND COALESCE(ends_at,DATE_ADD(starts_at,INTERVAL 1 DAY)) < NOW() AND id NOT IN (SELECT event_id FROM experience_archive WHERE event_id IS NOT NULL)")->fetchAll();
        $count=0;
        foreach ($rows as $event) {
            try { $cover=self::mediaUrl($event['image_url'] ?? ''); } catch (InvalidArgumentException $e) { $cover=''; }
            $d=$this->document(['title'=>$event['title'],'subtitle'=>$event['subtitle'] ?? '', 'date'=>substr($event['starts_at'],0,10),'description'=>mb_substr(trim(strip_tags($event['description'] ?? '')),0,500),'cover_image'=>$cover, 'blocks'=>[]]);
            $s=$this->pdo->prepare('INSERT IGNORE INTO experience_archive (event_id,slug,draft_json) VALUES (?,?,?)');
            // Prefix avoids competing with the original event URL or a hand-created chapter.
            $s->execute([(int)$event['id'],'evento-'.$event['id'].'-'.substr($event['slug'],0,110),json_encode($d,JSON_UNESCAPED_UNICODE)]);$count+=$s->rowCount();
        }
        return $count;
    }
}
