-- Export experience_archive before reverting. This removes editorial content only.
-- Never run without a verified backup and explicit authorization in production.
DROP TABLE IF EXISTS experience_archive;
