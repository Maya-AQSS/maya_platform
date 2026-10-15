-- Sin revisión: vale 1. El prefijo numérico solo ordena.
CREATE OR REPLACE VIEW maya_apps.v_app_test_profile AS
SELECT u.oauth_uid AS user_id, e.personal_email
  FROM public.maya_core_employee e
  JOIN public.res_users u ON u.id = e.user_id
