-- revision: 1
-- Tipos de estudio de cada usuario: las compañías hijas de CEEDCV (FP, BACH, FPA) a las que está
-- asignado en Odoo (res_company_users_rel). Lo lee `user_study_types_fdw` de shared-profile-laravel
-- (vista local `user_study_types`). study_type_id = res_company.id, como v_app_study_types.
CREATE OR REPLACE VIEW maya_apps.v_app_user_study_types AS
SELECT md5('ust_' || u.oauth_uid || '_' || rcr.cid::text)::uuid::text AS id,
       u.oauth_uid::text                                             AS user_id,
       rcr.cid::text                                                 AS study_type_id,
       NULL::timestamp                                               AS created_at,
       NULL::timestamp                                               AS updated_at
  FROM public.res_company_users_rel rcr
  JOIN public.res_company c ON c.id = rcr.cid AND c.parent_id IS NOT NULL
  JOIN public.res_users u   ON u.id = rcr.user_id
 WHERE u.active
   AND u.oauth_uid IS NOT NULL
   AND u.oauth_uid <> '';
