-- Configuración puntual de publicación para la experiencia existente mostrada
-- en el editor como id=1. No establece valores por defecto para otros eventos.
UPDATE events
SET slug = 'la-perigalla-01-ibicenca',
    status = 'draft',
    visible = 1,
    publication_at = NULL,
    unlisted = 0,
    link_only = 0,
    show_sold_out = 1,
    show_availability = 1,
    show_price_from = 1,
    seo_title = 'La Perigalla 01 | Boda ibicenca en Finca La Laguna',
    seo_description = 'Vive La Perigalla 01, una boda ficticia de inspiración ibicenca con gastronomía, música y experiencias en Finca La Laguna. 29 de agosto de 2026.',
    canonical_url = 'https://perigallo.com/eventos/la-perigalla-01-ibicenca',
    updated_at = NOW()
WHERE id = 1
  AND title = 'La Perigalla 01';
