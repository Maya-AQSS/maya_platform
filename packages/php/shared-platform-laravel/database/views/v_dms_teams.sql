-- revision: 1
-- Equipos y departamentos (maya_core_team). Lo lee `teams_fdw` de shared-profile-laravel (vista
-- local `teams`). `id` es un UUID derivado del id de Odoo, como texto: v_dms_team_members.team_id usa
-- la misma derivación. owner_id = oauth_uid de quien creó el equipo (D26 R4; antes era el id de Odoo,
-- que nunca casaba con el `sub` con el que lo compara dms).
CREATE OR REPLACE VIEW maya_apps.v_dms_teams AS
SELECT md5('team_' || t.id::text)::uuid::text           AS id,
       coalesce(t.name ->> 'en_US', t.name ->> 'es_ES') AS name,
       NULL::text                                       AS description,
       cu.oauth_uid::text                               AS owner_id,
       t.is_departament                                 AS is_department,
       t.create_date                                    AS created_at,
       t.write_date                                     AS updated_at,
       NULL::timestamp                                  AS deleted_at
  FROM public.maya_core_team t
  LEFT JOIN public.res_users cu ON cu.id = t.create_uid;
