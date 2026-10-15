-- revision: 1
-- Catálogo de estudios activos (maya_core_study). Lo lee `studies_fdw` de shared-profile-laravel (vista
-- local `studies`, que desempaqueta name y usa company_id como study_type_id). Mismas columnas que esa
-- tabla foránea.
CREATE OR REPLACE VIEW maya_apps.v_app_studies AS
SELECT s.id,
       s.code,
       s.company_id,
       s.name,
       s.active
  FROM public.maya_core_study s
 WHERE s.active;
