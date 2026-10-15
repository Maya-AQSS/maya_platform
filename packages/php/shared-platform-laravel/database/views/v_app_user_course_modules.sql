-- revision: 1
-- Módulos que imparte cada usuario (maya_core_subject_employee_rel). module_id = '<estudio>_<asignatura>',
-- como el id de la vista local `course_modules`. Lo lee `user_course_modules_fdw` de shared-profile-laravel
-- (vista local `user_course_modules`).
CREATE OR REPLACE VIEW maya_apps.v_app_user_course_modules AS
SELECT md5('ucm_' || u.oauth_uid || '_' || ser.study_id::text
               || '_' || ser.subject_id::text)::uuid::text AS id,
       u.oauth_uid::text                                  AS user_id,
       ser.study_id::text || '_' || ser.subject_id::text  AS module_id,
       ser.create_date                                    AS created_at,
       ser.write_date                                     AS updated_at
  FROM public.maya_core_subject_employee_rel ser
  JOIN public.maya_core_employee e ON e.id = ser.employee_id
  JOIN public.res_users u          ON u.id = e.user_id
 WHERE u.active
   AND u.oauth_uid IS NOT NULL
   AND u.oauth_uid <> '';
