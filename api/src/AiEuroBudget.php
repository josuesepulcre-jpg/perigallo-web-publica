<?php
declare(strict_types=1);
namespace Perigallo\Ticketing;

use RuntimeException;

final class AiEuroBudget
{
    public static function reserve(array $request): void
    {
        $message = 'La petición no cabe en el presupuesto total diario de IA de 0,10 € o no puede verificarse su coste. Continúa manualmente; se renueva a medianoche de Madrid.';
        $json = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $value = json_encode(['app'=>'instagram', 'model'=>$request['model'], 'input_tokens_bound'=>strlen($json)+1024, 'output_tokens_max'=>$request['max_output_tokens']], JSON_THROW_ON_ERROR);
        $path = getenv('AI_SPEND_GUARD_SOCKET') ?: '/run/perigallo-ai-spend/guard.sock';
        $socket = @stream_socket_client('unix://'.$path, $errno, $error, 4);
        if ($socket === false) throw new RuntimeException($message, 429);
        try {
            stream_set_timeout($socket, 4);
            $wire = "POST /reserve HTTP/1.0\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: ".strlen($value)."\r\n\r\n".$value;
            while ($wire !== '') {
                $written = @fwrite($socket, $wire);
                if (!$written) throw new RuntimeException($message, 429);
                $wire = substr($wire, $written);
            }
            $response = stream_get_contents($socket, 4096);
            if (!is_string($response) || !preg_match('/^HTTP\/1\.[01] 200 /', $response)) throw new RuntimeException($message, 429);
            $parts = explode("\r\n\r\n", $response, 2);
            $body = json_decode($parts[1] ?? '', true);
            if (($body['allowed'] ?? false) !== true) throw new RuntimeException($message, 429);
        } finally {
            fclose($socket);
        }
    }
}
