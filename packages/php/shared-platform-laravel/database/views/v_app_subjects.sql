-- revision: 1
-- Catálogo de asignaturas (maya_core_subject). Lo lee `course_modules_subject_fdw` de shared-profile-laravel
-- (con v_app_study_subjects forma la vista local `course_modules`). Mismas columnas que esa tabla foránea.
CREATE OR REPLACE VIEW maya_apps.v_app_subjects AS
SELECT s.id,
       s.code,
       s.year,
       s.name
  FROM public.maya_core_subject s;
