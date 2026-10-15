-- revision: 1
-- Idiomas activos de Odoo (res_lang). Lo lee `res_lang_fdw` de shared-profile-laravel (vista local
-- `languages`, que los reduce a es/va/en). Mismas columnas que esa tabla foránea.
CREATE OR REPLACE VIEW maya_apps.v_app_languages AS
SELECT l.id,
       l.name,
       l.code,
       l.iso_code,
       l.active
  FROM public.res_lang l
 WHERE l.active;
