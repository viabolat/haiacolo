-- Create the PostgreSQL function to be called by the trigger.
-- This function sends a webhook-style payload to the user-signup-alert Edge Function.
CREATE OR REPLACE FUNCTION public.notify_new_user_signup()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
BEGIN
    -- Perform an HTTP POST request to the user-signup-alert Edge Function.
    -- The body is structured to mimic a standard Supabase webhook payload.
    PERFORM net.http_post(
        url := 'https://gucrinbliukkhubtxhqn.supabase.co/functions/v1/user-signup-alert',
        headers := '{"Content-Type": "application/json", "Authorization": "Bearer ' || secrets.get('SUPABASE_SERVICE_ROLE_KEY') || '"}'::jsonb,
        body := jsonb_build_object(
            'type', 'INSERT',
            'table', 'customers',
            'record', row_to_json(NEW)
        )
    );
    RETURN NEW;
EXCEPTION
    WHEN OTHERS THEN
        -- Log the error to the PostgreSQL logs but do not prevent the transaction from completing.
        RAISE WARNING 'Failed to send new user signup notification via webhook: %', SQLERRM;
        RETURN NEW;
END;
$$;

-- Create the trigger that fires after a new customer is inserted.
CREATE TRIGGER on_new_user_signup_trigger
AFTER INSERT ON public.customers
FOR EACH ROW
EXECUTE FUNCTION public.notify_new_user_signup();