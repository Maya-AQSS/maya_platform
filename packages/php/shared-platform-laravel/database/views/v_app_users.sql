-- revision: 1
-- Usuarios de las apps: los empleados de Odoo (maya_core_employee) con usuario activo y enlazado
-- con Keycloak. Lo lee la tabla foránea `users_fdw` de shared-profile-laravel (vista local `users`).
-- id = `sub` de Keycloak = res_users.oauth_uid (D26 R4).
CREATE OR REPLACE VIEW maya_apps.v_app_users AS
SELECT u.oauth_uid                                                    AS id,
       p.email                                                        AS email,
       trim(coalesce(e.name, '') || ' ' || coalesce(e.surname, ''))  AS display_name,
       e.name                                                         AS first_name,
       e.surname                                                      AS last_name,
       u.login                                                        AS username,
       e.id::text                                                     AS employee_id,
       e.dni                                                          AS dni,
       e.employee_type                                                AS employee_type,
       true                                                           AS is_active
  FROM public.maya_core_employee e
  JOIN public.res_users u   ON u.id = e.user_id
  JOIN public.res_partner p ON p.id = u.partner_id
 WHERE u.active
   AND u.id > 2
   AND u.oauth_uid IS NOT NULL
   AND u.oauth_uid <> '';
