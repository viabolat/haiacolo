-- This migration corrects the payload structure sent by the
-- notify_new_merchant_application trigger function.
-- The previous version sent a flat object, but the Edge Function
-- expects a standard Supabase webhook payload with a 'record' object.

CREATE OR REPLACE FUNCTION public.notify_new_merchant_application()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
BEGIN
    -- Perform an HTTP POST request to the merchant-application-alert Edge Function.
    -- The body is structured to mimic a standard Supabase webhook payload.
    PERFORM net.http_post(
        url := 'https://gucrinbliukkhubtxhqn.supabase.co/functions/v1/merchant-application-alert',
        headers := '{"Content-Type": "application/json", "Authorization": "Bearer ' || secrets.get('SUPABASE_SERVICE_ROLE_KEY') || '"}'::jsonb,
        body := jsonb_build_object(
            'type', 'INSERT',
            'table', 'merchants',
            'record', row_to_json(NEW)
        )
    );
    RETURN NEW;
EXCEPTION
    WHEN OTHERS THEN
        -- Log the error to the PostgreSQL logs but do not prevent the transaction from completing.
        RAISE WARNING 'Failed to send merchant application notification via webhook: %', SQLERRM;
        RETURN NEW;
END;
$$;