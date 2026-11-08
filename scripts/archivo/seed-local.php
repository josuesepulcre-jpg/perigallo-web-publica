<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/src/bootstrap.php';
use Perigallo\Ticketing\Database;
use Perigallo\Ticketing\ExperienceArchive;
if (env_value('APP_ENV')!=='staging' || env_value('DB_DATABASE')!=='perigallo_archivo_local') exit('Local archive database required.');
$archive=new ExperienceArchive(Database::pdo());
$seeds=json_decode(file_get_contents(__DIR__.'/seed-content.json'),true,512,JSON_THROW_ON_ERROR);
foreach ($seeds as $seed) {
    if ($archive->get($seed['slug'],true)) continue;
    $entry=$archive->save($seed);
    if ($seed['slug']==='la-perigalla-01-noche-ibicenca') $archive->transition($entry['id'],'publish',$entry['revision']);
    echo $seed['slug'].PHP_EOL;
}
