# Auditoría de audio de La Perigalla 01

Ejecuta desde la raíz publicada:

```bash
node scripts/audit-story-audio.mjs --strict --output=reports/story-audio-audit.json
```

La herramienta comprueba los 23 MP3 `v9-scenes`: existencia, cabecera MP3, tamaño, duración mediante `ffprobe` cuando está disponible y silencios mediante `ffmpeg` cuando está disponible. No marca como correcto un contenido verbal que no pueda transcribir.

Para verificar una transcripción externa, aporta un JSON con cada id de escena como clave:

```bash
node scripts/audit-story-audio.mjs --transcript=reports/story-transcripts.json --output=reports/story-audio-audit.json
```

Si el informe detecta «punto» u otra locución no incluida en el guion, el MP3 afectado debe regenerarse en origen. La aplicación no elimina palabras mediante JavaScript. Después de sustituirlo, incrementa `AUDIO_VERSION` en `components/story/StoryPlayer.tsx` para invalidar la caché de navegador y CDN.
