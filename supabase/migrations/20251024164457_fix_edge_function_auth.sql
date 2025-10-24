CREATE OR REPLACE FUNCTION public.notify_new_merchant_application()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
BEGIN
    PERFORM net.http_post(
        url := 'https://gucrinbliukkhubtxhqn.supabase.co/functions/v1/merchant-application-alert',
        headers := '{"Content-Type": "application/json", "Authorization": "Bearer ' || (SELECT value FROM supabase_vault.secrets WHERE name = 'SUPABASE_SERVICE_ROLE_KEY') || '"}'::jsonb,
        body := jsonb_build_object(
            'type', 'INSERT',
            'table', 'merchants',
            'record', row_to_json(NEW)
        )
    );
    RETURN NEW;
EXCEPTION
    WHEN OTHERS THEN
        RAISE WARNING 'Failed to send merchant application notification via webhook: %', SQLERRM;
        RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION public.notify_new_user_signup()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
BEGIN
    PERFORM net.http_post(
        url := 'https://gucrinbliukkhubtxhqn.supabase.co/functions/v1/user-signup-alert',
        headers := '{"Content-Type": "application/json", "Authorization": "Bearer ' || (SELECT value FROM supabase_vault.secrets WHERE name = 'SUPABASE_SERVICE_ROLE_KEY') || '"}'::jsonb,
        body := jsonb_build_object(
            'type', 'INSERT',
            'table', 'customers',
            'record', row_to_json(NEW)
        )
    );
    RETURN NEW;
EXCEPTION
    WHEN OTHERS THEN
        RAISE WARNING 'Failed to send new user signup notification via webhook: %', SQLERRM;
        RETURN NEW;
END;
$$;