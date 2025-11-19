-- This migration simplifies the payload sent by the broadcast trigger
-- to debug a "realtime json does not exist" error.
-- It updates the broadcast_loyalty_card_update function to send a minimal payload.

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
      'payload', json_build_object('id', NEW.id) -- Send minimal payload
    )
  );
  RETURN NEW;
END;
$$;
