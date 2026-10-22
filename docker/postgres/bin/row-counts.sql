-- The number of rows in every table Atlas owns, one "table rows" line each.
-- CI runs it against the live and the restored database and compares.
--
--   docker compose exec db psql -U atlas -d atlas -tAf /atlas/row-counts.sql

SELECT table_name || ' ' || (xpath('/row/n/text()',
           query_to_xml(format('SELECT count(*) AS n FROM public.%I', table_name), false, true, '')))[1]::text
FROM information_schema.tables
WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
ORDER BY table_name;
