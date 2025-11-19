-- This migration creates a trigger to automatically broadcast a message
-- on Supabase Realtime when a loyalty card's punch count is updated.

-- 1. Create the trigger function
CREATE OR REPLACE FUNCTION public.broadcast_loyalty_card_update()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
BEGIN
  -- The channel name is specific to the card being updated
  PERFORM realtime.broadcast(
    'card-update-' || NEW.id,
    json_build_object(
      'type', 'broadcast',
      'event', 'punch',
      'payload', row_to_json(NEW) -- Send the entire updated row as the payload
    )
  );
  RETURN NEW;
END;
$$;

-- 2. Create the trigger on the loyalty_cards table
-- This trigger will fire after any update to a row.
CREATE TRIGGER on_loyalty_card_update
AFTER UPDATE ON public.loyalty_cards
FOR EACH ROW
-- To be efficient, only run the trigger if the punch count has actually changed.
WHEN (OLD.current_punches IS DISTINCT FROM NEW.current_punches)
EXECUTE FUNCTION public.broadcast_loyalty_card_update();
