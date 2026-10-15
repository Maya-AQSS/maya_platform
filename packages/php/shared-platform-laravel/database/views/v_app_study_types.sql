-- revision: 1
-- Catálogo de tipos de estudio: las compañías hijas de CEEDCV (FP, BACH, FPA). Lo lee `res_company_fdw`
-- de shared-profile-laravel (vista local `study_types`, que usa name como código y como nombre). Mismas
-- columnas que esa tabla foránea.
CREATE OR REPLACE VIEW maya_apps.v_app_study_types AS
SELECT c.id,
       c.name,
       c.parent_id
  FROM public.res_company c
 WHERE c.parent_id IS NOT NULL;
