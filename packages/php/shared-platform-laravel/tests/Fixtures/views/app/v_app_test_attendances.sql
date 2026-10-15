-- revision: 2
-- Vista de ejemplo de una app: fichajes. El ';' dentro del literal no es otra sentencia.
CREATE OR REPLACE VIEW maya_apps.v_app_test_attendances AS
SELECT a.id::text AS id,
       u.oauth_uid AS user_id,   -- sub de Keycloak
       a.check_time AS check_in,
       'a;b'::text AS source
  FROM public.maya_attendance_attendance a
  /* empleado → usuario */
  JOIN public.maya_core_employee e ON e.id = a.employee_id
  JOIN public.res_users u ON u.id = e.user_id
 WHERE a.attendance_type = 'I';
