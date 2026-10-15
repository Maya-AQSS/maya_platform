-- revision: 1
-- Asignaturas de cada estudio (maya_core_study_maya_core_subject_rel). Lo lee `course_modules_rel_fdw` de
-- shared-profile-laravel (vista local `course_modules`, id '<estudio>_<asignatura>'). Mismas columnas que
-- esa tabla foránea.
CREATE OR REPLACE VIEW maya_apps.v_app_study_subjects AS
SELECT r.maya_core_study_id,
       r.maya_core_subject_id
  FROM public.maya_core_study_maya_core_subject_rel r;
