-- revision: 1
-- Estudios de cada usuario: todos los estudios activos de las compañías a las que está asignado
-- (semántica de gestión, no de impartición). Lo lee `user_studies_fdw` de shared-profile-laravel
-- (vista local `user_studies`).
CREATE OR REPLACE VIEW maya_apps.v_app_user_studies AS
SELECT md5('us_' || u.oauth_uid || '_' || s.id::text)::uuid::text AS id,
       u.oauth_uid::text                                         AS user_id,
       s.id::text                                                AS study_id,
       s.create_date                                             AS created_at,
       s.write_date                                              AS updated_at
  FROM public.res_company_users_rel rcr
  JOIN public.res_company c     ON c.id = rcr.cid AND c.parent_id IS NOT NULL
  JOIN public.res_users u       ON u.id = rcr.user_id
  JOIN public.maya_core_study s ON s.company_id = rcr.cid
 WHERE u.active
   AND u.oauth_uid IS NOT NULL
   AND u.oauth_uid <> ''
   AND s.active;
