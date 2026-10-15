-- revision: 1
-- Miembros de los equipos (maya_core_employee_maya_core_team_rel). Lo lee `team_members_fdw` de
-- shared-profile-laravel (vista local `team_members`). team_id = v_dms_teams.id; user_id = oauth_uid.
CREATE OR REPLACE VIEW maya_apps.v_dms_team_members AS
SELECT md5('member_' || rel.maya_core_employee_id::text
               || '_' || rel.maya_core_team_id::text)::uuid::text AS id,
       md5('team_' || rel.maya_core_team_id::text)::uuid::text     AS team_id,
       u.oauth_uid::text                                           AS user_id,
       'member'::varchar                                           AS role,
       e.create_date                                               AS created_at,
       e.write_date                                                AS updated_at
  FROM public.maya_core_employee_maya_core_team_rel rel
  JOIN public.maya_core_employee e ON e.id = rel.maya_core_employee_id
  JOIN public.res_users u          ON u.id = e.user_id
 WHERE u.oauth_uid IS NOT NULL
   AND u.oauth_uid <> '';
