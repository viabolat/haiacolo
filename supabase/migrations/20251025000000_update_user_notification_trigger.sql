-- Drop the old trigger that only fired on INSERT
DROP TRIGGER IF EXISTS on_new_user_signup_trigger ON public.customers;

-- Create a new trigger that fires on both INSERT and UPDATE.
-- This ensures the notification is sent even when an existing user's
-- details are updated during the signup flow (upsert).
CREATE TRIGGER on_new_or_updated_user_trigger
AFTER INSERT OR UPDATE ON public.customers
FOR EACH ROW
EXECUTE FUNCTION public.notify_new_user_signup();